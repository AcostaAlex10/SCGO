# Traspaso: cómo retomar SCGO

Este archivo existe porque las sesiones locales de Claude Code se pierden con
cada corte de luz o reinicio brusco. **Lo que no está acá ni en el repositorio,
se perdió.** Una sesión nueva empieza leyendo esto.

- **Actualizado:** 2026-10-21, después de A-18 (#73), C-13 (#72), D-16 (#64) y
  D-22 (#74)
- **Base:** `main` @ `bcd75f2`, CI en verde. **La base de Aiven volvió**:
  `/api/health` responde `{"status":"ok","db":"ok"}`. **Sigue caído el correo**
  (Brevo, sección 3)
- **Demo de testers:** se publica desde `testing` (decisión del equipo, 2026-09-23).
  Desde el #65 tiene los seis arreglos del informe

---

## 1. Qué leer, y en qué orden

| Archivo | Para qué |
|---|---|
| Este archivo | Dónde quedó todo y qué hay en vuelo **ahora** |
| [CONTRIBUTING.md](../CONTRIBUTING.md) | Cómo se trabaja: ramas, PRs, qué cuenta como terminado |
| [docs/PROCESO.md](PROCESO.md) | Quién hace qué: Claude orquesta, cinco agentes por rol, qué necesita a Alex antes |
| [docs/PLAN-PRODUCTO.md](PLAN-PRODUCTO.md) | **El backlog real.** Todo lo que falta, con prioridad y tamaño |
| [docs/REQUERIMIENTOS.md](REQUERIMIENTOS.md) | Qué tiene que hacer el sistema (RF y RNF) |
| [docs/ARQUITECTURA.md](ARQUITECTURA.md) | Cómo está construido |
| [docs/STACK.md](STACK.md) | El stack en una página, con versiones |
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
| #34, #35 | Cierre de sesión; DEC-04 postergada; B-10 y el entorno de Pages, hechos |
| #36, #37 | C-11: fuera 36 archivos muertos del front, 37 dependencias sin uso y `JsonProyectoRepository`; nuevo `docs/STACK.md` |
| #39, #41, #42 | C-01: 108 pruebas de integración, y los 16 controladores con pruebas. Encontraron A-14, A-15, C-13, D-11, D-12 y tres errores que se arreglaron |
| #40 | A-14 (P0): una baja, un cambio de rol o un restablecimiento cortan la sesión en el primer pedido |
| #43 | C-03: paginación a pedido con el total en `X-Total-Count`; "Ver más" en asistencias y reportes |
| #44 | D-03: el consumo anómalo de maquinaria llega a Alertas; agregado D-13 |
| #45 | A-16: `Mailer` y `Geocoder` vuelven a verificar el certificado TLS |
| #46 | D-02: una incidencia alta o media avisa por correo; RF26 cumplido |
| #47 | D-04: una obra nueva arranca `creada` |
| #48 | D-09: la certificación la calcula la API; RF15 cumplido |
| #49 | Este archivo, al cerrar la sesión en la nube del 2026-09-27 |
| #50 | A-17: un correo que no sale deja el motivo en el log de Render, y `/auth/olvide` dice qué hizo |
| #61 | Vite 8.3.4 con `@vitejs/plugin-react` 5.2.0; reemplazó al #59 de Dependabot |
| #62 | D-14 (DEF-05): un consumo que supera lo que queda asignado se rechaza con 422, con `Sgso\Reglas\StockMaterial` |
| #63 | D-15 (DEF-01, 02, 03 y 06): textos del CU1 y el CU22, "Obligatorio" por campo, confirmar al cancelar el alta, "Cancelar" en Materiales |
| #65 | **En `testing`:** los seis arreglos del informe, adaptados al código de la demo, y `GUIA-TESTERS.md` al día |
| #66 | Este archivo, para seguir en una sesión local |
| #67 | Este archivo, después de armar el entorno local; Selenium del Grupo 9 en Edge, 9/10 |
| #68 | D-11: una falla de maquinaria se marca resuelta o se reabre sin borrarla; RF27 cumplido |
| #70 | Requerimientos contrastados con el relevamiento: RF13 pasa a parcial, RF29 a RF33 propuestos, DEC-08 y D-17 a D-21 |
| #71 | Este archivo, con el #68 y el #70 |
| #72 | C-13: una fecha imposible da 422 y no 500, con una sola regla, `Sgso\Reglas\Fecha::esValida`, en los 11 controladores que reciben fechas. La fecha de inicio de la obra ahora se valida, y Documento e ItemExcedente ya no cambian en silencio una fecha mala por la de hoy. 14 pruebas de integración nuevas (821 pruebas en CI, sin salteadas) |
| #73 | A-18 (P0): el aviso de php.net del 2026-10-21 trae dos RCE críticas (GHSA-w8hw-w9fx-j79w y GHSA-6wmf-4rv5-p3rp), corregidas en 8.3.36; el peor caso es Apache con mod_php, que es lo que usa `back/Dockerfile`. El `Dockerfile` corta el build si PHP no tiene el parche (mínimo por rama: 8.2.35, 8.3.36, 8.4.27, 8.5.12) y apaga `session.upload_progress`. Ya desplegado en Render |
| #64 | D-16 (DEF-04): la lista de consumos se guarda y se envía junta. Con él quedan atendidos en `main` los seis defectos del informe del Grupo 9 |
| #74 | D-22: en Usuarios, los dados de baja van a una sección plegable propia con "Reactivar", y dar de baja pide confirmación. No se borra de verdad: `reporte.id_usuario` (`fk_reporte_usuario`, la única FK hacia `usuario`) no tiene `ON DELETE` (RESTRICT), y el `DELETE` falla con 1451 para cualquier autor de un reporte |

**No queda ningún P0 de seguridad abierto.** De seguridad quedan A-09, A-11, A-12
y A-15, ninguno bloqueante.

---

## 3. Qué hay en vuelo ahora mismo

### La base volvió; el correo sigue caído

La base de Aiven volvió y el host no cambió: `/api/health` responde
`{"status":"ok","db":"ok"}`. El PHP parcheado del #73 ya corre en producción
(deploy automático de `e2bb7b2`, Live, sin limpiar caché). El freno corre en cada build: si alguno falla ahí, se redespliega con "Clear build cache & deploy".

**Brevo sigue caído, y solo alguien del equipo lo arregla.** Los logs de Render
dicen `Brevo contestó "HTTP/1.1 401 Unauthorized": unauthorized API Key is not
enabled`. Hay que reactivar o generar la clave en Brevo (SMTP & API → API Keys)
y ponerla en `BREVO_API_KEY` en Render → Environment. Después puede aparecer
"unrecognised IP address" (Security → Authorised IPs) o un remitente sin
verificar. Afecta la recuperación de contraseña y los avisos de incidencias
(D-02).

**Mientras Brevo no ande, para entrar a producción sin correo:** `php sql/seed.php`
desde `back/`, con `SEED_ADMIN_PASSWORD` en el entorno (y `SEED_ADMIN_EMAIL`, que es opcional:
por defecto `admin@sgso.com`), crea un admin. Con un email que ya existe
(`ON DUPLICATE KEY UPDATE contrasena, activo`) le cambia la clave y lo reactiva,
pero **no le cambia el rol**: para tener un admin hay que usar un email nuevo o
uno que ya sea admin. **Pega contra producción**, porque `back/.env` apunta ahí
(trampa 9). Las cuentas de prueba se dan de baja desde Usuarios al terminar.

**Pendiente chico: el renombre a SCGO del correo de recuperación.** El asunto
todavía dice "Recuperá tu contraseña - SGSO" (`AuthController.php`, que también
titula el cuerpo con SGSO), y el remitente de `Mailer.php` se llama `SGSO`. No
tiene ítem en el plan.

Esto no se arregla con código, y **ninguna credencial pasa por el chat ni por el
repositorio**.

### PR abiertos

- **#69 (`claude/practicas-cmmi`), borrador: marco de proceso basado en CMMI.**
  Sin código: criterios de aceptación copiados del caso de uso y el RF en cada
  PR (`CONTRIBUTING.md` §2), una plantilla de PR con la lista de cierre, un
  registro de riesgos en `OPERACION.md` §8 (todos a cargo de Alex) y
  `docs/PROCESO.md`. **Cambia la forma de trabajar:** la sesión principal de
  Claude orquesta y delega en cinco agentes por rol (`.claude/agents/`), cada uno
  con su modelo y su nivel de autonomía.
- **#23 (B-03, migraciones versionadas).** Quedó atrás de `main` y hay que
  rebasearlo (trampas 8 y 19). **No se mergea hasta que alguien del equipo corra
  las migraciones en Aiven**: el PR saca el `CREATE TABLE` que creaba
  `intento_login` sola en cada arranque.

  ```bash
  php back/sql/migrar.php --estado   # informa, no toca nada
  php back/sql/migrar.php            # registra las versiones base y aplica 0004
  ```

  Con las credenciales de la base, desde la máquina de alguien del equipo.
- **Dependabot:** #54 (`react-is` 19), #58 (menores del front) y #60
  (`lucide-react` 1.57) están en verde; #51 (`phpstan` 2.3.1) falla en Backend
  (PHPStan + PHPUnit) y #55 (`react-router` 8) en Playwright (`gh pr checks`,
  2026-10-21). Antes de mergear cualquiera, la trampa 20.

### El informe de testing del Grupo 9

Los testers (repositorio `RybergB/Grupo9_CentralDeTesting`, TP1 del 2026-10-02)
encontraron seis defectos en la demo. Están todos arreglados en `main`
(DEF-04 con el #64) y **los seis están en `testing`** desde el #65:

| Defecto | Qué era | En `main` | En la demo |
|---|---|---|---|
| DEF-01 | El aviso al registrar una obra no decía "Obra registrada con éxito" | #63 | #65 |
| DEF-02 | Un campo vacío mostraba el aviso del navegador, no "Obligatorio" | #63 | #65 |
| DEF-03 | Cerrar el alta con datos cargados no pedía confirmación | #63 | #65 |
| DEF-04 | No había forma de enviar la lista de consumos del día junta | #64 | #65 |
| DEF-05 | Un consumo mayor a lo asignado se guardaba igual | #62 | #65 |
| DEF-06 | Materiales no tenía botón "Cancelar" | #63 | #65 |

En `testing` no hay CI ni `tsconfig`. Se verificó con un script de Playwright
que repite lo que verifican `tests/test_selenium_3.py` y
`tests/test_materiales.py` del grupo: 14/14 con los arreglos, y falla sin
ellos.

**Las pruebas de Selenium del grupo se corrieron en Edge el 2026-10-21**, contra
la demo publicada: 9/10. La que falla, `test_cancelar_solicita_confirmacion`, es
un error de la prueba y no de la demo: el cartel aparece con el texto pedido y
esa comprobación pasa, pero después la prueba saca una captura con el cartel
abierto, Edge la rechaza y cierra el cartel, y el `dismiss()` siguiente da
`NoAlertPresentException`. Conviene avisarle al grupo.

**Para verificar en producción.** La base ya anda, así que de la 3 en adelante se
pueden probar ya; la 1 y la 2 esperan a Brevo:

1. **"Olvidé mi contraseña"**, una vez, con una cuenta propia: el correo tiene
   que llegar. Si no llega, el log de Render dice por qué (A-17).
2. **Una incidencia de gravedad alta** en una obra de prueba: el correo tiene
   que llegarles a los Gerentes y al Personal Administrativo (D-02, #46).
3. **Un consumo mayor a lo que queda asignado** tiene que dar "Stock
   insuficiente: quedan X" (#62).
4. **El login de Vercel con la consola limpia**, después de Vite 8 (#61) y del
   #63, que tocaron el front.
5. **Marcar una falla de maquinaria como resuelta** y reabrirla (D-11, #68): el
   contador de fallas abiertas de la máquina tiene que bajar y volver a subir, y
   la falla seguir en el historial.
6. **La lista de consumos (#64)**: una que entra entera, y otra con un material
   sin stock que rechaza las dos líneas.
7. **La pantalla de usuarios (#74)**: los dados de baja en su sección, "Reactivar"
   y la confirmación al dar de baja.

### Los requerimientos, contrastados con el relevamiento (#70)

Se releyeron los cuatro documentos del relevamiento (TP1, plan de entrevistas,
TP2 y estudio de viabilidad) contra `REQUERIMIENTOS.md` y el código. Salieron
tres cosas:

- **RF13 pasa a parcial.** El "ejecutado" del análisis es presupuesto por avance,
  una estimación: el sistema no registra ningún gasto real (desvío 3).
- **El relevamiento quedó corto.** Solo se entrevistó a la dirección técnica;
  administración y el supervisor de campo quedaron sin fecha. Y la comparación de
  proveedores, que en el TP1 era una de las dos funciones principales, se perdió
  sin que nadie lo decidiera.
- **RF29 a RF33 quedan *propuestos*:** gastos reales por obra, pedidos de
  material, cotizaciones de proveedores, horas aprobadas y pendientes. Ninguno
  está aceptado. Esperan **DEC-08**, y en el plan son D-17 a D-21; todos piden
  migraciones.

**Decisiones pendientes, todas del equipo:**

- **Obras viejas en `planificacion` sin planificación.** Desde D-04 (#47) toda
  obra nueva arranca `creada`; las que ya existían no se tocaron. Pasarlas a
  `creada` es una migración de datos: la consulta está en el PR #47.
- **D-13:** la alerta de consumo compara cada máquina consigo misma. Hace falta
  guardar un consumo esperado por máquina (migración) y decidir una ventana de
  fechas.
- **D-12:** la asistencia duplicada depende de si hay turnos.
- **DEC-04** sigue postergada hasta que se cierre la posible venta. De ella
  dependen B-01 y B-02.
- **DEC-08:** si Triwe quiere RF29 a RF33. Antes conviene hacer la entrevista a
  administración que quedó pendiente, porque RF29 a RF32 son sus circuitos.

**Configuración del equipo:**

- **B-04, pendiente:** poner `SENTRY_DSN` en Render → Environment y crear el
  monitor externo con los valores de `OPERACION.md` §5. **Antes del monitor:**
  el workspace de Render tiene **otro servicio web gratuito**, `sgso-backend`
  (Node, de otro repositorio). Las 750 horas del mes son por workspace: con el
  monitor despertando producción, los dos juntos las agotan y Render suspende
  todo. Hay que apagar o mover ese servicio primero.
- **Render:** el servicio de la API no tiene Health Check Path. Conviene ponerle
  `/api/health`, que ya comprueba la base (B-05). El deploy automático ya espera
  al CI (`autoDeployTrigger: checksPass`), que era lo opcional de B-06.
- **GitHub:** la app de Claude no está instalada en el repositorio, así que una
  sesión no recibe los eventos de sus PR (CI, comentarios) y tiene que
  consultarlos. Se instala desde https://github.com/apps/claude.
- **Vercel:** el conector de esta cuenta necesita re-autenticarse para el scope
  `acostaalex10s-projects` (trampa 28).
- **A-11:** la prueba gratuita de Strix terminó; el pentest queda sin
  herramienta hasta decidir cómo hacerlo.

**Lo siguiente del plan.** C-13 (#72) y D-11 (#68) ya están. Quedan:

- **A-15:** RF20 por lista negra. Cada importe nuevo hay que acordarse de
  quitarlo, como pasó con `certificado` en el #48.
- **DEC-03 → D-01:** documentos como archivos. DEC-03 es una decisión del equipo.
- **D-05** (registro de cambios): pide una tabla nueva, o sea una migración, así
  que se pregunta antes.
- **D-12** y **D-13**, que esperan decisiones del equipo (ver arriba).
- **El asunto "SGSO"** del correo de recuperación (ver arriba).

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
    merge los demás PR tienen conflicto ahí. En `CONTRIBUTING.md`, la tabla de
    ramas abiertas queda **solo con la fila de la propia rama**; la rama ya
    mergeada pasa al historial en un PR posterior. El registro de
    `PLAN-PRODUCTO.md` choca igual: ahí se dejan **las dos entradas**, en orden
    de merge.
20. **Un PR de Dependabot en verde puede ser vacío.** Si nadie importa el
    paquete, el CI pasa aunque la versión nueva rompa todo: pasó con el #19, el
    #26 y el #30. Antes de mergear uno, `git grep` del paquete en `FRONT/src`.
    Si no aparece, la respuesta es sacar la dependencia, no subirla.
21. **Para probar un DSN de Sentry desde esta máquina, PHP no sirve.** No puede
    abrir HTTPS por el antivirus (trampa 11), y además el transporte de
    `Sgso\Monitoreo\Sentry` se traga los errores a propósito, así que un
    `true` no prueba nada. Lo que funciona es armar el evento con esa clase,
    pasándole un transporte que lo guarde, y mandarlo con `curl.exe` de
    Windows, que sí pasa el antivirus: así se hizo el 2026-09-26. La prueba de
    verdad es verlo aparecer en Sentry → Issues.
22. **En la nube, Composer no baja `phpstan/phpstan`.** El paquete es solo
    `dist` y el proxy del entorno no deja bajar el zip de la API de GitHub
    ("Could not authenticate against github.com"). Se resuelve sembrando la
    caché: `git fetch` del commit exacto que pide `composer.lock`, `git archive
    --prefix=phpstan-phpstan-<sha corto>/` a un zip, y ponerlo en
    `~/.cache/composer/files/phpstan/phpstan/` con el nombre que espera Composer
    (el `sha1` de la URL del zip). Después, `composer install --prefer-source`:
    el resto de los paquetes se clona con git, que el proxy sí deja pasar.
    Además hace falta `COMPOSER_ALLOW_SUPERUSER=1`.
23. **En la nube no hay Docker: MariaDB va por `apt`.** Con `mariadb-server`
    instalado, `mkdir -p /run/mysqld && chown mysql:mysql /run/mysqld` y
    `mysqld_safe --user=mysql --bind-address=127.0.0.1 --port=3306` en segundo
    plano. **Se muere cuando el contenedor se duerme**, igual que el servidor de
    la demo en `:8123`: si las pruebas de integración fallan todas juntas, lo
    primero es levantarlos de nuevo.
24. **`pkill -f` y `pgrep -f` se encuentran a sí mismos** en la nube: el patrón
    aparece en su propia línea de comandos y matan su propio shell. Se usa el
    truco del corchete (`pkill -f "[p]hp -S 127.0.0.1:8099"`), y matar y
    levantar un servidor van en llamadas separadas.
25. **Nunca reconstruir un SHA a mano.** La trampa 14 vale también para la
    herramienta de merge: con un SHA completo armado de memoria respondió 409
    ("Head branch was modified"). El SHA se lee con `git rev-parse` de la rama
    remota recién traída.
26. **Un `vendor/` enlazado entre worktrees carga el `src/` del otro.** El
    autoloader de Composer resuelve la ruta real, y `php -S` además guarda en
    caché las rutas resueltas. Cada worktree lleva su `vendor/` copiado, con
    `composer dump-autoload`, y el servidor se reinicia.
27. **Render despliega solo con el CI de `main` en verde**
    (`autoDeployTrigger: checksPass`) y solo si el merge cambió `back/`. Después
    de mergear, el deploy tarda lo que tarda ese CI, unos tres minutos: antes de
    eso, "el deploy no aparece" no es una falla.
28. **Desde la nube no se ve producción.** El proxy del entorno responde 403 a
    `onrender.com` y `vercel.app`, y el conector de Vercel responde 403 para el
    scope `acostaalex10s-projects` hasta que se re-autentique. Lo que sí
    funciona es el conector de Render, en solo lectura: `list_deploys` para ver
    que el deploy quedó "live" y `list_logs` para buscar errores.
29. **Con MySQL, `lastInsertId()` vuelve a 0 después de cualquier otra
    consulta.** Pasó en `AvanceController` (#41): el alta devolvía id 0. Se lee
    apenas termina el `INSERT`, antes de sincronizar la obra o de mandar un
    aviso.
30. **`json_encode` saca el `.0` de los float**: `100.0` sale como `100`, que
    PHP vuelve a leer como entero. En las pruebas de integración, los importes y
    porcentajes se comparan con `assertNumero` de `CasoConBase`.
31. **Un merge a `testing` puede no publicar la demo.** El 2026-10-21 el squash
    del #65 no disparó `pages-testing.yml`, aunque el workflow corre en cada
    push a `testing`. Después de cada merge ahí, mirar Actions: si en un par de
    minutos no hay run del commit nuevo, se corre el workflow a mano **sobre
    `testing`**, nunca sobre `main` (trampa 15).
32. **Una sesión en la nube no llega a la máquina local**: ni su PHP, ni
    `back/.env`, ni Edge. Para trabajo local, abrir la sesión en la máquina
    (Claude Desktop o `claude remote-control`).
33. **El repositorio del Grupo 9 no se clona entero en una carpeta de ruta
    larga.** Tiene capturas con nombres muy largos y Windows corta en 260
    caracteres: `git clone` sale con 128 y deja el checkout a medias. Se clona
    con `git -c core.longpaths=true clone ...`.
34. **Un rebase puede combinar mal una cuenta sin dar conflicto.** Pasó con el
    #70: el resumen de `REQUERIMIENTOS.md` ("N cumplidos, M parciales") quedó
    con los números de una sola rama, porque cada PR lo cambió en una línea
    distinta de la que choca. Después de cada rebase que toque estados de RF, se
    vuelve a contar la tabla. Al mergear, el repositorio **no permite merge
    automático** (`gh api repos/AcostaAlex10/SCGO -q .allow_auto_merge` da
    `false`): "mergear cuando el CI esté en verde" lo hace alguien a mano.
35. **En PowerShell 5.1, `gh pr create --body "..."` se rompe** si el texto
    tiene comillas dobles: `gh` lo recibe partido en varios argumentos ("unknown
    argument"). Se escribe el cuerpo en un archivo y se pasa con `--body-file`.
36. **Una sesión aislada en un worktree no puede operar git en otro worktree.**
    Los subagentes heredan ese aislamiento, así que hay que entrar al worktree
    (`EnterWorktree`) antes de delegar.
37. **En un worktree no hay `back/vendor` ni `FRONT/node_modules`.** Se copian del
    checkout principal: Composer no descarga (trampa 11) y `npm install` borra
    Playwright (trampa 12). Un `vendor/` copiado necesita `composer dump-autoload`
    (trampa 26).
38. **Dos agentes que comparten `C:/tmp/scgo` y el `http-server` del puerto 8123 se
    pisan las corridas de Playwright.** Uno por vez, o carpetas y puertos
    distintos.
39. **Si Render loguea `getaddrinfo for ...aivencloud.com failed: Name or service
    not known`, la base no está.** El servicio gratuito de Aiven se apagó: se
    enciende desde la consola de Aiven. Si al volver cambió el host, se actualiza
    `DB_HOST` en Render → Environment. Después, `/api/health` tiene que dar
    `{"status":"ok","db":"ok"}`.
40. **En la nube, `gh pr create` falla** con "GraphQL is not available" (403):
    el proxy solo deja la API REST. El PR se abre con `gh api
    repos/AcostaAlex10/SCGO/pulls -X POST -f base=main -f head=<rama>
    -F draft=true -f title=... -F body=@archivo.md`, y se edita con `-X PATCH`
    sobre `pulls/<n>`.

### Si la sesión corre en la nube y no en tu máquina

Las cinco suites de Playwright necesitan Chromium. En el contenedor remoto el
navegador preinstalado (`/opt/pw-browsers`, `chromium-1194`) es una compilación
más vieja que la que pide el último `playwright`. Lo más simple es instalar la
versión que le corresponde: `npm install --no-save playwright@1.56.1` con
`PLAYWRIGHT_BROWSERS_PATH=/opt/pw-browsers`.

Además, `humo.mjs` da **24/28 en la nube**: las cuatro que fallan son las de
"sin errores en consola", una por rol, por `ERR_CERT_AUTHORITY_INVALID` al
cargar Google Fonts detrás del proxy del entorno. **No es una regresión.** En el
CI de GitHub da 28/28.

**Las trampas 9, 10, 11, 13, 17, 21, 33, 35, 36, 37 y 38 son de la máquina local** (Windows,
antivirus, `back/.env`): en la nube no aplican. La 12, la 14, la 19, la 31, la 34 y la 39 valen para las dos, y de la 22 a
la 28 y la 40 son propias de la nube. En la nube tampoco hay `back/.env`, así que
no hay forma de pegarle a producción por accidente, y así tiene que seguir.

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

### Armar el entorno en una máquina local

Lo mínimo para correr los tres chequeos del CI y las suites, en este orden:

1. **`git pull` en `main`** y leer la sección 3 de este archivo.
2. **Backend:** `cd back && composer install`. Si Composer falla con `curl error
   60`, es la trampa 11. PHPStan, con `--memory-limit=1G` (trampa 10).
3. **Base de pruebas:** un MariaDB local (XAMPP, Docker o el instalador) con una
   base **cuyo nombre tenga "test"**, por ejemplo `sgso_test`. Después:

   ```bash
   export SGSO_TEST_DB_HOST=127.0.0.1 SGSO_TEST_DB_PORT=3306
   export SGSO_TEST_DB_NAME=sgso_test SGSO_TEST_DB_USER=root SGSO_TEST_DB_PASSWORD=<la tuya>
   cd back && composer test
   ```

   Las pruebas de integración leen solo esas variables. **Nunca** apuntarlas a
   Aiven, y ojo con `back/.env`, que sí apunta a producción (trampa 9).
4. **Frontend:** `cd FRONT && npm ci --legacy-peer-deps && npm run typecheck`.
5. **Playwright:** `npm install --no-save playwright` y, la primera vez,
   `npx playwright install chromium` (trampa 12).
6. **La demo, como la publica Pages**, con el bloque de arriba. En Windows,
   copiar `dist/` en vez de enlazarlo (trampa 13).

Para probar la demo de `testing` (la de los testers) se hace lo mismo desde un
worktree de esa rama: `git worktree add ../scgo-testing origin/testing`.

---

## 6. Mantener esto al día

Es la única defensa contra el próximo corte de luz.

- **Al terminar cada tarea**, actualizá la sección 3 y la fecha de arriba.
- **Si algo te hizo perder tiempo**, va a la sección 4. Esa lista es el activo
  más valioso del archivo.
- **Lo que cambia de estado en el backlog** se anota en `PLAN-PRODUCTO.md`, no acá.
- **Los riesgos** se revisan en `OPERACION.md` §8: si uno pasó, se anota; si
  apareció uno nuevo, se agrega.
- **Commiteá y pusheá seguido.** Una rama sin pushear es trabajo que el próximo
  corte se lleva.
