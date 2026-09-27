<?php

declare(strict_types=1);

namespace Sgso\Tests\Reglas;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sgso\Reglas\Permisos;
use Sgso\Reglas\ProtocoloIncidencias;

/** A quién se avisa de una incidencia según su gravedad (RF26, D-02). */
#[CoversClass(ProtocoloIncidencias::class)]
final class ProtocoloIncidenciasTest extends TestCase
{
    public function testUnaAltaAvisaAGerentesYAlPersonalAdministrativo(): void
    {
        self::assertSame(
            [Permisos::GERENTE, Permisos::PERSONAL_ADMINISTRATIVO],
            ProtocoloIncidencias::rolesAAvisar('alta')
        );
    }

    public function testUnaMediaAvisaSoloAlPersonalAdministrativo(): void
    {
        self::assertSame([Permisos::PERSONAL_ADMINISTRATIVO], ProtocoloIncidencias::rolesAAvisar('media'));
    }

    public function testUnaBajaNoAvisaANadie(): void
    {
        self::assertSame([], ProtocoloIncidencias::rolesAAvisar('baja'));
    }

    /** Una gravedad que no existe no avisa: la validación del controlador ya la rechaza antes. */
    public function testUnaGravedadDesconocidaNoAvisaANadie(): void
    {
        self::assertSame([], ProtocoloIncidencias::rolesAAvisar('urgente'));
    }

    /** El Técnico carga la incidencia y el Administrador del Sistema no opera obras. */
    public function testNuncaSeAvisaAlTecnicoNiAlAdministradorDelSistema(): void
    {
        foreach (['alta', 'media', 'baja'] as $gravedad) {
            $roles = ProtocoloIncidencias::rolesAAvisar($gravedad);
            self::assertNotContains(Permisos::PERSONAL_TECNICO, $roles);
            self::assertNotContains(Permisos::ADMINISTRADOR_SISTEMA, $roles);
        }
    }
}
