<?php

declare(strict_types=1);

namespace Sgso\Tests\Migraciones;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sgso\Migraciones\Migrador;

/**
 * Lo del migrador que se puede probar sin base (plan de producto, B-03).
 *
 * El efecto sobre el esquema lo prueba `Integracion\MigracionesTest`, contra
 * MariaDB. Acá están el separador de sentencias, que es donde un script mal
 * partido rompería la migración a mitad de camino, y el orden de los archivos.
 */
#[CoversClass(Migrador::class)]
final class MigradorTest extends TestCase
{
    public function testSeparaLasSentenciasPorPuntoYComa(): void
    {
        $sql = "CREATE TABLE a (id INT);\nCREATE TABLE b (id INT);\n";

        self::assertSame(
            ['CREATE TABLE a (id INT)', 'CREATE TABLE b (id INT)'],
            Migrador::sentencias($sql)
        );
    }

    /**
     * Un `;` dentro de un comentario partía la sentencia en dos mitades
     * inválidas. Por eso los comentarios se sacan antes de separar.
     */
    public function testUnPuntoYComaComentadoNoParteLaSentencia(): void
    {
        $sql = "-- ojo; esto es un comentario\nCREATE TABLE a (id INT);";

        self::assertSame(['CREATE TABLE a (id INT)'], Migrador::sentencias($sql));
    }

    public function testIgnoraElEspacioYLasSentenciasVacias(): void
    {
        self::assertSame([], Migrador::sentencias("-- solo comentarios\n\n   ;  ;\n"));
    }

    public function testLasVersionesBaseSonLasTresAnterioresAEsteSistema(): void
    {
        self::assertSame(
            ['0001-esquema-base', '0002-estado-enum', '0003-reporte-final'],
            Migrador::VERSIONES_BASE
        );
    }

    public function testLasMigracionesDelRepositorioEstanBienNombradasYOrdenadas(): void
    {
        $versiones = $this->migradorDe(__DIR__ . '/../../sql/migraciones')->disponibles();

        self::assertNotSame([], $versiones, 'No se encontró ninguna migración en back/sql/migraciones');
        $ordenadas = $versiones;
        sort($ordenadas);
        self::assertSame($ordenadas, $versiones);
        // Ninguna puede pisar una versión base ya registrada.
        foreach ($versiones as $version) {
            self::assertNotContains($version, Migrador::VERSIONES_BASE);
        }
    }

    public function testRechazaUnNombreQueNoSigueElFormato(): void
    {
        $directorio = sys_get_temp_dir() . '/scgo-migraciones-' . uniqid();
        mkdir($directorio);
        file_put_contents($directorio . '/agregar_columna.sql', 'SELECT 1;');

        try {
            $this->expectException(RuntimeException::class);
            $this->migradorDe($directorio)->disponibles();
        } finally {
            unlink($directorio . '/agregar_columna.sql');
            rmdir($directorio);
        }
    }

    /** El migrador no toca la base hasta que se le pide aplicar algo. */
    private function migradorDe(string $directorio): Migrador
    {
        return new Migrador($this->createStub(\PDO::class), $directorio, __DIR__ . '/../../sql/schema.sql');
    }
}
