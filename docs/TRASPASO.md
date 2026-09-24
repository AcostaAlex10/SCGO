# Traspaso: cómo retomar SCGO

Este archivo existe porque las sesiones locales de Claude Code se pierden con
cada corte de luz o reinicio brusco. **Lo que no está acá ni en el repositorio,
se perdió.** Una sesión nueva empieza leyendo esto.

- **Actualizado:** 2026-09-23, al cierre de la sesión que vació la cola de PR
- **Base:** `main` @ `f2a99c4`, CI en verde, producción desplegada y respondiendo
- **Demo de testers:** se publica desde `testing` (decisión del equipo, 2026-09-23)

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
| #22 | Este archivo, revisado después del renombre |
| #24 | B-04: el log dice qué pedido falló, y los errores van a Sentry sin SDK |
| #25 | B-02 queda bloqueado por DEC-04; el CI verifica el `connect-src` de la CSP |
| #32 | La interfaz dice SCGO; la documentación dice que la demo sale de `testing` |
| #33 | Triaje de Dependabot #26 a #30: fuera `motion` y `react-responsive-masonry`, código listo para TS 7 y recharts 3 |
| #27, #29 | Dependabot: TypeScript 7.0.2 y recharts 3.10.1 |
| #31 | **En `testing`:** guía y `HANDOFF.md` al día; la demo vuelve a publicarse desde ahí |

**No queda ningún P0 de seguridad abierto.** De seguridad quedan A-09, A-11 y
A-12, ninguno bloqueante.

---

## 3. Qué hay en vuelo ahora mismo

**Un solo PR abierto: el #23 (B-03, migraciones versionadas).** Está en verde y
al día con `main`, pero **no se mergea hasta correr las migraciones en Aiven**:
el PR saca el `CREATE TABLE` que creaba `intento_login` sola en cada arranque.

```bash
php back/sql/migrar.php --estado   # informa, no toca nada
php back/sql/migrar.php            # registra las versiones base y aplica 0004
```

Con las credenciales de Render en el entorno, desde la máquina de quien tenga
acceso. Después de eso se mergea. Cualquier merge a `main` en el medio lo deja
atrás y hay que rebasearlo (trampa 8).

**Dependabot, al día.** Los cinco PR del lunes 2026-09-21 están resueltos: #26 y
#30 cerrados (el verde era vacío: nadie importaba esos paquetes, y se sacaron en
el #33), #28 cerrado (React 19 sin `react-dom`), y #27 y #29 mergeados después
de preparar el código en el #33. El próximo lunes llegan más: ver trampa 20.

**Configuración pendiente, del equipo, no de código:**

- **Settings → Environments → github-pages: dejar solo `testing`.** Verificado
  el 2026-09-23: todavía admite `main` y `testing`, así que un disparo manual
  del workflow sobre `main` sigue pudiendo pisar la demo (trampa 15).
- **B-10: agregar "Imagen Docker (arranque seguro)" a los chequeos obligatorios**
  del ruleset de `main`. Verificado el 2026-09-23: hoy son solo Backend,
  Frontend y Playwright.
- **B-04:** poner `SENTRY_DSN` en Render → Environment y crear el monitor
  externo con los valores de `OPERACION.md` §5. Hasta entonces el envío a Sentry
  queda apagado, que es lo previsto.

**Decisiones pendientes:** si se revierte el #21 (B-05 se mergeó sin consulta: el
permiso era solo para el PR del paso 1), y DEC-04, de la que dependen B-01 y B-02.

**Lo siguiente del plan, sin decisiones pendientes:** Ola 3 de
`PLAN-PRODUCTO.md` (C-01, C-03, D-03, D-02, D-04, D-09). Conviene arrancarla
con la cola vacía, o sea después del #23: cada rama nueva choca con las abiertas
en la tabla de `CONTRIBUTING.md`.

---

## 4. Trampas que ya costaron tiempo

Cada una de estas hizo perder al menos media hora. Están acá para no repetirlas.

