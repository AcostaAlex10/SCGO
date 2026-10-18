<?php

declare(strict_types=1);

namespace Sgso\Tests\Ruteo;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sgso\Reglas\Permisos;
use Sgso\Ruteo\Ruta;
use Sgso\Ruteo\Tabla;

/**
 * La tabla de rutas (ADR-001 seccion 5.4).
 *
 * El beneficio de que el ruteo sea dato y no una cadena de `if` es este
 * archivo: la guarda de rol de cada endpoint se puede recorrer entera, en vez
 * de repetir los casos a mano o confiar en que nadie se olvide una.
 */
#[CoversClass(Tabla::class)]
#[CoversClass(Ruta::class)]
final class TablaTest extends TestCase
{
    /**
     * Las rutas que exigen un rol, con el grupo que exigen: la tabla de RF19
     * escrita a mano. Si alguien cambia una guarda en la tabla, esta prueba lo
     * obliga a cambiarla tambien aca, que es donde se ve el impacto.
     *
     * Nota: GESTION_OBRA y REPORTE_APROBAR tienen hoy los mismos dos roles, asi
     * que la comparacion es por valor y no los distingue. Para lo que importa
     * —quien pasa y quien no— son equivalentes.
     *
     * @return list<array{string, string, list<string>}>
     */
    private static function guardasEsperadas(): array
    {
        return [
            ['POST', '/reportes', Permisos::DOC],
            ['POST', '/reportes/{id}/enviar', Permisos::DOC],
            ['POST', '/reportes/{id}/aprobar', Permisos::REPORTE_APROBAR],
            ['POST', '/reportes/{id}/rechazar', Permisos::REPORTE_APROBAR],
            ['PUT', '/reportes/{id}', Permisos::DOC],
            ['DELETE', '/reportes/{id}', Permisos::DOC],

            ['POST', '/maquinaria', Permisos::GESTION_OBRA],
            ['DELETE', '/maquinaria/registro/{id}', Permisos::DOC],
            ['DELETE', '/maquinaria/falla/{id}', Permisos::DOC],
            ['POST', '/maquinaria/{id}/registros', Permisos::DOC],
            ['POST', '/maquinaria/{id}/fallas', Permisos::DOC],
            ['DELETE', '/maquinaria/{id}', Permisos::GESTION_OBRA],

            ['GET', '/usuarios', Permisos::ADMIN],
            ['PUT', '/usuarios/{id}', Permisos::ADMIN],
            ['POST', '/materiales', Permisos::GESTION_OBRA],

            ['PUT', '/planificacion/avance/{id}', Permisos::AVANCE],
            ['DELETE', '/planificacion/avance/{id}', Permisos::AVANCE],
            ['PUT', '/planificacion/etapa/{id}', Permisos::GESTION_OBRA],
            ['DELETE', '/planificacion/etapa/{id}', Permisos::GESTION_OBRA],
            ['POST', '/planificacion/{id}/etapas', Permisos::GESTION_OBRA],
            ['POST', '/planificacion/{id}/avances', Permisos::AVANCE],
            ['PUT', '/planificacion/{id}', Permisos::GESTION_OBRA],
            ['DELETE', '/planificacion/{id}', Permisos::GESTION_OBRA],

            ['DELETE', '/proyectos/asistencia/{id}', Permisos::AVANCE],
            ['DELETE', '/proyectos/incidencia/{id}', Permisos::AVANCE],
            ['POST', '/proyectos/material/{id}/consumos', Permisos::AVANCE],
            ['DELETE', '/proyectos/material/{id}', Permisos::GESTION_OBRA],
            ['DELETE', '/proyectos/consumo/{id}', Permisos::AVANCE],
            ['DELETE', '/proyectos/documento/{id}', Permisos::DOC],
            ['PUT', '/proyectos/inactividad/{id}', Permisos::DOC],
            ['DELETE', '/proyectos/inactividad/{id}', Permisos::DOC],
            ['DELETE', '/proyectos/excedente/{id}', Permisos::DOC],

            ['POST', '/proyectos/{id}/asistencias', Permisos::AVANCE],
            ['POST', '/proyectos/{id}/incidencias', Permisos::AVANCE],
            ['POST', '/proyectos/{id}/materiales', Permisos::GESTION_OBRA],
            ['POST', '/proyectos/{id}/consumos', Permisos::AVANCE],
            ['POST', '/proyectos/{id}/documentos', Permisos::DOC],
            ['POST', '/proyectos/{id}/inactividades', Permisos::DOC],
            ['POST', '/proyectos/{id}/excedentes', Permisos::DOC],
            ['POST', '/proyectos/{id}/planificacion', Permisos::GESTION_OBRA],

            ['POST', '/proyectos', Permisos::GESTION_OBRA],
            ['PUT', '/proyectos/{id}', Permisos::GESTION_OBRA],
            ['DELETE', '/proyectos/{id}', Permisos::GESTION_OBRA],
        ];
    }

