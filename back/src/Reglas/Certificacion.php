<?php

declare(strict_types=1);

namespace Sgso\Reglas;

/**
 * El monto certificado de una obra a la fecha (RF15, D-09): su presupuesto por
 * el porcentaje de avance físico, redondeado al centavo.
 *
 * Antes lo calculaba el navegador y lo redondeaba al peso, mientras que el
 * análisis (RF13) mostraba el mismo monto como "ejecutado" redondeado al
 * centavo: la misma obra daba dos números distintos en dos pantallas. Un
 * cálculo con impacto económico vive acá, con pruebas, y las dos pantallas lo
 * leen de la API.
 *
 * El simulador repite la cuenta (`FRONT/src/app/mock/servidor.ts`).
 */
final class Certificacion
{
    public static function monto(float $presupuesto, float $avance): float
    {
        return round($presupuesto * $avance / 100, 2);
    }
}
