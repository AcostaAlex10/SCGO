<?php

declare(strict_types=1);

namespace Sgso;

use Closure;
use DateTimeImmutable;
use PDO;
use Sgso\Http\Paginacion;
use Sgso\Reglas\ProtocoloIncidencias;

/**
 * Controlador de Incidencias externas de obra (RF09): clima, fallas de
 * maquinaria, retrasos de proveedores, etc. La `gravedad` clasifica la
 * incidencia (RF26) y `dias_retraso` documenta su impacto en el cronograma,
 * lo que sirve para justificar extensiones de plazo (RF08). Segun la gravedad,
 * al registrarla se avisa por correo (RF26, D-02; ver ProtocoloIncidencias).
 */
final class IncidenciaController
{
    private const TIPOS = ['clima', 'falla_maquinaria', 'proveedor', 'otro'];
    private const GRAVEDADES = ['baja', 'media', 'alta'];
    private const ETIQUETAS_TIPO = [
        'clima' => 'Clima',
        'falla_maquinaria' => 'Falla de maquinaria',
        'proveedor' => 'Retraso de proveedor',
        'otro' => 'Otro',
    ];

    /** @var Closure(list<string>, string, string): bool */
    private Closure $enviarCorreo;

    /**
     * @param (Closure(list<string>, string, string): bool)|null $enviarCorreo
     *        como sale el aviso de RF26: por defecto, Mailer. Las pruebas pasan
     *        una que anota a quien se hubiera mandado.
     */
    public function __construct(private PDO $db, ?Closure $enviarCorreo = null)
    {
        $this->enviarCorreo = $enviarCorreo ?? Mailer::enviar(...);
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

    /**
     * POST /api/proyectos/{idProyecto}/incidencias
     *
     * @param array<string, mixed> $datos
     * @param array<string, mixed> $usuario el de la sesion, para decir en el aviso quien la cargo
     */
    public function crear(string $idProyecto, array $datos, array $usuario = []): void
    {
        // La obra debe existir.
        $stmt = $this->db->prepare('SELECT nombre FROM proyecto WHERE id_proyecto = ?');
        $stmt->execute([$idProyecto]);
        $obra = $stmt->fetchColumn();
        if ($obra === false) {
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
        // Antes de cualquier otra consulta: con MySQL, lastInsertId() vuelve a 0.
        $idIncidencia = (int) $this->db->lastInsertId();

        $incidencia = [
            'fecha' => (string) $datos['fecha'],
            'tipo' => (string) $datos['tipo'],
            'gravedad' => (string) $datos['gravedad'],
            'descripcion' => trim((string) $datos['descripcion']),
            'dias_retraso' => $diasRetraso,
        ];
        $avisados = $this->avisar($incidencia, $idProyecto, (string) $obra, $usuario);

        $this->json(201, ['id_incidencia' => $idIncidencia, 'id_proyecto' => (int) $idProyecto] + $incidencia + ['avisados' => $avisados]);
    }

    /**
     * RF26 (D-02): avisa por correo segun la gravedad, con un solo correo para
     * todos. Devuelve a cuantos se aviso: 0 si la gravedad no lo pide, si no hay
     * cuentas activas a quien avisar o si el correo fallo. Un corte del correo
     * no deshace la incidencia, que ya quedo guardada.
     *
     * @param array{fecha: string, tipo: string, gravedad: string, descripcion: string, dias_retraso: int} $incidencia
     * @param array<string, mixed> $usuario
     */
    private function avisar(array $incidencia, string $idProyecto, string $obra, array $usuario): int
    {
        $roles = ProtocoloIncidencias::rolesAAvisar($incidencia['gravedad']);
        if ($roles === []) {
            return 0;
        }

        $marcas = implode(', ', array_fill(0, count($roles), '?'));
        $stmt = $this->db->prepare("SELECT email FROM usuario WHERE activo = 1 AND rol IN ({$marcas}) ORDER BY email");
        $stmt->execute($roles);
        $para = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        if ($para === []) {
            return 0;
        }

        $autor = null;
        if (isset($usuario['id_usuario'])) {
            $stmt = $this->db->prepare('SELECT nombre FROM usuario WHERE id_usuario = ?');
            $stmt->execute([(int) $usuario['id_usuario']]);
            $nombre = $stmt->fetchColumn();
            $autor = $nombre === false ? null : (string) $nombre;
        }

        [$asunto, $html] = self::correoDeAviso($incidencia, $idProyecto, $obra, $autor);

        return ($this->enviarCorreo)($para, $asunto, $html) ? count($para) : 0;
    }

    /**
     * El asunto y el cuerpo del aviso. Todo lo que escribio un usuario va
     * escapado: el correo sale en nombre del sistema.
     *
     * @param array{fecha: string, tipo: string, gravedad: string, descripcion: string, dias_retraso: int} $incidencia
     * @return array{0: string, 1: string}
     */
    private static function correoDeAviso(array $incidencia, string $idProyecto, string $obra, ?string $autor): array
    {
        $e = static fn (string $texto): string => htmlspecialchars($texto, ENT_QUOTES, 'UTF-8');

        $datos = [
            'Obra' => $obra,
            'Tipo' => self::ETIQUETAS_TIPO[$incidencia['tipo']] ?? $incidencia['tipo'],
            'Fecha' => (new DateTimeImmutable($incidencia['fecha']))->format('d/m/Y'),
        ];
        if ($incidencia['dias_retraso'] > 0) {
            $datos['Días de retraso estimados'] = (string) $incidencia['dias_retraso'];
        }
        if ($autor !== null) {
            $datos['Cargada por'] = $autor;
        }

        $html = '<h2>Incidencia de gravedad ' . $e($incidencia['gravedad']) . '</h2>';
        foreach ($datos as $etiqueta => $valor) {
            $html .= '<p><strong>' . $e($etiqueta) . ':</strong> ' . $e($valor) . '</p>';
        }
        $html .= '<p>' . nl2br($e($incidencia['descripcion'])) . '</p>';

        $base = rtrim(getenv('APP_URL') ?: (getenv('CORS_ORIGIN') ?: ''), '/');
        if ($base !== '') {
            $html .= '<p><a href="' . $e($base . '/proyectos/' . $idProyecto) . '">Ver la obra en SCGO</a></p>';
        }

        return ["Incidencia de gravedad {$incidencia['gravedad']} en {$obra}", $html];
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
