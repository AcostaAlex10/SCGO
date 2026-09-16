import { useState, useEffect, useCallback } from "react";
import { useParams, useNavigate } from "react-router";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "./ui/card";
import { Button } from "./ui/button";
import { Input } from "./ui/input";
import { Label } from "./ui/label";
import { Textarea } from "./ui/textarea";
import { Badge } from "./ui/badge";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "./ui/select";
import { ArrowLeft, MapPin, User, Calendar, TrendingUp, Plus, Users, CloudRain, Trash2, Package, FileText, AlertTriangle, Pause, Play, Layers, Wallet, Pencil, ClipboardCheck } from "lucide-react";
import { toast } from "sonner";
import {
  obtenerProyecto, obtenerPlanificacion, crearPlanificacion,
  listarAvances, resumenAvances, crearAvance,
  listarEtapas, crearEtapa, actualizarEtapa, eliminarEtapa,
  listarAsistencias, crearAsistencia, eliminarAsistencia,
  listarIncidencias, crearIncidencia, eliminarIncidencia,
  listarCatalogoMateriales, listarMaterialesObra, asignarMaterial,
  eliminarAsignacionMaterial, crearConsumo,
  listarDocumentos, crearDocumento, eliminarDocumento,
  listarInactividades, crearInactividad, cerrarInactividad, eliminarInactividad,
  listarExcedentes, crearExcedente, eliminarExcedente,
  type Proyecto, type Planificacion, type Avance, type Resumen,
  type EtapaPlanificacion,
  type Asistencia, type Incidencia, type EstadoAsistencia,
  type TipoIncidencia, type GravedadIncidencia,
  type Material, type AsignacionMaterial, type Documento, type TipoDocumento,
  type PeriodoInactividad, type ItemExcedente,
} from "../api/proyectos";
import { puedeGestionarObras, puedeRegistrarAvance, puedeCargarDocumentos, puedeVerCostos } from "../auth/permisos";
import { etiquetaEstado } from "../estadosObra";
import { EnlaceDocumento } from "./EnlaceDocumento";

// Fecha de HOY en zona horaria LOCAL (no UTC), para que de noche en Argentina
// no muestre el día siguiente.
const hoy = () => { const d = new Date(); return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10); };
const fmtFecha = (s: string) => new Date(s + "T00:00:00").toLocaleDateString("es-AR");

// Etiquetas y colores para mostrar asistencia e incidencias.
const ESTADO_ASIS: Record<EstadoAsistencia, { label: string; color: string }> = {
  presente: { label: "Presente", color: "#22c55e" },
  ausente: { label: "Ausente", color: "#ef4444" },
  tarde: { label: "Tarde", color: "#e8981e" },
};
const TIPO_INC: Record<TipoIncidencia, string> = {
  clima: "Clima",
  falla_maquinaria: "Falla de maquinaria",
  proveedor: "Retraso de proveedor",
  otro: "Otro",
};
const GRAVEDAD_INC: Record<GravedadIncidencia, { label: string; color: string }> = {
  baja: { label: "Baja", color: "#22c55e" },
  media: { label: "Media", color: "#e8981e" },
  alta: { label: "Alta", color: "#ef4444" },
};

