<?php

declare(strict_types=1);

namespace Sgso;

use PDO;
use Sgso\Http\Paginacion;
use Sgso\Reglas\CicloDeVida;

/**
 * Reportes operativos de obra con flujo de aprobacion (RF21) y observaciones
 * del revisor (RF17).
 *
 * Ciclo de vida del reporte (coincide con el diseno del TP3):
 *   borrador --enviar--> en_revision --aprobar--> aprobado
 *                                    --rechazar-> rechazado --enviar--> en_revision
 *
 * El autor (personal de obra) crea, edita y envia; el supervisor
 * (PersonalAdministrativo) aprueba o rechaza dejando una observacion.
 *
 * REPORTE FINAL Y CIERRE DE LA OBRA
 *
 * Un reporte marcado con `es_final` es la certificacion de cierre: enviarlo
 * lleva la obra a 'en_revision' y aprobarlo la finaliza. Es la unica via por
 * la que una obra queda terminada. El avance fisico ya no la finaliza solo
 * por llegar al 100 %: una obra no esta terminada porque un numero llegue a
 * cien, sino porque alguien la recibe.
 *
 * La obra solo se mueve si esta en el estado que corresponde: enviar exige
 * 'en_ejecucion', y aprobar o rechazar solo mueven una obra que este en
 * 'en_revision'. Asi la resolucion de un reporte no revive una obra pausada ni
 * pisa una cancelada.
 */
final class ReporteController
{
    /** Estados de reporte en los que un final ya ocupa el cierre de la obra. */
    private const FINAL_VIGENTE = ['en_revision', 'aprobado'];

    public function __construct(private PDO $db)
    {
    }

    private const SELECT =
        'SELECT r.id_reporte, r.id_proyecto, p.nombre AS proyecto, r.id_usuario,
                u.nombre AS autor, r.titulo, r.contenido, r.estado, r.es_final,
                r.observacion_revision, r.fecha_creacion, r.fecha_revision
         FROM reporte r
         JOIN proyecto p ON p.id_proyecto = r.id_proyecto
         JOIN usuario u ON u.id_usuario = r.id_usuario';

    /** GET /api/reportes[?estado=...] */
    public function listar(?string $estado, ?Paginacion $pagina = null): void
    {
        $filtro = $estado !== null && $estado !== '' ? ' WHERE r.estado = ?' : '';
        $parametros = $filtro !== '' ? [$estado] : [];
        // El id desempata dos reportes creados en el mismo segundo.
        $sql = self::SELECT . $filtro . ' ORDER BY r.fecha_creacion DESC, r.id_reporte DESC';
        if ($pagina !== null) {
            $sql .= $pagina->aplicar($this->db, 'SELECT COUNT(*) FROM reporte r' . $filtro, $parametros);
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($parametros);
        $this->json(200, array_map([self::class, 'normalizar'], $stmt->fetchAll()));
    }

    /**
     * POST /api/reportes  -> crea un reporte en estado borrador.
     * @param array<string,mixed> $datos
     * @param array<string,mixed> $usuario  payload del token (autor)
     */
    public function crear(array $datos, array $usuario): void
    {
        $idProyecto = $datos['id_proyecto'] ?? null;
        $titulo = trim((string) ($datos['titulo'] ?? ''));
        $contenido = trim((string) ($datos['contenido'] ?? ''));

        $errores = [];
        if (!is_numeric($idProyecto)) { $errores['id_proyecto'] = 'Seleccioná una obra'; }
        if ($titulo === '') { $errores['titulo'] = 'Obligatorio'; }
        if ($contenido === '') { $errores['contenido'] = 'Obligatorio'; }
        if (!empty($errores)) { $this->json(422, ['errors' => $errores]); return; }

        $stmt = $this->db->prepare('SELECT id_proyecto FROM proyecto WHERE id_proyecto = ?');
        $stmt->execute([$idProyecto]);
        if ($stmt->fetch() === false) { $this->json(404, ['error' => 'Obra no encontrada']); return; }

        $stmt = $this->db->prepare(
            'INSERT INTO reporte (id_proyecto, id_usuario, titulo, contenido, es_final) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $idProyecto,
            (int) ($usuario['id_usuario'] ?? 0),
            $titulo,
            $contenido,
            self::leerEsFinal($datos, false) ? 1 : 0,
        ]);

        $this->mostrarPorId((int) $this->db->lastInsertId(), 201);
    }

