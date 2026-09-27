<?php

declare(strict_types=1);

namespace Sgso;

use PDO;
use Sgso\Http\Paginacion;

/**
 * Materiales asignados a una obra y sus consumos (RF10). Calcula el total
 * consumido vs. lo asignado para detectar excesos (RF12).
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

        $stmt = $this->db->prepare('INSERT INTO consumo_material (id_asignacion, fecha, cantidad_consumida, observaciones) VALUES (?, ?, ?, ?)');
        $stmt->execute([$idAsignacion, $fecha, (float) $cantidad, $obs]);

        $this->json(201, ['id_consumo' => (int) $this->db->lastInsertId()]);
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
