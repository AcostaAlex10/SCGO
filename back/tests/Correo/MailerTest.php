<?php

declare(strict_types=1);

namespace Sgso\Tests\Correo;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sgso\Mailer;

/**
 * Por qué no salió un correo.
 *
 * Mailer contestaba solo `false`, y quien lo llama no puede decirle nada al
 * usuario: la recuperación de contraseña responde siempre lo mismo. Un correo
 * que no salía no dejaba ningún rastro en el log de Render, y "no me llega el
 * mail" no se podía distinguir de "Brevo rechazó la clave" ni de "el remitente
 * no está verificado". Ahora el motivo queda en el log.
 */
#[CoversClass(Mailer::class)]
final class MailerTest extends TestCase
{
    private const CLAVE = 'xkeysib-clave-de-prueba-que-no-es-real';

    private string $log = '';

    private string|false $logAnterior = false;

    /** @var array<string, string|false> */
    private array $entornoAnterior = [];

    protected function setUp(): void
    {
        $this->log = (string) tempnam(sys_get_temp_dir(), 'mailer');
        $this->logAnterior = ini_set('error_log', $this->log);
        foreach (['BREVO_API_KEY', 'BREVO_SENDER'] as $variable) {
            $this->entornoAnterior[$variable] = getenv($variable);
            putenv($variable);
        }
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->logAnterior === false ? '' : $this->logAnterior);
        @unlink($this->log);
        foreach ($this->entornoAnterior as $variable => $valor) {
            putenv($valor === false ? $variable : "{$variable}={$valor}");
        }
    }

    public function testUnCorreoAceptadoNoTieneMotivoDeFalla(): void
    {
        self::assertNull(Mailer::motivoDeFalla('{"messageId":"<1@brevo>"}', 'HTTP/1.1 201 Created', '', self::CLAVE));
    }

    public function testUnaClaveRechazadaDiceQueContestoBrevo(): void
    {
        $motivo = Mailer::motivoDeFalla(
            '{"code":"unauthorized","message":"Key not found"}',
            'HTTP/1.1 401 Unauthorized',
            '',
            self::CLAVE
        );

        self::assertNotNull($motivo);
        self::assertStringContainsString('401', $motivo);
        self::assertStringContainsString('unauthorized', $motivo);
        self::assertStringContainsString('Key not found', $motivo);
    }

    public function testUnRemitenteSinVerificarTambienSeVe(): void
    {
        $motivo = (string) Mailer::motivoDeFalla(
            '{"code":"invalid_parameter","message":"Sender is not valid"}',
            'HTTP/1.1 400 Bad Request',
            '',
            self::CLAVE
        );

        self::assertStringContainsString('400', $motivo);
        self::assertStringContainsString('Sender is not valid', $motivo);
    }

    /** Sin respuesta: la red, el DNS, el tiempo de espera o el certificado. */
    public function testSiBrevoNoRespondeQuedaElErrorDeRed(): void
    {
        $motivo = (string) Mailer::motivoDeFalla(
            false,
            '',
            'file_get_contents(): SSL operation failed with code 1',
            self::CLAVE
        );

        self::assertStringContainsString('SSL operation failed', $motivo);
    }

    public function testUnaRespuestaQueNoEsJsonSeRecortaPeroSeVe(): void
    {
        $motivo = (string) Mailer::motivoDeFalla(
            '<html>' . str_repeat('x', 5000) . '</html>',
            'HTTP/1.1 502 Bad Gateway',
            '',
            self::CLAVE
        );

        self::assertStringContainsString('502', $motivo);
        self::assertStringContainsString('<html>', $motivo);
        self::assertLessThan(400, strlen($motivo), 'una página de error entera no entra en una línea de log');
    }

    /** El log de Render lo lee más gente que la que tiene acceso a las variables de entorno. */
    public function testLaClaveNuncaQuedaEnElMotivo(): void
    {
        $motivo = (string) Mailer::motivoDeFalla(
            '{"code":"unauthorized","message":"Key ' . self::CLAVE . ' not found"}',
            'HTTP/1.1 401 Unauthorized',
            'fallo con ' . self::CLAVE,
            self::CLAVE
        );

        self::assertStringNotContainsString(self::CLAVE, $motivo);
        self::assertStringContainsString('[oculto]', $motivo);
    }

    public function testSinCredencialesElLogDiceCualFalta(): void
    {
        putenv('BREVO_API_KEY=' . self::CLAVE);

        self::assertFalse(Mailer::enviar('alguien@sgso.test', 'Asunto de prueba', '<p>hola</p>'));

        $log = (string) file_get_contents($this->log);
        self::assertStringContainsString('BREVO_SENDER', $log);
        self::assertStringNotContainsString('BREVO_API_KEY', $log, 'la clave está: no es la que falta');
        self::assertStringContainsString('Asunto de prueba', $log);
        self::assertStringNotContainsString(self::CLAVE, $log);
        self::assertStringNotContainsString('alguien@sgso.test', $log);
    }
}
