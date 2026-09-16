# Plan de ejecución de ADR-001

Traduce la sección 5 de [ADR-001](ADR-001-stack.md) ("Qué implica C, concretamente")
a tareas ejecutables, en el orden en que se habilitan entre sí.

Medido sobre el código el 2026-09-11, en `main` @ `6643888`:

| | |
|---|---|
| Backend PHP | 25 clases, 4.519 LOC |
| `composer.json` | no existe |
| Pruebas de backend | ninguna |
| `index.php` | 549 líneas, 29 ramas de ruteo, 24 `require_once` a mano |
| Pruebas de frontend | 5 suites Playwright contra el simulador |
| CI | ninguno (el único workflow publica la demo estática desde `testing`) |

---

## Estado de los seis puntos del ADR

| # | Tarea (ADR §5) | Estado |
|---|---|---|
| 1 | `composer.json` con autoload PSR-4, mover `back/src/` a namespaces | **hecho** (Fase 1) |
| 2 | PHPUnit sobre ciclo de vida, permisos por rol y cierre por reporte final | **hecho** (2a y 2b) |
| 3 | PHPStan en nivel medio | **hecho** (nivel 5, 0 errores) |
| 4 | Reemplazar el ruteo de 29 ramas de `index.php` | **hecho** (tabla propia) |
| 5 | CI en GitHub Actions | **hecho** |
| 6 | `typescript` en el front y script `typecheck` | **hecho** (`366d379`) |

