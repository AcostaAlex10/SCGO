<?php

declare(strict_types=1);

namespace Sgso\Tests\Integracion;

use PHPUnit\Framework\Attributes\CoversClass;
use Sgso\AnalisisController;
use Sgso\MaquinariaController;

/**
 * El tablero de analisis y el feed de alertas: RF11 (avance real contra el
 * esperado), RF12 (materiales excedidos), RF13 (presupuesto contra lo
 * ejecutado), RF20 (el Personal Tecnico no ve importes) y RF24 (consumo
 * anomalo de maquinaria).
 *
 * El avance esperado depende de la fecha de hoy: cada etapa aporta su peso en
 * proporcion al tiempo transcurrido. Para que las pruebas no dependan del dia
 * en que corren, las etapas estan enteras en el pasado (aportan todo su peso)
 * o enteras en el futuro (no aportan nada). La unica que cae en curso tiene
 * tolerancia.
 */
#[CoversClass(AnalisisController::class)]
final class AnalisisTest extends CasoConBase
{
    private const PASADO_INICIO = '2020-01-01';
    private const PASADO_FIN = '2020-12-31';
    private const FUTURO_INICIO = '2099-01-01';
    private const FUTURO_FIN = '2099-12-31';

    /** Los roles que si pueden ver costos. */
    private const ROLES_CON_COSTOS = ['PersonalAdministrativo', 'Gerente', 'AdministradorSistema'];

    // ----------------------------------------------------------------
    //  RF20 y RF13: importes
    // ----------------------------------------------------------------

    /**
     * RF20 es Critica y hasta ahora solo la probaba humo.mjs, contra el
     * simulador. Esta es la prueba contra el backend real.
     *
     * Ademas de mirar las claves, se busca cada importe en el JSON crudo: si
     * alguien agrega una clave nueva con el presupuesto, tambien falla.
     */
    public function testElPersonalTecnicoNoRecibeNingunImporte(): void
    {
        $idProyecto = $this->obraConImportes();

        $respuesta = $this->resumen('PersonalTecnico');

        self::assertSame(200, $respuesta['codigo']);
        $obra = $this->obra($respuesta['cuerpo'], $idProyecto);
        foreach (['presupuesto', 'ejecutado', 'diferencia', 'presupuesto_base_total'] as $clave) {
            self::assertArrayNotHasKey($clave, $obra, "El Personal Tecnico recibio '{$clave}'");
        }

        $crudo = (string) json_encode($respuesta['cuerpo']);
        foreach (['987654', '246913', '740740', '55555'] as $importe) {
            self::assertStringNotContainsString($importe, $crudo, "El importe {$importe} llego al Personal Tecnico");
        }
    }

    public function testElPersonalTecnicoSigueViendoElAvanceYLasAlertas(): void
    {
        $idProyecto = $this->obraConImportes();

        $obra = $this->obra($this->resumen('PersonalTecnico')['cuerpo'], $idProyecto);

        self::assertNumero(25.0, $obra['avance_real']);
        self::assertNumero(100.0, $obra['avance_esperado']);
        self::assertTrue($obra['alerta_avance']);
    }

    /** RF13: lo ejecutado se estima como presupuesto por porcentaje de avance. */
    public function testLosDemasRolesVenElPresupuestoLoEjecutadoYLaDiferencia(): void
    {
        $idProyecto = $this->obraConImportes();

        foreach (self::ROLES_CON_COSTOS as $rol) {
            $obra = $this->obra($this->resumen($rol)['cuerpo'], $idProyecto);

            self::assertNumero(987654.32, $obra['presupuesto'], $rol);
            self::assertNumero(246913.58, $obra['ejecutado'], $rol);
            self::assertNumero(740740.74, $obra['diferencia'], $rol);
            self::assertNumero(55555.55, $obra['presupuesto_base_total'], $rol);
        }
    }

