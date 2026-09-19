import { useEffect, useState } from "react";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "./ui/card";
import { Button } from "./ui/button";
import { Input } from "./ui/input";
import { Label } from "./ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "./ui/select";
import { Badge } from "./ui/badge";
import { Users, UserPlus, ShieldCheck, Ban, RotateCcw } from "lucide-react";
import { toast } from "sonner";
import {
  listarUsuarios,
  crearUsuario,
  actualizarUsuario,
  type Usuario,
  type RolUsuario,
} from "../api/proyectos";
import { getUsuario } from "../auth/session";

// HU16 (completa RF19): alta de cuentas, listado y asignación de roles.
// La ruta ya está protegida por el sidebar y, sobre todo, por el backend:
// /api/usuarios exige rol AdministradorSistema.

const ROLES: { valor: RolUsuario; etiqueta: string; detalle: string }[] = [
  { valor: "AdministradorSistema", etiqueta: "Administrador", detalle: "Gestiona cuentas y roles" },
  { valor: "PersonalAdministrativo", etiqueta: "Administrativo", detalle: "Registra obras y valida reportes" },
  { valor: "PersonalTecnico", etiqueta: "Técnico", detalle: "Carga avances en obra (sin ver costos)" },
  { valor: "Gerente", etiqueta: "Gerente", detalle: "Consulta análisis y reportes" },
];

const COLOR_ROL: Record<RolUsuario, string> = {
  AdministradorSistema: "#e8981e",
  PersonalAdministrativo: "#3b82f6",
  PersonalTecnico: "#22c55e",
  Gerente: "#a855f7",
};

const FORM_VACIO = { nombre: "", email: "", contrasena: "", rol: "" as RolUsuario | "" };

