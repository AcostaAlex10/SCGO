<?php

declare(strict_types=1);

namespace Sgso;

use PDO;
use Sgso\Reglas\Fecha;

/**
 * Items o trabajos excedentes no contemplados en la planificacion inicial
 * (RF22). Permiten reflejar modificaciones surgidas durante la ejecucion.
 */
final class ItemExcedenteController
{
    public function __construct(private PDO $db)
    {
    }

    /** GET /api/proyectos/{idProyecto}/excedentes */
    public function listarPorProyecto(string $idProyecto): void
    {
        $stmt = $this->db->prepare('SELECT * FROM item_excedente WHERE id_proyecto = ? ORDER BY fecha DESC, id_item DESC');
        $stmt->execute([$idProyecto]);
        $this->json(200, array_map([self::class, 'normalizar'], $stmt->fetchAll()));
    }

    /** POST /api/proyectos/{idProyecto}/excedentes */
    public function crear(string $idProyecto, array $datos): void
    {
        $stmt = $this->db->prepare('SELECT id_proyecto FROM proyecto WHERE id_proyecto = ?');
        $stmt->execute([$idProyecto]);
        if ($stmt->fetch() === false) { $this->json(404, ['error' => 'Obra no encontrada']); return; }

        $descripcion = trim((string) ($datos['descripcion'] ?? ''));
        $fecha = (string) ($datos['fecha'] ?? '');

        $errores = [];
        // Ausente o vacía: hoy. Presente e inválida: error (antes se reemplazaba en silencio).
        if ($fecha === '') { $fecha = date('Y-m-d'); }
        elseif (!Fecha::esValida($fecha)) { $errores['fecha'] = 'Formato esperado: YYYY-MM-DD'; }
        if ($descripcion === '') { $errores['descripcion'] = 'Obligatorio'; }
        if (isset($datos['cantidad']) && $datos['cantidad'] !== '' && !is_numeric($datos['cantidad'])) {
            $errores['cantidad'] = 'Debe ser un número';
        }
        if (!empty($errores)) { $this->json(422, ['errors' => $errores]); return; }

        $cantidad = isset($datos['cantidad']) && is_numeric($datos['cantidad']) ? (float) $datos['cantidad'] : null;
        $unidad = trim((string) ($datos['unidad'] ?? '')) ?: null;
        $motivo = trim((string) ($datos['motivo'] ?? '')) ?: null;

        $stmt = $this->db->prepare('INSERT INTO item_excedente (id_proyecto, descripcion, cantidad, unidad, fecha, motivo) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$idProyecto, $descripcion, $cantidad, $unidad, $fecha, $motivo]);

        $this->json(201, [
            'id_item' => (int) $this->db->lastInsertId(),
            'id_proyecto' => (int) $idProyecto,
            'descripcion' => $descripcion,
            'cantidad' => $cantidad,
            'unidad' => $unidad,
            'fecha' => $fecha,
            'motivo' => $motivo,
        ]);
    }

    /** DELETE /api/proyectos/excedente/{id} */
    public function eliminar(string $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM item_excedente WHERE id_item = ?');
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 0) { $this->json(404, ['error' => 'Ítem no encontrado']); return; }
        $this->json(200, ['mensaje' => 'Ítem eliminado']);
    }

    /** @param array<string,mixed> $f @return array<string,mixed> */
    private static function normalizar(array $f): array
    {
        $f['id_item'] = (int) $f['id_item'];
        $f['id_proyecto'] = (int) $f['id_proyecto'];
        $f['cantidad'] = $f['cantidad'] !== null ? (float) $f['cantidad'] : null;
        return $f;
    }

    private function json(int $codigo, mixed $cuerpo): void
    {
        http_response_code($codigo);
        echo json_encode($cuerpo, JSON_UNESCAPED_UNICODE);
    }
}
