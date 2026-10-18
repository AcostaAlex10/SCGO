<?php

declare(strict_types=1);

namespace Sgso\Reglas;

/**
 * Cuánto material queda de una asignación y si un consumo entra en eso (RF10,
 * CU22). Un consumo mayor a lo que queda se rechaza: el material no está en la
 * obra, así que no se pudo haber usado (DEF-05 del informe de testing).
 *
 * Las cantidades se guardan con dos decimales (DECIMAL(12,2)), así que la
 * cuenta se hace en centésimos: en coma flotante, 3,3 + 3,3 + 3,4 da apenas más
 * de 10 y un consumo exacto parecería un exceso.
 *
 * El simulador repite la regla (`FRONT/src/app/mock/servidor.ts`).
 */
final class StockMaterial
{
    /** Lo que queda sin consumir; nunca negativo, aunque haya excesos viejos. */
    public static function restante(float $asignada, float $consumido): float
    {
        return max(0, self::centesimos($asignada) - self::centesimos($consumido)) / 100;
    }

    public static function alcanza(float $asignada, float $consumido, float $cantidad): bool
    {
        return self::centesimos($cantidad) <= self::centesimos($asignada) - self::centesimos($consumido);
    }

    private static function centesimos(float $cantidad): int
    {
        return (int) round($cantidad * 100);
    }
}
