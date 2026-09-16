# Plan de producto de SCGO

Todo lo que falta para que SCGO se pueda entregar y cobrar a Triwe, ordenado por
prioridad.

- **Actualizado:** 2026-09-16
- **Base:** `main` @ `b4f93c2`
- **Punto de partida:** [ADR-001](adr/ADR-001-stack.md) está completo. El backend ya
  tiene la base que le faltaba: Composer, 466 pruebas, PHPStan nivel 5, tabla de
  rutas y CI con tres chequeos en verde.
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
| **DEC-07** | **El repositorio es público.** Cualquiera puede copiar el código que se le vende a Triwe, y quedaron publicados datos de producción (ya retirados, pero siguen en el historial). | Abierta. Recomendación: **pasarlo a privado**. El costo es que GitHub Pages en un repositorio privado requiere plan pago, y ahí vive la demo de los testers; la alternativa es publicar esa demo como un proyecto aparte en Vercel. | E-04 |

---

## A. Seguridad — antes de mostrarle el sistema a Triwe

| # | P | Problema | Dónde | Tamaño | Quién |
|---|---|---|---|---|---|
| **A-01** | **P0** | **Hecho en el PR #10.** **XSS almacenado por enlaces de documentos. Confirmado.** `FILTER_VALIDATE_URL` acepta `javascript://x%0Aalert(1)`, y el front lo muestra como `<a href>`. Cualquier rol que carga documentos, incluido Personal Técnico, puede plantar un enlace que ejecuta código en la sesión de quien le haga clic. Como el token vive en `localStorage`, eso es robo de cuenta. El simulador ni siquiera valida la URL. | `DocumentoController.php:45`, `DocumentacionPage.tsx:75`, `mock/servidor.ts:1209` | S | Claude |
| **A-02** | **P0** | **Hecho en el PR #10.** **El secreto de los tokens tiene un valor por defecto.** Si `JWT_SECRET` falta, la API firma con `cambiar_esta_clave` y cualquiera puede fabricarse un token de administrador. Tiene que fallar cerrado: sin secreto, o con uno corto, no arranca. | `public/index.php` | S | Claude |
| **A-03** | **P0** | **Sin límite de intentos de login.** Se pueden probar contraseñas sin freno. Hace falta un límite por IP y por cuenta, con espera creciente. | `AuthController::login` | M | Claude |
| **A-04** | **P0** | **Hecho en el PR #10.** **Sin manejo global de errores.** No hay `set_exception_handler` y la imagen de Docker no carga un `php.ini`, así que PHP muestra los errores. La base se conecta antes de rutear y sin `try`: si Aiven falla, el cliente ve un error crudo con la ruta interna y el host y usuario de la base. Solución: `php.ini-production`, un manejador que registre el error y conteste JSON genérico, y probar la caída de la base. | `Dockerfile`, `index.php`, `Database.php` | S | Claude |
| **A-05** | P1 | **Contraseñas de 6 caracteres como mínimo.** Subir a 10 o 12 y rechazar las más comunes. | `AuthController.php` | S | Claude |
| **A-06** | P1 | **`react-router` 7.13.0 con avisos de severidad alta.** La mayoría aplica al modo framework o SSR, que no usamos, pero actualizar es barato y saca la alarma. | `FRONT/package.json` | S | Codex |
| **A-07** | P1 | **Sin headers de seguridad.** `vercel.json` solo reescribe rutas. Faltan CSP (segunda barrera contra A-01), HSTS, `frame-ancestors`, `X-Content-Type-Options` y `Referrer-Policy`, en el front y en la API. | `FRONT/vercel.json`, API | S | Claude |
| **A-08** | P1 | **Hecho en el PR #10.** **Sin `.dockerignore`.** El `Dockerfile` copia todo `back/`: si alguien construye la imagen en local, se lleva su `.env` adentro. | `back/` | S | Claude |
| **A-09** | P2 | **Token en `localStorage`.** Es lo que vuelve grave cualquier XSS. Con A-01 y A-07 el riesgo baja mucho; pasarlo a cookie `httpOnly` cambia CORS y exige protección CSRF. | `auth/session.ts` | M | Claude |
| **A-10** | P1 | **Auditoría de dependencias en CI:** `composer audit`, `npm audit` y Dependabot. | `ci.yml` | S | Codex |
| **A-11** | P1 | **Pentest antes de la entrega.** Las skills de Strix están instaladas y generan el informe que suele pedir un cliente. Necesita Docker, pendiente en esta máquina. | — | M | Claude |

---

## B. Operación

| # | P | Problema | Tamaño | Quién |
|---|---|---|---|---|
| **B-01** | **P0** | **Plan pago y respaldos** (DEC-04). Respaldos automáticos con retención conocida y **una restauración probada al menos una vez**. Es lo que hace cumplir RNF03 (hoy la primera consulta tarda cerca de un minuto) y RNF07 (respaldo diario). | M | Grupo + Claude |
| **B-02** | P1 | **Staging de verdad.** `testing` es una demo con datos simulados; falta un entorno con backend y base propios para probar antes de producción. | M | Claude |
| **B-03** | P1 | **Migraciones versionadas.** Hoy son scripts sueltos que alguien tiene que acordarse de correr. Hace falta una tabla `schema_migrations` y un comando que aplique las pendientes en cada deploy. | M | Claude |
| **B-04** | P1 | **Logs y monitoreo.** Errores a un servicio tipo Sentry y un chequeo externo de disponibilidad. | M | Claude |
| **B-05** | P1 | **`/api/health` no revisa la base.** Tiene que ejecutar un `SELECT 1` y reportarlo. | S | Codex |
| **B-06** | **P0** | **`main` protegida y deploy condicionado al CI.** Que nada llegue a `main` sin PR y sin CI en verde. | S | Alex (configuración de GitHub y Render) |
| **B-07** | P2 | **PHP 8.3 → 8.4.** 8.3 terminó su soporte activo en 12/2025. | S | Codex |
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
| **C-08** | P2 | **Ciclo de vida del reporte** a `Sgso\Reglas`. Y aclarar si `GESTION_OBRA` y `REPORTE_APROBAR` son iguales a propósito: hoy tienen los mismos roles. | S | Claude |
| **C-09** | P2 | **Normalización pendiente.** `proyecto.encargado` es texto libre; `proyecto.avance` se guarda en vez de calcularse. | M | Claude |
| **C-10** | P2 | **60 dependencias en el front**, con MUI, Radix y shadcn a la vez. Podarlas baja la carga inicial, que en obra y con señal móvil se nota (RNF01). | M | Codex |
| **C-11** | P2 | **Código muerto.** `JsonProyectoRepository` no lo usa nadie en ejecución: es el prototipo con archivo JSON anterior a la base. | S | Codex |

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

1. **B-06:** proteger `main`. Todo lo que sigue entra por PR con el CI en verde.
2. **A-01, A-02 y A-04:** los tres P0 de seguridad que son chicos. Cada uno con una
   prueba que falle antes del arreglo y pase después.
3. **A-03:** límite de intentos de login.
4. **A-05 a A-08 y A-10:** el resto de los ajustes de seguridad.
5. **DEC-07:** decidir si el repositorio pasa a privado.

### Ola 2 — Producción que aguante

6. **DEC-04**, después **B-01** (plan pago y restauración probada).
7. **B-03** (migraciones), **B-04** (monitoreo), **B-05** (health con base) y **B-02** (staging).

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
