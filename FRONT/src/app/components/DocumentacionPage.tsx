import { useEffect, useState } from "react";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "./ui/card";
import { Button } from "./ui/button";
import { Input } from "./ui/input";
import { Label } from "./ui/label";
import { Badge } from "./ui/badge";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "./ui/select";
import { FileText, Plus, Trash2 } from "lucide-react";
import { toast } from "sonner";
import { EnlaceDocumento } from "./EnlaceDocumento";
import {
  listarProyectos, listarDocumentos, crearDocumento, eliminarDocumento,
  type Proyecto, type Documento, type TipoDocumento,
} from "../api/proyectos";
import { puedeCargarDocumentos } from "../auth/permisos";

const hoy = () => { const d = new Date(); return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10); };
const fmtFecha = (s: string) => new Date(s + "T00:00:00").toLocaleDateString("es-AR");

export default function DocumentacionPage() {
  const [proyectos, setProyectos] = useState<Proyecto[]>([]);
  const [obra, setObra] = useState("");
  const [docs, setDocs] = useState<Documento[]>([]);
  const [form, setForm] = useState<{ nombre: string; tipo: TipoDocumento; categoria: string; url: string }>({ nombre: "", tipo: "pdf", categoria: "", url: "" });
  const carga = puedeCargarDocumentos();

  useEffect(() => { listarProyectos().then(setProyectos).catch(() => {}); }, []);
  useEffect(() => { if (!obra) { setDocs([]); return; } listarDocumentos(obra).then(setDocs).catch(() => {}); }, [obra]);

  async function guardar(e: React.FormEvent) {
    e.preventDefault(); if (!obra) return;
    try {
      await crearDocumento(obra, { nombre: form.nombre, tipo: form.tipo, categoria: form.categoria || "General", url: form.url, fecha_carga: hoy() });
      toast.success("Documento agregado"); setForm({ nombre: "", tipo: "pdf", categoria: "", url: "" });
      setDocs(await listarDocumentos(obra));
    } catch (err) { toast.error(err instanceof Error ? err.message : "Error"); }
  }

  return (
    <div className="space-y-6">
      <div>
        <h2 className="text-3xl font-bold text-foreground flex items-center gap-2"><FileText className="w-7 h-7" /> Documentación</h2>
        <p className="text-muted-foreground mt-2">Documentos por obra. Se guarda un enlace al archivo (Drive/URL); no se sube el binario.</p>
      </div>

      <Card><CardContent className="pt-6">
        <Label>Obra</Label>
        <Select value={obra} onValueChange={setObra}>
          <SelectTrigger className="mt-2 max-w-md"><SelectValue placeholder="Elegí una obra" /></SelectTrigger>
          <SelectContent>{proyectos.map((p) => <SelectItem key={p.id} value={String(p.id)}>{p.nombre}</SelectItem>)}</SelectContent>
        </Select>
      </CardContent></Card>

      {!obra ? (
        <Card><CardContent className="py-12 text-center text-muted-foreground">Seleccioná una obra para ver y cargar su documentación.</CardContent></Card>
      ) : (
        <Card>
          <CardHeader><CardTitle>Documentos de la obra</CardTitle><CardDescription>Planos, contratos, fotos (por enlace).</CardDescription></CardHeader>
          <CardContent className="space-y-4">
            {carga && (
              <form onSubmit={guardar} className="grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
                <div className="space-y-2 md:col-span-2"><Label>Nombre</Label><Input required value={form.nombre} onChange={(e) => setForm({ ...form, nombre: e.target.value })} placeholder="Ej: Plano de planta" /></div>
                <div className="space-y-2"><Label>Tipo</Label>
                  <Select value={form.tipo} onValueChange={(v) => setForm({ ...form, tipo: v as TipoDocumento })}><SelectTrigger><SelectValue /></SelectTrigger>
                    <SelectContent><SelectItem value="pdf">PDF</SelectItem><SelectItem value="imagen">Imagen</SelectItem><SelectItem value="otro">Otro</SelectItem></SelectContent></Select></div>
                <div className="space-y-2"><Label>Categoría</Label><Input value={form.categoria} onChange={(e) => setForm({ ...form, categoria: e.target.value })} placeholder="Plano / Contrato / Foto" /></div>
                <div className="space-y-2 md:col-span-3"><Label>Enlace (URL)</Label><Input type="url" required value={form.url} onChange={(e) => setForm({ ...form, url: e.target.value })} placeholder="https://drive.google.com/..." /></div>
                <Button type="submit" className="gap-2 md:self-end"><Plus className="w-4 h-4" /> Agregar</Button>
              </form>
            )}
            {docs.length === 0 ? <p className="text-muted-foreground text-sm">Sin documentos cargados.</p> : (
              <div className="space-y-2">{docs.map((d) => (
                <div key={d.id_documento} className="flex items-center gap-3 border rounded-md px-4 py-2 text-sm">
                  <span className="text-muted-foreground w-24">{fmtFecha(d.fecha_carga)}</span>
                  <span className="font-medium">{d.nombre}</span><Badge variant="secondary">{d.categoria}</Badge>
                  <EnlaceDocumento url={d.url} />
                  {carga && <Button size="sm" variant="ghost" onClick={() => eliminarDocumento(d.id_documento).then(() => setDocs((p) => p.filter((x) => x.id_documento !== d.id_documento))).catch(() => toast.error("Error"))}><Trash2 className="w-4 h-4" style={{ color: "#ef4444" }} /></Button>}
                </div>
              ))}</div>
            )}
          </CardContent>
        </Card>
      )}
    </div>
  );
}
