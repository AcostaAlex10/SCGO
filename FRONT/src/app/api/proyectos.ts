// Cliente de API para Proyectos (RF01) y la informacion por obra (RF02:
// planificacion y avances). Usa apiFetch, que agrega el token y la URL base.
import { apiFetch } from "../auth/api";

export interface Proyecto {
  id: string;
  nombre: string;
  tipo: string;
  ubicacion: string;
  encargado: string;
  fechaInicio: string;
  estado: string;
  avance: number;
  presupuesto: number;
}

export type ProyectoInput = Omit<Proyecto, "id" | "avance" | "estado"> &
  Partial<Pick<Proyecto, "estado" | "avance">>;

export interface Planificacion {
  id_planificacion: number;
  avance_esperado_total: number;
  fecha_carga: string;
  id_proyecto: number;
}

export interface EtapaPlanificacion {
  id_etapa: number;
  id_planificacion: number;
  nombre: string;
  peso_porcentual: number;
  fecha_inicio: string;
  fecha_fin: string;
  orden: number;
  presupuesto_base: number;
}

export interface Avance {
  id_avance: number;
  id_planificacion: number;
  cantidad_ejecutada: number;
  porcentaje_avance: number;
  fecha: string;
  observaciones: string | null;
}

export interface Resumen {
  avance_esperado: number;
  avance_real: number;
  desvio_pp: number;
  total_registros: number;
  ultimo_registro: string | null;
}

// Seguimiento operativo (Sprint 2)
export type EstadoAsistencia = "presente" | "ausente" | "tarde";
export interface Asistencia {
  id_asistencia: number;
  id_proyecto: number;
  fecha: string;
  trabajador: string;
  estado: EstadoAsistencia;
  justificacion: string | null;
}

export type TipoIncidencia = "clima" | "falla_maquinaria" | "proveedor" | "otro";
export type GravedadIncidencia = "baja" | "media" | "alta";
export interface Incidencia {
  id_incidencia: number;
  id_proyecto: number;
  fecha: string;
  tipo: TipoIncidencia;
  gravedad: GravedadIncidencia;
  descripcion: string;
  dias_retraso: number;
}

// Materiales (Sprint 3)
export interface Material {
  id_material: number;
  nombre: string;
  unidad: string;
}
export interface AsignacionMaterial {
  id_asignacion: number;
  id_material: number;
  nombre: string;
  unidad: string;
  cantidad_asignada: number;
  consumido: number;
  restante: number;
  excedido: boolean;
}
export interface ConsumoMaterial {
  id_consumo: number;
  id_asignacion: number;
  fecha: string;
  cantidad_consumida: number;
  observaciones: string | null;
}

// Documentacion (Sprint 3)
export type TipoDocumento = "pdf" | "imagen" | "otro";
export interface Documento {
  id_documento: number;
  id_proyecto: number;
  nombre: string;
  tipo: TipoDocumento;
  categoria: string;
  url: string;
  fecha_carga: string;
}

// Reportes, inactividad y excedentes (Sprint 4)
export type EstadoReporte = "borrador" | "en_revision" | "aprobado" | "rechazado";
export interface Reporte {
  id_reporte: number;
  id_proyecto: number;
  proyecto: string;
  id_usuario: number;
  autor: string;
  titulo: string;
  contenido: string;
  estado: EstadoReporte;
  /** Reporte de cierre de la obra: enviarlo la pone en revisión y aprobarlo la finaliza. */
  es_final: boolean;
  observacion_revision: string | null;
  fecha_creacion: string;
  fecha_revision: string | null;
}

/**
 * Respuesta de las acciones que además mueven la obra (enviar, aprobar,
 * rechazar un reporte final). `estado_proyecto` solo viene si la obra cambió.
 */
export type ReporteConObra = Reporte & { estado_proyecto?: string };
export interface PeriodoInactividad {
  id_periodo: number;
  id_proyecto: number;
  fecha_inicio: string;
  fecha_fin: string | null;
  motivo: string;
  /** Ya empezó y todavía no terminó: es el que mantiene pausada la obra. */
  vigente?: boolean;
}
/**
 * Alta y cierre de un período devuelven además el estado en que quedó la obra,
 * o null si no cambió. Evita tener que volver a pedir el proyecto entero.
 */
export interface RespuestaInactividad extends PeriodoInactividad {
  estado_proyecto?: string | null;
}
export interface ItemExcedente {
  id_item: number;
  id_proyecto: number;
  descripcion: string;
  cantidad: number | null;
  unidad: string | null;
  fecha: string;
  motivo: string | null;
}