Quedan cinco. Ninguno toca lógica de negocio: son todos red de seguridad, que es
exactamente el problema que el ADR identificó ("el problema de SGSO hoy no es el
lenguaje, es que no hay red de seguridad").

---

## Fase 0 — Dos decisiones que el ADR dejó abiertas

No son código. Bloquean parte de lo que sigue, así que van primero.

### D1. Qué hacer con `back-node/` — **resuelto el 2026-09-11: borrado**

> Alex aprobó borrarlo. La carpeta salió del árbol de trabajo en esta rama; el
> código sigue en el historial de git (último commit que la tocó: `424972c`).
> Los documentos que la mencionaban quedaron actualizados.

El ADR lo dice explícitamente en "Qué NO decide este ADR": la recomendación es
archivarlo o borrarlo, pero necesita tu aprobación.

**Recomendación: borrarlo del árbol de trabajo.** Son 2.106 LOC congeladas en
`424972c` a las que les faltan `es_final`, `cancelada` y la sincronización de
estado por inactividad. Hoy no aporta nada y sí invita a tocar el backend
equivocado — que es el error que `CLAUDE.md` ya tiene que advertir por escrito.
Git no pierde nada: el commit queda en el historial y el ADR deja el puntero.

Si preferís conservarlo visible, la alternativa es un `README` en la carpeta que
diga que está congelado. Es más débil, pero es tu llamada.

### D2. Slim o tabla de rutas propia — **resuelto el 2026-09-11: tabla propia**

> Alex aprobó la recomendación. Se implementó en la Fase 4 sin agregar ninguna
> dependencia: el `Dockerfile` sigue igual y el arranque en frío de Render no
> cambia.

**Recomendación: tabla de rutas, sin framework.**

- No agrega dependencias en runtime: el `Dockerfile` sigue siendo trivial y el
  arranque en frío de Render no cambia — el propio ADR marcó en §4d que eso
  importa en el plan gratuito.
- No se tocan los controladores, que es la restricción que el ADR puso en negrita.
- El ADR ya autoriza esta salida: "por Slim, **o extraer una tabla de rutas**".

Slim resolvería lo mismo a cambio de una dependencia y de un ADR propio. No se
gana nada que no tengamos. Si más adelante aparece middleware de tenant para
Triwe, ahí sí se reabre.

---

## Fase 1 — Composer y PSR-4 (ADR §5.1)

Es el prerequisito de todo lo demás: sin Composer no hay forma razonable de
instalar PHPUnit ni PHPStan (§4b del ADR).

**Qué se hace**

1. `back/composer.json` con `autoload.psr-4: {"Sgso\\": "src/"}`, `require-dev`
   con PHPUnit y PHPStan, y `composer.lock` commiteado.
2. `namespace Sgso;` en las 25 clases de `back/src/`.
3. `index.php` pasa de 24 `require_once` a un solo `require __DIR__ . '/../vendor/autoload.php'`
   más los `use`. Igual en `back/sql/migrar.php`, `seed.php`,
   `migracion-estado-enum.php` y `migracion-reporte-final.php`, que hoy requieren
   `src/Env.php` a mano.
4. `.gitignore`: `back/vendor/`.

**Los dos riesgos, y cómo se cubren**

- **Las clases globales dejan de resolverse.** Dentro de un namespace, `new PDO(...)`
  se busca como `Sgso\PDO` y muere en runtime. Hay **30 usos de `PDO` y 3 de
  `DateTime`** en `back/src/`. Todos necesitan `\PDO` o un `use PDO;` arriba.
  Las funciones y constantes sí caen al global, así que `json_encode` y
  `JSON_UNESCAPED_UNICODE` no se tocan. Se verifica con `php -l` en los 31
  archivos más un arranque real de `php -S` contra `/api/health`.
- **El deploy de Render.** El `Dockerfile` copia `back/` y arranca Apache; si no
  existe `vendor/`, la API queda caída en producción. Hay que agregar
  `COPY --from=composer:2 /usr/bin/composer /usr/bin/composer` y
  `RUN composer install --no-dev --optimize-autoloader` **en el mismo commit**.
  Sin dependencias de producción eso solo genera el autoloader: no descarga nada
  y no agrega tiempo de arranque.

**Terminado cuando:** `php -l` limpio en los 31 archivos, `/api/health` responde
bajo `php -S`, y el build del `Dockerfile` corre localmente sin error.

---

## Fase 2 — PHPUnit (ADR §5.2)

El ADR nombra tres reglas sin ninguna prueba hoy: **ciclo de vida de la obra,
permisos por rol y cierre por reporte final**.

El obstáculo real es que los controladores reciben un `PDO` y responden con
`http_response_code()` + `echo`. Probarlos tal cual exige base y buffer de salida.
Por eso se parte en dos, y la primera mitad es la que tiene casi todo el valor.

### 2a. Extraer las reglas puras y probarlas sin base

> **Hecho.** Quedaron `Sgso\Reglas\CicloDeVida` y `Sgso\Reglas\Permisos`, con
> 115 pruebas en verde. Los cuatro controladores que decidían el estado por su
> cuenta ahora delegan, y las constantes `ROLES_*` de `index.php` salen de
> `Permisos`. Una prueba contrasta los estados contra
> `FRONT/src/app/estadosObra.ts`: la próxima desincronización con el front sale
> en rojo en vez de aparecer en la demo.

- `Sgso\Reglas\CicloDeVida` — la máquina de estados de los siete estados del TP3:
  qué transición es legal desde dónde. Hoy está repartida entre
  `InactividadController::sincronizarEstado()`, `ProyectoController` (cancelación)
  y `ReporteController` (cierre por reporte final).
- `Sgso\Reglas\Permisos` — los cinco grupos `ROLES_*` que hoy son constantes
  sueltas arriba de `index.php`, y `exigirRol()`.

Los controladores pasan a delegar en estas clases en vez de decidir ellos. No es
reescribir lógica: es moverla a donde se pueda mirar. Cobertura objetivo: 100 %
de las dos clases, que son puras y chicas.

Esto además ataca de frente el problema que el ADR marcó en §4c: la regla queda
escrita **una sola vez** y `FRONT/src/app/estadosObra.ts` puede generarse o
contrastarse contra ella, en lugar de repetirla a mano como hoy.

### 2b. Pruebas de integración sobre MariaDB

> **Hecho.** `back/tests/Integracion/` con 13 pruebas contra MariaDB: pausa y
> reactivación (incluido el caso en que vuelve a `en_revision` porque hay un
> reporte final esperando), cancelación, y el cierre por reporte final completo.
> En CI corren las 466 pruebas sin saltear ninguna.
>
> `CasoConBase` levanta el esquema real y vacía las tablas entre pruebas. No
> puede tocar una base que no sea descartable: lee **solo** las variables
> `SGSO_TEST_DB_*` —nunca `back/.env` ni `Sgso\Database`—, se saltea si no
> están definidas y aborta si el nombre de la base no contiene `test`.
>
> La tercera regla del ADR, permisos por rol, quedó cubierta en `TablaTest`:
> recorre los 117 endpoints y verifica que el `Gerente` no pase ninguno con
> guarda. Es más fuerte que probar unos pocos a mano, así que no se repite acá.

Un puñado de pruebas que levantan el esquema real y pegan a los controladores:
que cerrar el último período vigente devuelva la obra a `en_ejecucion` — o a
`en_revision` si hay un reporte final esperando —, que un `Gerente` reciba 403 en
todo lo que escribe, que aprobar el reporte final deje la obra `finalizada`.

Corren contra el servicio `mariadb` de GitHub Actions (Fase 5), no contra Aiven.
Nunca tocan la base de producción.

**Terminado cuando:** `composer test` verde en local, con las tres reglas del ADR
cubiertas.

---

## Fase 3 — PHPStan (ADR §5.3)

> **Hecho, y sin baseline.** El nivel 5 encontró solo tres redundancias — dos en
> código de producción y una en una prueba propia — y se arreglaron. El pozo era
> chico, así que el nivel queda en 5 y `phpstan.neon` cubre `src`, `public` y
> `tests`.

`phpstan.neon` apuntando a `back/src` y `back/public`.

El ADR pide "nivel medio, subiendo de a poco". El camino concreto: correr nivel 5
primero para ver el tamaño real del pozo. Si sale manejable, se arregla y queda
en 5. Si no, se congela lo viejo en `phpstan-baseline.neon`, se fija el nivel 5
para código nuevo y se va vaciando el baseline con cada tarea. Lo que **no** se
hace es bajar el nivel hasta que dé verde: eso es tener la herramienta apagada.

Casi todo lo que va a aparecer son `array<string,mixed>` sin forma declarada en
los `fetch()` de PDO. Se arregla con anotaciones, no cambiando comportamiento.

**Terminado cuando:** `composer phpstan` sale con 0 errores (con o sin baseline),
y el nivel está declarado en el `.neon`.

---

## Fase 4 — Tabla de rutas (ADR §5.4)

> **Hecho.** `Sgso\Ruteo`: `Tabla` (117 rutas como dato), `Despachador` (puro),
> `Ruta` y `Resolucion`. `index.php` baja de 549 a 281 líneas y queda con el
> arranque más un mapa `clave => closure`, que es lo único que conoce a los
> controladores. Ninguna firma de controlador cambió.
>
> Verificado comparando el ruteo viejo contra el nuevo en 35 peticiones: la
> única diferencia es la buscada (`DELETE /api/health` pasa de 200 a 405).
> Las guardas de rol se contrastaron una por una: DOC 15, AVANCE 9,
> GESTION_OBRA + REPORTE_APROBAR 16, ADMIN 2.

Con la decisión D2 tomada: extraer las 29 ramas de `index.php` a un array de
rutas declarativo — método, patrón, roles exigidos, controlador y método — y un
despachador de ~40 líneas que lo recorra.

**Restricción del ADR, textual: sin reescribir controladores.** Las firmas no
cambian. Lo único que se mueve es quién las llama.

Beneficio concreto más allá de la prolijidad: la tabla es enumerable, así que la
guarda de rol de cada endpoint se vuelve un dato — y la prueba de "permisos por
rol" de la Fase 2 puede recorrerla entera en vez de repetir los casos a mano.

**Terminado cuando:** las 5 suites de Playwright y las pruebas de integración
pasan igual que antes, y `index.php` baja de 549 líneas a un arranque + la tabla.

---

## Fase 5 — CI en GitHub Actions (ADR §5.5)

> **Hecho.** `.github/workflows/ci.yml` con los tres jobs, disparado en `main` y
> en los pull request hacia `main`. `testing` no se toca: su
> `pages-testing.yml` sigue siendo el único que corre ahí. El badge quedó en el
> README. La primera corrida dio los tres jobs en verde.
>
> Cobertura medida ahí (pcov, que en local no está): **100 % de líneas y de
> métodos** en `Sgso\Reglas` y `Sgso\Ruteo` — 174 líneas, 22 métodos, 6
> clases. Era el objetivo que la fase 2a había dejado sin medir.

Un workflow nuevo, `.github/workflows/ci.yml`, que corre en `push` a `main` y en
todo pull request:

| Job | Qué corre |
|---|---|
| `back` | `composer install`, PHPStan, PHPUnit (con servicio `mariadb`) |
| `front` | `npm ci --legacy-peer-deps`, `npm run typecheck`, `npm run build` |
| `e2e` | build con `VITE_MOCK=1` y las **5** suites de Playwright |

Dos aclaraciones sobre el texto del ADR: dice "las cuatro suites" porque se
escribió antes de que existiera `contrato.mjs`. Son cinco.

**Restricción no negociable:** este workflow **no toca `testing`**. `testing` está
congelada en `a25da85` y su `pages-testing.yml` es lo que ven los testers. El
workflow nuevo se dispara solo en `main` y en PRs.

**Terminado cuando:** un PR de prueba muestra los tres jobs en verde, y el badge
queda en el README.

---

## Orden y dependencias

```
D1 (back-node)  ──────────────────────────┐
D2 (ruteo)  ──────────────┐               │
                          │               │
Fase 1  Composer + PSR-4  │               │
   │                      │               │
   ├──> Fase 2  PHPUnit ──┼───────────────┼──> Fase 5  CI
   │                      │               │
   ├──> Fase 3  PHPStan ──┼───────────────┤
   │                      │               │
   └──> Fase 4  Rutas <───┘               │
              │                           │
              └──> refuerza Fase 2b ──────┘
```

La Fase 1 bloquea a todas. Las Fases 2 y 3 son independientes entre sí y pueden
ir en cualquier orden. La Fase 4 necesita D2. La Fase 5 va última porque corre lo
que las anteriores crean.

**Sugerencia de corte por sesión:** Fase 1 sola en una sesión, con su verificación
de deploy. Fases 2a + 3 juntas. Fase 4 sola. Fases 2b + 5 juntas, porque la
prueba de integración necesita el CI para tener base.

---

## Lo que queda pendiente y NO es de ADR-001

Para que no se mezcle: estas tareas están en `HANDOFF.md` §5 y son de producto,
no de infraestructura.

| Tema | Qué falta |
|---|---|
| RF07 / RF16 | Decidir documentos como archivo o como enlace. Es decisión de grupo, hay tres caminos. |
| RF26 | Protocolos de notificación según severidad de la incidencia. |
| Alertas | La alerta de consumo de maquinaria se calcula en `MaquinariaController` pero no aparece en la pantalla de Alertas. |
| Estados | `Creada` y `Planificado` existen en el ENUM pero la UI no los distingue. |
| Correcciones C1, C2, C3 | Son ediciones a los PDF del TP2. No se resuelven desde el repo. |

---

## Qué NO se hace en este plan

- No se toca lógica de negocio. Ni una regla cambia de comportamiento.
- No se toca `testing` ni la demo estática.
- No se corre nada contra la base de Aiven.
- No se decide multi-tenant: el ADR lo manda a su propio documento.
