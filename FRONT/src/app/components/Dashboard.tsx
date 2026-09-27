import { useEffect, useState } from "react";
import {
  FolderKanban, Activity, Wallet, TrendingUp, Package, FileText, Wrench, AlertTriangle,
} from "lucide-react";
import { Link } from "react-router";
import { DistribucionProyectosChart, PresupuestoChart } from "./ChartsPanel";
import { listarProyectos, type Proyecto } from "../api/proyectos";
import { puedeVerCostos } from "../auth/permisos";
import { ESTADOS_OBRA, etiquetaEstado, colorEstado } from "../estadosObra";

const modules = [
  { title: "Proyectos", icon: FolderKanban, link: "/proyectos", accent: "#3b82f6" },
  { title: "Seguimiento", icon: Activity, link: "/seguimiento", accent: "#22c55e" },
  { title: "Materiales", icon: Package, link: "/materiales", accent: "#f97316" },
  { title: "Documentación", icon: FileText, link: "/documentacion", accent: "#6366f1" },
  { title: "Reportes", icon: FileText, link: "/reportes", accent: "#a855f7" },
  { title: "Alertas", icon: AlertTriangle, link: "/alertas", accent: "#ef4444" },
  { title: "Maquinaria", icon: Wrench, link: "/maquinaria", accent: "#64748b" },
];

// Etiqueta y color por estado de la obra.
// La distribucion se arma iterando ESTADOS_OBRA, asi que un estado que falte
// ahi no aparece en el grafico (los de valor 0 se filtran despues).
const ESTADOS = ESTADOS_OBRA;
function estadoInfo(estado: string) {
  return { label: etiquetaEstado(estado), color: colorEstado(estado) };
}
function pesos(n: number) {
  return "$" + n.toLocaleString("es-AR", { maximumFractionDigits: 0 });
}

function StatCard({ label, value, sub, icon: Icon, accent }: {
  label: string; value: string; sub: string; icon: typeof FolderKanban; accent: string;
}) {
  return (
    <div style={{ background: "var(--card)", border: "1px solid var(--border)", borderRadius: "var(--radius)", padding: "18px 20px", position: "relative", overflow: "hidden" }}>
      <div style={{ position: "absolute", top: 0, left: 0, width: "3px", height: "100%", background: accent, opacity: 0.7 }} />
      <div className="flex items-start justify-between">
        <div>
          <div style={{ fontSize: "10px", fontWeight: 600, color: "var(--muted-foreground)", letterSpacing: "0.08em", textTransform: "uppercase", marginBottom: "8px" }}>{label}</div>
          <div style={{ fontSize: "28px", fontWeight: 700, color: "var(--foreground)", lineHeight: 1, fontFamily: "'JetBrains Mono', monospace", marginBottom: "8px" }}>{value}</div>
          <div style={{ fontSize: "11px", color: "var(--muted-foreground)" }}>{sub}</div>
        </div>
        <div style={{ width: "36px", height: "36px", background: `${accent}18`, border: `1px solid ${accent}30`, borderRadius: "4px", display: "flex", alignItems: "center", justifyContent: "center" }}>
          <Icon style={{ width: "16px", height: "16px", color: accent }} />
        </div>
      </div>
    </div>
  );
}

function ProgressBar({ value, color }: { value: number; color: string }) {
  return (
    <div style={{ display: "flex", alignItems: "center", gap: "10px" }}>
      <div style={{ flex: 1, height: "4px", background: "var(--secondary)", borderRadius: "2px", overflow: "hidden" }}>
        <div style={{ width: `${value}%`, height: "100%", background: color, borderRadius: "2px", transition: "width 0.6s ease" }} />
      </div>
      <span style={{ fontSize: "11px", fontWeight: 600, color: "var(--muted-foreground)", fontFamily: "'JetBrains Mono', monospace", width: "34px", textAlign: "right" }}>{value}%</span>
    </div>
  );
}