    public function testElPresupuestoBaseEsLaSumaDeLasEtapasYNullSinEtapas(): void
    {
        $conEtapas = $this->crearProyecto('en_ejecucion', 'Con etapas');
        $idPlan = $this->crearPlanificacion($conEtapas);
        $this->crearEtapa($idPlan, 60, self::PASADO_INICIO, self::PASADO_FIN, 100000);
        $this->crearEtapa($idPlan, 40, self::FUTURO_INICIO, self::FUTURO_FIN, 50000.5);
        $sinEtapas = $this->crearProyecto('en_ejecucion', 'Sin etapas');
        $this->crearPlanificacion($sinEtapas, 10);

        $cuerpo = $this->resumen('Gerente')['cuerpo'];

        self::assertNumero(150000.5, $this->obra($cuerpo, $conEtapas)['presupuesto_base_total']);
        self::assertNull($this->obra($cuerpo, $sinEtapas)['presupuesto_base_total']);
    }

    // ----------------------------------------------------------------
    //  RF11: avance real contra el esperado
    // ----------------------------------------------------------------

    public function testAlertaCuandoElAvanceRealQuedaDebajoDelEsperado(): void
    {
        $idProyecto = $this->crearProyecto('en_ejecucion', 'Atrasada');
        $this->crearEtapa($this->crearPlanificacion($idProyecto), 100, self::PASADO_INICIO, self::PASADO_FIN);
        $this->fijarAvance($idProyecto, 40);

        $cuerpo = $this->resumen('Gerente')['cuerpo'];
        $obra = $this->obra($cuerpo, $idProyecto);

        self::assertNumero(100.0, $obra['avance_esperado']);
        self::assertNumero(-60.0, $obra['desvio_avance']);
        self::assertTrue($obra['alerta_avance']);

        $alerta = $this->alertaDe($cuerpo, 'Atrasada', 'avance');
        self::assertNotNull($alerta);
        self::assertSame('alta', $alerta['gravedad']);
    }

    /** Un desvio de 15 puntos o mas es gravedad alta; menos, media. */
    public function testLaGravedadDeLaAlertaCambiaALos15PuntosDeDesvio(): void
    {
        foreach (['Quince' => 85, 'Catorce' => 86] as $nombre => $avance) {
            $idProyecto = $this->crearProyecto('en_ejecucion', $nombre);
            $this->crearEtapa($this->crearPlanificacion($idProyecto), 100, self::PASADO_INICIO, self::PASADO_FIN);
            $this->fijarAvance($idProyecto, $avance);
        }

        $cuerpo = $this->resumen('Gerente')['cuerpo'];

        self::assertSame('alta', $this->alertaDe($cuerpo, 'Quince', 'avance')['gravedad'] ?? null);
        self::assertSame('media', $this->alertaDe($cuerpo, 'Catorce', 'avance')['gravedad'] ?? null);
    }

    public function testNoHayAlertaCuandoElAvanceAlcanzaAlEsperado(): void
    {
        $idProyecto = $this->crearProyecto('en_ejecucion', 'Al dia');
        $this->crearEtapa($this->crearPlanificacion($idProyecto), 100, self::PASADO_INICIO, self::PASADO_FIN);
        $this->fijarAvance($idProyecto, 100);

        $cuerpo = $this->resumen('Gerente')['cuerpo'];

        self::assertFalse($this->obra($cuerpo, $idProyecto)['alerta_avance']);
        self::assertNumero(0.0, $this->obra($cuerpo, $idProyecto)['desvio_avance']);
        self::assertNull($this->alertaDe($cuerpo, 'Al dia', 'avance'));
    }

