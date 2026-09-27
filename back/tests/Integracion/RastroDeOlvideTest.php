<?php

declare(strict_types=1);

namespace Sgso\Tests\Integracion;

use PHPUnit\Framework\Attributes\CoversClass;
use Sgso\AuthController;

/**
 * Qué deja en el log cada pedido de recuperación de contraseña.
 *
 * `/auth/olvide` contesta lo mismo pase lo que pase, para no delatar qué
 * emails tienen cuenta. Del lado del servidor tampoco quedaba nada, así que
 * "pedí el correo y no llega" no tenía por dónde empezar: podía ser un email
 * sin cuenta, un pedido repetido, Brevo o un enlace mal armado.
 *
 * El log es del servidor y no lleva el email ni el token: con el token, quien
 * lea el log entra a la cuenta.
 */
#[CoversClass(AuthController::class)]
final class RastroDeOlvideTest extends CasoConBase
{
    private string $log = '';

    private string|false $logAnterior = false;

    /** @var array<string, string|false> */
    private array $entornoAnterior = [];

    /** @var list<string> */
    private array $enviados = [];

    private bool $elCorreoSale = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->log = (string) tempnam(sys_get_temp_dir(), 'olvide');
        $this->logAnterior = ini_set('error_log', $this->log);
        foreach (['APP_URL', 'CORS_ORIGIN'] as $variable) {
            $this->entornoAnterior[$variable] = getenv($variable);
        }
        putenv('APP_URL=https://obras.triwe.test/');
        putenv('CORS_ORIGIN');
        $this->enviados = [];
        $this->elCorreoSale = true;
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->logAnterior === false ? '' : $this->logAnterior);
        @unlink($this->log);
        foreach ($this->entornoAnterior as $variable => $valor) {
            putenv($valor === false ? $variable : "{$variable}={$valor}");
        }
        parent::tearDown();
    }

    public function testUnEmailSinCuentaDejaDichoQueNoSeMandoNada(): void
    {
        $this->pedir('nadie@triwe.test');

        self::assertSame([], $this->enviados);
        self::assertStringContainsString('no se mandó correo', $this->log());
        self::assertStringNotContainsString('nadie@triwe.test', $this->log());
    }

    public function testUnCorreoQueSaleDiceADondeApuntaElEnlace(): void
    {
        $email = $this->crearCuenta();

        $this->pedir($email);

        self::assertCount(1, $this->enviados);
        self::assertStringContainsString('correo de recuperación enviado', $this->log());
        self::assertStringContainsString('https://obras.triwe.test/restablecer', $this->log());
    }

    /** Sin base, el enlace del correo queda relativo y no abre nada. */
    public function testSinDireccionDelFrontendElLogAvisaQueElEnlaceNoSirve(): void
    {
        putenv('APP_URL');
        $email = $this->crearCuenta();

        $this->pedir($email);

        self::assertStringContainsString('APP_URL', $this->log());
        self::assertStringContainsString('CORS_ORIGIN', $this->log());
    }

    public function testUnCorreoQueNoSaleQuedaRegistrado(): void
    {
        $this->elCorreoSale = false;
        $email = $this->crearCuenta();

        $this->pedir($email);

        self::assertStringContainsString('el correo de recuperación no salió', $this->log());
        self::assertStringNotContainsString('correo de recuperación enviado', $this->log());
    }

    public function testElLogNoLlevaNiElEmailNiElToken(): void
    {
        $email = $this->crearCuenta();

        $this->pedir($email);
        $conCorreo = $this->tokenDe($email);
        $this->elCorreoSale = false;
        $this->envejecerPedido($email);
        $this->pedir($email);
        $sinCorreo = $this->tokenDe($email);

        self::assertNotSame($conCorreo, $sinCorreo);
        self::assertStringNotContainsString($email, $this->log());
        self::assertStringNotContainsString($conCorreo, $this->log());
        self::assertStringNotContainsString($sinCorreo, $this->log());
    }

    // ----------------------------------------------------------------

    private function crearCuenta(): string
    {
        $email = 'cuenta-' . uniqid('', true) . '@triwe.test';
        $this->base()->prepare('INSERT INTO usuario (nombre, email, contrasena, rol) VALUES (?, ?, ?, ?)')
            ->execute(['Prueba', $email, password_hash('una-contrasena-correcta', PASSWORD_BCRYPT), 'PersonalTecnico']);

        return $email;
    }

    private function pedir(string $email): void
    {
        $enviar = function (string $para, string $asunto, string $html): bool {
            $this->enviados[] = $para;
            return $this->elCorreoSale;
        };
        $auth = new AuthController($this->base(), 'secreto-de-pruebas-con-mas-de-32-caracteres', 3600, null, $enviar);

        $this->capturar(fn () => $auth->olvide(['email' => $email]));
    }

    /** Deja pasar un pedido nuevo sin esperar los cinco minutos. */
    private function envejecerPedido(string $email): void
    {
        $this->base()->prepare('UPDATE usuario SET reset_expira = ? WHERE email = ?')
            ->execute([gmdate('Y-m-d H:i:s', time() + 3600 - 600), $email]);
    }

    private function tokenDe(string $email): string
    {
        $stmt = $this->base()->prepare('SELECT reset_token FROM usuario WHERE email = ?');
        $stmt->execute([$email]);
        $token = (string) $stmt->fetchColumn();
        self::assertNotSame('', $token);

        return $token;
    }

    private function log(): string
    {
        return (string) file_get_contents($this->log);
    }
}
