# SCGO — API REST

API REST en PHP sin framework (acceso a datos con PDO) sobre MariaDB / MySQL.
Es el backend del proyecto: el que está desplegado en Render y el que consume el
frontend.

Para el diseño del sistema ver [`../docs/ARQUITECTURA.md`](../docs/ARQUITECTURA.md)
y, para su alcance, [`../docs/REQUERIMIENTOS.md`](../docs/REQUERIMIENTOS.md);
para levantar todo el stack, [`../README.md`](../README.md).

## Requisitos

- PHP 8.2 o superior (el contenedor de Render corre 8.3).
- Extensión PDO con driver MySQL.
- [Composer](https://getcomposer.org/). No hay dependencias de producción: se usa
  para el autoload PSR-4 y para las herramientas de desarrollo (ADR-001 §5.1).

## Cómo levantarlo

```bash
composer install          # genera vendor/autoload.php (sin esto no arranca)
cp .env.example .env      # datos de la base y credenciales de Brevo
php -r "echo bin2hex(random_bytes(32));"   # pegarlo en JWT_SECRET: sin él la API no arranca
php sql/migrar.php        # crea las tablas (idempotente)
SEED_ADMIN_PASSWORD=una-clave-larga php sql/seed.php   # administrador inicial

# Sobre una base que YA existe, migrar.php no cambia columnas: sus tablas usan
# CREATE TABLE IF NOT EXISTS. Los cambios de tipo van en scripts aparte:
php sql/migracion-estado-enum.php   # proyecto.estado: VARCHAR -> ENUM
php -S localhost:8000 -t public
```

La API queda en `http://localhost:8000/api`. Con Apache o XAMPP, el
`public/.htaccess` ya trae la reescritura para que todo pase por `index.php`.

## Pruebas y análisis estático

```bash
composer test       # PHPUnit sobre back/tests/
composer phpstan    # PHPStan nivel 5; tiene que quedar en 0 errores
```

Hay dos niveles:

- **Unitarias**, sin base: las reglas de `src/Reglas/` (ciclo de vida de la obra y
  permisos por rol) y el ruteo de `src/Ruteo/`, que se recorre endpoint por
  endpoint.
- **De integración**, en `tests/Integracion/`, contra una MariaDB descartable.
  Vacían las tablas en cada prueba, así que solo leen las variables
  `SGSO_TEST_DB_*`, nunca `.env`, y abortan si el nombre de la base no contiene
  `test`. Sin esas variables se saltean; en el CI las provee el workflow.

## Estructura

Las clases de `src/` viven bajo el namespace `Sgso\` y las carga el autoload PSR-4
de Composer; no hay `require_once` a mano.

```
back/
  composer.json   <- autoload PSR-4 (Sgso\ -> src/) y herramientas de desarrollo
  composer.lock   <- versiones fijadas; se commitea
  public/
    index.php     <- arranque, mapa de manejadores, token y guarda de rol
    .htaccess     <- reescritura para Apache
  phpunit.xml     <- configuración de PHPUnit
  phpstan.neon    <- nivel 5 sobre src, public y tests
  tests/          <- pruebas, namespace Sgso\Tests\
  src/            <- namespace Sgso\
    Ruteo/                              <- tabla de rutas y despachador (dato + resolución pura)
    Reglas/                             <- reglas puras y probadas (CicloDeVida, Permisos)
    Env.php, Cors.php, Database.php     <- configuración, CORS y conexión PDO
    Jwt.php, AuthMiddleware.php         <- emisión y validación de tokens
    Mailer.php                          <- correo de recuperación (Brevo)
    Geocoder.php                        <- geocodificación de la ubicación de la obra
    *Controller.php                     <- un controlador por recurso
  sql/
    schema.sql    <- modelo relacional (17 tablas)
    migrar.php    <- aplica schema.sql (solo crea lo que falte)
    migracion-*.php            <- cambios de esquema sobre bases ya creadas, uno por cambio
    seed.php      <- usuario administrador inicial
  data/
    proyectos.seed.json   <- datos de ejemplo del prototipo (ya no se usan en runtime)
```

## Autenticación y roles

El login devuelve un **JWT** que hay que enviar en `Authorization: Bearer <token>`.
Las contraseñas se guardan hasheadas con bcrypt, nunca en texto plano.

Los grupos de roles viven en `Sgso\Reglas\Permisos` (`GESTION_OBRA`, `AVANCE`,
`DOC`, `REPORTE_APROBAR`, `ADMIN`). Cada ruta de `Sgso\Ruteo\Tabla` declara qué
grupo exige.

| Método | Ruta | Protección |
|---|---|---|
| POST | `/api/auth/login` | pública |
| POST | `/api/auth/olvide` | pública |
| POST | `/api/auth/restablecer` | pública |
| POST | `/api/auth/register` | solo `AdministradorSistema` |
| GET | `/api/auth/me` | requiere token |
| GET | `/api/health` | pública |

## Recursos

`proyectos`, `planificacion`, `materiales`, `maquinaria`, `reportes`, `analisis`
y `usuarios`. Bajo `proyectos/{id}` cuelgan además los subrecursos de
planificación, avances, asistencia, incidencias, materiales asignados,
documentos, períodos de inactividad e ítems excedentes.

### Ejemplo: CRUD de proyectos (CU1, CU2, CU3)

| Método | Ruta | Acción | Caso de uso |
|---|---|---|---|
| GET | `/api/proyectos` | Listar (`?q=texto` busca por nombre o ubicación) | — |
| GET | `/api/proyectos/{id}` | Ver uno | — |
| POST | `/api/proyectos` | Registrar | CU1 |
| PUT | `/api/proyectos/{id}` | Modificar | CU2 |
| DELETE | `/api/proyectos/{id}` | Eliminar | CU3 |

Body esperado en POST y PUT:

```json
{
  "nombre": "Obra Vial Ruta 14",
  "tipo": "Infraestructura Vial",
  "ubicacion": "Posadas, Misiones",
  "encargado": "Ing. Roberto Suénaga",
  "fechaInicio": "2026-01-15",
  "presupuesto": 15000000
}
```

Todos los campos son obligatorios. Si falta alguno responde `422` con
`{ "errors": { "nombre": "Obligatorio" } }`. Si ya existe un proyecto con el mismo
`nombre` y `ubicacion` responde `409` con `{ "error": "Obra ya existente" }`.

## Probarlo con curl

```bash
TOKEN=$(curl -s -X POST http://localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"...","contrasena":"..."}' | jq -r .token)

curl http://localhost:8000/api/proyectos -H "Authorization: Bearer $TOKEN"
```

## Notas del modelo

- Las columnas usan snake_case (`fecha_inicio`) y el frontend espera camelCase
  (`fechaInicio`); el mapeo lo resuelve `MySqlProyectoRepository`.
- `proyecto.encargado` es texto libre; debería ser una referencia a `usuario`.
- `proyecto.avance` se guarda en vez de calcularse desde `avance_fisico`.

Ambas están en el [plan de producto](../docs/PLAN-PRODUCTO.md) (C-09).