1. **`testing` es la rama de la demo de los testers.** Su código es el anterior
   al renombre (en pantalla dice SGSO) y GitHub Pages publica solo desde ella,
   con su propio `pages-testing.yml` y su propia `GUIA-TESTERS.md`. Se cambia
   solo para la demo o su guía, **por PR contra `testing`**, y nunca se mergea
   con `main` en ninguna dirección. Estuvo congelada en `a25da85` hasta el
   2026-09-23.
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
12. **Playwright no es dependencia del proyecto**, y `npm ci` lo borra. También
    lo borra `npm uninstall` de cualquier otro paquete. Después de cada uno:
    `npm install --no-save playwright` (y la primera vez,
    `npx playwright install chromium`).
13. **En Windows, `ln -sfn` copia en vez de enlazar.** Después de cada build hay
    que volver a copiar `dist/` a la carpeta que sirve `http-server`, o las
    suites prueban el build anterior.
14. **`gh pr merge --match-head-commit` pide el SHA completo.** Con uno corto
    falla, sin mergear nada ("Could not coerce value").
15. **Disparar a mano el workflow de Pages sobre `main` pisa la demo.** Pasó el
    2026-09-23: se lo corrió dos veces sobre `main` y los testers quedaron
    viendo otra versión, sin aviso. Cada run usa el archivo de la rama que lo
    dispara, así que `main` y `testing` tienen dos `pages-testing.yml` distintos
    (el de `testing` compila con Node 20). El entorno `github-pages` tiene que
    admitir solo `testing`.
16. **Hay dos guías de testers, a propósito.** `docs/pruebas/GUIA-TESTERS.md` en
    `main` describe la versión actual; `GUIA-TESTERS.md` en la raíz de
    `testing`, la versión de la demo, con sus cuentas de prueba. No se
    sincronizan: cada una describe su rama.
17. **En Git Bash, `git show rama:ruta` falla** con "ambiguous argument": Git
    Bash convierte la ruta como si fuera de Windows. Anteponer
    `MSYS_NO_PATHCONV=1`.
18. **La demo publicada se puede probar entera desde acá.** Las cinco suites
    aceptan `SGSO_URL` (desde el #32; antes `contrato.mjs` leía `BASE` y se iba
    en silencio a localhost): `SGSO_URL=https://acostaalex10.github.io/SCGO/`.
    Ojo: las suites de `main` prueban la versión de `main`, y la demo es la de
    `testing`, así que alguna diferencia puede ser legítima.
19. **La tabla de ramas abiertas choca en cada rebase.** Todas las ramas agregan
    su fila en el mismo lugar de `CONTRIBUTING.md`, así que después de cada
    merge los demás PR tienen conflicto ahí. Se resuelve dejando **solo la fila
    de la propia rama**, y la del PR recién mergeado se pasa al historial en un
    commit aparte. El registro de `PLAN-PRODUCTO.md` choca igual: se dejan las
    dos entradas, por fecha.
20. **Un PR de Dependabot en verde puede ser vacío.** Si nadie importa el
    paquete, el CI pasa aunque la versión nueva rompa todo: pasó con el #19, el
    #26 y el #30. Antes de mergear uno, `git grep` del paquete en `FRONT/src`.
    Si no aparece, la respuesta es sacar la dependencia, no subirla.

### Si la sesión corre en la nube y no en tu máquina

Las cinco suites de Playwright necesitan Chromium. En el contenedor remoto el
navegador preinstalado es una compilación más vieja que la que pide el paquete
`playwright` del proyecto, y la carpeta cambió de nombre entre versiones
(`chrome-linux` → `chrome-linux64`, `headless_shell` →
`chrome-headless-shell`). Se resuelve armando enlaces simbólicos con los nombres
nuevos apuntando a los binarios viejos y exportando `PLAYWRIGHT_BROWSERS_PATH`.

Además, `humo.mjs` da **20/24 en la nube**: las cuatro que fallan son
`ERR_CERT_AUTHORITY_INVALID` al cargar Google Fonts, por el proxy del entorno.
**No es una regresión.** En el CI de GitHub da todas en verde.

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
