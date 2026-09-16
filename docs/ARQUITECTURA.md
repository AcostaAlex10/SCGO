# Arquitectura de SCGO

Cómo está construido el sistema. Qué tiene que hacer está en
[REQUERIMIENTOS.md](REQUERIMIENTOS.md); por qué el backend es PHP, en
[ADR-001](adr/ADR-001-stack.md).

---

## 1. Piezas

| Capa | Carpeta | Tecnología | Dónde corre |
|---|---|---|---|
| Frontend | `FRONT/` | React 18, React Router 7, Vite 6, TypeScript, Tailwind CSS 4, shadcn/ui, Recharts, Leaflet | Vercel |
| API REST | `back/` | PHP 8.3 sin framework, PDO, Composer (PSR-4) | Render, contenedor Docker con Apache |
| Base de datos | `back/sql/` | MariaDB / MySQL | Aiven, con SSL |
| Seguridad | — | bcrypt para contraseñas, JWT (HS256) para la sesión | — |

El frontend y la API viven en orígenes distintos. La API valida el `Origin` contra
una lista blanca antes de habilitar CORS (`back/src/Cors.php`).

La instalación en un servidor propio de la empresa está en
[despliegue/ON-PREMISE.md](despliegue/ON-PREMISE.md); la de la nube, en
[despliegue/NUBE.md](despliegue/NUBE.md).

---

## 2. Backend

### Recorrido de un pedido

```
public/index.php
  ├─ carga el entorno, fija la zona horaria, envía CORS
  ├─ Sgso\Ruteo\Despachador::resolver(método, camino, Tabla::rutas())
  │     ├─ camino desconocido        → 404
  │     └─ método que no aplica      → 405 (después de exigir el token, si el camino es protegido)
  ├─ exige el token, salvo en las rutas públicas
  ├─ exige el grupo de roles que la ruta declara
  └─ llama al manejador → controlador
```

- **`Sgso\Ruteo\Tabla`** tiene las 117 rutas **como dato**: método, patrón, grupo
  de roles y clave del manejador. Como no contiene código, se puede recorrer
  entera en las pruebas: así se verifica, por ejemplo, que el Gerente no pase
  ninguna ruta de escritura.
- **`Sgso\Ruteo\Despachador`** es puro: no toca la base ni imprime.
- **`public/index.php`** tiene el mapa de clave a controlador. Es el único lugar
  que conoce a los controladores.

**Para agregar un endpoint:** una fila en la tabla y su manejador en `index.php`.
Si falta alguno de los dos, `TablaTest` falla.

### Reglas de negocio

Las decisiones que antes tomaba cada controlador por su cuenta viven en
`Sgso\Reglas`, sin base de datos ni HTTP, y con cobertura completa:

- **`CicloDeVida`** — los siete estados de la obra y qué transición es legal.
- **`Permisos`** — los grupos de roles del RF19.

Una regla de estado se cambia ahí, no en un controlador.

### Controladores

Un controlador por recurso en `back/src/`. Reciben un `PDO` y contestan con
`http_response_code()` y `echo` de JSON.

Piezas transversales: `Env` (lee `.env`), `Cors`, `Database` (conexión PDO
única), `Jwt`, `AuthMiddleware`, `Mailer` (correo de recuperación por Brevo) y
`Geocoder` (ubicación de la obra).

La zona horaria está fijada en `America/Argentina/Buenos_Aires`: Render corre en
UTC y las validaciones de fecha dependen del día local.

### Sesión

El login devuelve un JWT que viaja en `Authorization: Bearer <token>`. Cada login
regenera `usuario.sesion_token`, así que **una cuenta tiene una sola sesión
activa**: entrar desde otro lado cierra la anterior.

---

## 3. Frontend

Entrada: `index.html` → `src/main.tsx` → `src/app/App.tsx` → `src/app/routes.tsx`.

| Ruta | Pantalla | Para qué |
|---|---|---|
| `/login`, `/olvide`, `/restablecer` | públicas | acceso y recuperación de contraseña |
| `/` | `Dashboard` | indicadores y comparativas |
| `/proyectos` | `ProyectosPage` | alta, edición, baja y búsqueda de obras |
| `/proyectos/:id` | `ProyectoDetallePage` | detalle de una obra |
| `/seguimiento` | `SeguimientoPage` | avance, asistencia, incidencias, inactividad |
| `/materiales` | `MaterialesPage` | asignación y consumo |
| `/documentacion` | `DocumentacionPage` | documentos de cada obra |
| `/reportes` | `ReportesPage` | reportes y su aprobación |
| `/alertas` | `AlertasPage` | alertas de desvío |
| `/maquinaria` | `MaquinariaPage` | uso, fallas y rendimiento |
| `/usuarios` | `UsuariosPage` | cuentas y roles |

Todas las rutas salvo las públicas cuelgan del layout `Root`, que exige sesión.

- Todas las llamadas a la API pasan por `transporte()` en `src/app/auth/api.ts`,
  que decide entre la API real y el simulador según `VITE_MOCK`.
- **Modo de prueba:** `src/app/mock/servidor.ts` reproduce la API en memoria para
  publicar una demo sin backend. Si una validación cambia en PHP, tiene que
  cambiar ahí también (ver [FRONT/MODO-PRUEBA.md](../FRONT/MODO-PRUEBA.md)).
