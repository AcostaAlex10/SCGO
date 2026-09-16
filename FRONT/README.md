# SCGO — Frontend

Aplicación React del sistema. La visión general y cómo levantar todo el sistema
están en el [README principal](../README.md).

## Comandos

```bash
npm install --legacy-peer-deps   # el flag es necesario por conflictos entre dependencias
npm run dev                      # servidor de desarrollo
npm run typecheck                # verificación de tipos (TypeScript estricto)
npm run build                    # build de producción en dist/
```

Copiar `.env.example` a `.env` y ajustar `VITE_API_URL` si la API no está en la
dirección por defecto.

## Modos

| Modo | Cómo se activa | Para qué |
|---|---|---|
| Normal | por defecto | contra la API real |
| Prueba | `VITE_MOCK=1` | sin backend, con datos simulados; ver [MODO-PRUEBA.md](MODO-PRUEBA.md) |

## Estructura

```
src/app/
  components/     una pantalla por módulo; ui/ tiene los primitivos de shadcn/ui
  auth/           sesión, permisos y api.ts, por donde pasa toda llamada
  mock/           simulador de la API y sus datos
  routes.tsx      rutas de la aplicación
scripts/
  pruebas/        suites de punta a punta con Playwright
```

El diseño de la arquitectura está en
[docs/ARQUITECTURA.md](../docs/ARQUITECTURA.md), y los componentes de terceros,
en [ATTRIBUTIONS.md](ATTRIBUTIONS.md).
