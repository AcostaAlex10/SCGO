/**
 * Servidor simulado para el modo de prueba estatico (VITE_MOCK=1).
 *
 * Reproduce en memoria el contrato de la API PHP de `back/`, incluidas las
 * reglas de autorizacion por rol (RF19) y el ocultamiento de costos al
 * PersonalTecnico (RF20), de modo que la interfaz se comporte igual que contra
 * el backend real. Sirve para probar la UI sin backend, sin base de datos y sin
 * tocar los datos de la demo.
 *
 * NO reemplaza a las pruebas de integracion: aca no se ejercita PHP, ni SQL, ni
 * la autorizacion real del servidor. Es un doble de prueba de la interfaz.
 */
import base from "./datos.json";
import { VENTANA, segundosDeEspera } from "./politicaIntentos";
import { validarContrasena } from "./politicaContrasena";
import { ESTADOS_CANCELABLES } from "../estadosObra";
import { esEnlaceSeguro } from "../enlaces";

/**
 * Hash FNV-1a de 32 bits, en base 36. No es criptografico: solo sirve para
 * notar que `datos.json` cambio.
 */
function huella(texto: string): string {
  let h = 0x811c9dc5;
  for (let i = 0; i < texto.length; i++) {
    h ^= texto.charCodeAt(i);
    h = Math.imul(h, 0x01000193);
  }
  return (h >>> 0).toString(36);
}

const PREFIJO_ALMACEN = "sgso_mock_db_";
// La clave lleva la huella de los datos base. Al publicar un `datos.json`
// distinto cambia sola: la copia guardada en el navegador deja de encontrarse y
// el tester arranca desde los datos nuevos en lugar de seguir con los viejos.
// localStorage es por origen, no por version del sitio, asi que sin esto un
// redespliegue no alcanza para que vea los datos corregidos.
const CLAVE_ALMACEN = PREFIJO_ALMACEN + huella(JSON.stringify(base));
const DEMORA_MS = 80;

type Fila = Record<string, unknown>;
interface BaseDatos {
  usuarios: Fila[];
  proyectos: Fila[];
  planificaciones: Fila[];
  etapas: Fila[];
  avances: Fila[];
  asistencias: Fila[];
  incidencias: Fila[];
  materiales: Fila[];
  asignaciones: Fila[];
  consumos: Fila[];
  documentos: Fila[];
  reportes: Fila[];
  inactividades: Fila[];
  excedentes: Fila[];
  maquinaria: Fila[];
  registros_maquinaria: Fila[];
  fallas_maquinaria: Fila[];
}

// ---------------------------------------------------------------- estado

function cargar(): BaseDatos {
  try {
    purgarCopiasViejas();
    const guardado = localStorage.getItem(CLAVE_ALMACEN);
    if (guardado) return JSON.parse(guardado) as BaseDatos;
  } catch {
    /* almacenamiento no disponible: seguimos con los datos base */
  }
  return JSON.parse(JSON.stringify(base)) as BaseDatos;
}

/** Descarta las copias que quedaron de versiones anteriores de `datos.json`. */
function purgarCopiasViejas(): void {
  for (let i = localStorage.length - 1; i >= 0; i--) {
    const clave = localStorage.key(i);
    if (clave !== null && clave.startsWith(PREFIJO_ALMACEN) && clave !== CLAVE_ALMACEN) {
      localStorage.removeItem(clave);
    }
  }
}

let db: BaseDatos = cargar();

interface IntentosDeCuenta {
  fallos: number;
  ultimoFallo: number;
}

// Los intentos no se persisten: en la demo una recarga empieza de cero, como
// corresponde a un doble de prueba sin datos sensibles en localStorage.
const intentosLogin = new Map<string, IntentosDeCuenta>();

function guardar(): void {
  try {
    localStorage.setItem(CLAVE_ALMACEN, JSON.stringify(db));
  } catch {
    /* sin almacenamiento: los cambios viven solo en memoria */
  }
}

/** Vuelve a los datos originales. Disponible como window.sgsoMockReset(). */
export function reiniciar(): void {
  db = JSON.parse(JSON.stringify(base)) as BaseDatos;
  intentosLogin.clear();
  guardar();
}

function proximoId(filas: Fila[], campo: string): number {
  return filas.reduce((max, f) => Math.max(max, Number(f[campo]) || 0), 0) + 1;
}

// ---------------------------------------------------------------- utilidades

const esperar = (ms: number) => new Promise((r) => setTimeout(r, ms));

function json(estado: number, cuerpo: unknown, cabeceras?: HeadersInit): Response {
  return new Response(JSON.stringify(cuerpo), {
    status: estado,
    headers: { "Content-Type": "application/json", ...cabeceras },
  });
}

const ok = (cuerpo: unknown) => json(200, cuerpo);
const creado = (cuerpo: unknown) => json(201, cuerpo);
const sinContenido = () => json(200, { ok: true });
const noEncontrado = (msg = "Recurso no encontrado") => json(404, { error: msg });
const noAutenticado = () => json(401, { error: "No autenticado" });
const prohibido = () => json(403, { error: "No tiene permisos para esta operacion" });

const num = (v: unknown, def = 0): number => {
  const n = Number(v);
  return Number.isFinite(n) ? n : def;
};
const texto = (v: unknown): string => (typeof v === "string" ? v.trim() : "");
const hoy = () => new Date().toISOString().slice(0, 10);
const redondear = (n: number, dec = 2) => Math.round(n * 10 ** dec) / 10 ** dec;

/** La API usa el email recortado y en minúsculas como identidad del contador. */
const claveDeCuenta = (email: string) => email.trim().toLowerCase();
const ahoraEnSegundos = () => Math.floor(Date.now() / 1000);

function esperaDeCuenta(clave: string): number {
  const intento = intentosLogin.get(clave);
  if (!intento) return 0;
  return segundosDeEspera(intento.fallos, Math.max(0, ahoraEnSegundos() - intento.ultimoFallo));
}

function registrarFallo(clave: string): void {
  const ahora = ahoraEnSegundos();
  const anterior = intentosLogin.get(clave);
  // Un fallo viejo no debe prolongar un bloqueo nuevo: PHP vuelve a contar en 1.
  const fallos = !anterior || ahora - anterior.ultimoFallo >= VENTANA ? 1 : anterior.fallos + 1;
  intentosLogin.set(clave, { fallos, ultimoFallo: ahora });
}

// ---------------------------------------------------------------- roles

const ROLES_GESTION_OBRA = ["AdministradorSistema", "PersonalAdministrativo"];
const ROLES_AVANCE = ["AdministradorSistema", "PersonalTecnico"];
const ROLES_DOC = ["AdministradorSistema", "PersonalAdministrativo", "PersonalTecnico"];
const ROLES_REPORTE_APROBAR = ["AdministradorSistema", "PersonalAdministrativo"];
const ROLES_ADMIN = ["AdministradorSistema"];

interface Sesion {
  id_usuario: number;
  nombre: string;
  email: string;
  rol: string;
}

/** El token simulado es "mock.<id_usuario>"; no hay firma ni criptografia. */
function usuarioDeToken(headers: Headers): Sesion | null {
  const auth = headers.get("Authorization") ?? "";
  const m = /^Bearer\s+mock\.(\d+)$/.exec(auth);
  if (!m) return null;
  const u = db.usuarios.find((x) => Number(x.id_usuario) === Number(m[1]));
  if (!u || u.activo === false) return null;
  return {
    id_usuario: Number(u.id_usuario),
    nombre: String(u.nombre),
    email: String(u.email),
    rol: String(u.rol),
  };
}

