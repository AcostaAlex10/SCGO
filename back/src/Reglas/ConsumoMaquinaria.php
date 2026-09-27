<?php

declare(strict_types=1);

namespace Sgso\Reglas;

/**
 * Cuándo un registro de uso de una máquina consume de más (RF24).
 *
 * Cada registro se compara con el combustible por hora promedio de su máquina,
 * sobre todos sus registros con horas: si lo supera en más de FACTOR veces, es
 * anómalo. La usan el listado de la máquina y el feed de Alertas (D-03), que
 * así no pueden discrepar. La misma regla está en el simulador
 * (`FRONT/src/app/mock/servidor.ts`).
 *
 * Limitación conocida (REQUERIMIENTOS, desvío 2): una máquina que siempre
 * consume de más no alerta nunca, porque no se aparta de su propio promedio.
 * Compararla con un consumo esperado pide guardar ese dato (plan: D-13).
 */
final class ConsumoMaquinaria
{
    public const FACTOR = 1.5;

    /** Combustible por hora; 0 si no hubo horas de uso. */
    public static function porHora(float $combustible, float $horas): float
    {
        return $horas > 0 ? $combustible / $horas : 0.0;
    }

    /** Sin promedio (ningún registro con horas y combustible) no hay contra qué comparar. */
    public static function esAnomalo(float $porHora, float $promedio): bool
    {
        return $promedio > 0 && $porHora > $promedio * self::FACTOR;
    }
}
