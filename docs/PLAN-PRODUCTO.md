# Plan de producto

De proyecto académico a software que se puede cobrar.

- **Fecha:** 2026-09-16
- **Base:** `main` @ `67b479a` más la rama del PR #7 (CI y pruebas de integración)
- **Punto de partida:** [ADR-001](adr/ADR-001-stack.md) está completo. El backend ya tiene
  la base de seguridad que le faltaba: Composer, 466 pruebas, PHPStan nivel 5, tabla de
  rutas y CI con tres jobs en verde.

Este documento junta **todo** lo que falta para venderlo. Sale de una auditoría del
código hecha ese día, no de la memoria. Cada ítem dice dónde está el problema, por qué
importa para un producto pago y cuánto cuesta.

**Tamaños:** **S** = menos de medio día · **M** = uno o dos días · **L** = una semana o más.

**Quién:** *Claude* decide, escribe lo delicado (seguridad, diseño, migraciones) y
revisa. *Codex* escribe el código de volumen con un spec cerrado. *Grupo* es una decisión
de ustedes, no de código.

---

## Cómo leer las prioridades

| Prioridad | Significa |
|---|---|
| **P0** | Bloquea mostrarle el sistema a un cliente. Va primero. |
| **P1** | Hace falta antes de cobrar. |
| **P2** | Mejora real, pero se puede vender sin esto. |

---

## 0. Decisiones que necesito de ustedes

No son código, pero cada una destraba un bloque del plan. Mi recomendación va al lado.

| # | Decisión | Qué bloquea | Recomendación |
|---|---|---|---|
| **D-01** | **Nombre del producto.** El repo, la documentación y la API dicen **SGSO**. En el pedido escribiste **SCGO**. ¿Es un nombre nuevo o un error de tipeo? | Marca, dominio, textos (E-05) | Decidirlo antes de comprar el dominio. Renombrar el código no es necesario: el namespace `Sgso\` puede quedar como nombre interno. |
| **D-02** | **Modelo comercial.** ¿Un solo sistema para muchas constructoras (SaaS), o una instalación por cliente, empezando por Triwe? | Todo el bloque multicliente (E-01 a E-03) | **SaaS**, pero después del primer cliente. Instalar solo para Triwe primero valida el producto sin pagar el costo del multicliente por adelantado. ADR-001 ya advertía que migrar el modelo de datos después sale más caro, así que la decisión tiene que tomarse antes de que entre un segundo cliente. |
| **D-03** | **RF07 y RF16: documentos como archivo o como enlace.** Hoy se guardan enlaces. | D-01 del bloque funcional | **Archivos**, en almacenamiento compatible con S3. Un cliente espera subir el PDF del plano, no pegar un enlace de Drive. Además resuelve de raíz el XSS de A-01. |
| **D-04** | **Hosting pago.** Render en plan gratuito se duerme a los ~15 minutos y tarda en despertar (`DEPLOY.md`). | B-01 | Plan pago de Render sin reposo, y una base con respaldos automáticos. Un cliente que espera 30 segundos la primera carga del día no renueva. |
| **D-05** | **Cómo se factura.** Condición fiscal y factura electrónica ante ARCA. | E-03, E-04 | Consultarlo con un contador antes del primer cobro. No es tema de código. |
| **D-06** | **La rama `TP1-plan-de-testing`.** Está vacía (igual a `main`), y el TP1 es sobre el proyecto de otro grupo. | Orden del repo | Borrarla de este repositorio. |

---

## A. Seguridad — antes de cualquier demo con un cliente

| # | P | Problema | Dónde | Tamaño | Quién |
|---|---|---|---|---|---|
| **A-01** | **P0** | **XSS almacenado por enlaces de documentos. Confirmado.** `FILTER_VALIDATE_URL` acepta `javascript://x%0Aalert(1)`, y el front lo muestra como `<a href>`. Cualquier rol que carga documentos (incluido Personal Técnico) puede plantar un enlace que ejecuta código en la sesión de quien le haga clic, por ejemplo un administrador. Como el token vive en `localStorage`, eso es robo de cuenta. El simulador ni siquiera valida la URL. | `DocumentoController.php:45`, `DocumentacionPage.tsx:75`, `mock/servidor.ts:1209` | S | Claude |
| **A-02** | **P0** | **El secreto de los tokens tiene un valor por defecto.** Si `JWT_SECRET` falta en algún entorno, la API firma con `cambiar_esta_clave` y cualquiera puede fabricarse un token de administrador. Tiene que fallar cerrado: sin secreto, o con uno corto, no arranca. | `public/index.php:58` | S | Claude |
| **A-03** | **P0** | **Sin límite de intentos de login.** Se puede probar contraseñas sin freno. Hace falta un límite por IP y por cuenta, con espera creciente. | `AuthController::login` | M | Claude |
| **A-04** | **P0** | **Sin manejo global de errores.** No hay `set_exception_handler`, y la imagen de Docker no carga un `php.ini`, así que PHP usa sus valores por defecto y muestra los errores. La base se conecta antes de rutear y sin `try` (`index.php:61`): si Aiven falla, el cliente recibe un error crudo con la ruta interna del servidor y el host y usuario de la base, en vez de un 500 prolijo. Solución: `php.ini-production` en el `Dockerfile`, un manejador que registre el error y conteste JSON genérico, y probar la caída de la base. | `Dockerfile`, `index.php`, `Database.php` | S | Claude |
| **A-05** | P1 | **Contraseñas de 6 caracteres como mínimo.** Es poco para un sistema con datos de obra y costos. Subir a 10 o 12 y rechazar las más comunes. | `AuthController.php:119` y `:223` | S | Claude |
| **A-06** | P1 | **`react-router` 7.13.0 con avisos de severidad alta** (`npm audit`). La mayoría aplica al modo framework o SSR, que no usamos (acá es modo librería), pero actualizar a una versión corregida es barato y saca la alarma. | `FRONT/package.json` | S | Codex |
| **A-07** | P1 | **Sin headers de seguridad.** `vercel.json` solo tiene la reescritura de rutas. Faltan CSP (que además frena el XSS de A-01 como segunda barrera), HSTS, `frame-ancestors`, `X-Content-Type-Options` y `Referrer-Policy`, en el front y en la API. | `FRONT/vercel.json`, API | S | Claude |
| **A-08** | P1 | **Sin `.dockerignore`.** El `Dockerfile` hace `COPY .` de todo `back/`: si alguien construye la imagen en local, se lleva su `.env` adentro. | `back/` | S | Claude |
| **A-09** | P2 | **Token en `localStorage`.** Es lo que vuelve grave a cualquier XSS. Con A-01 y A-07 el riesgo baja mucho. Pasarlo a cookie `httpOnly` cambia CORS y obliga a protegerse de CSRF, así que es una decisión para después. | `auth/session.ts` | M | Claude |
| **A-10** | P1 | **Auditoría de dependencias en CI.** Agregar `composer audit`, `npm audit` y Dependabot, para que el próximo aviso aparezca solo. | `ci.yml` | S | Codex |
| **A-11** | P1 | **Pentest antes del primer cliente.** Las skills de Strix ya están instaladas y generan el informe que suele pedir un cliente en su cuestionario de seguridad. Necesita Docker, que está pendiente en esta máquina. | — | M | Claude |