- Alias `@` → `FRONT/src`. Pantallas en `src/app/components/`; primitivos de
  shadcn/ui en `src/app/components/ui/`.
- Tema oscuro con acento naranja (`--primary: #e8981e`) en `src/styles/theme.css`.

### Módulo → código

| Módulo | Frontend | Backend |
|---|---|---|
| Autenticación | `LoginPage`, `OlvidePage`, `RestablecerPage` | `AuthController`, `AuthMiddleware`, `Jwt`, `Mailer` |
| Proyectos | `ProyectosPage`, `ProyectoDetallePage`, `MapaProyectos` | `ProyectoController`, `MySqlProyectoRepository`, `Geocoder` |
| Planificación y avance | `SeguimientoPage` | `PlanificacionController`, `EtapaPlanificacionController`, `AvanceController` |
| Seguimiento operativo | `SeguimientoPage` | `AsistenciaController`, `IncidenciaController`, `InactividadController`, `ItemExcedenteController` |
| Materiales | `MaterialesPage` | `MaterialController`, `MaterialObraController` |
| Maquinaria | `MaquinariaPage` | `MaquinariaController` |
| Documentación | `DocumentacionPage` | `DocumentoController` |
| Reportes | `ReportesPage` | `ReporteController` |
| Análisis y alertas | `AlertasPage`, `Dashboard`, `ChartsPanel` | `AnalisisController` |
| Usuarios | `UsuariosPage` | `UsuarioController` |

---

## 4. Modelo de datos

Diecisiete tablas en `back/sql/schema.sql`. La entidad central es `proyecto`: de
ella cuelgan la planificación con sus etapas y avances, la asistencia, las
incidencias, los materiales, los documentos, los reportes y los períodos de
inactividad.

| Grupo | Tablas |
|---|---|
| Acceso | `usuario` (rol, activo, hash bcrypt, token de sesión y de recuperación) |
| Obra | `proyecto`, `planificacion`, `etapa_planificacion`, `avance_fisico` |
| Seguimiento | `asistencia`, `incidencia`, `periodo_inactividad`, `item_excedente` |
| Materiales | `material`, `asignacion_material`, `consumo_material` |
| Maquinaria | `maquinaria`, `registro_maquinaria`, `falla_maquinaria` |
| Documentación | `documento`, `reporte` |

Los estados se guardan en minúscula y snake_case (`en_ejecucion`).

### Ciclo de vida de la obra

```
creada → planificacion → en_ejecucion ⇄ pausada
                              ↕            ↑
                         en_revision ──────┘
                              ↓
                         finalizada          (y cancelada, desde
                                              en_ejecucion o pausada)
```

| Transición | Qué la provoca | Dónde |
|---|---|---|
| `planificacion → en_ejecucion` | el primer avance mayor a cero | `AvanceController` |
| `en_ejecucion` o `en_revision` `→ pausada` | un período de inactividad vigente | `InactividadController` |
| `pausada → en_ejecucion` | se cierra el último período vigente | `InactividadController` |
| `pausada → en_revision` | ídem, si hay un reporte final esperando revisión | `InactividadController` |
| `en_ejecucion → en_revision` | se envía el reporte marcado como final | `ReporteController` |
| `en_revision → finalizada` | se aprueba el reporte final | `ReporteController` |
| `en_revision → en_ejecucion` | se rechaza el reporte final | `ReporteController` |
| `en_ejecucion` o `pausada` `→ cancelada` | decisión manual de un rol de gestión | `ProyectoController` |

Todas las decisiones pasan por `Sgso\Reglas\CicloDeVida`. Tres reglas que no son
obvias:

- **El avance no finaliza la obra.** Llegar al 100 % es un dato. La obra se cierra
  cuando se aprueba el reporte final.
- **Cancelar es el único cambio manual.** Cualquier otro estado enviado a mano se
  rechaza con 422; cancelar una obra que no está en marcha, con 409. El alta
  ignora el estado recibido.
- **Un período está vigente** si ya empezó y todavía no terminó. `fecha_fin` es el
  día en que la obra vuelve a arrancar, así que cerrar un período con la fecha de
  hoy la reactiva hoy. Un período histórico sobre una obra terminada no la revive.

Si mientras un reporte final espera la obra se pausa o se cancela, resolverlo
registra la decisión en el reporte y deja la obra donde está.

### Otros estados

- **Reporte:** `borrador → en_revision → aprobado | rechazado`. Un rechazado se
  puede corregir y volver a enviar; el motivo queda en `observacion_revision`.
- **Asistencia:** `presente`, `ausente`, `tarde`.
- **Incidencia:** tipo `clima`, `falla_maquinaria`, `proveedor`, `otro`; gravedad
  `baja`, `media`, `alta`.

---

## 5. Calidad

| Qué | Cómo se corre | Dónde |
|---|---|---|
| Pruebas del backend | `composer test` | `back/tests/` |
| Análisis estático | `composer phpstan` (nivel 5, cero errores) | `back/phpstan.neon` |
| Tipos del frontend | `npm run typecheck` | `FRONT/` |
| Pruebas de punta a punta | cinco suites de Playwright | `FRONT/scripts/pruebas/` |

Las tres primeras y las suites corren en cada pull request
(`.github/workflows/ci.yml`). Las pruebas de integración necesitan una base
descartable y solo leen las variables `SGSO_TEST_DB_*`; sin ellas se saltean.
