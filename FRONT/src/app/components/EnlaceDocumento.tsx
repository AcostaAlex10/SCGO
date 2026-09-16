import { ExternalLink } from "lucide-react";
import { esEnlaceSeguro } from "../enlaces";

/**
 * El enlace "Abrir" de un documento.
 *
 * Si la URL guardada no es http ni https, no se muestra como enlace: abrirla
 * podría ejecutar código en la sesión de quien hace clic (plan de producto,
 * A-01). Cubre también los documentos guardados antes de que la API validara el
 * esquema.
 */
export function EnlaceDocumento({ url }: { url: unknown }) {
  if (!esEnlaceSeguro(url)) {
    return (
      <span
        className="text-muted-foreground ml-auto"
        title="El enlace guardado no empieza con http:// o https://, así que no se abre."
      >
        Enlace no válido
      </span>
    );
  }

  return (
    <a
      href={url}
      target="_blank"
      rel="noopener noreferrer"
      className="text-primary inline-flex items-center gap-1 ml-auto"
    >
      Abrir <ExternalLink className="w-3 h-3" />
    </a>
  );
}