async function parse<T>(res: Response): Promise<T> {
  const data = await res.json().catch(() => ({}));
  if (!res.ok) {
    const d = data as { error?: string; errors?: Record<string, string> };
    // Errores por campo (422): los unimos en un mensaje legible.
    const porCampo = d.errors ? Object.values(d.errors).join(". ") : null;
    throw new Error(d.error ?? porCampo ?? "Error en la solicitud");
  }
  return data as T;
}

/**
 * Una pagina de un listado que crece con el tiempo (C-03). La API la devuelve
 * con `?limite=&desde=`, y el total en el encabezado `X-Total-Count`.
 */
export interface Pagina<T> {
  filas: T[];
  total: number | null;
  hayMas: boolean;
}
export const TAMANO_PAGINA = 50;

async function pedirPagina<T>(ruta: string, desde: number): Promise<Pagina<T>> {
  const separador = ruta.includes("?") ? "&" : "?";
  const res = await apiFetch(`${ruta}${separador}limite=${TAMANO_PAGINA}&desde=${desde}`);
  const filas = await parse<T[]>(res);
  const encabezado = res.headers.get("X-Total-Count");
  const total = encabezado === null ? null : Number(encabezado);
  // Sin el total (un proxy que no expone el encabezado), una pagina llena
  // significa que puede haber mas: mejor ofrecerlas que perderlas en silencio.
  const hayMas = total === null ? filas.length === TAMANO_PAGINA : desde + filas.length < total;
  return { filas, total, hayMas };
}

export function paginaVacia<T>(): Pagina<T> {
  return { filas: [], total: 0, hayMas: false };
}

/**
 * Agrega la pagina siguiente a la que ya se ve. Si alguien cargo una fila
 * entre medio, el corrimiento repetiria la ultima: se descarta por id.
 */
export function sumarPagina<T>(vista: Pagina<T>, siguiente: Pagina<T>, id: keyof T): Pagina<T> {
  const vistos = new Set(vista.filas.map((f) => f[id]));
  return { ...siguiente, filas: [...vista.filas, ...siguiente.filas.filter((f) => !vistos.has(f[id]))] };
}

/** Saca de la vista una fila eliminada, y la descuenta del total. */
export function quitarDePagina<T>(vista: Pagina<T>, quitar: (f: T) => boolean): Pagina<T> {
  const filas = vista.filas.filter((f) => !quitar(f));
  const total = vista.total === null ? null : vista.total - (vista.filas.length - filas.length);
  return { ...vista, filas, total };
}

// ---------- Proyectos (RF01) ----------
export async function listarProyectos(busqueda?: string): Promise<Proyecto[]> {
  const q = busqueda ? `?q=${encodeURIComponent(busqueda)}` : "";
  return parse(await apiFetch(`/proyectos${q}`));
}
export async function crearProyecto(datos: ProyectoInput): Promise<Proyecto> {
  return parse(await apiFetch("/proyectos", { method: "POST", body: JSON.stringify(datos) }));
}
export async function actualizarProyecto(id: string, datos: Partial<ProyectoInput>): Promise<Proyecto> {
  return parse(await apiFetch(`/proyectos/${id}`, { method: "PUT", body: JSON.stringify(datos) }));
}
export async function eliminarProyecto(id: string): Promise<void> {
  await parse(await apiFetch(`/proyectos/${id}`, { method: "DELETE" }));
}
export async function obtenerProyecto(id: string): Promise<Proyecto> {
  return parse(await apiFetch(`/proyectos/${id}`));
}

// ---------- Planificacion (RF02) ----------
export async function obtenerPlanificacion(idProyecto: string): Promise<Planificacion | null> {
  const res = await apiFetch(`/proyectos/${idProyecto}/planificacion`);
  if (res.status === 404) return null;
  return parse(res);
}
export async function crearPlanificacion(
  idProyecto: string,
  datos: { avance_esperado_total?: number; fecha_carga: string }
): Promise<Planificacion> {
  return parse(await apiFetch(`/proyectos/${idProyecto}/planificacion`, { method: "POST", body: JSON.stringify(datos) }));
}

