<?php

declare(strict_types=1);

namespace Sgso;

use DateTime;
use PDO;

/**
 * CRUD de etapas de planificacion (1-a-muchos con planificacion).
 * Cada etapa tiene un rango de fechas y un peso porcentual; la suma de
 * pesos de todas las etapas de una planificacion debe ser 100.
 *
 * El metodo estatico calcularEsperadoHoy() (y su variante en batch) es
 * compartido con AvanceController y AnalisisController para calcular el
 * avance esperado a la fecha segun el progreso temporal de las etapas.
 *
 * Endpoints:
 *   GET    /api/planificacion/{planId}/etapas
 *   POST   /api/planificacion/{planId}/etapas   (ROLES_GESTION_OBRA)
 *   PUT    /api/planificacion/etapa/{id}         (ROLES_GESTION_OBRA)
 *   DELETE /api/planificacion/etapa/{id}         (ROLES_GESTION_OBRA)
 */
final class EtapaPlanificacionController
{
    public function __construct(private PDO $db)
    {
    }

    /** GET /api/planificacion/{planId}/etapas */
    public function listar(string $planId): void
    {
        $stmt = $this->db->prepare('SELECT id_planificacion FROM planificacion WHERE id_planificacion = ?');
        $stmt->execute([$planId]);
        if ($stmt->fetch() === false) {
            $this->json(404, ['error' => 'Planificacion no encontrada']);
            return;
        }

        $stmt = $this->db->prepare(
            'SELECT * FROM etapa_planificacion WHERE id_planificacion = ? ORDER BY orden ASC, id_etapa ASC'
        );
        $stmt->execute([$planId]);
        $this->json(200, array_map([$this, 'normalizar'], $stmt->fetchAll()));
    }

    /** POST /api/planificacion/{planId}/etapas */
    public function crear(string $planId, array $datos): void
    {
        $stmt = $this->db->prepare('SELECT id_planificacion FROM planificacion WHERE id_planificacion = ?');
        $stmt->execute([$planId]);
        if ($stmt->fetch() === false) {
            $this->json(404, ['error' => 'Planificacion no encontrada']);
            return;
        }

        $errores = $this->validar($datos);
        if (!empty($errores)) {
            $this->json(422, ['errors' => $errores]);
            return;
        }

        $nuevoPeso = (float) $datos['peso_porcentual'];
        $sumaActual = $this->sumaPesos((int) $planId);
        if ($sumaActual + $nuevoPeso > 100.01) {
            $this->json(422, ['errors' => [
                'peso_porcentual' => sprintf(
                    'La suma de pesos superaría 100%%. Suma actual: %.2f%%. Peso disponible: %.2f%%.',
                    $sumaActual,
                    max(0, 100 - $sumaActual)
                ),
            ]]);
            return;
        }

        // Siguiente orden: max + 1 (o usar el que mande el cliente)
        if (isset($datos['orden'])) {
            $orden = (int) $datos['orden'];
        } else {
            $stmt = $this->db->prepare(
                'SELECT COALESCE(MAX(orden), 0) + 1 FROM etapa_planificacion WHERE id_planificacion = ?'
            );
            $stmt->execute([$planId]);
            $orden = (int) $stmt->fetchColumn();
        }

        $presupuestoBase = isset($datos['presupuesto_base']) ? (float) $datos['presupuesto_base'] : 0.0;

        $stmt = $this->db->prepare(
            'INSERT INTO etapa_planificacion (id_planificacion, nombre, peso_porcentual, fecha_inicio, fecha_fin, orden, presupuesto_base)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$planId, trim($datos['nombre']), $nuevoPeso, $datos['fecha_inicio'], $datos['fecha_fin'], $orden, $presupuestoBase]);

        $this->json(201, [
            'id_etapa'         => (int) $this->db->lastInsertId(),
            'id_planificacion' => (int) $planId,
            'nombre'           => trim($datos['nombre']),
            'peso_porcentual'  => $nuevoPeso,
            'fecha_inicio'     => $datos['fecha_inicio'],
            'fecha_fin'        => $datos['fecha_fin'],
            'orden'            => $orden,
            'presupuesto_base' => $presupuestoBase,
            'suma_pesos'       => round($sumaActual + $nuevoPeso, 2),
        ]);
    }

    /** PUT /api/planificacion/etapa/{id} */
    public function actualizar(string $id, array $datos): void
    {
        $stmt = $this->db->prepare('SELECT * FROM etapa_planificacion WHERE id_etapa = ?');
        $stmt->execute([$id]);
        $actual = $stmt->fetch();
        if ($actual === false) {
            $this->json(404, ['error' => 'Etapa no encontrada']);
            return;
        }

        // La fecha que no viene se completa con la guardada: sin eso, la
        // comparacion de inicio y fin no corria con una sola, y un PUT con solo
        // fecha_fin anterior al inicio dejaba una etapa que termina antes de empezar.
        $errores = $this->validar($datos + [
            'fecha_inicio' => (string) $actual['fecha_inicio'],
            'fecha_fin' => (string) $actual['fecha_fin'],
        ], true);
        if (!empty($errores)) {
            $this->json(422, ['errors' => $errores]);
            return;
        }

        if (isset($datos['peso_porcentual'])) {
            $nuevoPeso = (float) $datos['peso_porcentual'];
            $sumaOtras = $this->sumaPesos((int) $actual['id_planificacion'], (int) $id);
            if ($sumaOtras + $nuevoPeso > 100.01) {
                $this->json(422, ['errors' => [
                    'peso_porcentual' => sprintf(
                        'La suma de pesos superaría 100%%. Suma de las otras etapas: %.2f%%. Peso disponible: %.2f%%.',
                        $sumaOtras,
                        max(0, 100 - $sumaOtras)
                    ),
                ]]);
                return;
            }
        }

        $stmt = $this->db->prepare(
            'UPDATE etapa_planificacion
             SET nombre = ?, peso_porcentual = ?, fecha_inicio = ?, fecha_fin = ?, orden = ?, presupuesto_base = ?
             WHERE id_etapa = ?'
        );
        $stmt->execute([
            isset($datos['nombre']) ? trim($datos['nombre']) : $actual['nombre'],
            isset($datos['peso_porcentual']) ? (float) $datos['peso_porcentual'] : (float) $actual['peso_porcentual'],
            $datos['fecha_inicio']    ?? $actual['fecha_inicio'],
            $datos['fecha_fin']       ?? $actual['fecha_fin'],
            isset($datos['orden'])    ? (int)   $datos['orden']            : (int)   $actual['orden'],
            isset($datos['presupuesto_base']) ? (float) $datos['presupuesto_base'] : (float) $actual['presupuesto_base'],
            $id,
        ]);
        $this->json(200, ['mensaje' => 'Etapa actualizada']);
    }

    /** DELETE /api/planificacion/etapa/{id} */
    public function eliminar(string $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM etapa_planificacion WHERE id_etapa = ?');
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 0) {
            $this->json(404, ['error' => 'Etapa no encontrada']);
            return;
        }
        $this->json(200, ['mensaje' => 'Etapa eliminada']);
    }

