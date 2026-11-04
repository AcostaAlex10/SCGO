import { useState, FormEvent } from "react";
import { useNavigate, useLocation } from "react-router";
import { HardHat } from "lucide-react";
import { login } from "../auth/api";
import { setSession } from "../auth/session";

export default function LoginPage() {
  const navigate = useNavigate();
  const location = useLocation();

  // A donde volver despues de loguearse (la ruta que se intento visitar, o "/").
  const from = (location.state as { from?: string } | null)?.from ?? "/";

  const [email, setEmail] = useState("");
  const [contrasena, setContrasena] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [cargando, setCargando] = useState(false);

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    setError(null);
    setCargando(true);
    try {
      const { token, usuario } = await login(email, contrasena);
      setSession(token, usuario);
      navigate(from, { replace: true });
    } catch (err) {
      setError(err instanceof Error ? err.message : "Error desconocido");
    } finally {
      setCargando(false);
    }
  }

  return (
    <div
      className="flex items-center justify-center"
      style={{ minHeight: "100vh", background: "var(--background)", padding: "24px" }}
    >
      <form
        onSubmit={handleSubmit}
        style={{
          width: "100%",
          maxWidth: "360px",
          background: "var(--card)",
          border: "1px solid var(--border)",
          borderRadius: "6px",
          padding: "32px 28px",
        }}
      >
        {/* Logo / encabezado */}
        <div className="flex items-center gap-3" style={{ marginBottom: "24px" }}>
          <div
            className="flex items-center justify-center"
            style={{ width: "38px", height: "38px", background: "var(--primary)", borderRadius: "4px" }}
          >
            <HardHat style={{ width: "20px", height: "20px", color: "var(--primary-foreground)" }} />
          </div>
          <div style={{ lineHeight: 1.2 }}>
            <div style={{ fontSize: "14px", fontWeight: 700, color: "var(--foreground)" }}>OBRAS · SGSO</div>
            <div style={{ fontSize: "10px", color: "var(--muted-foreground)", textTransform: "uppercase", letterSpacing: "0.08em" }}>
              Iniciar sesion
            </div>
          </div>
        </div>

        {/* Email */}
        <label style={{ display: "block", fontSize: "12px", color: "var(--muted-foreground)", marginBottom: "6px" }}>
          Email
        </label>
        <input
          type="email"
          value={email}
          onChange={(e) => setEmail(e.target.value)}
          required
          autoFocus
          placeholder="admin@sgso.com"
          style={inputStyle}
        />

        {/* Contrasena */}
        <label style={{ display: "block", fontSize: "12px", color: "var(--muted-foreground)", margin: "16px 0 6px" }}>
          Contrasena
        </label>
        <input
          type="password"
          value={contrasena}
          onChange={(e) => setContrasena(e.target.value)}
          required
          placeholder="••••••••"
          style={inputStyle}
        />

        {/* Error */}
        {error && (
          <div
            style={{
              marginTop: "16px",
              fontSize: "12px",
              color: "#fca5a5",
              background: "rgba(192,57,43,0.12)",
              border: "1px solid rgba(192,57,43,0.4)",
              borderRadius: "4px",
              padding: "8px 10px",
            }}
          >
            {error}
          </div>
        )}

        {/* Boton */}
        <button
          type="submit"
          disabled={cargando}
          style={{
            marginTop: "24px",
            width: "100%",
            padding: "10px",
            background: "var(--primary)",
            color: "var(--primary-foreground)",
            border: "none",
            borderRadius: "4px",
            fontSize: "13px",
            fontWeight: 600,
            cursor: cargando ? "default" : "pointer",
            opacity: cargando ? 0.6 : 1,
          }}
        >
          {cargando ? "Ingresando..." : "Ingresar"}
        </button>

        <div style={{ textAlign: "center", marginTop: "16px" }}>
          <a href="/olvide" style={{ fontSize: "12px", color: "var(--muted-foreground)" }}>
            ¿Olvidaste tu contraseña?
          </a>
        </div>
      </form>
    </div>
  );
}

const inputStyle: React.CSSProperties = {
  width: "100%",
  padding: "10px 12px",
  background: "var(--secondary)",
  border: "1px solid var(--border)",
  borderRadius: "4px",
  color: "var(--foreground)",
  fontSize: "13px",
  outline: "none",
};
