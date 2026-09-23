# SGSO — Guía para testers

Todo lo necesario para empezar a probar el sistema. Está pensada para un equipo
externo que no participó del desarrollo.

**SGSO** (Sistema de Gestión y Seguimiento Operativo de Obras) es una aplicación
web para que una empresa constructora centralice la gestión de sus obras:
proyectos, planificación, avance físico, asistencia, materiales, maquinaria,
documentación, reportes y alertas de desvío. Es un proyecto académico de IC-413
(Ingeniería del Software I, UNaM, 2026).

---

## 1. Dónde probar

Hay dos entornos y conviene saber cuál usar, porque no son equivalentes.

| | Demo estática | Sistema real |
|---|---|---|
| URL | https://acostaalex10.github.io/SCGO/ | https://ingenieria-en-software-proyecto.vercel.app/ |
| Backend | ninguno: se simula en el navegador | PHP sobre MariaDB |
| Datos | completos, ficticios | reales del equipo, varias secciones vacías |
| Tus cambios | quedan solo en tu navegador | los ven todos |
| Disponibilidad | inmediata | la primera carga puede tardar ~1 minuto |

La demo pública de GitHub Pages se compiló desde `main` en modo de prueba.
Esta rama `testing` conserva la guía y puede tener código anterior al sitio publicado.

**Recomendación: empezar por la demo estática.** Tiene datos cargados en todas
las pantallas, no requiere esperas y no hay forma de romper nada: cada persona
trabaja sobre su propia copia. Es el entorno adecuado para recorrer el sistema,
diseñar casos de prueba y ejecutarlos.

**Usar el sistema real** cuando el caso de prueba necesite el backend de verdad:
persistencia entre sesiones y dispositivos, validaciones del servidor, códigos
de estado HTTP, o comportamiento con varios usuarios en simultáneo.

> La demo estática reproduce el contrato de la API real, incluidas las reglas de
> permisos, pero **no ejecuta PHP ni SQL**. Un defecto que solo exista en el
> backend no puede detectarse ahí.

### Volver los datos al punto de partida

Solo en la demo estática. Abrir la consola del navegador con F12 y ejecutar:

```js
sgsoMockReset()
```

---

## 2. Cuentas

### Demo estática

