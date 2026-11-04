<?php

declare(strict_types=1);

namespace Sgso\Tests\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sgso\Cors;

/** Los encabezados CORS que le permiten al front, desde otro origen, usar la API. */
#[CoversClass(Cors::class)]
final class CorsTest extends TestCase
{
    private const PRODUCCION = 'https://sgsos.vercel.app';

    private string|false $corsOriginAnterior;

    protected function setUp(): void
    {
        $this->corsOriginAnterior = getenv('CORS_ORIGIN');
        putenv('CORS_ORIGIN');
    }

    protected function tearDown(): void
    {
        putenv($this->corsOriginAnterior === false ? 'CORS_ORIGIN' : "CORS_ORIGIN={$this->corsOriginAnterior}");
    }

    public function testUnOrigenPermitidoRecibeSuPropioOrigenYVary(): void
    {
        $encabezados = Cors::encabezados(self::PRODUCCION);

        self::assertContains('Access-Control-Allow-Origin: ' . self::PRODUCCION, $encabezados);
        self::assertContains('Vary: Origin', $encabezados);
    }

    /** La URL anterior sigue conectada en Vercel: quien la tenga guardada puede entrar. */
    public function testLaUrlAnteriorDeProduccionSigueSiendoUnOrigenPermitido(): void
    {
        $origen = 'https://ingenieria-en-software-proyecto.vercel.app';

        self::assertContains("Access-Control-Allow-Origin: {$origen}", Cors::encabezados($origen));
    }

    public function testUnOrigenAjenoNoRecibePermiso(): void
    {
        self::assertNoRecibePermiso('https://sitio-ajeno.example');
    }

    /** @return array<string, array{string}> */
    public static function previewsDelProyecto(): array
    {
        return [
            // Copiada del comentario de Vercel en el PR #91.
            'la URL de una rama' => ['https://sgso-git-claude-url-sgsos-acostaalex10s-projects.vercel.app'],
            'la URL de un commit' => ['https://sgso-a1b2c3d4e-acostaalex10s-projects.vercel.app'],
            'una rama larga, que Vercel acorta con un hash' => ['https://sgso-git-b1ef2e-acostaalex10s-projects.vercel.app'],
        ];
    }

    #[DataProvider('previewsDelProyecto')]
    public function testUnaPreviewDelProyectoEsUnOrigenPermitido(string $origen): void
    {
        self::assertContains("Access-Control-Allow-Origin: {$origen}", Cors::encabezados($origen));
    }

    /** @return array<string, array{string}> */
    public static function origenesQueImitanUnaPreview(): array
    {
        return [
            'el ejemplo que el comodín anterior aceptaba' => ['https://evil-acostaalex10.vercel.app'],
            'una ruta que el comodín anterior aceptaba' => ['https://evil.example/x-acostaalex10.vercel.app'],
            'otro proyecto de la misma cuenta' => ['https://otro-git-rama-acostaalex10s-projects.vercel.app'],
            'el proyecto en otra cuenta' => ['https://sgso-git-rama-otra-cuenta.vercel.app'],
            'con una ruta' => ['https://sgso-git-rama-acostaalex10s-projects.vercel.app/ruta'],
            'con la forma de la preview en la ruta' => ['https://evil.example/sgso-git-rama-acostaalex10s-projects.vercel.app'],
            'con una barra en el comodín' => ['https://sgso-evil.example/x-acostaalex10s-projects.vercel.app'],
            'con un punto en el comodín' => ['https://sgso-git.evil.example-acostaalex10s-projects.vercel.app'],
            'con un dominio ajeno al final' => ['https://sgso-git-rama-acostaalex10s-projects.vercel.app.evil.example'],
            'con un puerto' => ['https://sgso-git-rama-acostaalex10s-projects.vercel.app:8443'],
            'sin HTTPS' => ['http://sgso-git-rama-acostaalex10s-projects.vercel.app'],
            'con un salto de línea al final' => ["https://sgso-git-rama-acostaalex10s-projects.vercel.app\n"],
        ];
    }

    #[DataProvider('origenesQueImitanUnaPreview')]
    public function testUnOrigenQueImitaUnaPreviewNoRecibePermiso(string $origen): void
    {
        self::assertNoRecibePermiso($origen);
    }

    /**
     * Límite conocido (R-08 en OPERACION.md): el patrón mira la forma del origen, no
     * quién lo controla. Esto puede ser la rama `main-evil` de nuestro proyecto o el
     * proyecto `sgso` de un equipo `evil-acostaalex10s-projects`, y no se distinguen.
     * Si esta prueba molesta porque se sumaron credenciales, hay que sacar el comodín.
     */
    public function testElPatronNoDistingueUnaCuentaConElMismoSufijo(): void
    {
        $origen = 'https://sgso-git-main-evil-acostaalex10s-projects.vercel.app';

        self::assertContains("Access-Control-Allow-Origin: {$origen}", Cors::encabezados($origen));
        self::assertNotContains('Access-Control-Allow-Credentials: true', Cors::encabezados($origen));
    }

    /** @return array<string, array{string}> */
    public static function origenesDeDesarrollo(): array
    {
        return [
            'vite dev' => ['http://localhost:5173'],
            'vite preview' => ['http://localhost:4173'],
            'vite dev por IP' => ['http://127.0.0.1:5173'],
            'vite preview por IP' => ['http://127.0.0.1:4173'],
        ];
    }

    #[DataProvider('origenesDeDesarrollo')]
    public function testElDesarrolloLocalEsUnOrigenPermitido(string $origen): void
    {
        self::assertContains("Access-Control-Allow-Origin: {$origen}", Cors::encabezados($origen));
    }

    /** Así lo configura la guía de despliegue on-premise: un origen exacto, o varios separados por coma. */
    public function testCorsOriginAceptaOrigenesExactosSeparadosPorComa(): void
    {
        putenv('CORS_ORIGIN=http://192.168.1.50, https://obra.cliente.example');

        foreach (['http://192.168.1.50', 'https://obra.cliente.example'] as $origen) {
            self::assertContains("Access-Control-Allow-Origin: {$origen}", Cors::encabezados($origen));
        }
        self::assertNoRecibePermiso('http://192.168.1.51');
    }

    /** Un comodín de CORS_ORIGIN vale lo mismo que uno de la lista por defecto: una sola etiqueta. */
    public function testUnComodinDelEntornoCubreUnaSolaEtiqueta(): void
    {
        putenv('CORS_ORIGIN=https://*.cliente.example');

        $origen = 'https://app.cliente.example';
        self::assertContains("Access-Control-Allow-Origin: {$origen}", Cors::encabezados($origen));
        self::assertNoRecibePermiso('https://a.b.cliente.example');
        self::assertNoRecibePermiso('https://evil.example/x.cliente.example');
    }

    /** Un `*` solo no abre la API a cualquier origen. */
    public function testUnComodinSoloEnElEntornoNoPermiteCualquierOrigen(): void
    {
        putenv('CORS_ORIGIN=*');

        self::assertNoRecibePermiso('https://sitio-ajeno.example');
    }

    private static function assertNoRecibePermiso(string $origen): void
    {
        foreach (Cors::encabezados($origen) as $encabezado) {
            self::assertStringStartsNotWith('Access-Control-Allow-Origin', $encabezado);
        }
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
