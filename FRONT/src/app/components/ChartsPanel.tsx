import {
  AreaChart, Area, BarChart, Bar, LineChart, Line,
  PieChart, Pie, Cell, RadarChart, Radar, PolarGrid,
  PolarAngleAxis, PolarRadiusAxis, RadialBarChart, RadialBar,
  ResponsiveContainer, XAxis, YAxis, CartesianGrid, Tooltip,
  Legend, ReferenceLine
} from "recharts";
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from "./ui/card";

// Paleta dark industrial
const C = {
  amber: "#e8981e",
  blue: "#3b82f6",
  green: "#22c55e",
  purple: "#a855f7",
  red: "#ef4444",
  orange: "#f97316",
  grid: "#1e2a42",
  tick: "#5a6a8a",
  bg: "#111521",
};

const avanceMensual = [
  { mes: "Ene", planificado: 10, ejecutado: 8 },
  { mes: "Feb", planificado: 20, ejecutado: 17 },
  { mes: "Mar", planificado: 32, ejecutado: 29 },
  { mes: "Abr", planificado: 46, ejecutado: 42 },
  { mes: "May", planificado: 58, ejecutado: 55 },
  { mes: "Jun", planificado: 68, ejecutado: 68 },
];

const presupuestoData = [
  { nombre: "Ruta 14", presupuestado: 12, ejecutado: 13.2 },
  { nombre: "Los Pinos", presupuestado: 8.5, ejecutado: 7.9 },
  { nombre: "Puente P-E", presupuestado: 22, ejecutado: 19.5 },
  { nombre: "C. Comercial", presupuestado: 15, ejecutado: 16.8 },
  { nombre: "C. Deportivo", presupuestado: 9, ejecutado: 8.1 },
];

const distribucionProyectos = [
  { name: "En ejecución", value: 6, color: C.green },
  { name: "Planificados", value: 3, color: C.blue },
  { name: "Paralizados", value: 1, color: C.red },
  { name: "Finalizados", value: 4, color: C.purple },
];

const consumoMateriales = [
  { semana: "S1", cemento: 40, hierro: 24, madera: 35 },
  { semana: "S2", cemento: 30, hierro: 28, madera: 20 },
  { semana: "S3", cemento: 50, hierro: 32, madera: 45 },
  { semana: "S4", cemento: 45, hierro: 18, madera: 30 },
  { semana: "S5", cemento: 60, hierro: 40, madera: 25 },
  { semana: "S6", cemento: 55, hierro: 35, madera: 50 },
];

const rendimientoRadar = [
  { indicador: "Avance Físico", valor: 80 },
  { indicador: "Presupuesto", valor: 65 },
  { indicador: "Materiales", valor: 90 },
  { indicador: "Personal", valor: 75 },
  { indicador: "Maquinaria", valor: 70 },
  { indicador: "Documentación", valor: 85 },
];

const alertasPorTipo = [
  { name: "Desvío Avance", value: 35, fill: C.orange },
  { name: "Sobrecostos", value: 28, fill: C.red },
  { name: "Consumo Anómalo", value: 22, fill: C.amber },
  { name: "Presupuesto", value: 15, fill: C.purple },
];

const RADIAN = Math.PI / 180;
// recharts 3 declara estas props como opcionales y los radios como número o
// texto: se normalizan acá, así compila igual con recharts 2 y con 3.
type EtiquetaTorta = {
  cx?: number | string; cy?: number | string; midAngle?: number;
  innerRadius?: number | string; outerRadius?: number | string; percent?: number;
};

const renderCustomLabel = (props: EtiquetaTorta) => {
  const cx = Number(props.cx ?? 0);
  const cy = Number(props.cy ?? 0);
  const midAngle = props.midAngle ?? 0;
  const innerRadius = Number(props.innerRadius ?? 0);
  const outerRadius = Number(props.outerRadius ?? 0);
  const percent = props.percent ?? 0;
  const radius = innerRadius + (outerRadius - innerRadius) * 0.5;
  const x = cx + radius * Math.cos(-midAngle * RADIAN);
  const y = cy + radius * Math.sin(-midAngle * RADIAN);
  if (percent < 0.08) return null;
  return (
    <text x={x} y={y} fill="white" textAnchor="middle" dominantBaseline="central"
      style={{ fontSize: "11px", fontWeight: 700, fontFamily: "'JetBrains Mono', monospace" }}>
      {`${(percent * 100).toFixed(0)}%`}
    </text>
  );
};

