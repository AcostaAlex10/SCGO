<?php

declare(strict_types=1);

namespace Sgso;

use PDO;
use Sgso\Http\Paginacion;

/**
 * Controlador de Asistencia del personal en obra (RF06). Cada registro es
 * la asistencia de un trabajador, en una obra (proyecto) y una fecha dadas.
 * El campo `justificacion` permite documentar el motivo de una inasistencia
 * o llegada tarde (RF08).
 */
final class AsistenciaController
{
    private const ESTADOS = ['presente', 'ausente', 'tarde'];

    public function __construct(private PDO $db)
    {
    }

    /** GET /api/proyectos/{idProyecto}/asistencias */
    public function listarPorProyecto(string $idProyecto, ?Paginacion $pagina = null): void
    {
        $sql = 'SELECT * FROM asistencia WHERE id_proyecto = ? ORDER BY fecha DESC, id_asistencia DESC';
        if ($pagina !== null) {
            $sql .= $pagina->aplicar($this->db, 'SELECT COUNT(*) FROM asistencia WHERE id_proyecto = ?', [$idProyecto]);
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$idProyecto]);
        $this->json(200, array_map([self::class, 'normalizar'], $stmt->fetchAll()));
    }

    /** POST /api/proyectos/{idProyecto}/asistencias */
    public function crear(string $idProyecto, array $datos): void
    {
        // La obra debe existir.
        $stmt = $this->db->prepare('SELECT id_proyecto FROM proyecto WHERE id_proyecto = ?');
        $stmt->execute([$idProyecto]);
        if ($stmt->fetch() === false) {
            $this->json(404, ['error' => 'Obra no encontrada']);
            return;
        }

        $errores = $this->validar($datos);
        if (!empty($errores)) {
            $this->json(422, ['errors' => $errores]);
            return;
        }

        $justificacion = isset($datos['justificacion']) && trim((string) $datos['justificacion']) !== ''
            ? trim((string) $datos['justificacion'])
            : null;

        $stmt = $this->db->prepare(
            'INSERT INTO asistencia (id_proyecto, fecha, trabajador, estado, justificacion)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $idProyecto,
            $datos['fecha'],
            trim((string) $datos['trabajador']),
            $datos['estado'],
            $justificacion,
        ]);

        $this->json(201, [
            'id_asistencia' => (int) $this->db->lastInsertId(),
            'id_proyecto' => (int) $idProyecto,
            'fecha' => $datos['fecha'],
            'trabajador' => trim((string) $datos['trabajador']),
            'estado' => $datos['estado'],
            'justificacion' => $justificacion,
        ]);
    }

    /** DELETE /api/proyectos/asistencia/{id} */
    public function eliminar(string $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM asistencia WHERE id_asistencia = ?');
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 0) {
            $this->json(404, ['error' => 'Registro de asistencia no encontrado']);
            return;
        }
        $this->json(200, ['mensaje' => 'Asistencia eliminada']);
    }

    /** @param array<string,mixed> $datos @return array<string,string> */
    private function validar(array $datos): array
    {
        $errores = [];
        if (!isset($datos['trabajador']) || trim((string) $datos['trabajador']) === '') {
            $errores['trabajador'] = 'Obligatorio';
        }
        if (!isset($datos['fecha']) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $datos['fecha'])) {
            $errores['fecha'] = 'Formato esperado: YYYY-MM-DD';
        }
        if (!isset($datos['estado']) || !in_array($datos['estado'], self::ESTADOS, true)) {
            $errores['estado'] = 'Estado invalido (presente, ausente o tarde)';
        }
        return $errores;
    }

    /** @param array<string,mixed> $fila @return array<string,mixed> */
    private static function normalizar(array $fila): array
    {
        $fila['id_asistencia'] = (int) $fila['id_asistencia'];
        $fila['id_proyecto'] = (int) $fila['id_proyecto'];
        return $fila;
    }

    private function json(int $codigo, mixed $cuerpo): void
    {
        http_response_code($codigo);
        echo json_encode($cuerpo, JSON_UNESCAPED_UNICODE);
    }
}
