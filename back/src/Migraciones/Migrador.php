<?php

declare(strict_types=1);

namespace Sgso\Migraciones;

use PDO;
use RuntimeException;

/**
 * Migraciones versionadas (plan de producto, B-03).
 *
 * Antes, cada cambio de esquema era un script suelto en `back/sql/` que alguien
 * tenía que acordarse de correr, y nada registraba si ya se había corrido. Ahora
 * la tabla `schema_migrations` lleva la cuenta y este migrador aplica solo lo
 * que falta.
 *
 * **Cómo trata una base que ya existe.** Producción viene de antes de esto: ya
 * tiene el esquema y los dos scripts sueltos aplicados. Si `schema_migrations`
 * está vacía pero la base ya tiene tablas, se registran las tres versiones base
 * como aplicadas **sin ejecutarlas**: volver a correrlas no haría nada, pero
 * dejar el registro en claro evita que alguien lo dude. Si la base está vacía,
 * se ejecuta `schema.sql`, que ya incluye lo que hacían esos dos scripts.
 *
 * **Regla para las migraciones nuevas.** Cada archivo de `sql/migraciones/` se
 * escribe idempotente (`IF NOT EXISTS`, o comprobando antes), y su cambio va
 * *también* a `schema.sql`, que sigue siendo la foto completa del esquema.
 * `MigracionesTest` falla si una migración cambia algo sobre una base recién
 * creada desde `schema.sql`, que es exactamente lo que pasa cuando alguien se
 * olvidó de actualizar uno de los dos.
 */
final class Migrador
{
    /**
     * Lo que ya estaba antes de este sistema: el esquema y los dos scripts
     * sueltos, que en producción ya se corrieron.
     *
     * @var list<string>
     */
    public const VERSIONES_BASE = [
        '0001-esquema-base',
        '0002-estado-enum',
        '0003-reporte-final',
    ];

    /** Cualquier tabla del esquema sirve para saber si la base ya tiene datos. */
    private const TABLA_TESTIGO = 'proyecto';

    public function __construct(
        private PDO $db,
        private string $directorio,
        private string $archivoEsquema
    ) {
    }

    /**
     * Aplica lo pendiente y devuelve una línea por paso, para imprimir.
     *
     * @return list<string>
     */
    public function aplicarPendientes(): array
    {
        $this->asegurarRegistro();

        $hechos = [];
        if ($this->registroVacio()) {
            $hechos[] = $this->sentarLaBase();
        }

        $aplicadas = $this->aplicadas();
        $pendientes = array_values(array_filter(
            $this->disponibles(),
            static fn (string $version): bool => !in_array($version, $aplicadas, true)
        ));

        foreach ($pendientes as $version) {
            $this->ejecutarArchivo($this->ruta($version));
            $this->registrar($version);
            $hechos[] = "Aplicada {$version}";
        }

        if ($pendientes === []) {
            $hechos[] = 'No hay migraciones pendientes.';
        }

        return $hechos;
    }

    /**
     * Qué hay aplicado y qué falta, sin tocar nada.
     *
     * @return array{aplicadas: list<string>, pendientes: list<string>}
     */
    public function estado(): array
    {
        $this->asegurarRegistro();
        $aplicadas = $this->aplicadas();

        return [
            'aplicadas' => $aplicadas,
            'pendientes' => array_values(array_filter(
                $this->disponibles(),
                static fn (string $version): bool => !in_array($version, $aplicadas, true)
            )),
        ];
    }

    /**
     * Las migraciones que hay en disco, ordenadas por su número.
     *
     * @return list<string>
     */
    public function disponibles(): array
    {
        $archivos = glob($this->directorio . '/*.sql');
        if ($archivos === false) {
            return [];
        }

        $versiones = [];
        foreach ($archivos as $archivo) {
            $nombre = basename($archivo, '.sql');
            if (preg_match('/^\d{4}-[a-z0-9-]+$/', $nombre) !== 1) {
                throw new RuntimeException(
                    "El nombre de la migración '{$nombre}.sql' no sigue el formato NNNN-nombre-en-minusculas.sql"
                );
            }
            $versiones[] = $nombre;
        }

        sort($versiones);

        return $versiones;
    }

    /**
     * Separa un script en sentencias. Los comentarios de línea se sacan antes,
     * porque si no un `;` comentado partiría la sentencia por la mitad.
     *
     * @return list<string>
     */
    public static function sentencias(string $sql): array
    {
        $sinComentarios = (string) preg_replace('/^\s*--.*$/m', '', $sql);

        return array_values(array_filter(
            array_map('trim', explode(';', $sinComentarios)),
            static fn (string $sentencia): bool => $sentencia !== ''
        ));
    }

    private function asegurarRegistro(): void
    {
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
               version     VARCHAR(64) NOT NULL PRIMARY KEY,
               aplicada_en DATETIME NOT NULL
             )'
        );
    }

    /**
     * Primera corrida: o la base ya existía y solo hay que registrarla, o está
     * vacía y hay que crearla desde `schema.sql`.
     */
    private function sentarLaBase(): string
    {
        $baseVacia = !$this->existeLaTablaTestigo();

        if ($baseVacia) {
            $this->ejecutarArchivo($this->archivoEsquema);
        }

        foreach (self::VERSIONES_BASE as $version) {
            $this->registrar($version);
        }

        return $baseVacia
            ? 'Base vacía: se creó el esquema desde schema.sql y se registraron ' . count(self::VERSIONES_BASE) . ' versiones base.'
            : 'La base ya existía: se registraron ' . count(self::VERSIONES_BASE) . ' versiones base como aplicadas, sin ejecutarlas.';
    }

    private function existeLaTablaTestigo(): bool
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = ?'
        );
        $stmt->execute([self::TABLA_TESTIGO]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function registroVacio(): bool
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn() === 0;
    }

    /** @return list<string> */
    private function aplicadas(): array
    {
        $filas = $this->db->query('SELECT version FROM schema_migrations ORDER BY version')
            ->fetchAll(PDO::FETCH_COLUMN);

        return array_map('strval', $filas);
    }

    private function registrar(string $version): void
    {
        $this->db
            ->prepare('INSERT IGNORE INTO schema_migrations (version, aplicada_en) VALUES (?, NOW())')
            ->execute([$version]);
    }

    private function ruta(string $version): string
    {
        return $this->directorio . '/' . $version . '.sql';
    }

    private function ejecutarArchivo(string $archivo): void
    {
        $sql = file_get_contents($archivo);
        if ($sql === false) {
            throw new RuntimeException("No se pudo leer {$archivo}");
        }

        foreach (self::sentencias($sql) as $sentencia) {
            $this->db->exec($sentencia);
        }
    }
}
