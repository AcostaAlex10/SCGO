import { Outlet, Link, useLocation, useNavigate } from "react-router";
import {
  LayoutDashboard,
  FolderKanban,
  Activity,
  Package,
  FileText,
  BarChart3,
  Bell,
  Wrench,
  Users,
  HardHat,
  ChevronRight,
  Clock,
  LogOut,
} from "lucide-react";
import { Toaster } from "./ui/sonner";
import { toast } from "sonner";
import { useState, useEffect } from "react";
import { getUsuario, clearSession } from "../auth/session";
import { puedeGestionarUsuarios } from "../auth/permisos";
import { apiFetch } from "../auth/api";
import { obtenerAnalisis } from "../api/proyectos";

const navItems = [
  { path: "/", label: "Dashboard", icon: LayoutDashboard, abbr: "DSH" },
  { path: "/proyectos", label: "Proyectos", icon: FolderKanban, abbr: "PRY" },
  { path: "/seguimiento", label: "Seguimiento", icon: Activity, abbr: "SEG" },
  { path: "/materiales", label: "Materiales", icon: Package, abbr: "MAT" },
  { path: "/documentacion", label: "Documentación", icon: FileText, abbr: "DOC" },
  { path: "/reportes", label: "Reportes", icon: BarChart3, abbr: "REP" },
  { path: "/alertas", label: "Alertas", icon: Bell, abbr: "ALE", alert: true },
  { path: "/maquinaria", label: "Maquinaria", icon: Wrench, abbr: "MAQ" },
  // HU16: solo lo ve el AdministradorSistema (se filtra al renderizar).
  { path: "/usuarios", label: "Usuarios", icon: Users, abbr: "USR", soloAdmin: true },
];

function LiveClock() {
  const [time, setTime] = useState(new Date());
  useEffect(() => {
    const t = setInterval(() => setTime(new Date()), 1000);
    return () => clearInterval(t);
  }, []);
  return (
    <span className="mono" style={{ fontFamily: "'JetBrains Mono', monospace", fontSize: "11px", letterSpacing: "0.05em" }}>
      {time.toLocaleTimeString("es-AR", { hour: "2-digit", minute: "2-digit", second: "2-digit" })}
    </span>
  );
}

// Iniciales para el avatar (ej. "Admin Sistema" -> "AS").
function iniciales(nombre: string): string {
  return nombre
    .split(" ")
    .map((p) => p[0])
    .filter(Boolean)
    .slice(0, 2)
    .join("")
    .toUpperCase();
}

