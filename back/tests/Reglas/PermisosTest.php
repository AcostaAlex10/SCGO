<?php

declare(strict_types=1);

namespace Sgso\Tests\Reglas;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sgso\Reglas\Permisos;

/**
 * Permisos por rol, RF19 (ADR-001 seccion 5.2).
 *
 * La matriz de abajo es la tabla de RF19 escrita una sola vez. Si alguien
 * agrega un rol a un grupo sin decidirlo, esto se pone rojo.
 */
#[CoversClass(Permisos::class)]
final class PermisosTest extends TestCase
{
    /**
     * Que rol puede que grupo. Es la fuente de verdad de esta prueba: se
     * compara contra las constantes, no se deriva de ellas.
     *
     * @return array<string, list<string>>
     */
    private const ESPERADO = [
        'GESTION_OBRA' => ['AdministradorSistema', 'PersonalAdministrativo'],
        'AVANCE' => ['AdministradorSistema', 'PersonalTecnico'],
        'DOC' => ['AdministradorSistema', 'PersonalAdministrativo', 'PersonalTecnico'],
        'REPORTE_APROBAR' => ['AdministradorSistema', 'PersonalAdministrativo'],
        'ADMIN' => ['AdministradorSistema'],
    ];

    /** @return iterable<string, array{string, list<string>}> */
    public static function grupos(): iterable
    {
        foreach (Permisos::grupos() as $nombre => $roles) {
            yield $nombre => [$nombre, $roles];
        }
    }

    /** @return iterable<string, array{string}> */
    public static function roles(): iterable
    {
        foreach (Permisos::ROLES as $rol) {
            yield $rol => [$rol];
        }
    }

    public function testSonCincoGrupos(): void
    {
        self::assertSame(array_keys(self::ESPERADO), array_keys(Permisos::grupos()));
    }

    /** @param list<string> $roles */
    #[DataProvider('grupos')]
    public function testCadaGrupoTieneExactamenteLosRolesDeRf19(string $nombre, array $roles): void
    {
        self::assertSame(self::ESPERADO[$nombre], $roles);
    }

    /** @param list<string> $roles */
    #[DataProvider('grupos')]
    public function testCadaRolPasaSoloDondeRf19LoPermite(string $nombre, array $roles): void
    {
        foreach (Permisos::ROLES as $rol) {
            self::assertSame(
                in_array($rol, self::ESPERADO[$nombre], true),
                Permisos::puede($rol, $roles),
                "{$rol} en {$nombre}"
            );
        }
    }

    /** @param list<string> $roles */
    #[DataProvider('grupos')]
    public function testElAdministradorEsSuperusuarioYPasaLosCincoGrupos(string $nombre, array $roles): void
    {
        self::assertTrue(Permisos::puede(Permisos::ADMINISTRADOR_SISTEMA, $roles), $nombre);
    }

    /** @param list<string> $roles */
    #[DataProvider('grupos')]
    public function testElGerenteEsSoloLecturaYNoPasaNinguno(string $nombre, array $roles): void
    {
        self::assertFalse(Permisos::puede(Permisos::GERENTE, $roles), $nombre);
    }

    /** @param list<string> $roles */
    #[DataProvider('grupos')]
    public function testUnTokenSinRolNuncaPasa(string $nombre, array $roles): void
    {
        self::assertFalse(Permisos::puede(null, $roles), $nombre);
    }

    /** @param list<string> $roles */
    #[DataProvider('grupos')]
    public function testUnRolDesconocidoNuncaPasa(string $nombre, array $roles): void
    {
        self::assertFalse(Permisos::puede('Auditor', $roles), $nombre);
        self::assertFalse(Permisos::puede('', $roles), $nombre);
        // La comparacion es estricta: ni mayusculas distintas ni espacios.
        self::assertFalse(Permisos::puede('administradorsistema', $roles), $nombre);
        self::assertFalse(Permisos::puede(' AdministradorSistema', $roles), $nombre);
    }

    /**
     * RF20 es una lista blanca: ver costos hay que concederlo. La lista se
     * escribe aca a mano para que agregar un rol sin decidirlo se ponga rojo.
     */
    public function testSoloEstosRolesVenCostos(): void
    {
        self::assertSame(
            ['AdministradorSistema', 'PersonalAdministrativo', 'Gerente'],
            Permisos::VER_COSTOS
        );
        self::assertFalse(Permisos::puede(Permisos::PERSONAL_TECNICO, Permisos::VER_COSTOS));
    }

    public function testUnRolDesconocidoOSinRolNoVeCostos(): void
    {
        self::assertFalse(Permisos::puede('Auditor', Permisos::VER_COSTOS));
        self::assertFalse(Permisos::puede('', Permisos::VER_COSTOS));
        self::assertFalse(Permisos::puede(null, Permisos::VER_COSTOS));
    }

    public function testLosCuatroRolesSonLosDelEnum(): void
    {
        self::assertSame(
            ['AdministradorSistema', 'PersonalAdministrativo', 'PersonalTecnico', 'Gerente'],
            Permisos::ROLES
        );
    }
}
