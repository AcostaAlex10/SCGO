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
 * Lo mismo vale para la conexion a la base (A-19): MYSQL_ATTR_SSL_VERIFY_SERVER_CERT
 * en false deja cifrar sin saber con quien se habla.
 *
 * Se revisa el codigo entero y no una clase: la proxima llamada saliente queda
 * cubierta sin tener que acordarse de agregarle una prueba.
 */
#[CoversNothing]
final class VerificacionTlsTest extends TestCase
{
    /** Las formas de apagar la verificacion con streams de PHP, con cURL y con PDO. */
    private const PATRONES = [
        '/[\'"]verify_peer(_name)?[\'"]\s*=>\s*(false|0)\b/i',
        '/[\'"]allow_self_signed[\'"]\s*=>\s*(true|1)\b/i',
        '/CURLOPT_SSL_VERIFY(PEER|HOST)\s*,\s*(false|0)\b/i',
        '/(MYSQL_ATTR|Mysql::ATTR)_SSL_VERIFY_SERVER_CERT\s*\]?\s*(=>|=)\s*(false|null|0|[\'"]0[\'"]|!\s*1)(?![\w.])/i',
    ];

    /**
     * Los PHP de src (la API), sql (migraciones y seed) y public (el front controller).
     *
     * @return array<string, string>  ruta relativa a back/ => contenido
     */
    private static function codigoDelBackend(): array
    {
        $back = dirname(__DIR__, 2);
        $archivos = [];
        foreach (['src', 'sql', 'public'] as $carpeta) {
            $raiz = $back . '/' . $carpeta;
            self::assertDirectoryExists($raiz);
            /** @var SplFileInfo $archivo */
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($raiz)) as $archivo) {
                if ($archivo->getExtension() === 'php') {
                    $ruta = $carpeta . '/' . substr($archivo->getPathname(), strlen($raiz) + 1);
                    $archivos[str_replace('\\', '/', $ruta)] = (string) file_get_contents($archivo->getPathname());
                }
            }
        }
        ksort($archivos);
        return $archivos;
    }

    /** Cada conexion a la base arma sus opciones TLS con OpcionesTls, no a mano. */
    public function testTodoArchivoQueAbreUnaConexionUsaOpcionesTls(): void
    {
        $abren = 0;
        $sinOpciones = [];
        foreach (self::codigoDelBackend() as $ruta => $codigo) {
            if (preg_match('/new\s+\\\\?(PDO|Pdo\\\\Mysql)\b/i', $codigo) !== 1) {
                continue;
            }
            $abren++;
            if (!str_contains($codigo, 'OpcionesTls::desde(')) {
                $sinOpciones[] = $ruta;
            }
        }

        self::assertGreaterThan(0, $abren, 'La prueba no encontro ninguna conexion que revisar');
        self::assertSame([], $sinOpciones, 'Estos archivos abren una conexion sin usar OpcionesTls');
    }

    public function testNingunArchivoDelBackendApagaLaVerificacionTls(): void
    {
        $culpables = [];
        foreach (self::codigoDelBackend() as $ruta => $codigo) {
            foreach (self::PATRONES as $patron) {
                if (preg_match($patron, $codigo) === 1) {
                    $culpables[] = $ruta;
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
            '$o[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;',
            '$o = [PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => 0];',
            '$o = [PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => null];',
            '$o = [\Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT => "0"];',
            '$o[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = !1;',
        ];
        foreach ($ejemplos as $ejemplo) {
            $reconocido = false;
            foreach (self::PATRONES as $patron) {
                $reconocido = $reconocido || preg_match($patron, $ejemplo) === 1;
            }
            self::assertTrue($reconocido, "No reconoce: {$ejemplo}");
        }
        self::assertSame(0, preg_match(self::PATRONES[0], "'verify_peer' => true"));
        self::assertSame(
            0,
            preg_match(self::PATRONES[3], '$o[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;')
        );
    }
}
