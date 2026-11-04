import { useEffect, useRef, useState } from "react";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "./ui/card";
import { Button } from "./ui/button";
import { Input } from "./ui/input";
import { Label } from "./ui/label";
import { Textarea } from "./ui/textarea";
import { Badge } from "./ui/badge";
import { Checkbox } from "./ui/checkbox";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "./ui/dialog";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "./ui/select";
import { Wrench, Plus, Trash2, AlertTriangle, Fuel, Activity, Users } from "lucide-react";
import { toast } from "sonner";
import {
  listarMaquinaria, crearMaquinaria, eliminarMaquinaria, actualizarConsumoEsperado, ErrorDeApi,
  listarRegistrosMaq, crearRegistroMaq, eliminarRegistroMaq,
  listarFallasMaq, crearFallaMaq, marcarFallaMaq, eliminarFallaMaq, rendimientoOperarios,
  listarProyectos,
  type Maquinaria, type RegistroMaquinaria, type FallaMaquinaria, type RendimientoOperario, type Proyecto,
} from "../api/proyectos";
import { puedeGestionarObras, puedeCargarDocumentos } from "../auth/permisos";

const hoy = () => { const d = new Date(); return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10); };
const fmtFecha = (s: string) => new Date(s + "T00:00:00").toLocaleDateString("es-AR");
const legible = (n: number) => String(n).replace(".", ",");
const MENSAJE_ESPERADO = "Debe ser un número mayor a 0";
// "" es "sin dato"; si hay texto, un número finito. El servidor decide el resto (mayor a 0, tope).
const esperadoDelCampo = (v: string): number | null | undefined => {
  if (v.trim() === "") return null;
  const n = Number(v.replace(",", "."));
  return Number.isFinite(n) ? n : undefined;
};