export default function Root() {
  const location = useLocation();
  const navigate = useNavigate();
  const [mobileOpen, setMobileOpen] = useState(false);
  const [alertCount, setAlertCount] = useState(0);

  // Usuario de la sesion actual (guardado al iniciar sesion).
  const usuario = getUsuario();

  // Cantidad real de alertas activas para el badge del menu (RF11/RF12).
  useEffect(() => {
    let vivo = true;
    function refrescar() {
      obtenerAnalisis().then((a) => { if (vivo) setAlertCount(a.alertas.length); }).catch(() => {});
    }
    refrescar();
    const t = setInterval(refrescar, 60000);
    return () => { vivo = false; clearInterval(t); };
  }, [location.pathname]);

  // Sesion unica: chequeamos contra el backend si nuestra sesion sigue activa.
  // Si alguien inicio sesion con la misma cuenta en otro lado, /auth/me devuelve
  // 401 y cerramos sesion aca. Se revisa al entrar y cada 45 segundos.
  useEffect(() => {
    let cancelado = false;
    async function verificarSesion() {
      try {
        const res = await apiFetch("/auth/me");
        if (!cancelado && res.status === 401) {
          toast.error("Tu sesión se cerró: se inició sesión en otro dispositivo.");
          clearSession();
          navigate("/login", { replace: true });
        }
      } catch {
        // error de red: no hacemos nada (no cerramos por una caida temporal)
      }
    }
    verificarSesion();
    const t = setInterval(verificarSesion, 45000);
    return () => { cancelado = true; clearInterval(t); };
  }, [navigate]);

  function handleLogout() {
    clearSession();
    navigate("/login", { replace: true });
  }

  const isActive = (path: string) => {
    if (path === "/") return location.pathname === path;
    return location.pathname.startsWith(path);
  };

  const currentPage = navItems.find(n => isActive(n.path))?.label ?? "Dashboard";

  return (
    <div className="flex h-screen overflow-hidden" style={{ background: "var(--background)" }}>
      <Toaster position="top-right" theme="dark" />

      {/* Sidebar */}
      <aside
        className={`
          fixed inset-y-0 left-0 z-50 flex flex-col transition-transform duration-200
          lg:relative lg:translate-x-0
          ${mobileOpen ? "translate-x-0" : "-translate-x-full"}
        `}
        style={{
          width: "220px",
          background: "var(--sidebar)",
          borderRight: "1px solid var(--sidebar-border)",
          flexShrink: 0,
        }}
      >
        {/* Logo */}
        <div className="flex items-center gap-3 px-5 py-5" style={{ borderBottom: "1px solid var(--sidebar-border)" }}>
          <div
            className="flex items-center justify-center"
            style={{
              width: "34px",
              height: "34px",
              background: "var(--primary)",
              borderRadius: "4px",
              flexShrink: 0,
            }}
          >
            <HardHat style={{ width: "18px", height: "18px", color: "var(--primary-foreground)" }} />
          </div>
          <div style={{ lineHeight: 1.2 }}>
            <div style={{ fontSize: "12px", fontWeight: 700, color: "var(--foreground)", letterSpacing: "0.02em" }}>
              OBRAS
            </div>
            <div style={{ fontSize: "10px", color: "var(--muted-foreground)", letterSpacing: "0.08em", textTransform: "uppercase" }}>
              Sistema Operativo
            </div>
          </div>
        </div>

        {/* Status bar */}
        <div
          className="flex items-center justify-between px-5 py-2"
          style={{ background: "rgba(232,152,30,0.07)", borderBottom: "1px solid var(--sidebar-border)" }}
        >
          <div className="flex items-center gap-1.5">
            <div style={{ width: "6px", height: "6px", borderRadius: "50%", background: "#22c55e", boxShadow: "0 0 6px #22c55e" }} />
            <span style={{ fontSize: "10px", color: "var(--muted-foreground)", letterSpacing: "0.06em", textTransform: "uppercase" }}>En línea</span>
          </div>
          <div className="flex items-center gap-1" style={{ color: "var(--muted-foreground)" }}>
            <Clock style={{ width: "10px", height: "10px" }} />
            <LiveClock />
          </div>
        </div>

        {/* Nav */}
        <nav className="flex-1 overflow-y-auto py-3" style={{ padding: "12px 8px" }}>
          <div style={{ fontSize: "10px", color: "var(--muted-foreground)", letterSpacing: "0.1em", textTransform: "uppercase", padding: "4px 12px 8px" }}>
            Módulos
          </div>
          {navItems.filter((item) => !item.soloAdmin || puedeGestionarUsuarios()).map((item) => {
            const Icon = item.icon;
            const active = isActive(item.path);
            return (
              <Link
                key={item.path}
                to={item.path}
                onClick={() => setMobileOpen(false)}
                className="flex items-center gap-3 rounded-sm transition-all duration-100 group"
                style={{
                  padding: "8px 12px",
                  marginBottom: "2px",
                  background: active ? "rgba(232,152,30,0.12)" : "transparent",
                  borderLeft: active ? "2px solid var(--primary)" : "2px solid transparent",
                  color: active ? "var(--primary)" : "var(--sidebar-foreground)",
                }}
              >
                <Icon style={{ width: "15px", height: "15px", flexShrink: 0 }} />
                <span
                  style={{
                    fontSize: "13px",
                    fontWeight: active ? 600 : 400,
                    flex: 1,
                    color: active ? "var(--foreground)" : "var(--sidebar-foreground)",
                  }}
                >
                  {item.label}
                </span>
                {item.alert && alertCount > 0 && (
                  <span
                    style={{
                      fontSize: "10px",
                      fontWeight: 700,
                      background: "#c0392b",
                      color: "white",
                      borderRadius: "3px",
                      padding: "1px 5px",
                      fontFamily: "'JetBrains Mono', monospace",
                    }}
                  >
                    {alertCount}
                  </span>
                )}
                {active && <ChevronRight style={{ width: "12px", height: "12px", color: "var(--primary)", flexShrink: 0 }} />}
              </Link>
            );
          })}
        </nav>

        {/* User info */}
        <div
          style={{
            padding: "12px 16px",
            borderTop: "1px solid var(--sidebar-border)",
          }}
        >
          <div className="flex items-center gap-3">
            <div
              className="flex items-center justify-center"
              style={{
                width: "30px",
                height: "30px",
                background: "var(--secondary)",
                borderRadius: "50%",
                border: "1px solid var(--border)",
                flexShrink: 0,
                fontSize: "11px",
                fontWeight: 700,
                color: "var(--primary)",
                fontFamily: "'JetBrains Mono', monospace",
              }}
            >
              {usuario ? iniciales(usuario.nombre) : "??"}
            </div>
            <div style={{ lineHeight: 1.3, overflow: "hidden" }}>
              <div style={{ fontSize: "12px", fontWeight: 600, color: "var(--foreground)", whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis" }}>
                {usuario?.nombre ?? "Sin sesion"}
              </div>
              <div style={{ fontSize: "10px", color: "var(--muted-foreground)", letterSpacing: "0.04em" }}>
                {usuario?.rol ?? ""}
              </div>
            </div>
            <button
              onClick={handleLogout}
              title="Cerrar sesion"
              className="ml-auto flex items-center"
              style={{ color: "var(--muted-foreground)", background: "transparent", border: "none", cursor: "pointer", padding: "4px" }}
            >
              <LogOut style={{ width: "14px", height: "14px" }} />
            </button>
          </div>
        </div>

        {/* Footer Pro Badge */}
        <div
          style={{
            padding: "8px 16px",
            borderTop: "1px solid var(--sidebar-border)",
            background: "rgba(232,152,30,0.05)",
          }}
        >
          <div style={{ fontSize: "9px", color: "var(--muted-foreground)", textAlign: "center", letterSpacing: "0.06em" }}>
            SISTEMA PROFESIONAL V1.0
          </div>
          <div style={{ fontSize: "8px", color: "var(--muted-foreground)", textAlign: "center", letterSpacing: "0.04em", marginTop: "2px", opacity: 0.7 }}>
            Senior Engineering · Est. 1986
          </div>
        </div>
      </aside>

      {/* Mobile overlay */}
      {mobileOpen && (
        <div
          className="fixed inset-0 z-40 lg:hidden"
          style={{ background: "rgba(0,0,0,0.7)" }}
          onClick={() => setMobileOpen(false)}
        />
      )}

      {/* Main area */}
      <div className="flex flex-col flex-1 overflow-hidden">
        {/* Top bar */}
        <header
          className="flex items-center justify-between flex-shrink-0"
          style={{
            height: "48px",
            padding: "0 24px",
            background: "var(--card)",
            borderBottom: "1px solid var(--border)",
          }}
        >
          <div className="flex items-center gap-3">
            {/* Mobile menu */}
            <button
              className="lg:hidden"
              style={{ color: "var(--muted-foreground)", padding: "4px" }}
              onClick={() => setMobileOpen(true)}
            >
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <line x1="3" y1="6" x2="21" y2="6" />
                <line x1="3" y1="12" x2="21" y2="12" />
                <line x1="3" y1="18" x2="21" y2="18" />
              </svg>
            </button>
            {/* Breadcrumb */}
            <div className="flex items-center gap-2" style={{ fontSize: "12px", color: "var(--muted-foreground)" }}>
              <span style={{ letterSpacing: "0.03em" }}>SCGO</span>
              <span style={{ color: "var(--border)" }}>/</span>
              <span style={{ color: "var(--foreground)", fontWeight: 600 }}>{currentPage}</span>
            </div>
          </div>

          <div className="flex items-center gap-4">
            <div
              className="flex items-center gap-2"
              style={{
                fontSize: "11px",
                color: "var(--muted-foreground)",
                fontFamily: "'JetBrains Mono', monospace",
                background: "var(--secondary)",
                padding: "3px 10px",
                borderRadius: "3px",
                border: "1px solid var(--border)",
              }}
            >
              <span style={{ color: "#22c55e" }}>●</span>
              <span>SCGO</span>
            </div>
            <div style={{ fontSize: "11px", color: "var(--muted-foreground)" }}>
              {new Date().toLocaleDateString("es-AR", { weekday: "short", day: "numeric", month: "short", year: "numeric" })}
            </div>
          </div>
        </header>

        {/* Content */}
        <main
          className="flex-1 overflow-y-auto"
          style={{ padding: "24px", background: "var(--background)" }}
        >
          <Outlet />
        </main>
      </div>
    </div>
  );
}
