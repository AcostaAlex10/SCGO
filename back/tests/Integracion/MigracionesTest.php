<?php

declare(strict_types=1);

namespace Sgso\Tests\Integracion;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
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
    }

    // ----------------------------------------------------------------

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
