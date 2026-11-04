<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Sgso\Env;
use Sgso\Seguridad\OpcionesTls;

/**
 * Agrega reporte.es_final, la marca que distingue el reporte de cierre de la
 * obra de un parte diario comun.
 *
 * Hace falta un script aparte porque migrar.php solo ejecuta schema.sql, y
 * todas sus tablas usan CREATE TABLE IF NOT EXISTS: sobre una base que ya
 * existe, agregar una columna en schema.sql no tiene ningun efecto. Sin esto,
 * la columna solo aparece en instalaciones nuevas.
 *
 * Se puede correr mas de una vez: si la columna ya existe, no toca nada.
 *
 * No modifica ningun reporte existente. Todos quedan con es_final = 0, que es
 * lo correcto: ninguno se cargo con la intencion de cerrar una obra.
 *
 * Uso, con las credenciales en back/.env (copiado de back/.env.example):
 *   php back/sql/migracion-reporte-final.php
 *
 * Tambien sirven las variables de entorno, y tienen prioridad sobre back/.env:
 *   DB_HOST=... DB_PORT=... DB_NAME=... DB_USER=... DB_PASSWORD=... DB_SSL=true DB_SSL_CA=... \
 *     php back/sql/migracion-reporte-final.php
 */

// Credenciales: primero back/.env, despues el entorno real. Env::cargar NO pisa
// variables ya definidas, asi que en Render y en CI sigue mandando el entorno.
// Sin esto habia que exportar cinco variables a mano en la misma consola, y
// olvidarse de una hacia que el script cayera en los valores por defecto
// (127.0.0.1 / root) y fallara con un error de conexion enganoso.
Env::cargar(__DIR__ . '/../.env');

$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = getenv('DB_PORT') ?: '3306';
$db   = getenv('DB_NAME') ?: 'sgso';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASSWORD') ?: '';

$dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
$opciones = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
try {
    $opciones += OpcionesTls::desde(getenv('DB_SSL') ?: null, getenv('DB_SSL_CA') ?: null);
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}

try {
    $pdo = new PDO($dsn, $user, $pass, $opciones);
} catch (PDOException $e) {
    // Un PDOException crudo no dice por que fallo. El caso tipico es que no se
    // encontraron las credenciales y el script cayo en los valores por defecto,
    // que es lo que hace ruido: el mensaje habla de 127.0.0.1 y de 'root' aunque
    // la base real este en Aiven.
    fwrite(STDERR, "No pude conectar a {$host}:{$port} como '{$user}'." . PHP_EOL);
    if ($host === '127.0.0.1' && $user === 'root') {
        fwrite(STDERR, <<<TXT
        Esos son los valores por defecto: no se encontraron las credenciales.
        Opciones:
          1. Copia back/.env.example a back/.env y completalo (recomendado).
          2. O defini DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASSWORD, DB_SSL y DB_SSL_CA
             en la MISMA consola desde la que corres este script.

        TXT);
    }
    fwrite(STDERR, 'Detalle: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

// 1. La tabla tiene que existir.
$stmt = $pdo->prepare(
    'SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
);
$stmt->execute(['reporte']);
if ((int) $stmt->fetchColumn() === 0) {
    fwrite(STDERR, "No existe la tabla reporte. Corre primero: php back/sql/migrar.php\n");
    exit(1);
}

// 2. Si la columna ya esta, no hay nada que hacer.
$stmt = $pdo->prepare(
    'SELECT COLUMN_TYPE FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ?
        AND COLUMN_NAME = ?'
);
$stmt->execute(['reporte', 'es_final']);
$tipo = $stmt->fetchColumn();

if ($tipo !== false) {
    echo "reporte.es_final ya existe.\n";
    echo "  {$tipo}\n";
    exit(0);
}

// 3. Agregarla. Va despues de `estado`, junto al resto del ciclo de vida.
$pdo->exec(
    "ALTER TABLE reporte
        ADD COLUMN es_final TINYINT(1) NOT NULL DEFAULT 0 AFTER estado"
);

$total = (int) $pdo->query('SELECT COUNT(*) FROM reporte')->fetchColumn();

echo "Listo. reporte.es_final agregada.\n";
echo "Reportes existentes: {$total}, todos con es_final = 0.\n";
echo "Ninguna obra cambia de estado por esta migracion.\n";
