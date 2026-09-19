<?php

declare(strict_types=1);

namespace Sgso\Tests\Http;

use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sgso\Http\Salud;

/**
 * `/api/health` le pregunta a la base (plan de producto, B-05).
 *
 * Antes contestaba `{"status":"ok"}` sin ejecutar nada: decía que el sistema
 * andaba aunque la base no respondiera consultas. Estas pruebas usan una base
 * simulada; la que corre `SELECT 1` de verdad es
 * `Integracion\SaludConBaseTest`.
 */
#[CoversClass(Salud::class)]
final class SaludTest extends TestCase
{
    public function testReportaLaBaseCuandoRespondeAlSelect1(): void
    {
        $db = $this->createMock(PDO::class);
        $db->expects(self::once())
            ->method('query')
            ->with('SELECT 1')
            ->willReturn($this->resultado(1));

        self::assertSame(['status' => 'ok', 'db' => 'ok'], Salud::comprobar($db));
    }

    /**
     * La falla no se atrapa: sigue hasta el manejador global, que contesta el
     * 500 genérico con código de referencia (A-04). Es lo mismo que pasa si la
     * base ni siquiera acepta la conexión, y lo que verifica el CI con la
     * imagen de Docker.
     */
    public function testSiLaConsultaFallaNoDiceOk(): void
    {
        $db = $this->createStub(PDO::class);
        $db->method('query')->willThrowException(new PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away'));

        $this->expectException(PDOException::class);
        Salud::comprobar($db);
    }

    public function testSiLaBaseContestaOtraCosaNoDiceOk(): void
    {
        $db = $this->createStub(PDO::class);
        $db->method('query')->willReturn($this->resultado(false));

        $this->expectException(RuntimeException::class);
        Salud::comprobar($db);
    }

    /** Con ERRMODE_SILENT, PDO devuelve false en vez de lanzar. */
    public function testSiLaConsultaDevuelveFalseNoDiceOk(): void
    {
        $db = $this->createStub(PDO::class);
        $db->method('query')->willReturn(false);

        $this->expectException(RuntimeException::class);
        Salud::comprobar($db);
    }

    private function resultado(mixed $valor): PDOStatement
    {
        $resultado = $this->createStub(PDOStatement::class);
        $resultado->method('fetchColumn')->willReturn($valor);

        return $resultado;
    }
}
