<?php

declare(strict_types=1);

namespace Sgso\Tests\Seguridad;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sgso\Seguridad\OpcionesTls;

#[CoversClass(OpcionesTls::class)]
final class OpcionesTlsTest extends TestCase
{
    public function testSinDbSslNoAgregaOpcionesTls(): void
    {
        self::assertSame([], OpcionesTls::desde(null, null));
        self::assertSame([], OpcionesTls::desde('false', '/no/importa'));
        self::assertSame([], OpcionesTls::desde('', null));
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

    public function testSinCaFallaCerradoNombrandoLaVariable(): void
    {
        foreach ([null, '', '   '] as $ca) {
            try {
                OpcionesTls::desde('true', $ca);
                self::fail('Debia fallar sin DB_SSL_CA');
            } catch (RuntimeException $e) {
                self::assertStringContainsString('DB_SSL_CA', $e->getMessage());
            }
        }
    }

    public function testConArchivoInexistenteOIlegibleFallaCerrado(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('DB_SSL_CA');
        OpcionesTls::desde('true', sys_get_temp_dir() . '/sgso-no-existe-' . bin2hex(random_bytes(4)) . '.pem');
    }

    public function testElMensajeNoFiltraLaRutaNiDatosDeConexion(): void
    {
        $ruta = '/secreto/ruta/ca-' . bin2hex(random_bytes(4)) . '.pem';
        try {
            OpcionesTls::desde('true', $ruta);
            self::fail('Debia fallar');
        } catch (RuntimeException $e) {
            self::assertStringNotContainsString($ruta, $e->getMessage());
        }
    }
}
