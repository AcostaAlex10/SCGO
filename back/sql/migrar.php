<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Sgso\Env;
use Sgso\Migraciones\Migrador;
use Sgso\Seguridad\OpcionesTls;

/**
 * Aplica las migraciones pendientes a la base configurada por variables de
 * entorno (DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASSWORD, DB_SSL, DB_SSL_CA).
 *
 * Sobre una base vacía crea el esquema desde schema.sql; sobre una que ya
 * existe, registra las versiones base como aplicadas y sigue con lo que falte
 * (plan de producto, B-03). Correrlo varias veces no rompe nada: aplica solo lo
 * que no está en `schema_migrations`.
 *
 * **No corre solo en el deploy**: Render levanta Apache y nada más. Después de
 * un deploy que traiga migraciones, hay que correrlo a mano.
 *
 * Uso (PowerShell):
 *   $env:DB_HOST="..."; $env:DB_PORT="..."; ...; php back/sql/migrar.php
 *
 * Para mirar una base sin tocarla (no crea ni `schema_migrations`):
 *   php back/sql/migrar.php --estado
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

$migrador = new Migrador($pdo, __DIR__ . '/migraciones', __DIR__ . '/schema.sql');

// --estado solo informa: sirve para mirar una base antes de tocarla.
if (in_array('--estado', $argv, true)) {
    $estado = $migrador->estado();
    echo 'Aplicadas:  ' . ($estado['aplicadas'] === [] ? '(ninguna)' : implode(', ', $estado['aplicadas'])) . PHP_EOL;
    echo 'Pendientes: ' . ($estado['pendientes'] === [] ? '(ninguna)' : implode(', ', $estado['pendientes'])) . PHP_EOL;
    if ($estado['baseExistenteSinRegistro']) {
        echo 'Nota: la base ya tiene tablas pero ningun registro. La primera corrida va a anotar '
            . implode(', ', Migrador::VERSIONES_BASE) . ' como aplicadas, SIN ejecutarlas.' . PHP_EOL;
    }
    exit(0);
}

// Cada linea sale apenas ocurre: si una migracion falla, queda a la vista cual
// fue y cuales ya se habian aplicado antes.
try {
    $migrador->aplicarPendientes(static function (string $linea): void {
        echo $linea . PHP_EOL;
    });
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    fwrite(STDERR, 'Lo que figura arriba como aplicado, quedo aplicado. Corregi la causa y volve a correr: ' . PHP_EOL
        . 'las migraciones son idempotentes. Para ver como quedo la base: php back/sql/migrar.php --estado' . PHP_EOL);
    exit(1);
}
