<?php

declare(strict_types=1);

namespace Sgso;

use PDO;
use Sgso\Reglas\Certificacion;
use Sgso\Reglas\CicloDeVida;


/**
 * Implementacion de ProyectoRepositoryInterface con MariaDB/MySQL (PDO).
 * Es la version "real" que Juan dejo prevista en la interfaz: el
 * controlador no cambia, solo se cambia esta implementacion en index.php.
 *
 * La tabla `proyecto` (ver back/sql/schema.sql) usa los mismos campos
 * que la API; aqui solo mapeamos id_proyecto <-> id y fecha_inicio <->
 * fechaInicio para conservar el contrato que ya consume el frontend.
 */
final class MySqlProyectoRepository implements ProyectoRepositoryInterface
{
    public function __construct(private PDO $db)
    {
    }

    private const SELECT = 'SELECT id_proyecto AS id, nombre, tipo, ubicacion, encargado,
               fecha_inicio AS fechaInicio, estado, avance, presupuesto
        FROM proyecto';

    public function listar(?string $busqueda = null): array
    {
        if ($busqueda === null || trim($busqueda) === '') {
            $stmt = $this->db->query(self::SELECT . ' ORDER BY id_proyecto DESC');
            return array_map([self::class, 'normalizar'], $stmt->fetchAll());
        }

        $patron = '%' . mb_strtolower(trim($busqueda)) . '%';
        $stmt = $this->db->prepare(self::SELECT . '
            WHERE LOWER(nombre) LIKE ? OR LOWER(ubicacion) LIKE ?
            ORDER BY id_proyecto DESC');
        $stmt->execute([$patron, $patron]);
        return array_map([self::class, 'normalizar'], $stmt->fetchAll());
    }

    public function buscarPorId(string $id): ?array
    {
        $stmt = $this->db->prepare(self::SELECT . ' WHERE id_proyecto = ?');
        $stmt->execute([$id]);
        $fila = $stmt->fetch();
        return $fila === false ? null : self::normalizar($fila);
    }

    public function crear(array $datos): array
    {
        $stmt = $this->db->prepare(
            'INSERT INTO proyecto (nombre, tipo, ubicacion, encargado, fecha_inicio, estado, avance, presupuesto)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $datos['nombre'],
            $datos['tipo'],
            $datos['ubicacion'],
            $datos['encargado'],
            $datos['fechaInicio'],
            $datos['estado'] ?? CicloDeVida::INICIAL,
            (float) ($datos['avance'] ?? 0),
            (float) $datos['presupuesto'],
        ]);

        return $this->buscarPorId((string) $this->db->lastInsertId());
    }

    public function actualizar(string $id, array $datos): ?array
    {
        $actual = $this->buscarPorId($id);
        if ($actual === null) {
            return null;
        }

        $stmt = $this->db->prepare(
            'UPDATE proyecto SET nombre = ?, tipo = ?, ubicacion = ?, encargado = ?,
                    fecha_inicio = ?, estado = ?, avance = ?, presupuesto = ?
             WHERE id_proyecto = ?'
        );
        $stmt->execute([
            $datos['nombre'] ?? $actual['nombre'],
            $datos['tipo'] ?? $actual['tipo'],
            $datos['ubicacion'] ?? $actual['ubicacion'],
            $datos['encargado'] ?? $actual['encargado'],
            $datos['fechaInicio'] ?? $actual['fechaInicio'],
            $datos['estado'] ?? $actual['estado'],
            isset($datos['avance']) ? (float) $datos['avance'] : $actual['avance'],
            isset($datos['presupuesto']) ? (float) $datos['presupuesto'] : $actual['presupuesto'],
            $id,
        ]);

        return $this->buscarPorId($id);
    }

    public function eliminar(string $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM proyecto WHERE id_proyecto = ?');
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    public function existeDuplicado(string $nombre, string $ubicacion, ?string $idExcluido = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM proyecto WHERE LOWER(nombre) = LOWER(?) AND LOWER(ubicacion) = LOWER(?)';
        $parametros = [$nombre, $ubicacion];

        if ($idExcluido !== null) {
            $sql .= ' AND id_proyecto <> ?';
            $parametros[] = $idExcluido;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($parametros);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Ajusta tipos: id como string (como en el repo JSON) y numericos como float.
     * Suma el monto certificado a la fecha (RF15, D-09), que es un importe: el
     * controlador lo quita junto con el presupuesto para el Personal Tecnico.
     * @param array<string, mixed> $fila
     * @return array<string, mixed>
     */
    private static function normalizar(array $fila): array
    {
        $fila['id'] = (string) $fila['id'];
        $fila['avance'] = (float) $fila['avance'];
        $fila['presupuesto'] = (float) $fila['presupuesto'];
        $fila['certificado'] = Certificacion::monto($fila['presupuesto'], $fila['avance']);
        return $fila;
    }
}
