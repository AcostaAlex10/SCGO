<?php

declare(strict_types=1);

namespace Sgso\Reglas;

/**
 * Qué texto vale como fecha (plan de producto, C-13).
 *
 * Una fecha como `2026-02-31` tiene el formato correcto pero no existe: si llega
 * a la base, MariaDB la rechaza y el usuario ve un 500 en vez de un 422. Por eso
 * no alcanza con comprobar la forma: se comprueba también que exista en el
 * calendario. El año mínimo es 1000 porque es el rango de DATE de MariaDB
 * (1000-9999).
 *
 * La misma regla está en el simulador del frontend (`fechaValida` en
 * `FRONT/src/app/mock/servidor.ts`).
 */
final class Fecha
{
    /** `\z` y no `$`: así no se acepta un salto de línea al final. */
    public static function esValida(string $texto): bool
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})\z/', $texto, $m) === 1
            && (int) $m[1] >= 1000
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }
}
