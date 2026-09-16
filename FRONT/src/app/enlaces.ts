// Qué enlace externo se puede guardar y mostrar (plan de producto, A-01).
//
// Los documentos de una obra se guardan como URL y se muestran como un enlace.
// Con el esquema `javascript:`, abrirlo ejecuta código en la sesión de quien
// hace clic. La regla es la misma que `Sgso\Reglas\Enlaces` en el backend:
// solo http o https, con host, y sin espacios ni caracteres de control.
//
// Se aplica al guardar (en el simulador) y al mostrar (`EnlaceDocumento`): así
// también quedan neutralizados los enlaces guardados antes de este control.

const ESQUEMAS_PERMITIDOS = ["http:", "https:"];

// Espacios y caracteres de control: la vía clásica para colar un esquema que el
// navegador después normaliza.
const CARACTERES_PROHIBIDOS = /[\u0000-\u0020\u007f]/;

export function esEnlaceSeguro(url: unknown): url is string {
  if (typeof url !== "string" || url === "" || CARACTERES_PROHIBIDOS.test(url)) {
    return false;
  }

  let destino: URL;
  try {
    destino = new URL(url);
  } catch {
    return false;
  }

  return ESQUEMAS_PERMITIDOS.includes(destino.protocol) && destino.hostname !== "";
}