// ---------- Etapas de planificación ----------
export async function listarEtapas(idPlan: number): Promise<EtapaPlanificacion[]> {
  return parse(await apiFetch(`/planificacion/${idPlan}/etapas`));
}
export async function crearEtapa(
  idPlan: number,
  datos: { nombre: string; peso_porcentual: number; fecha_inicio: string; fecha_fin: string; presupuesto_base?: number; orden?: number }
): Promise<EtapaPlanificacion & { suma_pesos: number }> {
  return parse(await apiFetch(`/planificacion/${idPlan}/etapas`, { method: "POST", body: JSON.stringify(datos) }));
}
export async function actualizarEtapa(
  id: number,
  datos: { nombre?: string; peso_porcentual?: number; fecha_inicio?: string; fecha_fin?: string; presupuesto_base?: number; orden?: number }
): Promise<void> {
  await parse(await apiFetch(`/planificacion/etapa/${id}`, { method: "PUT", body: JSON.stringify(datos) }));
}
export async function eliminarEtapa(id: number): Promise<void> {
  await parse(await apiFetch(`/planificacion/etapa/${id}`, { method: "DELETE" }));
}
export async function actualizarPlanificacion(
  idPlan: number,
  datos: { avance_esperado_total: number; fecha_carga: string }
): Promise<void> {
  await parse(await apiFetch(`/planificacion/${idPlan}`, { method: "PUT", body: JSON.stringify(datos) }));
}

// ---------- Avances fisicos (RF02) ----------
export async function listarAvances(idPlan: number): Promise<Avance[]> {
  return parse(await apiFetch(`/planificacion/${idPlan}/avances`));
}
export async function resumenAvances(idPlan: number): Promise<Resumen> {
  return parse(await apiFetch(`/planificacion/${idPlan}/avances/resumen`));
}
export async function crearAvance(
  idPlan: number,
  datos: { cantidad_ejecutada: number; porcentaje_avance: number; fecha: string; observaciones?: string }
): Promise<Avance> {
  return parse(await apiFetch(`/planificacion/${idPlan}/avances`, { method: "POST", body: JSON.stringify(datos) }));
}

// ---------- Asistencia del personal (RF06) ----------
export async function listarAsistencias(idProyecto: string, desde = 0): Promise<Pagina<Asistencia>> {
  return pedirPagina(`/proyectos/${idProyecto}/asistencias`, desde);
}
export async function crearAsistencia(
  idProyecto: string,
  datos: { fecha: string; trabajador: string; estado: EstadoAsistencia; justificacion?: string }
): Promise<Asistencia> {
  return parse(await apiFetch(`/proyectos/${idProyecto}/asistencias`, { method: "POST", body: JSON.stringify(datos) }));
}
export async function eliminarAsistencia(idAsistencia: number): Promise<void> {
  await parse(await apiFetch(`/proyectos/asistencia/${idAsistencia}`, { method: "DELETE" }));
}

// ---------- Incidencias externas (RF09) ----------
export async function listarIncidencias(idProyecto: string): Promise<Incidencia[]> {
  return parse(await apiFetch(`/proyectos/${idProyecto}/incidencias`));
}
export async function crearIncidencia(
  idProyecto: string,
  datos: { fecha: string; tipo: TipoIncidencia; gravedad: GravedadIncidencia; descripcion: string; dias_retraso?: number }
): Promise<Incidencia> {
  return parse(await apiFetch(`/proyectos/${idProyecto}/incidencias`, { method: "POST", body: JSON.stringify(datos) }));
}
export async function eliminarIncidencia(idIncidencia: number): Promise<void> {
  await parse(await apiFetch(`/proyectos/incidencia/${idIncidencia}`, { method: "DELETE" }));
}

// ---------- Materiales (RF04/RF10/RF12) ----------
export async function listarCatalogoMateriales(): Promise<Material[]> {
  return parse(await apiFetch(`/materiales`));
}
export async function listarMaterialesObra(idProyecto: string): Promise<AsignacionMaterial[]> {
  return parse(await apiFetch(`/proyectos/${idProyecto}/materiales`));
}
export async function asignarMaterial(
  idProyecto: string,
  datos: { id_material: number; cantidad_asignada: number }
): Promise<{ id_asignacion: number }> {
  return parse(await apiFetch(`/proyectos/${idProyecto}/materiales`, { method: "POST", body: JSON.stringify(datos) }));
}
export async function eliminarAsignacionMaterial(idAsignacion: number): Promise<void> {
  await parse(await apiFetch(`/proyectos/material/${idAsignacion}`, { method: "DELETE" }));
}
export async function listarConsumos(idAsignacion: number): Promise<ConsumoMaterial[]> {
  return parse(await apiFetch(`/proyectos/material/${idAsignacion}/consumos`));
}
export async function crearConsumo(
  idAsignacion: number,
  datos: { fecha: string; cantidad_consumida: number; observaciones?: string }
): Promise<{ id_consumo: number }> {
  return parse(await apiFetch(`/proyectos/material/${idAsignacion}/consumos`, { method: "POST", body: JSON.stringify(datos) }));
}
export async function eliminarConsumo(idConsumo: number): Promise<void> {
  await parse(await apiFetch(`/proyectos/consumo/${idConsumo}`, { method: "DELETE" }));
}

