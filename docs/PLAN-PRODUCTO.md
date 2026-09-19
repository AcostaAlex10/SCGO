# Plan de producto de SCGO

Todo lo que falta para que SCGO se pueda entregar y cobrar a Triwe, ordenado por
prioridad.

- **Actualizado:** 2026-09-19
- **Base:** `main` @ `d326aba`
- **Punto de partida:** [ADR-001](adr/ADR-001-stack.md) está completo. El backend ya
  tiene la base que le faltaba: Composer, 466 pruebas, PHPStan nivel 5, tabla de
  rutas y CI con cuatro jobs en verde (tres obligatorios; el de Docker es B-10).
- **Alcance:** lo que el sistema tiene que hacer está en
  [REQUERIMIENTOS.md](REQUERIMIENTOS.md). Este plan dice qué falta para llegar ahí
  con calidad de producto.

Sale de una auditoría del código, no de la memoria. Cada ítem dice dónde está el
problema, por qué importa para un producto pago y cuánto cuesta.

**Tamaños:** **S** = menos de medio día · **M** = uno o dos días · **L** = una semana o más.

**Quién:** *Claude* decide, escribe lo delicado (seguridad, diseño, migraciones) y
revisa. *Codex* escribe el código de volumen con un spec cerrado. *Grupo* es una
decisión de ustedes, no de código.

| Prioridad | Significa |
|---|---|
| **P0** | Bloquea mostrarle el sistema a Triwe. Va primero. |
| **P1** | Hace falta antes de cobrar. |
| **P2** | Mejora real, pero se puede entregar sin esto. |

---

## 0. Decisiones

