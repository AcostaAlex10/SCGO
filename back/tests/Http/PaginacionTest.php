<?php

declare(strict_types=1);

namespace Sgso\Tests\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sgso\Http\Paginacion;

/** Paginacion a pedido de los listados (C-03). */
#[CoversClass(Paginacion::class)]
final class PaginacionTest extends TestCase
{
    public function testSinParametrosNoHayPaginacion(): void
    {
        self::assertNull(Paginacion::desdeConsulta([]));
        self::assertNull(Paginacion::desdeConsulta(['limite' => '', 'desde' => '']));
        self::assertNull(Paginacion::desdeConsulta(['q' => 'algo']));
    }

    public function testLeeElLimiteYElDesde(): void
    {
        $pagina = Paginacion::desdeConsulta(['limite' => '50', 'desde' => '100']);

        self::assertInstanceOf(Paginacion::class, $pagina);
        self::assertSame(50, $pagina->limite);
        self::assertSame(100, $pagina->desde);
        self::assertSame(' LIMIT 50 OFFSET 100', $pagina->sql());
    }

    public function testSinDesdeEmpiezaDelPrincipio(): void
    {
        $pagina = Paginacion::desdeConsulta(['limite' => '20']);

        self::assertInstanceOf(Paginacion::class, $pagina);
        self::assertSame(0, $pagina->desde);
    }

    public function testElLimiteTieneUnMaximo(): void
    {
        $pagina = Paginacion::desdeConsulta(['limite' => '5000']);

        self::assertInstanceOf(Paginacion::class, $pagina);
        self::assertSame(Paginacion::MAXIMO, $pagina->limite);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function consultasInvalidas(): iterable
    {
        yield 'limite cero' => [['limite' => '0'], 'limite'];
        yield 'limite negativo' => [['limite' => '-5'], 'limite'];
        yield 'limite con texto' => [['limite' => '10abc'], 'limite'];
        yield 'limite decimal' => [['limite' => '2.5'], 'limite'];
        yield 'desde sin limite' => [['desde' => '10'], 'limite'];
        yield 'desde negativo' => [['limite' => '10', 'desde' => '-1'], 'desde'];
        yield 'desde con texto' => [['limite' => '10', 'desde' => 'x'], 'desde'];
        yield 'inyeccion' => [['limite' => '10; DROP TABLE usuario'], 'limite'];
    }

    /** @param array<string, mixed> $consulta */
    #[DataProvider('consultasInvalidas')]
    public function testUnValorInvalidoEsUnErrorDelParametro(array $consulta, string $campo): void
    {
        $resultado = Paginacion::desdeConsulta($consulta);

        self::assertIsArray($resultado);
        self::assertArrayHasKey($campo, $resultado);
    }
}
