import { useEffect, useRef, useState } from "react";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "./ui/card";
import { Button } from "./ui/button";
import { Input } from "./ui/input";
import { Label } from "./ui/label";
import { Textarea } from "./ui/textarea";
import { Checkbox } from "./ui/checkbox";
import { etiquetaEstado } from "../estadosObra";
import { Badge } from "./ui/badge";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "./ui/select";
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "./ui/dialog";
import { FileText, Plus, Send, Check, X, Edit, Trash2 } from "lucide-react";
import { toast } from "sonner";
import {
  listarReportes, crearReporte, editarReporte, enviarReporte,
  aprobarReporte, rechazarReporte, eliminarReporte,
  listarProyectos, paginaVacia, sumarPagina,
  type Reporte, type EstadoReporte, type Proyecto, type Pagina,
} from "../api/proyectos";
import { puedeCargarDocumentos, puedeAprobarReportes } from "../auth/permisos";

const ESTADOS: Record<EstadoReporte, { label: string; color: string }> = {
  borrador: { label: "Borrador", color: "#64748b" },
  en_revision: { label: "En revisión", color: "#e8981e" },
  aprobado: { label: "Aprobado", color: "#22c55e" },
  rechazado: { label: "Rechazado", color: "#ef4444" },
};

const FILTROS: { valor: string; label: string }[] = [
  { valor: "", label: "Todos" },
  { valor: "en_revision", label: "En revisión" },
  { valor: "borrador", label: "Borradores" },
  { valor: "aprobado", label: "Aprobados" },
  { valor: "rechazado", label: "Rechazados" },
];