export default function ProyectoDetallePage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();

  // Permisos (RF19): quién puede planificar, registrar avances y cargar docs.
  const gestiona = puedeGestionarObras();
  const registraAvance = puedeRegistrarAvance();
  const cargaDocs = puedeCargarDocumentos();
  const verCostos = puedeVerCostos();

  const [proyecto, setProyecto] = useState<Proyecto | null>(null);
  const [plan, setPlan] = useState<Planificacion | null>(null);
  const [avances, setAvances] = useState<Avance[]>([]);
  const [resumen, setResumen] = useState<Resumen | null>(null);
  const [loading, setLoading] = useState(true);

  const [formAvance, setFormAvance] = useState({ cantidad_ejecutada: "", porcentaje_avance: "", fecha: hoy(), observaciones: "" });

  // Etapas de planificación
  const [etapas, setEtapas] = useState<EtapaPlanificacion[]>([]);
  const [formEtapa, setFormEtapa] = useState({ nombre: "", peso_porcentual: "", fecha_inicio: hoy(), fecha_fin: "", presupuesto_base: "" });
  const [editandoEtapa, setEditandoEtapa] = useState<number | null>(null);
  const [formEditEtapa, setFormEditEtapa] = useState({ nombre: "", peso_porcentual: "", fecha_inicio: "", fecha_fin: "", presupuesto_base: "" });

  // Seguimiento operativo (Sprint 2): asistencia (RF06) e incidencias (RF09).
  const [asistencias, setAsistencias] = useState<Asistencia[]>([]);
  const [incidencias, setIncidencias] = useState<Incidencia[]>([]);
  const [formAsis, setFormAsis] = useState<{ trabajador: string; estado: EstadoAsistencia; fecha: string; justificacion: string }>(
    { trabajador: "", estado: "presente", fecha: hoy(), justificacion: "" }
  );
  const [formInc, setFormInc] = useState<{ tipo: TipoIncidencia; gravedad: GravedadIncidencia; fecha: string; descripcion: string; dias_retraso: string }>(
    { tipo: "clima", gravedad: "media", fecha: hoy(), descripcion: "", dias_retraso: "" }
  );

  // Materiales (RF10/RF12) y documentación (RF16).
  const [catalogo, setCatalogo] = useState<Material[]>([]);
  const [materiales, setMateriales] = useState<AsignacionMaterial[]>([]);
  const [documentos, setDocumentos] = useState<Documento[]>([]);
  const [formMat, setFormMat] = useState({ id_material: "", cantidad_asignada: "" });
  const [consumoInput, setConsumoInput] = useState<Record<number, string>>({});
  const [formDoc, setFormDoc] = useState<{ nombre: string; tipo: TipoDocumento; categoria: string; url: string }>(
    { nombre: "", tipo: "pdf", categoria: "", url: "" }
  );

  // Inactividad (RF25) y ítems excedentes (RF22).
  const [inactividades, setInactividades] = useState<PeriodoInactividad[]>([]);
  const [excedentes, setExcedentes] = useState<ItemExcedente[]>([]);
  const [formInac, setFormInac] = useState({ fecha_inicio: hoy(), fecha_fin: "", motivo: "" });
  const [formExc, setFormExc] = useState({ descripcion: "", cantidad: "", unidad: "", motivo: "" });

  const cargar = useCallback(async () => {
    if (!id) return;
    setLoading(true);
    try {
      // Todo lo de la obra se pide EN PARALELO (antes era en serie y con el
      // arranque en frío de Render tardaba >10 s mostrando "Cargando obra...").
      const [proy, asis, inci, mats, docs, inacs, excs, planif] = await Promise.all([
        obtenerProyecto(id),
        listarAsistencias(id),
        listarIncidencias(id),
        listarMaterialesObra(id),
        listarDocumentos(id),
        listarInactividades(id),
        listarExcedentes(id),
        obtenerPlanificacion(id),
      ]);
      setProyecto(proy);
      setAsistencias(asis);
      setIncidencias(inci);
      setMateriales(mats);
      setDocumentos(docs);
      setInactividades(inacs);
      setExcedentes(excs);
      setPlan(planif);
      // El catálogo de materiales no bloquea la carga de la obra.
      if (catalogo.length === 0) listarCatalogoMateriales().then(setCatalogo).catch(() => {});
      if (planif) {
        const [av, res, etas] = await Promise.all([
          listarAvances(planif.id_planificacion),
          resumenAvances(planif.id_planificacion),
          listarEtapas(planif.id_planificacion),
        ]);
        setAvances(av);
        setResumen(res);
        setEtapas(etas);
      } else {
        setAvances([]);
        setResumen(null);
        setEtapas([]);
      }
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "No se pudo cargar la obra");
    } finally {
      setLoading(false);
    }
  }, [id]);
  useEffect(() => { cargar(); }, [cargar]);

  async function guardarPlan() {
    if (!id) return;
    try {
      await crearPlanificacion(id, { fecha_carga: hoy() });
      toast.success("Planificación creada. Ahora podés agregar las etapas.");
      await cargar();
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Error al crear la planificación");
    }
  }

  // --- Etapas de planificación ---
  async function guardarEtapa(e: React.FormEvent) {
    e.preventDefault();
    if (!plan) return;
    try {
      await crearEtapa(plan.id_planificacion, {
        nombre: formEtapa.nombre,
        peso_porcentual: parseFloat(formEtapa.peso_porcentual),
        fecha_inicio: formEtapa.fecha_inicio,
        fecha_fin: formEtapa.fecha_fin,
        presupuesto_base: formEtapa.presupuesto_base ? parseFloat(formEtapa.presupuesto_base) : 0,
      });
      toast.success("Etapa agregada");
      setFormEtapa({ nombre: "", peso_porcentual: "", fecha_inicio: hoy(), fecha_fin: "", presupuesto_base: "" });
      const [etas, res] = await Promise.all([
        listarEtapas(plan.id_planificacion),
        resumenAvances(plan.id_planificacion),
      ]);
      setEtapas(etas);
      setResumen(res);
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Error al agregar la etapa");
    }
  }
  async function guardarEditEtapa(e: React.FormEvent, idEtapa: number) {
    e.preventDefault();
    if (!plan) return;
    try {
      await actualizarEtapa(idEtapa, {
        nombre: formEditEtapa.nombre,
        peso_porcentual: parseFloat(formEditEtapa.peso_porcentual),
        fecha_inicio: formEditEtapa.fecha_inicio,
        fecha_fin: formEditEtapa.fecha_fin,
        presupuesto_base: formEditEtapa.presupuesto_base ? parseFloat(formEditEtapa.presupuesto_base) : 0,
      });
      toast.success("Etapa actualizada");
      setEditandoEtapa(null);
      const [etas, res] = await Promise.all([
        listarEtapas(plan.id_planificacion),
        resumenAvances(plan.id_planificacion),
      ]);
      setEtapas(etas);
      setResumen(res);
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Error al actualizar la etapa");
    }
  }
  async function borrarEtapa(idEtapa: number) {
    if (!plan) return;
    try {
      await eliminarEtapa(idEtapa);
      const [etas, res] = await Promise.all([
        listarEtapas(plan.id_planificacion),
        resumenAvances(plan.id_planificacion),
      ]);
      setEtapas(etas);
      setResumen(res);
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Error al eliminar la etapa");
    }
  }

  async function guardarAvance(e: React.FormEvent) {
    e.preventDefault();
    if (!plan) return;
    try {
      await crearAvance(plan.id_planificacion, {
        cantidad_ejecutada: parseFloat(formAvance.cantidad_ejecutada),
        porcentaje_avance: parseFloat(formAvance.porcentaje_avance),
        fecha: formAvance.fecha,
        observaciones: formAvance.observaciones || undefined,
      });
      toast.success("Avance registrado");
      setFormAvance({ cantidad_ejecutada: "", porcentaje_avance: "", fecha: hoy(), observaciones: "" });
      await cargar();
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Error al registrar el avance");
    }
  }

  // --- Asistencia (RF06) ---
  async function guardarAsistencia(e: React.FormEvent) {
    e.preventDefault();
    if (!id) return;
    try {
      await crearAsistencia(id, {
        fecha: formAsis.fecha,
        trabajador: formAsis.trabajador,
        estado: formAsis.estado,
        justificacion: formAsis.justificacion || undefined,
      });
      toast.success("Asistencia registrada");
      setFormAsis({ trabajador: "", estado: "presente", fecha: hoy(), justificacion: "" });
      setAsistencias(await listarAsistencias(id));
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Error al registrar la asistencia");
    }
  }
  async function borrarAsistencia(idA: number) {
    try {
      await eliminarAsistencia(idA);
      setAsistencias((prev) => prev.filter((a) => a.id_asistencia !== idA));
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Error al eliminar");
    }
  }

  // --- Incidencias externas (RF09) ---
  async function guardarIncidencia(e: React.FormEvent) {
    e.preventDefault();
    if (!id) return;
    try {
      await crearIncidencia(id, {
        fecha: formInc.fecha,
        tipo: formInc.tipo,
        gravedad: formInc.gravedad,
        descripcion: formInc.descripcion,
        dias_retraso: formInc.dias_retraso ? parseInt(formInc.dias_retraso, 10) : 0,
      });
      toast.success("Incidencia registrada");
      setFormInc({ tipo: "clima", gravedad: "media", fecha: hoy(), descripcion: "", dias_retraso: "" });
      setIncidencias(await listarIncidencias(id));
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Error al registrar la incidencia");
    }
  }
  async function borrarIncidencia(idI: number) {
    try {
      await eliminarIncidencia(idI);
      setIncidencias((prev) => prev.filter((x) => x.id_incidencia !== idI));
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Error al eliminar");
    }
  }

  // --- Materiales (RF10/RF12) ---
  async function asignarMat(e: React.FormEvent) {
    e.preventDefault();
    if (!id) return;
    try {
      await asignarMaterial(id, {
        id_material: parseInt(formMat.id_material, 10),
        cantidad_asignada: parseFloat(formMat.cantidad_asignada),
      });
      toast.success("Material asignado a la obra");
      setFormMat({ id_material: "", cantidad_asignada: "" });
      setMateriales(await listarMaterialesObra(id));
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Error al asignar el material");
    }
  }
  async function borrarAsignacion(idAsig: number) {
    if (!id) return;
    try {
      await eliminarAsignacionMaterial(idAsig);
      setMateriales(await listarMaterialesObra(id));
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Error al eliminar");
    }
  }
  async function registrarConsumo(idAsig: number) {
    if (!id) return;
    const cantidad = parseFloat(consumoInput[idAsig] ?? "");
    if (!cantidad || cantidad <= 0) {
      toast.error("Ingresá una cantidad válida");
      return;
    }
    try {
      await crearConsumo(idAsig, { fecha: hoy(), cantidad_consumida: cantidad });
      toast.success("Consumo registrado");
      setConsumoInput((prev) => ({ ...prev, [idAsig]: "" }));
      setMateriales(await listarMaterialesObra(id));
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Error al registrar el consumo");
    }
  }

  // --- Documentación (RF16) ---
  async function guardarDoc(e: React.FormEvent) {
    e.preventDefault();
    if (!id) return;
    try {
      await crearDocumento(id, {
        nombre: formDoc.nombre,
        tipo: formDoc.tipo,
        categoria: formDoc.categoria || "General",
        url: formDoc.url,
        fecha_carga: hoy(),
      });
      toast.success("Documento agregado");
      setFormDoc({ nombre: "", tipo: "pdf", categoria: "", url: "" });
      setDocumentos(await listarDocumentos(id));
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Error al agregar el documento");
    }
  }
  async function borrarDoc(idDoc: number) {
    try {
      await eliminarDocumento(idDoc);
      setDocumentos((prev) => prev.filter((d) => d.id_documento !== idDoc));
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Error al eliminar");
    }
  }

  // --- Inactividad (RF25) ---
  // El backend mueve el estado de la obra al registrar o cerrar un período
  // (TP3) y lo devuelve en la respuesta, así que no hace falta recargarla.
  function aplicarEstadoObra(estado: string | null | undefined) {
    if (!estado) return;
    setProyecto((prev) => (prev ? { ...prev, estado } : prev));
    toast.info(estado === "pausada" ? "La obra quedó pausada" : "La obra volvió a ejecución");
  }

  async function guardarInactividad(e: React.FormEvent) {
    e.preventDefault();
    if (!id) return;
    try {
      const creado = await crearInactividad(id, {
        fecha_inicio: formInac.fecha_inicio,
        fecha_fin: formInac.fecha_fin || undefined,
        motivo: formInac.motivo,
      });
      toast.success("Período de inactividad registrado");
      setFormInac({ fecha_inicio: hoy(), fecha_fin: "", motivo: "" });
      setInactividades(await listarInactividades(id));
      aplicarEstadoObra(creado.estado_proyecto);
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Error al registrar el período");
    }
  }
  async function cerrarPeriodo(idP: number) {
    if (!id) return;
    try {
      const cerrado = await cerrarInactividad(idP);
      setInactividades(await listarInactividades(id));
      if (cerrado.estado_proyecto) aplicarEstadoObra(cerrado.estado_proyecto);
      else toast.success("Período cerrado");
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Error al cerrar el período");
    }
  }
  async function borrarInactividad(idP: number) {
    try {
      const r = await eliminarInactividad(idP);
      setInactividades((prev) => prev.filter((p) => p.id_periodo !== idP));
      aplicarEstadoObra(r.estado_proyecto);
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Error al eliminar");
    }
  }

  // --- Ítems excedentes (RF22) ---
  async function guardarExcedente(e: React.FormEvent) {
    e.preventDefault();
    if (!id) return;
    try {
      await crearExcedente(id, {
        descripcion: formExc.descripcion,
        cantidad: formExc.cantidad ? parseFloat(formExc.cantidad) : undefined,
        unidad: formExc.unidad || undefined,
        fecha: hoy(),
        motivo: formExc.motivo || undefined,
      });
      toast.success("Ítem excedente registrado");
      setFormExc({ descripcion: "", cantidad: "", unidad: "", motivo: "" });
      setExcedentes(await listarExcedentes(id));
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Error al registrar el ítem");
    }
  }
  async function borrarExcedente(idX: number) {
    try {
      await eliminarExcedente(idX);
      setExcedentes((prev) => prev.filter((x) => x.id_item !== idX));
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Error al eliminar");
    }
  }

  // El período que mantiene pausada la obra, si hay uno. Alimenta el aviso de arriba.
  const periodoVigente = inactividades.find((p) => p.vigente) ?? null;

  if (loading) return <div className="text-center text-muted-foreground py-12">Cargando obra...</div>;
  if (!proyecto) return (
    <div className="space-y-4">
      <Button variant="outline" className="gap-2" onClick={() => navigate("/proyectos")}><ArrowLeft className="w-4 h-4" /> Volver</Button>
      <p className="text-muted-foreground">Proyecto no encontrado.</p>
    </div>
  );

  return (
    <div className="space-y-6">
      <Button variant="outline" className="gap-2" onClick={() => navigate("/proyectos")}>
        <ArrowLeft className="w-4 h-4" /> Volver a proyectos
      </Button>

      {/* Obra pausada: que se note sin tener que bajar hasta la tarjeta de inactividad. */}
      {proyecto.estado === "pausada" && (
        <div
          className="rounded-lg border-2 px-5 py-4 flex flex-wrap items-center gap-x-4 gap-y-2"
          style={{ borderColor: "#ef4444", background: "rgba(239, 68, 68, 0.10)" }}
          role="status"
        >
          <Pause className="w-7 h-7 shrink-0" style={{ color: "#ef4444" }} />
          <div className="flex-1 min-w-[240px]">
            <p className="font-semibold text-lg leading-tight" style={{ color: "#ef4444" }}>Obra pausada</p>
            <p className="text-sm text-muted-foreground mt-0.5">
              {periodoVigente
                ? <>Desde el {fmtFecha(periodoVigente.fecha_inicio)} · {periodoVigente.motivo}</>
                : "No hay ningún período de inactividad abierto."}
            </p>
          </div>
          {cargaDocs && periodoVigente && (
            <Button className="gap-2" onClick={() => cerrarPeriodo(periodoVigente.id_periodo)}>
              <Play className="w-4 h-4" /> Continuar obra
            </Button>
          )}
        </div>
      )}

      {/* Obra en revisión: el reporte final está esperando al supervisor. */}
      {proyecto.estado === "en_revision" && (
        <div
          className="rounded-lg border-2 px-5 py-4 flex flex-wrap items-center gap-x-4 gap-y-2"
          style={{ borderColor: "#f59e0b", background: "rgba(245, 158, 11, 0.10)" }}
          role="status"
        >
          <ClipboardCheck className="w-7 h-7 shrink-0" style={{ color: "#f59e0b" }} />
          <div className="flex-1 min-w-[240px]">
            <p className="font-semibold text-lg leading-tight" style={{ color: "#f59e0b" }}>Obra en revisión</p>
            <p className="text-sm text-muted-foreground mt-0.5">
              El reporte final está enviado. La obra queda finalizada cuando el supervisor lo apruebe.
            </p>
          </div>
        </div>
      )}

      {/* La obra llegó al 100 % pero nadie mandó el reporte final. El avance ya
          no la finaliza solo, así que sin este aviso quedaría en ejecución sin
          que se entienda por qué. */}
      {proyecto.estado === "en_ejecucion" && proyecto.avance >= 100 && (
        <div
          className="rounded-lg border px-5 py-4 flex flex-wrap items-center gap-x-4 gap-y-2"
          style={{ borderColor: "#3b82f6", background: "rgba(59, 130, 246, 0.08)" }}
          role="status"
        >
          <ClipboardCheck className="w-6 h-6 shrink-0" style={{ color: "#3b82f6" }} />
          <p className="text-sm flex-1 min-w-[240px]">
            <span className="font-semibold">La obra alcanzó el 100 % de avance.</span>{" "}
            Para cerrarla, cargá el reporte final desde Reportes y enviálo a revisión.
          </p>
        </div>
      )}

      {/* Datos de la obra */}
      <Card>
        <CardHeader>
          <div className="flex items-start justify-between">
            <div>
              <CardTitle className="text-2xl">{proyecto.nombre}</CardTitle>
              <CardDescription className="mt-1">{proyecto.tipo}</CardDescription>
            </div>
            <Badge variant={proyecto.estado === "pausada" ? "destructive" : "secondary"}>{etiquetaEstado(proyecto.estado)}</Badge>
          </div>
        </CardHeader>
        <CardContent className="grid grid-cols-1 md:grid-cols-3 gap-3 text-sm text-muted-foreground">
          <div className="flex items-center gap-2"><MapPin className="w-4 h-4" /> {proyecto.ubicacion}</div>
          <div className="flex items-center gap-2"><User className="w-4 h-4" /> {proyecto.encargado}</div>
          <div className="flex items-center gap-2"><Calendar className="w-4 h-4" /> Inicio: {fmtFecha(proyecto.fechaInicio)}</div>
          {/* RF15: certificación por monto = presupuesto x % de avance (oculto al técnico, RF20) */}
          {verCostos && proyecto.presupuesto != null && (
            <div className="flex items-center gap-2 md:col-span-3 pt-1" style={{ color: "var(--foreground)" }}>
              <Wallet className="w-4 h-4" /> Certificación a la fecha: <b>${Math.round((proyecto.presupuesto * proyecto.avance) / 100).toLocaleString("es-AR")}</b>
              <span className="text-muted-foreground">({proyecto.avance}% de ${proyecto.presupuesto.toLocaleString("es-AR")})</span>
            </div>
          )}
        </CardContent>
      </Card>

      {/* Planificacion (RF02) */}
      {(() => {
        const sumaEtapas = etapas.reduce((acc, e) => acc + e.peso_porcentual, 0);
        const sumaPct = Math.round(sumaEtapas * 100) / 100;
        return !plan ? (
          <Card>
            <CardHeader>
              <CardTitle>Planificación de la obra</CardTitle>
              <CardDescription>
                {gestiona
                  ? "Esta obra todavía no tiene planificación. Creá una para poder cargar etapas y avances."
                  : "Esta obra todavía no tiene planificación cargada."}
              </CardDescription>
            </CardHeader>
            <CardContent>
              {!gestiona ? (
                <p className="text-muted-foreground text-sm">Solo el personal administrativo puede cargar la planificación.</p>
              ) : (
                <Button onClick={guardarPlan} className="gap-2"><Plus className="w-4 h-4" /> Crear planificación</Button>
              )}
            </CardContent>
          </Card>
        ) : (
          <>
            {/* Etapas de planificación */}
            {gestiona && (
            <Card>
              <CardHeader>
                <div className="flex items-center justify-between flex-wrap gap-2">
                  <CardTitle className="flex items-center gap-2">
                    <Calendar className="w-5 h-5" /> Etapas planificadas
                  </CardTitle>
                  <Badge style={{
                    background: sumaPct === 100 ? "#22c55e" : sumaPct > 100 ? "#ef4444" : "#e8981e",
                    color: "#fff",
                  }}>
                    {sumaPct === 100 ? "✓ 100%" : `${sumaPct}% / 100%`}
                  </Badge>
                </div>
                <CardDescription>
                  Cada etapa aporta un peso al avance total y define un rango de fechas. La suma de pesos debe ser 100%.
                  El esperado de hoy se calcula automáticamente según el progreso temporal de cada etapa.
                </CardDescription>
              </CardHeader>
              <CardContent className="space-y-3">
                {etapas.length === 0 && (
                  <p className="text-muted-foreground text-sm">Todavía no hay etapas cargadas. Agregá etapas para que el esperado se calcule por fecha.</p>
                )}
                {etapas.map((e) =>
                  editandoEtapa === e.id_etapa ? (
                    <form
                      key={e.id_etapa}
                      onSubmit={(ev) => guardarEditEtapa(ev, e.id_etapa)}
                      className="border rounded-md px-4 py-3 grid grid-cols-1 md:grid-cols-5 gap-2 items-end"
                    >
                      <div className="space-y-1">
                        <Label>Nombre</Label>
                        <Input required value={formEditEtapa.nombre}
                          onChange={(ev) => setFormEditEtapa({ ...formEditEtapa, nombre: ev.target.value })} />
                      </div>
                      <div className="space-y-1">
                        <Label>Peso (%)</Label>
                        <Input type="number" min="0" max="100" step="0.01" required
                          value={formEditEtapa.peso_porcentual}
                          onChange={(ev) => setFormEditEtapa({ ...formEditEtapa, peso_porcentual: ev.target.value })} />
                      </div>
                      <div className="space-y-1">
                        <Label>Inicio</Label>
                        <Input type="date" required value={formEditEtapa.fecha_inicio}
                          onChange={(ev) => setFormEditEtapa({ ...formEditEtapa, fecha_inicio: ev.target.value })} />
                      </div>
                      <div className="space-y-1">
                        <Label>Fin</Label>
                        <Input type="date" required min={formEditEtapa.fecha_inicio} value={formEditEtapa.fecha_fin}
                          onChange={(ev) => setFormEditEtapa({ ...formEditEtapa, fecha_fin: ev.target.value })} />
                      </div>
                      {verCostos && (
                        <div className="space-y-1">
                          <Label>Presup. base ($)</Label>
                          <Input type="number" min="0" step="0.01" value={formEditEtapa.presupuesto_base}
                            onChange={(ev) => setFormEditEtapa({ ...formEditEtapa, presupuesto_base: ev.target.value })} placeholder="0" />
                        </div>
                      )}
                      <div className="flex gap-2 md:col-span-5">
                        <Button type="submit" size="sm">Guardar</Button>
                        <Button type="button" size="sm" variant="outline" onClick={() => setEditandoEtapa(null)}>Cancelar</Button>
                      </div>
                    </form>
                  ) : (
                    <div key={e.id_etapa} className="flex items-center gap-3 border rounded-md px-4 py-2 text-sm flex-wrap">
                      <span className="font-medium">{e.nombre}</span>
                      <Badge variant="secondary">{e.peso_porcentual}%</Badge>
                      <span className="text-muted-foreground">{fmtFecha(e.fecha_inicio)} → {fmtFecha(e.fecha_fin)}</span>
                      {verCostos && e.presupuesto_base > 0 && (
                        <span className="text-muted-foreground">${e.presupuesto_base.toLocaleString("es-AR")}</span>
                      )}
                      <div className="ml-auto flex gap-1">
                        <Button size="sm" variant="ghost" title="Editar" onClick={() => {
                          setEditandoEtapa(e.id_etapa);
                          setFormEditEtapa({ nombre: e.nombre, peso_porcentual: String(e.peso_porcentual), fecha_inicio: e.fecha_inicio, fecha_fin: e.fecha_fin, presupuesto_base: String(e.presupuesto_base) });
                        }}>
                          <Pencil className="w-4 h-4" />
                        </Button>
                        <Button size="sm" variant="ghost" title="Eliminar" onClick={() => borrarEtapa(e.id_etapa)}>
                          <Trash2 className="w-4 h-4" style={{ color: "#ef4444" }} />
                        </Button>
                      </div>
                    </div>
                  )
                )}
                {/* Totales de presupuesto base (solo visibles para quien puede ver costos) */}
                {verCostos && etapas.length > 0 && (() => {
                  const totalBase = etapas.reduce((a, e) => a + e.presupuesto_base, 0);
                  if (totalBase === 0) return null;
                  const ejecutadoEst = proyecto ? Math.round((proyecto.presupuesto * (resumen?.avance_real ?? 0)) / 100) : 0;
                  return (
                    <div className="flex gap-6 text-sm pt-1 border-t">
                      <span>Presup. base total: <b>${totalBase.toLocaleString("es-AR")}</b></span>
                      <span>Ejecutado estimado: <b>${ejecutadoEst.toLocaleString("es-AR")}</b></span>
                      <span style={{ color: totalBase - ejecutadoEst >= 0 ? "#22c55e" : "#ef4444" }}>
                        Diferencia base: <b>${(totalBase - ejecutadoEst).toLocaleString("es-AR")}</b>
                      </span>
                    </div>
                  );
                })()}

                {/* Formulario para agregar nueva etapa */}
                <form onSubmit={guardarEtapa} className="grid grid-cols-1 md:grid-cols-5 gap-2 items-end pt-3 border-t">
                  <div className="space-y-1">
                    <Label htmlFor="en">Nueva etapa</Label>
                    <Input id="en" placeholder="Ej: Cimientos" required value={formEtapa.nombre}
                      onChange={(e) => setFormEtapa({ ...formEtapa, nombre: e.target.value })} />
                  </div>
                  <div className="space-y-1">
                    <Label htmlFor="ep">Peso (%)</Label>
                    <Input id="ep" type="number" min="0" max="100" step="0.01" required placeholder="Ej: 30"
                      value={formEtapa.peso_porcentual}
                      onChange={(e) => setFormEtapa({ ...formEtapa, peso_porcentual: e.target.value })} />
                  </div>
                  <div className="space-y-1">
                    <Label htmlFor="efi">Inicio</Label>
                    <Input id="efi" type="date" required value={formEtapa.fecha_inicio}
                      onChange={(e) => setFormEtapa({ ...formEtapa, fecha_inicio: e.target.value })} />
                  </div>
                  <div className="space-y-1">
                    <Label htmlFor="eff">Fin</Label>
                    <Input id="eff" type="date" required min={formEtapa.fecha_inicio} value={formEtapa.fecha_fin}
                      onChange={(e) => setFormEtapa({ ...formEtapa, fecha_fin: e.target.value })} />
                  </div>
                  {verCostos && (
                    <div className="space-y-1">
                      <Label htmlFor="epb">Presup. base ($)</Label>
                      <Input id="epb" type="number" min="0" step="0.01" placeholder="0"
                        value={formEtapa.presupuesto_base}
                        onChange={(e) => setFormEtapa({ ...formEtapa, presupuesto_base: e.target.value })} />
                    </div>
                  )}
                  <div className={verCostos ? "md:col-span-5" : "md:col-span-4"}>
                    <Button type="submit" className="gap-2"><Plus className="w-4 h-4" /> Agregar etapa</Button>
                    {sumaPct > 0 && sumaPct < 100 && (
                      <span className="ml-3 text-sm" style={{ color: "#e8981e" }}>
                        Quedan {Math.round((100 - sumaPct) * 100) / 100}% por asignar
                      </span>
                    )}
                  </div>
                </form>
              </CardContent>
            </Card>
            )}

            {/* Resumen avance esperado vs real */}
            {resumen && (
              <Card>
                <CardHeader>
                  <CardTitle className="flex items-center gap-2"><TrendingUp className="w-5 h-5" /> Seguimiento de avance</CardTitle>
                  <CardDescription>
                    Comparación entre lo planificado (calculado a la fecha según etapas) y lo ejecutado.
                  </CardDescription>
                </CardHeader>
                <CardContent className="grid grid-cols-2 md:grid-cols-4 gap-4">
                  <Metric label="Esperado hoy" value={`${resumen.avance_esperado}%`} />
                  <Metric label="Avance real" value={`${resumen.avance_real}%`} />
                  <Metric label="Desvío" value={`${resumen.desvio_pp} pp`} color={resumen.desvio_pp < 0 ? "#ef4444" : "#22c55e"} />
                  <Metric label="Registros" value={String(resumen.total_registros)} />
                </CardContent>
              </Card>
            )}

          {/* Cargar avance (solo el encargado de obra / Personal Técnico) */}
          {registraAvance && (
          <Card>
            <CardHeader><CardTitle>Registrar avance físico</CardTitle></CardHeader>
            <CardContent>
              <form onSubmit={guardarAvance} className="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
                <div className="space-y-2">
                  <Label htmlFor="ce">Cantidad ejecutada</Label>
                  <Input id="ce" type="number" min="0" step="0.01" required value={formAvance.cantidad_ejecutada}
                    onChange={(e) => setFormAvance({ ...formAvance, cantidad_ejecutada: e.target.value })} />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="pa">% de avance</Label>
                  <Input id="pa" type="number" min="0" max="100" step="0.01" required value={formAvance.porcentaje_avance}
                    onChange={(e) => setFormAvance({ ...formAvance, porcentaje_avance: e.target.value })} />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="fa">Fecha</Label>
                  <Input id="fa" type="date" required value={formAvance.fecha}
                    onChange={(e) => setFormAvance({ ...formAvance, fecha: e.target.value })} />
                </div>
                <Button type="submit" className="gap-2"><Plus className="w-4 h-4" /> Registrar</Button>
                <div className="space-y-2 md:col-span-4">
                  <Label htmlFor="obs">Observaciones</Label>
                  <Textarea id="obs" value={formAvance.observaciones}
                    onChange={(e) => setFormAvance({ ...formAvance, observaciones: e.target.value })}
                    placeholder="Opcional" />
                </div>
              </form>
            </CardContent>
          </Card>
          )}

          {/* Historial de avances */}
          <Card>
            <CardHeader><CardTitle>Historial de avances ({avances.length})</CardTitle></CardHeader>
            <CardContent>
              {avances.length === 0 ? (
                <p className="text-muted-foreground text-sm">Todavía no hay avances cargados.</p>
              ) : (
                <div className="space-y-2">
                  {avances.map((a) => (
                    <div key={a.id_avance} className="flex items-center justify-between border rounded-md px-4 py-2 text-sm">
                      <span className="text-muted-foreground">{fmtFecha(a.fecha)}</span>
                      <span>Cant.: <b>{a.cantidad_ejecutada}</b></span>
                      <span>Avance: <b>{a.porcentaje_avance}%</b></span>
                      <span className="text-muted-foreground flex-1 text-right truncate ml-4">{a.observaciones ?? ""}</span>
                    </div>
                  ))}
                </div>
              )}
            </CardContent>
          </Card>
        </>
        );
      })()}

      {/* Asistencia del personal (RF06) */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2"><Users className="w-5 h-5" /> Asistencia del personal</CardTitle>
          <CardDescription>Registro diario de los trabajadores presentes en la obra.</CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          {registraAvance && (
            <form onSubmit={guardarAsistencia} className="grid grid-cols-1 md:grid-cols-5 gap-3 items-end">
              <div className="space-y-2 md:col-span-2">
                <Label htmlFor="trab">Trabajador</Label>
                <Input id="trab" required value={formAsis.trabajador}
                  onChange={(e) => setFormAsis({ ...formAsis, trabajador: e.target.value })} placeholder="Nombre y apellido" />
              </div>
              <div className="space-y-2">
                <Label>Estado</Label>
                <Select value={formAsis.estado} onValueChange={(v) => setFormAsis({ ...formAsis, estado: v as EstadoAsistencia })}>
                  <SelectTrigger><SelectValue /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value="presente">Presente</SelectItem>
                    <SelectItem value="ausente">Ausente</SelectItem>
                    <SelectItem value="tarde">Tarde</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-2">
                <Label htmlFor="fasis">Fecha</Label>
                <Input id="fasis" type="date" required value={formAsis.fecha}
                  onChange={(e) => setFormAsis({ ...formAsis, fecha: e.target.value })} />
              </div>
              <Button type="submit" className="gap-2"><Plus className="w-4 h-4" /> Registrar</Button>
              {formAsis.estado !== "presente" && (
                <div className="space-y-2 md:col-span-5">
                  <Label htmlFor="just">Justificación (opcional)</Label>
                  <Input id="just" value={formAsis.justificacion}
                    onChange={(e) => setFormAsis({ ...formAsis, justificacion: e.target.value })}
                    placeholder="Motivo de la ausencia o llegada tarde" />
                </div>
              )}
            </form>
          )}
          {asistencias.length === 0 ? (
            <p className="text-muted-foreground text-sm">Todavía no hay asistencias registradas.</p>
          ) : (
            <div className="space-y-2">
              {asistencias.map((a) => {
                const info = ESTADO_ASIS[a.estado];
                return (
                  <div key={a.id_asistencia} className="flex items-center gap-3 border rounded-md px-4 py-2 text-sm">
                    <span className="text-muted-foreground w-24">{fmtFecha(a.fecha)}</span>
                    <span className="font-medium">{a.trabajador}</span>
                    <Badge style={{ background: info.color }}>{info.label}</Badge>
                    <span className="text-muted-foreground flex-1 truncate">{a.justificacion ?? ""}</span>
                    {registraAvance && (
                      <Button size="sm" variant="ghost" onClick={() => borrarAsistencia(a.id_asistencia)} title="Eliminar">
                        <Trash2 className="w-4 h-4" style={{ color: "#ef4444" }} />
                      </Button>
                    )}
                  </div>
                );
              })}
            </div>
          )}
        </CardContent>
      </Card>

      {/* Incidencias externas (RF09) */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2"><CloudRain className="w-5 h-5" /> Incidencias externas</CardTitle>
          <CardDescription>Clima, fallas de maquinaria o retrasos de proveedores que afectan la obra.</CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          {registraAvance && (
            <form onSubmit={guardarIncidencia} className="grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
              <div className="space-y-2">
                <Label>Tipo</Label>
                <Select value={formInc.tipo} onValueChange={(v) => setFormInc({ ...formInc, tipo: v as TipoIncidencia })}>
                  <SelectTrigger><SelectValue /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value="clima">Clima</SelectItem>
                    <SelectItem value="falla_maquinaria">Falla de maquinaria</SelectItem>
                    <SelectItem value="proveedor">Retraso de proveedor</SelectItem>
                    <SelectItem value="otro">Otro</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-2">
                <Label>Gravedad</Label>
                <Select value={formInc.gravedad} onValueChange={(v) => setFormInc({ ...formInc, gravedad: v as GravedadIncidencia })}>
                  <SelectTrigger><SelectValue /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value="baja">Baja</SelectItem>
                    <SelectItem value="media">Media</SelectItem>
                    <SelectItem value="alta">Alta</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-2">
                <Label htmlFor="finc">Fecha</Label>
                <Input id="finc" type="date" required value={formInc.fecha}
                  onChange={(e) => setFormInc({ ...formInc, fecha: e.target.value })} />
              </div>
              <div className="space-y-2">
                <Label htmlFor="dr">Días de retraso</Label>
                <Input id="dr" type="number" min="0" value={formInc.dias_retraso}
                  onChange={(e) => setFormInc({ ...formInc, dias_retraso: e.target.value })} placeholder="0" />
              </div>
              <div className="space-y-2 md:col-span-3">
                <Label htmlFor="desc">Descripción</Label>
                <Textarea id="desc" required value={formInc.descripcion}
                  onChange={(e) => setFormInc({ ...formInc, descripcion: e.target.value })}
                  placeholder="Detalle de la incidencia" />
              </div>
              <Button type="submit" className="gap-2 md:self-end"><Plus className="w-4 h-4" /> Registrar</Button>
            </form>
          )}
          {incidencias.length === 0 ? (
            <p className="text-muted-foreground text-sm">Todavía no hay incidencias registradas.</p>
          ) : (
            <div className="space-y-2">
              {incidencias.map((x) => {
                const g = GRAVEDAD_INC[x.gravedad];
                return (
                  <div key={x.id_incidencia} className="flex items-center gap-3 border rounded-md px-4 py-2 text-sm">
                    <span className="text-muted-foreground w-24">{fmtFecha(x.fecha)}</span>
                    <span className="font-medium">{TIPO_INC[x.tipo]}</span>
                    <Badge style={{ background: g.color }}>{g.label}</Badge>
                    <span className="text-muted-foreground flex-1 truncate">{x.descripcion}</span>
                    {x.dias_retraso > 0 && <span className="text-xs text-muted-foreground whitespace-nowrap">+{x.dias_retraso} d</span>}
                    {registraAvance && (
                      <Button size="sm" variant="ghost" onClick={() => borrarIncidencia(x.id_incidencia)} title="Eliminar">
                        <Trash2 className="w-4 h-4" style={{ color: "#ef4444" }} />
                      </Button>
                    )}
                  </div>
                );
              })}
            </div>
          )}
        </CardContent>
      </Card>

      {/* Materiales (RF10/RF12) */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2"><Package className="w-5 h-5" /> Materiales</CardTitle>
          <CardDescription>Materiales asignados a la obra y consumo registrado. Se avisa si se excede lo asignado.</CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          {gestiona && (
            <form onSubmit={asignarMat} className="grid grid-cols-1 md:grid-cols-3 gap-3 items-end">
              <div className="space-y-2">
                <Label>Material</Label>
                <Select value={formMat.id_material} onValueChange={(v) => setFormMat({ ...formMat, id_material: v })}>
                  <SelectTrigger><SelectValue placeholder="Elegí un material" /></SelectTrigger>
                  <SelectContent>
                    {catalogo.map((m) => (
                      <SelectItem key={m.id_material} value={String(m.id_material)}>{m.nombre} ({m.unidad})</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-2">
                <Label htmlFor="cant">Cantidad asignada</Label>
                <Input id="cant" type="number" min="0" step="0.01" required value={formMat.cantidad_asignada}
                  onChange={(e) => setFormMat({ ...formMat, cantidad_asignada: e.target.value })} />
              </div>
              <Button type="submit" className="gap-2"><Plus className="w-4 h-4" /> Asignar material</Button>
            </form>
          )}
          {materiales.length === 0 ? (
            <p className="text-muted-foreground text-sm">Todavía no hay materiales asignados a la obra.</p>
          ) : (
            <div className="space-y-2">
              {materiales.map((m) => (
                <div key={m.id_asignacion} className="border rounded-md px-4 py-3 text-sm space-y-2">
                  <div className="flex items-center gap-3 flex-wrap">
                    <span className="font-medium">{m.nombre}</span>
                    <span className="text-muted-foreground">
                      {m.consumido} / {m.cantidad_asignada} {m.unidad} · restante {m.restante} {m.unidad}
                    </span>
                    {m.excedido && (
                      <Badge variant="destructive" className="gap-1"><AlertTriangle className="w-3 h-3" /> Excedido</Badge>
                    )}
                    {gestiona && (
                      <Button size="sm" variant="ghost" className="ml-auto" onClick={() => borrarAsignacion(m.id_asignacion)} title="Quitar material">
                        <Trash2 className="w-4 h-4" style={{ color: "#ef4444" }} />
                      </Button>
                    )}
                  </div>
                  <div className="w-full bg-gray-200 rounded-full h-2">
                    <div className="h-2 rounded-full transition-all"
                      style={{ width: `${Math.min(100, m.cantidad_asignada > 0 ? (m.consumido / m.cantidad_asignada) * 100 : 0)}%`, background: m.excedido ? "#ef4444" : "#3b82f6" }} />
                  </div>
                  {registraAvance && (
                    <div className="flex items-end gap-2 pt-1">
                      <Input type="number" min="0" step="0.01" placeholder={`Consumo (${m.unidad})`}
                        value={consumoInput[m.id_asignacion] ?? ""}
                        onChange={(e) => setConsumoInput((prev) => ({ ...prev, [m.id_asignacion]: e.target.value }))}
                        className="max-w-[220px]" />
                      <Button size="sm" type="button" onClick={() => registrarConsumo(m.id_asignacion)} className="gap-1">
                        <Plus className="w-4 h-4" /> Registrar consumo
                      </Button>
                    </div>
                  )}
                </div>
              ))}
            </div>
          )}
        </CardContent>
      </Card>

      {/* Documentación (RF16) */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2"><FileText className="w-5 h-5" /> Documentación</CardTitle>
          <CardDescription>Documentos de la obra (planos, contratos, fotos). Se guarda un enlace al archivo.</CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          {cargaDocs && (
            <form onSubmit={guardarDoc} className="grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
              <div className="space-y-2 md:col-span-2">
                <Label htmlFor="dnom">Nombre</Label>
                <Input id="dnom" required value={formDoc.nombre}
                  onChange={(e) => setFormDoc({ ...formDoc, nombre: e.target.value })} placeholder="Ej: Plano de planta" />
              </div>
              <div className="space-y-2">
                <Label>Tipo</Label>
                <Select value={formDoc.tipo} onValueChange={(v) => setFormDoc({ ...formDoc, tipo: v as TipoDocumento })}>
                  <SelectTrigger><SelectValue /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value="pdf">PDF</SelectItem>
                    <SelectItem value="imagen">Imagen</SelectItem>
                    <SelectItem value="otro">Otro</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-2">
                <Label htmlFor="dcat">Categoría</Label>
                <Input id="dcat" value={formDoc.categoria}
                  onChange={(e) => setFormDoc({ ...formDoc, categoria: e.target.value })} placeholder="Plano / Contrato / Foto" />
              </div>
              <div className="space-y-2 md:col-span-3">
                <Label htmlFor="durl">Enlace (URL)</Label>
                <Input id="durl" type="url" required value={formDoc.url}
                  onChange={(e) => setFormDoc({ ...formDoc, url: e.target.value })} placeholder="https://drive.google.com/..." />
              </div>
              <Button type="submit" className="gap-2 md:self-end"><Plus className="w-4 h-4" /> Agregar</Button>
            </form>
          )}
          {documentos.length === 0 ? (
            <p className="text-muted-foreground text-sm">Todavía no hay documentos cargados.</p>
          ) : (
            <div className="space-y-2">
              {documentos.map((d) => (
                <div key={d.id_documento} className="flex items-center gap-3 border rounded-md px-4 py-2 text-sm">
                  <span className="text-muted-foreground w-24">{fmtFecha(d.fecha_carga)}</span>
                  <span className="font-medium">{d.nombre}</span>
                  <Badge variant="secondary">{d.categoria}</Badge>
                  <EnlaceDocumento url={d.url} />
                  {cargaDocs && (
                    <Button size="sm" variant="ghost" onClick={() => borrarDoc(d.id_documento)} title="Eliminar">
                      <Trash2 className="w-4 h-4" style={{ color: "#ef4444" }} />
                    </Button>
                  )}
                </div>
              ))}
            </div>
          )}
        </CardContent>
      </Card>

      {/* Períodos de inactividad (RF25) */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2"><Pause className="w-5 h-5" /> Períodos de inactividad</CardTitle>
          <CardDescription>Paradas de obra con su motivo (ayudan a justificar retrasos).</CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          {cargaDocs && (
            <form onSubmit={guardarInactividad} className="grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
              <div className="space-y-2">
                <Label htmlFor="ii">Desde</Label>
                <Input id="ii" type="date" required value={formInac.fecha_inicio} onChange={(e) => setFormInac({ ...formInac, fecha_inicio: e.target.value })} />
              </div>
              <div className="space-y-2">
                <Label htmlFor="if">Hasta (opcional)</Label>
                <Input id="if" type="date" min={formInac.fecha_inicio} value={formInac.fecha_fin} onChange={(e) => setFormInac({ ...formInac, fecha_fin: e.target.value })} />
              </div>
              <div className="space-y-2 md:col-span-2">
                <Label htmlFor="im">Motivo</Label>
                <Input id="im" required value={formInac.motivo} onChange={(e) => setFormInac({ ...formInac, motivo: e.target.value })} placeholder="Ej: Lluvias / falta de materiales" />
              </div>
              <Button type="submit" className="gap-2"><Plus className="w-4 h-4" /> Registrar período</Button>
            </form>
          )}
          {inactividades.length === 0 ? (
            <p className="text-muted-foreground text-sm">No hay períodos de inactividad registrados.</p>
          ) : (
            <div className="space-y-2">
              {inactividades.map((p) => (
                <div key={p.id_periodo} className="flex items-center gap-3 border rounded-md px-4 py-2 text-sm">
                  <span className="text-muted-foreground">
                    {fmtFecha(p.fecha_inicio)}{p.fecha_fin ? ` → ${fmtFecha(p.fecha_fin)}` : ""}
                  </span>
                  <span className="flex-1">{p.motivo}</span>
                  {p.vigente && <Badge variant="destructive">Obra pausada</Badge>}
                  {cargaDocs && p.vigente && (
                    <Button size="sm" className="gap-1" onClick={() => cerrarPeriodo(p.id_periodo)} title="Cierra el período y la obra vuelve a ejecución"><Play className="w-3 h-3" /> Continuar obra</Button>
                  )}
                  {cargaDocs && (
                    <Button size="sm" variant="ghost" onClick={() => borrarInactividad(p.id_periodo)} title="Eliminar"><Trash2 className="w-4 h-4" style={{ color: "#ef4444" }} /></Button>
                  )}
                </div>
              ))}
            </div>
          )}
        </CardContent>
      </Card>

      {/* Ítems excedentes (RF22) */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2"><Layers className="w-5 h-5" /> Ítems / trabajos excedentes</CardTitle>
          <CardDescription>Trabajos no contemplados en la planificación inicial.</CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          {cargaDocs && (
            <form onSubmit={guardarExcedente} className="grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
              <div className="space-y-2 md:col-span-2">
                <Label htmlFor="xd">Descripción</Label>
                <Input id="xd" required value={formExc.descripcion} onChange={(e) => setFormExc({ ...formExc, descripcion: e.target.value })} placeholder="Ej: Refuerzo de cimientos" />
              </div>
              <div className="space-y-2">
                <Label htmlFor="xc">Cantidad</Label>
                <Input id="xc" type="number" min="0" step="0.01" value={formExc.cantidad} onChange={(e) => setFormExc({ ...formExc, cantidad: e.target.value })} />
              </div>
              <div className="space-y-2">
                <Label htmlFor="xu">Unidad</Label>
                <Input id="xu" value={formExc.unidad} onChange={(e) => setFormExc({ ...formExc, unidad: e.target.value })} placeholder="m3, kg..." />
              </div>
              <div className="space-y-2 md:col-span-3">
                <Label htmlFor="xm">Motivo (opcional)</Label>
                <Input id="xm" value={formExc.motivo} onChange={(e) => setFormExc({ ...formExc, motivo: e.target.value })} placeholder="Por qué surgió" />
              </div>
              <Button type="submit" className="gap-2 md:self-end"><Plus className="w-4 h-4" /> Registrar</Button>
            </form>
          )}
          {excedentes.length === 0 ? (
            <p className="text-muted-foreground text-sm">No hay ítems excedentes registrados.</p>
          ) : (
            <div className="space-y-2">
              {excedentes.map((x) => (
                <div key={x.id_item} className="flex items-center gap-3 border rounded-md px-4 py-2 text-sm">
                  <span className="text-muted-foreground w-24">{fmtFecha(x.fecha)}</span>
                  <span className="font-medium">{x.descripcion}</span>
                  {x.cantidad != null && <span className="text-muted-foreground">{x.cantidad} {x.unidad ?? ""}</span>}
                  <span className="text-muted-foreground flex-1 truncate">{x.motivo ?? ""}</span>
                  {cargaDocs && (
                    <Button size="sm" variant="ghost" onClick={() => borrarExcedente(x.id_item)} title="Eliminar"><Trash2 className="w-4 h-4" style={{ color: "#ef4444" }} /></Button>
                  )}
                </div>
              ))}
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  );
}

function Metric({ label, value, color }: { label: string; value: string; color?: string }) {
  return (
    <div className="rounded-md border p-3">
      <div className="text-xs text-muted-foreground">{label}</div>
      <div className="text-xl font-bold" style={color ? { color } : undefined}>{value}</div>
    </div>
  );
}
