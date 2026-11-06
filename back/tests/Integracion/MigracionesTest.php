<?php

declare(strict_types=1);

namespace Sgso\Tests\Integracion;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use Sgso\Migraciones\Migrador;

/**
 * Migraciones versionadas contra MariaDB de verdad (plan de producto, B-03).
 *
 * Son de integración porque lo que hay que probar es justamente el efecto
 * sobre el esquema: que una base viva no se toque, que una vacía quede
 * completa, y que `schema.sql` y las migraciones no se desincronicen.
 */
#[CoversClass(Migrador::class)]
final class MigracionesTest extends CasoConBase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Cada prueba decide en qué estado arranca el registro.
        $this->base()->exec('DROP TABLE IF EXISTS schema_migrations');
    }

    /**
     * La prueba que evita el problema de fondo: si alguien escribe una
     * migración y se olvida de llevar el cambio a `schema.sql` (o al revés),
     * las dos formas de construir la base dejan de coincidir. Sobre una base
     * recién creada desde `schema.sql`, aplicar todo no puede cambiar nada.
     */
    public function testNingunaMigracionCambiaUnaBaseCreadaDesdeSchemaSql(): void
    {
        $antes = $this->esquema();

        $this->migrador()->aplicarPendientes();

        self::assertSame($antes, $this->esquema());
    }

    public function testEnUnaBaseQueYaExistiaRegistraLasVersionesBaseSinEjecutarlas(): void
    {
        // Una obra cargada hace de testigo: si el migrador ejecutara de nuevo
        // el esquema con un DROP o un TRUNCATE, esto desaparecería.
        $idProyecto = $this->crearProyecto();

        $hechos = $this->migrador()->aplicarPendientes();

        self::assertStringContainsString('ya existía', implode(' ', $hechos));
        self::assertSame('en_ejecucion', $this->estadoDeLaObra($idProyecto));
        foreach (Migrador::VERSIONES_BASE as $version) {
            self::assertContains($version, $this->registradas());
        }
    }

    public function testEnUnaBaseVaciaCreaElEsquemaCompleto(): void
    {
        $this->vaciarLaBaseEntera();

        $hechos = $this->migrador()->aplicarPendientes();

        self::assertStringContainsString('Base vacía', implode(' ', $hechos));
        self::assertContains('proyecto', $this->tablas());
        self::assertContains('intento_login', $this->tablas());
    }

    public function testRegistraTodaMigracionQueHayEnDisco(): void
    {
        $migrador = $this->migrador();
        $migrador->aplicarPendientes();

        $registradas = $this->registradas();
        foreach ($migrador->disponibles() as $version) {
            self::assertContains($version, $registradas, "La migración {$version} no quedó registrada");
        }
    }

    public function testLaSegundaCorridaNoAplicaNada(): void
    {
        $migrador = $this->migrador();
        $migrador->aplicarPendientes();
        $despuesDeLaPrimera = $this->registradas();

        $hechos = $migrador->aplicarPendientes();

        self::assertSame(['No hay migraciones pendientes.'], $hechos);
        self::assertSame($despuesDeLaPrimera, $this->registradas());
    }

    public function testElEstadoNoAplicaNada(): void
    {
        $migrador = $this->migrador();

        $estado = $migrador->estado();

        self::assertSame([], $estado['aplicadas']);
        self::assertSame($migrador->disponibles(), $estado['pendientes']);
        self::assertSame([], $this->registradas());
        // Ni siquiera crea la tabla del registro: mirar una base no la toca.
        self::assertFalse($this->base()->query("SHOW TABLES LIKE 'schema_migrations'")->fetchColumn());
    }

    public function testElEstadoDespuesDeAplicarNoTienePendientes(): void
    {
        $migrador = $this->migrador();
        $migrador->aplicarPendientes();

        $estado = $migrador->estado();

        self::assertSame([], $estado['pendientes']);
        self::assertContains('0005-intento-login', $estado['aplicadas']);
    }

    /**
     * Una base de antes de D-13, sin la columna `consumo_esperado_por_hora`: la
     * migración 0004 tiene que agregarla. Es lo que la hace distinta de un
     * no-op, y lo que `schema.sql` solo no logra sobre una base que ya existe.
     */
    public function testLaMigracionDeConsumoEsperadoAgregaLaColumnaSiFalta(): void
    {
        $pdo = $this->base();
        $pdo->exec('ALTER TABLE maquinaria DROP COLUMN consumo_esperado_por_hora');

        $this->migrador()->aplicarPendientes();

        $columna = $pdo->query(
            "SELECT column_type FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'maquinaria'
                AND column_name = 'consumo_esperado_por_hora'"
        )->fetchColumn();
        self::assertSame('decimal(8,2)', strtolower((string) $columna));
    }

    /**
     * El esquema base falla después de crear algunas tablas (corte de conexión,
     * error). Sin la marca, el reintento veía la tabla `proyecto`, creía que la
     * base ya existía y la dejaba sin el resto del esquema, para siempre.
     */
    public function testUnEsquemaBaseAMediasSeCompletaAlReintentar(): void
    {
        $this->vaciarLaBaseEntera();
        $primeras = array_slice(Migrador::sentencias((string) file_get_contents(self::ESQUEMA)), 0, 3);
        $roto = $this->archivoTemporal(implode(";\n", $primeras) . ";\nESTO NO ES SQL;");

        try {
            try {
                (new Migrador($this->base(), self::MIGRACIONES, $roto))->aplicarPendientes();
                self::fail('Tenía que fallar con el esquema roto.');
            } catch (RuntimeException $e) {
                self::assertStringContainsString('No se pudo crear el esquema base', $e->getMessage());
            }
            self::assertContains(Migrador::MARCA_ESQUEMA_A_MEDIAS, $this->registradas());
            self::assertContains('proyecto', $this->tablas());
            self::assertNotContains('maquinaria', $this->tablas());

            $hechos = $this->migrador()->aplicarPendientes();

            self::assertStringContainsString('a medias', implode(' ', $hechos));
            self::assertContains('maquinaria', $this->tablas());
            self::assertNotContains(Migrador::MARCA_ESQUEMA_A_MEDIAS, $this->registradas());
            self::assertSame([], $this->migrador()->estado()['pendientes']);
        } finally {
            unlink($roto);
        }
    }

    /**
     * Una migración que falla a la mitad: las anteriores quedan aplicadas y
     * registradas, ella no, y quien corre lo ve apenas ocurre. Corregida la
     * causa, el reintento sigue desde ahí.
     */
    public function testUnaMigracionQueFallaNoQuedaRegistradaYSeReintenta(): void
    {
        $directorio = $this->directorioTemporal();
        file_put_contents($directorio . '/9001-prueba-ok.sql', 'CREATE TABLE IF NOT EXISTS zz_prueba_b03 (id INT);');
        file_put_contents($directorio . '/9002-prueba-mala.sql', 'ESTO NO ES SQL;');
        $migrador = new Migrador($this->base(), $directorio, self::ESQUEMA);
        $vistas = [];

        try {
            try {
                $migrador->aplicarPendientes(static function (string $linea) use (&$vistas): void {
                    $vistas[] = $linea;
                });
                self::fail('Tenía que fallar la migración 9002.');
            } catch (RuntimeException $e) {
                self::assertStringContainsString('9002-prueba-mala', $e->getMessage());
            }

            self::assertContains('Aplicada 9001-prueba-ok', $vistas);
            self::assertContains('9001-prueba-ok', $this->registradas());
            self::assertNotContains('9002-prueba-mala', $this->registradas());

            file_put_contents($directorio . '/9002-prueba-mala.sql', 'DO 0;');
            $hechos = $migrador->aplicarPendientes();

            self::assertSame(['Aplicada 9002-prueba-mala'], $hechos);
        } finally {
            $this->base()->exec('DROP TABLE IF EXISTS zz_prueba_b03');
            array_map('unlink', glob($directorio . '/*.sql') ?: []);
            rmdir($directorio);
        }
    }

    /** Dos corridas a la vez aplicaban la misma migración dos veces. */
    public function testUnaSegundaCorridaSimultaneaFallaEnVezDeAplicarDosVeces(): void
    {
        ['host' => $host, 'puerto' => $puerto, 'usuario' => $usuario, 'clave' => $clave] = self::datosDeConexion();
        $otra = new PDO(
            "mysql:host={$host};port={$puerto};dbname=" . (string) getenv('SGSO_TEST_DB_NAME') . ';charset=utf8mb4',
            $usuario,
            $clave,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $otra->query("SELECT GET_LOCK('sgso_migrar', 0)")->fetchColumn();

        try {
            $this->migrador()->aplicarPendientes();
            self::fail('Tenía que negarse: hay otra corrida en curso.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('otra corrida', $e->getMessage());
        } finally {
            $otra->query("SELECT RELEASE_LOCK('sgso_migrar')")->fetchColumn();
        }

        // Y una vez libre, la corrida normal anda.
        self::assertNotSame([], $this->migrador()->aplicarPendientes());
    }

    public function testElEstadoAvisaQueLaPrimeraCorridaSoloRegistraLasVersionesBase(): void
    {
        self::assertTrue($this->migrador()->estado()['baseExistenteSinRegistro']);

        $this->migrador()->aplicarPendientes();

        self::assertFalse($this->migrador()->estado()['baseExistenteSinRegistro']);
    }

    // ----------------------------------------------------------------

    private const ESQUEMA = __DIR__ . '/../../sql/schema.sql';
    private const MIGRACIONES = __DIR__ . '/../../sql/migraciones';

    private function archivoTemporal(string $contenido): string
    {
        $ruta = sys_get_temp_dir() . '/sgso-esquema-' . uniqid() . '.sql';
        file_put_contents($ruta, $contenido);

        return $ruta;
    }

    private function directorioTemporal(): string
    {
        $directorio = sys_get_temp_dir() . '/sgso-migraciones-' . uniqid();
        mkdir($directorio);

        return $directorio;
    }

    private function migrador(): Migrador
    {
        return new Migrador($this->base(), __DIR__ . '/../../sql/migraciones', __DIR__ . '/../../sql/schema.sql');
    }

    /**
     * Tablas y columnas con su tipo: la foto que no tiene que cambiar.
     *
     * `schema_migrations` queda afuera a propósito: es el registro del propio
     * migrador, no parte del esquema de la aplicación, y por eso no está en
     * `schema.sql`.
     */
    private function esquema(): string
    {
        $filas = $this->base()->query(
            "SELECT table_name, column_name, column_type, is_nullable, column_default
               FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name <> 'schema_migrations'
              ORDER BY table_name, column_name"
        )->fetchAll(PDO::FETCH_ASSOC);

        return (string) json_encode($filas);
    }

    /** @return list<string> */
    private function tablas(): array
    {
        $filas = $this->base()->query(
            'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()'
        )->fetchAll(PDO::FETCH_COLUMN);

        return array_map('strval', $filas);
    }

    /** @return list<string> */
    private function registradas(): array
    {
        $tabla = $this->base()->query("SHOW TABLES LIKE 'schema_migrations'")->fetchColumn();
        if ($tabla === false) {
            return [];
        }

        $filas = $this->base()->query('SELECT version FROM schema_migrations ORDER BY version')
            ->fetchAll(PDO::FETCH_COLUMN);

        return array_map('strval', $filas);
    }

    private function vaciarLaBaseEntera(): void
    {
        $pdo = $this->base();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($this->tablas() as $tabla) {
            $pdo->exec('DROP TABLE IF EXISTS `' . $tabla . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}
