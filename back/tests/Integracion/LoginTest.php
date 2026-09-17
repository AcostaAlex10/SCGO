<?php

declare(strict_types=1);

namespace Sgso\Tests\Integracion;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use Sgso\AuthController;
use Sgso\Seguridad\IntentosLogin;
use Sgso\Seguridad\PoliticaIntentos;

/**
 * Login contra la base real (plan de producto, A-03).
 *
 * Antes del arreglo fallaban tres: no había límite de intentos, y una cuenta
 * inactiva se delataba sin necesidad de conocer su contraseña.
 */
#[CoversClass(AuthController::class)]
final class LoginTest extends CasoConBase
{
    private const CONTRASENA = 'una-contrasena-correcta';
    private const SECRETO = 'secreto-de-pruebas-con-mas-de-32-caracteres';

    public function testElSextoIntentoSeBloqueaAunConLaContrasenaCorrecta(): void
    {
        $email = $this->crearCuenta();
        $this->fallar($email, 5);

        $respuesta = $this->entrar($email, self::CONTRASENA);

        self::assertSame(429, $respuesta['codigo']);
        self::assertIsArray($respuesta['cuerpo']);
        self::assertArrayNotHasKey('token', $respuesta['cuerpo']);
        self::assertGreaterThan(0, $respuesta['cuerpo']['reintentar_en_segundos'] ?? 0);
    }

    public function testUnEmailSinCuentaSeComportaIgualQueUnoConCuenta(): void
    {
        // Si el bloqueo solo se aplicara a cuentas reales, el 429 delataría qué
        // emails existen.
        $inexistente = 'nadie-' . uniqid() . '@sgso.test';

        self::assertSame(401, $this->entrar($inexistente, 'lo-que-sea')['codigo']);
        $this->fallar($inexistente, 4);
        self::assertSame(429, $this->entrar($inexistente, 'lo-que-sea')['codigo']);
    }

    public function testElBloqueoEsDeLaCuentaNoDeTodos(): void
    {
        $atacada = $this->crearCuenta();
        $otra = $this->crearCuenta();
        $this->fallar($atacada, 5);

        self::assertSame(429, $this->entrar($atacada, self::CONTRASENA)['codigo']);
        self::assertSame(200, $this->entrar($otra, self::CONTRASENA)['codigo']);
    }

    public function testElBloqueoNoDistingueMayusculasDelEmail(): void
    {
        $email = $this->crearCuenta();
        $this->fallar(strtoupper($email), 5);

        self::assertSame(429, $this->entrar($email, self::CONTRASENA)['codigo']);
    }

    public function testUnLoginCorrectoReiniciaElContador(): void
    {
        $email = $this->crearCuenta();

        $this->fallar($email, 4);
        self::assertSame(200, $this->entrar($email, self::CONTRASENA)['codigo']);

        // Si el contador no se hubiera reiniciado, estos serían el 5.º al 8.º fallo.
        $this->fallar($email, 4);
        self::assertSame(200, $this->entrar($email, self::CONTRASENA)['codigo']);
    }

    public function testUnaCuentaInactivaNoSeDelataSinLaContrasena(): void
    {
        $email = $this->crearCuenta(activa: false);

        $respuesta = $this->entrar($email, 'contrasena-equivocada');

        self::assertSame(401, $respuesta['codigo']);
        self::assertSame('Credenciales invalidas', $respuesta['cuerpo']['error'] ?? null);
    }

    public function testUnaCuentaInactivaSeInformaConLaContrasenaCorrecta(): void
    {
        $email = $this->crearCuenta(activa: false);

        self::assertSame(403, $this->entrar($email, self::CONTRASENA)['codigo']);
    }

    public function testCumplidaLaEsperaSePuedeVolverAEntrar(): void
    {
        $email = $this->crearCuenta();
        $reloj = new RelojDePrueba();
        $entrar = $this->entradaConReloj($email, $reloj);

        $this->repetir(5, fn () => $entrar('contrasena-equivocada'));
        self::assertSame(429, $entrar(self::CONTRASENA));

        $reloj->avanzar(PoliticaIntentos::ESPERA_INICIAL);
        self::assertSame(200, $entrar(self::CONTRASENA));
    }

    public function testCadaFalloDespuesDelBloqueoDuplicaLaEspera(): void
    {
        $email = $this->crearCuenta();
        $reloj = new RelojDePrueba();
        $entrar = $this->entradaConReloj($email, $reloj);

        $this->repetir(5, fn () => $entrar('contrasena-equivocada'));
        $reloj->avanzar(60);
        self::assertSame(401, $entrar('otra-equivocada'), 'cumplido el minuto se puede probar, y falla');

        // Seis fallos: ahora la espera es de 120 s.
        $reloj->avanzar(60);
        self::assertSame(429, $entrar(self::CONTRASENA));
        $reloj->avanzar(60);
        self::assertSame(200, $entrar(self::CONTRASENA));
    }

    // ----------------------------------------------------------------

    /** @return Closure(string): int  intenta entrar y devuelve el código HTTP */
    private function entradaConReloj(string $email, RelojDePrueba $reloj): Closure
    {
        $auth = new AuthController(
            $this->base(),
            self::SECRETO,
            3600,
            new IntentosLogin($this->base(), $reloj->comoClosure())
        );

        return fn (string $contrasena): int => $this->capturar(
            fn () => $auth->login(['email' => $email, 'contrasena' => $contrasena])
        )['codigo'];
    }

    private function repetir(int $veces, callable $accion): void
    {
        for ($i = 0; $i < $veces; $i++) {
            $accion();
        }
    }

    private function crearCuenta(bool $activa = true): string
    {
        $email = 'cuenta-' . uniqid('', true) . '@sgso.test';
        $stmt = $this->base()->prepare(
            'INSERT INTO usuario (nombre, email, contrasena, rol, activo) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            'Prueba',
            $email,
            password_hash(self::CONTRASENA, PASSWORD_BCRYPT),
            'PersonalTecnico',
            $activa ? 1 : 0,
        ]);

        return $email;
    }

    /** @return array{codigo: int, cuerpo: mixed} */
    private function entrar(string $email, string $contrasena): array
    {
        $auth = new AuthController($this->base(), self::SECRETO, 3600);

        return $this->capturar(fn () => $auth->login(['email' => $email, 'contrasena' => $contrasena]));
    }

    private function fallar(string $email, int $veces): void
    {
        for ($i = 0; $i < $veces; $i++) {
            $this->entrar($email, 'contrasena-equivocada-' . $i);
        }
    }
}