// ---------------------------------------------------------------- derivados

/** Fecha YYYY-MM-DD a milisegundos UTC, ignorando la hora. */
function dia(iso: string): number {
  const [a, m, d] = String(iso).slice(0, 10).split("-").map(Number);
  return Date.UTC(a, (m || 1) - 1, d || 1);
}

/** Hoy en la misma escala que dia(), reutilizando la fecha que ya usa el resto del simulador. */
function hoyEnDias(): number {
  return dia(hoy());
}

/**
 * Avance esperado a la fecha, igual que EtapaPlanificacionController::
 * calcularEsperadoHoy() en el backend real: cada etapa aporta su peso segun
 * la fraccion de su plazo ya transcurrida. Si la planificacion no tiene
 * etapas se usa avance_esperado_total como valor de reserva.
 */
function esperadoDe(idPlan: number, fallback: number): number {
  const etapas = db.etapas
    .filter((e) => Number(e.id_planificacion) === idPlan)
    .sort((a, b) => num(a.orden) - num(b.orden) || num(a.id_etapa) - num(b.id_etapa));
  if (!etapas.length) return fallback;

  const ahora = hoyEnDias();
  const total = etapas.reduce((suma, e) => {
    const inicio = dia(String(e.fecha_inicio));
    const fin = dia(String(e.fecha_fin));
    const duracion = fin - inicio;
    const fraccion =
      duracion <= 0
        ? ahora >= fin
          ? 1
          : 0
        : Math.max(0, Math.min(1, (ahora - inicio) / duracion));
    return suma + fraccion * num(e.peso_porcentual);
  }, 0);
  return redondear(total);
}

function consumidoDe(idAsignacion: number): number {
  return db.consumos
    .filter((c) => Number(c.id_asignacion) === idAsignacion)
    .reduce((s, c) => s + num(c.cantidad_consumida), 0);
}

function asignacionesDe(idProyecto: number): Fila[] {
  return db.asignaciones
    .filter((a) => Number(a.id_proyecto) === idProyecto)
    .map((a) => {
      const material = db.materiales.find((m) => Number(m.id_material) === Number(a.id_material));
      const asignada = num(a.cantidad_asignada);
      const consumido = consumidoDe(Number(a.id_asignacion));
      return {
        id_asignacion: Number(a.id_asignacion),
        id_material: Number(a.id_material),
        nombre: String(material?.nombre ?? "Material"),
        unidad: String(material?.unidad ?? ""),
        cantidad_asignada: asignada,
        consumido: redondear(consumido),
        restante: redondear(asignada - consumido),
        excedido: consumido > asignada,
      };
    });
}

function resumenDe(idPlan: number) {
  const plan = db.planificaciones.find((p) => Number(p.id_planificacion) === idPlan);
  const avances = db.avances.filter((a) => Number(a.id_planificacion) === idPlan);
  const real = avances.reduce((max, a) => Math.max(max, num(a.porcentaje_avance)), 0);
  const esperado = esperadoDe(idPlan, num(plan?.avance_esperado_total));
  const fechas = avances.map((a) => String(a.fecha)).sort();
  return {
    avance_esperado: esperado,
    avance_real: real,
    desvio_pp: redondear(real - esperado),
    total_registros: avances.length,
    ultimo_registro: fechas.length ? fechas[fechas.length - 1] : null,
  };
}

/**
 * Sincroniza el estado y el avance de la obra a partir de sus avances fisicos,
 * igual que AvanceController::sincronizarProyecto() en el backend real.
 */
function sincronizarProyecto(idPlan: number): void {
  const plan = db.planificaciones.find((p) => Number(p.id_planificacion) === idPlan);
  if (!plan) return;
  const proyecto = db.proyectos.find((p) => String(p.id) === String(plan.id_proyecto));
  if (!proyecto) return;
  const real = db.avances
    .filter((a) => Number(a.id_planificacion) === idPlan)
    .reduce((max, a) => Math.max(max, num(a.porcentaje_avance)), 0);
  proyecto.avance = real;
  // El avance NO finaliza la obra: la cierra el supervisor al aprobar el
  // reporte final, igual que en AvanceController::sincronizarProyecto().
  if (proyecto.estado === "planificacion" && real > 0) proyecto.estado = "en_ejecucion";
}

function analisis(rol: string) {
  const ocultarCostos = rol === "PersonalTecnico";
  const proyectos = db.proyectos.map((p) => {
    const plan = db.planificaciones.find((pl) => String(pl.id_proyecto) === String(p.id));
    const real = num(p.avance);
    const esperado = plan
      ? esperadoDe(Number(plan.id_planificacion), num(plan.avance_esperado_total))
      : null;
    const excedidos = asignacionesDe(Number(p.id)).filter((a) => a.excedido).length;
    const fila: Record<string, unknown> = {
      id_proyecto: Number(p.id),
      nombre: String(p.nombre),
      estado: String(p.estado),
      avance_real: real,
      avance_esperado: esperado,
      desvio_avance: esperado === null ? null : redondear(real - esperado),
      alerta_avance: esperado !== null && real < esperado,
      materiales_excedidos: excedidos,
    };
    if (!ocultarCostos) {
      const presupuesto = num(p.presupuesto);
      const ejecutado = redondear((presupuesto * real) / 100);
      fila.presupuesto = presupuesto;
      fila.ejecutado = ejecutado;
      fila.diferencia = redondear(presupuesto - ejecutado);
      const etapas = plan
        ? db.etapas.filter((e) => Number(e.id_planificacion) === Number(plan.id_planificacion))
        : [];
      fila.presupuesto_base_total = etapas.length
        ? etapas.reduce((s, e) => s + num(e.presupuesto_base), 0)
        : null;
    }
    return fila;
  });

  const alertas: Array<Record<string, string>> = [];
  for (const p of proyectos) {
    const desvio = p.desvio_avance as number | null;
    if (p.alerta_avance && desvio !== null) {
      alertas.push({
        tipo: "avance",
        gravedad: desvio <= -20 ? "alta" : desvio <= -10 ? "media" : "baja",
        proyecto: String(p.nombre),
        mensaje: `El avance real (${p.avance_real}%) esta ${Math.abs(desvio)} puntos por debajo del esperado (${p.avance_esperado}%).`,
      });
    }
    if (Number(p.materiales_excedidos) > 0) {
      alertas.push({
        tipo: "material",
        gravedad: "media",
        proyecto: String(p.nombre),
        mensaje: `${p.materiales_excedidos} material(es) superaron la cantidad asignada.`,
      });
    }
  }
  return { proyectos, alertas };
}

function maquinariaConTotales() {
  return db.maquinaria.map((m) => {
    const regs = db.registros_maquinaria.filter(
      (r) => Number(r.id_maquinaria) === Number(m.id_maquinaria)
    );
    const horas = regs.reduce((s, r) => s + num(r.horas_uso), 0);
    const combustible = regs.reduce((s, r) => s + num(r.combustible_consumido), 0);
    const produccion = regs.reduce((s, r) => s + num(r.produccion_realizada), 0);
    return {
      id_maquinaria: Number(m.id_maquinaria),
      nombre: String(m.nombre),
      tipo: String(m.tipo),
      activa: m.activa !== false,
      horas: redondear(horas),
      combustible: redondear(combustible),
      produccion: redondear(produccion),
      combustible_por_hora: horas > 0 ? redondear(combustible / horas) : 0,
      produccion_por_hora: horas > 0 ? redondear(produccion / horas) : 0,
      fallas_abiertas: db.fallas_maquinaria.filter(
        (f) => Number(f.id_maquinaria) === Number(m.id_maquinaria) && f.resuelto !== true
      ).length,
    };
  });
}

