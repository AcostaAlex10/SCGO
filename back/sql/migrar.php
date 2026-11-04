<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Sgso\Env;
use Sgso\Seguridad\OpcionesTls;

/**
 * Ejecuta back/sql/schema.sql contra la base configurada por variables de
 * entorno (DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASSWORD, DB_SSL).
 *
 * Es idempotente: todas las tablas usan CREATE TABLE IF NOT EXISTS, por lo
 * que correrlo varias veces no rompe nada (solo crea lo que falte).
 *
 * Uso (PowerShell):
 *   $env:DB_HOST="..."; $env:DB_PORT="..."; ...; php back/sql/migrar.php
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
          2. O defini DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASSWORD y DB_SSL
             en la MISMA consola desde la que corres este script.

        TXT);
    }
    fwrite(STDERR, 'Detalle: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$sql = file_get_contents(__DIR__ . '/schema.sql');
// Quitamos los comentarios de linea (-- ...) para poder separar por ';'.
$sql = preg_replace('/^\s*--.*$/m', '', (string) $sql);

$sentencias = array_filter(array_map('trim', explode(';', (string) $sql)));
foreach ($sentencias as $sentencia) {
    if ($sentencia === '') {
        continue;
    }
    $pdo->exec($sentencia);
    $resumen = substr(preg_replace('/\s+/', ' ', $sentencia) ?? '', 0, 64);
    echo "OK  {$resumen}...\n";
}

echo "Migracion completa.\n";
