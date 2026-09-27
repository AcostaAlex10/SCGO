<?php

declare(strict_types=1);

namespace Sgso\Tests\Reglas;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sgso\Reglas\CicloDeVida;

/**
 * Ciclo de vida de la obra (ADR-001 seccion 5.2).
 *
 * Las pruebas recorren los siete estados en vez de elegir un par a mano: asi
 * un estado nuevo en el ENUM aparece aca sin que haya que acordarse de
 * agregarlo caso por caso.
 */
#[CoversClass(CicloDeVida::class)]
final class CicloDeVidaTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function estados(): iterable
    {
        foreach (CicloDeVida::ESTADOS as $estado) {
            yield $estado => [$estado];
        }
    }

    public function testSonSieteEstadosSinRepetidos(): void
    {
        self::assertCount(7, CicloDeVida::ESTADOS);
        self::assertSame(CicloDeVida::ESTADOS, array_values(array_unique(CicloDeVida::ESTADOS)));
    }

    #[DataProvider('estados')]
    public function testTodoEstadoDeLaListaEsValido(string $estado): void
    {
        self::assertTrue(CicloDeVida::esEstado($estado));
    }

    public function testUnEstadoInventadoNoEsValido(): void
    {
        self::assertFalse(CicloDeVida::esEstado('en_ejecución'));
        self::assertFalse(CicloDeVida::esEstado('EN_EJECUCION'));
        self::assertFalse(CicloDeVida::esEstado(''));
    }

    #[DataProvider('estados')]
    public function testSoloFinalizadaYCanceladaSonTerminales(string $estado): void
    {
        $esperado = in_array($estado, [CicloDeVida::FINALIZADA, CicloDeVida::CANCELADA], true);
        self::assertSame($esperado, CicloDeVida::esTerminal($estado));
    }

    // ----------------------------------------------------------------
    //  Alta y planificacion (D-04)
    // ----------------------------------------------------------------

    public function testUnaObraNuevaArrancaCreada(): void
    {
        self::assertSame(CicloDeVida::CREADA, CicloDeVida::INICIAL);
    }

    #[DataProvider('estados')]
    public function testSoloUnaObraCreadaPasaAPlanificacionAlCargarLaPlanificacion(string $estado): void
    {
        $esperado = $estado === CicloDeVida::CREADA ? CicloDeVida::PLANIFICACION : null;
        self::assertSame($esperado, CicloDeVida::alCargarPlanificacion($estado));
    }

    #[DataProvider('estados')]
    public function testSoloUnaObraEnPlanificacionVuelveACreadaAlBorrarLaPlanificacion(string $estado): void
    {
        $esperado = $estado === CicloDeVida::PLANIFICACION ? CicloDeVida::CREADA : null;
        self::assertSame($esperado, CicloDeVida::alBorrarPlanificacion($estado));
    }

    // ----------------------------------------------------------------
    //  Arranque por avance
    // ----------------------------------------------------------------

    #[DataProvider('estados')]
    public function testSoloUnaObraEnPlanificacionArrancaPorAvance(string $estado): void
    {
        $esperado = $estado === CicloDeVida::PLANIFICACION;
        self::assertSame($esperado, CicloDeVida::arrancaPorAvance($estado, 0.5));
    }

    public function testUnAvanceDeCeroNoArrancaLaObra(): void
    {
        self::assertFalse(CicloDeVida::arrancaPorAvance(CicloDeVida::PLANIFICACION, 0.0));
    }

    public function testLlegarAlCienNoFinalizaLaObra(): void
    {
        // El avance no cierra la obra: eso lo hace el reporte final aprobado.
        self::assertFalse(CicloDeVida::arrancaPorAvance(CicloDeVida::EN_EJECUCION, 100.0));
        self::assertTrue(CicloDeVida::aceptaAvance(CicloDeVida::EN_EJECUCION));
    }

    #[DataProvider('estados')]
    public function testSoloUnaObraCanceladaRechazaAvance(string $estado): void
    {
        $esperado = $estado !== CicloDeVida::CANCELADA;
        self::assertSame($esperado, CicloDeVida::aceptaAvance($estado));
    }

    // ----------------------------------------------------------------
    //  Pausa y reactivacion (RF25)
    // ----------------------------------------------------------------

    #[DataProvider('estados')]
    public function testSoloSePausaUnaObraEnMarcha(string $estado): void
    {
        $esperado = in_array($estado, [CicloDeVida::EN_EJECUCION, CicloDeVida::EN_REVISION], true);
        self::assertSame($esperado, CicloDeVida::debePausar($estado, 1));
    }

    #[DataProvider('estados')]
    public function testSinPeriodosVigentesNadaSePausa(string $estado): void
    {
        self::assertFalse(CicloDeVida::debePausar($estado, 0));
    }

    public function testVariosPeriodosVigentesTambienPausan(): void
    {
        self::assertTrue(CicloDeVida::debePausar(CicloDeVida::EN_EJECUCION, 3));
    }

    #[DataProvider('estados')]
    public function testSoloUnaObraPausadaSeReactiva(string $estado): void
    {
        $esperado = $estado === CicloDeVida::PAUSADA;
        self::assertSame($esperado, CicloDeVida::debeReactivar($estado, 0));
    }

    public function testUnaObraPausadaConPeriodoVigenteSiguerPausada(): void
    {
        self::assertFalse(CicloDeVida::debeReactivar(CicloDeVida::PAUSADA, 1));
    }

    public function testAlReactivarVuelveARevisionSiHayReporteFinalEsperando(): void
    {
        self::assertSame(CicloDeVida::EN_REVISION, CicloDeVida::destinoAlReactivar(true));
    }

    public function testAlReactivarVuelveAEjecucionSiNoHayReporteFinal(): void
    {
        self::assertSame(CicloDeVida::EN_EJECUCION, CicloDeVida::destinoAlReactivar(false));
    }

    // ----------------------------------------------------------------
    //  Cancelacion manual
    // ----------------------------------------------------------------

    #[DataProvider('estados')]
    public function testSoloSeCancelaUnaObraEnEjecucionOPausada(string $estado): void
    {
        $esperado = in_array($estado, [CicloDeVida::EN_EJECUCION, CicloDeVida::PAUSADA], true);
        self::assertSame($esperado, CicloDeVida::puedeCancelar($estado));
    }

    /** @return iterable<string, array{string}> */
    public static function estadosTerminales(): iterable
    {
        foreach (CicloDeVida::TERMINALES as $estado) {
            yield $estado => [$estado];
        }
    }

    #[DataProvider('estadosTerminales')]
    public function testNingunEstadoTerminalSeCancela(string $estado): void
    {
        self::assertFalse(CicloDeVida::puedeCancelar($estado));
    }

    // ----------------------------------------------------------------
    //  Cierre por reporte final (RF21)
    // ----------------------------------------------------------------

    #[DataProvider('estados')]
    public function testSoloSeEnviaElReporteFinalDeUnaObraEnEjecucion(string $estado): void
    {
        $esperado = $estado === CicloDeVida::EN_EJECUCION;
        self::assertSame($esperado, CicloDeVida::puedeEnviarReporteFinal($estado));
    }

    public function testAprobarElReporteFinalFinalizaLaObra(): void
    {
        self::assertSame(
            CicloDeVida::FINALIZADA,
            CicloDeVida::destinoTrasResolverFinal(CicloDeVida::EN_REVISION, 'aprobado')
        );
    }

    public function testRechazarElReporteFinalDevuelveLaObraAEjecucion(): void
    {
        self::assertSame(
            CicloDeVida::EN_EJECUCION,
            CicloDeVida::destinoTrasResolverFinal(CicloDeVida::EN_REVISION, 'rechazado')
        );
    }

    #[DataProvider('estados')]
    public function testResolverNoMueveUnaObraQueNoEstaEnRevision(string $estado): void
    {
        if ($estado === CicloDeVida::EN_REVISION) {
            self::assertNotNull(CicloDeVida::destinoTrasResolverFinal($estado, 'aprobado'));
            return;
        }

        // Si la pausaron o la cancelaron mientras el reporte esperaba, la
        // resolucion se registra pero la obra se deja donde esta.
        self::assertNull(CicloDeVida::destinoTrasResolverFinal($estado, 'aprobado'));
        self::assertNull(CicloDeVida::destinoTrasResolverFinal($estado, 'rechazado'));
    }

    // ----------------------------------------------------------------
    //  Contraste con el frontend
    // ----------------------------------------------------------------

    /**
     * El front repite estas reglas en estadosObra.ts. Mientras siga
     * duplicado, esta prueba es lo que avisa cuando se desincronizan: es el
     * problema que ADR-001 marca en la seccion 4c y que ya costo errores.
     */
    public function testElFrontendDeclaraLosMismosEstadosYCancelables(): void
    {
        $ruta = __DIR__ . '/../../../FRONT/src/app/estadosObra.ts';
        if (!is_file($ruta)) {
            self::markTestSkipped('No esta el frontend en este arbol de trabajo');
        }

        $ts = (string) file_get_contents($ruta);

        preg_match('/ESTADOS_OBRA:\s*Record<string,\s*EstadoObra>\s*=\s*\{(.*?)\};/s', $ts, $m);
        preg_match_all('/^\s*([a-z_]+):\s*\{/m', $m[1] ?? '', $claves);
        self::assertSame(
            CicloDeVida::ESTADOS,
            $claves[1],
            'estadosObra.ts y CicloDeVida::ESTADOS no coinciden'
        );

        preg_match('/ESTADOS_CANCELABLES\s*=\s*\[(.*?)\]/s', $ts, $c);
        preg_match_all('/"([a-z_]+)"/', $c[1] ?? '', $cancelables);
        self::assertSame(
            CicloDeVida::CANCELABLES,
            $cancelables[1],
            'estadosObra.ts y CicloDeVida::CANCELABLES no coinciden'
        );
    }
}