---

## B. Operación — que producción aguante

| # | P | Problema | Tamaño | Quién |
|---|---|---|---|---|
| **B-01** | **P0** | **Plan pago y respaldos** (depende de D-04). Base con respaldos automáticos y retención conocida, y **una restauración probada al menos una vez**: un respaldo que nunca se restauró no sirve como respaldo. | M | Grupo + Claude |
| **B-02** | P1 | **Staging de verdad.** Hoy `testing` es una demo estática con datos simulados. Falta un entorno con backend y base propios para probar antes de que llegue a producción. | M | Claude |
| **B-03** | P1 | **Migraciones versionadas.** Hoy son scripts sueltos (`migracion-*.php`) que alguien tiene que acordarse de correr; ya hubo un "falta correr la migración" en `HANDOFF.md`. Hace falta una tabla `schema_migrations` y un comando que aplique las pendientes en cada deploy. | M | Claude |
| **B-04** | P1 | **Logs y monitoreo.** Sin registro de errores ni aviso cuando algo se cae. Errores a un servicio tipo Sentry, más un chequeo externo de disponibilidad. | M | Claude |
| **B-05** | P1 | **`/api/health` no revisa la base.** Contesta `ok` aunque la base esté lenta. Tiene que ejecutar un `SELECT 1` y reportarlo. | S | Codex |
| **B-06** | **P0** | **Deploy condicionado al CI y `main` protegida.** Hoy Render y Vercel despliegan cualquier cosa que llegue a `main`. Proteger la rama (PR obligatorio y CI en verde) y que el deploy espere al CI. Es lo que evita que un error llegue a un cliente. | S | Alex (configuración de GitHub) |
| **B-07** | P2 | **PHP 8.3 → 8.4.** 8.3 terminó su soporte activo en 12/2025 (seguridad hasta 12/2027). No es urgente, pero el cambio es de una línea con el CI de red. | S | Codex |
| **B-08** | P1 | **Un solo administrador activo** (`HANDOFF.md` §5). Si se pierde esa cuenta, nadie puede gestionar usuarios. Hace falta un segundo administrador y un procedimiento de recuperación documentado. | S | Grupo |

