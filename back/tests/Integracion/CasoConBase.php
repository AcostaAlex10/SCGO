<?php

declare(strict_types=1);

namespace Sgso\Tests\Integracion;

use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Base de las pruebas de integracion (ADR-001 fase 2b).
 *
 * Las pruebas de Sgso\Reglas no necesitan base porque las reglas son puras.
 * Estas si: verifican que los controladores, con SQL de verdad, terminen
 * dejando la obra en el estado que la regla dice. Es la diferencia entre
 * "la regla esta bien escrita" y "el sistema hace lo que la regla dice".
 *
 * SEGURIDAD. Estas pruebas VACIAN las tablas antes de cada prueba, asi que:
 *
 * 1. No leen `back/.env` ni usan `Sgso\Database`. La conexion sale solo de las
 *    variables `SGSO_TEST_DB_*`, que no existen en ningun entorno real.
 * 2. Si no estan definidas, la prueba se saltea en vez de conectarse a algo.
 * 3. El nombre de la base tiene que contener "test", o abortan. Esa es la
 *    barrera que impide que una configuracion distraida apunte a Aiven.
 *
 * En CI las define el servicio `mariadb` del workflow. En local:
 *
 *   SGSO_TEST_DB_NAME=sgso_test SGSO_TEST_DB_USER=root SGSO_TEST_DB_PASSWORD=... composer test
 */
abstract class CasoConBase extends TestCase
{
    private static ?PDO $pdo = null;
    private static bool $esquemaCargado = false;

    protected function setUp(): void
    {
        parent::setUp();

        $pdo = $this->base();
        $this->cargarEsquemaUnaVez($pdo);
        $this->vaciarTablas($pdo);
    }

    /** La conexion de pruebas. Saltea la prueba si no hay base configurada. */
    protected function base(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $nombre = (string) (getenv('SGSO_TEST_DB_NAME') ?: '');
        if ($nombre === '') {
            self::markTestSkipped(
                'No hay base de pruebas configurada. Definí SGSO_TEST_DB_NAME (y SGSO_TEST_DB_USER, '
                . 'SGSO_TEST_DB_PASSWORD, SGSO_TEST_DB_HOST, SGSO_TEST_DB_PORT si hacen falta). '
                . 'En CI las pone el servicio mariadb.'
            );
        }

        // Estas pruebas truncan todas las tablas: el nombre tiene que decir
        // que la base es descartable. Sin esta guarda, una variable mal puesta
        // vaciaria la base real.
        if (!str_contains($nombre, 'test')) {
            throw new RuntimeException(
                "La base de pruebas se vacia en cada prueba, asi que su nombre tiene que contener \"test\". Recibido: {$nombre}"
            );
        }

        $host = (string) (getenv('SGSO_TEST_DB_HOST') ?: '127.0.0.1');
        $puerto = (string) (getenv('SGSO_TEST_DB_PORT') ?: '3306');
        $usuario = (string) (getenv('SGSO_TEST_DB_USER') ?: 'root');
        $clave = (string) (getenv('SGSO_TEST_DB_PASSWORD') ?: '');

        self::$pdo = new PDO(
            "mysql:host={$host};port={$puerto};dbname={$nombre};charset=utf8mb4",
            $usuario,
            $clave,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );

        return self::$pdo;
    }

    /** El esquema real, el mismo que se despliega. Una sola vez por proceso. */
    private function cargarEsquemaUnaVez(PDO $pdo): void
    {
        if (self::$esquemaCargado) {
            return;
        }

        $sql = file_get_contents(__DIR__ . '/../../sql/schema.sql');
        if ($sql === false) {
            throw new RuntimeException('No se pudo leer back/sql/schema.sql');
        }

        $pdo->exec($sql);
        self::$esquemaCargado = true;
    }

    /** Cada prueba arranca con la base vacia: no dependen del orden entre si. */
    private function vaciarTablas(PDO $pdo): void
    {
        $tablas = $pdo->query(
            'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()'
        )->fetchAll(PDO::FETCH_COLUMN);

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tablas as $tabla) {
            $pdo->exec('TRUNCATE TABLE `' . $tabla . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /**
     * Corre un metodo de controlador y devuelve lo que habria contestado la API.
     *
     * Los controladores responden con http_response_code() + echo, que en CLI
     * funcionan igual: el codigo se puede leer y la salida se captura con el
     * buffer. Por eso se pueden probar sin levantar un servidor.
     *
     * @return array{codigo: int, cuerpo: mixed}
     */
    protected function capturar(callable $accion): array
    {
        http_response_code(200);
        ob_start();
        try {
            $accion();
        } finally {
            $salida = (string) ob_get_clean();
        }

        return [
            'codigo' => http_response_code() ?: 200,
            'cuerpo' => json_decode($salida, true),
        ];
    }

    // ----------------------------------------------------------------
    //  Datos de prueba
    // ----------------------------------------------------------------

    protected function crearUsuario(string $rol = 'AdministradorSistema'): int
    {
        $stmt = $this->base()->prepare(
            'INSERT INTO usuario (nombre, email, contrasena, rol) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute(['Prueba', uniqid('u', true) . '@sgso.test', password_hash('x', PASSWORD_BCRYPT), $rol]);

        return (int) $this->base()->lastInsertId();
    }

    protected function crearProyecto(string $estado = 'en_ejecucion', string $nombre = 'Obra de prueba'): int
    {
        $stmt = $this->base()->prepare(
            'INSERT INTO proyecto (nombre, tipo, ubicacion, encargado, fecha_inicio, estado, avance, presupuesto)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$nombre, 'Infraestructura Vial', 'Posadas', 'Ing. Prueba', '2026-01-15', $estado, 0, 1000000]);

        return (int) $this->base()->lastInsertId();
    }

    protected function crearReporte(
        int $idProyecto,
        int $idUsuario,
        bool $esFinal = false,
        string $estado = 'borrador'
    ): int {
        $stmt = $this->base()->prepare(
            'INSERT INTO reporte (id_proyecto, id_usuario, titulo, contenido, estado, es_final)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$idProyecto, $idUsuario, 'Reporte', 'Contenido', $estado, $esFinal ? 1 : 0]);

        return (int) $this->base()->lastInsertId();
    }

    protected function crearPeriodoInactividad(int $idProyecto, string $inicio, ?string $fin = null): int
    {
        $stmt = $this->base()->prepare(
            'INSERT INTO periodo_inactividad (id_proyecto, fecha_inicio, fecha_fin, motivo) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$idProyecto, $inicio, $fin, 'Lluvia']);

        return (int) $this->base()->lastInsertId();
    }

    /** El estado actual de la obra, que es lo que casi todas estas pruebas miran. */
    protected function estadoDeLaObra(int $idProyecto): string
    {
        $stmt = $this->base()->prepare('SELECT estado FROM proyecto WHERE id_proyecto = ?');
        $stmt->execute([$idProyecto]);

        return (string) $stmt->fetchColumn();
    }
}
