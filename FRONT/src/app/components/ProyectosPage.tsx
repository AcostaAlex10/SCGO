import { useState, useEffect } from "react";
import { useNavigate } from "react-router";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "./ui/card";
import { Button } from "./ui/button";
import { Input } from "./ui/input";
import { Label } from "./ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "./ui/select";
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "./ui/dialog";
import { Badge } from "./ui/badge";
import { Plus, Search, Edit, Trash2, MapPin, Calendar, User, ArrowRight } from "lucide-react";
import { toast } from "sonner";
import {
  listarProyectos, crearProyecto, actualizarProyecto, eliminarProyecto,
  type Proyecto, type ProyectoInput,
} from "../api/proyectos";
import { puedeGestionarObras, puedeVerCostos } from "../auth/permisos";
import { etiquetaEstado, sePuedeCancelar } from "../estadosObra";

const FORM_VACIO = { nombre: "", tipo: "", ubicacion: "", encargado: "", fechaInicio: "", presupuesto: "", estado: "" };

// Fecha de hoy (YYYY-MM-DD) en la zona horaria LOCAL del usuario (no UTC),
// para no permitir fechas de inicio en el pasado.
const HOY = (() => {
  const d = new Date();
  return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
})();
const fmtFecha = (s: string) => new Date(s + "T00:00:00").toLocaleDateString("es-AR");

// La etiqueta sale de estadosObra.ts; aca solo se elige el color por estado.
function estadoBadge(estado: string) {
  const etiqueta = etiquetaEstado(estado);
  switch (estado) {
    case "en_ejecucion": return <Badge className="bg-green-600">{etiqueta}</Badge>;
    case "pausada": return <Badge variant="destructive">{etiqueta}</Badge>;
    case "en_revision": return <Badge className="bg-amber-600">{etiqueta}</Badge>;
    case "finalizada":
    case "cancelada": return <Badge variant="outline">{etiqueta}</Badge>;
    default: return <Badge variant="secondary">{etiqueta}</Badge>;
  }
}

