# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**SCGO — Sistema de Control y Gestión de Obras de construcción.** A product built
for **Triwe**, a construction company, and meant to be sold: quality and security
come first. It started as a university project, which is why the PHP namespace
(`Sgso\`) and some URLs keep the old name, SGSO.

The repository contains the full system: React SPA, PHP REST API and relational
schema. Start here:

- `docs/REQUERIMIENTOS.md` — **the single source of truth for scope**: user
  stories, RF01–RF28, RNF01–RNF10 and the real status of each. Check it before
  building anything, and update it when a requirement or its status changes.
- `docs/ARQUITECTURA.md` — how the system is built.
- `docs/PLAN-PRODUCTO.md` — the prioritized roadmap. Work is picked from here.
- `docs/OPERACION.md` — environments, where credentials live, known pitfalls.
- `CONTRIBUTING.md` — branch and pull request rules.

### Implemented architecture
| Layer | Folder | Technology | Deployment |
|---|---|---|---|
| SPA | `FRONT/` | React 18 + Vite 6 + TypeScript | Vercel |
| REST API | `back/` | PHP 8, no framework, PDO | Render (Docker, PHP + Apache) |
| Database | `back/sql/` | MariaDB / MySQL | Aiven |

> **Which backend to edit:** always `back/` (PHP). The course requires PHP over
> MariaDB and that is what is deployed and consumed by the frontend. A Node
> alternative used to live in `back-node/`; it was deleted from the working tree
> when ADR-001 was accepted (`docs/adr/ADR-001-stack.md`) and only exists in git
> history.

### Branches

`main` is the only working branch and the one that deploys (Render + Vercel).
`testing` is frozen at `a25da85` for the testers — never merge into it, never
update it. Branch off an up-to-date `main`, keep branches short-lived, and
**add a row to `CONTRIBUTING.md` when you create one**, not later. Avoid stacking a
PR on another PR's branch: deleting the base branch closes the stacked PR
instead of retargeting it.

### User roles
- **AdministradorSistema** — superuser; manages accounts and role assignment
- **PersonalAdministrativo** — creates/edits projects, planning and materials; approves reports
- **PersonalTecnico** (Encargado de Obra) — registers daily advance, attendance, material consumption, machinery usage and incidents from the field
- **Gerente** — read-only: monitors advance, consults comparative reports

Role groups live in `Sgso\Reglas\Permisos` (`GESTION_OBRA`, `AVANCE`, `DOC`,
`REPORTE_APROBAR`, `ADMIN`). Each route in `Sgso\Ruteo\Tabla` declares which
group it requires, and `TablaTest` walks every route to check the guards.

## Commands

### Frontend (`FRONT/`)
```bash
npm install --legacy-peer-deps   # --legacy-peer-deps is required due to peer conflicts
npm run dev                      # Vite dev server
npm run build                    # production build
```

### Backend (`back/`)
```bash
cd back && composer install      # required: generates vendor/autoload.php
php back/sql/migrar.php          # apply schema.sql to the configured database
php back/sql/seed.php            # create the initial admin user (bcrypt hash)
php -S localhost:8000 -t back/public
cd back && composer test         # PHPUnit over back/tests/
cd back && composer phpstan      # PHPStan, level 5, must stay at zero errors
```

> Checks that must stay green: `npm run typecheck` (front), `composer test` and
> `composer phpstan` (back). All three run in CI (`.github/workflows/ci.yml`).
> Integration tests in `back/tests/Integracion/` skip locally unless the
> `SGSO_TEST_DB_*` variables point at a disposable database (name must contain
> "test"); CI provides one. The product roadmap is `docs/PLAN-PRODUCTO.md`.

## Architecture

### Frontend stack
- **React 18** + **React Router v7** (browser router)
- **Vite 6** with `@vitejs/plugin-react`
- **Tailwind CSS v4** via `@tailwindcss/vite` (no tailwind.config.js — config is in CSS)
- **shadcn/ui** component library (`src/app/components/ui/`)
- **Recharts** for charts
- Path alias `@` → `FRONT/src`

Entry points: `FRONT/index.html` → `src/main.tsx` → `src/app/App.tsx` → `src/app/routes.tsx`

### Routing (`src/app/routes.tsx`)
`/login`, `/olvide` and `/restablecer` are public. Every other route is wrapped by
the `Root` layout (sidebar + header) and requires an authenticated session:

| Route | Component | Purpose |
|---|---|---|
| `/` | `Dashboard` | Global KPIs and comparative charts |
| `/proyectos` | `ProyectosPage` | Register, modify, delete and filter projects |
| `/proyectos/:id` | `ProyectoDetallePage` | Single project detail |
| `/seguimiento` | `SeguimientoPage` | Daily advance, attendance, incidents, inactivity |
| `/materiales` | `MaterialesPage` | Assign materials to a project, register consumption |
| `/documentacion` | `DocumentacionPage` | Upload and query PDF/image files per project |
| `/reportes` | `ReportesPage` | Create, review and approve/reject operational reports |
| `/alertas` | `AlertasPage` | Active alerts (advance deviation, cost overrun) |
| `/maquinaria` | `MaquinariaPage` | Machinery usage logs, faults and maintenance |
| `/usuarios` | `UsuariosPage` | Account and role management |

### Backend (`back/`)
Single front controller: `back/public/index.php`. Routing is a declarative
table — `Sgso\Ruteo\Tabla` holds every route as data (method, path pattern,
role guard, handler key) and `Sgso\Ruteo\Despachador` resolves a request
against it. `index.php` keeps a `key => closure` map, the only place that knows
the controllers, and applies the JWT check and the role guard the route
declares. Add an endpoint in both places; `TablaTest` fails if a route has no
handler. Resources:
`auth`, `health`, `proyectos`, `planificacion`, `materiales`, `maquinaria`,
`reportes`, `analisis`, `usuarios`.

Cross-cutting pieces: `Env` (dotenv loader), `Cors`, `Database` (PDO singleton),
`Jwt`, `AuthMiddleware`, `Mailer` (Brevo, for password recovery), `Geocoder`.

Every class in `back/src/` lives under the `Sgso\` namespace and is loaded by
Composer's PSR-4 autoloader — no manual `require_once`. Inside that namespace the
global classes need importing, so files using `PDO` or `DateTime` carry a `use`.

Business rules that controllers used to decide inline now live in `Sgso\Reglas`:
`CicloDeVida` (the seven project states and which transition is legal) and
`Permisos` (the RF19 role groups). Both are pure — no PDO, no output — and are
the only backend code with tests. Change a state rule there, not in a controller.

The timezone is pinned to `America/Argentina/Buenos_Aires` because Render runs in
UTC and date validations depend on the local date.

### Styling system
Styles live in `src/styles/`: `theme.css` (dark/orange theme, `--primary: #e8981e`),
`tailwind.css`, `globals.css`. `default_shadcn_theme.css` is
kept as a light-theme reference but is not applied.

### Domain model
Seventeen tables in `back/sql/schema.sql`. `proyecto` is the core entity:

- **proyecto** — one `planificacion`, many `avance_fisico`, `asistencia`, `incidencia`, `periodo_inactividad`, `item_excedente`, `documento`, `reporte`, `asignacion_material`
- **planificacion / etapa_planificacion** — expected advance and base budget per stage
- **avance_fisico** — daily physical advance records tied to a planning stage
- **material / asignacion_material / consumo_material** — catalog → per-project assignment → consumption with stock check
- **maquinaria / registro_maquinaria / falla_maquinaria** — equipment → usage logs → fault history
- **usuario** — `rol` enum + `activo` flag, bcrypt password hash, password-reset token

#### State values (as stored, lowercase)
- Project (`proyecto.estado`, default `planificacion`): seven states — `creada`, `planificacion`, `en_ejecucion`, `pausada`, `en_revision`, `finalizada`, `cancelada`. Legal transitions live in `Sgso\Reglas\CicloDeVida`; the full table is in `docs/ARQUITECTURA.md` §4
- Report (`reporte.estado`, default `borrador`): `borrador` → `en_revision` → `aprobado` | `rechazado`
- Attendance (`asistencia.estado`): `presente` | `ausente` | `tarde`
- Incident (`incidencia`): type `clima` | `falla_maquinaria` | `proveedor` | `otro`; severity `baja` | `media` | `alta`

### Component conventions
- Page-level components live directly in `src/app/components/`; shadcn/ui primitives in `src/app/components/ui/`
- `src/app/components/figma/ImageWithFallback.tsx` handles Figma-exported images with graceful fallback
- The Vite config includes a custom plugin that resolves Figma asset paths; SVG and CSV are treated as static assets
