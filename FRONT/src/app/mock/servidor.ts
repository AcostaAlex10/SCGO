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

const CLAVE_ALMACEN = "sgso_mock_db_v1";
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
    const guardado = localStorage.getItem(CLAVE_ALMACEN);
    if (guardado) return JSON.parse(guardado) as BaseDatos;
  } catch {
    /* almacenamiento no disponible: seguimos con los datos base */
  }
  return JSON.parse(JSON.stringify(base)) as BaseDatos;
}

let db: BaseDatos = cargar();

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
  guardar();
}

function proximoId(filas: Fila[], campo: string): number {
  return filas.reduce((max, f) => Math.max(max, Number(f[campo]) || 0), 0) + 1;
}

// ---------------------------------------------------------------- utilidades

const esperar = (ms: number) => new Promise((r) => setTimeout(r, ms));

function json(estado: number, cuerpo: unknown): Response {
  return new Response(JSON.stringify(cuerpo), {
    status: estado,
    headers: { "Content-Type": "application/json" },
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

/**
 * Si un consumo de `cantidad` no entra en lo que queda de la asignación, el
 * mensaje de la API ("Stock insuficiente: quedan 6 bolsa"); si entra, null.
 * Misma cuenta en centésimos que Sgso\Reglas\StockMaterial en main (CU22,
 * DEF-05 del informe de testing del Grupo 9).
 */
function faltaStock(asignacion: Fila, cantidad: number): string | null {
  const centesimos = (n: number) => Math.round(n * 100);
  const asignada = centesimos(num(asignacion.cantidad_asignada));
  const consumido = centesimos(consumidoDe(Number(asignacion.id_asignacion)));
  if (centesimos(cantidad) <= asignada - consumido) return null;
  const material = db.materiales.find((m) => Number(m.id_material) === Number(asignacion.id_material));
  const queda = Math.max(0, asignada - consumido) / 100;
  return `Stock insuficiente: quedan ${String(queda).replace(".", ",")} ${String(material?.unidad ?? "")}`;
}

function nombreMaterial(asignacion: Fila): string {
  return String(db.materiales.find((m) => Number(m.id_material) === Number(asignacion.id_material))?.nombre ?? "Material");
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
  if (proyecto.estado === "planificacion" && real > 0) proyecto.estado = "en_ejecucion";
  if (real >= 100 && proyecto.estado === "en_ejecucion") proyecto.estado = "finalizada";
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
    proyecto: String(proyecto?.nombre ?? "Obra eliminada"),
    autor: String(autor?.nombre ?? "Usuario"),
  };
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
      const email = texto(cuerpo.email).toLowerCase();
      const u = db.usuarios.find((x) => String(x.email).toLowerCase() === email);
      if (!u || u.contrasena !== cuerpo.contrasena || u.activo === false) {
        return json(401, { error: "Credenciales invalidas" });
      }
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
      if (!texto(cuerpo.contrasena)) errores.contrasena = "Obligatorio";
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

  if (s[0] === "health") return ok({ status: "ok" });

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
      guardar();
      return ok(reporteCompleto(r));
    }
    if (metodo === "DELETE" && s.length === 2) {
      const veto = exige(ROLES_DOC);
      if (veto) return veto;
      return eliminarDe(db.reportes, "id_reporte", num(s[1]));
    }
    if (metodo === "POST" && s[2] === "enviar") {
      const veto = exige(ROLES_DOC);
      if (veto) return veto;
      if (r.estado !== "borrador" && r.estado !== "rechazado") {
        return json(409, { error: "El reporte ya fue enviado" });
      }
      r.estado = "en_revision";
      guardar();
      return ok(reporteCompleto(r));
    }
    if (metodo === "POST" && (s[2] === "aprobar" || s[2] === "rechazar")) {
      const veto = exige(ROLES_REPORTE_APROBAR);
      if (veto) return veto;
      if (r.estado !== "en_revision") {
        return json(409, { error: "Solo se puede revisar un reporte en revision" });
      }
      r.estado = s[2] === "aprobar" ? "aprobado" : "rechazado";
      r.observacion_revision = texto(cuerpo.observacion) || null;
      r.fecha_revision = hoy();
      guardar();
      return ok(reporteCompleto(r));
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
      inactividad: [db.inactividades, "id_periodo", ROLES_DOC],
      excedente: [db.excedentes, "id_item", ROLES_DOC],
    };
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
          const asignacion = db.asignaciones.find((a) => Number(a.id_asignacion) === idAsig);
          if (!asignacion) return json(404, { error: "Asignación no encontrada" });
          const cantidad = num(cuerpo.cantidad_consumida);
          if (!(cantidad > 0)) return json(422, { errors: { cantidad_consumida: "Cantidad inválida" } });
          // Un consumo no puede superar lo que queda asignado (CU22, DEF-05).
          const sinStock = faltaStock(asignacion, cantidad);
          if (sinStock) return json(422, { errors: { cantidad_consumida: sinStock } });
          const nuevo: Fila = {
            id_consumo: proximoId(db.consumos, "id_consumo"),
            id_asignacion: idAsig,
            fecha: texto(cuerpo.fecha) || hoy(),
            cantidad_consumida: cantidad,
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
        estado: texto(cuerpo.estado) || "planificacion",
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
        for (const campo of ["nombre", "tipo", "ubicacion", "encargado", "fechaInicio", "estado"]) {
          if (cuerpo[campo] !== undefined) obra[campo] = texto(cuerpo[campo]);
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
        return ok(coleccion.filter((f) => String(f.id_proyecto) === String(idObra)));
      }
      if (metodo === "POST") {
        const veto = exige(roles);
        if (veto) return veto;
        const nuevo = { [campoId]: proximoId(coleccion, campoId), ...construir(cuerpo) };
        coleccion.push(nuevo);
        guardar();
        return creado(nuevo);
      }
    }

    // La lista de consumos que se guarda y se envía junta (CU22, DEF-04).
    // Entra entera o no entra, como en main.
    if (sub === "consumos" && metodo === "POST") {
      const veto = exige(ROLES_AVANCE);
      if (veto) return veto;
      const fecha = texto(cuerpo.fecha);
      if (!/^\d{4}-\d{2}-\d{2}$/.test(fecha)) return json(422, { errors: { fecha: "Formato esperado: YYYY-MM-DD" } });
      const lista = Array.isArray(cuerpo.consumos) ? (cuerpo.consumos as Fila[]) : [];
      if (lista.length === 0) return json(422, { errors: { consumos: "Cargá al menos un consumo" } });
      const pedido = new Map<number, number>();
      for (const renglon of lista) {
        const idAsig = num(renglon?.id_asignacion);
        const cantidad = num(renglon?.cantidad_consumida);
        if (!idAsig || !(cantidad > 0)) {
          return json(422, { errors: { consumos: "Cada consumo necesita un material y una cantidad mayor a cero" } });
        }
        pedido.set(idAsig, (pedido.get(idAsig) ?? 0) + cantidad);
      }
      const errores: Record<string, string> = {};
      for (const [idAsig, cantidad] of pedido) {
        const asignacion = db.asignaciones.find((a) => Number(a.id_asignacion) === idAsig && Number(a.id_proyecto) === idNum);
        if (!asignacion) { errores[`consumos.${idAsig}`] = "Ese material no está asignado a la obra"; continue; }
        const sinStock = faltaStock(asignacion, cantidad);
        if (sinStock) errores[`consumos.${idAsig}`] = `${nombreMaterial(asignacion)}: ${sinStock}`;
      }
      if (Object.keys(errores).length > 0) return json(422, { errors: errores });
      for (const renglon of lista) {
        db.consumos.push({
          id_consumo: proximoId(db.consumos, "id_consumo"),
          id_asignacion: num(renglon.id_asignacion),
          fecha,
          cantidad_consumida: num(renglon.cantidad_consumida),
          observaciones: null,
        });
      }
      guardar();
      return json(201, { registrados: lista.length });
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
}
