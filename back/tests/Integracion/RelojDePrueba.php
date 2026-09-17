<?php

declare(strict_types=1);

namespace Sgso\Tests\Integracion;

use Closure;

/**
 * Un reloj que avanza solo cuando la prueba lo pide.
 *
 * Las esperas del límite de login son de minutos: con el reloj real, probarlas
 * obligaría a dormir la prueba.
 */
final class RelojDePrueba
{
    private int $ahora = 1_800_000_000;

    public function avanzar(int $segundos): void
    {
        $this->ahora += $segundos;
    }

    /** @return Closure(): int */
    public function comoClosure(): Closure
    {
        return fn (): int => $this->ahora;
    }
}
