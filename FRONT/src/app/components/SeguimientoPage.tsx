import { useEffect, useState } from "react";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "./ui/card";
import { Button } from "./ui/button";
import { Input } from "./ui/input";
import { Label } from "./ui/label";
import { Textarea } from "./ui/textarea";
import { Badge } from "./ui/badge";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "./ui/select";
import { Activity, Users, CloudRain, Plus, Trash2 } from "lucide-react";
import { toast } from "sonner";
import {
  listarProyectos,
  listarAsistencias, crearAsistencia, eliminarAsistencia,
  listarIncidencias, crearIncidencia, eliminarIncidencia, incidenciaRegistrada,
  paginaVacia, sumarPagina, quitarDePagina,
  type Proyecto, type Asistencia, type Incidencia, type Pagina,
  type EstadoAsistencia, type TipoIncidencia, type GravedadIncidencia,
} from "../api/proyectos";
import { puedeRegistrarAvance } from "../auth/permisos";

const hoy = () => { const d = new Date(); return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10); };
const fmtFecha = (s: string) => new Date(s + "T00:00:00").toLocaleDateString("es-AR");
const ESTADO_ASIS: Record<EstadoAsistencia, { label: string; color: string }> = {
  presente: { label: "Presente", color: "#22c55e" }, ausente: { label: "Ausente", color: "#ef4444" }, tarde: { label: "Tarde", color: "#e8981e" },
};
const TIPO_INC: Record<TipoIncidencia, string> = { clima: "Clima", falla_maquinaria: "Falla de maquinaria", proveedor: "Retraso de proveedor", otro: "Otro" };
const GRAVEDAD_INC: Record<GravedadIncidencia, { label: string; color: string }> = {
  baja: { label: "Baja", color: "#22c55e" }, media: { label: "Media", color: "#e8981e" }, alta: { label: "Alta", color: "#ef4444" },
};

