<?php

declare(strict_types=1);

namespace Sgso;

use PDO;
use Sgso\Http\Paginacion;
use Sgso\Reglas\StockMaterial;

/**
 * Materiales asignados a una obra y sus consumos (RF10). Calcula el total
 * consumido vs. lo asignado para detectar excesos (RF12), y rechaza un consumo
 * que supere lo que queda (CU22).
 */
final class MaterialObraController
{
    public function __construct(private PDO $db)
    {
    }

    /**
     * GET /api/proyectos/{idProyecto}/materiales
     * Lista los materiales asignados con el total consumido y si esta excedido.
     */
    public function listarPorProyecto(string $idProyecto): void
    {
        $stmt = $this->db->prepare(
            'SELECT am.id_asignacion, am.id_material, m.nombre, m.unidad,
                    am.cantidad_asignada,
                    COALESCE(SUM(cm.cantidad_consumida), 0) AS consumido
             FROM asignacion_material am
             JOIN material m ON m.id_material = am.id_material
             LEFT JOIN consumo_material cm ON cm.id_asignacion = am.id_asignacion
             WHERE am.id_proyecto = ?
             GROUP BY am.id_asignacion, am.id_material, m.nombre, m.unidad, am.cantidad_asignada
             ORDER BY m.nombre'
        );
        $stmt->execute([$idProyecto]);

        $filas = array_map(function (array $f): array {
            $asignada = (float) $f['cantidad_asignada'];
            $consumido = (float) $f['consumido'];
            return [
                'id_asignacion' => (int) $f['id_asignacion'],
                'id_material' => (int) $f['id_material'],
                'nombre' => $f['nombre'],
                'unidad' => $f['unidad'],
                'cantidad_asignada' => $asignada,
                'consumido' => $consumido,
                'restante' => round($asignada - $consumido, 2),
                'excedido' => $consumido > $asignada, // RF12
            ];
        }, $stmt->fetchAll());

        $this->json(200, $filas);
    }

    /** POST /api/proyectos/{idProyecto}/materiales  -> asignar material a la obra */
    public function asignar(string $idProyecto, array $datos): void
    {
        $stmt = $this->db->prepare('SELECT id_proyecto FROM proyecto WHERE id_proyecto = ?');
        $stmt->execute([$idProyecto]);
        if ($stmt->fetch() === false) { $this->json(404, ['error' => 'Obra no encontrada']); return; }

        $idMaterial = $datos['id_material'] ?? null;
        $cantidad = $datos['cantidad_asignada'] ?? null;

        $errores = [];
        if (!is_numeric($idMaterial)) { $errores['id_material'] = 'Seleccioná un material'; }
        if (!is_numeric($cantidad) || (float) $cantidad <= 0) { $errores['cantidad_asignada'] = 'Cantidad inválida'; }
        if (!empty($errores)) { $this->json(422, ['errors' => $errores]); return; }

        $stmt = $this->db->prepare('SELECT id_material FROM material WHERE id_material = ?');
        $stmt->execute([$idMaterial]);
        if ($stmt->fetch() === false) { $this->json(404, ['error' => 'Material inexistente']); return; }

        // Un material se asigna una sola vez por obra (uq_asig_proyecto_material).
        $stmt = $this->db->prepare('SELECT id_asignacion FROM asignacion_material WHERE id_proyecto = ? AND id_material = ?');
        $stmt->execute([$idProyecto, $idMaterial]);
        if ($stmt->fetch() !== false) {
            $this->json(409, ['error' => 'Ese material ya está asignado a la obra']);
            return;
        }

        $stmt = $this->db->prepare('INSERT INTO asignacion_material (id_proyecto, id_material, cantidad_asignada) VALUES (?, ?, ?)');
        $stmt->execute([$idProyecto, $idMaterial, (float) $cantidad]);

        $this->json(201, ['id_asignacion' => (int) $this->db->lastInsertId()]);
    }

    /** DELETE /api/proyectos/material/{idAsignacion} */
    public function eliminarAsignacion(string $idAsignacion): void
    {
        $stmt = $this->db->prepare('DELETE FROM asignacion_material WHERE id_asignacion = ?');
        $stmt->execute([$idAsignacion]);
        if ($stmt->rowCount() === 0) { $this->json(404, ['error' => 'Asignación no encontrada']); return; }
        $this->json(200, ['mensaje' => 'Asignación eliminada']);
    }

