# Traspaso: cómo retomar SCGO

Este archivo existe porque las sesiones locales de Claude Code se pierden con
cada corte de luz o reinicio brusco. **Lo que no está acá ni en el repositorio,
se perdió.** Una sesión nueva empieza leyendo esto.

- **Actualizado:** 2026-09-19
- **Base:** `main` @ `d326aba`, CI en verde

---

## 1. Qué leer, y en qué orden

| Archivo | Para qué |
|---|---|
| Este archivo | Dónde quedó todo y qué hay en vuelo **ahora** |
| [CONTRIBUTING.md](../CONTRIBUTING.md) | Cómo se trabaja: ramas, PRs, qué cuenta como terminado |
| [docs/PLAN-PRODUCTO.md](PLAN-PRODUCTO.md) | **El backlog real.** Todo lo que falta, con prioridad y tamaño |
| [docs/REQUERIMIENTOS.md](REQUERIMIENTOS.md) | Qué tiene que hacer el sistema (RF y RNF) |
| [docs/ARQUITECTURA.md](ARQUITECTURA.md) | Cómo está construido |
| [docs/OPERACION.md](OPERACION.md) | Cómo se despliega y se opera |

El backlog **no se duplica acá**. Si algo cambia de estado, se anota en
`PLAN-PRODUCTO.md`, que es donde se lleva la cuenta.

---

## 2. Dónde quedó el trabajo

ADR-001 está cerrado: el backend tiene Composer con PSR-4, `Sgso\Reglas` y
`Sgso\Ruteo`, PHPStan nivel 5, pruebas unitarias y de integración contra MariaDB,
y CI con cuatro chequeos. Después vinieron dos olas de seguridad y la puesta al
día de dependencias.

| PR | Qué entró |
|---|---|
| #4, #6, #7 | ADR-001 completo: Composer, reglas puras, tabla de rutas, pruebas, CI |
| #8, #9 | Documentación ordenada, requerimientos recuperados, producto renombrado a SCGO |
| #10, #11, #12 | Seguridad: XSS por enlaces, secreto JWT obligatorio, errores sin detalles internos, límite de login, contraseñas de 10, headers, límite de `/auth/olvide` |
| #13 | Dependencias al día y auditadas, Dependabot configurado |

**No queda ningún P0 de seguridad abierto.** De seguridad quedan A-09, A-11 y
A-12, ninguno bloqueante.

---

## 3. Qué hay en vuelo ahora mismo

Al mergearse el PR #13 se activó Dependabot, que abrió **seis PRs** en minutos.
La sesión local murió antes de triarlos. Este es el veredicto de cada uno,
verificado contra los registros del CI y la metadata de los paquetes:

