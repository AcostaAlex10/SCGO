import { useEffect, useState } from "react";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "./ui/card";
import { Button } from "./ui/button";
import { Input } from "./ui/input";
import { Label } from "./ui/label";
import { Badge } from "./ui/badge";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "./ui/select";
import { Package, Plus, Trash2, AlertTriangle } from "lucide-react";
import { toast } from "sonner";
import {
  listarProyectos, listarCatalogoMateriales, listarMaterialesObra,
  asignarMaterial, eliminarAsignacionMaterial, crearConsumo,
  type Proyecto, type Material, type AsignacionMaterial,
} from "../api/proyectos";
import { puedeGestionarObras, puedeRegistrarAvance } from "../auth/permisos";

const hoy = () => { const d = new Date(); return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10); };

export default function MaterialesPage() {
  const [proyectos, setProyectos] = useState<Proyecto[]>([]);
  const [catalogo, setCatalogo] = useState<Material[]>([]);
  const [obra, setObra] = useState("");
  const [materiales, setMateriales] = useState<AsignacionMaterial[]>([]);
  const [formMat, setFormMat] = useState({ id_material: "", cantidad_asignada: "" });
  const [consumoInput, setConsumoInput] = useState<Record<number, string>>({});
  const gestiona = puedeGestionarObras();
  const registra = puedeRegistrarAvance();

  useEffect(() => {
    listarProyectos().then(setProyectos).catch(() => {});
    listarCatalogoMateriales().then(setCatalogo).catch(() => {});
  }, []);
  useEffect(() => {
    if (!obra) { setMateriales([]); return; }
    listarMaterialesObra(obra).then(setMateriales).catch(() => {});
  }, [obra]);

  async function asignar(e: React.FormEvent) {
    e.preventDefault(); if (!obra) return;
    try {
      await asignarMaterial(obra, { id_material: parseInt(formMat.id_material, 10), cantidad_asignada: parseFloat(formMat.cantidad_asignada) });
      toast.success("Material asignado"); setFormMat({ id_material: "", cantidad_asignada: "" });
      setMateriales(await listarMaterialesObra(obra));
    } catch (err) { toast.error(err instanceof Error ? err.message : "Error"); }
  }
  const hayPendientes = Object.values(consumoInput).some((v) => v.trim() !== "");

  async function registrarConsumo(idAsig: number) {
    if (!obra) return;
    const cant = parseFloat(consumoInput[idAsig] ?? "");
    if (!cant || cant <= 0) { toast.error("Ingresá una cantidad válida"); return; }
    try {
      await crearConsumo(idAsig, { fecha: hoy(), cantidad_consumida: cant });
      toast.success("Consumo registrado"); setConsumoInput((p) => ({ ...p, [idAsig]: "" }));
      setMateriales(await listarMaterialesObra(obra));
    } catch (err) { toast.error(err instanceof Error ? err.message : "Error"); }
  }

  return (
    <div className="space-y-6">
      <div>
        <h2 className="text-3xl font-bold text-foreground flex items-center gap-2"><Package className="w-7 h-7" /> Gestión de Materiales</h2>
        <p className="text-muted-foreground mt-2">Asignación y consumo de materiales por obra, con alerta de exceso.</p>
      </div>

      <Card><CardContent className="pt-6">
        <Label>Obra</Label>
        <Select value={obra} onValueChange={setObra}>
          <SelectTrigger className="mt-2 max-w-md"><SelectValue placeholder="Elegí una obra" /></SelectTrigger>
          <SelectContent>{proyectos.map((p) => <SelectItem key={p.id} value={String(p.id)}>{p.nombre}</SelectItem>)}</SelectContent>
        </Select>
      </CardContent></Card>

      {!obra ? (
        <Card><CardContent className="py-12 text-center text-muted-foreground">Seleccioná una obra para gestionar sus materiales.</CardContent></Card>
      ) : (
        <Card>
          <CardHeader><CardTitle>Materiales de la obra</CardTitle><CardDescription>Asignado vs. consumido. Se avisa si se excede.</CardDescription></CardHeader>
          <CardContent className="space-y-4">
            {gestiona && (
              <form onSubmit={asignar} className="grid grid-cols-1 md:grid-cols-3 gap-3 items-end">
                <div className="space-y-2"><Label>Material</Label>
                  <Select value={formMat.id_material} onValueChange={(v) => setFormMat({ ...formMat, id_material: v })}><SelectTrigger><SelectValue placeholder="Elegí un material" /></SelectTrigger>
                    <SelectContent>{catalogo.map((m) => <SelectItem key={m.id_material} value={String(m.id_material)}>{m.nombre} ({m.unidad})</SelectItem>)}</SelectContent></Select></div>
                <div className="space-y-2"><Label>Cantidad asignada</Label><Input type="number" min="0" step="0.01" required value={formMat.cantidad_asignada} onChange={(e) => setFormMat({ ...formMat, cantidad_asignada: e.target.value })} /></div>
                <Button type="submit" className="gap-2"><Plus className="w-4 h-4" /> Asignar material</Button>
              </form>
            )}
            {materiales.length === 0 ? <p className="text-muted-foreground text-sm">Sin materiales asignados.</p> : (
              <div className="space-y-2">
                {/* Descartar lo cargado sin guardar (CU22, D-15). */}
                {registra && (
                  <div className="flex justify-end">
                    <Button size="sm" type="button" variant="outline" disabled={!hayPendientes} onClick={() => setConsumoInput({})}>Cancelar</Button>
                  </div>
                )}
                {materiales.map((m) => (
                <div key={m.id_asignacion} className="border rounded-md px-4 py-3 text-sm space-y-2">
                  <div className="flex items-center gap-3 flex-wrap">
                    <span className="font-medium">{m.nombre}</span>
                    <span className="text-muted-foreground">{m.consumido} / {m.cantidad_asignada} {m.unidad} · restante {m.restante} {m.unidad}</span>
                    {m.excedido && <Badge variant="destructive" className="gap-1"><AlertTriangle className="w-3 h-3" /> Excedido</Badge>}
                    {gestiona && <Button size="sm" variant="ghost" className="ml-auto" onClick={() => eliminarAsignacionMaterial(m.id_asignacion).then(() => listarMaterialesObra(obra).then(setMateriales)).catch(() => toast.error("Error"))}><Trash2 className="w-4 h-4" style={{ color: "#ef4444" }} /></Button>}
                  </div>
                  <div className="w-full bg-gray-200 rounded-full h-2"><div className="h-2 rounded-full" style={{ width: `${Math.min(100, m.cantidad_asignada > 0 ? (m.consumido / m.cantidad_asignada) * 100 : 0)}%`, background: m.excedido ? "#ef4444" : "#3b82f6" }} /></div>
                  {registra && (
                    <div className="flex items-end gap-2 pt-1">
                      <Input type="number" min="0" step="0.01" placeholder={`Consumo (${m.unidad})`} value={consumoInput[m.id_asignacion] ?? ""} onChange={(e) => setConsumoInput((p) => ({ ...p, [m.id_asignacion]: e.target.value }))} className="max-w-[220px]" />
                      <Button size="sm" type="button" onClick={() => registrarConsumo(m.id_asignacion)} className="gap-1"><Plus className="w-4 h-4" /> Registrar consumo</Button>
                    </div>
                  )}
                </div>
              ))}
              </div>
            )}
          </CardContent>
        </Card>
      )}
    </div>
  );
}