export default function SeguimientoPage() {
  const [proyectos, setProyectos] = useState<Proyecto[]>([]);
  const [obra, setObra] = useState("");
  const [asistencias, setAsistencias] = useState<Pagina<Asistencia>>(paginaVacia());
  const [incidencias, setIncidencias] = useState<Incidencia[]>([]);
  const [formAsis, setFormAsis] = useState<{ trabajador: string; estado: EstadoAsistencia; fecha: string; justificacion: string }>({ trabajador: "", estado: "presente", fecha: hoy(), justificacion: "" });
  const [formInc, setFormInc] = useState<{ tipo: TipoIncidencia; gravedad: GravedadIncidencia; fecha: string; descripcion: string; dias_retraso: string }>({ tipo: "clima", gravedad: "media", fecha: hoy(), descripcion: "", dias_retraso: "" });
  const registra = puedeRegistrarAvance();

  useEffect(() => { listarProyectos().then(setProyectos).catch(() => {}); }, []);
  useEffect(() => {
    if (!obra) { setAsistencias(paginaVacia()); setIncidencias([]); return; }
    listarAsistencias(obra).then(setAsistencias).catch(() => {});
    listarIncidencias(obra).then(setIncidencias).catch(() => {});
  }, [obra]);

  async function guardarAsis(e: React.FormEvent) {
    e.preventDefault(); if (!obra) return;
    try {
      await crearAsistencia(obra, { fecha: formAsis.fecha, trabajador: formAsis.trabajador, estado: formAsis.estado, justificacion: formAsis.justificacion || undefined });
      toast.success("Asistencia registrada"); setFormAsis({ trabajador: "", estado: "presente", fecha: hoy(), justificacion: "" });
      setAsistencias(await listarAsistencias(obra));
    } catch (err) { toast.error(err instanceof Error ? err.message : "Error"); }
  }
  async function verMasAsis() {
    try {
      const siguiente = await listarAsistencias(obra, asistencias.filas.length);
      setAsistencias((vista) => sumarPagina(vista, siguiente, "id_asistencia"));
    } catch (err) { toast.error(err instanceof Error ? err.message : "Error"); }
  }
  async function guardarInc(e: React.FormEvent) {
    e.preventDefault(); if (!obra) return;
    try {
      const creada = await crearIncidencia(obra, { fecha: formInc.fecha, tipo: formInc.tipo, gravedad: formInc.gravedad, descripcion: formInc.descripcion, dias_retraso: formInc.dias_retraso ? parseInt(formInc.dias_retraso, 10) : 0 });
      toast.success(incidenciaRegistrada(creada.avisados)); setFormInc({ tipo: "clima", gravedad: "media", fecha: hoy(), descripcion: "", dias_retraso: "" });
      setIncidencias(await listarIncidencias(obra));
    } catch (err) { toast.error(err instanceof Error ? err.message : "Error"); }
  }

  return (
    <div className="space-y-6">
      <div>
        <h2 className="text-3xl font-bold text-foreground flex items-center gap-2"><Activity className="w-7 h-7" /> Seguimiento Operativo</h2>
        <p className="text-muted-foreground mt-2">Asistencia del personal e incidencias externas por obra.</p>
      </div>

      <Card><CardContent className="pt-6">
        <Label>Obra</Label>
        <Select value={obra} onValueChange={setObra}>
          <SelectTrigger className="mt-2 max-w-md"><SelectValue placeholder="Elegí una obra para ver/cargar su seguimiento" /></SelectTrigger>
          <SelectContent>{proyectos.map((p) => <SelectItem key={p.id} value={String(p.id)}>{p.nombre}</SelectItem>)}</SelectContent>
        </Select>
      </CardContent></Card>

      {!obra ? (
        <Card><CardContent className="py-12 text-center text-muted-foreground">Seleccioná una obra para ver su asistencia e incidencias.</CardContent></Card>
      ) : (
        <>
          <Card>
            <CardHeader><CardTitle className="flex items-center gap-2"><Users className="w-5 h-5" /> Asistencia del personal</CardTitle><CardDescription>Trabajadores presentes en la obra.</CardDescription></CardHeader>
            <CardContent className="space-y-4">
              {registra && (
                <form onSubmit={guardarAsis} className="grid grid-cols-1 md:grid-cols-5 gap-3 items-end">
                  <div className="space-y-2 md:col-span-2"><Label>Trabajador</Label><Input required value={formAsis.trabajador} onChange={(e) => setFormAsis({ ...formAsis, trabajador: e.target.value })} placeholder="Nombre y apellido" /></div>
                  <div className="space-y-2"><Label>Estado</Label>
                    <Select value={formAsis.estado} onValueChange={(v) => setFormAsis({ ...formAsis, estado: v as EstadoAsistencia })}><SelectTrigger><SelectValue /></SelectTrigger>
                      <SelectContent><SelectItem value="presente">Presente</SelectItem><SelectItem value="ausente">Ausente</SelectItem><SelectItem value="tarde">Tarde</SelectItem></SelectContent></Select></div>
                  <div className="space-y-2"><Label>Fecha</Label><Input type="date" required value={formAsis.fecha} onChange={(e) => setFormAsis({ ...formAsis, fecha: e.target.value })} /></div>
                  <Button type="submit" className="gap-2"><Plus className="w-4 h-4" /> Registrar</Button>
                  {formAsis.estado !== "presente" && <div className="space-y-2 md:col-span-5"><Label>Justificación</Label><Input value={formAsis.justificacion} onChange={(e) => setFormAsis({ ...formAsis, justificacion: e.target.value })} placeholder="Motivo" /></div>}
                </form>
              )}
              {asistencias.filas.length === 0 ? <p className="text-muted-foreground text-sm">Sin asistencias registradas.</p> : (
                <div className="space-y-2">{asistencias.filas.map((a) => { const i = ESTADO_ASIS[a.estado]; return (
                  <div key={a.id_asistencia} className="flex items-center gap-3 border rounded-md px-4 py-2 text-sm">
                    <span className="text-muted-foreground w-24">{fmtFecha(a.fecha)}</span>
                    <span className="font-medium">{a.trabajador}</span><Badge style={{ background: i.color }}>{i.label}</Badge>
                    <span className="text-muted-foreground flex-1 truncate">{a.justificacion ?? ""}</span>
                    {registra && <Button size="sm" variant="ghost" onClick={() => eliminarAsistencia(a.id_asistencia).then(() => setAsistencias((p) => quitarDePagina(p, (x) => x.id_asistencia === a.id_asistencia))).catch(() => toast.error("Error"))}><Trash2 className="w-4 h-4" style={{ color: "#ef4444" }} /></Button>}
                  </div>); })}</div>
              )}
              {asistencias.hayMas && (
                <Button variant="outline" size="sm" onClick={verMasAsis}>
                  Ver más{asistencias.total !== null && ` (${asistencias.filas.length} de ${asistencias.total})`}
                </Button>
              )}
            </CardContent>
          </Card>

          <Card>
            <CardHeader><CardTitle className="flex items-center gap-2"><CloudRain className="w-5 h-5" /> Incidencias externas</CardTitle><CardDescription>Clima, fallas, proveedores.</CardDescription></CardHeader>
            <CardContent className="space-y-4">
              {registra && (
                <form onSubmit={guardarInc} className="grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
                  <div className="space-y-2"><Label>Tipo</Label>
                    <Select value={formInc.tipo} onValueChange={(v) => setFormInc({ ...formInc, tipo: v as TipoIncidencia })}><SelectTrigger><SelectValue /></SelectTrigger>
                      <SelectContent><SelectItem value="clima">Clima</SelectItem><SelectItem value="falla_maquinaria">Falla de maquinaria</SelectItem><SelectItem value="proveedor">Retraso de proveedor</SelectItem><SelectItem value="otro">Otro</SelectItem></SelectContent></Select></div>
                  <div className="space-y-2"><Label>Gravedad</Label>
                    <Select value={formInc.gravedad} onValueChange={(v) => setFormInc({ ...formInc, gravedad: v as GravedadIncidencia })}><SelectTrigger><SelectValue /></SelectTrigger>
                      <SelectContent><SelectItem value="baja">Baja</SelectItem><SelectItem value="media">Media</SelectItem><SelectItem value="alta">Alta</SelectItem></SelectContent></Select></div>
                  <div className="space-y-2"><Label>Fecha</Label><Input type="date" required value={formInc.fecha} onChange={(e) => setFormInc({ ...formInc, fecha: e.target.value })} /></div>
                  <div className="space-y-2"><Label>Días de retraso</Label><Input type="number" min="0" value={formInc.dias_retraso} onChange={(e) => setFormInc({ ...formInc, dias_retraso: e.target.value })} placeholder="0" /></div>
                  <div className="space-y-2 md:col-span-3"><Label>Descripción</Label><Textarea required value={formInc.descripcion} onChange={(e) => setFormInc({ ...formInc, descripcion: e.target.value })} placeholder="Detalle" /></div>
                  <Button type="submit" className="gap-2 md:self-end"><Plus className="w-4 h-4" /> Registrar</Button>
                </form>
              )}
              {incidencias.length === 0 ? <p className="text-muted-foreground text-sm">Sin incidencias registradas.</p> : (
                <div className="space-y-2">{incidencias.map((x) => { const g = GRAVEDAD_INC[x.gravedad]; return (
                  <div key={x.id_incidencia} className="flex items-center gap-3 border rounded-md px-4 py-2 text-sm">
                    <span className="text-muted-foreground w-24">{fmtFecha(x.fecha)}</span>
                    <span className="font-medium">{TIPO_INC[x.tipo]}</span><Badge style={{ background: g.color }}>{g.label}</Badge>
                    <span className="text-muted-foreground flex-1 truncate">{x.descripcion}</span>
                    {x.dias_retraso > 0 && <span className="text-xs text-muted-foreground whitespace-nowrap">+{x.dias_retraso} d</span>}
                    {registra && <Button size="sm" variant="ghost" onClick={() => eliminarIncidencia(x.id_incidencia).then(() => setIncidencias((p) => p.filter((y) => y.id_incidencia !== x.id_incidencia))).catch(() => toast.error("Error"))}><Trash2 className="w-4 h-4" style={{ color: "#ef4444" }} /></Button>}
                  </div>); })}</div>
              )}
            </CardContent>
          </Card>
        </>
      )}
    </div>
  );
}
