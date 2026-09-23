# Traspaso de contexto — SGSO

Punto de entrada para retomar el trabajo desde otra máquina, otra sesión o la
nube. Resume dónde está cada cosa, qué está hecho, qué falta y con qué trampas
ya nos topamos.

Última actualización: 31 de agosto de 2026.

---

## 1. Cómo retomar

**Repositorio:** `AcostaAlex10/SCGO` (se llamaba `ingenieria-en-software-proyecto` hasta el 2026-09-16) — público.

**Rama de trabajo: `testing`.** Es donde está todo lo último. `main` va algunos
commits atrás; si trabajás desde la nube, empezá por `testing` o mergeala a
`main` primero.

```bash
git clone https://github.com/AcostaAlex10/SCGO.git
cd SCGO
git checkout testing
```

**Qué leer, en este orden:**

| Documento | Para qué |
|---|---|
| `README.md` | arquitectura y cómo levantar el sistema |
| `DOCUMENTACION.md` | contexto del proyecto, modelo de datos, trazabilidad con los TP |
| `REVISION-TPS.md` | correcciones del docente y divergencias entre los TP y el código |
| `GUIA-TESTERS.md` | lo que necesita un equipo externo para probar |
| `FRONT/MODO-PRUEBA.md` | el modo estático: cómo funciona y cómo publicarlo |
| `DEPLOY.md` | despliegue en la nube |
| `back/README.md` | la API PHP en detalle |

---

## 2. Entornos

| | URL | Qué es |
|---|---|---|
| Sistema real | https://ingenieria-en-software-proyecto.vercel.app/ | SPA en Vercel contra la API PHP |
| API | https://ingenieria-en-software-proyecto.onrender.com/api | PHP + Apache en Render (Docker) |
| Base | Aiven | MySQL/MariaDB gestionada |
| Demo estática | https://acostaalex10.github.io/SCGO/ | GitHub Pages, sin backend |

La demo estática se publica sola con el workflow `.github/workflows/pages-testing.yml`
en cada push a `main` o `testing`. Compila con `BASE_PATH=./`,
`VITE_HASH_ROUTER=1` y `VITE_MOCK=1`, que es la combinación verificada sirviendo
el sitio desde un subdirectorio.

Comprobación rápida de que todo sigue en pie:

```bash
curl -s https://ingenieria-en-software-proyecto.onrender.com/api/health
curl -s -o /dev/null -w "%{http_code}\n" https://acostaalex10.github.io/SCGO/
```

La API puede tardar cerca de un minuto en responder la primera vez: el plan
gratuito de Render suspende el servicio tras unos minutos sin uso.

---

## 3. Estructura del código

```
FRONT/            SPA React 18 + Vite 6 + TypeScript
  src/app/components/   una pantalla por módulo
  src/app/api/          cliente de la API
  src/app/auth/         sesión, permisos y api.ts (punto único de salida)
  src/app/mock/         servidor simulado + datos.json  ← modo estático
  scripts/              empaquetado de la demo en un solo .html
back/             API REST en PHP 8 sin framework — este es el backend
  public/index.php      front controller: rutas, token y roles
  src/                  un controlador por recurso
  sql/                  schema.sql (17 tablas), migrar.php, seed.php
back-node/        API equivalente en Node — NO se despliega, no tocar
```

**El backend del proyecto es `back/` (PHP).** La cátedra lo exige. `back-node/`
quedó como alternativa histórica.

Todas las peticiones del frontend pasan por `transporte()` en
`FRONT/src/app/auth/api.ts`, que elige entre la API real y el simulador según
`VITE_MOCK`. Si agregás un endpoint en PHP, agregá su equivalente en
`FRONT/src/app/mock/servidor.ts` o el modo estático responderá 404 en esa ruta.

---

## 4. Estado

Los diez módulos están implementados y funcionando: proyectos, planificación por
etapas, avance físico, seguimiento (asistencia e incidencias), materiales con
control de excedidos, documentación, reportes con circuito de aprobación,
análisis y alertas, maquinaria, y gestión de usuarios.

Los roles y permisos (RF19) se aplican en el servidor, y el Personal Técnico no
recibe el presupuesto (RF20). Verificado contra producción y contra el modo
estático.

El TP4 está cerrado. La demo estática está publicada y verificada.

---

## 5. Pendientes

**Del código**, ninguno bloqueante. En orden de valor:

1. Convertir `proyecto.estado` en `ENUM`. Hoy es un `VARCHAR(30)` sin
   restricción, así que admite cualquier valor.
2. Pausar la obra al registrar un período de inactividad. El TP3 define esa
   transición y `InactividadController` no toca el estado.
3. Permitir cancelar una obra. Es el único estado terminal del TP3 que el sistema
   no ofrece, y el formulario no incluye el campo estado.