export default function UsuariosPage() {
  const [usuarios, setUsuarios] = useState<Usuario[]>([]);
  const [loading, setLoading] = useState(true);
  const [form, setForm] = useState(FORM_VACIO);
  const [guardando, setGuardando] = useState(false);
  const yo = getUsuario();

  const cargar = () =>
    listarUsuarios()
      .then(setUsuarios)
      .catch((e) => toast.error(e instanceof Error ? e.message : "No se pudieron cargar los usuarios"))
      .finally(() => setLoading(false));

  useEffect(() => {
    cargar();
  }, []);

  async function crear(e: React.FormEvent) {
    e.preventDefault();
    if (!form.rol) return toast.error("Elegí un rol");
    setGuardando(true);
    try {
      await crearUsuario({ ...form, rol: form.rol });
      toast.success(`Usuario ${form.nombre} creado`);
      setForm(FORM_VACIO);
      await cargar();
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "No se pudo crear el usuario");
    } finally {
      setGuardando(false);
    }
  }

  async function cambiarRol(u: Usuario, rol: RolUsuario) {
    if (rol === u.rol) return;
    try {
      await actualizarUsuario(u.id_usuario, { rol });
      toast.success(`${u.nombre} ahora es ${ROLES.find((r) => r.valor === rol)?.etiqueta}`);
      await cargar();
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "No se pudo cambiar el rol");
    }
  }

  async function alternarActivo(u: Usuario) {
    try {
      await actualizarUsuario(u.id_usuario, { activo: !u.activo });
      toast.success(u.activo ? `${u.nombre} dado de baja` : `${u.nombre} reactivado`);
      await cargar();
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "No se pudo actualizar el usuario");
    }
  }

  return (
    <div className="space-y-6">
      <div>
        <h2 className="text-3xl font-bold text-foreground flex items-center gap-2">
          <Users className="w-7 h-7" /> Gestión de Usuarios
        </h2>
        <p className="text-muted-foreground mt-2">
          Alta de cuentas, asignación de roles y baja lógica.
        </p>
      </div>

      {/* Alta de usuario */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <UserPlus className="w-5 h-5" /> Nuevo usuario
          </CardTitle>
          <CardDescription>La contraseña se guarda cifrada; el usuario puede cambiarla después.</CardDescription>
        </CardHeader>
        <CardContent>
          <form onSubmit={crear} className="grid gap-4 md:grid-cols-5 items-end">
            <div className="space-y-2 md:col-span-1">
              <Label htmlFor="nombre">Nombre *</Label>
              <Input
                id="nombre"
                value={form.nombre}
                onChange={(e) => setForm({ ...form, nombre: e.target.value })}
                placeholder="Ej: Ana Pérez"
                required
              />
            </div>
            <div className="space-y-2 md:col-span-1">
              <Label htmlFor="email">Email *</Label>
              <Input
                id="email"
                type="email"
                value={form.email}
                onChange={(e) => setForm({ ...form, email: e.target.value })}
                placeholder="ana@sgso.com"
                required
              />
            </div>
            <div className="space-y-2 md:col-span-1">
              <Label htmlFor="contrasena">Contraseña *</Label>
              <Input
                id="contrasena"
                type="password"
                value={form.contrasena}
                onChange={(e) => setForm({ ...form, contrasena: e.target.value })}
                placeholder="Mínimo 10 caracteres"
                minLength={10}
                required
              />
            </div>
            <div className="space-y-2 md:col-span-1">
              <Label htmlFor="rol">Rol *</Label>
              <Select value={form.rol} onValueChange={(v) => setForm({ ...form, rol: v as RolUsuario })}>
                <SelectTrigger id="rol">
                  <SelectValue placeholder="Seleccionar rol" />
                </SelectTrigger>
                <SelectContent>
                  {ROLES.map((r) => (
                    <SelectItem key={r.valor} value={r.valor}>
                      {r.etiqueta}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <Button type="submit" disabled={guardando} className="md:col-span-1">
              {guardando ? "Creando..." : "Crear usuario"}
            </Button>
          </form>
        </CardContent>
      </Card>

      {/* Listado */}
      <Card>
        <CardHeader>
          <CardTitle>Usuarios del sistema ({usuarios.length})</CardTitle>
          <CardDescription>
            Cambiar un rol o dar de baja cierra la sesión activa de esa persona.
          </CardDescription>
        </CardHeader>
        <CardContent>
          {loading ? (
            <p className="text-muted-foreground text-sm py-4">Cargando usuarios...</p>
          ) : usuarios.length === 0 ? (
            <p className="text-muted-foreground text-sm py-4">No hay usuarios cargados.</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="text-left text-muted-foreground border-b">
                    <th className="py-2 pr-4 font-medium">Nombre</th>
                    <th className="py-2 pr-4 font-medium">Email</th>
                    <th className="py-2 pr-4 font-medium">Rol</th>
                    <th className="py-2 pr-4 font-medium">Estado</th>
                    <th className="py-2 font-medium text-right">Acciones</th>
                  </tr>
                </thead>
                <tbody>
                  {usuarios.map((u) => {
                    const esYo = yo?.id_usuario === u.id_usuario;
                    return (
                      <tr key={u.id_usuario} className="border-b last:border-0">
                        <td className="py-3 pr-4 font-medium">
                          {u.nombre}
                          {esYo && <span className="text-muted-foreground font-normal"> (vos)</span>}
                        </td>
                        <td className="py-3 pr-4 text-muted-foreground">{u.email}</td>
                        <td className="py-3 pr-4">
                          <div className="flex items-center gap-2">
                            <Badge style={{ background: COLOR_ROL[u.rol] }}>
                              {ROLES.find((r) => r.valor === u.rol)?.etiqueta ?? u.rol}
                            </Badge>
                          </div>
                        </td>
                        <td className="py-3 pr-4">
                          {u.activo ? (
                            <span className="flex items-center gap-1" style={{ color: "#22c55e" }}>
                              <ShieldCheck className="w-4 h-4" /> Activo
                            </span>
                          ) : (
                            <span className="flex items-center gap-1 text-muted-foreground">
                              <Ban className="w-4 h-4" /> Dado de baja
                            </span>
                          )}
                        </td>
                        <td className="py-3">
                          <div className="flex items-center gap-2 justify-end">
                            <Select value={u.rol} onValueChange={(v) => cambiarRol(u, v as RolUsuario)}>
                              <SelectTrigger className="w-[170px]" disabled={esYo}>
                                <SelectValue />
                              </SelectTrigger>
                              <SelectContent>
                                {ROLES.map((r) => (
                                  <SelectItem key={r.valor} value={r.valor}>
                                    {r.etiqueta}
                                  </SelectItem>
                                ))}
                              </SelectContent>
                            </Select>
                            <Button
                              size="sm"
                              variant={u.activo ? "ghost" : "secondary"}
                              onClick={() => alternarActivo(u)}
                              disabled={esYo}
                              title={esYo ? "No podés darte de baja a vos mismo" : u.activo ? "Dar de baja" : "Reactivar"}
                            >
                              {u.activo ? <Ban className="w-4 h-4" /> : <RotateCcw className="w-4 h-4" />}
                            </Button>
                          </div>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          )}
        </CardContent>
      </Card>

      {/* Referencia de permisos por rol */}
      <Card>
        <CardHeader>
          <CardTitle>Qué puede hacer cada rol</CardTitle>
        </CardHeader>
        <CardContent className="grid gap-3 md:grid-cols-2">
          {ROLES.map((r) => (
            <div key={r.valor} className="flex items-start gap-3 border rounded-md px-4 py-3">
              <Badge style={{ background: COLOR_ROL[r.valor] }}>{r.etiqueta}</Badge>
              <span className="text-sm text-muted-foreground">{r.detalle}</span>
            </div>
          ))}
        </CardContent>
      </Card>
    </div>
  );
}