export default function ReportesPage() {
  const [reportes, setReportes] = useState<Pagina<Reporte>>(paginaVacia());
  const [proyectos, setProyectos] = useState<Proyecto[]>([]);
  const [loading, setLoading] = useState(true);
  const [filtro, setFiltro] = useState("");

  const [dialogNuevo, setDialogNuevo] = useState(false);
  const [formNuevo, setFormNuevo] = useState({ id_proyecto: "", titulo: "", contenido: "", es_final: false });
  const [editando, setEditando] = useState<Reporte | null>(null);
  const [formEdit, setFormEdit] = useState({ titulo: "", contenido: "", es_final: false });
  const [rechazando, setRechazando] = useState<Reporte | null>(null);
  const [observacion, setObservacion] = useState("");

  const cargaReportes = puedeCargarDocumentos();
  const aprueba = puedeAprobarReportes();

  // El filtro por estado lo aplica la API, que pagina (C-03). Si se cambia de
  // filtro antes de que llegue la respuesta anterior, esa se descarta.
  const pedido = useRef(0);
  async function cargar() {
    const este = ++pedido.current;
    setLoading(true);
    try {
      const pagina = await listarReportes(filtro);
      if (este === pedido.current) setReportes(pagina);
    } catch {
      toast.error("No se pudieron cargar los reportes");
    } finally {
      if (este === pedido.current) setLoading(false);
    }
  }
  async function verMas() {
    const este = pedido.current;
    try {
      const siguiente = await listarReportes(filtro, reportes.filas.length);
      if (este === pedido.current) setReportes((vista) => sumarPagina(vista, siguiente, "id_reporte"));
    } catch {
      toast.error("No se pudieron cargar más reportes");
    }
  }
  useEffect(() => {
    listarProyectos().then(setProyectos).catch(() => {});
  }, []);
  useEffect(() => {
    cargar();
  }, [filtro]);

  async function guardarNuevo(e: React.FormEvent) {
    e.preventDefault();
    try {
      await crearReporte({
        id_proyecto: parseInt(formNuevo.id_proyecto, 10),
        titulo: formNuevo.titulo,
        contenido: formNuevo.contenido,
        es_final: formNuevo.es_final,
      });
      toast.success("Reporte creado (borrador)");
      setDialogNuevo(false);
      setFormNuevo({ id_proyecto: "", titulo: "", contenido: "", es_final: false });
      await cargar();
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Error al crear el reporte");
    }
  }

  async function guardarEdicion(e: React.FormEvent) {
    e.preventDefault();
    if (!editando) return;
    try {
      await editarReporte(editando.id_reporte, formEdit);
      toast.success("Reporte actualizado");
      setEditando(null);
      await cargar();
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Error al editar");
    }
  }

  // Enviar, aprobar o rechazar un reporte final mueve la obra. El backend
  // devuelve `estado_proyecto` solo cuando eso pasó, y se avisa: el usuario
  // actuó sobre un reporte y el efecto real está en otra pantalla.
  async function accion(fn: () => Promise<unknown>, ok: string) {
    try {
      const res = (await fn()) as { estado_proyecto?: string } | undefined;
      toast.success(ok);
      if (res?.estado_proyecto) {
        toast.info(`La obra quedó ${etiquetaEstado(res.estado_proyecto).toLowerCase()}`);
      }
      await cargar();
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Error en la operación");
    }
  }

  async function confirmarRechazo(e: React.FormEvent) {
    e.preventDefault();
    if (!rechazando) return;
    try {
      await rechazarReporte(rechazando.id_reporte, observacion);
      toast.success("Reporte rechazado");
      setRechazando(null);
      setObservacion("");
      await cargar();
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Error al rechazar");
    }
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-3xl font-bold text-foreground flex items-center gap-2"><FileText className="w-7 h-7" /> Reportes y Control</h2>
          <p className="text-muted-foreground mt-2">Carga, revisión y aprobación de reportes operativos de obra.</p>
        </div>
        {cargaReportes && (
          <Button className="gap-2" onClick={() => setDialogNuevo(true)}>
            <Plus className="w-4 h-4" /> Nuevo reporte
          </Button>
        )}
      </div>

      {/* Filtros por estado */}
      <div className="flex flex-wrap gap-2">
        {FILTROS.map((f) => (
          <Button key={f.valor} size="sm" variant={filtro === f.valor ? "default" : "outline"} onClick={() => setFiltro(f.valor)}>
            {f.label}
          </Button>
        ))}
      </div>

      {loading && <div className="text-center text-muted-foreground py-12">Cargando reportes...</div>}

      {!loading && reportes.filas.length === 0 && (
        <Card><CardContent className="py-12 text-center text-muted-foreground">No hay reportes para mostrar.</CardContent></Card>
      )}

      <div className="space-y-4">
        {reportes.filas.map((r) => {
          const est = ESTADOS[r.estado];
          const editable = r.estado === "borrador" || r.estado === "rechazado";
          return (
            <Card key={r.id_reporte}>
              <CardHeader>
                <div className="flex items-start justify-between gap-3">
                  <div>
                    <CardTitle className="text-lg">{r.titulo}</CardTitle>
                    <CardDescription className="mt-1">
                      {r.proyecto} · por {r.autor} · {new Date(r.fecha_creacion).toLocaleDateString("es-AR")}
                    </CardDescription>
                  </div>
                  <div className="flex flex-wrap items-center gap-2">
                    {r.es_final && <Badge variant="outline">Reporte final</Badge>}
                    <Badge style={{ background: est.color }}>{est.label}</Badge>
                  </div>
                </div>
              </CardHeader>
              <CardContent className="space-y-3">
                <p className="text-sm whitespace-pre-wrap">{r.contenido}</p>

                {r.observacion_revision && (
                  <div className="text-sm rounded-md border-l-2 px-3 py-2" style={{ borderColor: "#ef4444", background: "rgba(239,68,68,0.06)" }}>
                    <span className="font-semibold">Observación del revisor: </span>{r.observacion_revision}
                  </div>
                )}

                <div className="flex flex-wrap gap-2 pt-1">
                  {cargaReportes && editable && (
                    <>
                      <Button size="sm" variant="outline" className="gap-1" onClick={() => { setEditando(r); setFormEdit({ titulo: r.titulo, contenido: r.contenido, es_final: r.es_final }); }}>
                        <Edit className="w-4 h-4" /> Editar
                      </Button>
                      <Button size="sm" className="gap-1" onClick={() => accion(() => enviarReporte(r.id_reporte), "Enviado a revisión")}>
                        <Send className="w-4 h-4" /> Enviar a revisión
                      </Button>
                      <Button size="sm" variant="ghost" className="gap-1" onClick={() => accion(() => eliminarReporte(r.id_reporte), "Reporte eliminado")}>
                        <Trash2 className="w-4 h-4" style={{ color: "#ef4444" }} />
                      </Button>
                    </>
                  )}
                  {aprueba && r.estado === "en_revision" && (
                    <>
                      <Button size="sm" className="gap-1" style={{ background: "#22c55e" }} onClick={() => accion(() => aprobarReporte(r.id_reporte), "Reporte aprobado")}>
                        <Check className="w-4 h-4" /> Aprobar
                      </Button>
                      <Button size="sm" variant="destructive" className="gap-1" onClick={() => { setRechazando(r); setObservacion(""); }}>
                        <X className="w-4 h-4" /> Rechazar
                      </Button>
                    </>
                  )}
                </div>
              </CardContent>
            </Card>
          );
        })}
      </div>

      {!loading && reportes.hayMas && (
        <div className="text-center">
          <Button variant="outline" onClick={verMas}>
            Ver más{reportes.total !== null && ` (${reportes.filas.length} de ${reportes.total})`}
          </Button>
        </div>
      )}

      {/* Dialog: nuevo reporte */}
      <Dialog open={dialogNuevo} onOpenChange={setDialogNuevo}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Nuevo reporte</DialogTitle>
            <DialogDescription>Se crea como borrador. Después podés enviarlo a revisión.</DialogDescription>
          </DialogHeader>
          <form onSubmit={guardarNuevo} className="space-y-4">
            <div className="space-y-2">
              <Label>Obra</Label>
              <Select value={formNuevo.id_proyecto} onValueChange={(v) => setFormNuevo({ ...formNuevo, id_proyecto: v })}>
                <SelectTrigger><SelectValue placeholder="Elegí la obra" /></SelectTrigger>
                <SelectContent>
                  {proyectos.map((p) => (
                    <SelectItem key={p.id} value={String(p.id)}>{p.nombre}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-2">
              <Label htmlFor="rtit">Título</Label>
              <Input id="rtit" required value={formNuevo.titulo} onChange={(e) => setFormNuevo({ ...formNuevo, titulo: e.target.value })} placeholder="Ej: Reporte semanal de avance" />
            </div>
            <div className="space-y-2">
              <Label htmlFor="rcont">Contenido</Label>
              <Textarea id="rcont" required value={formNuevo.contenido} onChange={(e) => setFormNuevo({ ...formNuevo, contenido: e.target.value })} placeholder="Detalle del reporte..." rows={5} />
            </div>
            {/* El reporte final cierra la obra: al enviarlo pasa a revisión y
                al aprobarse queda finalizada. Un parte diario común va sin
                marcar y no toca el estado de la obra. */}
            <div className="flex items-start gap-3 rounded-md border p-3">
              <Checkbox
                id="rfinal"
                checked={formNuevo.es_final}
                onCheckedChange={(v) => setFormNuevo({ ...formNuevo, es_final: v === true })}
              />
              <div className="space-y-1">
                <Label htmlFor="rfinal" className="cursor-pointer">Es el reporte final de la obra</Label>
                <p className="text-xs text-muted-foreground">
                  Al enviarlo, la obra pasa a revisión. Cuando el supervisor lo apruebe, queda finalizada.
                </p>
              </div>
            </div>
            <div className="flex justify-end gap-2">
              <Button type="button" variant="outline" onClick={() => setDialogNuevo(false)}>Cancelar</Button>
              <Button type="submit">Crear borrador</Button>
            </div>
          </form>
        </DialogContent>
      </Dialog>

      {/* Dialog: editar reporte */}
      <Dialog open={editando !== null} onOpenChange={(o) => !o && setEditando(null)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Editar reporte</DialogTitle>
            <DialogDescription>Solo se puede editar en borrador o rechazado.</DialogDescription>
          </DialogHeader>
          <form onSubmit={guardarEdicion} className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="etit">Título</Label>
              <Input id="etit" required value={formEdit.titulo} onChange={(e) => setFormEdit({ ...formEdit, titulo: e.target.value })} />
            </div>
            <div className="space-y-2">
              <Label htmlFor="econt">Contenido</Label>
              <Textarea id="econt" required value={formEdit.contenido} onChange={(e) => setFormEdit({ ...formEdit, contenido: e.target.value })} rows={5} />
            </div>
            <div className="flex items-start gap-3 rounded-md border p-3">
              <Checkbox
                id="efinal"
                checked={formEdit.es_final}
                onCheckedChange={(v) => setFormEdit({ ...formEdit, es_final: v === true })}
              />
              <div className="space-y-1">
                <Label htmlFor="efinal" className="cursor-pointer">Es el reporte final de la obra</Label>
                <p className="text-xs text-muted-foreground">
                  Al enviarlo, la obra pasa a revisión. Cuando el supervisor lo apruebe, queda finalizada.
                </p>
              </div>
            </div>
            <div className="flex justify-end gap-2">
              <Button type="button" variant="outline" onClick={() => setEditando(null)}>Cancelar</Button>
              <Button type="submit">Guardar</Button>
            </div>
          </form>
        </DialogContent>
      </Dialog>

      {/* Dialog: rechazar reporte */}
      <Dialog open={rechazando !== null} onOpenChange={(o) => !o && setRechazando(null)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Rechazar reporte</DialogTitle>
            <DialogDescription>Indicá el motivo del rechazo. El autor podrá corregir y reenviar.</DialogDescription>
          </DialogHeader>
          <form onSubmit={confirmarRechazo} className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="obs">Observación</Label>
              <Textarea id="obs" required value={observacion} onChange={(e) => setObservacion(e.target.value)} placeholder="Motivo del rechazo..." rows={4} />
            </div>
            <div className="flex justify-end gap-2">
              <Button type="button" variant="outline" onClick={() => setRechazando(null)}>Cancelar</Button>
              <Button type="submit" variant="destructive">Rechazar</Button>
            </div>
          </form>
        </DialogContent>
      </Dialog>
    </div>
  );
}
