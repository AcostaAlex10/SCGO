<?php

declare(strict_types=1);

namespace Sgso\Tests\Reglas;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sgso\Reglas\Certificacion;

/** El monto certificado de una obra a la fecha (RF15, D-09). */
#[CoversClass(Certificacion::class)]
final class CertificacionTest extends TestCase
{
    /** @return iterable<string, array{float, float, float}> */
    public static function casos(): iterable
    {
        yield 'sin avance no se certifica nada' => [1_000_000, 0, 0.0];
        yield 'un cuarto de la obra' => [1_000_000, 25, 250_000.0];
        yield 'la obra completa certifica el presupuesto' => [987_654.32, 100, 987_654.32];
        yield 'con centavos' => [987_654.32, 25, 246_913.58];
        yield 'redondea al centavo, no al peso' => [1_000.50, 33, 330.17];
        yield 'avance con decimales' => [1_234_567.89, 12.5, 154_320.99];
    }

    #[DataProvider('casos')]
    public function testElMontoEsElPresupuestoPorElAvance(float $presupuesto, float $avance, float $esperado): void
    {
        self::assertSame($esperado, Certificacion::monto($presupuesto, $avance));
    }
}
