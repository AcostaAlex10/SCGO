# Cómo se trabaja en SCGO

Reglas del equipo para que `main` esté siempre en condiciones de desplegarse.

> **¿Sesión nueva?** Empezá por [docs/TRASPASO.md](docs/TRASPASO.md): dice dónde
> quedó todo, qué hay en vuelo y qué trampas ya costaron tiempo.

---

## 1. Ramas

| Rama | Qué es | Se despliega | Quién la toca |
|---|---|---|---|
| `main` | La rama de trabajo. Todo el código vive acá. | **Sí**: API en Render, frontend en Vercel | Todo el equipo, **solo por pull request** |
| `testing` | **Congelada** en `a25da85`. Demo con datos simulados para los testers, publicada por `.github/workflows/pages-testing.yml`. | GitHub Pages | **Nadie.** No se mergea, no se rebasea, no se actualiza |

> `testing` no se toca. Si alguna vez hay que actualizarla, es una decisión
> explícita del equipo.

El repositorio se llamaba `ingenieria-en-software-proyecto` hasta el 2026-09-16.
GitHub redirige las URLs viejas, **salvo la de la demo**, que ahora es
https://acostaalex10.github.io/SCGO/.

### Ramas de trabajo abiertas

**Cada rama nueva se anota acá en el momento en que se crea**, no después: una
rama sin entrada es una rama que nadie va a saber para qué estaba.

| Rama | Desde | Para qué | PR |
|---|---|---|---|
| `claude/ingenieria-software-nube-2ws8jg` | `main` @ `d326aba` (2026-09-19) | Triaje de los seis PR de Dependabot y documento de traspaso; borra dos primitivos de shadcn sin uso | #20 |

### Reglas

1. **Salir de `main` actualizado:** `git checkout main && git pull` antes de crear
   la rama.
2. **Una rama por tarea**, corta, y con su fila en la tabla de arriba.
3. **Pull request contra `main`.** No apilar un PR sobre la rama de otro PR: si se
   borra la rama base, GitHub cierra el PR apilado en vez de reapuntarlo.
4. **Al mergear:** borrar la rama y pasarla al historial de abajo.

---

## 2. Pull requests

El CI corre cuatro jobs. Los tres primeros son obligatorios en el ruleset de
`main`; el de Docker todavía no (es B-10 en el plan de producto), pero si falla,
falla por algo real.

| Chequeo | Qué verifica | ¿Obligatorio? |
|---|---|---|
| Backend (PHPStan + PHPUnit) | análisis estático y pruebas, incluidas las de integración contra MariaDB | sí |
| Frontend (tipos + build) | que el frontend compile sin errores de tipos, y que `vercel.json` siga declarando los headers | sí |
| Playwright (demo estática) | las cinco suites de punta a punta | sí |
| Imagen Docker (arranque seguro) | que la API falle cerrado sin `JWT_SECRET` y no filtre detalles internos | no todavía |

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
| `TP1-plan-de-testing` | Nada: se creó vacía para un trabajo de la facultad que no corresponde a este repositorio | Borrada sin mergear |

### Dos cosas que no conviene repetir

- **Una skill parecía no existir.** Estaba en `main` desde el PR #3, pero las ramas
  siguientes salieron de un commit anterior a ese merge y no la tenían. Se evita
  saliendo siempre de `main` actualizado.
- **El PR #5 quedó cerrado sin mergear.** Estaba apilado sobre la rama del PR #4;
  al borrarse esa rama, GitHub lo cerró. Su contenido entró igual por el PR #6,
  que lo incluía.
