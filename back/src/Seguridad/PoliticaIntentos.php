<?php

declare(strict_types=1);

namespace Sgso\Seguridad;

/**
 * Cuánto hay que esperar después de varios logins fallidos (plan de producto, A-03).
 *
 * Sin límite, cualquiera puede probar contraseñas contra una cuenta sin freno.
 * La regla: los primeros fallos son gratis, porque cualquiera se equivoca; a
 * partir del quinto seguido, cada intento exige una espera que se duplica,
 * hasta un tope. Los fallos viejos se olvidan.
 *
 * Es pura: recibe los números y devuelve segundos. El tiempo lo pone quien la
 * llama, así se puede probar sin esperar.
 */
final class PoliticaIntentos
{
    /** Fallos seguidos que se toleran antes de empezar a frenar. */
    public const FALLOS_TOLERADOS = 5;

    /** La primera espera, en segundos. Después se duplica con cada fallo. */
    public const ESPERA_INICIAL = 60;

    /**
     * Tope de la espera. Limita el daño de quien bloquea a propósito la cuenta
     * de otro: como mucho, el dueño espera esto.
     */
    public const ESPERA_MAXIMA = 900;

    /** Pasado este tiempo sin fallos, el contador vuelve a cero. */
    public const VENTANA = 900;

    /**
     * Segundos que todavía hay que esperar antes de aceptar otro intento.
     *
     * @param int $fallos                    fallos seguidos registrados
     * @param int $segundosDesdeUltimoFallo  cuánto pasó desde el último
     */
    public static function segundosDeEspera(int $fallos, int $segundosDesdeUltimoFallo): int
    {
        if (self::olvidado($segundosDesdeUltimoFallo) || $fallos < self::FALLOS_TOLERADOS) {
            return 0;
        }

        return max(0, self::espera($fallos) - $segundosDesdeUltimoFallo);
    }

    /**
     * La espera que impone tener `$fallos` fallos seguidos: 60 s con 5, 120 s
     * con 6, 240 s con 7, hasta el tope.
     */
    public static function espera(int $fallos): int
    {
        if ($fallos < self::FALLOS_TOLERADOS) {
            return 0;
        }

        $duplicaciones = min($fallos - self::FALLOS_TOLERADOS, 10);

        return min(self::ESPERA_INICIAL * (2 ** $duplicaciones), self::ESPERA_MAXIMA);
    }

    /** Si el último fallo ya es lo bastante viejo como para no contarlo. */
    public static function olvidado(int $segundosDesdeUltimoFallo): bool
    {
        return $segundosDesdeUltimoFallo >= self::VENTANA;
    }
}