    /** PUT /api/reportes/{id}  -> editar (solo en borrador o rechazado). */
    public function editar(string $id, array $datos): void
    {
        $actual = $this->buscar($id);
        if ($actual === null) { $this->json(404, ['error' => 'Reporte no encontrado']); return; }
        if (!in_array($actual['estado'], ['borrador', 'rechazado'], true)) {
            $this->json(409, ['error' => 'Solo se puede editar un reporte en borrador o rechazado']);
            return;
        }

        $titulo = trim((string) ($datos['titulo'] ?? $actual['titulo']));
        $contenido = trim((string) ($datos['contenido'] ?? $actual['contenido']));
        if ($titulo === '' || $contenido === '') {
            $this->json(422, ['error' => 'Título y contenido son obligatorios']);
            return;
        }

        $esFinal = self::leerEsFinal($datos, (bool) $actual['es_final']);

        $stmt = $this->db->prepare('UPDATE reporte SET titulo = ?, contenido = ?, es_final = ? WHERE id_reporte = ?');
        $stmt->execute([$titulo, $contenido, $esFinal ? 1 : 0, $id]);
        $this->mostrarPorId((int) $id, 200);
    }

    /**
     * POST /api/reportes/{id}/enviar  -> manda a revision.
     *
     * Si el reporte es el final, ademas lleva la obra a 'en_revision'. Se
     * exige que la obra este 'en_ejecucion': una obra pausada o ya terminada
     * no puede entrar en revision de cierre.
     */
    public function enviar(string $id): void
    {
        $actual = $this->buscar($id);
        if ($actual === null) { $this->json(404, ['error' => 'Reporte no encontrado']); return; }
        if (!in_array($actual['estado'], ['borrador', 'rechazado'], true)) {
            $this->json(409, ['error' => 'El reporte ya fue enviado a revisión']);
            return;
        }

        $idProyecto = (string) $actual['id_proyecto'];
        $esFinal = (bool) $actual['es_final'];

        if ($esFinal) {
            if ($this->hayOtroFinalVigente($idProyecto, $id)) {
                $this->json(409, ['error' => 'La obra ya tiene un reporte final en revisión o aprobado']);
                return;
            }
            $estadoObra = $this->estadoObra($idProyecto);
            if (!CicloDeVida::puedeEnviarReporteFinal((string) $estadoObra)) {
                $this->json(409, [
                    'error' => 'Solo se puede enviar el reporte final de una obra en ejecución',
                ]);
                return;
            }
        }

        $this->db->prepare('UPDATE reporte SET estado = ?, observacion_revision = NULL WHERE id_reporte = ?')
            ->execute(['en_revision', $id]);

        $estadoProyecto = $esFinal ? $this->moverObra($idProyecto, CicloDeVida::EN_REVISION) : null;

        $this->mostrarPorId((int) $id, 200, $estadoProyecto);
    }

    /** POST /api/reportes/{id}/aprobar  -> solo desde en_revision. */
    public function aprobar(string $id, array $datos): void
    {
        $this->resolver($id, 'aprobado', $datos['observacion'] ?? null);
    }

    /** POST /api/reportes/{id}/rechazar  -> solo desde en_revision (observacion obligatoria). */
    public function rechazar(string $id, array $datos): void
    {
        $obs = trim((string) ($datos['observacion'] ?? ''));
        if ($obs === '') {
            $this->json(422, ['errors' => ['observacion' => 'Indicá el motivo del rechazo']]);
            return;
        }
        $this->resolver($id, 'rechazado', $obs);
    }

    /**
     * DELETE /api/reportes/{id}  -> solo en borrador o rechazado.
     *
     * La restriccion es la misma que en editar() y enviar(), y aca ademas es
     * lo que evita que la obra quede trabada: borrar el reporte final mientras
     * esta 'en_revision' dejaba la obra en ese estado sin nadie que la sacara.
     * enviar() otro final exige la obra 'en_ejecucion', resolver() exige un
     * reporte en revision que ya no existe, y modificar() de obra solo admite
     * cancelar desde 'en_ejecucion' o 'pausada'. No quedaba ninguna salida.
     */
    public function eliminar(string $id): void
    {
        $actual = $this->buscar($id);
        if ($actual === null) { $this->json(404, ['error' => 'Reporte no encontrado']); return; }
        if (!in_array($actual['estado'], ['borrador', 'rechazado'], true)) {
            $this->json(409, ['error' => 'Solo se puede eliminar un reporte en borrador o rechazado']);
            return;
        }

        $this->db->prepare('DELETE FROM reporte WHERE id_reporte = ?')->execute([$id]);
        $this->json(200, ['mensaje' => 'Reporte eliminado']);
    }