const Tip = ({ active, payload, label }: {
  active?: boolean; payload?: { name: string; value: number; color: string }[]; label?: string;
}) => {
  if (!active || !payload?.length) return null;
  return (
    <div style={{
      background: "#0d1120",
      border: "1px solid #1e2a42",
      borderRadius: "4px",
      padding: "10px 14px",
      boxShadow: "0 8px 24px rgba(0,0,0,0.5)",
    }}>
      {label && (
        <p style={{ fontSize: "11px", fontWeight: 600, color: "#94a3b8", marginBottom: "6px", letterSpacing: "0.06em", textTransform: "uppercase" }}>
          {label}
        </p>
      )}
      {payload.map((e, i) => (
        <div key={i} className="flex items-center gap-2" style={{ marginBottom: i < payload.length - 1 ? "4px" : 0 }}>
          <span style={{ width: "8px", height: "8px", borderRadius: "2px", background: e.color, display: "inline-block", flexShrink: 0 }} />
          <span style={{ fontSize: "12px", color: "#94a3b8" }}>{e.name}:</span>
          <span style={{ fontSize: "12px", fontWeight: 700, color: "#dde3ef", fontFamily: "'JetBrains Mono', monospace", marginLeft: "2px" }}>
            {typeof e.value === "number" && e.value > 100 ? `$${e.value.toFixed(1)}M` : `${e.value}`}
          </span>
        </div>
      ))}
    </div>
  );
};

const chartProps = {
  cartesianGrid: <CartesianGrid strokeDasharray="3 3" stroke={C.grid} vertical={false} />,
  xAxisProps: { tick: { fontSize: 11, fill: C.tick, fontFamily: "'JetBrains Mono', monospace" }, axisLine: false, tickLine: false },
  yAxisProps: { tick: { fontSize: 11, fill: C.tick, fontFamily: "'JetBrains Mono', monospace" }, axisLine: false, tickLine: false },
  legendProps: { wrapperStyle: { fontSize: "11px", color: "#94a3b8", paddingTop: "8px" } },
};

export function AvanceMensualChart() {
  return (
    <Card>
      <CardHeader>
        <CardTitle>Avance Físico Mensual</CardTitle>
        <CardDescription>Planificado vs Ejecutado (%)</CardDescription>
      </CardHeader>
      <CardContent>
        <ResponsiveContainer width="100%" height={240}>
          <AreaChart data={avanceMensual} margin={{ top: 10, right: 10, left: -10, bottom: 0 }}>
            <defs>
              <linearGradient id="gPlan" x1="0" y1="0" x2="0" y2="1">
                <stop offset="5%" stopColor={C.blue} stopOpacity={0.25} />
                <stop offset="95%" stopColor={C.blue} stopOpacity={0} />
              </linearGradient>
              <linearGradient id="gExec" x1="0" y1="0" x2="0" y2="1">
                <stop offset="5%" stopColor={C.amber} stopOpacity={0.35} />
                <stop offset="95%" stopColor={C.amber} stopOpacity={0} />
              </linearGradient>
            </defs>
            {chartProps.cartesianGrid}
            <XAxis dataKey="mes" {...chartProps.xAxisProps} />
            <YAxis {...chartProps.yAxisProps} unit="%" domain={[0, 80]} />
            <Tooltip content={<Tip />} />
            <Legend {...chartProps.legendProps} />
            <Area type="monotone" dataKey="planificado" name="Planificado" stroke={C.blue} strokeWidth={1.5} strokeDasharray="5 3" fill="url(#gPlan)" dot={false} />
            <Area type="monotone" dataKey="ejecutado" name="Ejecutado" stroke={C.amber} strokeWidth={2} fill="url(#gExec)" dot={{ fill: C.amber, r: 3, strokeWidth: 0 }} activeDot={{ r: 5, fill: C.amber, strokeWidth: 0 }} />
          </AreaChart>
        </ResponsiveContainer>
      </CardContent>
    </Card>
  );
}