Abrir [la pantalla de inicio de sesión](https://acostaalex10.github.io/SCGO/#/login)
para probar el acceso. Al entrar por la URL principal sin una sesión previa también
aparece esa pantalla. Si el navegador ya recuerda una sesión y muestra el panel,
pulsar **Cerrar sesión** o abrir el enlace en una ventana privada.

Probar una cuenta válida, una contraseña incorrecta y la cuenta inactiva indicada
abajo. Después de entrar, comprobar el rol mostrado y cerrar sesión para repetir
la prueba con otro rol. Estas cuentas son ficticias y solo sirven en la demo.

| Rol | Email | Contraseña |
|---|---|---|
| Administrador del Sistema | `admin@sgso.test` | `admin123` |
| Personal Administrativo | `administrativo@sgso.test` | `admin123` |
| Personal Técnico | `tecnico@sgso.test` | `tecnico123` |
| Gerente | `gerente@sgso.test` | `gerente123` |

Hay además una cuenta dada de baja, `tecnico2@sgso.test`, para probar que el
sistema rechaza el acceso de usuarios inactivos.

### Sistema real

La cuenta de pruebas la provee el equipo de desarrollo. Tiene rol
**Administrador del Sistema**, así que permite todas las operaciones, incluidas
las destructivas: eliminar obras y dar de baja usuarios. Conviene no eliminar
las obras existentes, que se usan como demostración.

Solo puede haber **una sesión activa por cuenta**: si dos personas entran con la
misma, la primera se cierra sola. Si van a probar en paralelo, pídanle al equipo
una cuenta por persona.

---

## 3. Roles y permisos

Es uno de los ejes de prueba más importantes (RF19 y RF20). La autorización se
resuelve en el servidor, no en la interfaz: aunque una opción no se vea, la
operación se rechaza igual si se la invoca directamente.

| Operación | Administrador | Administrativo | Técnico | Gerente |
|---|:---:|:---:|:---:|:---:|
| Ver obras, análisis y reportes | sí | sí | sí | sí |
| Ver el presupuesto de la obra | sí | sí | **no** | sí |
| Crear, editar y eliminar obras | sí | sí | no | no |
| Cargar planificación y etapas | sí | sí | no | no |
| Asignar materiales a una obra | sí | sí | no | no |
| Registrar avance físico | sí | no | sí | no |
| Registrar asistencia e incidencias | sí | no | sí | no |
| Registrar consumo de materiales | sí | no | sí | no |
| Cargar documentos y reportes | sí | sí | sí | no |
| Aprobar o rechazar reportes | sí | sí | no | no |
| Gestionar usuarios y roles | sí | no | no | no |

Dos comprobaciones concretas que valen la pena:

- Entrando como **Técnico**, el presupuesto no debe aparecer en ninguna pantalla:
  ni en la lista de obras, ni en el panel de control, ni en Análisis y Alertas.
- Entrando como **Gerente**, no debe poder cargar nada, solo consultar.

---

## 4. Módulos

| Pantalla | Qué hace | Requerimientos |
|---|---|---|
| Panel de Control | indicadores globales: obras, avance promedio, presupuesto | RF05, RF06 |
| Proyectos | alta, edición, baja y búsqueda de obras; filtro por estado | RF01, RF02, RF03, RF18 |
| Detalle de obra | planificación por etapas, avance, y todo lo de esa obra | RF05, RF11, RF15 |
| Seguimiento | asistencia del personal e incidencias externas | RF06, RF08, RF09 |
| Materiales | asignación por obra y registro de consumo | RF04, RF10, RF12 |
| Documentación | documentos de la obra, guardados como enlace | RF07, RF16 |
| Reportes | carga, revisión y aprobación de reportes | RF17, RF21, RF22 |
| Alertas | desvíos de avance y análisis presupuestario | RF11, RF13 |
| Maquinaria | uso de equipos, fallas y rendimiento por operario | RF23, RF24, RF27, RF28 |
| Usuarios | alta de cuentas, roles y baja lógica | RF19, RF20 |

---

## 5. Reglas de negocio verificables

Sirven para saber qué resultado es el correcto.

**Alta de obras**

- Nombre, tipo, ubicación, encargado, fecha de inicio y presupuesto son obligatorios.
- No se admiten dos obras con el mismo nombre y la misma ubicación.
- La fecha de inicio no puede ser anterior al día de hoy.
- La ubicación se valida contra un servicio de mapas; si no se puede verificar,
  el sistema deja continuar igual.

**Estados de la obra**

Cambian solos a partir del avance físico, no se eligen a mano:

- `planificación` → `en ejecución` con el primer avance mayor a cero.
- `en ejecución` → `finalizada` al llegar al 100 %.
- El avance de la obra es el mayor porcentaje registrado.

**Planificación y avance**

- Las etapas se cargan con un peso porcentual y un rango de fechas; los pesos
  deben sumar 100 %.
- El avance esperado **no** es un número fijo: se calcula a la fecha, sumando el
  peso de cada etapa según la fracción de su plazo ya transcurrida.
- El desvío es la diferencia entre el avance real y ese esperado. Si el real está
  por debajo, se genera una alerta.

**Materiales**

- Primero se asigna un material a la obra con una cantidad; después se registran
  los consumos.
- Cuando el consumo acumulado supera la cantidad asignada, la asignación se marca
  como excedida y aparece la alerta correspondiente.

**Reportes**

- Circuito: `borrador` → `en revisión` → `aprobado` o `rechazado`.
- Un reporte rechazado vuelve a poder editarse y reenviarse.
- Solo se puede aprobar o rechazar un reporte que esté en revisión; intentarlo en
  otro estado devuelve error.

**Maquinaria**

- Cada registro de uso lleva horas, combustible y producción.
- Se marca un consumo anómalo cuando los litros por hora de ese registro superan
  en más del 50 % el promedio de esa máquina.

**Certificación**

- El importe certificado de una obra es su presupuesto por el porcentaje de
  avance. No lo ve el Técnico.

---

## 6. Recorrido sugerido

Un camino de punta a punta que toca casi todo el sistema:

1. Entrar como **Administrativo** y crear una obra nueva.
2. Abrirla y cargarle una planificación con dos o tres etapas.
3. Asignarle un material con una cantidad chica.
4. Cerrar sesión y entrar como **Técnico**.
5. Registrar un avance físico y comprobar que la obra pasó a "en ejecución".
6. Registrar asistencia, una incidencia y un consumo que supere lo asignado.
7. Crear un reporte y enviarlo a revisión.
8. Volver como **Administrativo** y aprobarlo.
9. Ir a Alertas y verificar que aparecen el desvío de avance y el material excedido.
10. Entrar como **Gerente** y confirmar que ve todo pero no puede cargar nada.

---

## 7. Limitaciones conocidas

No son defectos. Reportarlas hace ruido.

- **El sistema real tarda en la primera carga.** El servidor se suspende tras unos
  minutos sin uso y despertarlo lleva cerca de un minuto. La aplicación reintenta
  sola; hay que esperar.
- **En el sistema real varias secciones están vacías.** Asistencia, incidencias,
  documentos y materiales por obra no tienen datos cargados. En la demo estática sí.
- **La pantalla de Maquinaria tarda unos segundos** en mostrar el contenido.
- **La documentación se guarda como enlace**, no se suben archivos. Es una decisión
  de diseño: el servidor no conserva archivos entre reinicios.
- **Faltan estados de obra** respecto de lo diagramado: no existen "cancelada" ni
  "en revisión", y registrar un período de inactividad no pausa la obra. Está
  documentado en `REVISION-TPS.md`.
- **El sistema no tiene pruebas automatizadas.**

---

## 8. Cómo reportar un defecto

Un formato mínimo que alcanza:

```
Título:        una línea que describa el problema
Entorno:       demo estática | sistema real
Navegador/dispositivo: ...
URL:           ...
Rol:           con qué cuenta se estaba operando
Pasos:         1. ...
               2. ...
Resultado esperado:
Resultado obtenido:
Severidad:     alta | media | baja
Evidencia:     captura de pantalla
```

Dos cosas que ayudan mucho:

- **Indicar siempre el entorno y el rol.** La mitad del comportamiento del sistema
  depende del rol, y los dos entornos tienen datos distintos.
- **Si el defecto es de cálculo**, incluir los números concretos: presupuesto,
  porcentajes, cantidades. Permite reproducirlo sin adivinar.

Para revisar el detalle técnico de un caso, la consola del navegador (F12) muestra
las peticiones y el código de estado que devolvió el servidor.

### Si la pestaña muestra «This page crashed»

Si aparece el aviso **“acostaalex10.github.io crashed unexpectedly”** dentro de
un navegador integrado, pulsar **Open in external browser** y abrir la
[demo](https://acostaalex10.github.io/SCGO/) en Chrome o Edge. El aviso indica
que la pestaña del navegador se cerró inesperadamente; por sí solo no identifica
un error de la aplicación. Si el problema se repite en el navegador externo,
probar una ventana privada y reportar el dispositivo, sistema operativo, navegador,
URL y paso exacto en que ocurrió, junto con una captura.

---

## 9. Datos disponibles

**Sistema real**: cuatro obras (una en planificación, dos en ejecución y una
finalizada), un catálogo de diez materiales, cuatro máquinas y un reporte. Las
secciones de seguimiento y documentación están vacías.

**Demo estática**: las mismas cuatro obras, más los datos que en el sistema real
faltan: asistencias, incidencias, documentos, períodos de inactividad, ítems
excedentes, un consumo que excede lo asignado, reportes en los cuatro estados y
usuarios de los cuatro roles. Está preparada para que todas las pantallas tengan
algo que mostrar.
