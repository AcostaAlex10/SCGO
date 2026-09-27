<?php

declare(strict_types=1);

namespace Sgso\Tests\Integracion;

use PHPUnit\Framework\Attributes\CoversClass;
use Sgso\EtapaPlanificacionController;

/**
 * Las etapas de la planificacion: de ellas sale el avance esperado a la fecha
 * (RF11) y el presupuesto base (RF13), asi que sus reglas pesan. Los pesos de
 * un plan no pueden pasar de 100, y ninguna etapa puede terminar antes de
 * empezar.
 */
#[CoversClass(EtapaPlanificacionController::class)]
final class EtapaPlanificacionTest extends CasoConBase
{
    public function testCrearUnaEtapaLaAgregaAlFinalYDevuelveLaSumaDePesos(): void
    {
        $idPlan = $this->planDeUnaObra();
        $this->crearEtapa($idPlan, 30, '2026-01-01', '2026-03-31');

        $respuesta = $this->crear($idPlan, 50);

        self::assertSame(201, $respuesta['codigo']);
        self::assertNumero(80.0, $respuesta['cuerpo']['suma_pesos']);
        self::assertSame(1, $respuesta['cuerpo']['orden'], 'orden = max + 1; la etapa del helper tiene orden 0');
        $real = (int) $this->base()->query('SELECT MAX(id_etapa) FROM etapa_planificacion')->fetchColumn();
        self::assertSame($real, $respuesta['cuerpo']['id_etapa']);
    }

    public function testLosPesosPuedenSumarExactamente100(): void
    {
        $idPlan = $this->planDeUnaObra();
        $this->crearEtapa($idPlan, 60, '2026-01-01', '2026-03-31');

        self::assertSame(201, $this->crear($idPlan, 40)['codigo']);
    }

    public function testLosPesosNoPuedenPasarDe100(): void
    {
        $idPlan = $this->planDeUnaObra();
        $this->crearEtapa($idPlan, 60, '2026-01-01', '2026-03-31');

        $respuesta = $this->crear($idPlan, 41);

        self::assertSame(422, $respuesta['codigo']);
        self::assertStringContainsString('40.00%', $respuesta['cuerpo']['errors']['peso_porcentual']);
        self::assertSame(1, $this->cantidadDeEtapas($idPlan));
    }

    /** Al cambiar el peso de una etapa, la suma no cuenta el peso viejo de esa misma etapa. */
    public function testCambiarElPesoDeUnaEtapaNoLaCuentaDosVeces(): void
    {
        $idPlan = $this->planDeUnaObra();
        $this->crearEtapa($idPlan, 40, '2026-01-01', '2026-03-31');
        $idEtapa = $this->crearEtapa($idPlan, 60, '2026-04-01', '2026-06-30');

        $aSesenta = $this->capturar(fn () => $this->etapas()->actualizar((string) $idEtapa, ['peso_porcentual' => 60]));
        $aSetenta = $this->capturar(fn () => $this->etapas()->actualizar((string) $idEtapa, ['peso_porcentual' => 70]));

        self::assertSame(200, $aSesenta['codigo']);
        self::assertSame(422, $aSetenta['codigo']);
    }

    public function testUnaEtapaNoPuedeTerminarAntesDeEmpezar(): void
    {
        $idPlan = $this->planDeUnaObra();

        $respuesta = $this->capturar(fn () => $this->etapas()->crear((string) $idPlan, [
            'nombre' => 'Excavacion',
            'peso_porcentual' => 20,
            'fecha_inicio' => '2026-05-01',
            'fecha_fin' => '2026-04-01',
        ]));

        self::assertSame(422, $respuesta['codigo']);
        self::assertArrayHasKey('fecha_fin', $respuesta['cuerpo']['errors']);
    }

    /**
     * La comparacion de fechas solo corria si llegaban las dos. Un PUT con solo
     * `fecha_fin`, anterior al inicio guardado, pasaba la validacion y dejaba
     * una etapa que termina antes de empezar.
     */
    public function testCorregirSoloLaFechaDeFinTambienLaComparaConElInicio(): void
    {
        $idPlan = $this->planDeUnaObra();
        $idEtapa = $this->crearEtapa($idPlan, 20, '2026-05-01', '2026-06-30');

        $respuesta = $this->capturar(fn () => $this->etapas()->actualizar((string) $idEtapa, [
            'fecha_fin' => '2026-04-01',
        ]));

        self::assertSame(422, $respuesta['codigo']);
        self::assertArrayHasKey('fecha_fin', $respuesta['cuerpo']['errors']);
        self::assertSame('2026-06-30', $this->fechasDeLaEtapa($idEtapa)['fecha_fin']);
    }

    /** Lo mismo del otro lado: mover solo el inicio despues del fin guardado. */
    public function testCorregirSoloLaFechaDeInicioTambienLaComparaConElFin(): void
    {
        $idPlan = $this->planDeUnaObra();
        $idEtapa = $this->crearEtapa($idPlan, 20, '2026-05-01', '2026-06-30');

        $respuesta = $this->capturar(fn () => $this->etapas()->actualizar((string) $idEtapa, [
            'fecha_inicio' => '2026-07-15',
        ]));

        self::assertSame(422, $respuesta['codigo']);
        self::assertSame('2026-05-01', $this->fechasDeLaEtapa($idEtapa)['fecha_inicio']);
    }

