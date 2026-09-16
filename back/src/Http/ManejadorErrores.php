<?php

declare(strict_types=1);

namespace Sgso\Http;

use Throwable;

/**
 * Qué ve el cliente cuando algo falla sin control (plan de producto, A-04).
 *
 * Un error sin atrapar llegaba al navegador tal cual: si la base se caía, el
 * mensaje de PDO mostraba el host y el usuario de la base, y la traza, las
 * rutas internas del servidor.
 *
 * Ahora el detalle queda en el log del servidor y el cliente recibe solo un
 * mensaje genérico con un código de referencia. Con ese código, soporte
 * encuentra el error exacto en el log sin que el usuario vea nada interno.
 */
final class ManejadorErrores
{
    public const MENSAJE = 'Error interno del servidor';

    /**
     * Registra el error completo y devuelve el cuerpo seguro para el cliente.
     *
     * @param callable(string): mixed $registrar  destino del detalle, en producción `error_log`
     * @return array{error: string, referencia: string}
     */
    public static function atender(Throwable $error, callable $registrar): array
    {
        $referencia = bin2hex(random_bytes(6));

        $registrar(sprintf(
            '[referencia %s] %s: %s en %s:%d',
            $referencia,
            $error::class,
            $error->getMessage(),
            $error->getFile(),
            $error->getLine()
        ));

        return [
            'error' => self::MENSAJE,
            'referencia' => $referencia,
        ];
    }
}
