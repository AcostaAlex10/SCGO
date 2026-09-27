<?php

declare(strict_types=1);

namespace Sgso;

/**
 * Verifica que una ubicacion (texto) corresponda a un lugar real, usando el
 * servicio gratuito de geocodificacion de OpenStreetMap (Nominatim).
 *
 * Politica de uso de Nominatim: maximo ~1 peticion por segundo y se exige un
 * User-Agent identificatorio. Para el volumen de este sistema alcanza de sobra.
 *
 * Criterio "fail-open": si el servicio no responde (red caida, timeout), NO se
 * bloquea el alta del proyecto (se asume valida), para no romper la app por una
 * caida externa.
 */
final class Geocoder
{
    public static function existe(string $ubicacion): bool
    {
        $ubicacion = trim($ubicacion);
        if ($ubicacion === '') {
            return false;
        }

        $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
            'format' => 'json',
            'limit' => 1,
            'q' => $ubicacion,
        ]);

        $contexto = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: SGSO-Grupo2/1.0 (proyecto academico UNaM)\r\n",
                'timeout' => 6,
            ],
        ]);

        $respuesta = @file_get_contents($url, false, $contexto);
        if ($respuesta === false) {
            // El servicio no respondio: no bloqueamos (fail-open).
            return true;
        }

        $resultados = json_decode($respuesta, true);
        return is_array($resultados) && count($resultados) > 0;
    }
}
