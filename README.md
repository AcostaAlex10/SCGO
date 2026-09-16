# SCGO — Sistema de Control y Gestión de Obras

[![CI](https://github.com/AcostaAlex10/SCGO/actions/workflows/ci.yml/badge.svg)](https://github.com/AcostaAlex10/SCGO/actions/workflows/ci.yml)

Sistema web para que una empresa constructora controle sus obras en un solo lugar:
planificación, avance físico, asistencia, materiales, maquinaria, documentación,
reportes con aprobación y alertas de desvío presupuestario.

Desarrollado para **Triwe**, empresa de construcción.

---

## Qué resuelve

La gestión de obras con planillas y archivos sueltos deja sin control el presupuesto
frente al avance real, pierde la trazabilidad de los materiales y separa a la obra de
la oficina. SCGO centraliza esa información y avisa cuando algo se desvía.

| Módulo | Para qué |
|---|---|
| Proyectos | Alta y organización de obras, con su planificación por etapas |
| Seguimiento | Avance diario, asistencia, incidencias y períodos de inactividad |
| Materiales | Asignación a cada obra y control de consumo |
| Maquinaria | Horas de uso, combustible, fallas y rendimiento |
| Documentación | Documentos de cada obra |
| Reportes | Partes de obra con circuito de revisión y aprobación |
| Análisis y alertas | Desvíos de avance y de presupuesto, comparativas |
| Usuarios | Cuentas y permisos por rol |

El alcance completo, con el estado de cada requerimiento, está en
**[docs/REQUERIMIENTOS.md](docs/REQUERIMIENTOS.md)**.

### Roles

| Rol | Puede |
|---|---|
| Administrador del Sistema | Todo, incluida la gestión de cuentas y roles |
| Personal Administrativo | Crear y editar obras, planificación y materiales; aprobar reportes |
| Personal Técnico | Cargar avance, asistencia, incidencias y consumos desde la obra |
| Gerencia | Consultar indicadores y reportes, sin carga operativa |

---

## Arquitectura

| Pieza | Carpeta | Tecnología | Producción |
|---|---|---|---|
| Frontend | `FRONT/` | React 18, Vite 6, TypeScript, Tailwind CSS 4 | Vercel |
| API REST | `back/` | PHP 8.3, PDO, Composer | Render (Docker) |
| Base de datos | `back/sql/` | MariaDB / MySQL | Aiven |

Detalle en [docs/ARQUITECTURA.md](docs/ARQUITECTURA.md).

---

## Cómo levantarlo en local

Hace falta PHP 8.2 o superior con PDO MySQL, Composer, Node 20 y una base MariaDB o
MySQL.

**1. Base de datos**

```bash
mysql -u root -p -e "CREATE DATABASE sgso CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mysql -u root -p sgso < back/sql/schema.sql
```

**2. API**

```bash
cd back
composer install
cp .env.example .env                                   # datos de la base
php -r "echo bin2hex(random_bytes(32));"             # pegar el resultado en JWT_SECRET (obligatorio)
SEED_ADMIN_PASSWORD=una-clave-larga php sql/seed.php   # primer administrador
php -S localhost:8000 -t public
```

La API queda en `http://localhost:8000/api`. Las variables de entorno están
descritas en [back/README.md](back/README.md).

**3. Frontend**

```bash
cd FRONT
npm install --legacy-peer-deps
cp .env.example .env    # VITE_API_URL, si la API no está en la dirección por defecto
npm run dev
```

Para probar la interfaz **sin backend ni base**, con datos simulados, ver
[FRONT/MODO-PRUEBA.md](FRONT/MODO-PRUEBA.md).

---

## Calidad

Cada pull request corre, y tiene que pasar:

| Qué | Comando |
|---|---|
| Pruebas del backend (unitarias y de integración) | `cd back && composer test` |
| Análisis estático (PHPStan nivel 5) | `cd back && composer phpstan` |
| Tipos del frontend | `cd FRONT && npm run typecheck` |
| Pruebas de punta a punta (Playwright) | ver [FRONT/scripts/pruebas/](FRONT/scripts/pruebas/README.md) |

---

## Documentación

| Documento | Contenido |
|---|---|
| [docs/REQUERIMIENTOS.md](docs/REQUERIMIENTOS.md) | Historias de usuario, requerimientos y su estado |
| [docs/ARQUITECTURA.md](docs/ARQUITECTURA.md) | Cómo está construido: capas, rutas, modelo de datos, ciclo de vida |
| [docs/PLAN-PRODUCTO.md](docs/PLAN-PRODUCTO.md) | Lo que falta, por prioridad |
| [docs/OPERACION.md](docs/OPERACION.md) | Entornos, credenciales, verificación y problemas conocidos |
| [docs/despliegue/](docs/despliegue/) | Instalación en la nube y en un servidor propio |
| [docs/pruebas/GUIA-TESTERS.md](docs/pruebas/GUIA-TESTERS.md) | Guía para el equipo de testing |
| [docs/adr/](docs/adr/) | Decisiones de arquitectura |
| [CONTRIBUTING.md](CONTRIBUTING.md) | Cómo se trabaja: ramas, pull requests y qué cuenta como terminado |

---

## Estructura

```
back/       API REST en PHP
  public/     punto de entrada (index.php)
  src/        controladores; Reglas/ y Ruteo/ con la lógica pura
  sql/        esquema y migraciones
  tests/      pruebas unitarias y de integración
FRONT/      aplicación React
  src/app/    pantallas, cliente de la API y simulador
  scripts/    pruebas de punta a punta y empaquetado de la demo
docs/       documentación del producto
```
