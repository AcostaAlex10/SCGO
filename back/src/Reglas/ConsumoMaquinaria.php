<?php

declare(strict_types=1);

namespace Sgso\Reglas;

use DateTimeImmutable;

/**
 * Cuándo un registro de uso de una máquina consume de más (RF24).
 *
 * Cada registro se compara con una referencia de combustible por hora: el
 * consumo esperado de la máquina (litros por hora, lo carga Gestión de obra) si
 * lo tiene, y si no, el promedio de todos sus registros con horas. Si lo supera
 * en más de FACTOR veces, es anómalo. La usan el listado de la máquina y el feed
 * de Alertas (D-03), que así no pueden discrepar. La misma regla está en el
 * simulador (`FRONT/src/app/mock/servidor.ts`).
 *
 * La ventana (D-13) decide qué registros alertan: solo los de los últimos
 * VENTANA_DIAS días, contados hacia atrás desde la fecha local. Un registro
 * anómalo deja de alertar cuando sale de la ventana. La ventana NO achica el
 * promedio: sin dato esperado, el promedio sigue saliendo de todos los
 * registros de la máquina.
 */
final class ConsumoMaquinaria
{
    public const FACTOR = 1.5;

    /**
     * Días de la ventana. El borde es inclusivo: un registro de hace exactamente
     * VENTANA_DIAS días todavía entra; uno de hace VENTANA_DIAS + 1, no.
     */
    public const VENTANA_DIAS = 30;

    /** La fecha local con la que se cuenta la ventana (Render corre en UTC). */
    public const ZONA = 'America/Argentina/Buenos_Aires';

    /** Tope de `maquinaria.consumo_esperado_por_hora`, DECIMAL(8,2). */
    public const ESPERADO_MAXIMO = 999999.99;

    /** Combustible por hora; 0 si no hubo horas de uso. */
    public static function porHora(float $combustible, float $horas): float
    {
        return $horas > 0 ? $combustible / $horas : 0.0;
    }

    /**
     * Contra qué se compara un registro: el consumo esperado si la máquina lo
     * tiene (mayor a 0), y si no, el promedio.
     */
    public static function referencia(?float $esperado, float $promedio): float
    {
        return $esperado !== null && $esperado > 0 ? $esperado : $promedio;
    }

    /** Sin referencia (0) no hay contra qué comparar. */
    public static function esAnomalo(float $porHora, float $referencia): bool
    {
        return $referencia > 0 && $porHora > $referencia * self::FACTOR;
    }

    /** Primer día de la ventana, `YYYY-MM-DD`, contando hacia atrás desde `$hoy`. */
    public static function desde(DateTimeImmutable $hoy): string
    {
        return $hoy->modify('-' . self::VENTANA_DIAS . ' days')->format('Y-m-d');
    }

    /** Si la fecha (`YYYY-MM-DD`) cae en la ventana que empieza en `$desde`, borde inclusive. */
    public static function enVentana(string $fecha, string $desde): bool
    {
        return $fecha >= $desde;
    }

    /**
     * La decisión completa para un registro: con horas, dentro de la ventana y
     * por encima de la referencia. Es la única que usan el listado y el feed.
     */
    public static function marcaAlerta(string $fecha, float $horas, float $combustible, float $referencia, string $desde): bool
    {
        return $horas > 0
            && self::enVentana($fecha, $desde)
            && self::esAnomalo(self::porHora($combustible, $horas), $referencia);
    }

    /** Un número como lo lee el usuario: coma decimal y sin ceros de más (`1,5`, `8`). */
    public static function formatear(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, ',', ''), '0'), ',');
    }

    /** Con qué se comparó, para el texto de la alerta. */
    public static function textoDeReferencia(?float $esperado): string
    {
        return $esperado !== null && $esperado > 0
            ? 'esperado de la máquina (' . self::formatear($esperado) . ' L/h)'
            : 'promedio de la máquina';
    }
}
