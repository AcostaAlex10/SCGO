<?php

declare(strict_types=1);

namespace Sgso\Tests\Integracion;

use PHPUnit\Framework\Attributes\CoversClass;
use Sgso\UsuarioController;

/**
 * Gestion de cuentas y roles (HU16, RF19): el listado, el cambio de rol y la
 * baja logica. La regla que mas importa es que el sistema nunca quede sin un
 * administrador activo, porque sin el nadie puede gestionar usuarios (B-08).
 *
 * Que la baja o el cambio de rol corten el acceso en el momento no se prueba
 * aca: lo decide la autenticacion de cada pedido, no este controlador. Esta en
 * RevocacionDeSesionTest (A-14).
 */
#[CoversClass(UsuarioController::class)]
final class UsuarioTest extends CasoConBase
{
    public function testElListadoNuncaDevuelveLaContrasenaNiLosTokens(): void
    {
        $idUsuario = $this->crearUsuario('PersonalTecnico');
        $this->base()->prepare(
            "UPDATE usuario SET sesion_token = 'sesion', reset_token = 'reset', reset_expira = NOW() WHERE id_usuario = ?"
        )->execute([$idUsuario]);

        $respuesta = $this->capturar(fn () => $this->usuarios()->listar());

        self::assertSame(200, $respuesta['codigo']);
        self::assertCount(1, $respuesta['cuerpo']);
        $usuario = $respuesta['cuerpo'][0];
        foreach (['contrasena', 'sesion_token', 'reset_token', 'reset_expira'] as $clave) {
            self::assertArrayNotHasKey($clave, $usuario);
        }
        self::assertSame($idUsuario, $usuario['id_usuario']);
        self::assertTrue($usuario['activo']);
        self::assertSame('PersonalTecnico', $usuario['rol']);
    }

    public function testCambiarElRolDeOtroUsuarioLoGuardaYBorraSuSesion(): void
    {
        $admin = $this->crearUsuario('AdministradorSistema');
        $otro = $this->usuarioConSesion('PersonalTecnico');

        $respuesta = $this->capturar(fn () => $this->usuarios()->actualizar(
            (string) $otro,
            ['rol' => 'Gerente'],
            ['id_usuario' => $admin]
        ));

        self::assertSame(200, $respuesta['codigo']);
        self::assertSame('Gerente', $respuesta['cuerpo']['rol']);
        self::assertSame('Gerente', $this->fila($otro)['rol']);
        self::assertNull($this->fila($otro)['sesion_token']);
    }

    public function testDarDeBajaAOtroUsuarioLoDejaInactivoYSinSesion(): void
    {
        $admin = $this->crearUsuario('AdministradorSistema');
        $otro = $this->usuarioConSesion('PersonalAdministrativo');

        $respuesta = $this->capturar(fn () => $this->usuarios()->actualizar(
            (string) $otro,
            ['activo' => false],
            ['id_usuario' => $admin]
        ));

        self::assertSame(200, $respuesta['codigo']);
        self::assertFalse($respuesta['cuerpo']['activo']);
        self::assertSame(0, (int) $this->fila($otro)['activo']);
        self::assertNull($this->fila($otro)['sesion_token']);
        self::assertSame('PersonalAdministrativo', $this->fila($otro)['rol']);
    }

    public function testReactivarAUnUsuarioLoVuelveADejarActivo(): void
    {
        $admin = $this->crearUsuario('AdministradorSistema');
        $otro = $this->crearUsuario('Gerente');
        $this->base()->prepare('UPDATE usuario SET activo = 0 WHERE id_usuario = ?')->execute([$otro]);

        $respuesta = $this->capturar(fn () => $this->usuarios()->actualizar(
            (string) $otro,
            ['activo' => true],
            ['id_usuario' => $admin]
        ));

        self::assertSame(200, $respuesta['codigo']);
        self::assertSame(1, (int) $this->fila($otro)['activo']);
    }

    public function testUnAdministradorNoPuedeQuitarseElRolASiMismo(): void
    {
        $admin = $this->crearUsuario('AdministradorSistema');
        $this->crearUsuario('AdministradorSistema'); // hay otro: no es la regla del ultimo

        $respuesta = $this->capturar(fn () => $this->usuarios()->actualizar(
            (string) $admin,
            ['rol' => 'Gerente'],
            ['id_usuario' => $admin]
        ));

        self::assertSame(409, $respuesta['codigo']);
        self::assertSame('AdministradorSistema', $this->fila($admin)['rol']);
    }