    /**
     * Aplica una resolucion (aprobado/rechazado) validando que este en revision.
     *
     * Si el reporte es el final, la resolucion cierra o reabre la obra:
     * aprobarlo la finaliza, rechazarlo la devuelve a 'en_ejecucion'. Solo se
     * mueve una obra que este en 'en_revision': si mientras tanto la pausaron
     * o la cancelaron, la resolucion queda registrada en el reporte y la obra
     * se deja donde esta, en vez de revivirla.
     */
    private function resolver(string $id, string $nuevoEstado, ?string $observacion): void
    {
        $actual = $this->buscar($id);
        if ($actual === null) { $this->json(404, ['error' => 'Reporte no encontrado']); return; }
        if ($actual['estado'] !== 'en_revision') {
            $this->json(409, ['error' => 'Solo se pueden resolver reportes en revisión']);
            return;
        }
        $stmt = $this->db->prepare('UPDATE reporte SET estado = ?, observacion_revision = ?, fecha_revision = NOW() WHERE id_reporte = ?');
        $stmt->execute([$nuevoEstado, $observacion, $id]);

        $estadoProyecto = null;
        if ((bool) $actual['es_final']) {
            $idProyecto = (string) $actual['id_proyecto'];
            $destino = CicloDeVida::destinoTrasResolverFinal((string) $this->estadoObra($idProyecto), $nuevoEstado);
            if ($destino !== null) {
                $estadoProyecto = $this->moverObra($idProyecto, $destino);
            }
        }

        $this->mostrarPorId((int) $id, 200, $estadoProyecto);
    }

    /** Estado actual de la obra, o null si no existe. */
    private function estadoObra(string $idProyecto): ?string
    {
        $stmt = $this->db->prepare('SELECT estado FROM proyecto WHERE id_proyecto = ?');
        $stmt->execute([$idProyecto]);
        $estado = $stmt->fetchColumn();
        return $estado === false ? null : (string) $estado;
    }

    /** Mueve la obra y devuelve el estado nuevo, para que el front lo refleje. */
    private function moverObra(string $idProyecto, string $estado): string
    {
        $this->db->prepare('UPDATE proyecto SET estado = ? WHERE id_proyecto = ?')
            ->execute([$estado, $idProyecto]);
        return $estado;
    }

    /** Si la obra ya tiene otro reporte final ocupando el cierre. */
    private function hayOtroFinalVigente(string $idProyecto, string $idExcluido): bool
    {
        $marcadores = implode(',', array_fill(0, count(self::FINAL_VIGENTE), '?'));
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM reporte
              WHERE id_proyecto = ? AND es_final = 1 AND id_reporte <> ?
                AND estado IN ({$marcadores})"
        );
        $stmt->execute(array_merge([$idProyecto, $idExcluido], self::FINAL_VIGENTE));
        return (int) $stmt->fetchColumn() > 0;
    }

    /** Lee `es_final` del cuerpo aceptando booleano, 0/1 o "true"/"false". */
    private static function leerEsFinal(array $datos, bool $porDefecto): bool
    {
        if (!array_key_exists('es_final', $datos)) {
            return $porDefecto;
        }
        return filter_var($datos['es_final'], FILTER_VALIDATE_BOOL);
    }

    /** @return array<string,mixed>|null */
    private function buscar(string $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM reporte WHERE id_reporte = ?');
        $stmt->execute([$id]);
        $fila = $stmt->fetch();
        return $fila === false ? null : $fila;
    }

    /**
     * @param string|null $estadoProyecto estado nuevo de la obra, si la
     *        operacion la movio. Viaja en la respuesta para que el front lo
     *        refleje sin volver a pedir la obra.
     */
    private function mostrarPorId(int $id, int $codigo, ?string $estadoProyecto = null): void
    {
        $stmt = $this->db->prepare(self::SELECT . ' WHERE r.id_reporte = ?');
        $stmt->execute([$id]);
        $fila = $stmt->fetch();
        if ($fila === false) { $this->json(404, ['error' => 'Reporte no encontrado']); return; }

        $cuerpo = self::normalizar($fila);
        if ($estadoProyecto !== null) {
            $cuerpo['estado_proyecto'] = $estadoProyecto;
        }

        $this->json($codigo, $cuerpo);
    }

    /** @param array<string,mixed> $f @return array<string,mixed> */
    private static function normalizar(array $f): array
    {
        $f['id_reporte'] = (int) $f['id_reporte'];
        $f['id_proyecto'] = (int) $f['id_proyecto'];
        $f['id_usuario'] = (int) $f['id_usuario'];
        $f['es_final'] = (bool) $f['es_final'];
        return $f;
    }

    private function json(int $codigo, mixed $cuerpo): void
    {
        http_response_code($codigo);
        echo json_encode($cuerpo, JSON_UNESCAPED_UNICODE);
    }
}
