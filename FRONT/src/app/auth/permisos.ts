// Permisos por rol en el frontend (RF19/RF20).
//
// IMPORTANTE: esto es solo para la experiencia de usuario (ocultar botones y
// datos que no corresponden). La seguridad REAL la aplica el backend, que
// vuelve a verificar el rol en cada operación. Nunca confiar solo en el front.

import { getUsuario } from "./session";

export type Rol =
  | "AdministradorSistema"
  | "PersonalAdministrativo"
  | "Gerente"
  | "PersonalTecnico";

/** Rol del usuario logueado (o cadena vacía si no hay sesión). */
export function rolActual(): string {
  return getUsuario()?.rol ?? "";
}

/** Crear/editar/eliminar obras y cargar planificación. */
export function puedeGestionarObras(rol: string = rolActual()): boolean {
  return rol === "AdministradorSistema" || rol === "PersonalAdministrativo";
}

/** Registrar/editar/eliminar avances físicos (el encargado de obra). */
export function puedeRegistrarAvance(rol: string = rolActual()): boolean {
  return rol === "AdministradorSistema" || rol === "PersonalTecnico";
}

/** Ver costos/presupuestos (RF20). Lista blanca, como `Permisos::VER_COSTOS`: un rol nuevo no los ve. */
export function puedeVerCostos(rol: string = rolActual()): boolean {
  return rol === "AdministradorSistema" || rol === "PersonalAdministrativo" || rol === "Gerente";
}

/** Gestionar usuarios y asignar roles (solo el AdministradorSistema). */
export function puedeGestionarUsuarios(rol: string = rolActual()): boolean {
  return rol === "AdministradorSistema";
}

/** Cargar documentación, reportes, inactividad y excedentes (operativos, menos el Gerente). */
export function puedeCargarDocumentos(rol: string = rolActual()): boolean {
  return rol === "AdministradorSistema" || rol === "PersonalAdministrativo" || rol === "PersonalTecnico";
}

/** Aprobar o rechazar reportes (supervisor administrativo). RF21. */
export function puedeAprobarReportes(rol: string = rolActual()): boolean {
  return rol === "AdministradorSistema" || rol === "PersonalAdministrativo";
}
