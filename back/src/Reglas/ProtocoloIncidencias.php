<?php

declare(strict_types=1);

namespace Sgso\Reglas;

/**
 * A quién se avisa por correo de una incidencia, según su gravedad (RF26, D-02).
 *
 * Lo decidió el equipo: una incidencia alta avisa a los Gerentes y al Personal
 * Administrativo; una media, solo al Personal Administrativo; una baja no manda
 * correo. En los tres casos queda registrada y visible en la obra.
 *
 * El simulador repite la regla (`FRONT/src/app/mock/servidor.ts`).
 */
final class ProtocoloIncidencias
{
    /** @return list<string> los roles a los que se avisa */
    public static function rolesAAvisar(string $gravedad): array
    {
        return match ($gravedad) {
            'alta' => [Permisos::GERENTE, Permisos::PERSONAL_ADMINISTRATIVO],
            'media' => [Permisos::PERSONAL_ADMINISTRATIVO],
            default => [],
        };
    }
}
