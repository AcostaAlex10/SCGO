# Proceso de SCGO

Cómo se trabaja en SCGO: qué modelo se toma de referencia, quién hace qué (personas
y agentes de IA), cuánta autonomía tiene cada uno y cómo se controla que nadie
invente resultados.

El marco vive en este repositorio, y no en uno compartido, porque cada proyecto
se evalúa por separado: la evidencia de SCGO es de SCGO. Otro proyecto puede
copiarlo y adaptarlo.

Las reglas del día a día (ramas, PR, qué cuenta como terminado) siguen en
[CONTRIBUTING.md](../CONTRIBUTING.md). Este archivo dice **quién** las aplica.

---

## 1. Referencia: CMMI

Se toma **CMMI for Development v1.3** (SEI, 2010), que es pública y gratuita:
[CMU/SEI-2010-TR-033](https://apps.dtic.mil/sti/pdfs/ADA532839.pdf). Las versiones
2.0 y 3.0 son pagas. Se usa como guía, con los **nombres de la 3.0**, que son
los que usa hoy el CMMI Institute (ISACA). El repositorio es público: el modelo
se cita y se resume, no se copia.

**La vía es la de capacidad, no la de madurez.** La de capacidad deja elegir las
áreas y sirve para mejorar el proceso propio; la de madurez evalúa un dominio
entero y sirve para compararse con otras empresas, que hoy no hace falta.

CMMI 3.0 tiene 19 áreas en el dominio de Desarrollo: las 17 del núcleo más
solución técnica (TS) e integración del producto (PI). La equivalencia con las
22 de la v1.3 es **aproximada**: la 3.0 juntó, partió y renombró áreas.

| v1.3 | 3.0 | Dónde está en SCGO | Nivel estimado |
|---|---|---|---|
| REQM, RD | **RDM** Desarrollo y gestión de requisitos | `REQUERIMIENTOS.md`, criterios del CU en cada PR, el RF en las pruebas | 1, en camino a 2 |
| VER, VAL | **VV** Verificación y validación; **PR** Revisiones entre pares | CI con cuatro chequeos, pruebas, informe del Grupo 9, revisión de cada PR | 2 |
| RSKM | **RSK** Gestión de riesgos y oportunidades | `OPERACION.md` §8 | 1, en camino a 2 |
| PPQA | **PQA** Aseguramiento de la calidad del proceso | Lista de cierre de la plantilla de PR, agente Auditor | 1, en camino a 2 |
| CM | **CM** Gestión de la configuración | `main` protegida, PR obligatorio, tabla de ramas | 2 |
| PP | **PLAN** Planificación; **EST** Estimación | `PLAN-PRODUCTO.md`, tamaños S, M y L | 1 |
| PMC, IPM | **MC** Monitoreo y control | Sección 3 de `TRASPASO.md`, registro del plan | 1 |
| MA, OPP, QPM | **MPM** Gestión del rendimiento y la medición | Sin medición todavía | 0 |
| DAR | **DAR** Análisis de decisiones y resolución | `DEC-xx` del plan, ADR | 2 |
| CAR | **CAR** Análisis causal y resolución | Trampas de `TRASPASO.md` §4 | 1 |
| TS, PI | **TS**, **PI** (dominio DEV) | `ARQUITECTURA.md`, ADR-001, CI | 2 |
| OPF, OPM | **PCM** Gestión de procesos | Este archivo | 1 |
| OPD | **PAD** Desarrollo de activos de procesos | Este archivo, plantilla de PR, agentes | 1 |
| OT | **OT** Capacitación organizacional | `TRASPASO.md` como inducción | 1 |
| — | **GOV** Gobernanza; **II** Infraestructura de implementación | Secciones 2 a 4 de este archivo | 1 |
| SAM | Dominio de proveedores | No aplica: SCGO no contrata proveedores de desarrollo | — |

Los niveles son una autoevaluación, no una evaluación formal. Para contar como
evidencia, cada uno necesita artefactos y afirmaciones (sección 5).

---

## 2. Quién hace qué

### Personas

| Quién | Hace |
|---|---|
| **Alex** | Decide el diseño y las prioridades. Mergea. Maneja credenciales y producción (Render, Vercel, Aiven, Brevo). Corre las migraciones en Aiven. Es el responsable de los riesgos de `OPERACION.md` §8 |

Cuando se sume alguien al equipo, se agrega acá y se reparten los riesgos.

### Claude, el orquestador

La sesión principal de Claude **planifica y reparte; no escribe el volumen**. Al
empezar una tarea:

1. Repite el objetivo en una frase y marca el esfuerzo de cada paso.
2. Le pide al Analista los criterios de aceptación, si la tarea sale de un caso
   de uso.
3. Le pasa al Desarrollador un spec cerrado.
4. Le pide al Revisor, al Tester y al Auditor que revisen el resultado.
5. Hace verificar cada hallazgo por otro agente (sección 4) y le lleva a Alex
   solo lo confirmado, con lo descartado y su motivo.

Hace él mismo lo que delegar costaría más que hacerlo: cambios de pocas líneas,
decisiones que dependen de la conversación con Alex.

### Agentes

Cada rol es un agente de Claude Code en `.claude/agents/`, con su modelo. Los
modelos más chicos hacen el trabajo mecánico, para no gastar el modelo grande en
todo.

| Rol | Archivo | Modelo | Hace | No hace | Área |
|---|---|---|---|---|---|
| **Analista** | `analista.md` | sonnet | Del caso de uso saca los criterios de aceptación y el RF | Escribir código | RDM |
| **Desarrollador** | `desarrollador.md` | sonnet | Escribe el código de un spec cerrado. Si hay Codex disponible, delega con la skill `codex-programador` | Decidir diseño, commitear, tocar lo supervisado | TS |
| **Revisor** | `revisor.md` | opus | Revisa el diff de otro: errores, seguridad, reglas del repo | Revisar su propio trabajo, editar | PR, VV |
| **Tester** | `tester.md` | sonnet | Escribe y corre pruebas; verifica contra los criterios | Cambiar el código que prueba para que pase | VV |
| **Auditor** | `auditor.md` | haiku | Revisa que el PR cumpla la lista de cierre y estas reglas | Juzgar el código | PQA |

---

## 3. Autonomía

Lo que dice la clase sobre gobernar la IA: cada acción es autónoma,
semiautónoma o supervisada.

| Nivel | Qué significa | Acciones |
|---|---|---|
| **Autónoma** | El agente la hace y avisa | Leer, buscar, revisar, correr pruebas y chequeos, auditar un PR |
| **Semiautónoma** | El agente la hace, otro agente la verifica, y Alex la ve en el PR | Escribir código, pruebas y documentación (los agentes). Commitear, pushear a una rama propia y abrir un PR como borrador (solo el orquestador, después de la revisión) |
| **Supervisada** | Solo con el visto bueno de Alex, antes | Mergear. Cualquier cosa en producción. Credenciales. `back/sql/schema.sql` y migraciones. `.github/workflows/`. Agregar una dependencia. Borrar un archivo que la tarea no nombra. Cambiar la rama `testing` |

**Nunca, para nadie:** credenciales, tokens o datos de Triwe en el código, en un
archivo o en el chat; correr algo contra la base de Aiven (`back/.env` apunta a
producción, trampa 9 de `TRASPASO.md`).

---

## 4. Verificación cruzada

Lo que se encarga a un agente puede salir con alucinaciones o falsos positivos.
Por eso:

1. **Nadie revisa su propio trabajo.** El Revisor nunca es el que escribió el
   código, y conviene que sea otro modelo.
2. **Cada hallazgo lo verifica otro agente** antes de llegar a Alex. Lo que
   encuentra el Revisor lo intenta reproducir el Tester con una prueba; lo que
   encuentra el Tester lo mira el Revisor en el código; lo del Auditor lo
   comprueba el orquestador, que es mecánico.
3. **Sin evidencia, no cuenta.** Un hallazgo trae `archivo:línea`, la salida de
   un comando o una prueba que falla. "Parece que" no es un hallazgo.
4. **Lo descartado también se informa**, con el motivo en una línea.

---

## 5. Evidencia

CMMI separa dos tipos de evidencia, y cada proyecto junta la suya:

- **Artefactos:** lo que el trabajo produce. En SCGO sobran: PR con su
  descripción, corridas del CI, commits que explican el porqué, pruebas que
  nombran el RF, `TRASPASO.md`, `PLAN-PRODUCTO.md`, `OPERACION.md` §8.
- **Afirmaciones:** lo que la gente cuenta de cómo trabaja ("antes de cerrar un
  PR, marco los criterios del CU"). **Faltan:** nadie las escribió todavía. Una
  autoevaluación de capacidad las juntaría, área por área.

---

## 6. Cómo se cambia esto

Como cualquier otra cosa: por PR contra `main`, con su fila en `CONTRIBUTING.md`.
Si un agente se porta mal (inventa, se pasa de su rol), se corrige su archivo en
`.claude/agents/` y se anota en `TRASPASO.md` §4.
