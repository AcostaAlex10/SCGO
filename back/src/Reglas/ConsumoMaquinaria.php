<?php

declare(strict_types=1);

namespace Sgso\Reglas;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

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
 * La comparación no usa coma flotante: litros y horas se guardan con dos
 * decimales (DECIMAL) y se cuentan en centésimos, como `StockMaterial`. La
 * referencia es una fracción de enteros (litros en centésimos sobre horas en
 * centésimos): el promedio no siempre tiene dos decimales, y así "justo 1,5
 * veces" no alerta ni "7,66 contra 7,65" deja de alertar por un error de
 * redondeo. Un registro anómalo es el que cumple `2 * litros * den > 3 * num * horas`.
 * Los enteros de PHP pasan a float recién por encima de 9×10^18, lejos de
 * cualquier dato real.
 *
 * La ventana (D-13) decide qué registros alertan: solo los de los últimos
 * VENTANA_DIAS días, de hace VENTANA_DIAS días a hoy, ambos inclusive, contados
 * desde la fecha local. Un registro anómalo deja de alertar cuando sale de la
 * ventana, y uno con fecha futura no alerta (se puede cargar, pero no está en
 * los últimos 30 días). La ventana NO achica el promedio: sin dato esperado, el
 * promedio sigue saliendo de todos los registros de la máquina.
 */
final class ConsumoMaquinaria
{
    public const FACTOR = 1.5;

    /**
     * Días de la ventana. Los bordes son inclusivos: un registro de hace exactamente
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

    /** Un valor de dos decimales (litros u horas de la base) en centésimos enteros. */
    public static function centesimos(float|string $valor): int
    {
        return (int) round((float) $valor * 100);
    }

    /**
     * La referencia como fracción exacta, `[litros, horas]` en centésimos: el
     * esperado si la máquina lo tiene (x litros en 1 hora) y si no, el promedio
     * (suma de litros sobre suma de horas de todos sus registros con horas).
     *
     * @return array{0: int, 1: int}
     */
    public static function fraccionDeReferencia(?float $esperado, int $sumaLitros, int $sumaHoras): array
    {
        return $esperado !== null && $esperado > 0
            ? [self::centesimos($esperado), 100]
            : [$sumaLitros, $sumaHoras];
    }

    /** `$hoy` a medianoche en la zona local, a partir de un instante cualquiera (en UTC o donde sea). */
    public static function hoy(DateTimeInterface $instante): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($instante)
            ->setTimezone(new DateTimeZone(self::ZONA))
            ->setTime(0, 0);
    }

    /** Primer día de la ventana, `YYYY-MM-DD`, contando hacia atrás desde `$hoy`. */
    public static function desde(DateTimeImmutable $hoy): string
    {
        return $hoy->modify('-' . self::VENTANA_DIAS . ' days')->format('Y-m-d');
    }

    /** Último día de la ventana (hoy), a partir de su primer día. */
    public static function hasta(string $desde): string
    {
        return (new DateTimeImmutable($desde))->modify('+' . self::VENTANA_DIAS . ' days')->format('Y-m-d');
    }

    /**
     * Si la fecha (`YYYY-MM-DD`) cae en la ventana que empieza en `$desde`: de
     * ese día a hoy, ambos inclusive. Una fecha futura queda afuera.
     */
    public static function enVentana(string $fecha, string $desde): bool
    {
        return $fecha >= $desde && $fecha <= self::hasta($desde);
    }

    /**
     * La decisión completa para un registro, sin coma flotante: con horas,
     * dentro de la ventana y por encima de la referencia (`[litros, horas]` en
     * centésimos, de `fraccionDeReferencia`). Es la única entrada: la usan el
     * listado y el feed. Sin horas (en centésimos: 0,004 h se guarda como 0,00)
     * o sin referencia no hay contra qué comparar.
     *
     * @param array{0: int, 1: int} $referencia
     */
    public static function marcaAlerta(string $fecha, float $horas, float $combustible, array $referencia, string $desde): bool
    {
        [$num, $den] = $referencia;
        $horasC = self::centesimos($horas);
        if ($horasC <= 0 || $num <= 0 || $den <= 0 || !self::enVentana($fecha, $desde)) {
            return false;
        }

        // litros / horas > FACTOR * num / den, con FACTOR = 3/2. Litros y horas
        // estan en centesimos y se compensan; num / den ya es litros por hora.
        return 2 * self::centesimos($combustible) * $den > 3 * $num * $horasC;
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
