# Requerimientos de SCGO

**SCGO — Sistema de Control y Gestión de Obras de construcción.** Cliente: **Triwe**,
empresa constructora.

Este es el documento de referencia del alcance del sistema. Si un requerimiento
cambia, se cambia acá primero.

- **Fuente:** la especificación del Trabajo Práctico 2 (2026), con las historias de
  usuario, los requerimientos y el alcance relevados en las entrevistas con la
  empresa. Está transcripta sin cambios de contenido: de ese trabajo salen los RF
  y los RNF de este documento. El PDF original estuvo en el repositorio hasta el
  2026-09-19 y se sacó porque trae datos personales de alumnos y docentes; lo
  conserva el equipo.
- **Estado:** contrastado contra el código el 2026-09-16. Lo que se afirma como
  cumplido está señalado en el código; lo que no se pudo verificar dice
  "sin verificar".

---

## 1. Problema y objetivo

La empresa gestiona sus obras con planillas de Excel y archivos sueltos en Google
Drive. De ahí salen los problemas que el sistema tiene que resolver:

- no hay control del presupuesto contra el avance real;
- no hay trazabilidad de materiales ni registro de consumos;
- los datos están dispersos, con versiones desactualizadas y pérdida de información;
- la comunicación entre la obra y la oficina es deficiente, lo que genera
  retrabajos y pérdidas económicas.

**Objetivo:** centralizar la información operativa de las obras en un único
sistema, con control presupuestario, trazabilidad y alertas de desvío.

---

## 2. Actores

| Actor | Qué hace en el sistema | Dónde lo usa |
|---|---|---|
| **Personal Administrativo** | Registra obras, presupuestos, contratos y materiales; revisa y aprueba reportes | Oficina, computadora |
| **Personal Técnico** (encargado de obra) | Registra avance, asistencia, consumos, uso de maquinaria e incidencias | **En obra, desde el celular** |
| **Gerencia** | Consulta indicadores, desvíos, comparativas e historial | Escritorio o celular |
| **Administrador del Sistema** | Gestiona cuentas y roles | Escritorio |

El uso desde el celular del Personal Técnico condiciona el diseño: ver RNF01,
RNF02 y RNF09.

---

## 3. Alcance

**Dentro:** gestión de proyectos y planificación, seguimiento operativo diario,
materiales, documentación, reportes con aprobación, análisis y alertas,
maquinaria, y control de acceso por roles.

**Fuera**, decidido en el relevamiento:

| Excluido | Por qué |
|---|---|
| Integración con ERP o software contable (por ejemplo, Xubio) | Exige APIs externas y reglas impositivas. **SCGO no es un sistema contable.** |
| Funcionamiento completamente offline | Sincronizar datos de forma segura es un problema en sí mismo |
| Inteligencia artificial y análisis predictivo | Requiere historia de datos que todavía no existe |
| Integración automática con WhatsApp | Dependencia externa y limitaciones técnicas del servicio |

> **Pendiente de confirmar con Triwe.** El diagrama de contexto del relevamiento
> muestra una consulta del estado de un lote a una base de datos de la
> municipalidad. No tiene ningún requerimiento funcional asociado y no está
> implementada (plan: D-10).

---

## 4. Historias de usuario

La columna **RF** se deriva del texto: cada historia se corresponde con los
requerimientos que la formalizan.