| # | Decisión | Estado | Qué destraba |
|---|---|---|---|
| **DEC-01** | Nombre del producto | **Resuelta:** SCGO, Sistema de Control y Gestión de Obras de construcción. El namespace `Sgso\` del código queda como nombre interno: renombrarlo no aporta nada al cliente. | E-05 |
| **DEC-02** | Modelo comercial | **Parcial:** el cliente es Triwe. Falta decidir si después se vende a otras constructoras. Recomendación: entregar a Triwe primero y decidir el multicliente **antes** de que aparezca un segundo cliente, porque migrar el modelo de datos después sale más caro. | E-01 a E-03 |
| **DEC-03** | RF07 y RF16: documentos como archivo o como enlace | Abierta. Recomendación: **archivos**, en almacenamiento compatible con S3. Triwe va a querer subir el PDF del plano, no pegar un enlace; y además resuelve de raíz el XSS de A-01. | D-01 |
| **DEC-04** | Hosting pago | Abierta. Recomendación: Render sin reposo y una base con respaldos automáticos. Es lo único que hace cumplir RNF03 y RNF07. | B-01 |
| **DEC-05** | Cómo se factura | Abierta. Condición fiscal y factura electrónica ante ARCA: consultarlo con un contador antes del primer cobro. | E-03, E-04 |
| **DEC-06** | La rama `TP1-plan-de-testing` | **Resuelta:** borrada. Estaba vacía y el trabajo no correspondía a este repositorio. | — |
| **DEC-07** | ¿El repositorio es público o privado? | **Resuelta:** sigue **público**, renombrado a `SCGO`. En GitHub Free la protección de `main` solo funciona en repositorios públicos, y se prefirió conservarla. La consecuencia es que el código es visible: **ningún dato de Triwe ni ninguna credencial puede entrar al repositorio**, lo que vuelve más importantes A-02 y A-08. Los datos de producción que se publicaron antes siguen en el historial; son de cuentas del equipo. | E-04 |

---

## A. Seguridad — antes de mostrarle el sistema a Triwe

| # | P | Problema | Dónde | Tamaño | Quién |
|---|---|---|---|---|---|
| **A-01** | **P0** | **Hecho en el PR #10.** **XSS almacenado por enlaces de documentos. Confirmado.** `FILTER_VALIDATE_URL` acepta `javascript://x%0Aalert(1)`, y el front lo muestra como `<a href>`. Cualquier rol que carga documentos, incluido Personal Técnico, puede plantar un enlace que ejecuta código en la sesión de quien le haga clic. Como el token vive en `localStorage`, eso es robo de cuenta. El simulador ni siquiera valida la URL. | `DocumentoController.php:45`, `DocumentacionPage.tsx:75`, `mock/servidor.ts:1209` | S | Claude |
| **A-02** | **P0** | **Hecho en el PR #10.** **El secreto de los tokens tiene un valor por defecto.** Si `JWT_SECRET` falta, la API firma con `cambiar_esta_clave` y cualquiera puede fabricarse un token de administrador. Tiene que fallar cerrado: sin secreto, o con uno corto, no arranca. | `public/index.php` | S | Claude |
| **A-03** | **P0** | **Hecho en el PR #11.** **Sin límite de intentos de login.** Ahora, a partir del quinto fallo seguido, cada intento exige una espera que se duplica (de 1 a 15 minutos). El límite es por cuenta; el límite por IP quedó como A-12. En el mismo PR se cerraron dos filtraciones del login: la cuenta inactiva se delataba sin la contraseña, y el tiempo de respuesta revelaba qué emails existían. | `AuthController::login` | M | Claude |
| **A-04** | **P0** | **Hecho en el PR #10.** **Sin manejo global de errores.** No hay `set_exception_handler` y la imagen de Docker no carga un `php.ini`, así que PHP muestra los errores. La base se conecta antes de rutear y sin `try`: si Aiven falla, el cliente ve un error crudo con la ruta interna y el host y usuario de la base. Solución: `php.ini-production`, un manejador que registre el error y conteste JSON genérico, y probar la caída de la base. | `Dockerfile`, `index.php`, `Database.php` | S | Claude |
| **A-05** | P1 | **Hecho en el PR #12.** **Contraseñas de 6 caracteres como mínimo.** Ahora el mínimo es de 10 y se rechazan las más usadas, en `Sgso\Seguridad\PoliticaContrasena`. No se exigen mayúsculas ni símbolos a propósito: esa regla empuja a contraseñas cortas y previsibles. | `AuthController.php` | S | Claude |
| **A-06** | P1 | **Hecho en el PR #13.** **`react-router` 7.13.0 con avisos de severidad alta.** Doce avisos; la mayoría del modo framework o SSR, pero dos open redirects vía `<Link>` y `useNavigate` sí nos tocaban. Pasó a 7.18.4, y con las herramientas de build al día `npm audit` quedó en cero. | `FRONT/package.json` | S | Claude |
| **A-07** | P1 | **Hecho en el PR #12.** **Sin headers de seguridad.** El front manda CSP, HSTS, `Permissions-Policy`, `X-Content-Type-Options` y `Referrer-Policy`; la API, la CSP más cerrada que existe (`default-src 'none'`) más las otras dos. El CI falla si alguno desaparece. | `FRONT/vercel.json`, API | S | Claude |
| **A-08** | P1 | **Hecho en el PR #10.** **Sin `.dockerignore`.** El `Dockerfile` copia todo `back/`: si alguien construye la imagen en local, se lleva su `.env` adentro. | `back/` | S | Claude |
| **A-09** | P2 | **Token en `localStorage`.** Es lo que vuelve grave cualquier XSS. Con A-01 y A-07 el riesgo baja mucho; pasarlo a cookie `httpOnly` cambia CORS y exige protección CSRF. | `auth/session.ts` | M | Claude |
| **A-10** | P1 | **Hecho en el PR #13.** **Auditoría de dependencias en CI.** En el front, el CI falla por un aviso alto o crítico en las dependencias de producción; las 134 de desarrollo no lo frenan, para que un aviso en una herramienta no deje sin mergear ningún PR. En el back se auditan todas: no hay de producción y las 28 de desarrollo son de PHPUnit y PHPStan. Lo demás, y las actions, lo vigila Dependabot, agrupado por semana. | `ci.yml`, `dependabot.yml` | S | Claude |
| **A-12** | P2 | **Límite de intentos por IP.** Detrás del proxy de Render todas las conexiones llegan con la misma IP de origen: limitar por ella bloquearía a todos los usuarios a la vez. Primero hay que confirmar qué cabecera agrega Render con la IP real, y que el cliente no la pueda falsificar. | `AuthController::login` | S | Claude |
| **A-13** | P1 | **Hecho en el PR #12.** **`/auth/olvide` sin límite.** Como mucho un correo cada 5 minutos por cuenta. El límite es la condición del mismo UPDATE que guarda el token: preguntar primero y escribir después dejaba pasar juntos a veinte pedidos simultáneos, que es justo el ataque. Pasado el límite responde lo mismo de siempre, sin mandar el correo: un 429 delataría qué emails tienen cuenta. | `AuthController::olvide` | S | Claude |
| **A-11** | P1 | **Pentest antes de la entrega.** Las skills de Strix están instaladas y generan el informe que suele pedir un cliente. Necesita Docker, pendiente en esta máquina. | — | M | Claude |

---

## B. Operación

