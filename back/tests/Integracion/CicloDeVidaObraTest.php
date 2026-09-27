<?php

declare(strict_types=1);

namespace Sgso\Tests\Integracion;

use PHPUnit\Framework\Attributes\CoversClass;
use Sgso\InactividadController;
use Sgso\MySqlProyectoRepository;
use Sgso\PlanificacionController;
use Sgso\ProyectoController;

#[CoversClass(InactividadController::class)]
#[CoversClass(ProyectoController::class)]
#[CoversClass(MySqlProyectoRepository::class)]
#[CoversClass(PlanificacionController::class)]
final class CicloDeVidaObraTest extends CasoConBase
{
    public function testRegistrarUnPeriodoVigentePausaUnaObraEnEjecucion(): void
    {
        $idProyecto = $this->crearProyecto();

        $respuesta = $this->capturar(fn () => $this->inactividad()->crear((string) $idProyecto, [
            'fecha_inicio' => date('Y-m-d'),
            'motivo' => 'Lluvia',
        ]));

        self::assertSame(201, $respuesta['codigo']);
        self::assertSame('pausada', $this->estadoDeLaObra($idProyecto));
    }

    public function testUnPeriodoHistoricoNoPausaLaObra(): void
    {
        $idProyecto = $this->crearProyecto();
        $haceTresDias = date('Y-m-d', strtotime('-3 days'));

        $respuesta = $this->capturar(fn () => $this->inactividad()->crear((string) $idProyecto, [
            'fecha_inicio' => $haceTresDias,
            'fecha_fin' => $haceTresDias,
            'motivo' => 'Lluvia',
        ]));

        self::assertSame(201, $respuesta['codigo']);
        self::assertSame('en_ejecucion', $this->estadoDeLaObra($idProyecto));
    }

    public function testCerrarElUltimoPeriodoVigenteReactivaLaObra(): void
    {
        $idProyecto = $this->crearProyecto('pausada');
        $idPeriodo = $this->crearPeriodoInactividad($idProyecto, date('Y-m-d'));

        $respuesta = $this->capturar(fn () => $this->inactividad()->cerrar((string) $idPeriodo, [
            'fecha_fin' => date('Y-m-d'),
        ]));

        self::assertSame(200, $respuesta['codigo']);
        self::assertSame('en_ejecucion', $this->estadoDeLaObra($idProyecto));
    }

    public function testCerrarElUltimoPeriodoVigenteDevuelveARevisionSiHayFinalEsperando(): void
    {
        $idProyecto = $this->crearProyecto('en_revision');
        $this->crearReporte($idProyecto, $this->crearUsuario(), true, 'en_revision');

        $pausa = $this->capturar(fn () => $this->inactividad()->crear((string) $idProyecto, [
            'fecha_inicio' => date('Y-m-d'),
            'motivo' => 'Lluvia',
        ]));
        self::assertIsArray($pausa['cuerpo']);
        $idPeriodo = (int) ($pausa['cuerpo']['id_periodo'] ?? 0);

        self::assertSame(201, $pausa['codigo']);
        self::assertGreaterThan(0, $idPeriodo);
        self::assertSame('pausada', $this->estadoDeLaObra($idProyecto));

        $respuesta = $this->capturar(fn () => $this->inactividad()->cerrar((string) $idPeriodo, [
            'fecha_fin' => date('Y-m-d'),
        ]));

        self::assertSame(200, $respuesta['codigo']);
        self::assertSame('en_revision', $this->estadoDeLaObra($idProyecto));
    }

    public function testUnPeriodoVigenteNoReviveUnaObraFinalizada(): void
    {
        $idProyecto = $this->crearProyecto('finalizada');

        $respuesta = $this->capturar(fn () => $this->inactividad()->crear((string) $idProyecto, [
            'fecha_inicio' => date('Y-m-d'),
            'motivo' => 'Lluvia',
        ]));

        self::assertSame(201, $respuesta['codigo']);
        self::assertSame('finalizada', $this->estadoDeLaObra($idProyecto));
    }

