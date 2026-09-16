<?php

declare(strict_types=1);

namespace Sgso\Tests\Http;

use PDOException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sgso\Http\ManejadorErrores;

/**
 * Qué ve el cliente cuando algo falla (plan de producto, A-04).
 */
#[CoversClass(ManejadorErrores::class)]
final class ManejadorErroresTest extends TestCase
{
    /** El mensaje real de PDO cuando no puede conectarse a Aiven. */
    private const FALLA_DE_CONEXION =
        "SQLSTATE[HY000] [2002] Connection refused (host: sgso-db.aivencloud.com, user: avnadmin)";

    public function testElClienteNoVeNadaDelErrorInterno(): void
    {
        $cuerpo = ManejadorErrores::atender(new PDOException(self::FALLA_DE_CONEXION), static fn () => null);
        $json = (string) json_encode($cuerpo);

        self::assertSame(ManejadorErrores::MENSAJE, $cuerpo['error']);
        self::assertStringNotContainsString('SQLSTATE', $json);
        self::assertStringNotContainsString('aivencloud', $json);
        self::assertStringNotContainsString('avnadmin', $json);
        self::assertStringNotContainsString(__FILE__, $json);
    }

    public function testElLogGuardaTodoElDetalleConLaMismaReferencia(): void
    {
        $registrado = [];
        $cuerpo = ManejadorErrores::atender(
            new PDOException(self::FALLA_DE_CONEXION),
            static function (string $linea) use (&$registrado): void {
                $registrado[] = $linea;
            }
        );

        self::assertCount(1, $registrado);
        self::assertStringContainsString($cuerpo['referencia'], $registrado[0]);
        self::assertStringContainsString('SQLSTATE[HY000]', $registrado[0]);
        self::assertStringContainsString('PDOException', $registrado[0]);
        self::assertStringContainsString(basename(__FILE__), $registrado[0]);
    }

    public function testCadaErrorTieneUnaReferenciaDistinta(): void
    {
        $primera = ManejadorErrores::atender(new RuntimeException('a'), static fn () => null);
        $segunda = ManejadorErrores::atender(new RuntimeException('b'), static fn () => null);

        self::assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $primera['referencia']);
        self::assertNotSame($primera['referencia'], $segunda['referencia']);
    }
}