    /** GET /api/proyectos/material/{idAsignacion}/consumos */
    public function listarConsumos(string $idAsignacion, ?Paginacion $pagina = null): void
    {
        $sql = 'SELECT * FROM consumo_material WHERE id_asignacion = ? ORDER BY fecha DESC, id_consumo DESC';
        if ($pagina !== null) {
            $sql .= $pagina->aplicar($this->db, 'SELECT COUNT(*) FROM consumo_material WHERE id_asignacion = ?', [$idAsignacion]);
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$idAsignacion]);
        $this->json(200, array_map([self::class, 'normalizarConsumo'], $stmt->fetchAll()));
    }

    /** POST /api/proyectos/material/{idAsignacion}/consumos */
    public function crearConsumo(string $idAsignacion, array $datos): void
    {
        $stmt = $this->db->prepare('SELECT id_asignacion FROM asignacion_material WHERE id_asignacion = ?');
        $stmt->execute([$idAsignacion]);
        if ($stmt->fetch() === false) { $this->json(404, ['error' => 'Asignación no encontrada']); return; }

        $cantidad = $datos['cantidad_consumida'] ?? null;
        $fecha = (string) ($datos['fecha'] ?? '');

        $errores = [];
        if (!is_numeric($cantidad) || (float) $cantidad <= 0) { $errores['cantidad_consumida'] = 'Cantidad inválida'; }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) { $errores['fecha'] = 'Formato esperado: YYYY-MM-DD'; }
        if (!empty($errores)) { $this->json(422, ['errors' => $errores]); return; }

        $obs = isset($datos['observaciones']) && trim((string) $datos['observaciones']) !== ''
            ? trim((string) $datos['observaciones']) : null;

        // Un consumo no puede superar lo que queda asignado (CU22). La fila de
        // la asignacion queda bloqueada hasta el INSERT: sin eso, dos consumos
        // simultaneos leen el mismo saldo y entran los dos.
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'SELECT am.cantidad_asignada, m.unidad,
                        (SELECT COALESCE(SUM(cm.cantidad_consumida), 0)
                           FROM consumo_material cm WHERE cm.id_asignacion = am.id_asignacion) AS consumido
                 FROM asignacion_material am
                 JOIN material m ON m.id_material = am.id_material
                 WHERE am.id_asignacion = ?
                 FOR UPDATE'
            );
            $stmt->execute([$idAsignacion]);
            $saldo = $stmt->fetch();
            if ($saldo === false) {
                // La asignacion se borro entre la verificacion de arriba y el bloqueo.
                $this->db->rollBack();
                $this->json(404, ['error' => 'Asignación no encontrada']);
                return;
            }

            $asignada = (float) $saldo['cantidad_asignada'];
            $consumido = (float) $saldo['consumido'];
            if (!StockMaterial::alcanza($asignada, $consumido, (float) $cantidad)) {
                $this->db->rollBack();
                $this->json(422, ['errors' => ['cantidad_consumida' => sprintf(
                    'Stock insuficiente: quedan %s %s',
                    self::cantidadLegible(StockMaterial::restante($asignada, $consumido)),
                    $saldo['unidad']
                )]]);
                return;
            }

            $stmt = $this->db->prepare('INSERT INTO consumo_material (id_asignacion, fecha, cantidad_consumida, observaciones) VALUES (?, ?, ?, ?)');
            $stmt->execute([$idAsignacion, $fecha, (float) $cantidad, $obs]);
            $idConsumo = (int) $this->db->lastInsertId();
            $this->db->commit();
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }

        $this->json(201, ['id_consumo' => $idConsumo]);
    }

    /**
     * POST /api/proyectos/{idProyecto}/consumos
     *
     * La lista de consumos del dia que el encargado carga y envia junta (CU22,
     * D-16): {"fecha": "YYYY-MM-DD", "consumos": [{"id_asignacion", "cantidad_consumida"}]}.
     * Entra entera o no entra: si un material no alcanza, no se guarda ninguno.
     *
     * @param array<string, mixed> $datos
     */
    public function crearListaConsumos(string $idProyecto, array $datos): void
    {
        $stmt = $this->db->prepare('SELECT id_proyecto FROM proyecto WHERE id_proyecto = ?');
        $stmt->execute([$idProyecto]);
        if ($stmt->fetch() === false) { $this->json(404, ['error' => 'Obra no encontrada']); return; }

        $fecha = (string) ($datos['fecha'] ?? '');
        $lista = $datos['consumos'] ?? null;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            $this->json(422, ['errors' => ['fecha' => 'Formato esperado: YYYY-MM-DD']]);
            return;
        }
        if (!is_array($lista) || $lista === []) {
            $this->json(422, ['errors' => ['consumos' => 'Cargá al menos un consumo']]);
            return;
        }

        // Cantidad pedida por asignacion: dos renglones del mismo material suman.
        $pedido = [];
        foreach ($lista as $renglon) {
            $idAsignacion = is_array($renglon) ? ($renglon['id_asignacion'] ?? null) : null;
            $cantidad = is_array($renglon) ? ($renglon['cantidad_consumida'] ?? null) : null;
            if (!is_numeric($idAsignacion) || !is_numeric($cantidad) || (float) $cantidad <= 0) {
                $this->json(422, ['errors' => ['consumos' => 'Cada consumo necesita un material y una cantidad mayor a cero']]);
                return;
            }
            $pedido[(int) $idAsignacion][] = (float) $cantidad;
        }

        $this->db->beginTransaction();
        try {
            // Las asignaciones quedan bloqueadas hasta el INSERT, como en el
            // consumo suelto. Solo cuentan las de esta obra.
            $marcas = implode(', ', array_fill(0, count($pedido), '?'));
            $stmt = $this->db->prepare(
                "SELECT am.id_asignacion, am.cantidad_asignada, m.nombre, m.unidad,
                        (SELECT COALESCE(SUM(cm.cantidad_consumida), 0)
                           FROM consumo_material cm WHERE cm.id_asignacion = am.id_asignacion) AS consumido
                 FROM asignacion_material am
                 JOIN material m ON m.id_material = am.id_material
                 WHERE am.id_proyecto = ? AND am.id_asignacion IN ($marcas)
                 FOR UPDATE"
            );
            $stmt->execute([$idProyecto, ...array_keys($pedido)]);
            $saldos = [];
            foreach ($stmt->fetchAll() as $fila) {
                $saldos[(int) $fila['id_asignacion']] = $fila;
            }

            $errores = [];
            foreach ($pedido as $idAsignacion => $cantidades) {
                $saldo = $saldos[$idAsignacion] ?? null;
                if ($saldo === null) {
                    $errores["consumos.{$idAsignacion}"] = 'Ese material no está asignado a la obra';
                    continue;
                }
                $asignada = (float) $saldo['cantidad_asignada'];
                $consumido = (float) $saldo['consumido'];
                if (!StockMaterial::alcanza($asignada, $consumido, array_sum($cantidades))) {
                    $errores["consumos.{$idAsignacion}"] = sprintf(
                        '%s: Stock insuficiente: quedan %s %s',
                        $saldo['nombre'],
                        self::cantidadLegible(StockMaterial::restante($asignada, $consumido)),
                        $saldo['unidad']
                    );
                }
            }
            if ($errores !== []) {
                $this->db->rollBack();
                $this->json(422, ['errors' => $errores]);
                return;
            }

            $insertar = $this->db->prepare('INSERT INTO consumo_material (id_asignacion, fecha, cantidad_consumida) VALUES (?, ?, ?)');
            $registrados = 0;
            foreach ($pedido as $idAsignacion => $cantidades) {
                foreach ($cantidades as $cantidad) {
                    $insertar->execute([$idAsignacion, $fecha, $cantidad]);
                    $registrados++;
                }
            }
            $this->db->commit();
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }

        $this->json(201, ['registrados' => $registrados]);
    }

    /** 6 -> "6", 2.5 -> "2,5": sin ceros de mas en el mensaje. */
    private static function cantidadLegible(float $cantidad): string
    {
        return rtrim(rtrim(number_format($cantidad, 2, ',', ''), '0'), ',');
    }

    /** DELETE /api/proyectos/consumo/{idConsumo} */
    public function eliminarConsumo(string $idConsumo): void
    {
        $stmt = $this->db->prepare('DELETE FROM consumo_material WHERE id_consumo = ?');
        $stmt->execute([$idConsumo]);
        if ($stmt->rowCount() === 0) { $this->json(404, ['error' => 'Consumo no encontrado']); return; }
        $this->json(200, ['mensaje' => 'Consumo eliminado']);
    }

    /** @param array<string,mixed> $f @return array<string,mixed> */
    private static function normalizarConsumo(array $f): array
    {
        $f['id_consumo'] = (int) $f['id_consumo'];
        $f['id_asignacion'] = (int) $f['id_asignacion'];
        $f['cantidad_consumida'] = (float) $f['cantidad_consumida'];
        return $f;
    }

    private function json(int $codigo, mixed $cuerpo): void
    {
        http_response_code($codigo);
        echo json_encode($cuerpo, JSON_UNESCAPED_UNICODE);
    }
}
