<?php

declare(strict_types=1);

namespace Sgso\Tests\Integracion;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use Sgso\AuthController;
use Sgso\Seguridad\IntentosLogin;
use Sgso\Seguridad\PoliticaIntentos;

/**
 * Límite de pedidos de recuperación de contraseña (plan de producto, A-13).
 *
 * Cada pedido manda un correo. Sin freno, alguien que conozca un email le llena
 * la casilla y de paso gasta la cuota de Brevo. Se reutiliza la política de A-03,
 * con una clave distinta: el límite de `/auth/olvide` no bloquea el login.
 *
 * La respuesta es siempre la misma —200 con un texto genérico—, exista o no la
 * cuenta y esté o no bloqueada: si el bloqueo se notara, el endpoint pasaría a
 * delatar para qué emails se pidió una recuperación.
 *
 * Como el envío del correo necesita credenciales de Brevo que en las pruebas no
 * están, lo que se mira es el efecto observable en la base: el token de reseteo.
 */
#[CoversClass(AuthController::class)]
final class OlvideTest extends CasoConBase
{
    private const SECRETO = 'secreto-de-pruebas-con-mas-de-32-caracteres';

    public function testLosPrimerosPedidosGeneranTokenNuevo(): void
    {
        $email = $this->crearCuenta();

        $this->pedir($email);
        $primero = $this->tokenDe($email);
        $this->pedir($email);

        self::assertNotNull($primero);
        self::assertNotSame($primero, $this->tokenDe($email));
    }

    public function testPasadoElLimiteElPedidoYaNoGeneraTokenNuevo(): void
    {
        $email = $this->crearCuenta();
        $this->repetir(PoliticaIntentos::FALLOS_TOLERADOS, fn () => $this->pedir($email));
        $ultimo = $this->tokenDe($email);

        $this->pedir($email);

        self::assertSame($ultimo, $this->tokenDe($email), 'el pedido bloqueado no llegó a mandar correo');
    }

    public function testLaRespuestaNoCambiaAunqueEstePasadoElLimite(): void
    {
        $email = $this->crearCuenta();
        $libre = $this->pedir($email);

        $this->repetir(PoliticaIntentos::FALLOS_TOLERADOS, fn () => $this->pedir($email));

        self::assertSame($libre, $this->pedir($email));
    }

    public function testElLimiteEsPorEmail(): void
    {
        $atacado = $this->crearCuenta();
        $otro = $this->crearCuenta();
        $this->repetir(PoliticaIntentos::FALLOS_TOLERADOS, fn () => $this->pedir($atacado));

        $this->pedir($otro);

        self::assertNotNull($this->tokenDe($otro));
    }

    public function testElLimiteDeOlvideNoBloqueaElLogin(): void
    {
        // Son contadores distintos: si compartieran clave, cualquiera podría
        // dejar a otro sin poder entrar pidiendo recuperaciones.
        $email = $this->crearCuenta();
        $this->repetir(PoliticaIntentos::FALLOS_TOLERADOS + 1, fn () => $this->pedir($email));

        $auth = new AuthController($this->base(), self::SECRETO, 3600);
        $respuesta = $this->capturar(
            fn () => $auth->login(['email' => $email, 'contrasena' => 'una-contrasena-correcta'])
        );

        self::assertSame(200, $respuesta['codigo']);
    }

    public function testCumplidaLaEsperaVuelveAFuncionar(): void
    {
        $email = $this->crearCuenta();
        $reloj = new RelojDePrueba();
        $pedir = $this->pedidoConReloj($reloj);

        $this->repetir(PoliticaIntentos::FALLOS_TOLERADOS + 1, fn () => $pedir($email));
        $bloqueado = $this->tokenDe($email);

        $reloj->avanzar(PoliticaIntentos::ESPERA_INICIAL);
        $pedir($email);

        self::assertNotSame($bloqueado, $this->tokenDe($email));
    }

    // ----------------------------------------------------------------

    /** @return Closure(string): void */
    private function pedidoConReloj(RelojDePrueba $reloj): Closure
    {
        $auth = new AuthController(
            $this->base(),
            self::SECRETO,
            3600,
            new IntentosLogin($this->base(), $reloj->comoClosure())
        );

        return function (string $email) use ($auth): void {
            $this->capturar(fn () => $auth->olvide(['email' => $email]));
        };
    }

    private function repetir(int $veces, callable $accion): void
    {
        for ($i = 0; $i < $veces; $i++) {
            $accion();
        }
    }

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

    private function tokenDe(string $email): ?string
    {
        $stmt = $this->base()->prepare('SELECT reset_token FROM usuario WHERE email = ?');
        $stmt->execute([$email]);
        $token = $stmt->fetchColumn();

        return is_string($token) ? $token : null;
    }
}
