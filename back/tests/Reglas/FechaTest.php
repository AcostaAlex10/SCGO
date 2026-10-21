<?php

declare(strict_types=1);

namespace Sgso\Tests\Reglas;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sgso\Reglas\Fecha;

/**
 * Qué texto vale como fecha (plan de producto, C-13): una fecha con la forma
 * correcta pero que no existe (2026-02-31) llegaba a la base y daba 500.
 */
#[CoversClass(Fecha::class)]
final class FechaTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function fechasValidas(): iterable
    {
        yield 'una fecha común' => ['2026-03-01'];
        yield 'bisiesto divisible por 4' => ['2028-02-29'];
        yield 'bisiesto divisible por 400' => ['2000-02-29'];
        yield 'fin de año' => ['2026-12-31'];
        yield 'piso del rango de MariaDB' => ['1000-01-01'];
        yield 'techo del rango de MariaDB' => ['9999-12-31'];
    }

    #[DataProvider('fechasValidas')]
    public function testAceptaUnaFechaQueExiste(string $texto): void
    {
        self::assertTrue(Fecha::esValida($texto), $texto);
    }

    /** @return iterable<string, array{string}> */
    public static function fechasInvalidas(): iterable
    {
        yield '29 de febrero de un año común' => ['2026-02-29'];
        yield '29 de febrero de un siglo no bisiesto' => ['1900-02-29'];
        yield '31 de febrero' => ['2026-02-31'];
        yield '31 de abril' => ['2026-04-31'];
        yield '31 de junio' => ['2026-06-31'];
        yield 'mes 13' => ['2026-13-01'];
        yield 'mes 00' => ['2026-00-10'];
        yield 'día 00' => ['2026-01-00'];
        yield 'año 0000' => ['0000-01-01'];
        yield 'año 0999, debajo del rango de MariaDB' => ['0999-12-31'];
        yield 'mes y día sin cero' => ['2026-1-1'];
        yield 'año de dos cifras' => ['26-01-01'];
        yield 'espacio adelante' => [' 2026-01-01'];
        yield 'espacio atrás' => ['2026-01-01 '];
        yield 'salto de línea atrás' => ["2026-01-01\n"];
        yield 'letra atrás' => ['2026-01-01x'];
        yield 'formato dd/mm/aaaa' => ['01/03/2026'];
        yield 'dígitos árabes' => ['٢٠٢٦-٠١-٠١'];
        yield 'vacía' => [''];
        yield 'solo espacios' => ['    '];
        yield 'con hora' => ['2026-01-01T00:00:00'];
    }

    #[DataProvider('fechasInvalidas')]
    public function testRechazaLoQueNoEsUnaFechaReal(string $texto): void
    {
        self::assertFalse(Fecha::esValida($texto), var_export($texto, true));
    }
}
