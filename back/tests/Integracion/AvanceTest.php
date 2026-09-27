<?php

declare(strict_types=1);

namespace Sgso\Tests\Integracion;

use PHPUnit\Framework\Attributes\CoversClass;
use Sgso\AvanceController;

/**
 * Avances fisicos de la obra (RF05). Cada avance mueve dos cosas de la obra:
 * su porcentaje (`proyecto.avance`, el mayor cargado) y, con el primero, su
 * estado, que pasa de `planificacion` a `en_ejecucion`. Llegar al 100 % no la
 * termina: eso lo hace el reporte final (CierrePorReporteFinalTest).
 */
#[CoversClass(AvanceController::class)]
final class AvanceTest extends CasoConBase
{
    /**
     * `lastInsertId()` se leia despues de sincronizar la obra, y con el driver
     * de MySQL cualquier SELECT o UPDATE lo deja en 0: la API devolvia
     * `id_avance: 0` para todo avance nuevo. El front no lo nota porque recarga
     * la lista, pero el contrato (y el simulador) dicen otra cosa.
     */
    public function testCrearUnAvanceDevuelveElIdDelRegistroCreado(): void
    {
        $idPlan = $this->planDeUnaObra();

        $respuesta = $this->crear($idPlan, 25);

        self::assertSame(201, $respuesta['codigo']);
        $real = (int) $this->base()->query('SELECT MAX(id_avance) FROM avance_fisico')->fetchColumn();
        self::assertGreaterThan(0, $real);
        self::assertSame($real, $respuesta['cuerpo']['id_avance']);
    }

    public function testElPrimerAvanceArrancaUnaObraEnPlanificacion(): void
    {
        $idProyecto = $this->crearProyecto('planificacion');
        $idPlan = $this->crearPlanificacion($idProyecto);

        $this->crear($idPlan, 10);

        self::assertSame('en_ejecucion', $this->estadoDeLaObra($idProyecto));
    }

    public function testUnAvanceEnCeroNoArrancaLaObra(): void
    {
        $idProyecto = $this->crearProyecto('planificacion');
        $idPlan = $this->crearPlanificacion($idProyecto);

        $this->crear($idPlan, 0);

        self::assertSame('planificacion', $this->estadoDeLaObra($idProyecto));
    }

    /** El 100 % es un dato, no una decision: la obra la cierra el reporte final. */
    public function testLlegarAlCienNoFinalizaLaObra(): void
    {
        $idProyecto = $this->crearProyecto('en_ejecucion');
        $idPlan = $this->crearPlanificacion($idProyecto);

        $this->crear($idPlan, 100);

        self::assertSame('en_ejecucion', $this->estadoDeLaObra($idProyecto));
        self::assertNumero(100.0, $this->avanceDeLaObra($idProyecto));
    }

    public function testUnAvanceNoReactivaUnaObraPausada(): void
    {
        $idProyecto = $this->crearProyecto('pausada');
        $idPlan = $this->crearPlanificacion($idProyecto);

        $this->crear($idPlan, 40);

        self::assertSame('pausada', $this->estadoDeLaObra($idProyecto));
        self::assertNumero(40.0, $this->avanceDeLaObra($idProyecto));
    }

    public function testElAvanceDeLaObraEsElMayorPorcentajeCargado(): void
    {
        $idProyecto = $this->crearProyecto();
        $idPlan = $this->crearPlanificacion($idProyecto);

        $this->crear($idPlan, 30, '2026-03-01');
        $this->crear($idPlan, 50, '2026-03-02');
        $this->crear($idPlan, 40, '2026-03-03');

        self::assertNumero(50.0, $this->avanceDeLaObra($idProyecto));
    }

    public function testBorrarElAvanceMayorRecalculaElDeLaObra(): void
    {
        $idProyecto = $this->crearProyecto();
        $idPlan = $this->crearPlanificacion($idProyecto);
        $this->crear($idPlan, 30);
        $mayor = $this->crear($idPlan, 50)['cuerpo']['id_avance'];

        $respuesta = $this->capturar(fn () => $this->avances()->eliminar((string) $mayor));

        self::assertSame(200, $respuesta['codigo']);
        self::assertNumero(30.0, $this->avanceDeLaObra($idProyecto));
    }

    public function testCorregirElPorcentajeRecalculaElDeLaObra(): void
    {
        $idProyecto = $this->crearProyecto();
        $idPlan = $this->crearPlanificacion($idProyecto);
        $idAvance = $this->crear($idPlan, 80)['cuerpo']['id_avance'];

        $respuesta = $this->capturar(fn () => $this->avances()->actualizar((string) $idAvance, [
            'porcentaje_avance' => 35,
        ]));

        self::assertSame(200, $respuesta['codigo']);
        self::assertNumero(35.0, $this->avanceDeLaObra($idProyecto));
    }

    public function testActualizarSoloUnCampoConservaLosDemas(): void
    {
        $idPlan = $this->planDeUnaObra();
        $idAvance = $this->crear($idPlan, 20, '2026-04-10')['cuerpo']['id_avance'];

        $this->capturar(fn () => $this->avances()->actualizar((string) $idAvance, ['observaciones' => 'Hormigonado']));
        $avance = $this->capturar(fn () => $this->avances()->mostrar((string) $idAvance))['cuerpo'];

        self::assertSame('Hormigonado', $avance['observaciones']);
        self::assertNumero(20.0, $avance['porcentaje_avance']);
        self::assertSame('2026-04-10', $avance['fecha']);
    }

