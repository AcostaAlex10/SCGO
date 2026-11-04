<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Sgso\Env;
use Sgso\Seguridad\OpcionesTls;

/**
 * Convierte proyecto.estado de VARCHAR(30) a ENUM con los siete estados del
 * ciclo de vida del TP3.
 *
 * Hace falta un script aparte porque migrar.php solo ejecuta schema.sql, y
 * todas sus tablas usan CREATE TABLE IF NOT EXISTS: sobre una base que ya
 * existe, cambiar el tipo de una columna en schema.sql no tiene ningun efecto.
 * Sin esto, el ENUM solo aplicaria a instalaciones nuevas.
 *
 * Se puede correr mas de una vez: si la columna ya es un ENUM, no toca nada.
 *
 * Uso, con las credenciales en back/.env (copiado de back/.env.example):
 *   php back/sql/migracion-estado-enum.php
 *
 * Tambien sirven las variables de entorno, y tienen prioridad sobre back/.env:
 *   DB_HOST=... DB_PORT=... DB_NAME=... DB_USER=... DB_PASSWORD=... DB_SSL=true \
 *     php back/sql/migracion-estado-enum.php
 */

/** Los siete estados, en la convencion snake_case de la base. */
const ESTADOS = [
    'creada',        // TP3: Creado
    'planificacion', // TP3: Planificado
    'en_ejecucion',  // TP3: EnEjecucion
    'pausada',       // TP3: Pausado
    'en_revision',   // TP3: EnRevision
    'finalizada',    // TP3: Finalizado
    'cancelada',     // TP3: Cancelado
];

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
          2. O defini DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASSWORD y DB_SSL
             en la MISMA consola desde la que corres este script.

        TXT);
    }
    fwrite(STDERR, 'Detalle: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

// 1. Si ya es el ENUM, no hay nada que hacer.
$stmt = $pdo->prepare(
    'SELECT COLUMN_TYPE FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ?
        AND COLUMN_NAME = ?'
);
$stmt->execute(['proyecto', 'estado']);
$tipo = $stmt->fetchColumn();

if ($tipo === false) {
    fwrite(STDERR, "No existe proyecto.estado. Corre primero: php back/sql/migrar.php\n");
    exit(1);
}
if (str_starts_with(strtolower((string) $tipo), 'enum(')) {
    echo "proyecto.estado ya es un ENUM.\n";
    echo "  {$tipo}\n";
    exit(0);
}

// 2. Ninguna fila puede tener un estado fuera de la lista. MySQL convertiria
//    esos valores en cadena vacia sin avisar, y la obra quedaria sin estado.
$actuales = $pdo->query('SELECT DISTINCT estado FROM proyecto')->fetchAll(PDO::FETCH_COLUMN);
$fuera = array_values(array_diff($actuales, ESTADOS));

if ($fuera !== []) {
    fwrite(STDERR, "Hay obras con un estado que no entra en el ENUM:\n");
    foreach ($fuera as $valor) {
        fwrite(STDERR, "  - '{$valor}'\n");
    }
    fwrite(STDERR, "Corregilos antes de migrar; si no, esas obras se quedarian sin estado.\n");
    exit(1);
}

// 3. Alterar.
$lista = "'" . implode("','", ESTADOS) . "'";
$pdo->exec("ALTER TABLE proyecto MODIFY estado ENUM({$lista}) NOT NULL DEFAULT 'planificacion'");

echo "Listo. proyecto.estado ahora acepta solo: " . implode(', ', ESTADOS) . "\n";
echo "Estados en uso: " . ($actuales === [] ? 'ninguno (tabla vacia)' : implode(', ', $actuales)) . "\n";
