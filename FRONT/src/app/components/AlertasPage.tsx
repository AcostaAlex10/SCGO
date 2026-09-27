import { useEffect, useState } from "react";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "./ui/card";
import { Badge } from "./ui/badge";
import { AlertTriangle, TrendingDown, Package, Wallet, Fuel, type LucideIcon } from "lucide-react";
import { toast } from "sonner";
import { obtenerAnalisis, type Alerta, type Analisis } from "../api/proyectos";
import { puedeVerCostos } from "../auth/permisos";

const GRAVEDAD: Record<string, string> = { alta: "#ef4444", media: "#e8981e", baja: "#22c55e" };
const ICONO: Record<Alerta["tipo"], LucideIcon> = { avance: TrendingDown, material: Package, maquinaria: Fuel };
const pesos = (n: number) => "$" + n.toLocaleString("es-AR", { maximumFractionDigits: 0 });

export default function AlertasPage() {
  const [data, setData] = useState<Analisis | null>(null);
  const [loading, setLoading] = useState(true);
  const verCostos = puedeVerCostos();

  useEffect(() => {
    obtenerAnalisis()
      .then(setData)
      .catch(() => toast.error("No se pudo cargar el análisis"))
      .finally(() => setLoading(false));
  }, []);

  if (loading) return <div className="text-center text-muted-foreground py-12">Cargando análisis...</div>;
  if (!data) return null;

  const conPlan = data.proyectos.filter((p) => p.avance_esperado !== null);

  return (
    <div className="space-y-6">
      <div>
        <h2 className="text-3xl font-bold text-foreground flex items-center gap-2"><AlertTriangle className="w-7 h-7" /> Análisis y Alertas</h2>
        <p className="text-muted-foreground mt-2">Desvíos de avance y análisis presupuestario.</p>
      </div>

      {/* Feed de alertas activas */}
      <Card>
        <CardHeader>
          <CardTitle>Alertas activas ({data.alertas.length})</CardTitle>
          <CardDescription>Situaciones que requieren atención.</CardDescription>
        </CardHeader>
        <CardContent className="space-y-2">
          {data.alertas.length === 0 ? (
            <p className="text-muted-foreground text-sm">No hay alertas activas. 👍</p>
          ) : (
            data.alertas.map((a, i) => {
              const Icono = ICONO[a.tipo];
              return (
                <div key={i} className="flex items-center gap-3 border rounded-md px-4 py-3 text-sm" style={{ borderLeft: `3px solid ${GRAVEDAD[a.gravedad]}` }}>
                  <Icono className="w-4 h-4" style={{ color: GRAVEDAD[a.gravedad] }} />
                  <span className="font-medium">
                    {a.maquina ?? a.proyecto}
                    {a.maquina && a.proyecto && <span className="text-muted-foreground font-normal"> · {a.proyecto}</span>}
                  </span>
                  <span className="flex-1 text-muted-foreground">{a.mensaje}</span>
                  <Badge style={{ background: GRAVEDAD[a.gravedad] }}>{a.gravedad}</Badge>
                </div>
              );
            })
          )}
        </CardContent>
      </Card>

      {/* Análisis de avance (RF11) */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2"><TrendingDown className="w-5 h-5" /> Avance esperado vs. real</CardTitle>
          <CardDescription>Obras con planificación cargada.</CardDescription>
        </CardHeader>
        <CardContent>
          {conPlan.length === 0 ? (
            <p className="text-muted-foreground text-sm">No hay obras con planificación para comparar.</p>
          ) : (
            <div className="space-y-2">
              <div className="grid text-xs text-muted-foreground uppercase" style={{ gridTemplateColumns: "1fr 110px 110px 110px" }}>
                <span>Obra</span><span>Esperado</span><span>Real</span><span>Desvío</span>
              </div>
              {conPlan.map((p) => (
                <div key={p.id_proyecto} className="grid items-center text-sm border-t py-2" style={{ gridTemplateColumns: "1fr 110px 110px 110px" }}>
                  <span className="font-medium">{p.nombre}</span>
                  <span>{p.avance_esperado}%</span>
                  <span>{p.avance_real}%</span>
                  <span style={{ color: p.alerta_avance ? "#ef4444" : "#22c55e", fontWeight: 600 }}>
                    {p.desvio_avance! > 0 ? "+" : ""}{p.desvio_avance} pp
                  </span>
                </div>
              ))}
            </div>
          )}
        </CardContent>
      </Card>

      {/* Análisis presupuestario (RF13) - oculto para Técnico (RF20) */}
      {verCostos && (
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2"><Wallet className="w-5 h-5" /> Presupuesto vs. ejecutado</CardTitle>
            <CardDescription>
              Gasto ejecutado estimado según el avance físico. "Base plan." es la suma del presupuesto base de las etapas planificadas.
            </CardDescription>
          </CardHeader>
          <CardContent>
            {(() => {
              const mostrarBase = data.proyectos.some((p) => (p.presupuesto_base_total ?? 0) > 0);
              const cols = mostrarBase ? "1fr 140px 140px 140px 140px" : "1fr 150px 150px 150px";
              return (
                <div className="space-y-2">
                  <div className="grid text-xs text-muted-foreground uppercase" style={{ gridTemplateColumns: cols }}>
                    <span>Obra</span>
                    <span>Presupuesto</span>
                    {mostrarBase && <span>Base plan.</span>}
                    <span>Ejecutado</span>
                    <span>Diferencia</span>
                  </div>
                  {data.proyectos.map((p) => (
                    <div key={p.id_proyecto} className="grid items-center text-sm border-t py-2" style={{ gridTemplateColumns: cols }}>
                      <span className="font-medium">{p.nombre}</span>
                      <span className="mono">{pesos(p.presupuesto ?? 0)}</span>
                      {mostrarBase && (
                        <span className="mono text-muted-foreground">
                          {(p.presupuesto_base_total ?? 0) > 0 ? pesos(p.presupuesto_base_total!) : "—"}
                        </span>
                      )}
                      <span className="mono">{pesos(p.ejecutado ?? 0)}</span>
                      <span className="mono" style={{ color: (p.diferencia ?? 0) >= 0 ? "#22c55e" : "#ef4444" }}>
                        {pesos(p.diferencia ?? 0)}
                      </span>
                    </div>
                  ))}
                </div>
              );
            })()}
          </CardContent>
        </Card>
      )}
    </div>
  );
}
