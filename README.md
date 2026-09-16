# SGSO — Sistema de Gestión y Seguimiento Operativo de Obras

[![CI](https://github.com/AcostaAlex10/ingenieria-en-software-proyecto/actions/workflows/ci.yml/badge.svg)](https://github.com/AcostaAlex10/ingenieria-en-software-proyecto/actions/workflows/ci.yml)

Aplicación web para que una empresa constructora centralice la gestión de sus obras:
proyectos, planificación, avance físico, materiales, maquinaria, documentación,
reportes y alertas de desvío.

Proyecto académico de **IC-413 — Ingeniería del Software I** (UNaM, Facultad de
Ingeniería, 2026), Grupo 2. El contexto completo del sistema, los requerimientos y
la trazabilidad con los trabajos prácticos están en **[DOCUMENTACION.md](DOCUMENTACION.md)**.

---

## Arquitectura

| Pieza | Carpeta | Tecnología | Estado |
|---|---|---|---|
| Frontend (SPA) | `FRONT/` | React 18 + Vite 6 + TypeScript, Tailwind v4, shadcn/ui | Desplegado en Vercel |
| Backend (API REST) | `back/` | **PHP 8** sin framework, PDO | Desplegado en Render (Docker) |
| Base de datos | `back/sql/` | MariaDB / MySQL | Desplegada en Aiven |

> **Cuál es el backend del proyecto:** el de `back/` (PHP). La cátedra exige PHP sobre
> MariaDB, y es el que está desplegado y conectado al frontend. Hubo una
> implementación alternativa en Node (`back-node/`), borrada del árbol de trabajo al
> aceptarse [ADR-001](docs/adr/ADR-001-stack.md); sigue disponible en el historial de git.

---

## Cómo levantarlo en local

### 1) Base de datos

Crear una base `sgso` en MariaDB o MySQL y cargar el esquema:

```bash
mysql -u root -p sgso < back/sql/schema.sql
```

El esquema crea las 17 tablas del modelo (usuario, proyecto, planificacion,
etapa_planificacion, avance_fisico, asistencia, incidencia, material,
asignacion_material, consumo_material, documento, reporte, periodo_inactividad,
item_excedente, maquinaria, registro_maquinaria, falla_maquinaria).

Alternativa desde PHP, útil para apuntar a la base remota: `php back/sql/migrar.php`.

### 2) Backend PHP

```bash
cd back && composer install && cd ..   # genera vendor/autoload.php (sin esto no arranca)
cp back/.env.example back/.env
php back/sql/seed.php
php -S localhost:8000 -t back/public
```

`seed.php` crea el usuario administrador inicial con la contraseña hasheada.
La API queda en `http://localhost:8000/api`.

Variables de entorno (`back/.env`):

| Variable | Descripción |
|---|---|
| `DB_HOST` / `DB_PORT` / `DB_NAME` / `DB_USER` / `DB_PASSWORD` | Conexión a la base |
| `DB_SSL` | `true` para bases en la nube (Aiven) |
| `JWT_SECRET` | Clave para firmar los tokens de sesión |
| `JWT_SEGUNDOS` | Validez del token en segundos (28800 = 8 h) |
| `BREVO_API_KEY` / `BREVO_SENDER` | Envío del correo de recuperación de contraseña |

### 3) Frontend

```bash
cd FRONT
npm install --legacy-peer-deps
npm run dev
```

`--legacy-peer-deps` es necesario por conflictos entre peer dependencies.
Copiar `FRONT/.env.example` a `FRONT/.env` y ajustar `VITE_API_URL` si la API no
está en la URL por defecto.

Las pruebas del front son `npm run typecheck` y las cinco suites de Playwright
(`FRONT/scripts/pruebas/`). No hay linter configurado todavía. El CI corre todo en cada PR.

---

## API

Todas las rutas cuelgan de `/api`. La autenticación es por **JWT** en el header
`Authorization: Bearer <token>`; las contraseñas se guardan hasheadas con bcrypt.

| Recurso | Descripción |
|---|---|
| `/auth` | `login`, `register`, `me`, `olvide`, `restablecer` |
| `/proyectos` | CRUD de obras y sus subrecursos: planificación, avances, asistencia, incidencias, materiales asignados, documentos, inactividad, ítems excedentes |
| `/planificacion` | Planificación por obra, etapas y avance físico asociado |
| `/materiales` | Catálogo de materiales y consumos |
| `/maquinaria` | Equipos, registros de uso y fallas |
| `/reportes` | Reportes operativos y su circuito de aprobación |
| `/analisis` | Indicadores, comparativas y alertas de desvío |
| `/usuarios` | Gestión de cuentas y roles |
| `/health` | Health-check del servicio |

### Roles

| Rol | Puede |
|---|---|
| `AdministradorSistema` | Todo, incluida la gestión de cuentas y roles |
| `PersonalAdministrativo` | Crear y editar obras, planificación, materiales; aprobar reportes |
| `PersonalTecnico` | Registrar avance, asistencia, incidencias y consumos desde la obra |
| `Gerente` | Consultar indicadores y reportes (sin carga operativa) |

---

## Estructura del repositorio

```
FRONT/          SPA React (páginas en src/app/components, rutas en src/app/routes.tsx)
back/           API REST en PHP — backend del proyecto
  public/       front controller (index.php) y .htaccess
  src/          controladores, middleware de auth, acceso a datos
  sql/          schema.sql, migrar.php, migracion-estado-enum.php, seed.php
Intalar/        instalador de Node y comandos de ayuda para el equipo
```

## Documentación

- **[DOCUMENTACION.md](DOCUMENTACION.md)** — contexto del sistema, requerimientos,
  modelo de dominio, decisiones técnicas y trazabilidad con los TPs.
- **[DEPLOY.md](DEPLOY.md)** — despliegue en la nube (Vercel + Render + Aiven).
- **[DEPLOY-ONPREMISE.md](DEPLOY-ONPREMISE.md)** — despliegue en servidor propio.
- **[CLAUDE.md](CLAUDE.md)** — guía para trabajar el repo con Claude Code.
- **[RAMAS.md](RAMAS.md)** — qué es cada rama, cuál se despliega y cómo se trabaja.
