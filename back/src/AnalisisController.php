<?php

declare(strict_types=1);

namespace Sgso;

use DateTimeImmutable;
use PDO;
use Sgso\Reglas\Certificacion;
use Sgso\Reglas\ConsumoMaquinaria;

/**
 * Analisis y alertas (RF11/RF13). No tiene tablas propias: calcula
 * indicadores a partir de los datos existentes (proyectos, planificacion,
 * avances y materiales).
 *
 *  - RF11: alerta cuando el avance real es menor al esperado planificado.
 *  - RF13: diferencia entre el presupuesto y el gasto ejecutado estimado
 *          (presupuesto x % de avance).
 *  - (bonus RF12) alerta cuando un material supera la cantidad asignada.
 *  - RF24: alerta cuando una maquina tiene registros de uso con consumo
 *          anomalo (D-03), con la misma regla que el listado de la maquina.
 *
 * RF20: al Personal Tecnico se le ocultan los importes (presupuesto, gasto
 * ejecutado y diferencia).
 */
final class AnalisisController
{
    public function __construct(private PDO $db)
    {
    }

    /** GET /api/analisis */
    public function resumen(?string $rol = null): void
    {
        $ocultarCostos = $rol === 'PersonalTecnico';

        // Proyectos con su planificacion (incluye id_planificacion para calcular etapas).
        $stmt = $this->db->query(
            'SELECT p.id_proyecto, p.nombre, p.estado, p.avance, p.presupuesto,
                    pl.id_planificacion, pl.avance_esperado_total
             FROM proyecto p
             LEFT JOIN planificacion pl ON pl.id_proyecto = p.id_proyecto
             ORDER BY p.nombre'
        );
        $filas = $stmt->fetchAll();

        // Calcular esperado por etapas en una sola query (batch).
        $planIds   = [];
        $fallbacks = [];
        foreach ($filas as $f) {
            if ($f['id_planificacion'] !== null) {
                $pid = (int) $f['id_planificacion'];
                $planIds[]        = $pid;
                $fallbacks[$pid]  = (float) $f['avance_esperado_total'];
            }
        }
        $esperadosPorPlan = EtapaPlanificacionController::calcularEsperadoBatch($this->db, $planIds, $fallbacks);

        // Presupuesto base total por proyecto (suma de etapas). Solo para no-Tecnico (RF20).
        $presupuestoBasePorProyecto = [];
        if (!$ocultarCostos && !empty($planIds)) {
            $placeholders = implode(',', array_fill(0, count($planIds), '?'));
            $stmtPB = $this->db->prepare(
                "SELECT pl.id_proyecto, COALESCE(SUM(ep.presupuesto_base), 0) AS presupuesto_base_total
                 FROM planificacion pl
                 JOIN etapa_planificacion ep ON ep.id_planificacion = pl.id_planificacion
                 WHERE pl.id_planificacion IN ($placeholders)
                 GROUP BY pl.id_proyecto"
            );
            $stmtPB->execute($planIds);
            foreach ($stmtPB->fetchAll() as $row) {
                $presupuestoBasePorProyecto[(int) $row['id_proyecto']] = (float) $row['presupuesto_base_total'];
            }
        }

        // Materiales excedidos por proyecto (RF12).
        $excedidos = $this->materialesExcedidosPorProyecto();

        $proyectos = [];
        $alertas = [];
        foreach ($filas as $f) {
            $idP = (int) $f['id_proyecto'];
            $avanceReal = (float) $f['avance'];
            $esperado = $f['id_planificacion'] !== null
                ? ($esperadosPorPlan[(int) $f['id_planificacion']] ?? null)
                : null;
            $alertaAvance = $esperado !== null && $avanceReal < $esperado;
            $matExc = $excedidos[$idP] ?? 0;

            $item = [
                'id_proyecto' => $idP,
                'nombre' => $f['nombre'],
                'estado' => $f['estado'],
                'avance_real' => $avanceReal,
                'avance_esperado' => $esperado,
                'desvio_avance' => $esperado !== null ? round($avanceReal - $esperado, 2) : null,
                'alerta_avance' => $alertaAvance,
                'materiales_excedidos' => $matExc,
            ];

            if (!$ocultarCostos) {
                $presupuesto = (float) $f['presupuesto'];
                $ejecutado = Certificacion::monto($presupuesto, $avanceReal);
                $item['presupuesto'] = $presupuesto;
                $item['ejecutado']   = $ejecutado;   // RF13 (estimado por avance)
                $item['diferencia']  = round($presupuesto - $ejecutado, 2);
                // Presupuesto base planificado (suma de etapas). null si el proyecto no tiene etapas.
                $pbTotal = $presupuestoBasePorProyecto[$idP] ?? null;
                $item['presupuesto_base_total'] = $pbTotal;
            }

            $proyectos[] = $item;

            // Feed de alertas
            if ($alertaAvance) {
                $alertas[] = [
                    'tipo' => 'avance',
                    'gravedad' => ($esperado - $avanceReal) >= 15 ? 'alta' : 'media',
                    'proyecto' => $f['nombre'],
                    'mensaje' => 'El avance real (' . $avanceReal . '%) está por debajo del esperado (' . $esperado . '%).',
                ];
            }
            if ($matExc > 0) {
                $alertas[] = [
                    'tipo' => 'material',
                    'gravedad' => 'media',
                    'proyecto' => $f['nombre'],
                    'mensaje' => $matExc . ' material(es) superan la cantidad asignada.',
                ];
            }
        }

        foreach ($this->consumosAnomalos() as $maquina) {
            $alertas[] = [
                'tipo' => 'maquinaria',
                'gravedad' => 'media',
                'proyecto' => $maquina['obra'],
                'maquina' => $maquina['nombre'],
                'mensaje' => self::mensajeDeConsumo($maquina['registros'], $maquina['ultimo']),
            ];
        }

        $this->json(200, ['proyectos' => $proyectos, 'alertas' => $alertas]);
    }