| PR | Qué sube | CI | Qué hacer |
|---|---|---|---|
| [#14](https://github.com/AcostaAlex10/SCGO/pull/14) | `phpstan` 2.2.13 → 2.2.14 | verde | **Mergear.** Parche de una herramienta de desarrollo. |
| [#15](https://github.com/AcostaAlex10/SCGO/pull/15) | 34 menores y parches del front | verde | **Mergear.** Es el grupo `front-menores`, para eso se agrupó. |
| [#16](https://github.com/AcostaAlex10/SCGO/pull/16) | `@vitejs/plugin-react` 4.7.0 → 6.1.1 | **rojo** | **Cerrar.** La versión 6 importa `vite/internal`, que Vite 6 no exporta: necesita Vite 7 primero. Es una actualización acoplada, no suelta. |
| [#17](https://github.com/AcostaAlex10/SCGO/pull/17) | `react-dom` 18 → **19** (y sus tipos) | **rojo** | **Cerrar.** Sube `react-dom` pero deja `react` en 18. El build pasa y la aplicación **no arranca**: la pantalla de login queda en blanco y `humo.mjs` expira esperando el campo de email. React 19 es su propia tarea. |
| [#18](https://github.com/AcostaAlex10/SCGO/pull/18) | `react-resizable-panels` 2.1.7 → 4.12.4 | **rojo** | **Cerrar:** resuelto de otra forma (ver abajo). |
| [#19](https://github.com/AcostaAlex10/SCGO/pull/19) | `date-fns` 3.6.0 → 4.4.0 | verde | **Cerrar:** resuelto de otra forma (ver abajo). **El verde engaña**: `react-day-picker@8.10.1` declara `date-fns: ^2.28.0 \|\| ^3.0.0`, y 4.4.0 queda fuera. El CI instala con `--legacy-peer-deps`, que no verifica los rangos de peers, así que una combinación que la librería no soporta pasa igual. |

### Lo que se hizo en vez de #18 y #19

`resizable.tsx` y `calendar.tsx` eran primitivos de shadcn que **no importaba
nadie**: los `<Calendar>` que aparecen en `ProyectosPage` y `ProyectoDetallePage`
son el ícono de lucide, no el componente. Actualizar una API rota de un
componente muerto no tiene sentido, así que se borraron los dos, y con ellos
`react-resizable-panels`, `react-day-picker` y `date-fns`.

El paquete de la aplicación bajó de **1.032,58 kB a 952,48 kB** (gzip: 293,21 →
269,86). Eso es avance parcial de C-10 y C-11, y cuenta para RNF01, que es la
carga en obra con señal móvil.

> **Ojo con el resto de los primitivos de shadcn.** `chart`, `carousel` y `drawer`
> tampoco los importa nadie. Se dejaron por ahora: borrarlos es C-11 y merece su
> propia tarea. Pero si Dependabot abre un PR sobre alguno, la respuesta
> probablemente sea la misma.

---

## 4. Trampas que ya costaron tiempo

Cada una de estas hizo perder al menos media hora. Están acá para no repetirlas.

1. **`testing` no se toca.** Está congelada en `a25da85`. Es la demo con datos
   simulados que ven los testers, y se publica sola por `pages-testing.yml`. No
   se mergea, no se rebasea. Todo el desarrollo va en `main`.
2. **El repositorio se renombró a `SCGO`** el 2026-09-16. GitHub redirige las URL
   viejas **menos la de la demo**, que ahora es
   https://acostaalex10.github.io/SCGO/.
3. **El simulador y PHP se desincronizan en silencio.** `mock/servidor.ts` son
   1.344 líneas que reimplementan el backend a mano. Ya pasó tres veces que la
   demo acepta datos que el sistema real rechaza. Si cambiás una validación de la
   API, cambiá las dos. Es el problema que ataca C-02.
4. **`migrar.php` no cambia columnas que ya existen.** Todas las tablas de
   `schema.sql` usan `CREATE TABLE IF NOT EXISTS`, así que sobre una base viva un
   cambio de tipo no hace nada. Por eso cada cambio de esquema lleva su script
   aparte en `back/sql/`. Las dos migraciones existentes **ya se corrieron**.
5. **Las credenciales de la base están en Render → servicio → Environment.** El
   repositorio es **público**: ninguna credencial ni dato de Triwe puede entrar,
   ni al código ni a un archivo ni al chat.
6. **No apilar un PR sobre la rama de otro PR.** Ya cerró el PR #5 por eso: al
   borrarse la rama base, GitHub cierra el PR apilado en vez de reapuntarlo.
7. **Salir siempre de `main` actualizado.** Una skill pareció no existir porque
   las ramas salieron de un commit anterior a su merge.

### Si la sesión corre en la nube y no en tu máquina

Las cinco suites de Playwright necesitan Chromium. En el contenedor remoto el
navegador preinstalado es una compilación más vieja que la que pide el paquete
`playwright` del proyecto, y la carpeta cambió de nombre entre versiones
(`chrome-linux` → `chrome-linux64`, `headless_shell` →
`chrome-headless-shell`). Se resuelve armando enlaces simbólicos con los nombres
nuevos apuntando a los binarios viejos y exportando `PLAYWRIGHT_BROWSERS_PATH`.

Además, `humo.mjs` da **20/24 en la nube**: las cuatro que fallan son
`ERR_CERT_AUTHORITY_INVALID` al cargar Google Fonts, por el proxy del entorno.
**No es una regresión.** En el CI de GitHub da 24/24.

---

## 5. Cómo verificar sin esperar al CI

```bash
# Backend
cd back && composer install && composer phpstan && composer test

# Frontend
cd FRONT && npm ci --legacy-peer-deps && npm run typecheck && npm run build

# Las cinco suites, contra la demo estática
BASE_PATH='./' VITE_HASH_ROUTER='1' VITE_MOCK='1' npm run build
mkdir -p /tmp/scgo && ln -sfn "$PWD/dist" /tmp/scgo/SCGO
npx --yes http-server /tmp/scgo -p 8123 -c-1 --silent &
for s in humo inactividad validaciones persistencia contrato; do
  node scripts/pruebas/$s.mjs
done
```

Las de integración del backend necesitan MariaDB y las variables
`SGSO_TEST_DB_*`; si no están, esas pruebas se saltean solas.

---

## 6. Mantener esto al día

Es la única defensa contra el próximo corte de luz.

- **Al terminar cada tarea**, actualizá la sección 3 y la fecha de arriba.
- **Si algo te hizo perder tiempo**, va a la sección 4. Esa lista es el activo
  más valioso del archivo.
- **Lo que cambia de estado en el backlog** se anota en `PLAN-PRODUCTO.md`, no acá.
- **Commiteá y pusheá seguido.** Una rama sin pushear es trabajo que el próximo
  corte se lleva.