export default function ProyectosPage() {
  const navigate = useNavigate();
  const [proyectos, setProyectos] = useState<Proyecto[]>([]);
  const [loading, setLoading] = useState(true);
  const [dialogAbierto, setDialogAbierto] = useState(false);
  const [editandoId, setEditandoId] = useState<string | null>(null);
  const [busqueda, setBusqueda] = useState("");
  const [filtroEstado, setFiltroEstado] = useState("");
  const [form, setForm] = useState(FORM_VACIO);

  // Permisos del usuario logueado (RF19/RF20).
  const gestiona = puedeGestionarObras();
  const verCostos = puedeVerCostos();

  // Estado con el que se abrio la edicion, para saber si la obra admite
  // cancelarse sin que la opcion desaparezca al elegirla.
  const estadoOriginal = editandoId
    ? proyectos.find((p) => p.id === editandoId)?.estado ?? ""
    : "";
  const puedeCancelar = sePuedeCancelar(estadoOriginal);

  async function cargar() {
    setLoading(true);
    try {
      setProyectos(await listarProyectos());
    } catch {
      toast.error("No se pudo conectar con el servidor");
    } finally {
      setLoading(false);
    }
  }
  useEffect(() => { cargar(); }, []);

  const filtrados = proyectos.filter((p) =>
    (p.nombre.toLowerCase().includes(busqueda.toLowerCase()) ||
      p.ubicacion.toLowerCase().includes(busqueda.toLowerCase())) &&
    (filtroEstado === "" || p.estado === filtroEstado)  // RF18: filtrar por estado (ej. finalizadas)
  );
  const FILTROS_ESTADO = [
    { v: "", l: "Todas" },
    { v: "en_ejecucion", l: "En ejecución" },
    { v: "creada", l: "Creadas" },
    { v: "planificacion", l: "Planificación" },
    { v: "pausada", l: "Pausadas" },
    { v: "finalizada", l: "Finalizadas" },
    { v: "cancelada", l: "Canceladas" },
  ];

  function abrirNuevo() {
    setEditandoId(null);
    setForm(FORM_VACIO);
    setDialogAbierto(true);
  }
  function abrirEdicion(p: Proyecto) {
    setEditandoId(p.id);
    setForm({
      nombre: p.nombre, tipo: p.tipo, ubicacion: p.ubicacion,
      encargado: p.encargado, fechaInicio: p.fechaInicio, presupuesto: String(p.presupuesto),
      estado: p.estado,
    });
    setDialogAbierto(true);
  }

  async function guardar(e: React.FormEvent) {
    e.preventDefault();
    const datos: ProyectoInput = {
      nombre: form.nombre, tipo: form.tipo, ubicacion: form.ubicacion,
      encargado: form.encargado, fechaInicio: form.fechaInicio,
      presupuesto: parseFloat(form.presupuesto),
    };
    try {
      if (editandoId) {
        // El estado solo viaja al editar: en el alta lo fija el backend en
        // 'creada'. Cancelar es el unico valor que el formulario ofrece.
        const cancelada = form.estado === "cancelada";
        await actualizarProyecto(editandoId, { ...datos, estado: form.estado });
        toast.success(cancelada ? "Obra cancelada" : "Proyecto actualizado");
      } else {
        await crearProyecto(datos);
        toast.success("Proyecto registrado con éxito");
      }
      setDialogAbierto(false);
      await cargar();
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Error al guardar el proyecto");
    }
  }

  async function borrar(id: string) {
    if (!confirm("¿Eliminar este proyecto? Se borrará también su planificación y avances.")) return;
    try {
      await eliminarProyecto(id);
      toast.success("Proyecto eliminado correctamente");
      setProyectos((prev) => prev.filter((p) => p.id !== id));
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Error al eliminar");
    }
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-3xl font-bold text-foreground">Gestión de Proyectos</h2>
          <p className="text-muted-foreground mt-2">Registrar, modificar y organizar proyectos de obra</p>
        </div>
        {gestiona && (
          <Button className="gap-2" onClick={abrirNuevo}>
            <Plus className="w-4 h-4" /> Nuevo Proyecto
          </Button>
        )}
      </div>

      {/* Buscador */}
      <Card>
        <CardContent className="pt-6">
          <div className="flex items-center gap-2">
            <Search className="w-5 h-5 text-gray-400" />
            <Input
              placeholder="Buscar proyectos por nombre o ubicación..."
              value={busqueda}
              onChange={(e) => setBusqueda(e.target.value)}
              className="flex-1"
            />
          </div>
          {/* RF18: filtro por estado (incluye historial de finalizadas) */}
          <div className="flex flex-wrap gap-2 mt-4">
            {FILTROS_ESTADO.map((f) => (
              <Button key={f.v} size="sm" variant={filtroEstado === f.v ? "default" : "outline"} onClick={() => setFiltroEstado(f.v)}>
                {f.l}
              </Button>
            ))}
          </div>
        </CardContent>
      </Card>

      {loading && <div className="text-center text-muted-foreground py-12">Cargando proyectos...</div>}

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {filtrados.map((p) => (
          <Card key={p.id}>
            <CardHeader>
              <div className="flex items-start justify-between">
                <div className="flex-1">
                  <CardTitle>{p.nombre}</CardTitle>
                  <CardDescription className="mt-2">{p.tipo}</CardDescription>
                </div>
                {gestiona && (
                  <div className="flex gap-2">
                    <Button size="sm" variant="ghost" onClick={() => abrirEdicion(p)} title="Editar">
                      <Edit className="w-4 h-4" />
                    </Button>
                    <Button size="sm" variant="ghost" onClick={() => borrar(p.id)} title="Eliminar">
                      <Trash2 className="w-4 h-4" style={{ color: "#ef4444" }} />
                    </Button>
                  </div>
                )}
              </div>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="flex items-center gap-2 text-sm text-muted-foreground">
                <MapPin className="w-4 h-4" /> {p.ubicacion}
              </div>
              <div className="flex items-center gap-2 text-sm text-muted-foreground">
                <User className="w-4 h-4" /> {p.encargado}
              </div>
              <div className="flex items-center gap-2 text-sm text-muted-foreground">
                <Calendar className="w-4 h-4" /> Inicio: {fmtFecha(p.fechaInicio)}
              </div>
              <div className="space-y-2">
                <div className="flex items-center justify-between text-sm">
                  <span className="text-muted-foreground">Avance físico</span>
                  <span className="font-semibold">{p.avance}%</span>
                </div>
                <div className="w-full bg-gray-200 rounded-full h-2">
                  <div className="bg-blue-600 h-2 rounded-full transition-all" style={{ width: `${p.avance}%` }} />
                </div>
              </div>
              <div className="flex items-center justify-between pt-2 border-t">
                {verCostos && p.presupuesto != null ? (
                  <div className="text-sm">
                    <span className="text-muted-foreground">Presupuesto: </span>
                    <span className="font-semibold">${p.presupuesto.toLocaleString("es-AR")}</span>
                  </div>
                ) : (
                  <span />
                )}
                {estadoBadge(p.estado)}
              </div>
              <Button variant="outline" className="w-full gap-2" onClick={() => navigate(`/proyectos/${p.id}`)}>
                Gestionar obra <ArrowRight className="w-4 h-4" />
              </Button>
            </CardContent>
          </Card>
        ))}
      </div>

      {!loading && filtrados.length === 0 && (
        <Card><CardContent className="py-12">
          <div className="text-center text-muted-foreground"><p>No se encontraron proyectos</p></div>
        </CardContent></Card>
      )}

      {/* Dialog crear / editar */}
      <Dialog open={dialogAbierto} onOpenChange={setDialogAbierto}>
        <DialogContent className="max-w-2xl">
          <DialogHeader>
            <DialogTitle>{editandoId ? "Modificar Obra" : "Registrar Nueva Obra"}</DialogTitle>
            <DialogDescription>Complete la información del proyecto. Todos los campos son obligatorios.</DialogDescription>
          </DialogHeader>
          <form onSubmit={guardar} className="space-y-4">
            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2 col-span-2">
                <Label htmlFor="nombre">Nombre del Proyecto *</Label>
                <Input id="nombre" value={form.nombre} onChange={(e) => setForm({ ...form, nombre: e.target.value })} placeholder="Ej: Obra Vial Ruta 14" required />
              </div>
              <div className="space-y-2">
                <Label htmlFor="tipo">Tipo de Obra *</Label>
                <Select value={form.tipo} onValueChange={(v) => setForm({ ...form, tipo: v })} required>
                  <SelectTrigger><SelectValue placeholder="Seleccionar tipo" /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value="Infraestructura Vial">Infraestructura Vial</SelectItem>
                    <SelectItem value="Construcción Edilicia">Construcción Edilicia</SelectItem>
                    <SelectItem value="Obra Hidráulica">Obra Hidráulica</SelectItem>
                    <SelectItem value="Mantenimiento">Mantenimiento</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-2">
                <Label htmlFor="encargado">Encargado *</Label>
                <Input id="encargado" value={form.encargado} onChange={(e) => setForm({ ...form, encargado: e.target.value })} placeholder="Ej: Ing. Roberto Suenaga" required />
              </div>
              <div className="space-y-2">
                <Label htmlFor="ubicacion">Ubicación *</Label>
                <Input id="ubicacion" value={form.ubicacion} onChange={(e) => setForm({ ...form, ubicacion: e.target.value })} placeholder="Ciudad, Provincia" required />
              </div>
              <div className="space-y-2">
                <Label htmlFor="fecha">Fecha de Inicio *</Label>
                {/* El piso de hoy es solo para el alta, igual que en
                    ProyectoController::registrar(). Al editar una obra que ya
                    empezó su fecha es pasada, y con el min puesto el navegador
                    daba el formulario por inválido: no se podía guardar ningún
                    cambio sobre una obra en marcha. */}
                <Input id="fecha" type="date" min={editandoId ? undefined : HOY} value={form.fechaInicio} onChange={(e) => setForm({ ...form, fechaInicio: e.target.value })} required />
              </div>
              <div className="space-y-2 col-span-2">
                <Label htmlFor="presupuesto">Presupuesto Estimado (ARS) *</Label>
                <Input id="presupuesto" type="number" value={form.presupuesto} onChange={(e) => setForm({ ...form, presupuesto: e.target.value })} placeholder="0.00" min="0" step="0.01" required />
              </div>
              {/* Estado: solo al editar, y solo para cancelar. El estado original
                  sale del listado, asi las opciones no cambian si el usuario
                  elige "Cancelada" y despues se arrepiente. */}
              {editandoId && (
                <div className="space-y-2 col-span-2">
                  <Label htmlFor="estado">Estado</Label>
                  <Select
                    value={form.estado}
                    onValueChange={(v) => setForm({ ...form, estado: v })}
                    disabled={!puedeCancelar}
                  >
                    <SelectTrigger id="estado"><SelectValue /></SelectTrigger>
                    <SelectContent>
                      <SelectItem value={estadoOriginal}>{etiquetaEstado(estadoOriginal)}</SelectItem>
                      {puedeCancelar && <SelectItem value="cancelada">Cancelada</SelectItem>}
                    </SelectContent>
                  </Select>
                  <p className="text-xs text-muted-foreground">
                    {puedeCancelar
                      ? "Cancelar da la obra por terminada sin completarla. No se puede deshacer desde acá."
                      : "Los demás estados los mueve el sistema con los avances y las paradas de obra."}
                  </p>
                </div>
              )}
            </div>
            <div className="flex justify-end gap-2 pt-4">
              <Button type="button" variant="outline" onClick={() => setDialogAbierto(false)}>Cancelar</Button>
              <Button type="submit">{editandoId ? "Guardar cambios" : "Registrar Proyecto"}</Button>
            </div>
          </form>
        </DialogContent>
      </Dialog>
    </div>
  );
}