4. Incorporar `EnRevision` para el proyecto, ligado a la aprobación del reporte
   final. Es el cambio más invasivo.

**De la documentación**, las correcciones del docente que están en `REVISION-TPS.md`:
el nombre del CU22, sus pre y poscondiciones, la transición `EnRevision → Pausado`
en el ciclo de la obra, y el estado `Cancelado` en el ciclo del reporte.

**Deuda técnica** anotada en `DOCUMENTACION.md`: no hay pruebas automatizadas,
`back-node/` duplica el backend, las migraciones están descritas por duplicado,
`proyecto.encargado` es texto libre en vez de una referencia a `usuario`, y
`proyecto.avance` se guarda plano en lugar de calcularse desde `avance_fisico`.

**Operativo:** hay un solo administrador activo. Si se pierde el acceso a esa
cuenta no hay forma de entrar a gestionar usuarios, porque el sistema exige que
siempre quede al menos uno. Conviene tener un segundo administrador de respaldo.

---

## 6. Credenciales

**Ninguna credencial va en este repositorio, que es público.**

| Qué | Dónde está |
|---|---|
| Base Aiven, JWT, Brevo | variables de entorno del servicio en Render |
| Cuentas del sistema | las administra el equipo desde la pantalla de Usuarios |
| Cuenta para testers | la provee el equipo por separado |

El seed ya no trae contraseña escrita en el código: la toma de
`SEED_ADMIN_PASSWORD` y aborta si no está definida.

```bash
SEED_ADMIN_PASSWORD=una-clave-larga php back/sql/seed.php
```

> Antecedente: `back/sql/seed.php` tenía la contraseña del administrador
> hardcodeada. Al pasar el repositorio a público quedó expuesta en el historial.
> La cuenta `admin@sgso.com` fue dada de baja y reemplazada. La contraseña sigue
> en el historial de git, pero ya no sirve para entrar.

Las únicas contraseñas que sí están versionadas, a propósito, son las del modo
estático (`FRONT/src/app/mock/datos.json`): son ficticias, con dominio `.test`, y
no existen en ningún sistema real.

---

## 7. Trampas conocidas

Cosas que ya nos costaron tiempo. Vale la pena leerlas antes de repetirlas.

**GitHub Actions — reintentar un run viejo no sirve.** "Re-run failed jobs" sobre
un run de días atrás falla con `No artifacts named "github-pages" were found`.
`actions/upload-pages-artifact` retiene el artefacto un solo día y el job de
compilación no se vuelve a ejecutar. Hay que mirar el run nuevo, o usar "Re-run
all jobs".

**GitHub Pages necesita habilitarse explícitamente.** Que el repositorio sea
público no alcanza: hay que poner Settings → Pages → Source en "GitHub Actions".
Sin eso el despliegue falla con `status: 404` aunque el build pase.

**Sesión única por cuenta.** Iniciar sesión con la misma cuenta en otro lado
cierra la anterior. Aparece como "se cerró sola" cuando en realidad es el
comportamiento esperado.

**El servidor de pruebas de Python sirve `.js` como `text/plain`** en Windows, y
el navegador rechaza el módulo. Además es mono-hilo y se cuelga con las
conexiones persistentes del navegador. Para probar un build estático hay que
usar un servidor que fije el MIME y sea multihilo.

**`String.replace()` con el bundle minificado.** El código minificado contiene la
secuencia `$&`, que `replace()` interpreta como "el texto que coincidió" y
sustituye en silencio, corrompiendo el JavaScript. Hay que pasar el reemplazo
como función. Lo aplica `FRONT/scripts/demo-un-archivo.mjs`.

**El antivirus borra los scripts PHP que se conectan a la base.** `migrar.php` y
similares desaparecen del working tree. Están commiteados, así que conviene no
hacer `git add -A` a ciegas.

---

## 8. Cómo verificar que sigue todo bien

```bash
# Compilar el modo estático y servirlo
cd FRONT
npm install --legacy-peer-deps
BASE_PATH=./ VITE_MOCK=1 VITE_HASH_ROUTER=1 npm run build

# Empaquetar la demo en un archivo suelto
VITE_MOCK=1 VITE_HASH_ROUTER=1 ARCHIVO_UNICO=1 npm run build
node scripts/demo-un-archivo.mjs      # deja dist/demo.html

# Levantar el sistema completo en local
php back/sql/migrar.php
php -S localhost:8000 -t back/public
cd FRONT && npm run dev
```

Un recorrido de humo sobre la demo estática: entrar con cada uno de los cuatro
roles, comprobar que el Técnico no ve presupuesto y que no le aparece el menú de
Usuarios, y recargar la página estando en `#/alertas` para confirmar que la ruta
sobrevive. Las cuentas están en `FRONT/MODO-PRUEBA.md`.
