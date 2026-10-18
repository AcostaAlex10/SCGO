<?php

declare(strict_types=1);

namespace Sgso\Tests\Reglas;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sgso\Reglas\StockMaterial;

#[CoversClass(StockMaterial::class)]
final class StockMaterialTest extends TestCase
{
    /** @return array<string, array{float, float, float, bool}> */
    public static function consumos(): array
    {
        return [
            'menos de lo que queda' => [10, 4, 5, true],
            'justo lo que queda' => [10, 4, 6, true],
            'un centésimo de más' => [10, 4, 6.01, false],
            'decimales que suman exacto' => [10, 6.6, 3.4, true],
            'asignación ya excedida' => [10, 12, 0.01, false],
        ];
    }

    #[DataProvider('consumos')]
    public function testUnConsumoAlcanzaSiNoSuperaLoQueQueda(float $asignada, float $consumido, float $cantidad, bool $alcanza): void
    {
        self::assertSame($alcanza, StockMaterial::alcanza($asignada, $consumido, $cantidad));
    }

    public function testLoQueQuedaNoEsNegativoAunqueHayaUnExcesoViejo(): void
    {
        self::assertSame(6.0, StockMaterial::restante(10, 4));
        self::assertSame(0.0, StockMaterial::restante(10, 12));
        self::assertSame(0.4, StockMaterial::restante(10, 9.6));
        // 0,29 * 100 da 28,999...: sin redondear, quedarían 0,72.
        self::assertSame(0.71, StockMaterial::restante(1, 0.29));
    }
}
