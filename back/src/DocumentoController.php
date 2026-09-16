<?php

declare(strict_types=1);

namespace Sgso;

use PDO;
use Sgso\Reglas\Enlaces;

/**
 * Documentacion de la obra (RF16). Se guarda el dato del documento y un
 * enlace (Drive/URL), no el binario. Decision de diseno acordada por el
 * tamano/infra del hosting gratuito.
 */
final class DocumentoController
{
    private const TIPOS = ['pdf', 'imagen', 'otro'];

    public function __construct(private PDO $db)
    {
    }

    /** GET /api/proyectos/{idProyecto}/documentos */
    public function listarPorProyecto(string $idProyecto): void
    {
        $stmt = $this->db->prepare('SELECT * FROM documento WHERE id_proyecto = ? ORDER BY fecha_carga DESC, id_documento DESC');
        $stmt->execute([$idProyecto]);
        $this->json(200, array_map([self::class, 'normalizar'], $stmt->fetchAll()));
    }

    /** POST /api/proyectos/{idProyecto}/documentos */
    public function crear(string $idProyecto, array $datos): void
    {
        $stmt = $this->db->prepare('SELECT id_proyecto FROM proyecto WHERE id_proyecto = ?');
        $stmt->execute([$idProyecto]);
        if ($stmt->fetch() === false) { $this->json(404, ['error' => 'Obra no encontrada']); return; }

        $nombre = trim((string) ($datos['nombre'] ?? ''));
        $url = trim((string) ($datos['url'] ?? ''));
        $tipo = (string) ($datos['tipo'] ?? 'otro');
        $categoria = trim((string) ($datos['categoria'] ?? '')) ?: 'General';
        $fecha = (string) ($datos['fecha_carga'] ?? '');

        $errores = [];
        if ($nombre === '') { $errores['nombre'] = 'Obligatorio'; }
        // Solo http o https: un enlace javascript: pasa FILTER_VALIDATE_URL y, al
        // abrirlo, ejecuta código en la sesión de quien hace clic (A-01).
        if (!Enlaces::esSeguro($url)) { $errores['url'] = 'Ingresá un enlace válido que empiece con http:// o https://'; }
        if (!in_array($tipo, self::TIPOS, true)) { $errores['tipo'] = 'Tipo inválido'; }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) { $fecha = date('Y-m-d'); }
        if (!empty($errores)) { $this->json(422, ['errors' => $errores]); return; }

        $stmt = $this->db->prepare('INSERT INTO documento (id_proyecto, nombre, tipo, categoria, url, fecha_carga) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$idProyecto, $nombre, $tipo, $categoria, $url, $fecha]);

        $this->json(201, [
            'id_documento' => (int) $this->db->lastInsertId(),
            'id_proyecto' => (int) $idProyecto,
            'nombre' => $nombre, 'tipo' => $tipo, 'categoria' => $categoria,
            'url' => $url, 'fecha_carga' => $fecha,
        ]);
    }

    /** DELETE /api/proyectos/documento/{idDoc} */
    public function eliminar(string $idDoc): void
    {
        $stmt = $this->db->prepare('DELETE FROM documento WHERE id_documento = ?');
        $stmt->execute([$idDoc]);
        if ($stmt->rowCount() === 0) { $this->json(404, ['error' => 'Documento no encontrado']); return; }
        $this->json(200, ['mensaje' => 'Documento eliminado']);
    }

    /** @param array<string,mixed> $f @return array<string,mixed> */
    private static function normalizar(array $f): array
    {
        $f['id_documento'] = (int) $f['id_documento'];
        $f['id_proyecto'] = (int) $f['id_proyecto'];
        return $f;
    }

    private function json(int $codigo, mixed $cuerpo): void
    {
        http_response_code($codigo);
        echo json_encode($cuerpo, JSON_UNESCAPED_UNICODE);
    }
}
