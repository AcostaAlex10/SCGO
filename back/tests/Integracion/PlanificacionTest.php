<?php

declare(strict_types=1);

namespace Sgso\Tests\Integracion;

use PHPUnit\Framework\Attributes\CoversClass;
use Sgso\PlanificacionController;

/**
 * La planificacion de la obra (RF03, HU02): una por obra, con el avance
 * esperado que se usa cuando todavia no hay etapas cargadas.
 */
#[CoversClass(PlanificacionController::class)]
final class PlanificacionTest extends CasoConBase
{
    public function testCrearLaPlanificacionLaDejaDisponibleParaLaObra(): void
    {
        $idProyecto = $this->crearProyecto();

        $respuesta = $this->capturar(fn () => $this->planes()->crear((string) $idProyecto, [
            'avance_esperado_total' => 35,
            'fecha_carga' => '2026-03-01',
        ]));

        self::assertSame(201, $respuesta['codigo']);
        $leida = $this->capturar(fn () => $this->planes()->obtenerPorProyecto((string) $idProyecto));
        self::assertSame(200, $leida['codigo']);
        self::assertSame($respuesta['cuerpo']['id_planificacion'], $leida['cuerpo']['id_planificacion']);
        self::assertNumero(35.0, $leida['cuerpo']['avance_esperado_total']);
        self::assertSame('2026-03-01', $leida['cuerpo']['fecha_carga']);
    }

    public function testElAvanceEsperadoEsOpcional(): void
    {
        $idProyecto = $this->crearProyecto();

        $respuesta = $this->capturar(fn () => $this->planes()->crear((string) $idProyecto, [
            'fecha_carga' => '2026-03-01',
        ]));

        self::assertSame(201, $respuesta['codigo']);
        self::assertNumero(0.0, $respuesta['cuerpo']['avance_esperado_total']);
    }

    public function testUnaObraTieneUnaSolaPlanificacion(): void
    {
        $idProyecto = $this->crearProyecto();
        $this->crearPlanificacion($idProyecto);

        $respuesta = $this->capturar(fn () => $this->planes()->crear((string) $idProyecto, [
            'fecha_carga' => '2026-03-01',
        ]));

        self::assertSame(409, $respuesta['codigo']);
        $cantidad = $this->base()->prepare('SELECT COUNT(*) FROM planificacion WHERE id_proyecto = ?');
        $cantidad->execute([$idProyecto]);
        self::assertSame(1, (int) $cantidad->fetchColumn());
    }

    public function testValidaElAvanceEsperadoYLaFecha(): void
    {
        $idProyecto = $this->crearProyecto();

        $fueraDeRango = $this->capturar(fn () => $this->planes()->crear((string) $idProyecto, [
            'avance_esperado_total' => 150,
            'fecha_carga' => '2026-03-01',
        ]));
        $sinFecha = $this->capturar(fn () => $this->planes()->crear((string) $idProyecto, []));
        $fechaMal = $this->capturar(fn () => $this->planes()->crear((string) $idProyecto, [
            'fecha_carga' => '01/03/2026',
        ]));

        self::assertSame(422, $fueraDeRango['codigo']);
        self::assertArrayHasKey('avance_esperado_total', $fueraDeRango['cuerpo']['errors']);
        self::assertSame(422, $sinFecha['codigo']);
        self::assertArrayHasKey('fecha_carga', $sinFecha['cuerpo']['errors']);
        self::assertSame(422, $fechaMal['codigo']);
        self::assertSame(404, $this->capturar(fn () => $this->planes()->obtenerPorProyecto((string) $idProyecto))['codigo']);
    }

    public function testActualizarSoloElAvanceEsperadoConservaLaFecha(): void
    {
        $idProyecto = $this->crearProyecto();
        $idPlan = $this->crearPlanificacion($idProyecto, 10);

        $respuesta = $this->capturar(fn () => $this->planes()->actualizar((string) $idPlan, [
            'avance_esperado_total' => 60,
        ]));

        self::assertSame(200, $respuesta['codigo']);
        $leida = $this->capturar(fn () => $this->planes()->obtenerPorProyecto((string) $idProyecto))['cuerpo'];
        self::assertNumero(60.0, $leida['avance_esperado_total']);
        self::assertSame('2020-01-01', $leida['fecha_carga']);
    }

    /**
     * Borrar la planificacion arrastra sus etapas y todos los avances cargados
     * (`ON DELETE CASCADE`), y el porcentaje de la obra queda con el ultimo
     * valor, sin avances que lo respalden. Se fija para que cambiarlo sea a
     * conciencia: es parte de D-05 (registro de cambios) y de C-09 (el avance
     * se guarda en vez de calcularse).
     */
    public function testBorrarLaPlanificacionBorraSusEtapasYSusAvances(): void
    {
        $idProyecto = $this->crearProyecto();
        $idPlan = $this->crearPlanificacion($idProyecto);
        $this->crearEtapa($idPlan, 100, '2026-01-01', '2026-12-31');
        $this->base()->prepare(
            "INSERT INTO avance_fisico (cantidad_ejecutada, porcentaje_avance, fecha, id_planificacion) VALUES (1, 40, '2026-03-01', ?)"
        )->execute([$idPlan]);

        $respuesta = $this->capturar(fn () => $this->planes()->eliminar((string) $idPlan));
        $otraVez = $this->capturar(fn () => $this->planes()->eliminar((string) $idPlan));

        self::assertSame(200, $respuesta['codigo']);
        self::assertSame(404, $otraVez['codigo']);
        foreach (['etapa_planificacion', 'avance_fisico'] as $tabla) {
            $stmt = $this->base()->prepare("SELECT COUNT(*) FROM {$tabla} WHERE id_planificacion = ?");
            $stmt->execute([$idPlan]);
            self::assertSame(0, (int) $stmt->fetchColumn(), $tabla);
        }
    }

    public function testUnaObraOUnaPlanificacionInexistentesDan404(): void
    {
        self::assertSame(404, $this->capturar(fn () => $this->planes()->crear('999', ['fecha_carga' => '2026-03-01']))['codigo']);
        self::assertSame(404, $this->capturar(fn () => $this->planes()->obtenerPorProyecto('999'))['codigo']);
        self::assertSame(404, $this->capturar(fn () => $this->planes()->actualizar('999', ['avance_esperado_total' => 1]))['codigo']);
    }

    private function planes(): PlanificacionController
    {
        return new PlanificacionController($this->base());
    }
}
