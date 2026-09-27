<?php

declare(strict_types=1);

namespace Sgso\Reglas;

/**
 * Ciclo de vida de la OBRA: los siete estados del TP3 y que transicion es
 * legal desde donde.
 *
 * Por que existe esta clase (ADR-001 seccion 5.2): la regla estaba repartida
 * entre InactividadController (pausa y reactivacion), AvanceController
 * (arranque), ProyectoController (cancelacion) y ReporteController (cierre
 * por reporte final). Para leerla completa habia que abrir cuatro archivos y
 * reconstruirla de memoria, y no habia forma de probarla sin base de datos.
 *
 * Es pura a proposito: no toca PDO, no imprime y no conoce HTTP. Los
 * controladores siguen siendo los que consultan y escriben; lo unico que se
 * movio aca es la decision.
 *
 * Cada metodo que decide un estado nuevo devuelve null cuando NO hay cambio,
 * que es la forma que ya usaban los controladores.
 *
 * El frontend repite estas reglas en FRONT/src/app/estadosObra.ts. Mientras
 * sigan duplicadas, este archivo es el original y aquel la copia.
 */
final class CicloDeVida
{
    public const CREADA = 'creada';
    public const PLANIFICACION = 'planificacion';
    public const EN_EJECUCION = 'en_ejecucion';
    public const PAUSADA = 'pausada';
    public const EN_REVISION = 'en_revision';
    public const FINALIZADA = 'finalizada';
    public const CANCELADA = 'cancelada';

    /** Los siete estados del ENUM proyecto.estado, en orden de avance. */
    public const ESTADOS = [
        self::CREADA,
        self::PLANIFICACION,
        self::EN_EJECUCION,
        self::PAUSADA,
        self::EN_REVISION,
        self::FINALIZADA,
        self::CANCELADA,
    ];

    /** Estados de los que ya no se sale. */
    public const TERMINALES = [self::FINALIZADA, self::CANCELADA];

    /**
     * Estados desde los que una obra puede pasar a pausada.
     *
     * Una obra en planificacion todavia no arranco y los terminales ya
     * cerraron, asi que registrar un periodo de inactividad historico sobre
     * ellos es legitimo y no los mueve.
     */
    public const EN_MARCHA = [self::EN_EJECUCION, self::EN_REVISION];

    /**
     * El TP3 traza la cancelacion desde EnEjecucion y desde Pausado. Una obra
     * que todavia no arranco se elimina, no se cancela; y una terminada ya es
     * un estado final.
     */
    public const CANCELABLES = [self::EN_EJECUCION, self::PAUSADA];

    public static function esEstado(string $estado): bool
    {
        return in_array($estado, self::ESTADOS, true);
    }

    public static function esTerminal(string $estado): bool
    {
        return in_array($estado, self::TERMINALES, true);
    }

    // ----------------------------------------------------------------
    //  Alta y planificacion (ProyectoController, PlanificacionController)
    // ----------------------------------------------------------------

    /** Una obra recien registrada todavia no tiene planificacion. */
    public const INICIAL = self::CREADA;

    /** Cargar la planificacion pasa una obra creada a planificacion. */
    public static function alCargarPlanificacion(string $estado): ?string
    {
        return $estado === self::CREADA ? self::PLANIFICACION : null;
    }

    /**
     * Borrar la planificacion devuelve a creada una obra que todavia no
     * arranco. Una que ya arranco no retrocede: su historia sigue siendo la
     * de una obra en marcha.
     */
    public static function alBorrarPlanificacion(string $estado): ?string
    {
        return $estado === self::PLANIFICACION ? self::CREADA : null;
    }

    // ----------------------------------------------------------------
    //  Arranque por avance fisico (AvanceController)
    // ----------------------------------------------------------------

    /**
     * El primer avance mayor a cero saca la obra de planificacion.
     *
     * El avance NO finaliza la obra: llegar al 100 % es un dato, no una
     * decision. La obra se cierra cuando se aprueba el reporte final.
     */
    public static function arrancaPorAvance(string $estado, float $avanceReal): bool
    {
        return $avanceReal > 0.0 && $estado === self::PLANIFICACION;
    }

    /**
     * Una obra cancelada se dio por terminada sin completarse: seguir
     * cargandole avance la haria subir en el dashboard como si avanzara.
     *
     * Mira solo cancelada. Que una finalizada tampoco deba recibir avance es
     * razonable, pero es una decision aparte y todavia no esta tomada.
     */
    public static function aceptaAvance(string $estado): bool
    {
        return $estado !== self::CANCELADA;
    }

    // ----------------------------------------------------------------
    //  Pausa y reactivacion por inactividad (InactividadController, RF25)
    // ----------------------------------------------------------------

    /** Con al menos un periodo vigente, una obra en marcha queda pausada. */
    public static function debePausar(string $estado, int $periodosVigentes): bool
    {
        return $periodosVigentes > 0 && in_array($estado, self::EN_MARCHA, true);
    }

    /** Sin periodos vigentes, una obra pausada vuelve a andar. */
    public static function debeReactivar(string $estado, int $periodosVigentes): bool
    {
        return $periodosVigentes === 0 && $estado === self::PAUSADA;
    }

    /**
     * A donde vuelve una obra al reactivarse.
     *
     * No hace falta recordar el estado anterior en una columna: si hay un
     * reporte final esperando revision, la obra estaba cerrandose y ahi
     * vuelve; si no, a ejecucion.
     */
    public static function destinoAlReactivar(bool $tieneFinalEnRevision): string
    {
        return $tieneFinalEnRevision ? self::EN_REVISION : self::EN_EJECUCION;
    }

    // ----------------------------------------------------------------
    //  Cancelacion manual (ProyectoController)
    // ----------------------------------------------------------------

    /** Cancelar es el unico cambio de estado que hace una persona a mano. */
    public static function puedeCancelar(string $estado): bool
    {
        return in_array($estado, self::CANCELABLES, true);
    }

    // ----------------------------------------------------------------
    //  Cierre por reporte final (ReporteController, RF21)
    // ----------------------------------------------------------------

    /**
     * Enviar el reporte final exige la obra en ejecucion: una pausada o ya
     * terminada no puede entrar en revision de cierre.
     */
    public static function puedeEnviarReporteFinal(string $estadoObra): bool
    {
        return $estadoObra === self::EN_EJECUCION;
    }

    /**
     * Resolucion del reporte final: aprobarlo cierra la obra, rechazarlo la
     * devuelve a ejecucion.
     *
     * Solo mueve una obra que este en_revision. Si mientras tanto la pausaron
     * o la cancelaron, la resolucion queda registrada en el reporte y la obra
     * se deja donde esta, en vez de revivirla: por eso null.
     */
    public static function destinoTrasResolverFinal(string $estadoObra, string $resolucionReporte): ?string
    {
        if ($estadoObra !== self::EN_REVISION) {
            return null;
        }

        return $resolucionReporte === 'aprobado' ? self::FINALIZADA : self::EN_EJECUCION;
    }
}