    public function testCancelarUnaObraEnEjecucionLaDejaCancelada(): void
    {
        $idProyecto = $this->crearProyecto();

        $respuesta = $this->capturar(fn () => $this->proyectos()->modificar(
            (string) $idProyecto,
            $this->datosParaModificar('cancelada')
        ));

        self::assertSame(200, $respuesta['codigo']);
        self::assertSame('cancelada', $this->estadoDeLaObra($idProyecto));
    }

    public function testNoSePuedeCancelarUnaObraEnPlanificacion(): void
    {
        $idProyecto = $this->crearProyecto('planificacion');

        $respuesta = $this->capturar(fn () => $this->proyectos()->modificar(
            (string) $idProyecto,
            $this->datosParaModificar('cancelada')
        ));

        self::assertSame(409, $respuesta['codigo']);
        self::assertSame('planificacion', $this->estadoDeLaObra($idProyecto));
    }

    // ----------------------------------------------------------------
    //  Creada y planificacion (D-04)
    // ----------------------------------------------------------------

    /** Una obra recien registrada todavia no tiene planificacion: esta creada. */
    public function testUnaObraNuevaArrancaCreada(): void
    {
        $respuesta = $this->capturar(fn () => $this->proyectos()->registrar($this->datosParaModificar('en_ejecucion')));

        self::assertSame(201, $respuesta['codigo']);
        self::assertSame('creada', $respuesta['cuerpo']['estado']);
        self::assertSame('creada', $this->estadoDeLaObra((int) $respuesta['cuerpo']['id']));
    }

    public function testCargarLaPlanificacionPasaLaObraAPlanificacion(): void
    {
        $idProyecto = $this->crearProyecto('creada');

        $respuesta = $this->capturar(fn () => $this->planificacion()->crear((string) $idProyecto, ['fecha_carga' => '2026-03-01']));

        self::assertSame(201, $respuesta['codigo']);
        self::assertSame('planificacion', $this->estadoDeLaObra($idProyecto));
    }

    /** Sin planificacion, una obra que no arranco vuelve a estar solo creada. */
    public function testBorrarLaPlanificacionAntesDeArrancarDevuelveLaObraACreada(): void
    {
        $idProyecto = $this->crearProyecto('planificacion');
        $idPlan = $this->crearPlanificacion($idProyecto);

        $respuesta = $this->capturar(fn () => $this->planificacion()->eliminar((string) $idPlan));

        self::assertSame(200, $respuesta['codigo']);
        self::assertSame('creada', $this->estadoDeLaObra($idProyecto));
    }

    /** Una obra que ya arranco no retrocede por perder la planificacion. */
    public function testLaPlanificacionNoMueveUnaObraQueYaArranco(): void
    {
        $idProyecto = $this->crearProyecto('en_ejecucion');
        $idPlan = $this->crearPlanificacion($idProyecto);

        $this->capturar(fn () => $this->planificacion()->eliminar((string) $idPlan));
        self::assertSame('en_ejecucion', $this->estadoDeLaObra($idProyecto));

        $this->capturar(fn () => $this->planificacion()->crear((string) $idProyecto, ['fecha_carga' => '2026-03-01']));
        self::assertSame('en_ejecucion', $this->estadoDeLaObra($idProyecto));
    }

    private function inactividad(): InactividadController
    {
        return new InactividadController($this->base());
    }

    private function proyectos(): ProyectoController
    {
        return new ProyectoController(new MySqlProyectoRepository($this->base()));
    }

    private function planificacion(): PlanificacionController
    {
        return new PlanificacionController($this->base());
    }

    /** @return array<string, string> */
    private function datosParaModificar(string $estado): array
    {
        return [
            'nombre' => 'Obra de prueba',
            'tipo' => 'Infraestructura Vial',
            'ubicacion' => 'Posadas',
            'encargado' => 'Ing. Prueba',
            'fechaInicio' => date('Y-m-d'),
            'presupuesto' => '1000000',
            'estado' => $estado,
        ];
    }
}