export function PresupuestoChart({ data }: { data?: typeof presupuestoData } = {}) {
  const datos = data ?? presupuestoData;
  return (
    <Card>
      <CardHeader>
        <CardTitle>Presupuesto vs Ejecutado</CardTitle>
        <CardDescription>Por proyecto (millones $ARS)</CardDescription>
      </CardHeader>
      <CardContent>
        <ResponsiveContainer width="100%" height={240}>
          <BarChart data={datos} margin={{ top: 10, right: 10, left: -10, bottom: 0 }} barGap={2}>
            {chartProps.cartesianGrid}
            <XAxis dataKey="nombre" {...chartProps.xAxisProps} />
            <YAxis {...chartProps.yAxisProps} tickFormatter={v => `$${v}M`} />
            <Tooltip content={<Tip />} />
            <Legend {...chartProps.legendProps} />
            <Bar dataKey="presupuestado" name="Presupuestado" fill={`${C.blue}50`} stroke={C.blue} strokeWidth={1} radius={[2, 2, 0, 0]} />
            <Bar dataKey="ejecutado" name="Ejecutado" fill={C.amber} radius={[2, 2, 0, 0]} />
          </BarChart>
        </ResponsiveContainer>
      </CardContent>
    </Card>
  );
}

export function DistribucionProyectosChart(
  { data, descripcion }: { data?: typeof distribucionProyectos; descripcion?: string } = {}
) {
  const datos = (data && data.length > 0) ? data : distribucionProyectos;
  return (
    <Card>
      <CardHeader>
        <CardTitle>Distribución de Proyectos</CardTitle>
        <CardDescription>{descripcion ?? "Estado actual — 14 obras registradas"}</CardDescription>
      </CardHeader>
      <CardContent>
        <ResponsiveContainer width="100%" height={240}>
          <PieChart>
            <Pie
              data={datos}
              cx="50%"
              cy="50%"
              labelLine={false}
              label={renderCustomLabel}
              outerRadius={100}
              innerRadius={44}
              dataKey="value"
              strokeWidth={2}
              stroke={C.bg}
            >
              {datos.map((entry, index) => (
                <Cell key={index} fill={entry.color} />
              ))}
            </Pie>
            <Tooltip formatter={(v, name) => [`${v} proyectos`, String(name)]} contentStyle={{ background: "#0d1120", border: "1px solid #1e2a42", borderRadius: "4px", fontSize: "12px" }} />
            <Legend {...chartProps.legendProps} formatter={(value, entry) => (
              <span style={{ color: "#94a3b8" }}>{value}: <strong style={{ color: "#dde3ef", fontFamily: "'JetBrains Mono', monospace" }}>{(entry.payload as { value: number }).value}</strong></span>
            )} />
          </PieChart>
        </ResponsiveContainer>
      </CardContent>
    </Card>
  );
}

export function ConsumoMaterialesChart() {
  return (
    <Card>
      <CardHeader>
        <CardTitle>Consumo de Materiales</CardTitle>
        <CardDescription>Últimas 6 semanas (toneladas)</CardDescription>
      </CardHeader>
      <CardContent>
        <ResponsiveContainer width="100%" height={240}>
          <LineChart data={consumoMateriales} margin={{ top: 10, right: 10, left: -10, bottom: 0 }}>
            {chartProps.cartesianGrid}
            <XAxis dataKey="semana" {...chartProps.xAxisProps} />
            <YAxis {...chartProps.yAxisProps} />
            <Tooltip content={<Tip />} />
            <Legend {...chartProps.legendProps} />
            <Line type="monotone" dataKey="cemento" name="Cemento" stroke={C.blue} strokeWidth={2} dot={{ r: 3, fill: C.blue, strokeWidth: 0 }} activeDot={{ r: 5 }} />
            <Line type="monotone" dataKey="hierro" name="Hierro" stroke={C.orange} strokeWidth={2} dot={{ r: 3, fill: C.orange, strokeWidth: 0 }} activeDot={{ r: 5 }} />
            <Line type="monotone" dataKey="madera" name="Madera" stroke={C.green} strokeWidth={2} dot={{ r: 3, fill: C.green, strokeWidth: 0 }} activeDot={{ r: 5 }} />
          </LineChart>
        </ResponsiveContainer>
      </CardContent>
    </Card>
  );
}

