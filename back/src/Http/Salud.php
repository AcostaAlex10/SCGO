<?php

declare(strict_types=1);

namespace Sgso\Http;

use PDO;
use RuntimeException;

/**
 * Lo que contesta `/api/health` (plan de producto, B-05).
 *
 * Antes respondía `{"status":"ok"}` sin preguntarle nada a la base, así que un
 * monitor externo veía el sistema arriba aunque no pudiera atender una sola
 * consulta. Ahora ejecuta `SELECT 1` y solo dice "ok" si la base contesta.
 *
 * Si la base falla, esto no lo tapa: la excepción sigue hasta el manejador
 * global, que responde el 500 genérico con código de referencia y deja el
 * detalle en el log (A-04). Es la misma respuesta que da la API cuando la base
 * ni siquiera acepta la conexión, así que para quien mire desde afuera hay una
 * sola señal de "no anda", y nunca con detalles internos.
 */
final class Salud
{
    /**
     * @return array{status: string, db: string}
     * @throws RuntimeException si la base no devuelve 1
     */
    public static function comprobar(PDO $db): array
    {
        $resultado = $db->query('SELECT 1');
        $valor = $resultado === false ? false : $resultado->fetchColumn();

        // Según el driver, `1` llega como entero o como texto.
        if ((string) $valor !== '1') {
            throw new RuntimeException('La base no devolvió 1 al SELECT 1 del chequeo de salud');
        }

        return ['status' => 'ok', 'db' => 'ok'];
    }
}
