<?php

declare(strict_types=1);

namespace Sgso\Tests\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sgso\Cors;

/** Los encabezados CORS que le permiten al front, desde otro origen, usar la API. */
#[CoversClass(Cors::class)]
final class CorsTest extends TestCase
{
    private const PRODUCCION = 'https://ingenieria-en-software-proyecto.vercel.app';

    public function testUnOrigenPermitidoRecibeSuPropioOrigenYVary(): void
    {
        $encabezados = Cors::encabezados(self::PRODUCCION);

        self::assertContains('Access-Control-Allow-Origin: ' . self::PRODUCCION, $encabezados);
        self::assertContains('Vary: Origin', $encabezados);
    }

    public function testUnOrigenAjenoNoRecibePermiso(): void
    {
        $encabezados = Cors::encabezados('https://sitio-ajeno.example');

        foreach ($encabezados as $encabezado) {
            self::assertStringStartsNotWith('Access-Control-Allow-Origin', $encabezado);
        }
    }

    public function testUnaPreviewDeVercelEsUnOrigenPermitido(): void
    {
        $origen = 'https://ingenieria-git-rama-acostaalex10.vercel.app';

        self::assertContains("Access-Control-Allow-Origin: {$origen}", Cors::encabezados($origen));
    }

    /** Sin Authorization permitido, el navegador bloquea todo pedido con el token. */
    public function testPermiteElEncabezadoDelToken(): void
    {
        self::assertContains('Access-Control-Allow-Headers: Content-Type, Authorization', Cors::encabezados(self::PRODUCCION));
    }

    /**
     * El total de un listado paginado va en X-Total-Count (C-03). Desde otro
     * origen, el navegador solo deja leer los encabezados que se exponen: sin
     * esto, el front no sabria cuantas filas quedan por pedir.
     */
    public function testExponeElTotalDeLosListadosPaginados(): void
    {
        self::assertContains('Access-Control-Expose-Headers: X-Total-Count', Cors::encabezados(self::PRODUCCION));
    }
}
