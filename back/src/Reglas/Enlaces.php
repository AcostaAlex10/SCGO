<?php

declare(strict_types=1);

namespace Sgso\Reglas;

/**
 * Qué enlace externo se puede guardar y mostrar (plan de producto, A-01).
 *
 * Los documentos de una obra se guardan como URL y la interfaz los muestra como
 * un enlace que el usuario abre. Si esa URL usa el esquema `javascript:`, abrirla
 * ejecuta código en la sesión de quien hace clic: un técnico podría plantar un
 * "documento" que le robe la sesión a un administrador.
 *
 * `FILTER_VALIDATE_URL` sola no alcanza: acepta `javascript://x%0Aalert(1)`,
 * porque sintácticamente es una URL válida. Por eso se exige además que el
 * esquema sea http o https y que haya un host.
 *
 * La misma regla está en el frontend (`FRONT/src/app/enlaces.ts`), que la
 * aplica al guardar en el simulador y **al mostrar**: así también quedan
 * neutralizados los enlaces que ya estuvieran guardados antes de este control.
 */
final class Enlaces
{
    /** Los únicos esquemas que se pueden abrir sin ejecutar nada. */
    public const ESQUEMAS_PERMITIDOS = ['http', 'https'];

    public static function esSeguro(string $url): bool
    {
        // Espacios o caracteres de control en cualquier lugar: son la vía
        // clásica para colar un esquema que el navegador después normaliza.
        if ($url === '' || preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
            return false;
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $partes = parse_url($url);
        if (!is_array($partes)) {
            return false;
        }

        $esquema = strtolower($partes['scheme'] ?? '');
        $host = $partes['host'] ?? '';

        return in_array($esquema, self::ESQUEMAS_PERMITIDOS, true) && $host !== '';
    }
}