    public function testNoSeRegistraAvanceEnUnaObraCancelada(): void
    {
        $idProyecto = $this->crearProyecto('cancelada');
        $idPlan = $this->crearPlanificacion($idProyecto);

        $respuesta = $this->crear($idPlan, 60);

        self::assertSame(409, $respuesta['codigo']);
        self::assertSame(0, $this->cantidadDeAvances($idPlan));
        self::assertNumero(0.0, $this->avanceDeLaObra($idProyecto));
    }

    public function testElPorcentajeTieneQueEstarEntre0Y100(): void
    {
        $idPlan = $this->planDeUnaObra();

        foreach ([-1, 100.5, 'mucho'] as $porcentaje) {
            $respuesta = $this->capturar(fn () => $this->avances()->crear((string) $idPlan, [
                'cantidad_ejecutada' => 1,
                'porcentaje_avance' => $porcentaje,
                'fecha' => '2026-03-01',
            ]));

            self::assertSame(422, $respuesta['codigo'], 'porcentaje: ' . var_export($porcentaje, true));
            self::assertArrayHasKey('porcentaje_avance', $respuesta['cuerpo']['errors']);
        }
        self::assertSame(0, $this->cantidadDeAvances($idPlan));
    }

    public function testFaltanCamposObligatorios(): void
    {
        $idPlan = $this->planDeUnaObra();

        $respuesta = $this->capturar(fn () => $this->avances()->crear((string) $idPlan, []));

        self::assertSame(422, $respuesta['codigo']);
        foreach (['cantidad_ejecutada', 'porcentaje_avance', 'fecha'] as $campo) {
            self::assertArrayHasKey($campo, $respuesta['cuerpo']['errors']);
        }
    }

    public function testElResumenComparaElEsperadoConElReal(): void
    {
        $idProyecto = $this->crearProyecto();
        $idPlan = $this->crearPlanificacion($idProyecto);
        $this->crearEtapa($idPlan, 100, '2020-01-01', '2020-12-31'); // terminada: aporta todo su peso
        $this->crear($idPlan, 30, '2026-03-01');
        $this->crear($idPlan, 45, '2026-03-05');

        $respuesta = $this->capturar(fn () => $this->avances()->resumen((string) $idPlan));

        self::assertSame(200, $respuesta['codigo']);
        self::assertNumero(100.0, $respuesta['cuerpo']['avance_esperado']);
        self::assertNumero(45.0, $respuesta['cuerpo']['avance_real']);
        self::assertNumero(-55.0, $respuesta['cuerpo']['desvio_pp']);
        self::assertSame(2, $respuesta['cuerpo']['total_registros']);
        self::assertSame('2026-03-05', $respuesta['cuerpo']['ultimo_registro']);
    }

    public function testLosAvancesSeListanDelMasRecienteAlMasViejo(): void
    {
        $idPlan = $this->planDeUnaObra();
        $this->crear($idPlan, 10, '2026-03-01');
        $this->crear($idPlan, 30, '2026-03-03');
        $this->crear($idPlan, 20, '2026-03-02');

        $respuesta = $this->capturar(fn () => $this->avances()->listarPorPlan((string) $idPlan));

        self::assertSame(['2026-03-03', '2026-03-02', '2026-03-01'], array_column($respuesta['cuerpo'], 'fecha'));
    }

    public function testUnaPlanificacionOUnAvanceInexistentesDan404(): void
    {
        self::assertSame(404, $this->crear(999, 10)['codigo']);
        self::assertSame(404, $this->capturar(fn () => $this->avances()->resumen('999'))['codigo']);
        self::assertSame(404, $this->capturar(fn () => $this->avances()->mostrar('999'))['codigo']);
        self::assertSame(404, $this->capturar(fn () => $this->avances()->actualizar('999', ['porcentaje_avance' => 1]))['codigo']);
        self::assertSame(404, $this->capturar(fn () => $this->avances()->eliminar('999'))['codigo']);
    }

    // ----------------------------------------------------------------
    //  Ayudas
    // ----------------------------------------------------------------

    private function planDeUnaObra(): int
    {
        return $this->crearPlanificacion($this->crearProyecto());
    }

    /** @return array{codigo: int, cuerpo: mixed} */
    private function crear(int $idPlan, float $porcentaje, string $fecha = '2026-03-01'): array
    {
        return $this->capturar(fn () => $this->avances()->crear((string) $idPlan, [
            'cantidad_ejecutada' => 1,
            'porcentaje_avance' => $porcentaje,
            'fecha' => $fecha,
        ]));
    }

    private function avances(): AvanceController
    {
        return new AvanceController($this->base());
    }

    private function avanceDeLaObra(int $idProyecto): float
    {
        $stmt = $this->base()->prepare('SELECT avance FROM proyecto WHERE id_proyecto = ?');
        $stmt->execute([$idProyecto]);

        return (float) $stmt->fetchColumn();
    }

    private function cantidadDeAvances(int $idPlan): int
    {
        $stmt = $this->base()->prepare('SELECT COUNT(*) FROM avance_fisico WHERE id_planificacion = ?');
        $stmt->execute([$idPlan]);

        return (int) $stmt->fetchColumn();
    }
}
