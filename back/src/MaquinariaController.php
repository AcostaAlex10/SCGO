<?php

declare(strict_types=1);

namespace Sgso;

use PDO;
use Sgso\Http\Paginacion;

/**
 * Gestion de maquinaria. Cubre:
 *  - RF23: registro de uso (horas, combustible, produccion) por maquina.
 *  - RF27: historial de fallas y reemplazos de componentes.
 *  - RF28: comparativa de rendimiento entre operarios (produccion/hora).
 *  - RF24: alerta cuando el consumo de combustible por hora de un registro
 *          supera notablemente el promedio de esa maquina.
 */
final class MaquinariaController
{
    public function __construct(private PDO $db)
    {
    }

    /** GET /api/maquinaria  -> equipos con totales de uso y fallas abiertas. */
    public function listar(): void
    {
        $stmt = $this->db->query(
            'SELECT m.id_maquinaria, m.nombre, m.tipo, m.activa,
                    COALESCE(SUM(r.horas_uso),0) AS horas,
                    COALESCE(SUM(r.combustible_consumido),0) AS combustible,
                    COALESCE(SUM(r.produccion_realizada),0) AS produccion
             FROM maquinaria m
             LEFT JOIN registro_maquinaria r ON r.id_maquinaria = m.id_maquinaria
             GROUP BY m.id_maquinaria, m.nombre, m.tipo, m.activa
             ORDER BY m.nombre'
        );
        $fallas = $this->fallasAbiertasPorMaquina();

        $out = array_map(function (array $m) use ($fallas): array {
            $horas = (float) $m['horas'];
            $comb = (float) $m['combustible'];
            $prod = (float) $m['produccion'];
            return [
                'id_maquinaria' => (int) $m['id_maquinaria'],
                'nombre' => $m['nombre'],
                'tipo' => $m['tipo'],
                'activa' => (int) $m['activa'] === 1,
                'horas' => $horas,
                'combustible' => $comb,
                'produccion' => $prod,
                'combustible_por_hora' => $horas > 0 ? round($comb / $horas, 2) : 0,
                'produccion_por_hora' => $horas > 0 ? round($prod / $horas, 2) : 0,
                'fallas_abiertas' => $fallas[(int) $m['id_maquinaria']] ?? 0,
            ];
        }, $stmt->fetchAll());

        $this->json(200, $out);
    }

    /** POST /api/maquinaria */
    public function crear(array $datos): void
    {
        $nombre = trim((string) ($datos['nombre'] ?? ''));
        $tipo = trim((string) ($datos['tipo'] ?? ''));
        $errores = [];
        if ($nombre === '') { $errores['nombre'] = 'Obligatorio'; }
        if ($tipo === '') { $errores['tipo'] = 'Obligatorio'; }
        if (!empty($errores)) { $this->json(422, ['errors' => $errores]); return; }

        $stmt = $this->db->prepare('INSERT INTO maquinaria (nombre, tipo) VALUES (?, ?)');
        $stmt->execute([$nombre, $tipo]);
        $this->json(201, ['id_maquinaria' => (int) $this->db->lastInsertId(), 'nombre' => $nombre, 'tipo' => $tipo]);
    }

