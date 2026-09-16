# Pruebas del modo estático

Cinco guiones que manejan un navegador real contra el build estático y verifican
lo que más se rompe. Cubren de punta a punta el frontend y el simulador, que es
donde vive la mayor parte del comportamiento por rol. Las pruebas del backend
están en `back/tests/`, y todo corre en el CI en cada pull request.

| Guion | Qué cubre |
|---|---|
| `humo.mjs` | los cuatro roles: login, RF19 (menú de Usuarios), RF20 (presupuesto oculto al Técnico), las ocho pantallas del menú y la recarga en `#/alertas` |
| `inactividad.mjs` | pausar y reactivar la obra: período vigente, período histórico que no pausa, obra finalizada que no revive, eliminar el vigente |
| `validaciones.mjs` | rango de fechas invertido, en las dos barreras: el `min` del navegador y la validación del simulador |
| `persistencia.mjs` | la copia en `localStorage` y su invalidación al cambiar `datos.json` |
| `contrato.mjs` | reglas que no tienen botón: borrar un reporte ya enviado, rechazar sin motivo, mandar un estado vacío, cargar avance en una obra cancelada |

`contrato.mjs` es distinto de los otros cuatro: en vez de manejar la pantalla,
le habla al simulador directo por `window.sgsoMockFetch`, la costura que expone
`src/app/mock/servidor.ts`. Existe porque esas cuatro reglas viven en la capa de
API y no hay forma de llegar a ellas desde la interfaz. Las cuatro cubren
defectos reales que estuvieron en el código; si alguna vuelve, esta suite se
pone en rojo.

## Cómo correrlos

Hace falta Playwright con Chromium. Una sola vez:

```bash
cd FRONT
npm install --no-save playwright
npx playwright install chromium
```

Después, compilar el modo estático y servirlo **desde un subdirectorio**, que es
como lo sirve GitHub Pages:

```bash
cd FRONT
BASE_PATH=./ VITE_MOCK=1 VITE_HASH_ROUTER=1 npm run build

mkdir -p /tmp/scgo
ln -sfn "$PWD/dist" /tmp/scgo/SCGO
npx http-server /tmp/scgo -p 8123 -c-1 --silent &
```

En Windows, en vez del `ln`, copiar `dist` a `C:\tmp\scgo\SCGO`.

Y correr:

```bash
node scripts/pruebas/humo.mjs
node scripts/pruebas/inactividad.mjs
node scripts/pruebas/validaciones.mjs
node scripts/pruebas/persistencia.mjs
node scripts/pruebas/contrato.mjs
```

Cada uno imprime una línea por comprobación y termina con el total. Sale con
código 1 si algo falla, así que sirven en un pipeline.

La URL se puede cambiar con `SGSO_URL`:

```bash
SGSO_URL=http://localhost:5173/ node scripts/pruebas/humo.mjs
```

## Cuándo correrlos

Si tocás `src/app/mock/servidor.ts`, `datos.json`, los permisos por rol o el ciclo
de estados de la obra, corrélos antes de pushear. Son unos treinta segundos y
cubren justo lo que es fácil romper sin darse cuenta.

## Dos cosas esperables

**`humo.mjs` reporta errores de consola por `fonts.googleapis.com`** si la red del
entorno intercepta TLS o está bloqueada. Es del entorno, no del sitio: en Pages la
hoja de estilos carga normal.

**No sirve el servidor de pruebas de Python.** Sirve los `.js` como `text/plain`
en Windows y el navegador rechaza el módulo; además es mono-hilo y se cuelga con
las conexiones persistentes. Usar `http-server` u otro que fije el MIME.
