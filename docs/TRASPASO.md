# Traspaso: cómo retomar SCGO

Este archivo existe porque las sesiones locales de Claude Code se pierden con
cada corte de luz o reinicio brusco. **Lo que no está acá ni en el repositorio,
se perdió.** Una sesión nueva empieza leyendo esto.

- **Actualizado:** 2026-09-19, al cierre de la segunda sesión del día
- **Base:** `main` @ `c3addfb`, CI en verde, producción desplegada y respondiendo

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
| #20 | Triaje de Dependabot, este archivo, y fuera dos primitivos de shadcn sin uso |
| #14, #15 | Dependabot: `phpstan` 2.2.14 y 34 menores del front |
| #21 | B-05: `/api/health` ejecuta `SELECT 1` |

**No queda ningún P0 de seguridad abierto.** De seguridad quedan A-09, A-11 y
A-12, ninguno bloqueante.

---

## 3. Qué hay en vuelo ahora mismo

**Nada a medio mergear.** Los seis PR de Dependabot están resueltos:

| PR | Qué pasó |
|---|---|
| #14, #15 | Mergeados. Los dos necesitaron `@dependabot rebase` (ver trampa 8). |
| #16 | Cerrado: `@vitejs/plugin-react` 6 importa `vite/internal`, que Vite 6 no exporta. |
| #17 | Cerrado: subía `react-dom` a 19 con `react` en 18, y la app queda en blanco. |
| #18, #19 | Cerrados: sin objeto, porque el #20 borró los componentes que los usaban. |

Vite 7 y React 19 quedaron anotados en C-10 como subidas acopladas, que no se
pueden hacer de a un paquete. **Si Dependabot vuelve a abrir cualquiera de las
dos por separado, la respuesta es la misma.**

### Esperando una decisión: B-03 (migraciones versionadas)

B-05 está hecho (#21). Lo que sigue de la Ola 2 sin depender de DEC-04 es B-03,
pero escribir migraciones y tocar `schema.sql` requiere el visto bueno del
equipo, y se frenó ahí. Lo que ya se relevó, para no repetirlo:

- Hoy hay tres mecanismos: `migrar.php` (ejecuta `schema.sql`, con
  `CREATE TABLE IF NOT EXISTS`), dos scripts sueltos que **ya se corrieron en
  producción** (`migracion-estado-enum.php` y `migracion-reporte-final.php`), y
  `Sgso\Seguridad\IntentosLogin`, que **crea su propia tabla en tiempo de
  ejecución** (`IntentosLogin.php:104`) justamente porque no había migraciones.
- El `Dockerfile` no corre ninguna migración: el deploy de Render solo levanta
  Apache.
- Las pruebas de integración cargan `schema.sql` directo (`CasoConBase`), así
  que `schema.sql` tiene que seguir describiendo el esquema completo.

Preguntas abiertas. Hasta que se respondan, **B-03 no se arranca**:

1. **¿Cuándo corren?** ¿Al arrancar el contenedor, antes de Apache (cada merge
   a `main` aplicaría las pendientes en producción sin intervención)? ¿O con un
   comando manual, después de cada deploy?
2. **¿Cómo se marca la base viva?** Producción ya tiene el esquema y las dos
   migraciones sueltas. La propuesta: si `schema_migrations` no existe pero
   `proyecto` sí, registrar esas tres como aplicadas sin ejecutarlas.
3. **¿`schema.sql` sigue siendo la foto completa?** La propuesta: sí, y una
   prueba de integración aplica todas las migraciones sobre una base vacía y
   compara el resultado con `schema.sql`, para que no se desincronicen.
4. **¿`intento_login` pasa a ser una migración?** Así se sacaría el
   `CREATE TABLE` en tiempo de ejecución de `IntentosLogin`.

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
8. **El ruleset de `main` exige que la rama esté al día.** Cada merge deja a los
   demás PR en `BEHIND`, y no se pueden mergear hasta actualizarlos y esperar
   otra vez el CI. Con los de Dependabot, se comenta `@dependabot rebase`:
   rebasearlos a mano mete commits ajenos en su rama. Con varios PR en fila,
   hay que contar un ciclo de CI (unos 3 minutos) por cada uno.
9. **`back/.env` local apunta a la base real.** `index.php` y `migrar.php` lo
   cargan, así que un `php -S` o un `php sql/migrar.php` en esta máquina
   **pegan contra producción**. Para probar la API de punta a punta está el job
   de Docker del CI. Las pruebas de integración no lo leen: usan solo
   `SGSO_TEST_DB_*`.
10. **El `php.ini` de esta máquina limita la memoria a 128M**, y PHPStan se cae
    con "Child process error... reached configured PHP memory limit". No es el
    código: `vendor/bin/phpstan analyse --memory-limit=1G`.
11. **Composer no descarga en esta máquina**: `curl error 60 ... unable to get
    local issuer certificate`. Algo intercepta TLS (antivirus o firewall). Con
    el `vendor/` que ya está alcanza para las pruebas, pero puede quedar una
    versión atrás de `composer.lock`. En ese caso manda el CI.
12. **Playwright no es dependencia del proyecto**, y `npm ci` lo borra. Después
    de cada `npm ci`: `npm install --no-save playwright` (y la primera vez,
    `npx playwright install chromium`).
13. **En Windows, `ln -sfn` copia en vez de enlazar.** Después de cada build hay
    que volver a copiar `dist/` a la carpeta que sirve `http-server`, o las
    suites prueban el build anterior.
14. **`gh pr merge --match-head-commit` pide el SHA completo.** Con uno corto
    falla, sin mergear nada ("Could not coerce value").

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