export default function MaquinariaPage() {
  const [maquinas, setMaquinas] = useState<Maquinaria[]>([]);
  const [proyectos, setProyectos] = useState<Proyecto[]>([]);
  const [operarios, setOperarios] = useState<RendimientoOperario[]>([]);
  const [sel, setSel] = useState<Maquinaria | null>(null);
  const [registros, setRegistros] = useState<RegistroMaquinaria[]>([]);
  const [fallas, setFallas] = useState<FallaMaquinaria[]>([]);
  const [loading, setLoading] = useState(true);

  const [formMaq, setFormMaq] = useState({ nombre: "", tipo: "", consumo_esperado: "" });
  const [errorEsperadoNuevo, setErrorEsperadoNuevo] = useState("");
  // Editar el consumo esperado de una maquina que ya existe (D-13).
  const [editando, setEditando] = useState<Maquinaria | null>(null);
  const [valorEsperado, setValorEsperado] = useState("");
  const [errorEsperado, setErrorEsperado] = useState("");
  const [guardandoEsperado, setGuardandoEsperado] = useState(false);
  const volverAlBoton = useRef<string | null>(null);
  const [formReg, setFormReg] = useState({ fecha: hoy(), id_proyecto: "", operario: "", horas_uso: "", combustible_consumido: "", produccion_realizada: "" });
  const [formFalla, setFormFalla] = useState({ fecha: hoy(), componente: "", descripcion: "", reemplazo: false });

  const gestiona = puedeGestionarObras();
  const carga = puedeCargarDocumentos();

  async function cargarTodo() {
    setLoading(true);
    try {
      setMaquinas(await listarMaquinaria());
      setOperarios(await rendimientoOperarios());
      listarProyectos().then(setProyectos).catch(() => {});
    } catch {
      toast.error("No se pudo cargar la maquinaria");
    } finally { setLoading(false); }
  }
  useEffect(() => { cargarTodo(); }, []);

  async function abrir(m: Maquinaria) {
    setSel(m);
    try {
      setRegistros(await listarRegistrosMaq(m.id_maquinaria));
      setFallas(await listarFallasMaq(m.id_maquinaria));
    } catch { /* noop */ }
  }
  async function recargarSel() {
    if (!sel) return;
    setRegistros(await listarRegistrosMaq(sel.id_maquinaria));
    setFallas(await listarFallasMaq(sel.id_maquinaria));
    setMaquinas(await listarMaquinaria());
    setOperarios(await rendimientoOperarios());
  }

  async function guardarMaq(e: React.FormEvent) {
    e.preventDefault();
    const esperado = esperadoDelCampo(formMaq.consumo_esperado);
    if (esperado === undefined) {
      setErrorEsperadoNuevo(MENSAJE_ESPERADO);
      document.getElementById("me")?.focus();
      return;
    }
    setErrorEsperadoNuevo("");
    try {
      await crearMaquinaria({
        nombre: formMaq.nombre,
        tipo: formMaq.tipo,
        ...(esperado !== null ? { consumo_esperado_por_hora: esperado } : {}),
      });
      toast.success("Maquinaria registrada");
      setFormMaq({ nombre: "", tipo: "", consumo_esperado: "" });
      setMaquinas(await listarMaquinaria());
    } catch (err) {
      const delServidor = err instanceof ErrorDeApi ? err.errores?.consumo_esperado_por_hora : undefined;
      if (delServidor) {
        setErrorEsperadoNuevo(delServidor);
        document.getElementById("me")?.focus();
      } else {
        toast.error(err instanceof Error ? err.message : "Error");
      }
    }
  }

  function abrirEsperado(m: Maquinaria) {
    setEditando(m);
    setValorEsperado(m.consumo_esperado_por_hora === null ? "" : String(m.consumo_esperado_por_hora));
    setErrorEsperado("");
  }
  function cerrarEsperado() {
    if (editando) volverAlBoton.current = `esperado-editar-${editando.id_maquinaria}`;
    setEditando(null);
  }
  async function guardarEsperado(e: React.FormEvent) {
    e.preventDefault();
    if (!editando) return;
    const esperado = esperadoDelCampo(valorEsperado);
    if (esperado === undefined) {
      setErrorEsperado(MENSAJE_ESPERADO);
      document.getElementById("esperado-valor")?.focus();
      return;
    }
    setGuardandoEsperado(true);
    try {
      await actualizarConsumoEsperado(editando.id_maquinaria, esperado);
      toast.success(esperado === null ? "Consumo esperado quitado" : "Consumo esperado guardado");
      const id = editando.id_maquinaria;
      cerrarEsperado();
      setMaquinas(await listarMaquinaria());
      // Cambia contra que se compara cada registro: se vuelven a pedir los de la maquina abierta.
      if (sel?.id_maquinaria === id) setRegistros(await listarRegistrosMaq(id));
    } catch (err) {
      const delServidor = err instanceof ErrorDeApi ? err.errores?.consumo_esperado_por_hora : undefined;
      if (delServidor) {
        setErrorEsperado(delServidor);
        document.getElementById("esperado-valor")?.focus();
      } else {
        toast.error(err instanceof Error ? err.message : "Error");
      }
    } finally { setGuardandoEsperado(false); }
  }
  async function guardarReg(e: React.FormEvent) {
    e.preventDefault();
    if (!sel) return;
    try {
      await crearRegistroMaq(sel.id_maquinaria, {
        fecha: formReg.fecha,
        id_proyecto: formReg.id_proyecto ? parseInt(formReg.id_proyecto, 10) : undefined,
        operario: formReg.operario || undefined,
        horas_uso: parseFloat(formReg.horas_uso) || 0,
        combustible_consumido: parseFloat(formReg.combustible_consumido) || 0,
        produccion_realizada: parseFloat(formReg.produccion_realizada) || 0,
      });
      toast.success("Uso registrado");
      setFormReg({ fecha: hoy(), id_proyecto: "", operario: "", horas_uso: "", combustible_consumido: "", produccion_realizada: "" });
      await recargarSel();
    } catch (err) { toast.error(err instanceof Error ? err.message : "Error"); }
  }
  async function guardarFalla(e: React.FormEvent) {
    e.preventDefault();
    if (!sel) return;
    try {
      await crearFallaMaq(sel.id_maquinaria, {
        fecha: formFalla.fecha, componente: formFalla.componente || undefined,
        descripcion: formFalla.descripcion, reemplazo: formFalla.reemplazo,
      });
      toast.success("Falla registrada");
      setFormFalla({ fecha: hoy(), componente: "", descripcion: "", reemplazo: false });
      await recargarSel();
    } catch (err) { toast.error(err instanceof Error ? err.message : "Error"); }
  }
  // D-11: resolver una falla la saca del contador sin borrarla del historial (RF27).
  async function marcarFalla(f: FallaMaquinaria) {
    try {
      await marcarFallaMaq(f.id_falla, !f.resuelto);
      toast.success(f.resuelto ? "Falla reabierta" : "Falla marcada como resuelta");
      await recargarSel();
    } catch (err) { toast.error(err instanceof Error ? err.message : "Error"); }
  }

  if (loading) return <div className="text-center text-muted-foreground py-12">Cargando maquinaria...</div>;

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-3xl font-bold text-foreground flex items-center gap-2"><Wrench className="w-7 h-7" /> Gestión de Maquinaria</h2>
          <p className="text-muted-foreground mt-2">Uso de equipos, fallas y rendimiento por operario.</p>
        </div>
      </div>

      {gestiona && (
        <Card>
          <CardContent className="pt-6">
            <form onSubmit={guardarMaq} className="flex flex-wrap items-end gap-3">
              <div className="space-y-2"><Label htmlFor="mn">Nueva máquina</Label>
                <Input id="mn" required placeholder="Nombre" value={formMaq.nombre} onChange={(e) => setFormMaq({ ...formMaq, nombre: e.target.value })} /></div>
              <div className="space-y-2"><Label htmlFor="mt">Tipo</Label>
                <Input id="mt" required placeholder="Ej: Excavación" value={formMaq.tipo} onChange={(e) => setFormMaq({ ...formMaq, tipo: e.target.value })} /></div>
              <div className="space-y-2"><Label htmlFor="me">Consumo esperado (L/h)</Label>
                <Input id="me" type="number" min="0.01" step="0.01" placeholder="Opcional" aria-invalid={!!errorEsperadoNuevo}
                  aria-describedby={errorEsperadoNuevo ? "me-error" : undefined}
                  value={formMaq.consumo_esperado} onChange={(e) => setFormMaq({ ...formMaq, consumo_esperado: e.target.value })} />
                {errorEsperadoNuevo && <p id="me-error" role="alert" className="text-sm text-destructive">{errorEsperadoNuevo}</p>}</div>
              <Button type="submit" className="gap-2"><Plus className="w-4 h-4" /> Agregar</Button>
            </form>
          </CardContent>
        </Card>
      )}

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
        {maquinas.map((m) => (
          <Card key={m.id_maquinaria} className={sel?.id_maquinaria === m.id_maquinaria ? "border-primary" : ""}>
            <CardHeader>
              <div className="flex items-start justify-between">
                <div><CardTitle>{m.nombre}</CardTitle><CardDescription>{m.tipo}</CardDescription></div>
                {m.fallas_abiertas > 0 && <Badge variant="destructive" className="gap-1"><AlertTriangle className="w-3 h-3" /> {m.fallas_abiertas} falla(s)</Badge>}
              </div>
            </CardHeader>
            <CardContent className="space-y-3 text-sm">
              <div className="flex flex-wrap gap-4 text-muted-foreground">
                <span className="flex items-center gap-1"><Activity className="w-4 h-4" /> {m.horas} h</span>
                <span className="flex items-center gap-1"><Fuel className="w-4 h-4" /> {m.combustible} L ({m.combustible_por_hora} L/h real,{" "}
                  {m.consumo_esperado_por_hora !== null ? `esperado: ${legible(m.consumo_esperado_por_hora)} L/h` : "sin dato esperado"})</span>
                <span>Prod.: {m.produccion} ({m.produccion_por_hora}/h)</span>
              </div>
              <div className="flex gap-2">
                <Button size="sm" variant="outline" onClick={() => abrir(m)}>Ver uso y fallas</Button>
                {gestiona && (
                  <Button size="sm" variant="outline" id={`esperado-editar-${m.id_maquinaria}`} onClick={() => abrirEsperado(m)}>Consumo esperado</Button>
                )}
                {gestiona && (
                  <Button size="sm" variant="ghost" onClick={() => { eliminarMaquinaria(m.id_maquinaria).then(() => { setMaquinas((p) => p.filter((x) => x.id_maquinaria !== m.id_maquinaria)); if (sel?.id_maquinaria === m.id_maquinaria) setSel(null); }).catch(() => toast.error("Error")); }}>
                    <Trash2 className="w-4 h-4" style={{ color: "#ef4444" }} />
                  </Button>
                )}
              </div>
            </CardContent>
          </Card>
        ))}
      </div>

      {/* Consumo esperado de una maquina (D-13) */}
      <Dialog open={editando !== null} onOpenChange={(abierto) => !abierto && cerrarEsperado()}>
        <DialogContent
          onCloseAutoFocus={(e) => {
            e.preventDefault();
            const id = volverAlBoton.current;
            volverAlBoton.current = null;
            if (id) document.getElementById(id)?.focus();
          }}
        >
          <form onSubmit={guardarEsperado} className="space-y-4" noValidate>
            <DialogHeader>
              <DialogTitle>Consumo esperado de {editando?.nombre}</DialogTitle>
              <DialogDescription>
                Litros de combustible por hora que se esperan de esta máquina. La alerta de consumo alto compara contra este valor.
              </DialogDescription>
            </DialogHeader>
            <div className="space-y-2">
              <Label htmlFor="esperado-valor">Consumo esperado (L/h)</Label>
              <Input id="esperado-valor" type="number" min="0.01" step="0.01" inputMode="decimal" value={valorEsperado}
                aria-invalid={!!errorEsperado}
                aria-describedby={errorEsperado ? "esperado-ayuda esperado-error" : "esperado-ayuda"}
                onChange={(e) => setValorEsperado(e.target.value)} />
              <p id="esperado-ayuda" className="text-sm text-muted-foreground">
                Dejalo vacío para quitar el dato: la máquina vuelve a compararse contra su promedio.
              </p>
              {errorEsperado && <p id="esperado-error" role="alert" className="text-sm text-destructive">{errorEsperado}</p>}
            </div>
            <DialogFooter>
              <Button type="button" variant="outline" onClick={cerrarEsperado}>Cancelar</Button>
              <Button type="submit" disabled={guardandoEsperado}>Guardar</Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      {sel && (
        <Card>
          <CardHeader><CardTitle>{sel.nombre} — uso y fallas</CardTitle></CardHeader>
          <CardContent className="space-y-6">
            {/* Registro de uso (RF23) */}
            <div className="space-y-3">
              <h4 className="font-semibold">Registro de uso</h4>
              {carga && (
                <form onSubmit={guardarReg} className="grid grid-cols-2 md:grid-cols-6 gap-2 items-end">
                  <div className="space-y-1 col-span-2 md:col-span-6"><Label>Obra (opcional)</Label>
                    <Select value={formReg.id_proyecto} onValueChange={(v) => setFormReg({ ...formReg, id_proyecto: v })}>
                      <SelectTrigger><SelectValue placeholder="Vincular este uso a una obra (opcional)" /></SelectTrigger>
                      <SelectContent>
                        <SelectItem value="">Sin obra</SelectItem>
                        {proyectos.map((p) => <SelectItem key={p.id} value={String(p.id)}>{p.nombre}</SelectItem>)}
                      </SelectContent>
                    </Select></div>
                  <div className="space-y-1"><Label htmlFor="rf">Fecha</Label><Input id="rf" type="date" required value={formReg.fecha} onChange={(e) => setFormReg({ ...formReg, fecha: e.target.value })} /></div>
                  <div className="space-y-1"><Label htmlFor="ro">Operario</Label><Input id="ro" value={formReg.operario} onChange={(e) => setFormReg({ ...formReg, operario: e.target.value })} /></div>
                  <div className="space-y-1"><Label htmlFor="rh">Horas</Label><Input id="rh" type="number" min="0" step="0.1" value={formReg.horas_uso} onChange={(e) => setFormReg({ ...formReg, horas_uso: e.target.value })} /></div>
                  <div className="space-y-1"><Label htmlFor="rc">Combust. (L)</Label><Input id="rc" type="number" min="0" step="0.1" value={formReg.combustible_consumido} onChange={(e) => setFormReg({ ...formReg, combustible_consumido: e.target.value })} /></div>
                  <div className="space-y-1"><Label htmlFor="rp">Producción</Label><Input id="rp" type="number" min="0" step="0.1" value={formReg.produccion_realizada} onChange={(e) => setFormReg({ ...formReg, produccion_realizada: e.target.value })} /></div>
                  <Button type="submit" className="gap-1"><Plus className="w-4 h-4" /> Registrar</Button>
                </form>
              )}
              {registros.length === 0 ? <p className="text-muted-foreground text-sm">Sin registros de uso.</p> : (
                <div className="space-y-1">
                  {registros.map((r) => (
                    <div key={r.id_registro} className="flex items-center gap-3 border rounded-md px-3 py-2 text-sm">
                      <span className="text-muted-foreground w-24">{fmtFecha(r.fecha)}</span>
                      <span className="w-32">{r.operario ?? "—"}</span>
                      <span>{r.horas_uso} h</span>
                      <span>{r.combustible_consumido} L</span>
                      <span className="text-muted-foreground">{r.combustible_por_hora} L/h</span>
                      {r.alerta_consumo && <Badge variant="destructive" className="gap-1"><AlertTriangle className="w-3 h-3" /> Consumo alto</Badge>}
                      <span className="flex-1 text-right">Prod. {r.produccion_realizada}</span>
                      {carga && <Button size="sm" variant="ghost" onClick={() => eliminarRegistroMaq(r.id_registro).then(recargarSel).catch(() => toast.error("Error"))}><Trash2 className="w-4 h-4" style={{ color: "#ef4444" }} /></Button>}
                    </div>
                  ))}
                </div>
              )}
            </div>

            {/* Fallas (RF27) */}
            <div className="space-y-3">
              <h4 className="font-semibold">Historial de fallas</h4>
              {carga && (
                <form onSubmit={guardarFalla} className="grid grid-cols-1 md:grid-cols-4 gap-2 items-end">
                  <div className="space-y-1"><Label htmlFor="ff">Fecha</Label><Input id="ff" type="date" required value={formFalla.fecha} onChange={(e) => setFormFalla({ ...formFalla, fecha: e.target.value })} /></div>
                  <div className="space-y-1"><Label htmlFor="fc">Componente</Label><Input id="fc" value={formFalla.componente} onChange={(e) => setFormFalla({ ...formFalla, componente: e.target.value })} /></div>
                  <div className="space-y-1 md:col-span-2"><Label htmlFor="fd">Descripción</Label><Textarea id="fd" required rows={1} value={formFalla.descripcion} onChange={(e) => setFormFalla({ ...formFalla, descripcion: e.target.value })} /></div>
                  <label className="flex items-center gap-2 text-sm"><Checkbox checked={formFalla.reemplazo} onCheckedChange={(v) => setFormFalla({ ...formFalla, reemplazo: !!v })} /> Hubo reemplazo</label>
                  <Button type="submit" className="gap-1"><Plus className="w-4 h-4" /> Registrar falla</Button>
                </form>
              )}
              {fallas.length === 0 ? <p className="text-muted-foreground text-sm">Sin fallas registradas.</p> : (
                <div className="space-y-1">
                  {fallas.map((f) => (
                    <div key={f.id_falla} className="flex items-center gap-3 border rounded-md px-3 py-2 text-sm">
                      <span className="text-muted-foreground w-24">{fmtFecha(f.fecha)}</span>
                      {f.componente && <Badge variant="secondary">{f.componente}</Badge>}
                      <span className="flex-1">{f.descripcion}</span>
                      {f.reemplazo && <Badge variant="outline">Reemplazo</Badge>}
                      {f.resuelto ? <Badge variant="secondary">Resuelta</Badge> : <Badge variant="destructive">Abierta</Badge>}
                      {carga && <Button size="sm" variant="outline" onClick={() => marcarFalla(f)}>{f.resuelto ? "Reabrir" : "Marcar resuelta"}</Button>}
                      {carga && <Button size="sm" variant="ghost" onClick={() => eliminarFallaMaq(f.id_falla).then(recargarSel).catch(() => toast.error("Error"))}><Trash2 className="w-4 h-4" style={{ color: "#ef4444" }} /></Button>}
                    </div>
                  ))}
                </div>
              )}
            </div>
          </CardContent>
        </Card>
      )}

      {/* Rendimiento por operario (RF28) */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2"><Users className="w-5 h-5" /> Rendimiento por operario</CardTitle>
          <CardDescription>Comparativa de producción entre operarios.</CardDescription>
        </CardHeader>
        <CardContent>
          {operarios.length === 0 ? <p className="text-muted-foreground text-sm">Todavía no hay datos de operarios.</p> : (
            <div className="space-y-1">
              <div className="grid text-xs text-muted-foreground uppercase" style={{ gridTemplateColumns: "1fr 110px 130px 130px" }}>
                <span>Operario</span><span>Horas</span><span>Producción</span><span>Prod./hora</span>
              </div>
              {operarios.map((o, i) => (
                <div key={i} className="grid items-center text-sm border-t py-2" style={{ gridTemplateColumns: "1fr 110px 130px 130px" }}>
                  <span className="font-medium">{o.operario}</span>
                  <span>{o.horas} h</span>
                  <span>{o.produccion}</span>
                  <span style={{ fontWeight: 600 }}>{o.produccion_por_hora}</span>
                </div>
              ))}
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
