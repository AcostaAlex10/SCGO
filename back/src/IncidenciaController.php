<?php

declare(strict_types=1);

namespace Sgso;

use PDO;
use Sgso\Http\Paginacion;

/**
 * Controlador de Incidencias externas de obra (RF09): clima, fallas de
 * maquinaria, retrasos de proveedores, etc. La `gravedad` clasifica la
 * incidencia (RF26) y `dias_retraso` documenta su impacto en el cronograma,
 * lo que sirve para justificar extensiones de plazo (RF08).
 */
final class IncidenciaController
{
    private const TIPOS = ['clima', 'falla_maquinaria', 'proveedor', 'otro'];
    private const GRAVEDADES = ['baja', 'media', 'alta'];

    public function __construct(private PDO $db)
    {
    }

    /** GET /api/proyectos/{idProyecto}/incidencias */
    public function listarPorProyecto(string $idProyecto, ?Paginacion $pagina = null): void
    {
        $sql = 'SELECT * FROM incidencia WHERE id_proyecto = ? ORDER BY fecha DESC, id_incidencia DESC';
        if ($pagina !== null) {
            $sql .= $pagina->aplicar($this->db, 'SELECT COUNT(*) FROM incidencia WHERE id_proyecto = ?', [$idProyecto]);
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$idProyecto]);
        $this->json(200, array_map([self::class, 'normalizar'], $stmt->fetchAll()));
    }

    /** POST /api/proyectos/{idProyecto}/incidencias */
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

        $diasRetraso = isset($datos['dias_retraso']) && is_numeric($datos['dias_retraso'])
            ? max(0, (int) $datos['dias_retraso'])
            : 0;

        $stmt = $this->db->prepare(
            'INSERT INTO incidencia (id_proyecto, fecha, tipo, gravedad, descripcion, dias_retraso)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $idProyecto,
            $datos['fecha'],
            $datos['tipo'],
            $datos['gravedad'],
            trim((string) $datos['descripcion']),
            $diasRetraso,
        ]);

        $this->json(201, [
            'id_incidencia' => (int) $this->db->lastInsertId(),
            'id_proyecto' => (int) $idProyecto,
            'fecha' => $datos['fecha'],
            'tipo' => $datos['tipo'],
            'gravedad' => $datos['gravedad'],
            'descripcion' => trim((string) $datos['descripcion']),
            'dias_retraso' => $diasRetraso,
        ]);
    }

    /** DELETE /api/proyectos/incidencia/{id} */
    public function eliminar(string $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM incidencia WHERE id_incidencia = ?');
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 0) {
            $this->json(404, ['error' => 'Incidencia no encontrada']);
            return;
        }
        $this->json(200, ['mensaje' => 'Incidencia eliminada']);
    }

    /** @param array<string,mixed> $datos @return array<string,string> */
    private function validar(array $datos): array
    {
        $errores = [];
        if (!isset($datos['fecha']) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $datos['fecha'])) {
            $errores['fecha'] = 'Formato esperado: YYYY-MM-DD';
        }
        if (!isset($datos['tipo']) || !in_array($datos['tipo'], self::TIPOS, true)) {
            $errores['tipo'] = 'Tipo invalido';
        }
        if (!isset($datos['gravedad']) || !in_array($datos['gravedad'], self::GRAVEDADES, true)) {
            $errores['gravedad'] = 'Gravedad invalida';
        }
        if (!isset($datos['descripcion']) || trim((string) $datos['descripcion']) === '') {
            $errores['descripcion'] = 'Obligatorio';
        }
        return $errores;
    }

    /** @param array<string,mixed> $fila @return array<string,mixed> */
    private static function normalizar(array $fila): array
    {
        $fila['id_incidencia'] = (int) $fila['id_incidencia'];
        $fila['id_proyecto'] = (int) $fila['id_proyecto'];
        $fila['dias_retraso'] = (int) $fila['dias_retraso'];
        return $fila;
    }

    private function json(int $codigo, mixed $cuerpo): void
    {
        http_response_code($codigo);
        echo json_encode($cuerpo, JSON_UNESCAPED_UNICODE);
    }
}
