import { useState, FormEvent } from "react";
import { Link } from "react-router";
import { HardHat } from "lucide-react";
import { olvideContrasena } from "../auth/api";

export default function OlvidePage() {
  const [email, setEmail] = useState("");
  const [mensaje, setMensaje] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [cargando, setCargando] = useState(false);

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    setError(null);
    setMensaje(null);
    setCargando(true);
    try {
      const msg = await olvideContrasena(email);
      setMensaje(msg);
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
            <div style={{ fontSize: "10px", color: "var(--muted-foreground)", textTransform: "uppercase", letterSpacing: "0.08em" }}>Recuperar contraseña</div>
          </div>
        </div>

        {mensaje ? (
          <div>
            <div style={{ fontSize: "13px", color: "var(--foreground)", background: "rgba(34,197,94,0.12)", border: "1px solid rgba(34,197,94,0.4)", borderRadius: "4px", padding: "12px" }}>
              {mensaje}
            </div>
            <p style={{ fontSize: "12px", color: "var(--muted-foreground)", marginTop: "12px" }}>Revisá tu casilla (y la carpeta de spam).</p>
            <Link to="/login" style={{ fontSize: "13px", color: "var(--primary)", display: "inline-block", marginTop: "16px" }}>← Volver al login</Link>
          </div>
        ) : (
          <form onSubmit={handleSubmit}>
            <p style={{ fontSize: "13px", color: "var(--muted-foreground)", marginBottom: "16px" }}>
              Ingresá tu email y te enviaremos un enlace para definir una nueva contraseña.
            </p>
            <label style={{ display: "block", fontSize: "12px", color: "var(--muted-foreground)", marginBottom: "6px" }}>Email</label>
            <input type="email" value={email} onChange={(e) => setEmail(e.target.value)} required autoFocus placeholder="tu@email.com" style={inputStyle} />

            {error && (
              <div style={{ marginTop: "16px", fontSize: "12px", color: "#fca5a5", background: "rgba(192,57,43,0.12)", border: "1px solid rgba(192,57,43,0.4)", borderRadius: "4px", padding: "8px 10px" }}>{error}</div>
            )}

            <button type="submit" disabled={cargando} style={{ marginTop: "20px", width: "100%", padding: "10px", background: "var(--primary)", color: "var(--primary-foreground)", border: "none", borderRadius: "4px", fontSize: "13px", fontWeight: 600, cursor: cargando ? "default" : "pointer", opacity: cargando ? 0.6 : 1 }}>
              {cargando ? "Enviando..." : "Enviar enlace"}
            </button>
            <Link to="/login" style={{ fontSize: "13px", color: "var(--muted-foreground)", display: "inline-block", marginTop: "16px" }}>← Volver al login</Link>
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
