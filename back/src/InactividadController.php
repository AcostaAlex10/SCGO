<?php

declare(strict_types=1);

namespace Sgso;

use PDO;
use Sgso\Reglas\CicloDeVida;
use Sgso\Reglas\Fecha;

/**
 * Periodos de inactividad de la obra con su motivo (RF25). Sirven para
 * justificar paradas y contextualizar desvios de avance.
 *
 * Ademas mueven el estado de la obra, como define el ciclo de vida del TP3:
 * registrar un periodo la pausa, y cerrarlo la reactiva.
 */
final class InactividadController
{
    public function __construct(private PDO $db)
    {
    }

    /** GET /api/proyectos/{idProyecto}/inactividades */
    public function listarPorProyecto(string $idProyecto): void
    {
        $stmt = $this->db->prepare('SELECT * FROM periodo_inactividad WHERE id_proyecto = ? ORDER BY fecha_inicio DESC, id_periodo DESC');
        $stmt->execute([$idProyecto]);
        $hoy = date('Y-m-d');
        $this->json(200, array_map(static fn (array $f): array => self::normalizar($f, $hoy), $stmt->fetchAll()));
    }

    /** POST /api/proyectos/{idProyecto}/inactividades */
    public function crear(string $idProyecto, array $datos): void
    {
        $stmt = $this->db->prepare('SELECT id_proyecto FROM proyecto WHERE id_proyecto = ?');
        $stmt->execute([$idProyecto]);
        if ($stmt->fetch() === false) { $this->json(404, ['error' => 'Obra no encontrada']); return; }

        $inicio = (string) ($datos['fecha_inicio'] ?? '');
        $fin = trim((string) ($datos['fecha_fin'] ?? ''));
        $motivo = trim((string) ($datos['motivo'] ?? ''));

        $errores = [];
        if (!Fecha::esValida($inicio)) { $errores['fecha_inicio'] = 'Formato esperado: YYYY-MM-DD'; }
        if ($fin !== '' && !Fecha::esValida($fin)) { $errores['fecha_fin'] = 'Formato esperado: YYYY-MM-DD'; }
        if ($fin !== '' && !isset($errores['fecha_inicio']) && !isset($errores['fecha_fin']) && $fin < $inicio) { $errores['fecha_fin'] = 'La fecha de fin no puede ser anterior al inicio'; }
        if ($motivo === '') { $errores['motivo'] = 'Obligatorio'; }
        if (!empty($errores)) { $this->json(422, ['errors' => $errores]); return; }

        $stmt = $this->db->prepare('INSERT INTO periodo_inactividad (id_proyecto, fecha_inicio, fecha_fin, motivo) VALUES (?, ?, ?, ?)');
        $stmt->execute([$idProyecto, $inicio, $fin !== '' ? $fin : null, $motivo]);

        $hoy = date('Y-m-d');
        $this->json(201, [
            'id_periodo' => (int) $this->db->lastInsertId(),
            'id_proyecto' => (int) $idProyecto,
            'fecha_inicio' => $inicio,
            'fecha_fin' => $fin !== '' ? $fin : null,
            'motivo' => $motivo,
            'vigente' => $inicio <= $hoy && ($fin === '' || $fin > $hoy),
            // La obra pasa a `pausada` si el periodo esta vigente (TP3).
            'estado_proyecto' => $this->sincronizarEstado($idProyecto),
        ]);
    }

    /**
     * PUT /api/proyectos/inactividad/{id}  -> cierra el periodo.
     *
     * Es la contraparte de crear: al cerrarse el ultimo periodo vigente la obra
     * vuelve a `en_ejecucion`. Sin este endpoint la unica forma de reactivar
     * una obra seria borrar el periodo, y con el se perderia el registro que
     * RF25 pide conservar.
     */
    public function cerrar(string $id, array $datos): void
    {
        $stmt = $this->db->prepare('SELECT id_proyecto, fecha_inicio FROM periodo_inactividad WHERE id_periodo = ?');
        $stmt->execute([$id]);
        $periodo = $stmt->fetch();
        if ($periodo === false) { $this->json(404, ['error' => 'Período no encontrado']); return; }

        // Sin fecha explicita se cierra hoy, que es el caso normal: la obra
        // vuelve a arrancar y alguien lo registra en el momento.
        $fin = trim((string) ($datos['fecha_fin'] ?? ''));
        if ($fin === '') { $fin = date('Y-m-d'); }

        if (!Fecha::esValida($fin)) {
            $this->json(422, ['errors' => ['fecha_fin' => 'Formato esperado: YYYY-MM-DD']]); return;
        }
        if ($fin < (string) $periodo['fecha_inicio']) {
            $this->json(422, ['errors' => ['fecha_fin' => 'La fecha de fin no puede ser anterior al inicio']]); return;
        }

        $stmt = $this->db->prepare('UPDATE periodo_inactividad SET fecha_fin = ? WHERE id_periodo = ?');
        $stmt->execute([$fin, $id]);

        $idProyecto = (string) $periodo['id_proyecto'];
        $this->json(200, [
            'id_periodo' => (int) $id,
            'id_proyecto' => (int) $idProyecto,
            'fecha_inicio' => $periodo['fecha_inicio'],
            'fecha_fin' => $fin,
            'vigente' => $fin > date('Y-m-d'),
            'estado_proyecto' => $this->sincronizarEstado($idProyecto),
        ]);
    }