function registrosDeMaquina(idMaq: number) {
  const regs = db.registros_maquinaria.filter((r) => Number(r.id_maquinaria) === idMaq);
  const horas = regs.reduce((s, r) => s + num(r.horas_uso), 0);
  const combustible = regs.reduce((s, r) => s + num(r.combustible_consumido), 0);
  const promedio = horas > 0 ? combustible / horas : 0;
  return regs.map((r) => {
    const h = num(r.horas_uso);
    const cph = h > 0 ? redondear(num(r.combustible_consumido) / h) : 0;
    return {
      id_registro: Number(r.id_registro),
      id_maquinaria: idMaq,
      id_proyecto: r.id_proyecto === null ? null : Number(r.id_proyecto),
      fecha: String(r.fecha),
      operario: (r.operario as string) ?? null,
      horas_uso: h,
      combustible_consumido: num(r.combustible_consumido),
      produccion_realizada: num(r.produccion_realizada),
      combustible_por_hora: cph,
      // RF24: consumo anomalo si supera 1,5 veces el promedio de la maquina.
      alerta_consumo: promedio > 0 && cph > promedio * 1.5,
    };
  });
}

function rendimientoOperarios() {
  const porOperario = new Map<string, { horas: number; produccion: number; combustible: number }>();
  for (const r of db.registros_maquinaria) {
    const op = texto(r.operario);
    if (!op) continue;
    const acum = porOperario.get(op) ?? { horas: 0, produccion: 0, combustible: 0 };
    acum.horas += num(r.horas_uso);
    acum.produccion += num(r.produccion_realizada);
    acum.combustible += num(r.combustible_consumido);
    porOperario.set(op, acum);
  }
  return [...porOperario.entries()]
    .map(([operario, a]) => ({
      operario,
      horas: redondear(a.horas),
      produccion: redondear(a.produccion),
      combustible: redondear(a.combustible),
      produccion_por_hora: a.horas > 0 ? redondear(a.produccion / a.horas) : 0,
    }))
    .sort((x, y) => y.produccion_por_hora - x.produccion_por_hora);
}

function reporteCompleto(r: Fila) {
  const proyecto = db.proyectos.find((p) => String(p.id) === String(r.id_proyecto));
  const autor = db.usuarios.find((u) => Number(u.id_usuario) === Number(r.id_usuario));
  return {
    ...r,
    id_reporte: Number(r.id_reporte),
    id_proyecto: Number(r.id_proyecto),
    // Los reportes que ya estaban en datos.json no traen el campo: un reporte
    // que se cargo antes de que existiera la marca no es el de cierre.
    es_final: Boolean(r.es_final),
    proyecto: String(proyecto?.nombre ?? "Obra eliminada"),
    autor: String(autor?.nombre ?? "Usuario"),
  };
}

// --- cierre de obra por reporte final -------------------------------------
// Mismas reglas que ReporteController: enviar el reporte final lleva la obra a
// `en_revision`, aprobarlo la finaliza y rechazarlo la devuelve a
// `en_ejecucion`. En el mock las obras se buscan por `id`, no por `id_proyecto`.

/** Estado actual de la obra, o null si no existe. */
function estadoObra(idProyecto: string): string | null {
  const obra = db.proyectos.find((p) => String(p.id) === String(idProyecto));
  return obra ? texto(obra.estado) : null;
}

/** Mueve la obra y devuelve el estado nuevo, para que el front lo refleje. */
function moverObra(idProyecto: string, estado: string): string {
  const obra = db.proyectos.find((p) => String(p.id) === String(idProyecto));
  if (obra) obra.estado = estado;
  return estado;
}

/** Si la obra ya tiene otro reporte final ocupando el cierre. */
function hayOtroFinalVigente(idProyecto: string, idExcluido: number): boolean {
  return db.reportes.some(
    (r) =>
      String(r.id_proyecto) === String(idProyecto) &&
      Boolean(r.es_final) &&
      Number(r.id_reporte) !== idExcluido &&
      ["en_revision", "aprobado"].includes(texto(r.estado))
  );
}

/** Si la obra de esa planificacion esta cancelada (AvanceController::obraCancelada). */
function obraCanceladaDePlan(idPlan: number): boolean {
  const plan = db.planificaciones.find((p) => Number(p.id_planificacion) === idPlan);
  if (!plan) return false;
  const obra = db.proyectos.find((p) => String(p.id) === String(plan.id_proyecto));
  return obra ? texto(obra.estado) === "cancelada" : false;
}

/** Si la obra tiene un reporte final esperando la revision del supervisor. */
function tieneFinalEnRevision(idProyecto: number): boolean {
  return db.reportes.some(
    (r) =>
      Number(r.id_proyecto) === idProyecto &&
      Boolean(r.es_final) &&
      texto(r.estado) === "en_revision"
  );
}

/** Agrega `estado_proyecto` a la respuesta solo si la obra se movio. */
function conEstadoProyecto(cuerpo: object, estado: string | null): object {
  return estado === null ? cuerpo : { ...cuerpo, estado_proyecto: estado };
}

/** RF20: el PersonalTecnico no ve el presupuesto de la obra. */
function proyectoSegunRol(p: Fila, rol: string): Fila {
  if (rol !== "PersonalTecnico") return { ...p };
  const copia = { ...p };
  delete copia.presupuesto;
  return copia;
}

// ---------------------------------------------------------------- despacho

/** Borra de una coleccion por id y devuelve la respuesta correspondiente. */
/**
 * Valida un rango de fechas como lo hace el backend: formato ISO en las dos y
 * el fin nunca antes del inicio. El simulador tiene que rechazar lo mismo que
 * PHP, o la demo acepta datos que el sistema real no.
 */
function validarRango(
  inicio: string,
  fin: string,
  errores: Record<string, string>,
  inicioObligatorio = true
): void {
  const iso = /^\d{4}-\d{2}-\d{2}$/;
  if (inicioObligatorio && !iso.test(inicio)) errores.fecha_inicio = "Formato esperado: YYYY-MM-DD";
  if (fin !== "" && !iso.test(fin)) errores.fecha_fin = "Formato esperado: YYYY-MM-DD";
  if (fin !== "" && !errores.fecha_inicio && !errores.fecha_fin && fin < inicio) {
    errores.fecha_fin = "La fecha de fin no puede ser anterior al inicio";
  }
}

/** Estados desde los que una obra puede pasar a `pausada` (TP3). */
const EN_MARCHA = ["en_ejecucion", "en_revision"];

/**
 * Un periodo esta vigente si ya empezo y todavia no termino. La fecha de fin es
 * el dia en que la obra vuelve a arrancar, no el ultimo dia parado: por eso se
 * compara con `>` y cerrar un periodo hoy reactiva la obra hoy.
 */
function periodoVigente(p: Fila, dia = hoy()): boolean {
  const inicio = texto(p.fecha_inicio);
  const fin = p.fecha_fin == null ? "" : texto(p.fecha_fin);
  return inicio <= dia && (fin === "" || fin > dia);
}

/**
 * Reproduce InactividadController::sincronizarEstado(): con al menos un periodo
 * vigente la obra queda `pausada`; cuando no queda ninguno, vuelve a
 * `en_ejecucion`. Devuelve el estado nuevo, o null si no hubo cambio.
 */
