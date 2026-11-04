<?php

declare(strict_types=1);

namespace Sgso\Reglas;

/**
 * Permisos por rol (RF19).
 *
 * Los cinco grupos estaban como constantes sueltas arriba de
 * public/index.php, donde no habia forma de probarlos ni de recorrerlos.
 * index.php los sigue exponiendo con los mismos nombres, pero tomados de
 * aca: la lista vive en un solo lugar.
 *
 * El AdministradorSistema es superusuario y por eso esta en los cinco grupos.
 */
final class Permisos
{
    public const ADMINISTRADOR_SISTEMA = 'AdministradorSistema';
    public const PERSONAL_ADMINISTRATIVO = 'PersonalAdministrativo';
    public const PERSONAL_TECNICO = 'PersonalTecnico';
    public const GERENTE = 'Gerente';

    /** Los cuatro roles del sistema (ENUM usuario.rol). */
    public const ROLES = [
        self::ADMINISTRADOR_SISTEMA,
        self::PERSONAL_ADMINISTRATIVO,
        self::PERSONAL_TECNICO,
        self::GERENTE,
    ];

    /** Crear, editar y eliminar obra, planificacion y materiales. */
    public const GESTION_OBRA = [self::ADMINISTRADOR_SISTEMA, self::PERSONAL_ADMINISTRATIVO];

    /** Registrar avance, asistencia, incidencias y consumos. */
    public const AVANCE = [self::ADMINISTRADOR_SISTEMA, self::PERSONAL_TECNICO];

    /** Documentacion, reportes, inactividad y excedentes: todos menos Gerente. */
    public const DOC = [self::ADMINISTRADOR_SISTEMA, self::PERSONAL_ADMINISTRATIVO, self::PERSONAL_TECNICO];

    /** Aprobar y rechazar reportes (RF21). */
    public const REPORTE_APROBAR = [self::ADMINISTRADOR_SISTEMA, self::PERSONAL_ADMINISTRATIVO];

    /** Gestionar cuentas y roles (HU16). */
    public const ADMIN = [self::ADMINISTRADOR_SISTEMA];

    /**
     * Ver costos, presupuestos e importes (RF20). Es lista blanca: un rol nuevo
     * no ve plata hasta que se lo agregue aca. No esta en grupos() porque
     * no protege una ruta: decide que campos lleva la respuesta.
     */
    public const VER_COSTOS = [self::ADMINISTRADOR_SISTEMA, self::PERSONAL_ADMINISTRATIVO, self::GERENTE];

    /**
     * Los cinco grupos por nombre. Sirve para recorrerlos en las pruebas en
     * vez de repetirlos a mano, y lo va a usar la tabla de rutas de la Fase 4.
     *
     * @return array<string, list<string>>
     */
    public static function grupos(): array
    {
        return [
            'GESTION_OBRA' => self::GESTION_OBRA,
            'AVANCE' => self::AVANCE,
            'DOC' => self::DOC,
            'REPORTE_APROBAR' => self::REPORTE_APROBAR,
            'ADMIN' => self::ADMIN,
        ];
    }

    /**
     * Si un rol esta dentro del grupo permitido.
     *
     * La comparacion es estricta y un rol ausente (null) nunca pasa: el token
     * puede venir sin rol si se emitio antes de que existiera el campo, y eso
     * no puede leerse como permiso.
     *
     * @param list<string> $grupo
     */
    public static function puede(?string $rol, array $grupo): bool
    {
        if ($rol === null) {
            return false;
        }

        return in_array($rol, $grupo, true);
    }
}