export default function Dashboard() {
  const [proyectos, setProyectos] = useState<Proyecto[]>([]);
  const [loading, setLoading] = useState(true);

  // RF20: el Personal Técnico no ve costos/presupuestos.
  const verCostos = puedeVerCostos();

  useEffect(() => {
    listarProyectos().then(setProyectos).catch(() => {}).finally(() => setLoading(false));
  }, []);

  // --- KPIs reales ---
  const total = proyectos.length;
  const enEjecucion = proyectos.filter((p) => p.estado === "en_ejecucion").length;
  const avancePromedio = total > 0 ? Math.round(proyectos.reduce((a, p) => a + (p.avance || 0), 0) / total) : 0;
  const presupuestoTotal = proyectos.reduce((a, p) => a + (p.presupuesto || 0), 0);

  // --- Datos para los graficos ---
  const distribucion = Object.entries(ESTADOS)
    .map(([estado, info]) => ({ name: info.label, value: proyectos.filter((p) => p.estado === estado).length, color: info.color }))
    .filter((d) => d.value > 0);

  const presupuestoChart = proyectos.slice(0, 6).map((p) => ({
    nombre: p.nombre.length > 12 ? p.nombre.slice(0, 12) + "…" : p.nombre,
    presupuestado: +(p.presupuesto / 1_000_000).toFixed(1),
    // El monto certificado lo calcula la API (D-09).
    ejecutado: +((p.certificado ?? 0) / 1_000_000).toFixed(1),
  }));

  return (
    <div style={{ display: "flex", flexDirection: "column", gap: "20px" }}>
      {/* Header */}
      <div className="flex items-start justify-between">
        <div>
          <h2 style={{ fontSize: "20px", fontWeight: 700, color: "var(--foreground)", letterSpacing: "-0.02em", marginBottom: "4px" }}>Panel de Control</h2>
          <p style={{ fontSize: "12px", color: "var(--muted-foreground)" }}>Sistema de Gestión y Seguimiento Operativo de Obras</p>
        </div>
        <div style={{ fontSize: "11px", color: "var(--muted-foreground)", background: "var(--secondary)", border: "1px solid var(--border)", borderRadius: "3px", padding: "4px 10px", fontFamily: "'JetBrains Mono', monospace" }}>
          {new Date().toLocaleDateString("es-AR", { day: "2-digit", month: "2-digit", year: "numeric" })}
        </div>
      </div>

      {/* KPIs reales */}
      <div className={`grid grid-cols-2 ${verCostos ? "lg:grid-cols-4" : "lg:grid-cols-3"} gap-4`}>
        <StatCard label="Proyectos" value={String(total)} sub="obras registradas" icon={FolderKanban} accent="#3b82f6" />
        <StatCard label="En Ejecución" value={String(enEjecucion)} sub="obras activas" icon={Activity} accent="#22c55e" />
        <StatCard label="Avance Promedio" value={`${avancePromedio}%`} sub="de las obras" icon={TrendingUp} accent="#e8981e" />
        {verCostos && (
          <StatCard label="Presupuesto Total" value={pesos(presupuestoTotal)} sub="suma de obras" icon={Wallet} accent="#a855f7" />
        )}
      </div>

      {loading && <div style={{ textAlign: "center", color: "var(--muted-foreground)", padding: "12px" }}>Cargando datos...</div>}

      {/* Graficos con datos reales */}
      {total > 0 && (
        <div className={`grid grid-cols-1 ${verCostos ? "lg:grid-cols-2" : ""} gap-4`}>
          <DistribucionProyectosChart data={distribucion} descripcion={`Estado actual — ${total} obra${total === 1 ? "" : "s"} registrada${total === 1 ? "" : "s"}`} />
          {verCostos && <PresupuestoChart data={presupuestoChart} />}
        </div>
      )}

      {/* Tabla de obras (real) */}
      <div style={{ background: "var(--card)", border: "1px solid var(--border)", borderRadius: "var(--radius)", overflow: "hidden" }}>
        <div className="flex items-center justify-between" style={{ padding: "14px 18px", borderBottom: "1px solid var(--border)" }}>
          <span style={{ fontSize: "13px", fontWeight: 600, color: "var(--foreground)" }}>Estado de Obras</span>
          <Link to="/proyectos" style={{ fontSize: "11px", color: "var(--primary)", fontWeight: 600 }}>Ver todos →</Link>
        </div>
        <div className="grid" style={{ gridTemplateColumns: verCostos ? "1fr 140px 130px 120px" : "1fr 160px 160px", padding: "8px 18px", borderBottom: "1px solid var(--border)", background: "var(--secondary)" }}>
          {(verCostos ? ["Proyecto", "Estado", "Avance", "Presupuesto"] : ["Proyecto", "Estado", "Avance"]).map((h, i) => (
            <span key={i} style={{ fontSize: "10px", fontWeight: 600, color: "var(--muted-foreground)", letterSpacing: "0.07em", textTransform: "uppercase" }}>{h}</span>
          ))}
        </div>
        {proyectos.length === 0 && !loading && (
          <div style={{ padding: "24px 18px", textAlign: "center", fontSize: "12px", color: "var(--muted-foreground)" }}>
            No hay obras cargadas todavía. <Link to="/proyectos" style={{ color: "var(--primary)" }}>Crear la primera →</Link>
          </div>
        )}
        {proyectos.map((p, i) => {
          const info = estadoInfo(p.estado);
          return (
            <Link key={p.id} to={`/proyectos/${p.id}`} className="grid" style={{ gridTemplateColumns: verCostos ? "1fr 140px 130px 120px" : "1fr 160px 160px", padding: "11px 18px", borderBottom: i < proyectos.length - 1 ? "1px solid var(--border)" : "none", alignItems: "center", textDecoration: "none" }}>
              <span style={{ fontSize: "12px", fontWeight: 500, color: "var(--foreground)" }}>{p.nombre}</span>
              <div className="flex items-center gap-1.5">
                <div style={{ width: "5px", height: "5px", borderRadius: "50%", background: info.color, flexShrink: 0 }} />
                <span style={{ fontSize: "11px", color: info.color, fontWeight: 600 }}>{info.label}</span>
              </div>
              <ProgressBar value={Math.round(p.avance || 0)} color={info.color} />
              {verCostos && (
                <span style={{ fontSize: "11px", color: "var(--muted-foreground)", fontFamily: "'JetBrains Mono', monospace" }}>{pesos(p.presupuesto)}</span>
              )}
            </Link>
          );
        })}
      </div>

      {/* Modulos (navegacion) */}
      <div>
        <div style={{ fontSize: "10px", fontWeight: 600, color: "var(--muted-foreground)", letterSpacing: "0.1em", textTransform: "uppercase", marginBottom: "10px" }}>Acceso Rápido — Módulos</div>
        <div className="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-3">
          {modules.map((m, i) => {
            const Icon = m.icon;
            return (
              <Link key={i} to={m.link} className="flex flex-col items-center gap-2 rounded-sm" style={{ background: "var(--card)", border: "1px solid var(--border)", padding: "14px 10px", textAlign: "center", textDecoration: "none" }}
                onMouseEnter={(e) => { (e.currentTarget as HTMLElement).style.borderColor = m.accent; }}
                onMouseLeave={(e) => { (e.currentTarget as HTMLElement).style.borderColor = "var(--border)"; }}>
                <Icon style={{ width: "18px", height: "18px", color: m.accent }} />
                <span style={{ fontSize: "11px", fontWeight: 600, color: "var(--foreground)", lineHeight: 1.3 }}>{m.title}</span>
              </Link>
            );
          })}
        </div>
      </div>
    </div>
  );
}
