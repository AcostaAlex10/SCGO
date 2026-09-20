# Cómo se trabaja en SCGO

Reglas del equipo para que `main` esté siempre en condiciones de desplegarse.

> **¿Sesión nueva?** Empezá por [docs/TRASPASO.md](docs/TRASPASO.md): dice dónde
> quedó todo, qué hay en vuelo y qué trampas ya costaron tiempo.

---

## 1. Ramas

| Rama | Qué es | Se despliega | Quién la toca |
|---|---|---|---|
| `main` | La rama de trabajo. Todo el código vive acá. | **Sí**: API en Render, frontend en Vercel | Todo el equipo, **solo por pull request** |
| `testing` | **La rama de la demo para testers.** Su código es el anterior al cambio de nombre (por eso dice SGSO) y se publica en GitHub Pages con su propio `pages-testing.yml`. Tiene su propia guía, `GUIA-TESTERS.md`. | GitHub Pages | Solo para la demo y su guía, **por PR contra `testing`**. Nunca se mergea con `main`, en ninguna dirección |

> **La demo de Pages sale solo de `testing`** (decisión del equipo, 2026-09-23).
> Disparar el workflow de Pages a mano sobre `main` publica la versión de `main`
> encima de la de los testers: pasó el 2026-09-23. Para que no pueda repetirse, el
> entorno `github-pages` tiene que admitir solo la rama `testing`.

El repositorio se llamaba `ingenieria-en-software-proyecto` hasta el 2026-09-16.
GitHub redirige las URLs viejas, **salvo la de la demo**, que ahora es
https://acostaalex10.github.io/SCGO/.

### Ramas de trabajo abiertas

**Cada rama nueva se anota acá en el momento en que se crea**, no después: una
rama sin entrada es una rama que nadie va a saber para qué estaba.

| Rama | Desde | Para qué | PR |
|---|---|---|---|
| `claude/migraciones-versionadas` | `main` @ `c3addfb` (2026-09-19) | B-03: tabla `schema_migrations` y un comando que aplica las migraciones pendientes | #23 |

### Reglas

1. **Salir de `main` actualizado:** `git checkout main && git pull` antes de crear
   la rama.
2. **Una rama por tarea**, corta, y con su fila en la tabla de arriba.
3. **Pull request contra `main`.** No apilar un PR sobre la rama de otro PR: si se
   borra la rama base, GitHub cierra el PR apilado en vez de reapuntarlo.
4. **Al mergear:** borrar la rama y pasarla al historial de abajo.

---

## 2. Pull requests

El CI corre cuatro jobs, y los cuatro son obligatorios en el ruleset de `main`
(el de Docker, desde el 2026-09-26: B-10 en el plan de producto).

| Chequeo | Qué verifica | ¿Obligatorio? |
|---|---|---|
| Backend (PHPStan + PHPUnit) | análisis estático y pruebas, incluidas las de integración contra MariaDB | sí |
| Frontend (tipos + build) | que el frontend compile sin errores de tipos, y que `vercel.json` siga declarando los headers | sí |
| Playwright (demo estática) | las cinco suites de punta a punta | sí |
| Imagen Docker (arranque seguro) | que la API falle cerrado sin `JWT_SECRET` y no filtre detalles internos | sí |

La descripción del PR dice qué cambia, por qué, y cómo se verificó. Si algo no se
pudo verificar, se dice.

### Qué cuenta como terminado

- Tiene una prueba que lo cubre. Si es un arreglo, **la prueba fallaba antes**.
- El CI está en verde.
- Si cambia el comportamiento de la API, el simulador
  (`FRONT/src/app/mock/servidor.ts`) cambia igual.
- Si cambia un requerimiento o su estado, se actualiza
  [docs/REQUERIMIENTOS.md](docs/REQUERIMIENTOS.md).
- Si cierra un ítem del [plan de producto](docs/PLAN-PRODUCTO.md), se anota ahí el
  número de PR.

---

## 3. Commits

- En español, en infinitivo y describiendo el cambio: *"Validar el esquema de los
  enlaces de documentos"*.
- Chicos y con un solo propósito.
- El cuerpo explica **por qué**, sobre todo cuando la razón no es obvia.

---

## 4. Historial de ramas integradas

El trabajo sigue en `main`; los PR explican por qué el código quedó como quedó.