    // ---------------------------------------------------------------
    // Metodos estaticos compartidos con AvanceController y AnalisisController
    // ---------------------------------------------------------------

    /**
     * Calcula el avance esperado HOY para una planificacion a partir de sus
     * etapas. Si no hay etapas, devuelve $fallback (o lo lee de la BD).
     *
     * Logica por etapa:
     *   fraccion = clamp( (hoy - inicio) / (fin - inicio), 0, 1 )
     *   aporte = fraccion * peso_porcentual
     * esperado = sum(aportes)
     */
    public static function calcularEsperadoHoy(PDO $db, int $planId, ?float $fallback = null): float
    {
        $stmt = $db->prepare(
            'SELECT * FROM etapa_planificacion WHERE id_planificacion = ? ORDER BY orden ASC, id_etapa ASC'
        );
        $stmt->execute([$planId]);
        $etapas = $stmt->fetchAll();

        if (empty($etapas)) {
            if ($fallback !== null) {
                return $fallback;
            }
            $stmt = $db->prepare('SELECT avance_esperado_total FROM planificacion WHERE id_planificacion = ?');
            $stmt->execute([$planId]);
            $plan = $stmt->fetch();
            return $plan ? (float) $plan['avance_esperado_total'] : 0.0;
        }

        return round(self::sumarAportes($etapas), 2);
    }

    /**
     * Version en batch: calcula el esperado hoy para multiples planificaciones
     * en una sola query a etapa_planificacion.
     *
     * @param  array<int>        $planIds   ids de planificacion a calcular
     * @param  array<int,float>  $fallbacks id_planificacion => avance_esperado_total (fallback si sin etapas)
     * @return array<int,float>             id_planificacion => esperado_hoy
     */
    public static function calcularEsperadoBatch(PDO $db, array $planIds, array $fallbacks = []): array
    {
        if (empty($planIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($planIds), '?'));
        $stmt = $db->prepare(
            "SELECT * FROM etapa_planificacion
             WHERE id_planificacion IN ($placeholders)
             ORDER BY id_planificacion ASC, orden ASC, id_etapa ASC"
        );
        $stmt->execute(array_values($planIds));

        // Agrupar etapas por planificacion
        $porPlan = [];
        foreach ($stmt->fetchAll() as $e) {
            $porPlan[(int) $e['id_planificacion']][] = $e;
        }

        $resultado = [];
        foreach ($planIds as $planId) {
            $etapas = $porPlan[$planId] ?? [];
            if (empty($etapas)) {
                $resultado[$planId] = $fallbacks[$planId] ?? 0.0;
            } else {
                $resultado[$planId] = round(self::sumarAportes($etapas), 2);
            }
        }
        return $resultado;
    }

