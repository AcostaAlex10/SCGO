<?php

declare(strict_types=1);

namespace Sgso\Migraciones;

use PDO;
use RuntimeException;
use Throwable;

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
 * dejar el registro en claro evita que alguien lo dude. Tampoco se comprueba que
 * de verdad estén aplicadas: una base vieja de alguien del equipo que se quedó
 * sin `0002` o `0003` hay que ponerla al día con sus scripts antes de migrar.
 * Si la base está vacía, se ejecuta `schema.sql`, que ya incluye lo que hacían
 * esos dos scripts.
 *
 * **Si el esquema base falla a mitad de camino**, la base queda con algunas
 * tablas y sin nada registrado, y sin ayuda el migrador la tomaría por una base
 * que ya existía. Por eso antes de ejecutar `schema.sql` deja una marca
 * (`MARCA_ESQUEMA_A_MEDIAS`) que solo se borra cuando termina: mientras esté, el
 * reintento vuelve a ejecutar el esquema, que es idempotente.
 *
 * **Regla para las migraciones nuevas.** Cada archivo de `sql/migraciones/` se
 * escribe idempotente (`IF NOT EXISTS`, o comprobando antes), y su cambio va
 * *también* a `schema.sql`, que sigue siendo la foto completa del esquema.
 * `MigracionesTest` falla si una migración cambia algo sobre una base recién
 * creada desde `schema.sql`, que es exactamente lo que pasa cuando alguien se
 * olvidó de actualizar uno de los dos.
 *
 * **Limitación de `sentencias()`.** Corta por `;` y solo saca los comentarios que
 * ocupan la línea entera. Un `;` dentro de un literal (`'a;b'`), de un comentario
 * al final de una línea o de un comentario de bloque parte la sentencia mal. Los
 * archivos de migración y `schema.sql` no los usan; un archivo nuevo tampoco.
 *
 * **Una sola corrida a la vez.** Se toma `GET_LOCK` mientras se aplica; una
 * segunda corrida simultánea falla en vez de aplicar lo mismo dos veces.
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

    /** Se registra antes de crear el esquema base y se borra al terminar. */
    public const MARCA_ESQUEMA_A_MEDIAS = '0000-creando-esquema';

    /** Cualquier tabla del esquema sirve para saber si la base ya tiene datos. */
    private const TABLA_TESTIGO = 'proyecto';

    private const BLOQUEO = 'sgso_migrar';

    /** `schema_migrations.version` es VARCHAR(64): un nombre más largo se truncaría. */
    private const FORMATO_DE_VERSION = '/^\d{4}-[a-z0-9-]{1,59}$/';

    public function __construct(
        private PDO $db,
        private string $directorio,
        private string $archivoEsquema
    ) {
    }

    /**
     * Aplica lo pendiente y devuelve una línea por paso.
     *
     * `$informar` recibe cada línea apenas ocurre: si una migración falla a mitad
     * de camino, quien corre esto ya vio qué se aplicó antes.
     *
     * @param (callable(string): void)|null $informar
     *
     * @return list<string>
     */
    public function aplicarPendientes(?callable $informar = null): array
    {
        $hechos = [];
        $decir = static function (string $linea) use (&$hechos, $informar): void {
            $hechos[] = $linea;
            if ($informar !== null) {
                $informar($linea);
            }
        };

        $this->tomarElBloqueo();
        try {
            $this->asegurarRegistro();

            if (!in_array(self::VERSIONES_BASE[0], $this->aplicadas(), true)) {
                $decir($this->sentarLaBase());
            }

            $aplicadas = $this->aplicadas();
            $pendientes = array_values(array_filter(
                $this->disponibles(),
                static fn (string $version): bool => !in_array($version, $aplicadas, true)
            ));

            foreach ($pendientes as $version) {
                try {
                    $this->ejecutarArchivo($this->ruta($version));
                } catch (Throwable $e) {
                    throw new RuntimeException(
                        "La migración {$version} falló y no quedó registrada: " . $e->getMessage(),
                        0,
                        $e
                    );
                }
                $this->registrar($version);
                $decir("Aplicada {$version}");
            }

            if ($pendientes === []) {
                $decir('No hay migraciones pendientes.');
            }
        } finally {
            $this->liberarElBloqueo();
        }

        return $hechos;
    }

    /**
     * Qué hay aplicado y qué falta, sin tocar nada.
     *
     * `baseExistenteSinRegistro` avisa que la primera corrida va a registrar las
     * versiones base sin ejecutarlas: sin ese aviso, "Aplicadas: (ninguna)" sobre
     * una base de producción asusta.
     *
     * @return array{aplicadas: list<string>, pendientes: list<string>, baseExistenteSinRegistro: bool}
     */
    public function estado(): array
    {
        // Solo mira: no crea `schema_migrations`. Quien lo corre contra una base
        // viva para ver qué falta no tiene que dejarle una tabla nueva.
        $aplicadas = $this->existeElRegistro() ? $this->aplicadas() : [];

        return [
            'aplicadas' => $aplicadas,
            'pendientes' => array_values(array_filter(
                $this->disponibles(),
                static fn (string $version): bool => !in_array($version, $aplicadas, true)
            )),
            'baseExistenteSinRegistro' => !in_array(self::VERSIONES_BASE[0], $aplicadas, true)
                && !in_array(self::MARCA_ESQUEMA_A_MEDIAS, $aplicadas, true)
                && $this->existeLaTablaTestigo(),
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
            if (preg_match(self::FORMATO_DE_VERSION, $nombre) !== 1) {
                throw new RuntimeException(
                    "El nombre de la migración '{$nombre}.sql' no sigue el formato NNNN-nombre-en-minusculas.sql "
                    . '(hasta 59 caracteres después del número)'
                );
            }
            $versiones[] = $nombre;
        }

        sort($versiones);

        return $versiones;
    }

    /**
     * Separa un script en sentencias. Los comentarios de línea se sacan antes,
     * porque si no un `;` comentado partiría la sentencia por la mitad. Ver la
     * limitación en la descripción de la clase.
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

    private function tomarElBloqueo(): void
    {
        $stmt = $this->db->prepare('SELECT GET_LOCK(?, 0)');
        $stmt->execute([self::BLOQUEO]);

        if ((int) $stmt->fetchColumn() !== 1) {
            throw new RuntimeException(
                'Hay otra corrida de migraciones en curso sobre esta base. Esperá a que termine y volvé a intentar.'
            );
        }
    }

    private function liberarElBloqueo(): void
    {
        $this->db->prepare('SELECT RELEASE_LOCK(?)')->execute([self::BLOQUEO]);
    }

    private function existeElRegistro(): bool
    {
        return $this->existeLaTabla('schema_migrations');
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
     * vacía (o quedó a medias) y hay que crearla desde `schema.sql`.
     */
    private function sentarLaBase(): string
    {
        $aplicadas = $this->aplicadas();
        $habiaQuedadoAMedias = in_array(self::MARCA_ESQUEMA_A_MEDIAS, $aplicadas, true);
        $crearElEsquema = $habiaQuedadoAMedias || !$this->existeLaTablaTestigo();

        if ($crearElEsquema) {
            if (!$habiaQuedadoAMedias) {
                $this->registrar(self::MARCA_ESQUEMA_A_MEDIAS);
            }
            try {
                $this->ejecutarArchivo($this->archivoEsquema);
            } catch (Throwable $e) {
                throw new RuntimeException(
                    'No se pudo crear el esquema base: ' . $e->getMessage()
                    . ' La base quedó a medias y marcada como tal: corregí la causa y volvé a correr.',
                    0,
                    $e
                );
            }
        }

        foreach (self::VERSIONES_BASE as $version) {
            if (!in_array($version, $aplicadas, true)) {
                $this->registrar($version);
            }
        }
        $this->db->prepare('DELETE FROM schema_migrations WHERE version = ?')
            ->execute([self::MARCA_ESQUEMA_A_MEDIAS]);

        $total = count(self::VERSIONES_BASE);

        return match (true) {
            $habiaQuedadoAMedias => "Se completó el esquema que había quedado a medias y se registraron {$total} versiones base.",
            $crearElEsquema => "Base vacía: se creó el esquema desde schema.sql y se registraron {$total} versiones base.",
            default => "La base ya existía: se registraron {$total} versiones base como aplicadas, sin ejecutarlas.",
        };
    }

    private function existeLaTablaTestigo(): bool
    {
        return $this->existeLaTabla(self::TABLA_TESTIGO);
    }

    private function existeLaTabla(string $tabla): bool
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = ?'
        );
        $stmt->execute([$tabla]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /** @return list<string> */
    private function aplicadas(): array
    {
        $filas = $this->db->query('SELECT version FROM schema_migrations ORDER BY version')
            ->fetchAll(PDO::FETCH_COLUMN);

        return array_map('strval', $filas);
    }

    /** Sin `IGNORE`: si la versión ya estaba, que falle y no lo oculte. */
    private function registrar(string $version): void
    {
        $this->db
            ->prepare('INSERT INTO schema_migrations (version, aplicada_en) VALUES (?, NOW())')
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