| # | P | Problema | Tamaño | Quién |
|---|---|---|---|---|
| **B-01** | **P0** | **Plan pago y respaldos** (DEC-04). Respaldos automáticos con retención conocida y **una restauración probada al menos una vez**. Es lo que hace cumplir RNF03 (hoy la primera consulta tarda cerca de un minuto) y RNF07 (respaldo diario). | M | Grupo + Claude |
| **B-02** | P1 | **Staging de verdad.** `testing` es una demo con datos simulados; falta un entorno con backend y base propios para probar antes de producción. | M | Claude |
| **B-03** | P1 | **Migraciones versionadas.** Hoy son scripts sueltos que alguien tiene que acordarse de correr. Hace falta una tabla `schema_migrations` y un comando que aplique las pendientes en cada deploy. | M | Claude |
| **B-04** | P1 | **Logs y monitoreo.** Errores a un servicio tipo Sentry y un chequeo externo de disponibilidad. | M | Claude |
| **B-05** | P1 | **Hecho en el PR #21.** **`/api/health` no revisaba la base.** Ahora ejecuta `SELECT 1` y contesta `{"status":"ok","db":"ok"}` solo si la base devuelve 1. Si falla, el manejador global responde el 500 genérico con referencia, igual que con la base sin conexión. | S | Claude |
| **B-06** | **P0** | **`main` protegida.** **Hecho:** ruleset con PR obligatorio y los tres chequeos del CI. Falta, opcional, que Render espere al CI antes de desplegar. | S | Alex |
| **B-07** | P2 | **PHP 8.3 → 8.4.** 8.3 terminó su soporte activo en 12/2025. | S | Codex |
| **B-09** | P2 | **Hecho en el PR #13.** **Actions del CI desactualizadas.** Todas en su última versión mayor, leídos los cambios de cada una. De paso el CI compilaba con Node 20, sin soporte desde abril de 2026: ahora la versión sale de `engines` en `package.json`, la misma que usa Vercel. | S | Claude |
| **B-10** | P1 | **El chequeo de Docker no es obligatorio.** El ruleset de `main` exige Backend, Frontend y Playwright, pero no "Imagen Docker (arranque seguro)", que es donde se prueba que la API falla cerrado, no filtra detalles y manda los headers. Se agrega en Settings → Rules. | S | Alex |
| **B-08** | P1 | **Un solo administrador activo.** Si se pierde esa cuenta, nadie gestiona usuarios. Hace falta un segundo administrador (ver [OPERACION.md](OPERACION.md)). | S | Grupo |

---

## C. Calidad del código