// ---------- Documentacion (RF16) ----------
export async function listarDocumentos(idProyecto: string): Promise<Documento[]> {
  return parse(await apiFetch(`/proyectos/${idProyecto}/documentos`));
}
export async function crearDocumento(
  idProyecto: string,
  datos: { nombre: string; tipo: TipoDocumento; categoria: string; url: string; fecha_carga: string }
): Promise<Documento> {
  return parse(await apiFetch(`/proyectos/${idProyecto}/documentos`, { method: "POST", body: JSON.stringify(datos) }));
}
export async function eliminarDocumento(idDocumento: number): Promise<void> {
  await parse(await apiFetch(`/proyectos/documento/${idDocumento}`, { method: "DELETE" }));
}

// ---------- Reportes (RF17/RF21) ----------
export async function listarReportes(estado = "", desde = 0): Promise<Pagina<Reporte>> {
  const q = estado ? `?estado=${encodeURIComponent(estado)}` : "";
  return pedirPagina(`/reportes${q}`, desde);
}
export async function crearReporte(datos: { id_proyecto: number; titulo: string; contenido: string; es_final?: boolean }): Promise<Reporte> {
  return parse(await apiFetch(`/reportes`, { method: "POST", body: JSON.stringify(datos) }));
}
export async function editarReporte(id: number, datos: { titulo: string; contenido: string; es_final?: boolean }): Promise<Reporte> {
  return parse(await apiFetch(`/reportes/${id}`, { method: "PUT", body: JSON.stringify(datos) }));
}
export async function enviarReporte(id: number): Promise<ReporteConObra> {
  return parse(await apiFetch(`/reportes/${id}/enviar`, { method: "POST" }));
}
export async function aprobarReporte(id: number, observacion?: string): Promise<ReporteConObra> {
  return parse(await apiFetch(`/reportes/${id}/aprobar`, { method: "POST", body: JSON.stringify({ observacion }) }));
}
export async function rechazarReporte(id: number, observacion: string): Promise<ReporteConObra> {
  return parse(await apiFetch(`/reportes/${id}/rechazar`, { method: "POST", body: JSON.stringify({ observacion }) }));
}
export async function eliminarReporte(id: number): Promise<void> {
  await parse(await apiFetch(`/reportes/${id}`, { method: "DELETE" }));
}

// ---------- Inactividad (RF25) ----------
export async function listarInactividades(idProyecto: string): Promise<PeriodoInactividad[]> {
  return parse(await apiFetch(`/proyectos/${idProyecto}/inactividades`));
}
export async function crearInactividad(
  idProyecto: string,
  datos: { fecha_inicio: string; fecha_fin?: string; motivo: string }
): Promise<RespuestaInactividad> {
  return parse(await apiFetch(`/proyectos/${idProyecto}/inactividades`, { method: "POST", body: JSON.stringify(datos) }));
}
/** Cierra el período y, si era el último vigente, reactiva la obra. */
export async function cerrarInactividad(id: number, fecha_fin?: string): Promise<RespuestaInactividad> {
  return parse(await apiFetch(`/proyectos/inactividad/${id}`, { method: "PUT", body: JSON.stringify({ fecha_fin }) }));
}
export async function eliminarInactividad(id: number): Promise<{ mensaje?: string; estado_proyecto?: string | null }> {
  return parse(await apiFetch(`/proyectos/inactividad/${id}`, { method: "DELETE" }));
}

// ---------- Ítems excedentes (RF22) ----------
export async function listarExcedentes(idProyecto: string): Promise<ItemExcedente[]> {
  return parse(await apiFetch(`/proyectos/${idProyecto}/excedentes`));
}
export async function crearExcedente(
  idProyecto: string,
  datos: { descripcion: string; cantidad?: number; unidad?: string; fecha: string; motivo?: string }
): Promise<ItemExcedente> {
  return parse(await apiFetch(`/proyectos/${idProyecto}/excedentes`, { method: "POST", body: JSON.stringify(datos) }));
}
export async function eliminarExcedente(id: number): Promise<void> {
  await parse(await apiFetch(`/proyectos/excedente/${id}`, { method: "DELETE" }));
}