---

## C. Calidad del código

| # | P | Problema | Tamaño | Quién |
|---|---|---|---|---|
| **C-01** | P1 | **13 de 16 controladores sin pruebas de integración.** Hoy están cubiertos Inactividad, Proyecto (cancelación) y Reporte. Van primero los que tocan plata o stock: `MaterialObraController` (consumo con control de stock), `AnalisisController` (alertas de desvío) y `UsuarioController` (la regla de "siempre al menos un administrador"). | L | Codex, con specs de Claude |
| **C-02** | P1 | **Contrato de la API duplicado.** `mock/servidor.ts` (1.344 líneas) reimplementa el backend a mano, y ya se desincronizaron tres veces (ADR-001 §4c). Definir el contrato una sola vez (OpenAPI) y verificar las dos implementaciones contra él. | L | Claude + Codex |
| **C-03** | P1 | **Sin paginación.** No hay un solo `LIMIT` en el backend: todos los listados traen la tabla entera. Con una constructora grande, el dashboard se vuelve lento. | M | Codex |
| **C-04** | P2 | **Sin linter en el front**, y el paquete se sigue llamando `@figma/my-make-file`. Agregar ESLint y Prettier, y renombrarlo. | S | Codex |
| **C-05** | P2 | **`ProyectoDetallePage.tsx` tiene 1.171 líneas.** Partirla en componentes por pestaña. | M | Codex |
| **C-06** | P2 | **Sin pruebas unitarias en el front.** Vitest para la lógica que no es pantalla: `estadosObra.ts`, el transporte de `api.ts`. | M | Codex |
| **C-07** | P2 | **PHPStan de nivel 5 a nivel 6 o más**, de a un nivel por vez. | M | Codex |
| **C-08** | P2 | **Ciclo de vida del reporte** a `Sgso\Reglas`, como se hizo con el de la obra. Y aclarar si `GESTION_OBRA` y `REPORTE_APROBAR` son iguales a propósito: hoy tienen los mismos dos roles. | S | Claude |
| **C-09** | P2 | **Normalización pendiente.** `proyecto.encargado` es texto libre y debería apuntar a `usuario`; `proyecto.avance` se guarda en vez de calcularse desde `avance_fisico`. | M | Claude |
| **C-10** | P2 | **60 dependencias en el front**, con MUI, Radix y shadcn a la vez. Podar lo que no se usa baja el tamaño de la carga inicial, que en obra, con señal móvil, se nota. | M | Codex |

---

## D. Funcionalidad pendiente

| # | P | Qué falta | Tamaño | Quién |
|---|---|---|---|---|
| **D-01** | P1 | **Documentos como archivos** (RF07 y RF16, depende de D-03): subida con límite de tamaño, tipos permitidos y enlaces firmados. | L | Claude |
| **D-02** | P1 | **RF26: avisos según la severidad** de la incidencia. `incidencia.gravedad` ya clasifica y `Mailer` ya funciona; falta conectarlos. | M | Codex |
| **D-03** | P1 | **La alerta de maquinaria no aparece en Alertas.** `MaquinariaController` la calcula (RF24), pero `AnalisisController` solo emite las de avance y material. | S | Codex |
| **D-04** | P2 | **Distinguir `Creada` de `Planificación`** en la interfaz. | S | Codex |
| **D-05** | P1 | **Registro de cambios: quién modificó qué y cuándo.** Hoy ninguna tabla lo guarda, salvo el autor del reporte. Para una constructora es trazabilidad ante un reclamo, y suele pedirse en un peritaje. | L | Claude |
| **D-06** | P2 | **Exportar a PDF y Excel** los reportes y el avance. Es lo primero que un gerente pide para llevar a una reunión. *A validar con Triwe.* | M | Codex |
| **D-07** | P1 | **Uso desde la obra.** El Personal Técnico carga todo en campo. Hay que revisar la interfaz en celular pantalla por pantalla. | M | Claude |
| **D-08** | P2 | **Avisos por correo** de aprobaciones, rechazos y asignaciones. | M | Codex |