| HU | Historia | RF |
|---|---|---|
| HU01 | Como personal administrativo, quiero registrar y organizar diferentes proyectos de obra, para centralizar la información y administrar cada obra de manera independiente. | RF01, RF02 |
| HU02 | Como administrador de proyectos, quiero cargar la planificación inicial de la obra con ítems, plazos, presupuestos y avance esperado, para poder realizar el seguimiento y control del proyecto. | RF03 |
| HU03 | Como encargado de obra, quiero utilizar listas de tareas y materiales precargadas, para agilizar la carga diaria y reducir errores. | RF04 |
| HU04 | Como personal técnico, quiero registrar diariamente el avance físico mediante cantidades numéricas específicas, para mantener actualizada la ejecución real. | RF05 |
| HU05 | Como encargado de obra, quiero registrar la asistencia diaria del personal, para controlar los trabajadores presentes en cada jornada. | RF06 |
| HU06 | Como supervisor de obra, quiero adjuntar fotografías del avance de las tareas, para documentar visualmente el estado de la obra. | RF07 |
| HU07 | Como encargado de obra, quiero registrar incidencias, retrasos o justificaciones, para dejar constancia de problemas y justificar demoras. | RF08 |
| HU08 | Como supervisor de obra, quiero registrar eventos climáticos o fallas externas, para justificar formalmente extensiones de plazo o retrasos. | RF09 |
| HU09 | Como personal administrativo, quiero asignar materiales a una obra y registrar su consumo, para controlar los recursos utilizados. | RF10 |
| HU10 | Como gerente de proyecto, quiero recibir alertas automáticas cuando el avance real sea menor al planificado, para detectar desvíos y decidir a tiempo. | RF11 |
| HU11 | Como administrador de obra, quiero recibir alertas cuando se excedan las cantidades presupuestadas o los materiales asignados, para evitar sobrecostos. | RF12 |
| HU12 | Como gerente de proyecto, quiero visualizar la relación entre el avance físico y los gastos ejecutados, para detectar desviaciones presupuestarias. | RF13 |
| HU13 | Como directivo, quiero generar informes comparativos entre lo planificado y lo ejecutado, para evaluar el desempeño de las obras. | RF14 |
| HU14 | Como administrador de proyectos, quiero calcular montos a partir del porcentaje de avance físico, para generar certificaciones de obra. | RF15 |
| HU15 | Como personal administrativo, quiero almacenar y consultar documentos de la obra en PDF e imágenes, para mantener organizada la documentación. | RF16 |
| HU16 | Como administrador del sistema, quiero asignar roles y permisos, para restringir la información sensible según el tipo de usuario. | RF19, RF20 |
| HU17 | Como supervisor administrativo, quiero revisar y aprobar los reportes del personal de obra, para validar la información antes de emitir informes oficiales. | RF17, RF21 |
| HU18 | Como encargado de obra, quiero registrar ítems o trabajos excedentes no contemplados, para reflejar las modificaciones hechas durante la ejecución. | RF22 |
| HU19 | Como gerente de proyecto, quiero consultar información histórica de obras finalizadas, para mejorar futuras planificaciones. | RF18 |
| HU20 | Como encargado de taller, quiero registrar horas de uso, combustible y producción de cada máquina, para analizar su rendimiento. | RF23 |
| HU21 | Como supervisor de obra, quiero recibir alertas cuando el consumo de recursos no coincida con el rendimiento esperado, para detectar ineficiencias. | RF24 |
| HU22 | Como encargado de obra, quiero registrar períodos de inactividad con sus causas, para justificar retrasos y no distorsionar los reportes de eficiencia. | RF25 |
| HU23 | Como responsable de taller, quiero consultar el historial de fallas y reemplazos de una máquina, para identificar problemas recurrentes. | RF27 |
| HU24 | Como gerente de obra, quiero comparar el rendimiento de distintos operarios, para evaluar productividad y desempeño. | RF28 |

RF26 no tiene historia propia.

---

## 5. Requerimientos funcionales

**Estado:** *Cumplido* — implementado y en uso · *Parcial* — implementado con una
diferencia respecto del texto · *Pendiente* — no implementado.

