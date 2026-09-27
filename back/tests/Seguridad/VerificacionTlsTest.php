<?php

declare(strict_types=1);

namespace Sgso\Tests\Seguridad;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Ninguna llamada saliente desactiva la verificacion TLS (plan de producto, A-16).
 *
 * El backend habla con Brevo (correo, con la API key en un encabezado y el
 * enlace para restablecer la contrasena en el cuerpo), con Nominatim y con
 * Sentry. Sin verificar el certificado, cualquiera en el camino puede hacerse
 * pasar por ellos y leer lo que se manda: con la API key manda correos en
 * nombre del sistema, y con el enlace toma la cuenta.
 *
 * Se revisa el codigo entero y no una clase: la proxima llamada saliente queda
 * cubierta sin tener que acordarse de agregarle una prueba.
 */
#[CoversNothing]
final class VerificacionTlsTest extends TestCase
{
    /** Las formas de apagar la verificacion con streams de PHP y con cURL. */
    private const PATRONES = [
        '/[\'"]verify_peer(_name)?[\'"]\s*=>\s*(false|0)\b/i',
        '/[\'"]allow_self_signed[\'"]\s*=>\s*(true|1)\b/i',
        '/CURLOPT_SSL_VERIFY(PEER|HOST)\s*,\s*(false|0)\b/i',
    ];

    public function testNingunArchivoDelBackendApagaLaVerificacionTls(): void
    {
        $src = dirname(__DIR__, 2) . '/src';
        $culpables = [];
        /** @var SplFileInfo $archivo */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src)) as $archivo) {
            if ($archivo->getExtension() !== 'php') {
                continue;
            }
            $codigo = (string) file_get_contents($archivo->getPathname());
            foreach (self::PATRONES as $patron) {
                if (preg_match($patron, $codigo) === 1) {
                    $culpables[] = substr($archivo->getPathname(), strlen($src) + 1);
                    break;
                }
            }
        }
        sort($culpables);

        self::assertSame([], $culpables, 'Estos archivos apagan la verificacion TLS');
    }

    /** Que la prueba de arriba no pase por no encontrar nada que leer. */
    public function testLosPatronesReconocenLasFormasDeApagarla(): void
    {
        $ejemplos = [
            "'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]",
            '"verify_peer_name" => 0',
            "'allow_self_signed' => true",
            'curl_setopt($c, CURLOPT_SSL_VERIFYPEER, false);',
            'curl_setopt($c, CURLOPT_SSL_VERIFYHOST, 0);',
        ];
        foreach ($ejemplos as $ejemplo) {
            $reconocido = false;
            foreach (self::PATRONES as $patron) {
                $reconocido = $reconocido || preg_match($patron, $ejemplo) === 1;
            }
            self::assertTrue($reconocido, "No reconoce: {$ejemplo}");
        }
        self::assertSame(0, preg_match(self::PATRONES[0], "'verify_peer' => true"));
    }
}