export function RendimientoRadarChart() {
  return (
    <Card>
      <CardHeader>
        <CardTitle>Rendimiento Global</CardTitle>
        <CardDescription>Indicadores operativos por área (%)</CardDescription>
      </CardHeader>
      <CardContent>
        <ResponsiveContainer width="100%" height={240}>
          <RadarChart cx="50%" cy="50%" outerRadius={90} data={rendimientoRadar}>
            <PolarGrid stroke={C.grid} />
            <PolarAngleAxis dataKey="indicador" tick={{ fontSize: 10, fill: C.tick, fontFamily: "'JetBrains Mono', monospace" }} />
            <PolarRadiusAxis angle={30} domain={[0, 100]} tick={{ fontSize: 9, fill: C.tick }} />
            <Radar name="Rendimiento" dataKey="valor" stroke={C.amber} fill={C.amber} fillOpacity={0.15} strokeWidth={2} dot={{ fill: C.amber, r: 3, strokeWidth: 0 }} />
            <Tooltip formatter={(v) => [`${v}%`, "Rendimiento"]} contentStyle={{ background: "#0d1120", border: "1px solid #1e2a42", borderRadius: "4px", fontSize: "12px" }} />
          </RadarChart>
        </ResponsiveContainer>
      </CardContent>
    </Card>
  );
}

export function AlertasTipoChart() {
  return (
    <Card>
      <CardHeader>
        <CardTitle>Alertas por Tipo</CardTitle>
        <CardDescription>Distribución acumulada del mes</CardDescription>
      </CardHeader>
      <CardContent>
        <ResponsiveContainer width="100%" height={240}>
          <RadialBarChart cx="50%" cy="50%" innerRadius={20} outerRadius={105} data={alertasPorTipo} startAngle={180} endAngle={0}>
            <RadialBar label={{ position: "insideStart", fill: "#fff", fontSize: 11, fontWeight: 700 }} background={{ fill: `${C.grid}60` }} dataKey="value" />
            <Legend iconSize={8} {...chartProps.legendProps} formatter={(value) => <span style={{ color: "#94a3b8", fontSize: "11px" }}>{value}</span>} />
            <Tooltip contentStyle={{ background: "#0d1120", border: "1px solid #1e2a42", borderRadius: "4px", fontSize: "12px" }} />
          </RadialBarChart>
        </ResponsiveContainer>
      </CardContent>
    </Card>
  );
}

export function DesviacionAvanceChart() {
  const data = avanceMensual.map(d => ({
    ...d,
    desvio: d.ejecutado - d.planificado,
  }));
  return (
    <Card>
      <CardHeader>
        <CardTitle>Desvío de Avance</CardTitle>
        <CardDescription>Diferencia ejecutado − planificado (pp)</CardDescription>
      </CardHeader>
      <CardContent>
        <ResponsiveContainer width="100%" height={240}>
          <BarChart data={data} margin={{ top: 10, right: 10, left: -10, bottom: 0 }}>
            {chartProps.cartesianGrid}
            <XAxis dataKey="mes" {...chartProps.xAxisProps} />
            <YAxis {...chartProps.yAxisProps} unit="pp" />
            <Tooltip content={<Tip />} />
            <ReferenceLine y={0} stroke="#94a3b8" strokeWidth={1} strokeDasharray="4 2" />
            <Bar dataKey="desvio" name="Desvío" radius={[2, 2, 0, 0]}>
              {data.map((entry, index) => (
                <Cell key={index} fill={entry.desvio >= 0 ? C.green : C.red} />
              ))}
            </Bar>
          </BarChart>
        </ResponsiveContainer>
      </CardContent>
    </Card>
  );
}