---

## E. Lo comercial — lo que lo vuelve un producto

| # | P | Qué falta | Tamaño | Quién |
|---|---|---|---|---|
| **E-01** | P1 | **Multicliente** (depende de D-02). Hoy ninguna de las 17 tablas sabe a qué empresa pertenece un dato. Necesita su propio ADR (ADR-002): organización en el modelo, aislamiento por fila, un middleware que fije la empresa en cada pedido, y **pruebas de que una empresa no puede ver datos de otra**, que son las que más importan. | L | Claude |
| **E-02** | P1 | **Alta de clientes.** Crear la empresa y su primer administrador, e importar los datos iniciales (obras y materiales) desde CSV. | M | Claude + Codex |
| **E-03** | P2 | **Suscripción y cobro** (si D-02 es SaaS), por ejemplo con Mercado Pago. | L | Claude |
| **E-04** | P1 | **Legal.** Términos y condiciones, política de privacidad, cumplimiento de la Ley 25.326 de protección de datos personales (incluida la inscripción de la base ante la AAIP) y acuerdo de tratamiento de datos con cada cliente. | M | Grupo, con asesoramiento |
| **E-05** | P1 | **Identidad** (depende de D-01). Dominio propio, correos enviados desde ese dominio (Brevo con SPF y DKIM, para que no caigan en spam) y una página de presentación. | M | Grupo + Claude |
| **E-06** | P2 | **Soporte.** Manual de usuario por rol, un canal de soporte y un compromiso de tiempos de respuesta. | M | Grupo |
| **E-07** | P1 | **Paquete para Triwe.** Demo con datos creíbles, el informe del pentest (A-11) y respuestas al cuestionario de seguridad. | M | Grupo + Claude |

---

## F. Documentación

| # | P | Qué falta | Tamaño |
|---|---|---|---|
| **F-01** | P1 | La documentación todavía presenta el sistema como trabajo de cátedra (`CLAUDE.md`, `README.md`, `DOCUMENTACION.md`). Separar lo académico de la documentación de producto. | M |
| **F-02** | P1 | El diagrama de estados de `CLAUDE.md` muestra cuatro estados; son siete. El `README` dice que no hay pruebas ni linters. Ambas cosas quedaron desactualizadas. | S |
| **F-03** | — | C1, C2 y C3 son correcciones sobre el PDF del TP2. Son académicas y no forman parte de este plan. | — |

---

## Orden de ataque

### Ola 1 — Cerrar agujeros (esta semana)

1. Mergear el PR #7, así el CI corre sobre `main`.
2. **B-06**: proteger `main`. Todo lo que sigue entra por PR con el CI en verde.
3. **A-01, A-02, A-04**: los tres P0 de seguridad que son chicos. Cada uno con una
   prueba que falle antes del arreglo y pase después.
4. **A-03**: límite de intentos de login.
5. **A-05 a A-08 y A-10**: el resto de los ajustes de seguridad.

### Ola 2 — Producción que aguante

6. Decisiones **D-04 y D-06**, después **B-01** (plan pago y restauración probada).
7. **B-03** (migraciones), **B-04** (monitoreo), **B-05** (health con base) y **B-02** (staging).

### Ola 3 — Calidad y funcionalidad, en paralelo

8. **C-01** (pruebas de controladores) y **C-03** (paginación), por Codex con specs cerrados.
9. **D-03, D-02 y D-04**: funcionalidad chica y ya especificada.
10. **D-01** (archivos, tras D-03 de decisiones) y **D-05** (registro de cambios).
11. **C-02**: el contrato OpenAPI, que corta de raíz el problema del simulador duplicado.

### Ola 4 — Producto

12. Decisiones **D-01, D-02 y D-05**.
13. **ADR-002 y E-01** (multicliente), después **E-02**.
14. **A-11** (pentest), **E-04, E-05 y E-07** para llegar a Triwe.

---

## Qué cuenta como "hecho"

Un ítem se cierra cuando:

- tiene una prueba que lo cubre, y si es un arreglo, la prueba **fallaba antes**;
- el CI está en verde en su PR;
- si cambia el comportamiento de la API, el simulador cambia igual;
- este documento se actualiza con el número de PR.

---

## Registro

| Fecha | Cambio |
|---|---|
| 2026-09-16 | Primera versión, a partir de la auditoría del código. |