    public function testUnaEtapaQueTodaviaNoEmpiezaNoSumaAlEsperado(): void
    {
        $idProyecto = $this->crearProyecto();
        $idPlan = $this->crearPlanificacion($idProyecto);
        $this->crearEtapa($idPlan, 40, self::PASADO_INICIO, self::PASADO_FIN);
        $this->crearEtapa($idPlan, 60, self::FUTURO_INICIO, self::FUTURO_FIN);
        $this->fijarAvance($idProyecto, 40);

        $obra = $this->obra($this->resumen('Gerente')['cuerpo'], $idProyecto);

        self::assertNumero(40.0, $obra['avance_esperado']);
        self::assertFalse($obra['alerta_avance']);
    }

    /**
     * Una etapa en curso aporta su peso en proporcion al tiempo transcurrido.
     * Esta arranco hace 10 dias y termina en 10: va por la mitad. La tolerancia
     * cubre un cambio de horario en el medio, si la zona horaria lo tuviera.
     */
    public function testUnaEtapaEnCursoAportaSegunElTiempoTranscurrido(): void
    {
        $idProyecto = $this->crearProyecto();
        $this->crearEtapa(
            $this->crearPlanificacion($idProyecto),
            100,
            date('Y-m-d', strtotime('-10 days')),
            date('Y-m-d', strtotime('+10 days'))
        );

        $obra = $this->obra($this->resumen('Gerente')['cuerpo'], $idProyecto);

        self::assertEqualsWithDelta(50.0, (float) $obra['avance_esperado'], 1.0);
    }

    public function testSinEtapasUsaElAvanceEsperadoCargadoEnLaPlanificacion(): void
    {
        $idProyecto = $this->crearProyecto();
        $this->crearPlanificacion($idProyecto, 30);
        $this->fijarAvance($idProyecto, 20);

        $obra = $this->obra($this->resumen('Gerente')['cuerpo'], $idProyecto);

        self::assertNumero(30.0, $obra['avance_esperado']);
        self::assertTrue($obra['alerta_avance']);
    }

    public function testUnaObraSinPlanificacionNoTieneEsperadoNiAlerta(): void
    {
        $idProyecto = $this->crearProyecto('planificacion', 'Sin plan');

        $cuerpo = $this->resumen('Gerente')['cuerpo'];
        $obra = $this->obra($cuerpo, $idProyecto);

        self::assertNull($obra['avance_esperado']);
        self::assertNull($obra['desvio_avance']);
        self::assertFalse($obra['alerta_avance']);
        self::assertSame([], $cuerpo['alertas']);
    }

    // ----------------------------------------------------------------
    //  RF12: materiales excedidos
    // ----------------------------------------------------------------

    public function testCuentaLosMaterialesExcedidosYLosAvisaEnLasAlertas(): void
    {
        $conExceso = $this->crearProyecto('en_ejecucion', 'Con exceso');
        $this->registrarConsumo($this->asignarMaterial($conExceso, $this->crearMaterial('Cemento'), 10), 11);
        $this->registrarConsumo($this->asignarMaterial($conExceso, $this->crearMaterial('Arena', 'm3'), 5), 6);
        $this->registrarConsumo($this->asignarMaterial($conExceso, $this->crearMaterial('Cal'), 5), 5);
        $sinExceso = $this->crearProyecto('en_ejecucion', 'Sin exceso');

        $cuerpo = $this->resumen('Gerente')['cuerpo'];

        self::assertSame(2, $this->obra($cuerpo, $conExceso)['materiales_excedidos']);
        self::assertSame(0, $this->obra($cuerpo, $sinExceso)['materiales_excedidos']);
        self::assertSame('media', $this->alertaDe($cuerpo, 'Con exceso', 'material')['gravedad'] ?? null);
        self::assertNull($this->alertaDe($cuerpo, 'Sin exceso', 'material'));
    }

    // ----------------------------------------------------------------
    //  RF24: consumo anomalo de maquinaria (D-03)
    // ----------------------------------------------------------------