| # | P | Problema | Tamaño | Quién |
|---|---|---|---|---|
| **C-01** | P1 | **13 de 16 controladores sin pruebas de integración.** Primero los que tocan plata o stock: `MaterialObraController`, `AnalisisController` y `UsuarioController` (la regla de "siempre al menos un administrador"). | L | Codex, con specs de Claude |
| **C-02** | P1 | **Contrato de la API duplicado.** `mock/servidor.ts` (1.344 líneas) reimplementa el backend a mano y ya se desincronizó tres veces. Definir el contrato una vez (OpenAPI) y verificar las dos implementaciones contra él. | L | Claude + Codex |
| **C-03** | P1 | **Sin paginación.** No hay un solo `LIMIT` en el backend: todo listado trae la tabla entera. Afecta RNF08. | M | Codex |
| **C-04** | P2 | **Sin linter en el front**, y el paquete se sigue llamando `@figma/my-make-file`. ESLint, Prettier y renombrarlo. | S | Codex |
| **C-05** | P2 | **`ProyectoDetallePage.tsx` tiene 1.171 líneas.** Partirla por pestaña. | M | Codex |
| **C-06** | P2 | **Sin pruebas unitarias en el front.** Vitest para la lógica que no es pantalla. | M | Codex |
| **C-07** | P2 | **PHPStan de nivel 5 a 6 o más**, de a un nivel. | M | Codex |
| **C-12** | P2 | **El CI no verifica los rangos de peers.** El front instala con `--legacy-peer-deps`, así que un PR puede dejar una dependencia fuera del rango que su librería declara y el CI pasa igual. Lo mostró el PR #19: subía `date-fns` a 4.4.0 con `react-day-picker@8.10.1`, que declara `^2.28.0 \|\| ^3.0.0`. Hace falta un chequeo que lo detecte, o sacar la bandera si ya no hace falta. | S | Claude |
| **C-08** | P2 | **Ciclo de vida del reporte** a `Sgso\Reglas`. Y aclarar si `GESTION_OBRA` y `REPORTE_APROBAR` son iguales a propósito: hoy tienen los mismos roles. | S | Claude |
| **C-09** | P2 | **Normalización pendiente.** `proyecto.encargado` es texto libre; `proyecto.avance` se guarda en vez de calcularse. | M | Claude |
| **C-10** | P2 | **Dependencias del front.** Empezado en la rama `claude/ingenieria-software-nube-2ws8jg` (PR #20): al borrar dos primitivos muertos salieron `react-day-picker`, `date-fns` y `react-resizable-panels`, y el paquete bajó de 1.032,58 kB a 952,48 kB (gzip: 293,21 → 269,86). Quedan MUI, Radix y shadcn conviviendo. Importa por RNF01: la carga en obra con señal móvil. Además, dos actualizaciones que no se pueden subir sueltas: **Vite 7 junto con `@vitejs/plugin-react` 6**, que importa `vite/internal` (cerrado el PR #16), y **React 19 con `react`, `react-dom` y sus tipos juntos**, porque subir solo `react-dom` deja la aplicación en blanco (cerrado el PR #17). | M | Codex |
| **C-11** | P2 | **Código muerto.** `JsonProyectoRepository` no lo usa nadie en ejecución: es el prototipo con archivo JSON anterior a la base. En el front, la rama `claude/ingenieria-software-nube-2ws8jg` (PR #20) borró `resizable.tsx` y `calendar.tsx`; siguen sin usarse `chart`, `carousel` y `drawer`. | S | Codex |

---

## D. Funcionalidad pendiente

Cada ítem remite a un requerimiento de [REQUERIMIENTOS.md](REQUERIMIENTOS.md).

| # | P | Qué falta | Req. | Tamaño | Quién |
|---|---|---|---|---|---|
| **D-01** | P1 | **Documentos como archivos** (DEC-03): subida con límite de tamaño, tipos permitidos y enlaces firmados. | RF07, RF16 | L | Claude |
| **D-02** | P1 | **Avisos según la gravedad** de la incidencia. La gravedad ya se clasifica y el correo ya funciona; falta conectarlos. | RF26 | M | Codex |
| **D-03** | P1 | **La alerta de consumo de maquinaria no aparece en Alertas.** | RF24 | S | Codex |
| **D-04** | P2 | **Distinguir `creada` de `planificacion`**: hoy nadie asigna `creada`. | ciclo de vida | S | Codex |
| **D-05** | P1 | **Registro de cambios:** quién modificó qué y cuándo. Ninguna tabla lo guarda hoy, salvo el autor del reporte. Es trazabilidad ante un reclamo y suele pedirse en un peritaje. | — | L | Claude |
| **D-06** | P2 | **Exportar a PDF y Excel** los reportes y el avance. *A validar con Triwe.* | RF14 | M | Codex |
| **D-07** | P1 | **Uso desde el celular.** El Personal Técnico carga todo en obra: revisar cada pantalla en un celular y medir el tiempo del registro diario. | RNF01, RNF02, RNF09 | M | Claude |
| **D-08** | P2 | **Avisos por correo** de aprobaciones, rechazos y asignaciones. | — | M | Codex |
| **D-09** | P1 | **La certificación se calcula en el navegador.** El monto de RF15 sale del porcentaje de avance en `ProyectoDetallePage`. Un cálculo con impacto económico tiene que vivir en el backend, con pruebas. | RF15 | M | Claude |
| **D-10** | — | **Consulta de lotes a la municipalidad.** Aparece en el diagrama de contexto del relevamiento, sin requerimiento asociado. *Confirmar con Triwe si se necesita.* | — | — | Grupo |

---

## E. Lo comercial

| # | P | Qué falta | Tamaño | Quién |
|---|---|---|---|---|
| **E-01** | P1 | **Multicliente** (DEC-02). Ninguna de las 17 tablas sabe a qué empresa pertenece un dato. Necesita su propio ADR: organización en el modelo, aislamiento por fila, un middleware que fije la empresa en cada pedido, y **pruebas de que una empresa no ve datos de otra**. | L | Claude |
| **E-02** | P1 | **Alta de cliente e importación inicial** de obras y materiales desde CSV. | M | Claude + Codex |
| **E-03** | P2 | **Suscripción y cobro** (si DEC-02 va a SaaS). | L | Claude |
| **E-04** | P1 | **Legal.** Términos y condiciones, política de privacidad, Ley 25.326 de protección de datos personales (con la inscripción de la base ante la AAIP) y acuerdo de tratamiento de datos con Triwe. | M | Grupo, con asesoramiento |
| **E-05** | P1 | **Identidad de SCGO.** Dominio propio, correos desde ese dominio (Brevo con SPF y DKIM, para que no caigan en spam) y una página de presentación. | M | Grupo + Claude |
| **E-06** | P2 | **Soporte.** Manual de usuario por rol, canal de soporte y tiempos de respuesta comprometidos. | M | Grupo |
| **E-07** | P1 | **Entrega a Triwe.** Demo con datos creíbles, el informe del pentest (A-11) y respuestas a su cuestionario de seguridad. | M | Grupo + Claude |

---

## F. Documentación

| # | Qué | Estado |
|---|---|---|
| **F-01** | Separar lo académico de la documentación de producto | **Hecho** en el PR #8 |
| **F-02** | Corregir el diagrama de estados y las afirmaciones desactualizadas sobre pruebas | **Hecho** en el PR #8 |
| **F-03** | Recuperar los requerimientos: el PDF del TP2 se había borrado y el repositorio no tenía ninguna copia de los RNF | **Hecho**: [REQUERIMIENTOS.md](REQUERIMIENTOS.md) |

---

## Orden de ataque

### Ola 1 — Cerrar agujeros

1. ~~**B-06:** proteger `main`.~~ Hecho.
2. ~~**A-01, A-02 y A-04**~~ (PR #10) y ~~**A-03**~~ (PR #11). Hechos, cada uno
   con una prueba que falló antes del arreglo.
3. ~~**A-05, A-07 y A-13**~~ (PR #12) y ~~**A-06, A-10 y B-09**~~ (PR #13). A-08 se
   hizo en el PR #10. Falta **B-10**, que es un cambio de configuración en GitHub.

### Ola 2 — Producción que aguante

6. **DEC-04**, después **B-01** (plan pago y restauración probada).
7. **B-03** (migraciones), **B-04** (monitoreo), ~~**B-05**~~ (health con base, PR #21) y **B-02** (staging).

### Ola 3 — Calidad y funcionalidad, en paralelo

8. **C-01** (pruebas de controladores) y **C-03** (paginación), por Codex con specs cerrados.
9. **D-03, D-02, D-04 y D-09**: funcionalidad chica y ya especificada.
10. **DEC-03**, después **D-01** (archivos) y **D-05** (registro de cambios).
11. **D-07:** SCGO usable desde el celular.
12. **C-02:** el contrato OpenAPI, que corta de raíz el problema del simulador duplicado.

### Ola 4 — Entrega

13. **DEC-02 y DEC-05.**
14. **A-11** (pentest), **E-04, E-05 y E-07** para la entrega a Triwe.
15. Si DEC-02 va a varios clientes: el ADR de multicliente y **E-01**, después **E-02**.

---

## Qué cuenta como "hecho"

Está en [CONTRIBUTING.md](../CONTRIBUTING.md). En corto: una prueba que falla antes
del arreglo, CI en verde, el simulador al día y el número de PR anotado acá.

---

## Registro

| Fecha | Cambio |
|---|---|
| 2026-09-16 | Primera versión, a partir de la auditoría del código. |
| 2026-09-16 | Resueltas DEC-01 y DEC-06; agregadas DEC-07, C-11, D-09 y D-10; cerrado el bloque F. Las decisiones pasan a `DEC-xx` para no confundirse con los ítems del bloque D. |
| 2026-09-16 | B-06 hecho: `main` protegida con un ruleset. DEC-07 resuelta: el repositorio sigue público y se renombra a `SCGO`. |
| 2026-09-16 | A-01, A-02, A-04 y A-08 hechos (PR #10). A-03 hecho (PR #11); agregados A-12 y A-13. Con eso no queda ningún P0 de seguridad abierto. |
| 2026-09-17 | A-05, A-07 y A-13 hechos (PR #12). De seguridad quedan A-06, A-09, A-10, A-11 y A-12, ninguno P0. |
| 2026-09-19 | A-06, A-10 y B-09 hechos (PR #13); agregado B-10. Fuera el mapa sin usar y el PDF del TP, que tenía datos personales. De seguridad quedan A-09, A-11 y A-12. |
| 2026-09-19 | Triados los seis PR de Dependabot, en `claude/ingenieria-software-nube-2ws8jg` (PR #20): se recomiendan #14 y #15; #16 y #17 se cierran (necesitan Vite 7 y React 19, cada uno su propia tarea); #18 y #19 quedan sin objeto al borrar dos primitivos de shadcn que no usaba nadie. Avance parcial de C-10 y C-11; agregado C-12. |
| 2026-09-19 | Mergeados #20 y #14; cerrados #16 a #19, cada uno con su motivo. C-10 anota Vite 7 y React 19 como subidas acopladas. B-05 hecho (PR #21). |
