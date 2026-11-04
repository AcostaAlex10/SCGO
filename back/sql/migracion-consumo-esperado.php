<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Sgso\Env;

/**
 * Agrega maquinaria.consumo_esperado_por_hora, el consumo de combustible que se
 * espera de cada maquina, en litros por hora (D-13, RF24). Con ese dato, la
 * alerta de consumo compara contra lo esperado y no contra el promedio de la
 * propia maquina.
 *
 * Hace falta un script aparte porque migrar.php solo ejecuta schema.sql, y
 * todas sus tablas usan CREATE TABLE IF NOT EXISTS: sobre una base que ya
 * existe, agregar una columna en schema.sql no tiene ningun efecto. Sin esto,
 * la columna solo aparece en instalaciones nuevas.
 *
 * Se puede correr mas de una vez: si la columna ya existe, no toca nada.
 *
 * No modifica ninguna maquina existente. Todas quedan con la columna en NULL
 * ("sin dato"), y siguen alertando contra su promedio hasta que Gestion de obra
 * cargue el consumo esperado desde la pantalla de Maquinaria.
 *
 * Orden: primero este script y despues desplegar la API. El codigo nuevo lee la
 * columna, asi que sin ella fallan GET /analisis (para todos los roles: Tablero y
 * Alertas), GET /maquinaria, GET /maquinaria/{id}/registros, POST /maquinaria y
 * PUT /maquinaria/{id}.
 *
 * Uso, con las credenciales en back/.env (copiado de back/.env.example):
 *   php back/sql/migracion-consumo-esperado.php
 *
 * Tambien sirven las variables de entorno, y tienen prioridad sobre back/.env:
 *   DB_HOST=... DB_PORT=... DB_NAME=... DB_USER=... DB_PASSWORD=... DB_SSL=true \
 *     php back/sql/migracion-consumo-esperado.php
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
if (getenv('DB_SSL') === 'true') {
    $opciones[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
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

// 1. La tabla tiene que existir.
$stmt = $pdo->prepare(
    'SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
);
$stmt->execute(['maquinaria']);
if ((int) $stmt->fetchColumn() === 0) {
    fwrite(STDERR, "No existe la tabla maquinaria. Corre primero: php back/sql/migrar.php\n");
    exit(1);
}

// 2. Si la columna ya esta, no hay nada que hacer.
$stmt = $pdo->prepare(
    'SELECT COLUMN_TYPE FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ?
        AND COLUMN_NAME = ?'
);
$stmt->execute(['maquinaria', 'consumo_esperado_por_hora']);
$tipo = $stmt->fetchColumn();

if ($tipo !== false) {
    echo "maquinaria.consumo_esperado_por_hora ya existe.\n";
    echo "  {$tipo}\n";
    exit(0);
}

// 3. Agregarla. Va despues de `activa`, al final de la tabla.
$pdo->exec(
    "ALTER TABLE maquinaria
        ADD COLUMN consumo_esperado_por_hora DECIMAL(8,2) NULL DEFAULT NULL AFTER activa"
);

$total = (int) $pdo->query('SELECT COUNT(*) FROM maquinaria')->fetchColumn();

echo "Listo. maquinaria.consumo_esperado_por_hora agregada.\n";
echo "Maquinas existentes: {$total}, todas sin dato esperado (NULL).\n";
echo "Siguen alertando contra su promedio hasta que se cargue el dato.\n";
