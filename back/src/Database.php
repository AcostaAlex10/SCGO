<?php

declare(strict_types=1);

namespace Sgso;

use PDO;
use Sgso\Seguridad\OpcionesTls;


/**
 * Conexion a MariaDB/MySQL mediante PDO (singleton).
 * Lee los datos de conexion de variables de entorno (ver .env.example).
 * En local/on-premise no hace falta SSL. Para una base en la nube que lo
 * exija, DB_SSL=true junto con DB_SSL_CA (ruta al certificado de la CA): se
 * cifra y se verifica el certificado. Sin DB_SSL_CA falla, no conecta a ciegas.
 */
final class Database
{
    private static ?PDO $pdo = null;

    public static function conexion(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $host = Env::get('DB_HOST', '127.0.0.1');
        $puerto = Env::get('DB_PORT', '3306');
        $nombre = Env::get('DB_NAME', 'sgso');
        $usuario = Env::get('DB_USER', 'root');
        $clave = Env::get('DB_PASSWORD', '');

        $dsn = "mysql:host={$host};port={$puerto};dbname={$nombre};charset=utf8mb4";

        $opciones = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        // Conexion cifrada y verificada para bases en la nube (ej. Aiven).
        $opciones += OpcionesTls::desde(Env::get('DB_SSL'), Env::get('DB_SSL_CA'));

        self::$pdo = new PDO($dsn, $usuario, $clave, $opciones);
        return self::$pdo;
    }
}