    public function testUnAdministradorNoPuedeDarseDeBajaASiMismo(): void
    {
        $admin = $this->crearUsuario('AdministradorSistema');
        $this->crearUsuario('AdministradorSistema');

        $respuesta = $this->capturar(fn () => $this->usuarios()->actualizar(
            (string) $admin,
            ['activo' => false],
            ['id_usuario' => $admin]
        ));

        self::assertSame(409, $respuesta['codigo']);
        self::assertSame(1, (int) $this->fila($admin)['activo']);
    }

    public function testSePuedeQuitarElRolAUnAdministradorSiQuedaOtroActivo(): void
    {
        $admin = $this->crearUsuario('AdministradorSistema');
        $otroAdmin = $this->crearUsuario('AdministradorSistema');

        $respuesta = $this->capturar(fn () => $this->usuarios()->actualizar(
            (string) $otroAdmin,
            ['rol' => 'PersonalAdministrativo'],
            ['id_usuario' => $admin]
        ));

        self::assertSame(200, $respuesta['codigo']);
        self::assertSame('PersonalAdministrativo', $this->fila($otroAdmin)['rol']);
    }

    /**
     * La guarda de "uno mismo" ya frena el caso comun. Esta es la red para
     * cuando quien pide no es el ultimo administrador activo: por ejemplo, un
     * administrador ya dado de baja con un token de antes de la baja.
     */
    public function testNoSePuedeDejarElSistemaSinNingunAdministradorActivo(): void
    {
        $unicoActivo = $this->crearUsuario('AdministradorSistema');
        $dadoDeBaja = $this->crearUsuario('AdministradorSistema');
        $this->base()->prepare('UPDATE usuario SET activo = 0 WHERE id_usuario = ?')->execute([$dadoDeBaja]);

        $baja = $this->capturar(fn () => $this->usuarios()->actualizar(
            (string) $unicoActivo,
            ['activo' => false],
            ['id_usuario' => $dadoDeBaja]
        ));
        $cambioDeRol = $this->capturar(fn () => $this->usuarios()->actualizar(
            (string) $unicoActivo,
            ['rol' => 'Gerente'],
            ['id_usuario' => $dadoDeBaja]
        ));

        self::assertSame(409, $baja['codigo']);
        self::assertSame(409, $cambioDeRol['codigo']);
        self::assertSame('AdministradorSistema', $this->fila($unicoActivo)['rol']);
        self::assertSame(1, (int) $this->fila($unicoActivo)['activo']);
    }

    public function testUnRolInexistenteSeRechaza(): void
    {
        $admin = $this->crearUsuario('AdministradorSistema');
        $otro = $this->crearUsuario('PersonalTecnico');

        $respuesta = $this->capturar(fn () => $this->usuarios()->actualizar(
            (string) $otro,
            ['rol' => 'SuperUsuario'],
            ['id_usuario' => $admin]
        ));

        self::assertSame(422, $respuesta['codigo']);
        self::assertArrayHasKey('rol', $respuesta['cuerpo']['errors']);
        self::assertSame('PersonalTecnico', $this->fila($otro)['rol']);
    }

    public function testUnPedidoSinRolNiActivoSeRechazaSinTocarNada(): void
    {
        $admin = $this->crearUsuario('AdministradorSistema');
        $otro = $this->usuarioConSesion('PersonalTecnico');

        $respuesta = $this->capturar(fn () => $this->usuarios()->actualizar(
            (string) $otro,
            ['nombre' => 'Otro nombre'],
            ['id_usuario' => $admin]
        ));

        self::assertSame(422, $respuesta['codigo']);
        self::assertSame('sesion-vigente', $this->fila($otro)['sesion_token']);
    }

    public function testActualizarUnUsuarioInexistenteDa404(): void
    {
        $admin = $this->crearUsuario('AdministradorSistema');

        $respuesta = $this->capturar(fn () => $this->usuarios()->actualizar(
            '999',
            ['rol' => 'Gerente'],
            ['id_usuario' => $admin]
        ));

        self::assertSame(404, $respuesta['codigo']);
    }

    private function usuarios(): UsuarioController
    {
        return new UsuarioController($this->base());
    }

    private function usuarioConSesion(string $rol): int
    {
        $idUsuario = $this->crearUsuario($rol);
        $this->base()->prepare("UPDATE usuario SET sesion_token = 'sesion-vigente' WHERE id_usuario = ?")
            ->execute([$idUsuario]);

        return $idUsuario;
    }

    /** @return array<string, mixed> */
    private function fila(int $idUsuario): array
    {
        $stmt = $this->base()->prepare('SELECT rol, activo, sesion_token FROM usuario WHERE id_usuario = ?');
        $stmt->execute([$idUsuario]);

        return $stmt->fetch();
    }
}
