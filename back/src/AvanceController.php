<?php

declare(strict_types=1);

namespace Sgso;

use PDO;
use Sgso\Http\Paginacion;
use Sgso\Reglas\CicloDeVida;

/**
 * Controlador de Avances fisicos. Cada avance se carga contra una
 * planificacion. Incluye un resumen que compara avance esperado vs real.
 * Portado del backend Node al stack PHP/MariaDB.
 */
final class AvanceController
{
    public function __construct(private PDO $db)
    {
    }

    /** GET /api/planificacion/{planId}/avances */
    public function listarPorPlan(string $planId, ?Paginacion $pagina = null): void
    {
        // El id desempata dos avances del mismo dia: sin un orden total, paginar
        // podria repetir uno en dos paginas y saltear otro.
        $sql = 'SELECT * FROM avance_fisico WHERE id_planificacion = ? ORDER BY fecha DESC, id_avance DESC';
        if ($pagina !== null) {
            $sql .= $pagina->aplicar($this->db, 'SELECT COUNT(*) FROM avance_fisico WHERE id_planificacion = ?', [$planId]);
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$planId]);
        $this->json(200, array_map([self::class, 'normalizar'], $stmt->fetchAll()));
    }

    /** GET /api/planificacion/{planId}/avances/resumen */
    public function resumen(string $planId): void
    {
        $stmt = $this->db->prepare('SELECT avance_esperado_total FROM planificacion WHERE id_planificacion = ?');
        $stmt->execute([$planId]);
        $plan = $stmt->fetch();
        if ($plan === false) {
            $this->json(404, ['error' => 'Planificacion no encontrada']);
            return;
        }

        $stmt = $this->db->prepare(
            'SELECT COUNT(*) AS total_registros, MAX(porcentaje_avance) AS avance_real, MAX(fecha) AS ultimo_registro
             FROM avance_fisico WHERE id_planificacion = ?'
        );
        $stmt->execute([$planId]);
        $stats = $stmt->fetch();

        // Calcula el esperado a hoy a partir de las etapas con sus fechas.
        // Si no hay etapas, usa avance_esperado_total como fallback.
        $esperado = EtapaPlanificacionController::calcularEsperadoHoy(
            $this->db,
            (int) $planId,
            (float) $plan['avance_esperado_total']
        );
        $real = (float) ($stats['avance_real'] ?? 0);

        $this->json(200, [
            'avance_esperado'  => $esperado,
            'avance_real'      => $real,
            'desvio_pp'        => round($real - $esperado, 2),
            'total_registros'  => (int) $stats['total_registros'],
            'ultimo_registro'  => $stats['ultimo_registro'],
        ]);
    }

    /** POST /api/planificacion/{planId}/avances */
    public function crear(string $planId, array $datos): void
    {
        $stmt = $this->db->prepare('SELECT id_planificacion FROM planificacion WHERE id_planificacion = ?');
        $stmt->execute([$planId]);
        if ($stmt->fetch() === false) {
            $this->json(404, ['error' => 'Planificacion no encontrada']);
            return;
        }

        if ($this->obraCancelada($planId)) {
            $this->json(409, ['error' => 'No se puede registrar avance en una obra cancelada']);
            return;
        }

        $errores = $this->validar($datos);
        if (!empty($errores)) {
            $this->json(422, ['errors' => $errores]);
            return;
        }

        $stmt = $this->db->prepare(
            'INSERT INTO avance_fisico (cantidad_ejecutada, porcentaje_avance, fecha, observaciones, id_planificacion)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            (float) $datos['cantidad_ejecutada'],
            (float) $datos['porcentaje_avance'],
            $datos['fecha'],
            $datos['observaciones'] ?? null,
            $planId,
        ]);
        // Antes de sincronizar: con el driver de MySQL, cualquier SELECT o UPDATE
        // posterior deja lastInsertId() en 0.
        $idAvance = (int) $this->db->lastInsertId();

        // Transicion automatica de estado de la obra segun sus avances.
        $this->sincronizarProyecto($planId);

        $this->json(201, [
            'id_avance' => $idAvance,
            'id_planificacion' => (int) $planId,
            'cantidad_ejecutada' => (float) $datos['cantidad_ejecutada'],
            'porcentaje_avance' => (float) $datos['porcentaje_avance'],
            'fecha' => $datos['fecha'],
            'observaciones' => $datos['observaciones'] ?? null,
        ]);
    }

    /** GET /api/planificacion/avance/{id} */
    public function mostrar(string $id): void
    {
        $stmt = $this->db->prepare('SELECT * FROM avance_fisico WHERE id_avance = ?');
        $stmt->execute([$id]);
        $avance = $stmt->fetch();
        if ($avance === false) {
            $this->json(404, ['error' => 'Registro de avance no encontrado']);
            return;
        }
        $this->json(200, $this->normalizar($avance));
    }

    /** PUT /api/planificacion/avance/{id} */
    public function actualizar(string $id, array $datos): void
    {
        $stmt = $this->db->prepare('SELECT * FROM avance_fisico WHERE id_avance = ?');
        $stmt->execute([$id]);
        $actual = $stmt->fetch();
        if ($actual === false) {
            $this->json(404, ['error' => 'Registro de avance no encontrado']);
            return;
        }

        if ($this->obraCancelada((string) $actual['id_planificacion'])) {
            $this->json(409, ['error' => 'No se puede registrar avance en una obra cancelada']);
            return;
        }

        $errores = $this->validar($datos, true);
        if (!empty($errores)) {
            $this->json(422, ['errors' => $errores]);
            return;
        }

        $stmt = $this->db->prepare(
            'UPDATE avance_fisico SET cantidad_ejecutada = ?, porcentaje_avance = ?, fecha = ?, observaciones = ?
             WHERE id_avance = ?'
        );
        $stmt->execute([
            isset($datos['cantidad_ejecutada']) ? (float) $datos['cantidad_ejecutada'] : $actual['cantidad_ejecutada'],
            isset($datos['porcentaje_avance']) ? (float) $datos['porcentaje_avance'] : $actual['porcentaje_avance'],
            $datos['fecha'] ?? $actual['fecha'],
            array_key_exists('observaciones', $datos) ? $datos['observaciones'] : $actual['observaciones'],
            $id,
        ]);

        // El porcentaje pudo cambiar -> recalcular estado/avance de la obra.
        $this->sincronizarProyecto((string) $actual['id_planificacion']);

        $this->json(200, ['mensaje' => 'Avance actualizado']);
    }

    /** DELETE /api/planificacion/avance/{id} */
    public function eliminar(string $id): void
    {
        // Necesitamos la planificacion antes de borrar para recalcular la obra.
        $stmt = $this->db->prepare('SELECT id_planificacion FROM avance_fisico WHERE id_avance = ?');
        $stmt->execute([$id]);
        $avance = $stmt->fetch();
        if ($avance === false) {
            $this->json(404, ['error' => 'Registro de avance no encontrado']);
            return;
        }

        $stmt = $this->db->prepare('DELETE FROM avance_fisico WHERE id_avance = ?');
        $stmt->execute([$id]);

        $this->sincronizarProyecto((string) $avance['id_planificacion']);

        $this->json(200, ['mensaje' => 'Avance eliminado']);
    }

    /**
     * Transicion automatica del estado de la OBRA segun los avances cargados
     * en su planificacion (RF: ciclo de vida del proyecto):
     *   - primer avance (> 0%) estando en 'planificacion' -> 'en_ejecucion'
     *
     * El avance NO finaliza la obra. Llegar al 100 % es un dato, no una
     * decision: la obra se cierra cuando el supervisor aprueba el reporte
     * final, como establece el TP3 y hace ReporteController. Antes esta
     * funcion la pasaba a 'finalizada' al tocar el 100 %, y como esa regla no
     * miraba el estado previo, tambien terminaba una obra pausada o cancelada.
     *
     * Ninguna otra transicion sale de aca: 'pausada' la maneja
     * InactividadController, y 'cancelada' el formulario de obra.
     *
     * Ademas refleja el avance real (mayor porcentaje cargado) en proyecto.avance,
     * que es lo que muestran el dashboard y el listado.
     */
    /**
     * Si la obra de esa planificacion esta cancelada.
     *
     * Una obra cancelada se dio por terminada sin completarse: seguir
     * cargandole avance hace subir su porcentaje en el dashboard y en el
     * listado, como si avanzara. InactividadController ya se protege por otro
     * lado ('cancelada' no esta en EN_MARCHA); esto es el control equivalente.
     *
     * Mira solo 'cancelada'. Que una obra 'finalizada' tampoco deba recibir
     * avance es razonable, pero es una decision aparte y todavia no esta
     * tomada.
     */
    private function obraCancelada(string $planId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT p.estado
               FROM planificacion pl
               JOIN proyecto p ON p.id_proyecto = pl.id_proyecto
              WHERE pl.id_planificacion = ?'
        );
        $stmt->execute([$planId]);
        return !CicloDeVida::aceptaAvance((string) $stmt->fetchColumn());
    }

    private function sincronizarProyecto(string $planId): void
    {
        $stmt = $this->db->prepare(
            'SELECT p.id_proyecto, p.estado
             FROM planificacion pl
             JOIN proyecto p ON p.id_proyecto = pl.id_proyecto
             WHERE pl.id_planificacion = ?'
        );
        $stmt->execute([$planId]);
        $proyecto = $stmt->fetch();
        if ($proyecto === false) {
            return; // planificacion sin proyecto asociado (no deberia ocurrir)
        }

        $stmt = $this->db->prepare(
            'SELECT COALESCE(MAX(porcentaje_avance), 0) FROM avance_fisico WHERE id_planificacion = ?'
        );
        $stmt->execute([$planId]);
        $avanceReal = (float) $stmt->fetchColumn();

        $estado = $proyecto['estado'];
        if (CicloDeVida::arrancaPorAvance((string) $proyecto['estado'], $avanceReal)) {
            $estado = CicloDeVida::EN_EJECUCION;
        }

        $stmt = $this->db->prepare('UPDATE proyecto SET avance = ?, estado = ? WHERE id_proyecto = ?');
        $stmt->execute([$avanceReal, $estado, $proyecto['id_proyecto']]);
    }

    /** @param array<string,mixed> $datos @return array<string,string> */
    private function validar(array $datos, bool $parcial = false): array
    {
        $errores = [];
        $reglas = [
            'cantidad_ejecutada' => fn ($v) => is_numeric($v) && $v >= 0,
            'porcentaje_avance' => fn ($v) => is_numeric($v) && $v >= 0 && $v <= 100,
        ];
        foreach ($reglas as $campo => $ok) {
            if (isset($datos[$campo])) {
                if (!$ok($datos[$campo])) {
                    $errores[$campo] = 'Valor invalido';
                }
            } elseif (!$parcial) {
                $errores[$campo] = 'Obligatorio';
            }
        }
        if (isset($datos['fecha'])) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $datos['fecha'])) {
                $errores['fecha'] = 'Formato esperado: YYYY-MM-DD';
            }
        } elseif (!$parcial) {
            $errores['fecha'] = 'Obligatorio';
        }
        return $errores;
    }

    /** @param array<string,mixed> $fila @return array<string,mixed> */
    private static function normalizar(array $fila): array
    {
        $fila['id_avance'] = (int) $fila['id_avance'];
        $fila['id_planificacion'] = (int) $fila['id_planificacion'];
        $fila['cantidad_ejecutada'] = (float) $fila['cantidad_ejecutada'];
        $fila['porcentaje_avance'] = (float) $fila['porcentaje_avance'];
        return $fila;
    }

    private function json(int $codigo, mixed $cuerpo): void
    {
        http_response_code($codigo);
        echo json_encode($cuerpo, JSON_UNESCAPED_UNICODE);
    }
}