    /** @return iterable<string, array{Ruta}> */
    public static function rutas(): iterable
    {
        foreach (Tabla::rutas() as $ruta) {
            yield "{$ruta->metodo} {$ruta->patron} ({$ruta->manejador})" => [$ruta];
        }
    }

    public function testNoHayDosRutasConElMismoMetodoYCamino(): void
    {
        $pares = array_map(
            static fn (Ruta $r): string => "{$r->metodo} {$r->patron}",
            Tabla::rutas()
        );

        self::assertSame($pares, array_values(array_unique($pares)), 'hay rutas duplicadas');
    }

    #[DataProvider('rutas')]
    public function testTodaRutaUsaUnMetodoHttpConocido(Ruta $ruta): void
    {
        self::assertContains($ruta->metodo, ['GET', 'POST', 'PUT', 'DELETE']);
    }

    /** @return iterable<string, array{Ruta}> */
    public static function rutasConRoles(): iterable
    {
        foreach (Tabla::rutas() as $ruta) {
            if ($ruta->roles !== null) {
                yield "{$ruta->metodo} {$ruta->patron}" => [$ruta];
            }
        }
    }

    /** @return iterable<string, array{Ruta}> */
    public static function rutasPublicas(): iterable
    {
        foreach (Tabla::rutas() as $ruta) {
            if ($ruta->publica) {
                yield "{$ruta->metodo} {$ruta->patron}" => [$ruta];
            }
        }
    }

    #[DataProvider('rutasConRoles')]
    public function testTodaGuardaSaleDeUnGrupoDePermisos(Ruta $ruta): void
    {
        // Que no haya arrays de roles escritos a mano en la tabla.
        self::assertContains($ruta->roles, array_values(Permisos::grupos()));
    }

    public function testLasUnicasRutasPublicasSonLasCuatroDeSiempre(): void
    {
        $publicas = array_map(
            static fn (Ruta $r): string => "{$r->metodo} {$r->patron}",
            array_values(array_filter(Tabla::rutas(), static fn (Ruta $r): bool => $r->publica))
        );

        self::assertSame(
            ['POST /auth/login', 'POST /auth/olvide', 'POST /auth/restablecer', 'GET /health'],
            $publicas
        );
    }

    #[DataProvider('rutasPublicas')]
    public function testNingunaRutaPublicaExigeRol(Ruta $ruta): void
    {
        // index.php solo evalua la guarda de rol para rutas no publicas: una
        // publica con roles seria una guarda que nunca corre.
        self::assertNull($ruta->roles);
    }

    #[DataProvider('rutasConRoles')]
    public function testElGerenteNoPasaNingunaRutaConGuarda(Ruta $ruta): void
    {
        // RF19: el Gerente es solo lectura. Recorrer la tabla entera es lo que
        // hace que esto valga tambien para los endpoints que se agreguen.
        self::assertFalse(Permisos::puede(Permisos::GERENTE, $ruta->roles));
    }

    #[DataProvider('rutas')]
    public function testTodaRutaTieneSuManejadorEnIndex(Ruta $ruta): void
    {
        // Una clave mal tipeada dejaria el endpoint respondiendo 500 sin que
        // nada lo avise hasta que alguien lo use.
        $index = (string) file_get_contents(__DIR__ . '/../../public/index.php');

        self::assertStringContainsString("'{$ruta->manejador}'", $index);
    }

    public function testLasGuardasSonExactamenteLasDeclaradas(): void
    {
        $enLaTabla = [];
        foreach (Tabla::rutas() as $ruta) {
            if ($ruta->roles !== null) {
                $enLaTabla[] = [$ruta->metodo, $ruta->patron, $ruta->roles];
            }
        }

        self::assertEquals(self::guardasEsperadas(), $enLaTabla);
    }

    public function testLaCantidadDeRutasEsLaEsperada(): void
    {
        // No es una prueba de calidad: es un recordatorio. Si alguien agrega o
        // saca un endpoint, tiene que pasar por aca y por las guardas.
        self::assertCount(117, Tabla::rutas());
    }
}