| RF | El sistema debe… | Prioridad | Estado | Dónde |
|---|---|---|---|---|
| RF01 | permitir registrar, modificar y eliminar proyectos de obra | Crítica | Cumplido | `ProyectoController` |
| RF02 | organizar la información de manera independiente para cada obra | Crítica | Cumplido | todo cuelga de `proyecto` |
| RF03 | permitir cargar la planificación inicial: ítems, plazos, avance proyectado y presupuestos | Crítica | Cumplido | `PlanificacionController`, `EtapaPlanificacionController` |
| RF04 | permitir precargar listas de tareas y materiales con cantidades definidas | Importante | Cumplido | `MaterialController` (catálogo) |
| RF05 | permitir registrar diariamente el avance físico con métricas numéricas | Crítica | Cumplido | `AvanceController` |
| RF06 | permitir registrar la asistencia diaria del personal | Importante | Cumplido | `AsistenciaController` |
| RF07 | permitir adjuntar imágenes y reportes fotográficos del avance | Importante | **Pendiente** | ver desvío 1 |
| RF08 | permitir registrar justificaciones cuando no se cumple el avance o hay inasistencias | Importante | Cumplido | `AsistenciaController`, `IncidenciaController` |
| RF09 | permitir registrar incidencias externas: lluvias, fallas, retrasos de proveedores | Importante | Cumplido | `IncidenciaController` |
| RF10 | permitir asignar materiales a una obra y registrar su consumo | Crítica | Cumplido | `MaterialObraController` |
| RF11 | generar alertas cuando el avance real sea inferior al planificado | Importante | Cumplido | `AnalisisController` |
| RF12 | generar alertas cuando se excedan las cantidades presupuestadas o asignadas | Importante | Cumplido | `AnalisisController`, `MaterialObraController` |
| RF13 | calcular la diferencia entre el presupuesto estimado y los gastos ejecutados | Importante | Cumplido | `AnalisisController` |
| RF14 | generar reportes comparativos entre avance planificado y ejecutado | Importante | Cumplido | `AnalisisController`, `Dashboard` |
| RF15 | traducir el porcentaje de avance en montos para generar certificaciones | Secundaria | **Parcial** | ver desvío 4 |
| RF16 | permitir almacenar y consultar documentación en PDF e imágenes | Importante | **Parcial** | ver desvío 1 |
| RF17 | permitir registrar observaciones asociadas a reportes o incidencias | Secundaria | Cumplido | `ReporteController` (`observacion_revision`) |
| RF18 | permitir consultar información histórica de proyectos finalizados | Secundaria | Cumplido | `ProyectosPage` (filtro por estado) |
| RF19 | contar con distintos roles y permisos de acceso | Crítica | Cumplido | `Sgso\Reglas\Permisos`, `Sgso\Ruteo\Tabla` |
| RF20 | impedir que los usuarios de obra vean costos o precios | Crítica | Cumplido | `AnalisisController`, `ProyectoController` |
| RF21 | permitir revisar, editar y aprobar reportes antes de emitir informes definitivos | Importante | Cumplido | `ReporteController`, `Sgso\Reglas\CicloDeVida` |
| RF22 | permitir registrar ítems o excedentes no contemplados | Secundaria | Cumplido | `ItemExcedenteController` |
| RF23 | registrar el rendimiento de maquinaria: horas, combustible y producción | Importante | Cumplido | `MaquinariaController` |
| RF24 | comparar el consumo con el rendimiento esperado y alertar ante desvíos | Importante | **Parcial** | ver desvío 2 |
| RF25 | permitir registrar períodos de inactividad con su motivo | Importante | Cumplido | `InactividadController`, `Sgso\Reglas\CicloDeVida` |
| RF26 | clasificar incidencias por gravedad para activar protocolos de notificación | Secundaria | **Parcial** | ver desvío 3 |
| RF27 | mantener el historial de fallas y reemplazos de cada máquina | Importante | Cumplido | `MaquinariaController` |
| RF28 | generar comparativas de rendimiento entre operarios | Secundaria | Cumplido | `MaquinariaController::rendimientoOperarios()` |

**Resumen:** 23 cumplidos, 4 parciales y 1 pendiente.

### Qué tiene pruebas automáticas hoy

| Regla | Pruebas |
|---|---|
| RF19 — permisos, endpoint por endpoint | `back/tests/Reglas/PermisosTest.php`, `back/tests/Ruteo/TablaTest.php` |
| RF20 — costos ocultos al Personal Técnico | `FRONT/scripts/pruebas/humo.mjs` |
| RF21 — cierre de obra por reporte final | `back/tests/Integracion/CierrePorReporteFinalTest.php` |
| RF25 — pausa y reactivación por inactividad | `back/tests/Integracion/CicloDeVidaObraTest.php`, `FRONT/scripts/pruebas/inactividad.mjs` |
| Ciclo de vida de la obra completo | `back/tests/Reglas/CicloDeVidaTest.php` |

El resto de los requerimientos funciona, pero **no tiene una prueba que avise si se
rompe**. Ampliar esa cobertura es el ítem C-01 del [plan de producto](PLAN-PRODUCTO.md).