| Rama | Qué traía | Cerró en |
|---|---|---|
| `chore/skill-codex` | La skill `codex-programador` para delegar código al Codex CLI | PR #3 |
| `claude/ingenieria-software-nube-2ws8jg` | ADR-001, su plan, el borrado de `back-node/` y Composer con PSR-4 | PR #4 |
| `claude/adr-001-fase-2a-3` | `Sgso\Reglas` (ciclo de vida y permisos) y PHPStan nivel 5 | Entró por el PR #6 |
| `claude/adr-001-fase-4` | Tabla de rutas declarativa (`Sgso\Ruteo`) | PR #6 |
| `claude/adr-001-fase-2b-5` | Pruebas de integración, CI y plan de producto | PR #7 |
| `claude/limpieza-docs` | Requerimientos recuperados, documentación ordenada y producto renombrado a SCGO | PR #8 |
| `claude/renombre-scgo` | Referencias actualizadas al nuevo nombre del repositorio | PR #9 |
| `claude/seguridad-ola-1` | XSS por enlaces, secreto JWT obligatorio, errores sin detalles internos y `.dockerignore` | PR #10 |
| `claude/seguridad-a03` | Límite de intentos de login por cuenta y dos filtraciones del login | PR #11 |
| `claude/seguridad-ola-2` | Contraseñas de 10 caracteres, headers de seguridad y límite de `/auth/olvide` | PR #12 |
| `claude/dependencias` | Dependencias al día y auditadas, y Dependabot configurado | PR #13 |
| `claude/ingenieria-software-nube-2ws8jg` | Triaje de los seis PR de Dependabot, `docs/TRASPASO.md` y el borrado de dos primitivos de shadcn sin uso. El nombre se reutilizó: es otra rama que la del PR #4 | PR #20 |
| `claude/health-con-base` | B-05: `/api/health` ejecuta `SELECT 1` y reporta la base | PR #21 |
| `claude/traspaso-cierre-sesion` | `docs/TRASPASO.md` al cierre de la sesión del 2026-09-19, revisado después del renombre | PR #22 |
| `claude/testing-demo-scgo` | **Contra `testing`.** La guía de testers dice que la demo sale de `testing`, y `HANDOFF.md` deja de apuntar a la URL vieja | PR #31, en `testing` |
| `claude/demo-desde-testing` | La demo de Pages vuelve a salir de `testing`: documentación alineada, la interfaz dice SCGO y `contrato.mjs` lee `SGSO_URL` | PR #32 |
| `claude/preparar-dependabot` | Triaje de los PR de Dependabot #26 a #30: fuera `motion` y `react-responsive-masonry`, y el código listo para TypeScript 7 y recharts 3 | PR #33 |
| `claude/monitoreo-errores` | B-04: el log dice qué pedido falló y los errores van a Sentry, sin SDK | PR #24 |
| `claude/staging-decision` | B-02 queda bloqueado por DEC-04, y el CI verifica el `connect-src` de la CSP | PR #25 |
| `claude/cierre-2026-09-23` | Cierre de la sesión del 2026-09-23: `TRASPASO.md` al día y la rama del #25 al historial | PR #34 |
| `claude/registrar-decisiones` | DEC-04 postergada hasta cerrar la posible venta; B-10 y el entorno de Pages, hechos; el timeout del monitor, corregido | PR #35 |
| `claude/codigo-muerto` | C-11: fuera 34 primitivos de shadcn, 37 dependencias del front que no usaba nadie y `JsonProyectoRepository`; `docs/STACK.md` | PR #36 |
| `claude/codigo-muerto-2` | C-11, segunda parte: fuera `globals.css` e `ImageWithFallback.tsx`; #34, #35 y #36 al historial | PR #37 |
| `claude/traspaso-nube` | `TRASPASO.md` al día para seguir en una sesión en la nube; la rama del #37 al historial | PR #38 |
| `claude/pruebas-controladores` | C-01, primera parte: 38 pruebas de integración de materiales, análisis y usuarios; encontraron A-14 y A-15 | PR #39 |
| `claude/revocar-sesiones` | A-14: una baja, un cambio de rol, un restablecimiento o un login en otro lado cortan el acceso en el primer pedido | PR #40 |
| `claude/pruebas-planificacion` | C-01, segunda parte: 35 pruebas de avances, planificación y etapas; el alta de avance que devolvía id 0 y la etapa que terminaba antes de empezar | PR #41 |
| `claude/pruebas-campo` | C-01, tercera parte: 35 pruebas de lo que se carga en obra; el uso de maquinaria negativo o de una obra inexistente. C-01 completo | PR #42 |
| `claude/paginacion` | C-03: paginación a pedido de los siete listados que crecen, con el total en `X-Total-Count`; "Ver más" en asistencias y reportes | PR #43 |
| `claude/alerta-consumo` | D-03: el consumo anómalo de maquinaria en Alertas, con la regla en `Sgso\Reglas\ConsumoMaquinaria`; el simulador arma el mismo feed que la API | PR #44 |
| `claude/verificacion-tls` | A-16: `Mailer` y `Geocoder` vuelven a verificar el certificado TLS; una prueba recorre `back/src` para que nada lo vuelva a apagar | PR #45 |
| `claude/avisos-incidencias` | D-02: una incidencia alta o media avisa por correo según el protocolo del equipo; RF26 cumplido | PR #46 |
| `claude/estado-creada` | D-04: una obra nueva arranca `creada` y pasa a `planificacion` al cargar su planificación | PR #47 |
| `claude/certificacion-api` | D-09: la certificación de RF15 la calcula la API, al centavo, y no le llega al Personal Técnico; RF15 cumplido | PR #48 |
| `claude/traspaso-cierre-nube` | `TRASPASO.md` al día al cerrar la sesión en la nube: estado, lo que falta probar en producción y las trampas de la nube | PR #49 |
| `TP1-plan-de-testing` | Nada: se creó vacía para un trabajo de la facultad que no corresponde a este repositorio | Borrada sin mergear |

### Dos cosas que no conviene repetir

- **Una skill parecía no existir.** Estaba en `main` desde el PR #3, pero las ramas
  siguientes salieron de un commit anterior a ese merge y no la tenían. Se evita
  saliendo siempre de `main` actualizado.
- **El PR #5 quedó cerrado sin mergear.** Estaba apilado sobre la rama del PR #4;
  al borrarse esa rama, GitHub lo cerró. Su contenido entró igual por el PR #6,
  que lo incluía.
