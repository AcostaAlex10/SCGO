<?php

declare(strict_types=1);

namespace Sgso\Tests\Integracion;

use PHPUnit\Framework\Attributes\CoversClass;
use Sgso\IncidenciaController;

/**
 * El aviso por correo de una incidencia segun su gravedad (RF26, D-02).
 *
 * El protocolo lo decidio el equipo: una incidencia alta avisa a los Gerentes
 * y al Personal Administrativo; una media, solo al Personal Administrativo;
 * una baja no manda correo. Solo a cuentas activas, y un solo correo por
 * incidencia.
 *
 * El correo no sale de verdad: el controlador recibe la funcion de envio, y
 * aca se reemplaza por una que anota a quien se hubiera mandado.
 */
#[CoversClass(IncidenciaController::class)]
final class AvisoDeIncidenciaTest extends CasoConBase
{
    /** @var list<array{para: list<string>, asunto: string, html: string}> */
    private array $enviados = [];

    private bool $elCorreoFunciona = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enviados = [];
        $this->elCorreoFunciona = true;

        $this->cuenta('Gerencia', 'gerente@triwe.test', 'Gerente');
        $this->cuenta('Gerencia de baja', 'gerente.baja@triwe.test', 'Gerente', false);
        $this->cuenta('Administracion', 'administracion@triwe.test', 'PersonalAdministrativo');
        $this->cuenta('Tecnico', 'tecnico@triwe.test', 'PersonalTecnico');
        $this->cuenta('Sistemas', 'sistemas@triwe.test', 'AdministradorSistema');
    }

    public function testUnaIncidenciaAltaAvisaAGerentesYAdministrativosActivos(): void
    {
        $respuesta = $this->registrar('alta');

        self::assertSame(201, $respuesta['codigo']);
        self::assertCount(1, $this->enviados, 'un solo correo por incidencia');
        self::assertSame(['administracion@triwe.test', 'gerente@triwe.test'], $this->destinatarios());
        self::assertSame(2, $respuesta['cuerpo']['avisados']);
    }

    public function testUnaMediaAvisaSoloAlPersonalAdministrativo(): void
    {
        $respuesta = $this->registrar('media');

        self::assertSame(['administracion@triwe.test'], $this->destinatarios());
        self::assertSame(1, $respuesta['cuerpo']['avisados']);
    }

    public function testUnaBajaNoMandaCorreo(): void
    {
        $respuesta = $this->registrar('baja');

        self::assertSame(201, $respuesta['codigo']);
        self::assertSame([], $this->enviados);
        self::assertSame(0, $respuesta['cuerpo']['avisados']);
    }

    /** El que recibe el correo tiene que poder decidir sin abrir el sistema. */
    public function testElCorreoDiceQuePasoDondeCuandoYQuienLoCargo(): void
    {
        $idTecnico = (int) $this->base()->query("SELECT id_usuario FROM usuario WHERE email = 'tecnico@triwe.test'")->fetchColumn();

        $this->registrar('alta', [
            'tipo' => 'proveedor',
            'fecha' => '2026-03-05',
            'dias_retraso' => 4,
            'descripcion' => 'El corralon no entrega el hierro hasta el lunes',
        ], ['id_usuario' => $idTecnico]);

        $correo = $this->enviados[0];
        self::assertStringContainsString('Torre Norte', $correo['asunto']);
        self::assertStringContainsString('alta', $correo['asunto']);
        foreach (['Torre Norte', 'Retraso de proveedor', '05/03/2026', '4', 'El corralon no entrega el hierro hasta el lunes', 'Tecnico'] as $dato) {
            self::assertStringContainsString($dato, $correo['html']);
        }
    }

    /**
     * La descripcion la escribe quien carga la incidencia. Sin escapar, un
     * enlace o un formulario falso viajaria dentro de un correo que el sistema
     * manda en su nombre.
     */
    public function testElTextoDeLaIncidenciaNoInyectaHtmlEnElCorreo(): void
    {
        $this->registrar('alta', ['descripcion' => '<a href="https://phishing.test">Revisar</a>']);

        self::assertStringNotContainsString('<a href="https://phishing.test">', $this->enviados[0]['html']);
        self::assertStringContainsString('&lt;a href=', $this->enviados[0]['html']);
    }

    /** Un corte de Brevo no puede costar la incidencia: ya quedo guardada. */
    public function testSiElCorreoFallaLaIncidenciaQuedaRegistradaIgual(): void
    {
        $this->elCorreoFunciona = false;

        $respuesta = $this->registrar('alta');

        self::assertSame(201, $respuesta['codigo']);
        self::assertSame(0, $respuesta['cuerpo']['avisados']);
        self::assertSame(1, (int) $this->base()->query('SELECT COUNT(*) FROM incidencia')->fetchColumn());
    }

    public function testSinNadieAQuienAvisarNoSeIntentaMandarNada(): void
    {
        $this->base()->exec("UPDATE usuario SET activo = 0 WHERE rol IN ('Gerente', 'PersonalAdministrativo')");

        $respuesta = $this->registrar('alta');

        self::assertSame([], $this->enviados);
        self::assertSame(0, $respuesta['cuerpo']['avisados']);
    }

    public function testUnaIncidenciaRechazadaNoAvisaANadie(): void
    {
        $respuesta = $this->registrar('alta', ['descripcion' => '   ']);

        self::assertSame(422, $respuesta['codigo']);
        self::assertSame([], $this->enviados);
    }

    // ----------------------------------------------------------------
    //  Ayudas
    // ----------------------------------------------------------------

    private function cuenta(string $nombre, string $email, string $rol, bool $activa = true): void
    {
        $this->base()->prepare('INSERT INTO usuario (nombre, email, contrasena, rol, activo) VALUES (?, ?, ?, ?, ?)')
            ->execute([$nombre, $email, password_hash('x', PASSWORD_BCRYPT), $rol, $activa ? 1 : 0]);
    }

    /**
     * @param array<string, mixed> $datos
     * @param array<string, mixed> $usuario
     * @return array{codigo: int, cuerpo: mixed}
     */
    private function registrar(string $gravedad, array $datos = [], array $usuario = []): array
    {
        $idProyecto = $this->crearProyecto('en_ejecucion', 'Torre Norte');
        $enviar = function (array $para, string $asunto, string $html): bool {
            $this->enviados[] = ['para' => $para, 'asunto' => $asunto, 'html' => $html];
            return $this->elCorreoFunciona;
        };
        $controlador = new IncidenciaController($this->base(), $enviar);

        return $this->capturar(fn () => $controlador->crear((string) $idProyecto, $datos + [
            'fecha' => '2026-03-02',
            'tipo' => 'clima',
            'gravedad' => $gravedad,
            'descripcion' => 'Tormenta, se suspende el hormigonado',
        ], $usuario));
    }

    /** @return list<string> */
    private function destinatarios(): array
    {
        $para = $this->enviados[0]['para'] ?? [];
        sort($para);
        return $para;
    }
}