---

## 6. Requerimientos no funcionales

En el relevamiento figuran como *validados* con la empresa, es decir, acordados.
La columna **Estado** dice si el sistema los cumple hoy.

| RNF | El sistema debe… | Prioridad | Estado hoy |
|---|---|---|---|
| RNF01 | poder usarse desde celulares y computadoras con una interfaz responsive | Crítica | **Sin verificar.** La interfaz usa diseño adaptable, pero nadie la revisó pantalla por pantalla en un celular (plan: D-07). |
| RNF02 | permitir completar el registro diario de avance en menos de 10 minutos sin ayuda | Crítica | **Sin verificar.** Nunca se midió con un usuario real. |
| RNF03 | responder las consultas principales en menos de 5 segundos en el 90 % de los casos | Crítica | **No se cumple.** En el plan gratuito, Render suspende la API y la primera consulta puede tardar cerca de un minuto (plan: B-01). |
| RNF04 | permitir el acceso en tiempo real desde distintas ubicaciones con internet | Importante | Cumple. Es una aplicación web publicada. |
| RNF05 | almacenar la información de forma persistente e inmediata | Crítica | Cumple. Cada registro se escribe en la base en el momento. |
| RNF06 | exigir usuario y contraseña para acceder | Crítica | Cumple: límite de intentos por cuenta, mínimo de 10 caracteres y rechazo de las contraseñas más usadas. |
| RNF07 | hacer respaldos automáticos diarios | Importante | **Sin verificar.** Depende del plan contratado en Aiven, y nunca se probó restaurar un respaldo (plan: B-01). |
| RNF08 | soportar al menos 10 obras activas sin degradarse | Importante | **Sin verificar.** Con el volumen actual anda, pero ningún listado está paginado y no hubo prueba de carga (plan: C-03). |
| RNF09 | minimizar el texto a tipear en obra: listas, selección rápida y carga numérica | Importante | **Parcial.** Hay listas para materiales y etapas; falta revisar el resto de los formularios de campo. |
| RNF10 | sincronizar lo cargado en obra con la base en menos de 10 segundos, con conexión | Importante | Cumple con conexión, salvo el arranque en frío de RNF03. No hay modo offline, que está fuera de alcance. |

**Los dos que más importan para vender:** RNF03 y RNF07. Los dos dependen de
contratar la infraestructura (decisión DEC-04 del plan) y hoy no se cumplen o no se
pueden demostrar.

---

## 7. Desvíos conocidos

1. **RF07 y RF16 — los documentos se guardan como enlaces, no como archivos.**
   Render no conserva archivos entre reinicios, así que se optó por referenciar una
   URL externa. Se resuelve con almacenamiento de objetos (plan: DEC-03 y D-01). Hoy
   esta limitación además abre un agujero de seguridad (plan: A-01).
2. **RF24 — la alerta de consumo existe pero no se ve en Alertas.**
   `MaquinariaController` compara cada registro contra el promedio histórico de esa
   máquina y marca `alerta_consumo` si lo supera en más de 1,5 veces. Dos
   consecuencias: una máquina que siempre consume de más nunca alerta, porque no se
   aparta de su propio promedio; y la alerta aparece solo en el listado de la
   máquina, porque `AnalisisController` no la incluye (plan: D-03).
3. **RF26 — la gravedad se clasifica, pero no dispara ningún aviso.** Falta el
   protocolo de notificación por nivel (plan: D-02).
4. **RF15 — la certificación se calcula en el navegador.** El monto sale del
   porcentaje de avance en `ProyectoDetallePage`, no en el servidor. Un cálculo con
   impacto económico tiene que vivir en el backend, donde se puede probar y
   auditar (plan: D-09).
5. **Estado `creada` sin uso.** El modelo de la obra tiene siete estados y `creada`
   no lo asigna nadie: toda obra nueva arranca en `planificacion` (plan: D-04).

---

## 8. Cómo se mantiene este documento

- Un requerimiento nuevo o modificado se agrega acá **antes** de implementarlo.
- Al cerrar un ítem del plan que cambia un estado, se actualiza la tabla en el
  mismo pull request.
- Si una regla de negocio tiene prueba automática, se anota en la sección 5.