function sincronizarPorInactividad(idProyecto: number): string | null {
  const obra = db.proyectos.find((p) => String(p.id) === String(idProyecto));
  if (!obra) return null;
  const vigentes = db.inactividades.filter(
    (p) => Number(p.id_proyecto) === idProyecto && periodoVigente(p)
  ).length;
  let nuevo: string;
  if (vigentes > 0 && EN_MARCHA.includes(texto(obra.estado))) nuevo = "pausada";
  else if (vigentes === 0 && texto(obra.estado) === "pausada") {
    // Igual que InactividadController: si hay un reporte final esperando
    // revision, la obra estaba cerrandose y vuelve a `en_revision`.
    nuevo = tieneFinalEnRevision(idProyecto) ? "en_revision" : "en_ejecucion";
  } else return null;
  obra.estado = nuevo;
  guardar();
  return nuevo;
}

function eliminarDe(coleccion: Fila[], campo: string, id: number): Response {
  const i = coleccion.findIndex((f) => Number(f[campo]) === id);
  if (i === -1) return noEncontrado();
  coleccion.splice(i, 1);
  guardar();
  return sinContenido();
}

async function despachar(ruta: string, opciones: RequestInit): Promise<Response> {
  const [camino, consulta = ""] = ruta.split("?");
  const s = camino.split("/").filter(Boolean);
  const metodo = (opciones.method ?? "GET").toUpperCase();
  const headers = new Headers(opciones.headers);
  const params = new URLSearchParams(consulta);
  const cuerpo: Fila =
    typeof opciones.body === "string" && opciones.body ? JSON.parse(opciones.body) : {};

  // ----- /auth (rutas publicas + sesion)
  if (s[0] === "auth") {
    if (metodo === "POST" && s[1] === "login") {
      const email = texto(cuerpo.email);
      const contrasena = String(cuerpo.contrasena ?? "");
      if (email === "" || contrasena === "") {
        return json(400, { error: "Email y contrasena son obligatorios" });
      }

      const clave = claveDeCuenta(email);
      const espera = esperaDeCuenta(clave);
      // Se consulta antes de validar la clave, para que ni la correcta saltee el bloqueo.
      if (espera > 0) {
        const minutos = Math.ceil(espera / 60);
        return json(429, {
          error: `Demasiados intentos fallidos. Esperá ${minutos} ${minutos === 1 ? "minuto" : "minutos"} y volvé a probar.`,
          reintentar_en_segundos: espera,
        }, { "Retry-After": String(espera) });
      }

      const u = db.usuarios.find((x) => String(x.email).toLowerCase() === clave);
      if (!u || u.contrasena !== contrasena) {
        registrarFallo(clave);
        return json(401, { error: "Credenciales invalidas" });
      }
      // Revelar la baja solo después de conocer la contraseña evita enumerar cuentas.
      if (u.activo === false) return json(403, { error: "Usuario inactivo" });

      intentosLogin.delete(clave);
      return ok({
        token: `mock.${u.id_usuario}`,
        usuario: {
          id_usuario: Number(u.id_usuario),
          nombre: String(u.nombre),
          email: String(u.email),
          rol: String(u.rol),
        },
      });
    }
    if (metodo === "POST" && s[1] === "olvide") {
      return ok({ mensaje: "Modo de prueba: no se envian correos. Usa las cuentas de demostracion." });
    }
    if (metodo === "POST" && s[1] === "restablecer") {
      return ok({ mensaje: "Modo de prueba: la contrasena no se modifica." });
    }

    const sesion = usuarioDeToken(headers);
    if (!sesion) return noAutenticado();

    if (metodo === "GET" && s[1] === "me") return ok(sesion);

    if (metodo === "POST" && s[1] === "register") {
      if (!ROLES_ADMIN.includes(sesion.rol)) return prohibido();
      const email = texto(cuerpo.email).toLowerCase();
      const errores: Record<string, string> = {};
      if (!texto(cuerpo.nombre)) errores.nombre = "Obligatorio";
      if (!email) errores.email = "Obligatorio";
      const contrasenaNueva = texto(cuerpo.contrasena);
      if (!contrasenaNueva) {
        errores.contrasena = "Obligatorio";
      } else {
        // Las mismas reglas que aplica el PHP (A-05).
        const problema = validarContrasena(contrasenaNueva);
        if (problema) errores.contrasena = problema;
      }
      if (Object.keys(errores).length) return json(422, { errors: errores });
      if (db.usuarios.some((u) => String(u.email).toLowerCase() === email)) {
        return json(409, { error: "El email ya esta registrado" });
      }
      const nuevo: Fila = {
        id_usuario: proximoId(db.usuarios, "id_usuario"),
        nombre: texto(cuerpo.nombre),
        email,
        contrasena: texto(cuerpo.contrasena),
        rol: texto(cuerpo.rol) || "PersonalTecnico",
        activo: true,
        fecha_creacion: hoy(),
      };
      db.usuarios.push(nuevo);
      guardar();
      const { contrasena: _omitida, ...publico } = nuevo;
      void _omitida;
      return creado(publico);
    }
    return noEncontrado("Ruta de autenticacion no encontrada");
  }

  // Misma forma que Sgso\Http\Salud: la API real ejecuta SELECT 1 (B-05).
  if (s[0] === "health") return ok({ status: "ok", db: "ok" });

  // ----- de aca en adelante todo exige sesion
  const sesion = usuarioDeToken(headers);
  if (!sesion) return noAutenticado();
  const rol = sesion.rol;
  const exige = (roles: string[]) => (roles.includes(rol) ? null : prohibido());

  // ----- /usuarios (HU16)
  if (s[0] === "usuarios") {
    const veto = exige(ROLES_ADMIN);
    if (veto) return veto;
    if (metodo === "GET" && s.length === 1) {
      return ok(
        db.usuarios.map(({ contrasena: _c, ...u }) => {
          void _c;
          return u;
        })
      );
    }
    if (metodo === "PUT" && s[1]) {
      const u = db.usuarios.find((x) => Number(x.id_usuario) === num(s[1]));
      if (!u) return noEncontrado("Usuario no encontrado");
      if (cuerpo.rol !== undefined) u.rol = cuerpo.rol;
      if (cuerpo.activo !== undefined) u.activo = Boolean(cuerpo.activo);
      guardar();
      const { contrasena: _c, ...publico } = u;
      void _c;
      return ok(publico);
    }
    return noEncontrado();
  }

  // ----- /analisis (RF11/RF13)
  if (s[0] === "analisis") return ok(analisis(rol));

  // ----- /materiales (catalogo)
  if (s[0] === "materiales" && s.length === 1) {
    if (metodo === "GET") return ok(db.materiales);
    if (metodo === "POST") {
      const veto = exige(ROLES_GESTION_OBRA);
      if (veto) return veto;
      const nombre = texto(cuerpo.nombre);
      if (!nombre) return json(422, { errors: { nombre: "Obligatorio" } });
      if (db.materiales.some((m) => String(m.nombre).toLowerCase() === nombre.toLowerCase())) {
        return json(409, { error: "El material ya existe" });
      }
      const nuevo = {
        id_material: proximoId(db.materiales, "id_material"),
        nombre,
        unidad: texto(cuerpo.unidad) || "unidad",
      };
      db.materiales.push(nuevo);
      guardar();
      return creado(nuevo);
    }
  }

  // ----- /reportes (RF17/RF21)
  if (s[0] === "reportes") {
    if (metodo === "GET" && s.length === 1) {
      const estado = params.get("estado");
      const filas = estado ? db.reportes.filter((r) => r.estado === estado) : db.reportes;
      return ok(filas.map(reporteCompleto));
    }
    if (metodo === "POST" && s.length === 1) {
      const veto = exige(ROLES_DOC);
      if (veto) return veto;
      const errores: Record<string, string> = {};
      if (!num(cuerpo.id_proyecto)) errores.id_proyecto = "Obligatorio";
      if (!texto(cuerpo.titulo)) errores.titulo = "Obligatorio";
      if (!texto(cuerpo.contenido)) errores.contenido = "Obligatorio";
      if (Object.keys(errores).length) return json(422, { errors: errores });
      const nuevo: Fila = {
        id_reporte: proximoId(db.reportes, "id_reporte"),
        id_proyecto: num(cuerpo.id_proyecto),
        id_usuario: sesion.id_usuario,
        titulo: texto(cuerpo.titulo),
        contenido: texto(cuerpo.contenido),
        estado: "borrador",
        es_final: Boolean(cuerpo.es_final),
        observacion_revision: null,
        fecha_creacion: hoy(),
        fecha_revision: null,
      };
      db.reportes.push(nuevo);
      guardar();
      return creado(reporteCompleto(nuevo));
    }

    const r = db.reportes.find((x) => Number(x.id_reporte) === num(s[1]));
    if (!r) return noEncontrado("Reporte no encontrado");

    if (metodo === "PUT" && s.length === 2) {
      const veto = exige(ROLES_DOC);
      if (veto) return veto;
      if (r.estado !== "borrador" && r.estado !== "rechazado") {
        return json(409, { error: "Solo se puede editar un reporte en borrador o rechazado" });
      }
      r.titulo = texto(cuerpo.titulo) || r.titulo;
      r.contenido = texto(cuerpo.contenido) || r.contenido;
      if (cuerpo.es_final !== undefined) r.es_final = Boolean(cuerpo.es_final);
      guardar();
      return ok(reporteCompleto(r));
    }
    if (metodo === "DELETE" && s.length === 2) {
      const veto = exige(ROLES_DOC);
      if (veto) return veto;
      // Igual que ReporteController::eliminar(): borrar el reporte final
      // mientras esta en revision dejaba la obra trabada en `en_revision`,
      // sin ninguna transicion que la sacara de ahi.
      if (r.estado !== "borrador" && r.estado !== "rechazado") {
        return json(409, { error: "Solo se puede eliminar un reporte en borrador o rechazado" });
      }
      return eliminarDe(db.reportes, "id_reporte", num(s[1]));
    }
    if (metodo === "POST" && s[2] === "enviar") {
      const veto = exige(ROLES_DOC);
      if (veto) return veto;
      if (r.estado !== "borrador" && r.estado !== "rechazado") {
        return json(409, { error: "El reporte ya fue enviado" });
      }
      // Mismos controles que ReporteController::enviar().
      const idObraEnvio = String(r.id_proyecto);
      if (r.es_final) {
        if (hayOtroFinalVigente(idObraEnvio, Number(r.id_reporte))) {
          return json(409, { error: "La obra ya tiene un reporte final en revisión o aprobado" });
        }
        if (estadoObra(idObraEnvio) !== "en_ejecucion") {
          return json(409, {
            error: "Solo se puede enviar el reporte final de una obra en ejecución",
          });
        }
      }
      r.estado = "en_revision";
      const estadoTrasEnviar = r.es_final ? moverObra(idObraEnvio, "en_revision") : null;
      guardar();
      return ok(conEstadoProyecto(reporteCompleto(r), estadoTrasEnviar));
    }
    if (metodo === "POST" && (s[2] === "aprobar" || s[2] === "rechazar")) {
      const veto = exige(ROLES_REPORTE_APROBAR);
      if (veto) return veto;
      if (r.estado !== "en_revision") {
        return json(409, { error: "Solo se puede revisar un reporte en revision" });
      }
      // Igual que ReporteController::rechazar(): sin motivo no hay rechazo.
      // Va antes de tocar el estado, porque si no la demo aceptaba un rechazo
      // vacio que la API real corta con 422.
      if (s[2] === "rechazar" && texto(cuerpo.observacion).trim() === "") {
        return json(422, { errors: { observacion: "Indicá el motivo del rechazo" } });
      }
      r.estado = s[2] === "aprobar" ? "aprobado" : "rechazado";
      r.observacion_revision = texto(cuerpo.observacion) || null;
      r.fecha_revision = hoy();
      // Solo se mueve una obra que este en revision: si mientras tanto la
      // pausaron o la cancelaron, se la deja donde esta.
      let estadoTrasResolver: string | null = null;
      if (r.es_final) {
        const idObraResol = String(r.id_proyecto);
        if (estadoObra(idObraResol) === "en_revision") {
          estadoTrasResolver = moverObra(
            idObraResol,
            s[2] === "aprobar" ? "finalizada" : "en_ejecucion"
          );
        }
      }
      guardar();
      return ok(conEstadoProyecto(reporteCompleto(r), estadoTrasResolver));
    }
    return noEncontrado();
  }

  // ----- /maquinaria (RF23/RF24/RF27/RF28)
  if (s[0] === "maquinaria") {
    if (metodo === "GET" && s.length === 1) return ok(maquinariaConTotales());
    if (metodo === "GET" && s[1] === "operarios") return ok(rendimientoOperarios());
    if (metodo === "POST" && s.length === 1) {
      const veto = exige(ROLES_GESTION_OBRA);
      if (veto) return veto;
      if (!texto(cuerpo.nombre)) return json(422, { errors: { nombre: "Obligatorio" } });
      const nueva: Fila = {
        id_maquinaria: proximoId(db.maquinaria, "id_maquinaria"),
        nombre: texto(cuerpo.nombre),
        tipo: texto(cuerpo.tipo) || "General",
        activa: true,
      };
      db.maquinaria.push(nueva);
      guardar();
      return creado(nueva);
    }
    if (metodo === "DELETE" && s[1] === "registro") {
      const veto = exige(ROLES_DOC);
      if (veto) return veto;
      return eliminarDe(db.registros_maquinaria, "id_registro", num(s[2]));
    }
    if (metodo === "DELETE" && s[1] === "falla") {
      const veto = exige(ROLES_DOC);
      if (veto) return veto;
      return eliminarDe(db.fallas_maquinaria, "id_falla", num(s[2]));
    }
    const idMaq = num(s[1]);
    if (metodo === "DELETE" && s.length === 2) {
      const veto = exige(ROLES_GESTION_OBRA);
      if (veto) return veto;
      return eliminarDe(db.maquinaria, "id_maquinaria", idMaq);
    }
    if (s[2] === "registros") {
      if (metodo === "GET") return ok(registrosDeMaquina(idMaq));
      if (metodo === "POST") {
        const veto = exige(ROLES_DOC);
        if (veto) return veto;
        const nuevo: Fila = {
          id_registro: proximoId(db.registros_maquinaria, "id_registro"),
          id_maquinaria: idMaq,
          id_proyecto: cuerpo.id_proyecto ? num(cuerpo.id_proyecto) : null,
          fecha: texto(cuerpo.fecha) || hoy(),
          operario: texto(cuerpo.operario) || null,
          horas_uso: num(cuerpo.horas_uso),
          combustible_consumido: num(cuerpo.combustible_consumido),
          produccion_realizada: num(cuerpo.produccion_realizada),
        };
        db.registros_maquinaria.push(nuevo);
        guardar();
        return creado({ id_registro: nuevo.id_registro });
      }
    }
    if (s[2] === "fallas") {
      if (metodo === "GET") {
        return ok(db.fallas_maquinaria.filter((f) => Number(f.id_maquinaria) === idMaq));
      }
      if (metodo === "POST") {
        const veto = exige(ROLES_DOC);
        if (veto) return veto;
        if (!texto(cuerpo.descripcion)) return json(422, { errors: { descripcion: "Obligatorio" } });
        const nueva: Fila = {
          id_falla: proximoId(db.fallas_maquinaria, "id_falla"),
          id_maquinaria: idMaq,
          fecha: texto(cuerpo.fecha) || hoy(),
          componente: texto(cuerpo.componente) || null,
          descripcion: texto(cuerpo.descripcion),
          reemplazo: Boolean(cuerpo.reemplazo),
          resuelto: Boolean(cuerpo.resuelto),
        };
        db.fallas_maquinaria.push(nueva);
        guardar();
        return creado({ id_falla: nueva.id_falla });
      }
    }
    return noEncontrado();
  }

  // ----- /planificacion
  if (s[0] === "planificacion") {
    if (s[1] === "etapa") {
      const veto = exige(ROLES_GESTION_OBRA);
      if (veto) return veto;
      if (metodo === "DELETE") return eliminarDe(db.etapas, "id_etapa", num(s[2]));
      if (metodo === "PUT") {
        const e = db.etapas.find((x) => Number(x.id_etapa) === num(s[2]));
        if (!e) return noEncontrado("Etapa no encontrada");
        Object.assign(e, cuerpo);
        guardar();
        return ok(e);
      }
    }
    const idPlan = num(s[1]);
    if (metodo === "PUT" && s.length === 2) {
      const veto = exige(ROLES_GESTION_OBRA);
      if (veto) return veto;
      const plan = db.planificaciones.find((p) => Number(p.id_planificacion) === idPlan);
      if (!plan) return noEncontrado("Planificacion no encontrada");
      if (cuerpo.avance_esperado_total !== undefined) {
        plan.avance_esperado_total = num(cuerpo.avance_esperado_total);
      }
      guardar();
      return ok(plan);
    }
    if (s[2] === "etapas") {
      if (metodo === "GET") {
        return ok(
          db.etapas
            .filter((e) => Number(e.id_planificacion) === idPlan)
            .sort((a, b) => num(a.orden) - num(b.orden))
        );
      }
      if (metodo === "POST") {
        const veto = exige(ROLES_GESTION_OBRA);
        if (veto) return veto;
        // Mismas validaciones que EtapaPlanificacionController::validar().
        const errores: Record<string, string> = {};
        if (!texto(cuerpo.nombre)) errores.nombre = "Obligatorio";
        const peso = num(cuerpo.peso_porcentual);
        if (cuerpo.peso_porcentual === undefined || peso < 0 || peso > 100) {
          errores.peso_porcentual = "Debe ser un número entre 0 y 100";
        }
        validarRango(texto(cuerpo.fecha_inicio), texto(cuerpo.fecha_fin), errores);
        if (num(cuerpo.presupuesto_base) < 0) {
          errores.presupuesto_base = "Debe ser un número mayor o igual a 0";
        }
        const suma = db.etapas
          .filter((e) => Number(e.id_planificacion) === idPlan)
          .reduce((t, e) => t + num(e.peso_porcentual), 0);
        if (!errores.peso_porcentual && suma + peso > 100.01) {
          errores.peso_porcentual =
            `La suma de pesos superaría 100%. Suma actual: ${suma.toFixed(2)}%. ` +
            `Peso disponible: ${Math.max(0, 100 - suma).toFixed(2)}%.`;
        }
        if (Object.keys(errores).length) return json(422, { errors: errores });

        const nueva: Fila = {
          id_etapa: proximoId(db.etapas, "id_etapa"),
          id_planificacion: idPlan,
          nombre: texto(cuerpo.nombre),
          peso_porcentual: num(cuerpo.peso_porcentual),
          fecha_inicio: texto(cuerpo.fecha_inicio) || hoy(),
          fecha_fin: texto(cuerpo.fecha_fin) || hoy(),
          orden: num(cuerpo.orden, db.etapas.filter((e) => Number(e.id_planificacion) === idPlan).length + 1),
          presupuesto_base: num(cuerpo.presupuesto_base),
        };
        db.etapas.push(nueva);
        guardar();
        return creado(nueva);
      }
    }
    if (s[2] === "avances") {
      if (s[3] === "resumen" && metodo === "GET") return ok(resumenDe(idPlan));
      if (metodo === "GET") {
        return ok(
          db.avances
            .filter((a) => Number(a.id_planificacion) === idPlan)
            .sort((a, b) => String(a.fecha).localeCompare(String(b.fecha)))
        );
      }
      if (metodo === "POST") {
        const veto = exige(ROLES_AVANCE);
        if (veto) return veto;
        // Igual que AvanceController::crear(): una obra cancelada se dio por
        // terminada, cargarle avance le hace subir el porcentaje en el
        // dashboard. El mock no tiene edicion de avance, asi que el control
        // solo hace falta aca.
        if (obraCanceladaDePlan(idPlan)) {
          return json(409, { error: "No se puede registrar avance en una obra cancelada" });
        }
        const nuevo: Fila = {
          id_avance: proximoId(db.avances, "id_avance"),
          id_planificacion: idPlan,
          cantidad_ejecutada: num(cuerpo.cantidad_ejecutada),
          porcentaje_avance: num(cuerpo.porcentaje_avance),
          fecha: texto(cuerpo.fecha) || hoy(),
          observaciones: texto(cuerpo.observaciones) || null,
        };
        db.avances.push(nuevo);
        sincronizarProyecto(idPlan);
        guardar();
        return creado(nuevo);
      }
    }
    return noEncontrado();
  }

  // ----- /proyectos y sus subrecursos
  if (s[0] === "proyectos") {
    // Subrecursos que van por id propio, no por id de obra.
    const porId: Record<string, [Fila[], string, string[]]> = {
      asistencia: [db.asistencias, "id_asistencia", ROLES_AVANCE],
      incidencia: [db.incidencias, "id_incidencia", ROLES_AVANCE],
      consumo: [db.consumos, "id_consumo", ROLES_AVANCE],
      documento: [db.documentos, "id_documento", ROLES_DOC],
      excedente: [db.excedentes, "id_item", ROLES_DOC],
    };
    // Inactividad por id: cerrar (PUT) o eliminar (DELETE). Los dos reactivan
    // la obra si era el ultimo periodo vigente, pero cerrar conserva el
    // registro que RF25 pide y eliminar lo pierde.
    if (s[1] === "inactividad" && s[2] !== undefined && (metodo === "PUT" || metodo === "DELETE")) {
      const veto = exige(ROLES_DOC);
      if (veto) return veto;
      const idPer = num(s[2]);
      const i = db.inactividades.findIndex((p) => Number(p.id_periodo) === idPer);
      if (i === -1) return noEncontrado();
      const periodo = db.inactividades[i];
      const idProy = Number(periodo.id_proyecto);

      if (metodo === "DELETE") {
        db.inactividades.splice(i, 1);
        guardar();
        return ok({ mensaje: "Período eliminado", estado_proyecto: sincronizarPorInactividad(idProy) });
      }

      const fin = texto(cuerpo.fecha_fin) || hoy();
      if (!/^\d{4}-\d{2}-\d{2}$/.test(fin)) {
        return json(422, { errors: { fecha_fin: "Formato esperado: YYYY-MM-DD" } });
      }
      if (fin < texto(periodo.fecha_inicio)) {
        return json(422, { errors: { fecha_fin: "La fecha de fin no puede ser anterior al inicio" } });
      }
      periodo.fecha_fin = fin;
      guardar();
      return ok({ ...periodo, vigente: periodoVigente(periodo), estado_proyecto: sincronizarPorInactividad(idProy) });
    }

    if (metodo === "DELETE" && porId[s[1]]) {
      const [coleccion, campo, roles] = porId[s[1]];
      const veto = exige(roles);
      if (veto) return veto;
      return eliminarDe(coleccion, campo, num(s[2]));
    }
    if (s[1] === "material") {
      const idAsig = num(s[2]);
      if (metodo === "DELETE" && s.length === 3) {
        const veto = exige(ROLES_GESTION_OBRA);
        if (veto) return veto;
        db.consumos = db.consumos.filter((c) => Number(c.id_asignacion) !== idAsig);
        return eliminarDe(db.asignaciones, "id_asignacion", idAsig);
      }
      if (s[3] === "consumos") {
        if (metodo === "GET") {
          return ok(db.consumos.filter((c) => Number(c.id_asignacion) === idAsig));
        }
        if (metodo === "POST") {
          const veto = exige(ROLES_AVANCE);
          if (veto) return veto;
          const nuevo: Fila = {
            id_consumo: proximoId(db.consumos, "id_consumo"),
            id_asignacion: idAsig,
            fecha: texto(cuerpo.fecha) || hoy(),
            cantidad_consumida: num(cuerpo.cantidad_consumida),
            observaciones: texto(cuerpo.observaciones) || null,
          };
          db.consumos.push(nuevo);
          guardar();
          return creado(nuevo);
        }
      }
      return noEncontrado();
    }

    // Coleccion de obras.
    if (metodo === "GET" && s.length === 1) {
      const q = (params.get("q") ?? "").toLowerCase();
      const filas = q
        ? db.proyectos.filter(
            (p) =>
              String(p.nombre).toLowerCase().includes(q) ||
              String(p.ubicacion).toLowerCase().includes(q)
          )
        : db.proyectos;
      return ok(filas.map((p) => proyectoSegunRol(p, rol)));
    }
    if (metodo === "POST" && s.length === 1) {
      const veto = exige(ROLES_GESTION_OBRA);
      if (veto) return veto;
      const errores: Record<string, string> = {};
      for (const campo of ["nombre", "tipo", "ubicacion", "encargado", "fechaInicio"]) {
        if (!texto(cuerpo[campo])) errores[campo] = "Obligatorio";
      }
      if (!num(cuerpo.presupuesto)) errores.presupuesto = "Obligatorio";
      if (Object.keys(errores).length) return json(422, { errors: errores });
      const duplicado = db.proyectos.some(
        (p) =>
          String(p.nombre).toLowerCase() === texto(cuerpo.nombre).toLowerCase() &&
          String(p.ubicacion).toLowerCase() === texto(cuerpo.ubicacion).toLowerCase()
      );
      if (duplicado) return json(409, { error: "Obra ya existente" });
      const nuevo: Fila = {
        id: String(proximoId(db.proyectos, "id")),
        nombre: texto(cuerpo.nombre),
        tipo: texto(cuerpo.tipo),
        ubicacion: texto(cuerpo.ubicacion),
        encargado: texto(cuerpo.encargado),
        fechaInicio: texto(cuerpo.fechaInicio),
        // Toda obra nueva arranca en 'planificacion', igual que en PHP: si el
        // alta aceptara un estado, se saltearia la regla de transicion.
        estado: "planificacion",
        avance: num(cuerpo.avance),
        presupuesto: num(cuerpo.presupuesto),
      };
      db.proyectos.push(nuevo);
      guardar();
      return creado(proyectoSegunRol(nuevo, rol));
    }

    const idObra = s[1];
    const obra = db.proyectos.find((p) => String(p.id) === String(idObra));
    if (!obra) return noEncontrado("Proyecto no encontrado");

    if (s.length === 2) {
      if (metodo === "GET") return ok(proyectoSegunRol(obra, rol));
      const veto = exige(ROLES_GESTION_OBRA);
      if (veto) return veto;
      if (metodo === "PUT") {
        // Mismo control que ProyectoController::modificar(): cancelar es el
        // unico cambio de estado manual, y solo desde una obra en marcha. Si
        // el estado que llega es el que ya tiene, no hay nada que revisar.
        if (cuerpo.estado !== undefined) {
          const estadoNuevo = texto(cuerpo.estado);
          if (estadoNuevo !== "" && estadoNuevo !== texto(obra.estado)) {
            if (estadoNuevo !== "cancelada") {
              return json(422, {
                errors: {
                  estado:
                    'El unico estado que se asigna a mano es "cancelada"; el resto los mueve el sistema',
                },
              });
            }
            if (!ESTADOS_CANCELABLES.includes(texto(obra.estado))) {
              return json(409, {
                error: "Solo se puede cancelar una obra en ejecucion o pausada",
              });
            }
          }
        }
        for (const campo of ["nombre", "tipo", "ubicacion", "encargado", "fechaInicio", "estado"]) {
          if (cuerpo[campo] === undefined) continue;
          // Un estado vacio no es un cambio y no puede escribirse: dejaria la
          // obra fuera de la maquina de estados. En PHP el mismo caso llegaba
          // a un ENUM. Ver ProyectoController::modificar().
          if (campo === "estado" && texto(cuerpo[campo]).trim() === "") continue;
          obra[campo] = texto(cuerpo[campo]);
        }
        if (cuerpo.presupuesto !== undefined) obra.presupuesto = num(cuerpo.presupuesto);
        if (cuerpo.avance !== undefined) obra.avance = num(cuerpo.avance);
        guardar();
        return ok(proyectoSegunRol(obra, rol));
      }
      if (metodo === "DELETE") {
        const planes = db.planificaciones.filter((p) => String(p.id_proyecto) === String(idObra));
        for (const plan of planes) {
          db.avances = db.avances.filter(
            (a) => Number(a.id_planificacion) !== Number(plan.id_planificacion)
          );
          db.etapas = db.etapas.filter(
            (e) => Number(e.id_planificacion) !== Number(plan.id_planificacion)
          );
        }
        db.planificaciones = db.planificaciones.filter(
          (p) => String(p.id_proyecto) !== String(idObra)
        );
        db.asistencias = db.asistencias.filter((a) => String(a.id_proyecto) !== String(idObra));
        db.incidencias = db.incidencias.filter((i) => String(i.id_proyecto) !== String(idObra));
        db.documentos = db.documentos.filter((d) => String(d.id_proyecto) !== String(idObra));
        db.reportes = db.reportes.filter((r) => String(r.id_proyecto) !== String(idObra));
        db.inactividades = db.inactividades.filter((x) => String(x.id_proyecto) !== String(idObra));
        db.excedentes = db.excedentes.filter((x) => String(x.id_proyecto) !== String(idObra));
        const asigs = db.asignaciones.filter((a) => String(a.id_proyecto) === String(idObra));
        const ids = new Set(asigs.map((a) => Number(a.id_asignacion)));
        db.consumos = db.consumos.filter((c) => !ids.has(Number(c.id_asignacion)));
        db.asignaciones = db.asignaciones.filter((a) => String(a.id_proyecto) !== String(idObra));
        return eliminarDe(db.proyectos, "id", num(idObra));
      }
    }

    const sub = s[2];
    const idNum = Number(idObra);

    if (sub === "planificacion") {
      if (metodo === "GET") {
        const plan = db.planificaciones.find((p) => String(p.id_proyecto) === String(idObra));
        return plan ? ok(plan) : json(404, { error: "Sin planificacion" });
      }
      if (metodo === "POST") {
        const veto = exige(ROLES_GESTION_OBRA);
        if (veto) return veto;
        const existente = db.planificaciones.find((p) => String(p.id_proyecto) === String(idObra));
        if (existente) return json(409, { error: "La obra ya tiene planificacion" });
        const nueva: Fila = {
          id_planificacion: proximoId(db.planificaciones, "id_planificacion"),
          id_proyecto: idNum,
          avance_esperado_total: num(cuerpo.avance_esperado_total),
          fecha_carga: hoy(),
        };
        db.planificaciones.push(nueva);
        guardar();
        return creado(nueva);
      }
    }

    // Subcolecciones simples: listar y crear por obra.
    const simples: Record<string, [Fila[], string, string[], (c: Fila) => Fila]> = {
      asistencias: [
        db.asistencias,
        "id_asistencia",
        ROLES_AVANCE,
        (c) => ({
          id_proyecto: idNum,
          fecha: texto(c.fecha) || hoy(),
          trabajador: texto(c.trabajador),
          estado: texto(c.estado) || "presente",
          justificacion: texto(c.justificacion) || null,
        }),
      ],
      incidencias: [
        db.incidencias,
        "id_incidencia",
        ROLES_AVANCE,
        (c) => ({
          id_proyecto: idNum,
          fecha: texto(c.fecha) || hoy(),
          tipo: texto(c.tipo) || "otro",
          gravedad: texto(c.gravedad) || "media",
          descripcion: texto(c.descripcion),
          dias_retraso: num(c.dias_retraso),
        }),
      ],
      documentos: [
        db.documentos,
        "id_documento",
        ROLES_DOC,
        (c) => ({
          id_proyecto: idNum,
          nombre: texto(c.nombre),
          tipo: texto(c.tipo) || "otro",
          categoria: texto(c.categoria) || "General",
          url: texto(c.url),
          fecha_carga: hoy(),
        }),
      ],
      inactividades: [
        db.inactividades,
        "id_periodo",
        ROLES_DOC,
        (c) => ({
          id_proyecto: idNum,
          fecha_inicio: texto(c.fecha_inicio) || hoy(),
          fecha_fin: texto(c.fecha_fin) || null,
          motivo: texto(c.motivo),
        }),
      ],
      excedentes: [
        db.excedentes,
        "id_item",
        ROLES_DOC,
        (c) => ({
          id_proyecto: idNum,
          descripcion: texto(c.descripcion),
          cantidad: c.cantidad === undefined ? null : num(c.cantidad),
          unidad: texto(c.unidad) || null,
          fecha: texto(c.fecha) || hoy(),
          motivo: texto(c.motivo) || null,
        }),
      ],
    };

    if (simples[sub]) {
      const [coleccion, campoId, roles, construir] = simples[sub];
      if (metodo === "GET") {
        const filas = coleccion.filter((f) => String(f.id_proyecto) === String(idObra));
        if (sub === "inactividades") {
          // Mismo orden que el ORDER BY de InactividadController, y el flag que
          // la interfaz usa para saber cual periodo mantiene pausada la obra.
          return ok(
            filas
              .slice()
              .sort(
                (a, b) =>
                  texto(b.fecha_inicio).localeCompare(texto(a.fecha_inicio)) ||
                  Number(b.id_periodo) - Number(a.id_periodo)
              )
              .map((f) => ({ ...f, vigente: periodoVigente(f) }))
          );
        }
        return ok(filas);
      }
      if (metodo === "POST") {
        const veto = exige(roles);
        if (veto) return veto;
        if (sub === "inactividades") {
          // Mismas validaciones que InactividadController::crear().
          const errores: Record<string, string> = {};
          validarRango(texto(cuerpo.fecha_inicio), texto(cuerpo.fecha_fin), errores);
          if (!texto(cuerpo.motivo)) errores.motivo = "Obligatorio";
          if (Object.keys(errores).length) return json(422, { errors: errores });
        }
        if (sub === "documentos") {
          // Mismas validaciones que DocumentoController::crear(). La URL tiene que
          // ser http o https: un enlace javascript: ejecuta código al abrirlo (A-01).
          const errores: Record<string, string> = {};
          const tipo = cuerpo.tipo === undefined ? "otro" : String(cuerpo.tipo);
          if (!texto(cuerpo.nombre)) errores.nombre = "Obligatorio";
          if (!esEnlaceSeguro(texto(cuerpo.url))) errores.url = "Ingresá un enlace válido que empiece con http:// o https://";
          if (!["pdf", "imagen", "otro"].includes(tipo)) errores.tipo = "Tipo inválido";
          if (Object.keys(errores).length) return json(422, { errors: errores });
        }
        const nuevo = { [campoId]: proximoId(coleccion, campoId), ...construir(cuerpo) };
        coleccion.push(nuevo);
        guardar();
        if (sub === "inactividades") {
          // Registrar un periodo vigente pausa la obra (TP3).
          return creado({
            ...nuevo,
            vigente: periodoVigente(nuevo),
            estado_proyecto: sincronizarPorInactividad(idNum),
          });
        }
        return creado(nuevo);
      }
    }

    if (sub === "materiales") {
      if (metodo === "GET") return ok(asignacionesDe(idNum));
      if (metodo === "POST") {
        const veto = exige(ROLES_GESTION_OBRA);
        if (veto) return veto;
        const idMaterial = num(cuerpo.id_material);
        if (!idMaterial) return json(422, { errors: { id_material: "Obligatorio" } });
        if (
          db.asignaciones.some(
            (a) => Number(a.id_proyecto) === idNum && Number(a.id_material) === idMaterial
          )
        ) {
          return json(409, { error: "El material ya esta asignado a la obra" });
        }
        const nueva: Fila = {
          id_asignacion: proximoId(db.asignaciones, "id_asignacion"),
          id_proyecto: idNum,
          id_material: idMaterial,
          cantidad_asignada: num(cuerpo.cantidad_asignada),
        };
        db.asignaciones.push(nueva);
        guardar();
        return creado(nueva);
      }
    }

    return noEncontrado();
  }

  return noEncontrado(`Ruta no implementada en el modo de prueba: ${metodo} ${camino}`);
}

