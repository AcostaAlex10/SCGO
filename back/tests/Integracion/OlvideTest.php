<?php

declare(strict_types=1);

namespace Sgso\Tests\Integracion;

use PHPUnit\Framework\Attributes\CoversClass;
use Sgso\AuthController;

/**
 * Límite de pedidos de recuperación de contraseña (plan de producto, A-13).
 *
 * Cada pedido manda un correo. Sin freno, alguien que conozca un email le llena
 * la casilla y de paso gasta la cuota de Brevo.
 *
 * El límite no es un contador aparte: es la propia condición del UPDATE que
 * guarda el token. Se reserva y se decide en la misma sentencia, así que dos
 * pedidos simultáneos no pueden pasar los dos.
 *
 * La respuesta es siempre la misma —200 con un texto genérico—, exista o no la
 * cuenta y esté o no limitada: si el límite se notara, el endpoint pasaría a
 * delatar qué emails tienen cuenta.
 *
 * Como mandar el correo necesita credenciales de Brevo que en las pruebas no
 * están, lo que se mira es el efecto observable en la base: el token de reseteo.
 */
#[CoversClass(AuthController::class)]
final class OlvideTest extends CasoConBase
{
    private const SECRETO = 'secreto-de-pruebas-con-mas-de-32-caracteres';

    public function testElPrimerPedidoGeneraUnToken(): void
    {
        $email = $this->crearCuenta();

        $this->pedir($email);

        self::assertNotNull($this->tokenDe($email));
    }

    public function testElSegundoPedidoSeguidoNoGeneraOtroToken(): void
    {
        $email = $this->crearCuenta();
        $this->pedir($email);
        $primero = $this->tokenDe($email);

        $this->pedir($email);

        self::assertSame($primero, $this->tokenDe($email), 'el segundo pedido no llegó a mandar correo');
    }

    public function testPasadaLaEsperaSePuedeVolverAPedir(): void
    {
        $email = $this->crearCuenta();
        $this->pedir($email);
        $primero = $this->tokenDe($email);

        $this->envejecerPedido($email, 600);
        $this->pedir($email);

        self::assertNotSame($primero, $this->tokenDe($email));
    }

    public function testLaRespuestaNoCambiaAunqueEstePasadoElLimite(): void
    {
        $email = $this->crearCuenta();
        $libre = $this->pedir($email);

        self::assertSame($libre, $this->pedir($email));
    }

    public function testLaRespuestaEsLaMismaParaUnEmailSinCuenta(): void
    {
        $conCuenta = $this->pedir($this->crearCuenta());

        self::assertSame($conCuenta, $this->pedir('nadie-' . uniqid() . '@sgso.test'));
    }

    public function testElLimiteEsPorCuenta(): void
    {
        $atacada = $this->crearCuenta();
        $otra = $this->crearCuenta();
        $this->pedir($atacada);
        $this->pedir($atacada);

        $this->pedir($otra);

        self::assertNotNull($this->tokenDe($otra));
    }

    public function testPedirRecuperacionNoBloqueaElLogin(): void
    {
        // Si el límite se apoyara en el contador de intentos de login,
        // cualquiera podría dejar a otro sin entrar pidiendo recuperaciones.
        $email = $this->crearCuenta();
        $this->pedir($email);
        $this->pedir($email);
        $this->pedir($email);

        $auth = new AuthController($this->base(), self::SECRETO, 3600);
        $respuesta = $this->capturar(
            fn () => $auth->login(['email' => $email, 'contrasena' => 'una-contrasena-correcta'])
        );

        self::assertSame(200, $respuesta['codigo']);
    }

    // ----------------------------------------------------------------

    private function crearCuenta(): string
    {
        $email = 'cuenta-' . uniqid('', true) . '@sgso.test';
        $stmt = $this->base()->prepare(
            'INSERT INTO usuario (nombre, email, contrasena, rol) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            'Prueba',
            $email,
            password_hash('una-contrasena-correcta', PASSWORD_BCRYPT),
            'PersonalTecnico',
        ]);

        return $email;
    }

    /** @return array{codigo: int, cuerpo: mixed} */
    private function pedir(string $email): array
    {
        $auth = new AuthController($this->base(), self::SECRETO, 3600);

        return $this->capturar(fn () => $auth->olvide(['email' => $email]));
    }

    /**
     * Atrasa el vencimiento del token para simular que el pedido se hizo hace
     * rato. Es la única forma de probar la espera sin dormir la prueba: el
     * momento del pedido no se guarda aparte, se deduce de `reset_expira`.
     */
    private function envejecerPedido(string $email, int $segundos): void
    {
        // El token vale una hora desde que se emite: dejarlo venciendo dentro de
        // "una hora menos N" equivale a haberlo pedido hace N segundos. La cuenta
        // se hace en PHP y no en SQL para no depender de la zona horaria de la base.
        $this->base()
            ->prepare('UPDATE usuario SET reset_expira = ? WHERE email = ?')
            ->execute([gmdate('Y-m-d H:i:s', time() + 3600 - $segundos), $email]);
    }

    private function tokenDe(string $email): ?string
    {
        $stmt = $this->base()->prepare('SELECT reset_token FROM usuario WHERE email = ?');
        $stmt->execute([$email]);
        $token = $stmt->fetchColumn();

        return is_string($token) ? $token : null;
    }
}