    public function testCorregirSoloUnaFechaDentroDelRangoSeGuarda(): void
    {
        $idPlan = $this->planDeUnaObra();
        $idEtapa = $this->crearEtapa($idPlan, 20, '2026-05-01', '2026-06-30');

        $respuesta = $this->capturar(fn () => $this->etapas()->actualizar((string) $idEtapa, [
            'fecha_fin' => '2026-07-31',
        ]));

        self::assertSame(200, $respuesta['codigo']);
        self::assertSame('2026-07-31', $this->fechasDeLaEtapa($idEtapa)['fecha_fin']);
    }

    public function testFaltanCamposObligatorios(): void
    {
        $idPlan = $this->planDeUnaObra();

        $respuesta = $this->capturar(fn () => $this->etapas()->crear((string) $idPlan, []));

        self::assertSame(422, $respuesta['codigo']);
        foreach (['nombre', 'peso_porcentual', 'fecha_inicio', 'fecha_fin'] as $campo) {
            self::assertArrayHasKey($campo, $respuesta['cuerpo']['errors']);
        }
    }

    public function testElPresupuestoBaseNoPuedeSerNegativo(): void
    {
        $idPlan = $this->planDeUnaObra();

        $respuesta = $this->capturar(fn () => $this->etapas()->crear((string) $idPlan, [
            'nombre' => 'Estructura',
            'peso_porcentual' => 20,
            'fecha_inicio' => '2026-01-01',
            'fecha_fin' => '2026-02-01',
            'presupuesto_base' => -1,
        ]));

        self::assertSame(422, $respuesta['codigo']);
        self::assertArrayHasKey('presupuesto_base', $respuesta['cuerpo']['errors']);
    }

    public function testLasEtapasSeListanPorOrden(): void
    {
        $idPlan = $this->planDeUnaObra();
        foreach (['Tercera' => 3, 'Primera' => 1, 'Segunda' => 2] as $nombre => $orden) {
            $this->capturar(fn () => $this->etapas()->crear((string) $idPlan, [
                'nombre' => $nombre,
                'peso_porcentual' => 10,
                'fecha_inicio' => '2026-01-01',
                'fecha_fin' => '2026-02-01',
                'orden' => $orden,
            ]));
        }

        $respuesta = $this->capturar(fn () => $this->etapas()->listar((string) $idPlan));

        self::assertSame(['Primera', 'Segunda', 'Tercera'], array_column($respuesta['cuerpo'], 'nombre'));
    }

    public function testEliminarUnaEtapaLiberaSuPeso(): void
    {
        $idPlan = $this->planDeUnaObra();
        $idEtapa = $this->crearEtapa($idPlan, 70, '2026-01-01', '2026-03-31');

        $respuesta = $this->capturar(fn () => $this->etapas()->eliminar((string) $idEtapa));
        $otraVez = $this->capturar(fn () => $this->etapas()->eliminar((string) $idEtapa));

        self::assertSame(200, $respuesta['codigo']);
        self::assertSame(404, $otraVez['codigo']);
        self::assertSame(201, $this->crear($idPlan, 100)['codigo']);
    }

    public function testUnaPlanificacionOUnaEtapaInexistentesDan404(): void
    {
        self::assertSame(404, $this->crear(999, 10)['codigo']);
        self::assertSame(404, $this->capturar(fn () => $this->etapas()->listar('999'))['codigo']);
        self::assertSame(404, $this->capturar(fn () => $this->etapas()->actualizar('999', ['nombre' => 'X']))['codigo']);
    }

    // ----------------------------------------------------------------
    //  Ayudas
    // ----------------------------------------------------------------

    private function planDeUnaObra(): int
    {
        return $this->crearPlanificacion($this->crearProyecto());
    }

    /** @return array{codigo: int, cuerpo: mixed} */
    private function crear(int $idPlan, float $peso): array
    {
        return $this->capturar(fn () => $this->etapas()->crear((string) $idPlan, [
            'nombre' => 'Etapa nueva',
            'peso_porcentual' => $peso,
            'fecha_inicio' => '2026-04-01',
            'fecha_fin' => '2026-06-30',
        ]));
    }

    private function etapas(): EtapaPlanificacionController
    {
        return new EtapaPlanificacionController($this->base());
    }

    private function cantidadDeEtapas(int $idPlan): int
    {
        $stmt = $this->base()->prepare('SELECT COUNT(*) FROM etapa_planificacion WHERE id_planificacion = ?');
        $stmt->execute([$idPlan]);

        return (int) $stmt->fetchColumn();
    }

    /** @return array<string, string> */
    private function fechasDeLaEtapa(int $idEtapa): array
    {
        $stmt = $this->base()->prepare('SELECT fecha_inicio, fecha_fin FROM etapa_planificacion WHERE id_etapa = ?');
        $stmt->execute([$idEtapa]);

        return $stmt->fetch();
    }
}