/** Punto de entrada usado por apiFetch cuando el modo de prueba esta activo. */
export async function mockFetch(ruta: string, opciones: RequestInit = {}): Promise<Response> {
  await esperar(DEMORA_MS);
  try {
    return await despachar(ruta, opciones);
  } catch (e) {
    return json(500, { error: `Error del servidor simulado: ${String(e)}` });
  }
}

if (typeof window !== "undefined") {
  (window as unknown as { sgsoMockReset: () => void }).sgsoMockReset = () => {
    reiniciar();
    window.location.reload();
  };

  // Costura de prueba: varias reglas del contrato (borrar un reporte enviado,
  // rechazar sin motivo, cargar avance en una obra cancelada) viven en la capa
  // de API y no hay forma de llegar a ellas desde la interfaz. Las suites de
  // `scripts/pruebas/` las ejercitan por aca.
  //
  // Nunca se ejecuta en produccion. Vite emite este archivo como un chunk
  // aparte (`assets/servidor-*.js`) en todos los builds, pero el `import()`
  // que lo carga esta detras de VITE_MOCK === "1" en auth/api.ts: sin esa
  // variable el chunk queda en dist sin que nadie lo pida, y este bloque no
  // corre. La costura no agrega superficie: si el modulo se cargara, el
  // simulador entero ya estaria activo.
  (window as unknown as { sgsoMockFetch: typeof mockFetch }).sgsoMockFetch = mockFetch;
}
