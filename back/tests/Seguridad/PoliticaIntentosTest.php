<?php

declare(strict_types=1);

namespace Sgso\Tests\Seguridad;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sgso\Seguridad\PoliticaIntentos;

/**
 * Límite de intentos de login (plan de producto, A-03).
 */
#[CoversClass(PoliticaIntentos::class)]
final class PoliticaIntentosTest extends TestCase
{
    /** @return iterable<string, array{int}> */
    public static function pocosFallos(): iterable
    {
        foreach (range(0, PoliticaIntentos::FALLOS_TOLERADOS - 1) as $fallos) {
            yield "{$fallos} fallos" => [$fallos];
        }
    }

    #[DataProvider('pocosFallos')]
    public function testLosPrimerosFallosNoFrenan(int $fallos): void
    {
        self::assertSame(0, PoliticaIntentos::segundosDeEspera($fallos, 0));
    }

    /** @return iterable<string, array{int, int}> */
    public static function esperasPorFallos(): iterable
    {
        yield '5 fallos' => [5, 60];
        yield '6 fallos' => [6, 120];
        yield '7 fallos' => [7, 240];
        yield '8 fallos' => [8, 480];
        yield '9 fallos, ya en el tope' => [9, 900];
        yield '50 fallos, sigue en el tope' => [50, 900];
    }

    #[DataProvider('esperasPorFallos')]
    public function testLaEsperaSeDuplicaHastaElTope(int $fallos, int $segundos): void
    {
        self::assertSame($segundos, PoliticaIntentos::espera($fallos));
        self::assertSame($segundos, PoliticaIntentos::segundosDeEspera($fallos, 0));
    }

    public function testLaEsperaSeDescuentaConElTiempo(): void
    {
        // Con 5 fallos hay que esperar 60 s; pasados 45, faltan 15.
        self::assertSame(15, PoliticaIntentos::segundosDeEspera(5, 45));
    }

    public function testCumplidaLaEsperaSePuedeVolverAIntentar(): void
    {
        self::assertSame(0, PoliticaIntentos::segundosDeEspera(5, 60));
        self::assertSame(0, PoliticaIntentos::segundosDeEspera(5, 61));
    }

    public function testLosFallosViejosSeOlvidan(): void
    {
        $viejo = PoliticaIntentos::VENTANA;

        self::assertTrue(PoliticaIntentos::olvidado($viejo));
        self::assertFalse(PoliticaIntentos::olvidado($viejo - 1));
        self::assertSame(0, PoliticaIntentos::segundosDeEspera(50, $viejo));
    }

    public function testElTopeNuncaSuperaLaVentana(): void
    {
        // Si la espera maxima superara la ventana, el bloqueo se cortaria antes
        // de tiempo: al cumplirse la ventana el contador se olvida y deja pasar.
        self::assertLessThanOrEqual(PoliticaIntentos::VENTANA, PoliticaIntentos::ESPERA_MAXIMA);
    }
}
