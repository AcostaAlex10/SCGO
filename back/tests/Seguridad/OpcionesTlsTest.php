<?php

declare(strict_types=1);

namespace Sgso\Tests\Seguridad;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sgso\Seguridad\OpcionesTls;
use Throwable;

#[CoversClass(OpcionesTls::class)]
final class OpcionesTlsTest extends TestCase
{
    /** Ejecuta y devuelve la excepcion, o null si no hubo. Sin try/catch en las pruebas: AssertionFailedError es una RuntimeException. */
    private static function excepcion(?string $ssl, ?string $ca): ?Throwable
    {
        try {
            OpcionesTls::desde($ssl, $ca);
        } catch (RuntimeException $e) {
            return $e;
        }
        return null;
    }

    public function testSinDbSslNoAgregaOpcionesTls(): void
    {
        self::assertSame([], OpcionesTls::desde(null, null));
        self::assertSame([], OpcionesTls::desde('false', '/no/importa'));
        self::assertSame([], OpcionesTls::desde('', null));
    }

    /** @return array<string, array{string}> */
    public static function valoresMalEscritos(): array
    {
        return [
            'mayusculas' => ['TRUE'],
            'uno' => ['1'],
            'yes' => ['yes'],
            'espacio' => [' true'],
            'comillas' => ['"true"'],
            'False' => ['False'],
        ];
    }

    #[DataProvider('valoresMalEscritos')]
    public function testUnValorMalEscritoDeDbSslFallaSinMostrarloEnElMensaje(string $valor): void
    {
        // Con una CA valida: si no fallara por DB_SSL, devolveria opciones.
        $ca = tempnam(sys_get_temp_dir(), 'ca');
        self::assertIsString($ca);
        try {
            $e = self::excepcion($valor, $ca);
        } finally {
            unlink($ca);
        }

        self::assertNotNull($e, "DB_SSL={$valor} debia fallar");
        self::assertStringContainsString('DB_SSL', $e->getMessage());
        self::assertStringNotContainsString($valor, str_replace('DB_SSL', '', $e->getMessage()));
    }

    public function testConCaLegibleVerificaElCertificado(): void
    {
        $ca = tempnam(sys_get_temp_dir(), 'ca');
        self::assertIsString($ca);
        try {
            $opciones = OpcionesTls::desde('true', $ca);
        } finally {
            unlink($ca);
        }

        self::assertSame($ca, $opciones[PDO::MYSQL_ATTR_SSL_CA]);
        self::assertTrue($opciones[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT]);
    }

    /** @return array<string, array{string|null}> */
    public static function casosSinValor(): array
    {
        return ['null' => [null], 'vacio' => [''], 'espacios' => ['   ']];
    }

    #[DataProvider('casosSinValor')]
    public function testSinCaFallaCerradoNombrandoLaVariable(?string $ca): void
    {
        $e = self::excepcion('true', $ca);

        self::assertNotNull($e, 'Debia fallar sin DB_SSL_CA');
        self::assertStringContainsString('DB_SSL_CA', $e->getMessage());
    }

    public function testConArchivoInexistenteFallaCerrado(): void
    {
        $e = self::excepcion('true', sys_get_temp_dir() . '/sgso-no-existe-' . bin2hex(random_bytes(4)) . '.pem');

        self::assertNotNull($e);
        self::assertStringContainsString('DB_SSL_CA', $e->getMessage());
    }

    public function testConUnDirectorioComoCaFallaCerrado(): void
    {
        $e = self::excepcion('true', sys_get_temp_dir());

        self::assertNotNull($e, 'Un directorio no es un certificado');
        self::assertStringContainsString('DB_SSL_CA', $e->getMessage());
    }

    public function testConArchivoSinPermisoDeLecturaFallaCerrado(): void
    {
        $ca = tempnam(sys_get_temp_dir(), 'ca');
        self::assertIsString($ca);
        try {
            chmod($ca, 0000);
            if (is_readable($ca)) {
                self::markTestSkipped('El archivo sigue legible tras el chmod (Windows o root).');
            }
            $e = self::excepcion('true', $ca);
        } finally {
            chmod($ca, 0600);
            unlink($ca);
        }

        self::assertNotNull($e);
        self::assertStringContainsString('DB_SSL_CA', $e->getMessage());
    }

    public function testElMensajeNoFiltraLaRutaNiDatosDeConexion(): void
    {
        $ruta = '/secreto/ruta/ca-' . bin2hex(random_bytes(4)) . '.pem';
        $e = self::excepcion('true', $ruta);

        self::assertNotNull($e);
        self::assertStringNotContainsString($ruta, $e->getMessage());
    }
}