    // ---------------------------------------------------------------
    // Privados
    // ---------------------------------------------------------------

    /**
     * Suma los aportes de las etapas al avance esperado hoy.
     * @param array<array<string,mixed>> $etapas
     */
    private static function sumarAportes(array $etapas): float
    {
        $hoyTs = (new DateTime('today'))->getTimestamp();
        $total = 0.0;
        foreach ($etapas as $e) {
            $inicioTs = (new DateTime($e['fecha_inicio']))->getTimestamp();
            $finTs    = (new DateTime($e['fecha_fin']))->getTimestamp();
            $duracion = $finTs - $inicioTs;
            if ($duracion <= 0) {
                $fraccion = ($hoyTs >= $finTs) ? 1.0 : 0.0;
            } else {
                $fraccion = max(0.0, min(1.0, ($hoyTs - $inicioTs) / $duracion));
            }
            $total += $fraccion * (float) $e['peso_porcentual'];
        }
        return $total;
    }

    /** Suma de pesos de las etapas de un plan, excluyendo opcionalmente una. */
    private function sumaPesos(int $planId, ?int $excluirId = null): float
    {
        if ($excluirId !== null) {
            $stmt = $this->db->prepare(
                'SELECT COALESCE(SUM(peso_porcentual), 0) FROM etapa_planificacion WHERE id_planificacion = ? AND id_etapa != ?'
            );
            $stmt->execute([$planId, $excluirId]);
        } else {
            $stmt = $this->db->prepare(
                'SELECT COALESCE(SUM(peso_porcentual), 0) FROM etapa_planificacion WHERE id_planificacion = ?'
            );
            $stmt->execute([$planId]);
        }
        return (float) $stmt->fetchColumn();
    }

    /** @return array<string,string> */
    private function validar(array $datos, bool $parcial = false): array
    {
        $errores = [];

        if (!$parcial && !isset($datos['nombre'])) {
            $errores['nombre'] = 'Obligatorio';
        } elseif (isset($datos['nombre']) && trim((string) $datos['nombre']) === '') {
            $errores['nombre'] = 'No puede estar vacío';
        }

        if (!$parcial && !isset($datos['peso_porcentual'])) {
            $errores['peso_porcentual'] = 'Obligatorio';
        } elseif (isset($datos['peso_porcentual'])) {
            $v = $datos['peso_porcentual'];
            if (!is_numeric($v) || (float) $v < 0 || (float) $v > 100) {
                $errores['peso_porcentual'] = 'Debe ser un número entre 0 y 100';
            }
        }

        foreach (['fecha_inicio', 'fecha_fin'] as $campo) {
            if (!$parcial && !isset($datos[$campo])) {
                $errores[$campo] = 'Obligatorio';
            } elseif (isset($datos[$campo]) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $datos[$campo])) {
                $errores[$campo] = 'Formato esperado: YYYY-MM-DD';
            }
        }

        if (isset($datos['presupuesto_base'])) {
            $pb = $datos['presupuesto_base'];
            if (!is_numeric($pb) || (float) $pb < 0) {
                $errores['presupuesto_base'] = 'Debe ser un número mayor o igual a 0';
            }
        }

        // fecha_fin >= fecha_inicio
        if (
            isset($datos['fecha_inicio'], $datos['fecha_fin'])
            && empty($errores['fecha_inicio'])
            && empty($errores['fecha_fin'])
            && $datos['fecha_fin'] < $datos['fecha_inicio']
        ) {
            $errores['fecha_fin'] = 'La fecha de fin debe ser igual o posterior a la de inicio';
        }

        return $errores;
    }

    /** @param array<string,mixed> $fila @return array<string,mixed> */
    private function normalizar(array $fila): array
    {
        $fila['id_etapa']          = (int)   $fila['id_etapa'];
        $fila['id_planificacion']  = (int)   $fila['id_planificacion'];
        $fila['peso_porcentual']   = (float) $fila['peso_porcentual'];
        $fila['orden']             = (int)   $fila['orden'];
        $fila['presupuesto_base']  = (float) ($fila['presupuesto_base'] ?? 0);
        return $fila;
    }

    private function json(int $codigo, mixed $cuerpo): void
    {
        http_response_code($codigo);
        echo json_encode($cuerpo, JSON_UNESCAPED_UNICODE);
    }
}
