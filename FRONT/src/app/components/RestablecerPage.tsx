import { useState, FormEvent } from "react";
import { Link, useSearchParams, useNavigate } from "react-router";
import { HardHat } from "lucide-react";
import { restablecerContrasena } from "../auth/api";

export default function RestablecerPage() {
  const [params] = useSearchParams();
  const navigate = useNavigate();
  const token = params.get("token") ?? "";

  const [contrasena, setContrasena] = useState("");
  const [repetir, setRepetir] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [listo, setListo] = useState(false);
  const [cargando, setCargando] = useState(false);

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    setError(null);
    if (contrasena !== repetir) {
      setError("Las contraseñas no coinciden");
      return;
    }
    setCargando(true);
    try {
      await restablecerContrasena(token, contrasena);
      setListo(true);
      setTimeout(() => navigate("/login", { replace: true }), 2500);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Error desconocido");
    } finally {
      setCargando(false);
    }
  }

  return (
    <div className="flex items-center justify-center" style={{ minHeight: "100vh", background: "var(--background)", padding: "24px" }}>
      <div style={{ width: "100%", maxWidth: "380px", background: "var(--card)", border: "1px solid var(--border)", borderRadius: "6px", padding: "32px 28px" }}>
        <div className="flex items-center gap-3" style={{ marginBottom: "20px" }}>
          <div className="flex items-center justify-center" style={{ width: "38px", height: "38px", background: "var(--primary)", borderRadius: "4px" }}>
            <HardHat style={{ width: "20px", height: "20px", color: "var(--primary-foreground)" }} />
          </div>
          <div style={{ lineHeight: 1.2 }}>
            <div style={{ fontSize: "14px", fontWeight: 700, color: "var(--foreground)" }}>OBRAS · SGSO</div>
            <div style={{ fontSize: "10px", color: "var(--muted-foreground)", textTransform: "uppercase", letterSpacing: "0.08em" }}>Nueva contraseña</div>
          </div>
        </div>

        {!token ? (
          <div>
            <p style={{ fontSize: "13px", color: "#fca5a5" }}>Falta el token de recuperación. Abrí el enlace que te llegó por email.</p>
            <Link to="/olvide" style={{ fontSize: "13px", color: "var(--primary)", display: "inline-block", marginTop: "16px" }}>Pedir un enlace nuevo</Link>
          </div>
        ) : listo ? (
          <div style={{ fontSize: "13px", color: "var(--foreground)", background: "rgba(34,197,94,0.12)", border: "1px solid rgba(34,197,94,0.4)", borderRadius: "4px", padding: "12px" }}>
            ¡Contraseña actualizada! Te llevamos al login...
          </div>
        ) : (
          <form onSubmit={handleSubmit}>
            <label style={{ display: "block", fontSize: "12px", color: "var(--muted-foreground)", marginBottom: "6px" }}>Nueva contraseña</label>
            <input type="password" value={contrasena} onChange={(e) => setContrasena(e.target.value)} required minLength={10} autoFocus placeholder="Mínimo 10 caracteres" style={inputStyle} />
            <label style={{ display: "block", fontSize: "12px", color: "var(--muted-foreground)", margin: "16px 0 6px" }}>Repetir contraseña</label>
            <input type="password" value={repetir} onChange={(e) => setRepetir(e.target.value)} required minLength={10} placeholder="••••••••" style={inputStyle} />

            {error && (
              <div style={{ marginTop: "16px", fontSize: "12px", color: "#fca5a5", background: "rgba(192,57,43,0.12)", border: "1px solid rgba(192,57,43,0.4)", borderRadius: "4px", padding: "8px 10px" }}>{error}</div>
            )}

            <button type="submit" disabled={cargando} style={{ marginTop: "20px", width: "100%", padding: "10px", background: "var(--primary)", color: "var(--primary-foreground)", border: "none", borderRadius: "4px", fontSize: "13px", fontWeight: 600, cursor: cargando ? "default" : "pointer", opacity: cargando ? 0.6 : 1 }}>
              {cargando ? "Guardando..." : "Cambiar contraseña"}
            </button>
          </form>
        )}
      </div>
    </div>
  );
}

const inputStyle: React.CSSProperties = {
  width: "100%", padding: "10px 12px", background: "var(--secondary)", border: "1px solid var(--border)",
  borderRadius: "4px", color: "var(--foreground)", fontSize: "13px", outline: "none",
};