    /** DELETE /api/proyectos/inactividad/{id} */
    public function eliminar(string $id): void
    {
        // Hay que leer la obra antes de borrar: despues ya no se sabe cual era.
        $stmt = $this->db->prepare('SELECT id_proyecto FROM periodo_inactividad WHERE id_periodo = ?');
        $stmt->execute([$id]);
        $idProyecto = $stmt->fetchColumn();
        if ($idProyecto === false) { $this->json(404, ['error' => 'Período no encontrado']); return; }

        $stmt = $this->db->prepare('DELETE FROM periodo_inactividad WHERE id_periodo = ?');
        $stmt->execute([$id]);

        $this->json(200, [
            'mensaje' => 'Período eliminado',
            'estado_proyecto' => $this->sincronizarEstado((string) $idProyecto),
        ]);
    }

    /**
     * Ajusta el estado de la obra segun sus periodos de inactividad.
     *
     * Un periodo esta vigente si ya empezo y todavia no termino. Con al menos
     * uno vigente la obra queda `pausada`; cuando no queda ninguno, vuelve a
     * `en_ejecucion`.
     *
     * `fecha_fin` es el dia en que la obra vuelve a arrancar, no el ultimo dia
     * parado. Por eso la comparacion es estricta: cerrar un periodo con la
     * fecha de hoy reactiva la obra hoy, que es lo que promete el boton.
     *
     * Solo se pausa una obra en marcha: una en `planificacion` todavia no
     * arranco, y `finalizada` y `cancelada` son terminales. Registrar un
     * periodo historico sobre una obra terminada es legitimo y no la revive.
     *
     * La fecha de hoy sale de PHP y no de CURDATE(): la zona horaria del
     * proceso esta fijada en index.php, mientras que la de la base la decide
     * el proveedor.
     *
     * @return string|null el estado nuevo, o null si no hubo cambio.
     */
    private function sincronizarEstado(string $idProyecto): ?string
    {
        $hoy = date('Y-m-d');

        $stmt = $this->db->prepare('SELECT estado FROM proyecto WHERE id_proyecto = ?');
        $stmt->execute([$idProyecto]);
        $estado = $stmt->fetchColumn();
        if ($estado === false) { return null; }

        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM periodo_inactividad
              WHERE id_proyecto = ?
                AND fecha_inicio <= ?
                AND (fecha_fin IS NULL OR fecha_fin > ?)'
        );
        $stmt->execute([$idProyecto, $hoy, $hoy]);
        $vigentes = (int) $stmt->fetchColumn();

        if (CicloDeVida::debePausar((string) $estado, $vigentes)) {
            $nuevo = CicloDeVida::PAUSADA;
        } elseif (CicloDeVida::debeReactivar((string) $estado, $vigentes)) {
            // A donde vuelve depende de si la obra estaba cerrandose. No hace
            // falta recordar el estado anterior en una columna: si hay un
            // reporte final esperando revision, la obra estaba en
            // `en_revision` y ahi vuelve; si no, a `en_ejecucion`.
            $nuevo = CicloDeVida::destinoAlReactivar($this->tieneFinalEnRevision($idProyecto));
        } else {
            return null;
        }

        $stmt = $this->db->prepare('UPDATE proyecto SET estado = ? WHERE id_proyecto = ?');
        $stmt->execute([$nuevo, $idProyecto]);
        return $nuevo;
    }

    /** Si la obra tiene un reporte final esperando la revision del supervisor. */
    private function tieneFinalEnRevision(string $idProyecto): bool
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM reporte
              WHERE id_proyecto = ? AND es_final = 1 AND estado = 'en_revision'"
        );
        $stmt->execute([$idProyecto]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /** @param array<string,mixed> $f @return array<string,mixed> */
    private static function normalizar(array $f, string $hoy): array
    {
        $f['id_periodo'] = (int) $f['id_periodo'];
        $f['id_proyecto'] = (int) $f['id_proyecto'];
        // El periodo vigente es el que mantiene pausada la obra.
        $f['vigente'] = (string) $f['fecha_inicio'] <= $hoy
            && ($f['fecha_fin'] === null || (string) $f['fecha_fin'] > $hoy);
        return $f;
    }

    private function json(int $codigo, mixed $cuerpo): void
    {
        http_response_code($codigo);
        echo json_encode($cuerpo, JSON_UNESCAPED_UNICODE);
    }
}
