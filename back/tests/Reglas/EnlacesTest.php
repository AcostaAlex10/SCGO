<?php

declare(strict_types=1);

namespace Sgso\Tests\Reglas;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sgso\Reglas\Enlaces;

/**
 * Enlaces externos de los documentos (plan de producto, A-01).
 */
#[CoversClass(Enlaces::class)]
final class EnlacesTest extends TestCase
{
    /**
     * Por qué existe la clase: el filtro de PHP solo no alcanza. Si esta prueba
     * dejara de pasar, PHP habría empezado a rechazar el vector por su cuenta;
     * la regla seguiría siendo necesaria para los otros esquemas.
     */
    public function testElFiltroDePhpSoloAceptaUnEnlaceQueEjecutaCodigo(): void
    {
        self::assertNotFalse(filter_var('javascript://x%0Aalert(1)', FILTER_VALIDATE_URL));
    }

    /** @return iterable<string, array{string}> */
    public static function enlacesPeligrosos(): iterable
    {
        yield 'javascript con salto codificado' => ['javascript://x%0Aalert(document.cookie)'];
        yield 'javascript en mayusculas' => ['JavaScript://x%0Aalert(1)'];
        yield 'javascript directo' => ['javascript:alert(1)'];
        yield 'data con html' => ['data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg=='];
        yield 'vbscript' => ['vbscript://x%0Amsgbox(1)'];
        yield 'file local' => ['file:///etc/passwd'];
        yield 'ftp' => ['ftp://archivos.example.com/plano.pdf'];
        yield 'relativo al protocolo' => ['//evil.example.com/plano.pdf'];
        yield 'sin esquema' => ['drive.google.com/archivo'];
        yield 'espacio adelante' => [' https://drive.google.com/archivo'];
        yield 'tabulacion dentro del esquema' => ["java\tscript://x%0Aalert(1)"];
        yield 'salto de linea real' => ["https://drive.google.com/\nx"];
        yield 'https sin host' => ['https://'];
        yield 'vacio' => [''];
    }

    #[DataProvider('enlacesPeligrosos')]
    public function testRechazaLoQueNoEsHttpOHttpsConHost(string $url): void
    {
        self::assertFalse(Enlaces::esSeguro($url));
    }

    /** @return iterable<string, array{string}> */
    public static function enlacesValidos(): iterable
    {
        yield 'google drive' => ['https://drive.google.com/file/d/abc123/view'];
        yield 'http simple' => ['http://example.com/plano.pdf'];
        yield 'con puerto y consulta' => ['https://archivos.triwe.com.ar:8443/obra?id=5&tipo=pdf'];
        yield 'esquema en mayusculas' => ['HTTPS://example.com/plano.pdf'];
        yield 'con ancla' => ['https://example.com/informe#pagina-3'];
    }

    #[DataProvider('enlacesValidos')]
    public function testAceptaEnlacesHttpYHttpsComunes(string $url): void
    {
        self::assertTrue(Enlaces::esSeguro($url));
    }
}