    /**
     * El listado de la maquina ya marcaba el registro, pero el feed no lo
     * incluia: nadie lo veia salvo que abriera esa maquina.
     */
    public function testUnConsumoAnomaloDeMaquinariaApareceEnLasAlertas(): void
    {
        $idObra = $this->crearProyecto('en_ejecucion', 'Obra Norte');
        $idMaquina = $this->crearMaquina('Retroexcavadora');
        $this->cargarUso($idMaquina, '2026-03-01', 1, 10, $idObra);
        $this->cargarUso($idMaquina, '2026-03-02', 1, 10, $idObra);
        // Promedio 20 l/h: 40 l/h supera el umbral de 30.
        $this->cargarUso($idMaquina, '2026-03-03', 1, 40, $idObra);

        $alertas = $this->alertasDeMaquinaria($this->resumen('Gerente')['cuerpo']);

        self::assertCount(1, $alertas);
        self::assertSame('Retroexcavadora', $alertas[0]['maquina']);
        self::assertSame('Obra Norte', $alertas[0]['proyecto']);
        self::assertSame('media', $alertas[0]['gravedad']);
        self::assertStringContainsString('03/03/2026', $alertas[0]['mensaje']);
    }

    /** Una alerta por maquina, no una por registro: con cuantos son y el ultimo. */
    public function testUnaAlertaPorMaquinaConCuantosRegistrosYElUltimo(): void
    {
        $idObra = $this->crearProyecto('en_ejecucion', 'Obra Sur');
        $idMaquina = $this->crearMaquina('Grua');
        for ($dia = 1; $dia <= 6; $dia++) {
            $this->cargarUso($idMaquina, "2026-04-0{$dia}", 1, 10, $idObra);
        }
        // Promedio 140 / 8 = 17,5 l/h: el umbral es 26,25 y los dos de 40 lo superan.
        $this->cargarUso($idMaquina, '2026-04-10', 1, 40, $idObra);
        $this->cargarUso($idMaquina, '2026-04-08', 1, 40, $idObra);

        $alertas = $this->alertasDeMaquinaria($this->resumen('Gerente')['cuerpo']);

        self::assertCount(1, $alertas);
        self::assertStringContainsString('2 registros', $alertas[0]['mensaje']);
        self::assertStringContainsString('10/04/2026', $alertas[0]['mensaje']);
    }

    public function testUnaMaquinaSinDesviosNoAlerta(): void
    {
        $idMaquina = $this->crearMaquina('Hormigonera');
        $this->cargarUso($idMaquina, '2026-03-01', 2, 20);
        $this->cargarUso($idMaquina, '2026-03-02', 1, 12);
        // Una carga sin horas no consume por hora: no alerta ni corre el promedio.
        $this->cargarUso($idMaquina, '2026-03-03', 0, 50);

        self::assertSame([], $this->alertasDeMaquinaria($this->resumen('Gerente')['cuerpo']));
    }

    /** Un uso sin obra asignada tambien alerta: la maquina es la que consume. */
    public function testElUsoSinObraAlertaSinProyecto(): void
    {
        $idMaquina = $this->crearMaquina('Compactadora');
        $this->cargarUso($idMaquina, '2026-03-01', 1, 10);
        $this->cargarUso($idMaquina, '2026-03-02', 1, 10);
        $this->cargarUso($idMaquina, '2026-03-03', 1, 40);

        $alertas = $this->alertasDeMaquinaria($this->resumen('PersonalTecnico')['cuerpo']);

        self::assertCount(1, $alertas);
        self::assertNull($alertas[0]['proyecto']);
    }

