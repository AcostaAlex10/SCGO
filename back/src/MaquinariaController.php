<?php

declare(strict_types=1);

namespace Sgso;

use DateTimeImmutable;
use PDO;
use Sgso\Http\Paginacion;
use Sgso\Reglas\ConsumoMaquinaria;
use Sgso\Reglas\Fecha;

/**
 * Gestion de maquinaria. Cubre:
 *  - RF23: registro de uso (horas, combustible, produccion) por maquina.
 *  - RF27: historial de fallas y reemplazos de componentes.
 *  - RF28: comparativa de rendimiento entre operarios (produccion/hora).
 *  - RF24: alerta cuando el consumo de combustible por hora de un registro
 *          supera notablemente el consumo esperado de esa maquina (D-13) o, si
 *          no lo tiene cargado, su promedio. Solo alertan los registros de los
 *          ultimos ConsumoMaquinaria::VENTANA_DIAS dias.
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
            'SELECT m.id_maquinaria, m.nombre, m.tipo, m.activa, m.consumo_esperado_por_hora,
                    COALESCE(SUM(r.horas_uso),0) AS horas,
                    COALESCE(SUM(r.combustible_consumido),0) AS combustible,
                    COALESCE(SUM(CASE WHEN r.horas_uso > 0 THEN r.combustible_consumido ELSE 0 END),0) AS combustible_con_horas,
                    COALESCE(SUM(r.produccion_realizada),0) AS produccion
             FROM maquinaria m
             LEFT JOIN registro_maquinaria r ON r.id_maquinaria = m.id_maquinaria
             GROUP BY m.id_maquinaria, m.nombre, m.tipo, m.activa, m.consumo_esperado_por_hora
             ORDER BY m.nombre'
        );
        $fallas = $this->fallasAbiertasPorMaquina();

        $out = array_map(function (array $m) use ($fallas): array {
            $horas = (float) $m['horas'];
            $comb = (float) $m['combustible'];
            // El L/h real sale solo de los registros con horas, como la referencia
            // de la alerta: el combustible cargado sin horas no tiene por hora.
            $combConHoras = (float) $m['combustible_con_horas'];
            $prod = (float) $m['produccion'];
            return [
                'id_maquinaria' => (int) $m['id_maquinaria'],
                'nombre' => $m['nombre'],
                'tipo' => $m['tipo'],
                'activa' => (int) $m['activa'] === 1,
                'consumo_esperado_por_hora' => self::esperadoDe($m['consumo_esperado_por_hora']),
                'horas' => $horas,
                'combustible' => $comb,
                'produccion' => $prod,
                'combustible_por_hora' => $horas > 0 ? round($combConHoras / $horas, 2) : 0,
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
        // Opcional: sin el campo (o vacio) la maquina queda sin dato esperado.
        [$esperado, $errorEsperado] = self::validarEsperado($datos['consumo_esperado_por_hora'] ?? null);
        if ($errorEsperado !== null) { $errores['consumo_esperado_por_hora'] = $errorEsperado; }
        if (!empty($errores)) { $this->json(422, ['errors' => $errores]); return; }

        $stmt = $this->db->prepare('INSERT INTO maquinaria (nombre, tipo, consumo_esperado_por_hora) VALUES (?, ?, ?)');
        $stmt->execute([$nombre, $tipo, $esperado]);
        $this->json(201, [
            'id_maquinaria' => (int) $this->db->lastInsertId(),
            'nombre' => $nombre,
            'tipo' => $tipo,
            'consumo_esperado_por_hora' => $esperado,
        ]);
    }

    /**
     * PUT /api/maquinaria/{id}  -> carga, cambia o borra el consumo esperado (D-13).
     * Solo toca ese dato: el nombre, el tipo y el resto de la maquina no cambian.
     * `null` o vacio lo borra; que el campo falte es un error, para que un
     * cuerpo vacio no borre el dato sin querer.
     */
    public function actualizar(string $id, array $datos): void
    {
        // '12abc' no es la maquina 12: MySQL compararia '12abc' = 12 como verdadero.
        if (preg_match('/^[0-9]+\z/', $id) !== 1) { $this->json(404, ['error' => 'Maquinaria no encontrada']); return; }
        if (!array_key_exists('consumo_esperado_por_hora', $datos)) {
            $this->json(422, ['errors' => ['consumo_esperado_por_hora' => 'Obligatorio (null para borrar el dato)']]);
            return;
        }
        [$esperado, $error] = self::validarEsperado($datos['consumo_esperado_por_hora']);
        if ($error !== null) { $this->json(422, ['errors' => ['consumo_esperado_por_hora' => $error]]); return; }

        // Se busca antes porque, con MySQL, un UPDATE que no cambia nada
        // devuelve rowCount 0 igual que una maquina inexistente.
        if (!$this->existeMaquina($id)) { $this->json(404, ['error' => 'Maquinaria no encontrada']); return; }

        $stmt = $this->db->prepare('UPDATE maquinaria SET consumo_esperado_por_hora = ? WHERE id_maquinaria = ?');
        $stmt->execute([$esperado, $id]);
        $this->json(200, ['id_maquinaria' => (int) $id, 'consumo_esperado_por_hora' => $esperado]);
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
        // Promedio de combustible/hora de la maquina (para RF24). Sale de todos
        // sus registros con horas, no solo de los de la ventana.
        $stmt = $this->db->prepare(
            'SELECT COALESCE(SUM(combustible_consumido),0) AS c, COALESCE(SUM(horas_uso),0) AS h
             FROM registro_maquinaria WHERE id_maquinaria = ? AND horas_uso > 0'
        );
        $stmt->execute([$idMaq]);
        $tot = $stmt->fetch();
        // Se cuenta en centesimos (enteros), como el feed de Alertas: litros y horas
        // son DECIMAL de dos decimales, y asi los dos llegan a la misma decision.
        $sumaLitros = ConsumoMaquinaria::centesimos($tot['c']);
        $sumaHoras = ConsumoMaquinaria::centesimos($tot['h']);

        $stmt = $this->db->prepare('SELECT consumo_esperado_por_hora FROM maquinaria WHERE id_maquinaria = ?');
        $stmt->execute([$idMaq]);
        $esperado = self::esperadoDe($stmt->fetchColumn() ?: null);
        $referencia = ConsumoMaquinaria::fraccionDeReferencia($esperado, $sumaLitros, $sumaHoras);
        $desde = ConsumoMaquinaria::desde(ConsumoMaquinaria::hoy(new DateTimeImmutable()));

        // La referencia de arriba es sobre todos los registros, no sobre la pagina.
        $sql = 'SELECT * FROM registro_maquinaria WHERE id_maquinaria = ? ORDER BY fecha DESC, id_registro DESC';
        if ($pagina !== null) {
            $sql .= $pagina->aplicar($this->db, 'SELECT COUNT(*) FROM registro_maquinaria WHERE id_maquinaria = ?', [$idMaq]);
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$idMaq]);
        $out = array_map(function (array $r) use ($referencia, $desde, $esperado): array {
            $horas = (float) $r['horas_uso'];
            $cph = ConsumoMaquinaria::porHora((float) $r['combustible_consumido'], $horas);
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
                'consumo_esperado_por_hora' => $esperado,
                'alerta_consumo' => ConsumoMaquinaria::marcaAlerta(
                    (string) $r['fecha'],
                    $horas,
                    (float) $r['combustible_consumido'],
                    $referencia,
                    $desde
                ),
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
        if (!Fecha::esValida($fecha)) { $errores['fecha'] = 'Formato esperado: YYYY-MM-DD'; }

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
        if (!Fecha::esValida($fecha)) { $errores['fecha'] = 'Formato esperado: YYYY-MM-DD'; }
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

    /**
     * PUT /api/maquinaria/falla/{id}  -> marca la falla resuelta o la reabre.
     * Antes la unica forma de bajar el contador de fallas abiertas era borrarla,
     * y con ella el historial que pide RF27 (D-11).
     */
    public function actualizarFalla(string $id, array $datos): void
    {
        if (!is_bool($datos['resuelto'] ?? null)) { $this->json(422, ['errors' => ['resuelto' => 'Debe ser true o false']]); return; }

        // Se busca antes porque, con MySQL, un UPDATE que no cambia nada
        // devuelve rowCount 0 igual que una falla inexistente.
        $stmt = $this->db->prepare('SELECT id_falla FROM falla_maquinaria WHERE id_falla = ?');
        $stmt->execute([$id]);
        if ($stmt->fetch() === false) { $this->json(404, ['error' => 'Falla no encontrada']); return; }

        $stmt = $this->db->prepare('UPDATE falla_maquinaria SET resuelto = ? WHERE id_falla = ?');
        $stmt->execute([$datos['resuelto'] ? 1 : 0, $id]);
        $this->json(200, ['id_falla' => (int) $id, 'resuelto' => $datos['resuelto']]);
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

    /** El dato esperado como numero, o null: DECIMAL llega como texto y 0 no es un dato. */
    private static function esperadoDe(mixed $valor): ?float
    {
        return $valor === null || (float) $valor <= 0 ? null : (float) $valor;
    }

    /**
     * Valida el consumo esperado que llega en el cuerpo.
     *
     * Ausente, `null` o vacio (o solo espacios) es "sin dato". Si hay dato, tiene que ser un numero
     * mayor a 0 que entre en DECIMAL(8,2); se guarda con dos decimales.
     *
     * @return array{0: ?float, 1: ?string} el valor y el error, uno de los dos null (o los dos, sin dato)
     */
    private static function validarEsperado(mixed $valor): array
    {
        if ($valor === null || (is_string($valor) && trim($valor) === '')) {
            return [null, null];
        }
        if (!is_numeric($valor) || round((float) $valor, 2) <= 0) {
            return [null, 'Debe ser un número mayor a 0'];
        }
        if ((float) $valor > ConsumoMaquinaria::ESPERADO_MAXIMO) {
            return [null, 'Debe ser un número menor o igual a 999999,99'];
        }

        return [round((float) $valor, 2), null];
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
