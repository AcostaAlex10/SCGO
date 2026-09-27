<?php

declare(strict_types=1);

namespace Sgso\Tests\Reglas;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sgso\Reglas\ConsumoMaquinaria;

/** Cuándo un registro de uso de una máquina consume de más (RF24). */
#[CoversClass(ConsumoMaquinaria::class)]
final class ConsumoMaquinariaTest extends TestCase
{
    public function testElConsumoPorHoraEsElCombustibleSobreLasHoras(): void
    {
        self::assertEqualsWithDelta(12.5, ConsumoMaquinaria::porHora(50, 4), 0.0001);
    }

    /** Un registro sin horas no divide por cero: no consumió nada por hora. */
    public function testSinHorasElConsumoPorHoraEsCero(): void
    {
        self::assertSame(0.0, ConsumoMaquinaria::porHora(30, 0));
    }

    public function testEsAnomaloAlSuperarUnaVezYMediaElPromedio(): void
    {
        self::assertTrue(ConsumoMaquinaria::esAnomalo(15.01, 10));
    }

    /** El umbral es estricto: justo una vez y media todavía es normal. */
    public function testJustoUnaVezYMediaNoEsAnomalo(): void
    {
        self::assertFalse(ConsumoMaquinaria::esAnomalo(15, 10));
        self::assertFalse(ConsumoMaquinaria::esAnomalo(12, 10));
    }

    /** Sin promedio no hay contra qué comparar: ningún registro alerta. */
    public function testSinPromedioNadaEsAnomalo(): void
    {
        self::assertFalse(ConsumoMaquinaria::esAnomalo(40, 0));
    }
}