    /**
     * RF24 (D-03): las maquinas con registros de uso de consumo anomalo, una
     * sola vez cada una: con cuantos son y el ultimo, que es el que conviene
     * mirar primero. La obra es la de ese ultimo registro, o null si no tenia.
     *
     * Se calcula en PHP con la misma regla que MaquinariaController, para que
     * el feed y el listado de la maquina no puedan discrepar.
     *
     * @return list<array{nombre: string, registros: int, ultimo: string, obra: ?string}>
     */
    private function consumosAnomalos(): array
    {
        $stmt = $this->db->query(
            'SELECT r.id_maquinaria, m.nombre, r.fecha, r.horas_uso, r.combustible_consumido, p.nombre AS obra
             FROM registro_maquinaria r
             JOIN maquinaria m ON m.id_maquinaria = r.id_maquinaria
             LEFT JOIN proyecto p ON p.id_proyecto = r.id_proyecto
             WHERE r.horas_uso > 0
             ORDER BY m.nombre, r.id_maquinaria, r.fecha DESC, r.id_registro DESC'
        );
        $porMaquina = [];
        foreach ($stmt->fetchAll() as $r) {
            $porMaquina[(int) $r['id_maquinaria']][] = $r;
        }

        $out = [];
        foreach ($porMaquina as $registros) {
            $promedio = ConsumoMaquinaria::porHora(
                array_sum(array_map(fn (array $r): float => (float) $r['combustible_consumido'], $registros)),
                array_sum(array_map(fn (array $r): float => (float) $r['horas_uso'], $registros))
            );
            $anomalos = array_values(array_filter(
                $registros,
                fn (array $r): bool => ConsumoMaquinaria::esAnomalo(
                    ConsumoMaquinaria::porHora((float) $r['combustible_consumido'], (float) $r['horas_uso']),
                    $promedio
                )
            ));
            if ($anomalos === []) {
                continue;
            }
            $out[] = [
                'nombre' => (string) $anomalos[0]['nombre'],
                'registros' => count($anomalos),
                'ultimo' => (string) $anomalos[0]['fecha'],
                'obra' => $anomalos[0]['obra'] !== null ? (string) $anomalos[0]['obra'] : null,
            ];
        }

        return $out;
    }

    private static function mensajeDeConsumo(int $registros, string $ultimo): string
    {
        $fecha = (new DateTimeImmutable($ultimo))->format('d/m/Y');
        if ($registros === 1) {
            return "Un registro de uso, del {$fecha}, consumió más de 1,5 veces el combustible por hora promedio de la máquina.";
        }

        return "{$registros} registros de uso consumieron más de 1,5 veces el combustible por hora promedio de la máquina; el último, del {$fecha}.";
    }

    /**
     * Cuenta, por proyecto, cuantos materiales tienen consumo mayor a lo
     * asignado (RF12).
     * @return array<int,int>  id_proyecto => cantidad de materiales excedidos
     */
    private function materialesExcedidosPorProyecto(): array
    {
        $stmt = $this->db->query(
            'SELECT id_proyecto, COUNT(*) AS excedidos FROM (
                SELECT am.id_proyecto AS id_proyecto, am.id_asignacion,
                       am.cantidad_asignada,
                       COALESCE(SUM(cm.cantidad_consumida), 0) AS consumido
                FROM asignacion_material am
                LEFT JOIN consumo_material cm ON cm.id_asignacion = am.id_asignacion
                GROUP BY am.id_asignacion, am.id_proyecto, am.cantidad_asignada
            ) t
            WHERE t.consumido > t.cantidad_asignada
            GROUP BY id_proyecto'
        );
        $mapa = [];
        foreach ($stmt->fetchAll() as $f) {
            $mapa[(int) $f['id_proyecto']] = (int) $f['excedidos'];
        }
        return $mapa;
    }

    private function json(int $codigo, mixed $cuerpo): void
    {
        http_response_code($codigo);
        echo json_encode($cuerpo, JSON_UNESCAPED_UNICODE);
    }
}