// ---------- Análisis y alertas (RF11/RF13) ----------
export interface AnalisisProyecto {
  id_proyecto: number;
  nombre: string;
  estado: string;
  avance_real: number;
  avance_esperado: number | null;
  desvio_avance: number | null;
  alerta_avance: boolean;
  materiales_excedidos: number;
  presupuesto?: number;
  ejecutado?: number;
  diferencia?: number;
  presupuesto_base_total?: number | null;
}
export interface Alerta {
  tipo: "avance" | "material";
  gravedad: "alta" | "media" | "baja";
  proyecto: string;
  mensaje: string;
}
export interface Analisis {
  proyectos: AnalisisProyecto[];
  alertas: Alerta[];
}
export async function obtenerAnalisis(): Promise<Analisis> {
  return parse(await apiFetch(`/analisis`));
}

// ---------- Maquinaria (RF23/RF24/RF27/RF28) ----------
export interface Maquinaria {
  id_maquinaria: number; nombre: string; tipo: string; activa: boolean;
  horas: number; combustible: number; produccion: number;
  combustible_por_hora: number; produccion_por_hora: number; fallas_abiertas: number;
}
export interface RegistroMaquinaria {
  id_registro: number; id_maquinaria: number; id_proyecto: number | null; fecha: string;
  operario: string | null; horas_uso: number; combustible_consumido: number;
  produccion_realizada: number; combustible_por_hora: number; alerta_consumo: boolean;
}
export interface FallaMaquinaria {
  id_falla: number; id_maquinaria: number; fecha: string; componente: string | null;
  descripcion: string; reemplazo: boolean; resuelto: boolean;
}
export interface RendimientoOperario {
  operario: string; horas: number; produccion: number; combustible: number; produccion_por_hora: number;
}

export async function listarMaquinaria(): Promise<Maquinaria[]> {
  return parse(await apiFetch(`/maquinaria`));
}
export async function crearMaquinaria(datos: { nombre: string; tipo: string }): Promise<Maquinaria> {
  return parse(await apiFetch(`/maquinaria`, { method: "POST", body: JSON.stringify(datos) }));
}
export async function eliminarMaquinaria(id: number): Promise<void> {
  await parse(await apiFetch(`/maquinaria/${id}`, { method: "DELETE" }));
}
export async function listarRegistrosMaq(idMaq: number): Promise<RegistroMaquinaria[]> {
  return parse(await apiFetch(`/maquinaria/${idMaq}/registros`));
}
export async function crearRegistroMaq(
  idMaq: number,
  datos: { fecha: string; operario?: string; horas_uso: number; combustible_consumido: number; produccion_realizada: number; id_proyecto?: number }
): Promise<{ id_registro: number }> {
  return parse(await apiFetch(`/maquinaria/${idMaq}/registros`, { method: "POST", body: JSON.stringify(datos) }));
}
export async function eliminarRegistroMaq(id: number): Promise<void> {
  await parse(await apiFetch(`/maquinaria/registro/${id}`, { method: "DELETE" }));
}
export async function listarFallasMaq(idMaq: number): Promise<FallaMaquinaria[]> {
  return parse(await apiFetch(`/maquinaria/${idMaq}/fallas`));
}
export async function crearFallaMaq(
  idMaq: number,
  datos: { fecha: string; componente?: string; descripcion: string; reemplazo?: boolean; resuelto?: boolean }
): Promise<{ id_falla: number }> {
  return parse(await apiFetch(`/maquinaria/${idMaq}/fallas`, { method: "POST", body: JSON.stringify(datos) }));
}
export async function eliminarFallaMaq(id: number): Promise<void> {
  await parse(await apiFetch(`/maquinaria/falla/${id}`, { method: "DELETE" }));
}
export async function rendimientoOperarios(): Promise<RendimientoOperario[]> {
  return parse(await apiFetch(`/maquinaria/operarios`));
}

// ---------- Gestión de usuarios (HU16, completa RF19) ----------
export type RolUsuario =
  | "PersonalAdministrativo"
  | "PersonalTecnico"
  | "Gerente"
  | "AdministradorSistema";

export interface Usuario {
  id_usuario: number;
  nombre: string;
  email: string;
  rol: RolUsuario;
  activo: boolean;
  fecha_creacion?: string;
}

export async function listarUsuarios(): Promise<Usuario[]> {
  return parse(await apiFetch(`/usuarios`));
}
/** Alta de usuario: reutiliza el registro del módulo de autenticación. */
export async function crearUsuario(datos: {
  nombre: string;
  email: string;
  contrasena: string;
  rol: RolUsuario;
}): Promise<Usuario> {
  return parse(await apiFetch(`/auth/register`, { method: "POST", body: JSON.stringify(datos) }));
}
export async function actualizarUsuario(
  id: number,
  datos: { rol?: RolUsuario; activo?: boolean }
): Promise<Usuario> {
  return parse(await apiFetch(`/usuarios/${id}`, { method: "PUT", body: JSON.stringify(datos) }));
}
