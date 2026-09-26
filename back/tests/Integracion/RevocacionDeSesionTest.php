<?php

declare(strict_types=1);

namespace Sgso\Tests\Integracion;

use PHPUnit\Framework\Attributes\CoversClass;
use Sgso\AuthController;
use Sgso\AuthMiddleware;
use Sgso\Jwt;
use Sgso\UsuarioController;

/**
 * Que el acceso se corte cuando tiene que cortarse (plan de producto, A-14).
 *
 * Cada pedido autenticado pasa por AuthMiddleware. Antes del arreglo solo
 * verificaba la firma y el vencimiento del token, que dura 8 horas: una baja,
 * un cambio de rol o un restablecimiento de contrasena no cortaban nada hasta
 * que vencia. El peor caso es el administrador degradado, que conservaba el rol
 * del token y podia volver a asignarselo.
 *
 * Los tokens salen del login de verdad, y la baja y el cambio de rol pasan por
 * UsuarioController, igual que desde la pantalla de usuarios.
 */
#[CoversClass(AuthMiddleware::class)]
final class RevocacionDeSesionTest extends CasoConBase
{
    private const CONTRASENA = 'una-contrasena-correcta';
    private const SECRETO = 'secreto-de-pruebas-con-mas-de-32-caracteres';

    /** @var array<string, mixed> */
    private array $serverOriginal = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->serverOriginal = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverOriginal;
        parent::tearDown();
    }

    public function testUnaSesionVigentePasaConSuRol(): void
    {
        [$idUsuario, $token] = $this->cuentaConSesion('PersonalTecnico');

        $usuario = $this->autenticar($token);

        self::assertNotNull($usuario);
        self::assertSame($idUsuario, $usuario['id_usuario']);
        self::assertSame('PersonalTecnico', $usuario['rol']);
    }

    public function testDarDeBajaAlUsuarioCortaSuAccesoEnElMomento(): void
    {
        $admin = $this->crearUsuario('AdministradorSistema');
        [$idUsuario, $token] = $this->cuentaConSesion('PersonalAdministrativo');

        $cambio = $this->capturar(fn () => $this->usuarios()->actualizar((string) $idUsuario, ['activo' => false], ['id_usuario' => $admin]));
        self::assertSame(200, $cambio['codigo']);

        self::assertNull($this->autenticar($token));
    }

    public function testCambiarleElRolCortaSuAccesoEnElMomento(): void
    {
        $admin = $this->crearUsuario('AdministradorSistema');
        [$idUsuario, $token] = $this->cuentaConSesion('PersonalAdministrativo');

        $cambio = $this->capturar(fn () => $this->usuarios()->actualizar((string) $idUsuario, ['rol' => 'PersonalTecnico'], ['id_usuario' => $admin]));
        self::assertSame(200, $cambio['codigo']);

        self::assertNull($this->autenticar($token));
    }

    /**
     * El caso que hace de esto un P0: con el token de antes, el degradado
     * seguia pasando como administrador, y la guarda de "uno mismo" de
     * UsuarioController no le impide subirse el rol.
     */
    public function testUnAdministradorDegradadoYaNoPasaComoAdministrador(): void
    {
        $quienDegrada = $this->crearUsuario('AdministradorSistema');
        [$degradado, $token] = $this->cuentaConSesion('AdministradorSistema');

        $cambio = $this->capturar(fn () => $this->usuarios()->actualizar((string) $degradado, ['rol' => 'PersonalTecnico'], ['id_usuario' => $quienDegrada]));
        self::assertSame(200, $cambio['codigo']);

        self::assertNull($this->autenticar($token));
    }

    public function testIniciarSesionEnOtroLadoCortaLaSesionAnterior(): void
    {
        [, $tokenViejo, $email] = $this->cuentaConSesion('Gerente');

        $tokenNuevo = $this->entrar($email);

        self::assertNull($this->autenticar($tokenViejo));
        self::assertNotNull($this->autenticar($tokenNuevo));
    }

    public function testRestablecerLaContrasenaCortaLasSesionesAbiertas(): void
    {
        [$idUsuario, $token] = $this->cuentaConSesion('PersonalTecnico');
        $this->base()->prepare(
            "UPDATE usuario SET reset_token = 'enlace', reset_expira = NOW() + INTERVAL 1 HOUR WHERE id_usuario = ?"
        )->execute([$idUsuario]);

        $respuesta = $this->capturar(fn () => $this->auth()->restablecer([
            'token' => 'enlace',
            'contrasena' => 'otra-contrasena-bien-larga',
        ]));

        self::assertSame(200, $respuesta['codigo']);
        self::assertNull($this->autenticar($token));
    }

    /** Si el rol se cambio por fuera de la API, vale el de la base. */
    public function testElRolQueValeEsElDeLaBaseNoElDelToken(): void
    {
        [$idUsuario, $token] = $this->cuentaConSesion('AdministradorSistema');
        $this->base()->prepare("UPDATE usuario SET rol = 'Gerente' WHERE id_usuario = ?")->execute([$idUsuario]);

        $usuario = $this->autenticar($token);

        self::assertNotNull($usuario);
        self::assertSame('Gerente', $usuario['rol']);
    }

    public function testUnUsuarioBorradoNoPasa(): void
    {
        [$idUsuario, $token] = $this->cuentaConSesion('PersonalTecnico');
        $this->base()->prepare('DELETE FROM usuario WHERE id_usuario = ?')->execute([$idUsuario]);

        self::assertNull($this->autenticar($token));
    }

    /** El login siempre pone `sid`. Un token sin el no salio del login. */
    public function testUnTokenBienFirmadoPeroSinSesionNoPasa(): void
    {
        [$idUsuario] = $this->cuentaConSesion('AdministradorSistema');
        $sinSesion = Jwt::generar(
            ['id_usuario' => $idUsuario, 'email' => 'x@sgso.test', 'rol' => 'AdministradorSistema'],
            self::SECRETO,
            3600
        );

        self::assertNull($this->autenticar($sinSesion));
    }

    public function testUnTokenConOtraFirmaNoPasa(): void
    {
        [, $token] = $this->cuentaConSesion('PersonalTecnico');

        self::assertNull($this->autenticar($token, 'otro-secreto-de-pruebas-con-mas-de-32-caracteres'));
    }

    // ----------------------------------------------------------------
    //  Ayudas
    // ----------------------------------------------------------------

    /**
     * Una cuenta que ya inicio sesion por el login real.
     * @return array{0: int, 1: string, 2: string} id, token y email
     */
    private function cuentaConSesion(string $rol): array
    {
        $email = 'cuenta-' . uniqid('', true) . '@sgso.test';
        $this->base()->prepare(
            'INSERT INTO usuario (nombre, email, contrasena, rol) VALUES (?, ?, ?, ?)'
        )->execute(['Prueba', $email, password_hash(self::CONTRASENA, PASSWORD_BCRYPT), $rol]);
        $idUsuario = (int) $this->base()->lastInsertId();

        return [$idUsuario, $this->entrar($email), $email];
    }

    private function entrar(string $email): string
    {
        $respuesta = $this->capturar(fn () => $this->auth()->login([
            'email' => $email,
            'contrasena' => self::CONTRASENA,
        ]));
        self::assertSame(200, $respuesta['codigo']);

        return (string) $respuesta['cuerpo']['token'];
    }

    /**
     * Lo que ve la API en un pedido con ese token. En la consola no existe
     * getallheaders(), asi que el middleware lee el header de $_SERVER.
     * @return array<string, mixed>|null
     */
    private function autenticar(string $token, string $secreto = self::SECRETO): ?array
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;

        return AuthMiddleware::usuarioAutenticado($secreto, $this->base());
    }

    private function auth(): AuthController
    {
        return new AuthController($this->base(), self::SECRETO, 3600);
    }

    private function usuarios(): UsuarioController
    {
        return new UsuarioController($this->base());
    }
}