    /**
     * El feed y el listado de la maquina tienen que decir lo mismo: si uno
     * marca un registro y el otro no, el usuario ve dos verdades.
     */
    public function testElFeedCuentaLosMismosRegistrosQueMarcaElListadoDeLaMaquina(): void
    {
        $idMaquina = $this->crearMaquina('Topadora');
        // Promedio 210 / 14 = 15 l/h y umbral 22,5: superan el de 30 y el de 31; el de 20 no.
        foreach ([[4, 40], [2, 20], [1, 10], [1, 30], [3, 60], [1, 31], [2, 19]] as $i => [$horas, $litros]) {
            $this->cargarUso($idMaquina, '2026-05-0' . ($i + 1), $horas, $litros);
        }

        $listado = $this->capturar(fn () => (new MaquinariaController($this->base()))->listarRegistros((string) $idMaquina))['cuerpo'];
        $marcados = count(array_filter($listado, fn (array $r): bool => $r['alerta_consumo']));
        $alertas = $this->alertasDeMaquinaria($this->resumen('Gerente')['cuerpo']);

        self::assertSame(2, $marcados);
        self::assertCount(1, $alertas);
        self::assertStringContainsString("{$marcados} registros", $alertas[0]['mensaje']);
    }

    // ----------------------------------------------------------------
    //  Ayudas
    // ----------------------------------------------------------------

    /**
     * Una obra con importes faciles de reconocer en el JSON: presupuesto
     * 987.654,32, avance 25 % (ejecutado 246.913,58, diferencia 740.740,74) y
     * una etapa terminada con presupuesto base 55.555,55.
     */
    private function obraConImportes(): int
    {
        $idProyecto = $this->crearProyecto('en_ejecucion', 'Con importes');
        $this->base()->prepare('UPDATE proyecto SET presupuesto = ? WHERE id_proyecto = ?')
            ->execute([987654.32, $idProyecto]);
        $this->fijarAvance($idProyecto, 25);
        $this->crearEtapa(
            $this->crearPlanificacion($idProyecto),
            100,
            self::PASADO_INICIO,
            self::PASADO_FIN,
            55555.55
        );

        return $idProyecto;
    }

    private function fijarAvance(int $idProyecto, float $avance): void
    {
        $this->base()->prepare('UPDATE proyecto SET avance = ? WHERE id_proyecto = ?')
            ->execute([$avance, $idProyecto]);
    }

    /** @return array{codigo: int, cuerpo: mixed} */
    private function resumen(string $rol): array
    {
        return $this->capturar(fn () => (new AnalisisController($this->base()))->resumen($rol));
    }

    /**
     * @param array<string, mixed> $cuerpo
     * @return array<string, mixed>
     */
    private function obra(array $cuerpo, int $idProyecto): array
    {
        foreach ($cuerpo['proyectos'] as $obra) {
            if ($obra['id_proyecto'] === $idProyecto) {
                return $obra;
            }
        }
        self::fail("La obra {$idProyecto} no esta en el resumen");
    }

    private function crearMaquina(string $nombre): int
    {
        $this->base()->prepare("INSERT INTO maquinaria (nombre, tipo) VALUES (?, 'Movimiento de suelos')")->execute([$nombre]);
        return (int) $this->base()->lastInsertId();
    }

    private function cargarUso(int $idMaquina, string $fecha, float $horas, float $litros, ?int $idProyecto = null): void
    {
        $this->base()->prepare(
            'INSERT INTO registro_maquinaria (id_maquinaria, id_proyecto, fecha, horas_uso, combustible_consumido) VALUES (?, ?, ?, ?, ?)'
        )->execute([$idMaquina, $idProyecto, $fecha, $horas, $litros]);
    }

    /**
     * @param array<string, mixed> $cuerpo
     * @return list<array<string, mixed>>
     */
    private function alertasDeMaquinaria(array $cuerpo): array
    {
        return array_values(array_filter($cuerpo['alertas'], fn (array $a): bool => $a['tipo'] === 'maquinaria'));
    }

    /**
     * @param array<string, mixed> $cuerpo
     * @return array<string, mixed>|null
     */
    private function alertaDe(array $cuerpo, string $obra, string $tipo): ?array
    {
        foreach ($cuerpo['alertas'] as $alerta) {
            if ($alerta['proyecto'] === $obra && $alerta['tipo'] === $tipo) {
                return $alerta;
            }
        }

        return null;
    }
}