    /** DELETE /api/maquinaria/{id} */
    public function eliminar(string $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM maquinaria WHERE id_maquinaria = ?');
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 0) { $this->json(404, ['error' => 'Maquinaria no encontrada']); return; }
        $this->json(200, ['mensaje' => 'Maquinaria eliminada']);
    }

    /** GET /api/maquinaria/{id}/registros  (RF23 + alerta RF24) */
    public function listarRegistros(string $idMaq, ?Paginacion $pagina = null): void
    {
        // Promedio de combustible/hora de la maquina (para RF24).
        $stmt = $this->db->prepare(
            'SELECT COALESCE(SUM(combustible_consumido),0) AS c, COALESCE(SUM(horas_uso),0) AS h
             FROM registro_maquinaria WHERE id_maquinaria = ? AND horas_uso > 0'
        );
        $stmt->execute([$idMaq]);
        $tot = $stmt->fetch();
        $promedio = ((float) $tot['h']) > 0 ? ((float) $tot['c']) / ((float) $tot['h']) : 0;

        // El promedio de arriba es sobre todos los registros, no sobre la pagina.
        $sql = 'SELECT * FROM registro_maquinaria WHERE id_maquinaria = ? ORDER BY fecha DESC, id_registro DESC';
        if ($pagina !== null) {
            $sql .= $pagina->aplicar($this->db, 'SELECT COUNT(*) FROM registro_maquinaria WHERE id_maquinaria = ?', [$idMaq]);
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$idMaq]);
        $out = array_map(function (array $r) use ($promedio): array {
            $horas = (float) $r['horas_uso'];
            $cph = $horas > 0 ? (float) $r['combustible_consumido'] / $horas : 0;
            return [
                'id_registro' => (int) $r['id_registro'],
                'id_maquinaria' => (int) $r['id_maquinaria'],
                'id_proyecto' => $r['id_proyecto'] !== null ? (int) $r['id_proyecto'] : null,
                'fecha' => $r['fecha'],
                'operario' => $r['operario'],
                'horas_uso' => $horas,
                'combustible_consumido' => (float) $r['combustible_consumido'],
                'produccion_realizada' => (float) $r['produccion_realizada'],
                'combustible_por_hora' => round($cph, 2),
                // RF24: consumo anómalo si supera 1.5x el promedio de la maquina.
                'alerta_consumo' => $promedio > 0 && $cph > $promedio * 1.5,
            ];
        }, $stmt->fetchAll());
        $this->json(200, $out);
    }

    /** POST /api/maquinaria/{id}/registros */
    public function crearRegistro(string $idMaq, array $datos): void
    {
        if (!$this->existeMaquina($idMaq)) { $this->json(404, ['error' => 'Maquinaria no encontrada']); return; }
        $fecha = (string) ($datos['fecha'] ?? '');
        $errores = [];
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) { $errores['fecha'] = 'Formato esperado: YYYY-MM-DD'; }

        // Del uso cargado sale el promedio con el que se decide la alerta de
        // consumo (RF24): un valor negativo corre el umbral de todos los demas.
        foreach (['horas_uso', 'combustible_consumido', 'produccion_realizada'] as $campo) {
            $valor = $datos[$campo] ?? null;
            if ($valor !== null && $valor !== '' && (!is_numeric($valor) || (float) $valor < 0)) {
                $errores[$campo] = 'Debe ser un número mayor o igual a 0';
            }
        }

        // La obra es opcional, pero si viene tiene que existir: si no, el INSERT
        // viola la clave foranea y el cliente recibe un 500.
        $idProyecto = isset($datos['id_proyecto']) && is_numeric($datos['id_proyecto']) ? (int) $datos['id_proyecto'] : null;
        if ($idProyecto !== null) {
            $stmt = $this->db->prepare('SELECT id_proyecto FROM proyecto WHERE id_proyecto = ?');
            $stmt->execute([$idProyecto]);
            if ($stmt->fetch() === false) { $errores['id_proyecto'] = 'Obra inexistente'; }
        }

        if (!empty($errores)) { $this->json(422, ['errors' => $errores]); return; }

        $stmt = $this->db->prepare(
            'INSERT INTO registro_maquinaria (id_maquinaria, id_proyecto, fecha, operario, horas_uso, combustible_consumido, produccion_realizada)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $idMaq,
            $idProyecto,
            $fecha,
            trim((string) ($datos['operario'] ?? '')) ?: null,
            (float) ($datos['horas_uso'] ?? 0),
            (float) ($datos['combustible_consumido'] ?? 0),
            (float) ($datos['produccion_realizada'] ?? 0),
        ]);
        $this->json(201, ['id_registro' => (int) $this->db->lastInsertId()]);
    }

    /** DELETE /api/maquinaria/registro/{id} */
    public function eliminarRegistro(string $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM registro_maquinaria WHERE id_registro = ?');
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 0) { $this->json(404, ['error' => 'Registro no encontrado']); return; }
        $this->json(200, ['mensaje' => 'Registro eliminado']);
    }

    /** GET /api/maquinaria/{id}/fallas  (RF27) */
    public function listarFallas(string $idMaq): void
    {
        $stmt = $this->db->prepare('SELECT * FROM falla_maquinaria WHERE id_maquinaria = ? ORDER BY fecha DESC, id_falla DESC');
        $stmt->execute([$idMaq]);
        $out = array_map(function (array $f): array {
            $f['id_falla'] = (int) $f['id_falla'];
            $f['id_maquinaria'] = (int) $f['id_maquinaria'];
            $f['reemplazo'] = (int) $f['reemplazo'] === 1;
            $f['resuelto'] = (int) $f['resuelto'] === 1;
            return $f;
        }, $stmt->fetchAll());
        $this->json(200, $out);
    }

    /** POST /api/maquinaria/{id}/fallas */
    public function crearFalla(string $idMaq, array $datos): void
    {
        if (!$this->existeMaquina($idMaq)) { $this->json(404, ['error' => 'Maquinaria no encontrada']); return; }
        $fecha = (string) ($datos['fecha'] ?? '');
        $descripcion = trim((string) ($datos['descripcion'] ?? ''));
        $errores = [];
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) { $errores['fecha'] = 'Formato esperado: YYYY-MM-DD'; }
        if ($descripcion === '') { $errores['descripcion'] = 'Obligatorio'; }
        if (!empty($errores)) { $this->json(422, ['errors' => $errores]); return; }

        $stmt = $this->db->prepare(
            'INSERT INTO falla_maquinaria (id_maquinaria, fecha, componente, descripcion, reemplazo, resuelto)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $idMaq, $fecha,
            trim((string) ($datos['componente'] ?? '')) ?: null,
            $descripcion,
            !empty($datos['reemplazo']) ? 1 : 0,
            !empty($datos['resuelto']) ? 1 : 0,
        ]);
        $this->json(201, ['id_falla' => (int) $this->db->lastInsertId()]);
    }

    /** DELETE /api/maquinaria/falla/{id} */
    public function eliminarFalla(string $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM falla_maquinaria WHERE id_falla = ?');
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 0) { $this->json(404, ['error' => 'Falla no encontrada']); return; }
        $this->json(200, ['mensaje' => 'Falla eliminada']);
    }

    /** GET /api/maquinaria/operarios  -> rendimiento por operario (RF28). */
    public function rendimientoOperarios(): void
    {
        $stmt = $this->db->query(
            'SELECT operario,
                    SUM(horas_uso) AS horas,
                    SUM(produccion_realizada) AS produccion,
                    SUM(combustible_consumido) AS combustible
             FROM registro_maquinaria
             WHERE operario IS NOT NULL AND LENGTH(TRIM(operario)) > 0
             GROUP BY operario
             ORDER BY produccion DESC'
        );
        $out = array_map(function (array $o): array {
            $horas = (float) $o['horas'];
            $prod = (float) $o['produccion'];
            return [
                'operario' => $o['operario'],
                'horas' => $horas,
                'produccion' => $prod,
                'combustible' => (float) $o['combustible'],
                'produccion_por_hora' => $horas > 0 ? round($prod / $horas, 2) : 0,
            ];
        }, $stmt->fetchAll());
        $this->json(200, $out);
    }

    private function existeMaquina(string $id): bool
    {
        $stmt = $this->db->prepare('SELECT id_maquinaria FROM maquinaria WHERE id_maquinaria = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() !== false;
    }

    /** @return array<int,int> id_maquinaria => cantidad de fallas no resueltas */
    private function fallasAbiertasPorMaquina(): array
    {
        $stmt = $this->db->query('SELECT id_maquinaria, COUNT(*) AS n FROM falla_maquinaria WHERE resuelto = 0 GROUP BY id_maquinaria');
        $mapa = [];
        foreach ($stmt->fetchAll() as $f) { $mapa[(int) $f['id_maquinaria']] = (int) $f['n']; }
        return $mapa;
    }

    private function json(int $codigo, mixed $cuerpo): void
    {
        http_response_code($codigo);
        echo json_encode($cuerpo, JSON_UNESCAPED_UNICODE);
    }
}
