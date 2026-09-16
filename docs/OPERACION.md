# Operación de SCGO

Entornos, credenciales, cómo comprobar que todo anda, y los problemas que ya nos
costaron tiempo.

---

## 1. Entornos

| Entorno | URL | Qué es | Se publica desde |
|---|---|---|---|
| Producción — sistema | https://ingenieria-en-software-proyecto.vercel.app/ | La aplicación contra la API real | `main` |
| Producción — API | https://ingenieria-en-software-proyecto.onrender.com/api | PHP + Apache en Docker | `main` |
| Producción — base | Aiven | MariaDB / MySQL gestionada, con SSL | — |
| Demo para testers | https://acostaalex10.github.io/ingenieria-en-software-proyecto/ | Frontend sin backend, con datos simulados | `testing` (congelada) |

Todo lo que llega a `main` se despliega. Por eso `main` solo cambia por pull
request con el CI en verde (ver [CONTRIBUTING.md](../CONTRIBUTING.md)).

La demo se publica con `.github/workflows/pages-testing.yml` en cada push a
`testing`, y compila con `BASE_PATH=./`, `VITE_HASH_ROUTER=1` y `VITE_MOCK=1`.
**`testing` no se toca**: es lo que usan los testers.

**Comprobación rápida:**

```bash
curl -s https://ingenieria-en-software-proyecto.onrender.com/api/health
curl -s -o /dev/null -w "%{http_code}\n" https://acostaalex10.github.io/ingenieria-en-software-proyecto/
```

La API puede tardar cerca de un minuto la primera vez: el plan gratuito de Render
suspende el servicio tras unos minutos sin uso. Es el motivo por el que hoy no se
cumple el RNF03 (ver [REQUERIMIENTOS.md](REQUERIMIENTOS.md)).

---

## 2. Credenciales

**Ninguna credencial va en este repositorio**, que hoy es público.

| Qué | Dónde está |
|---|---|
| Base (Aiven), secreto de los tokens, correo (Brevo) | variables de entorno del servicio en Render |
| Cuentas del sistema | se administran desde la pantalla de Usuarios |
| Cuenta para testers | la entrega el equipo por separado |

El seed no trae contraseña en el código: la toma de `SEED_ADMIN_PASSWORD` y aborta
si no está definida.

```bash
SEED_ADMIN_PASSWORD=una-clave-larga php back/sql/seed.php
```

> **Antecedente.** `back/sql/seed.php` tuvo la contraseña del administrador escrita
> en el código, y quedó en el historial de git al publicarse el repositorio. Esa
> cuenta se dio de baja y se reemplazó: la contraseña del historial ya no sirve.

Las únicas contraseñas versionadas a propósito son las del modo de prueba
(`FRONT/src/app/mock/datos.json`): son ficticias, con dominio `.test`, y no existen
en ningún sistema real.

**Conviene tener siempre dos administradores activos.** El sistema exige que quede
al menos uno, así que si se pierde el acceso a la única cuenta no hay forma de
volver a gestionar usuarios.

---

## 3. Cómo verificar que todo anda

```bash
# Backend: pruebas y análisis estático
cd back
composer install
composer test
composer phpstan

# Frontend: tipos y build
cd FRONT
npm install --legacy-peer-deps
npm run typecheck
npm run build

# Sistema completo en local
php back/sql/migrar.php
php -S localhost:8000 -t back/public
cd FRONT && npm run dev
```

Todo esto corre solo en cada pull request. Las cinco suites de Playwright y cómo
ejecutarlas a mano están en
[FRONT/scripts/pruebas/README.md](../FRONT/scripts/pruebas/README.md).

**Demo en un solo archivo**, para compartir sin servidor:

```bash
cd FRONT
VITE_MOCK=1 VITE_HASH_ROUTER=1 ARCHIVO_UNICO=1 npm run build
node scripts/demo-un-archivo.mjs      # deja dist/demo.html
```

---

## 4. Problemas conocidos

Cosas que ya costaron tiempo. Conviene leerlas antes de repetirlas.

### Base de datos

**`migrar.php` no aplica cambios de tipo de columna.** Solo ejecuta `schema.sql`,
cuyas tablas usan `CREATE TABLE IF NOT EXISTS`: sobre una base existente no hace
nada. Un cambio de tipo necesita su propio script con el `ALTER`, como
`migracion-estado-enum.php`. Por eso el esquema del repositorio y el de Aiven
pueden no coincidir.

**El antivirus borra los scripts PHP que se conectan a la base.** `migrar.php` y
similares desaparecen del árbol de trabajo. Están versionados: si falta uno,
`git checkout` sobre ese archivo, y cuidado con un `git add -A` a ciegas.

### Sesión

**Una cuenta tiene una sola sesión.** Entrar desde otro lado cierra la anterior.
Aparece como "se cerró sola", pero es el comportamiento esperado.

### Modo de prueba

**El simulador se desincroniza del backend sin que nadie lo note.**
`FRONT/src/app/mock/servidor.ts` reproduce la API, pero es código aparte: si PHP
valida algo y el simulador no, la demo acepta datos que el sistema real rechaza.
Ya pasó con el rango de fechas y con las etapas. **Al tocar una validación en PHP,
tocá también el simulador.**

**En el simulador, las obras se identifican por `id`, no por `id_proyecto`.** El
resto de las colecciones sí usa `id_proyecto`. Un `find` con la clave equivocada
devuelve `undefined` en silencio.

**La clave de `localStorage` del simulador lleva la huella de `datos.json`.** Sin
eso, republicar la demo dejaba a quien ya había entrado viendo su copia vieja. No
la vuelvas a una constante fija.

### Build y publicación

**El preview de Vercel de una rama mezcla frontend nuevo con backend viejo.** El
frontend es el de la rama, pero apunta a la API de Render, que se despliega desde
`main`. Una función que dependa de un endpoint nuevo va a fallar en el preview
hasta que el backend esté desplegado.

**`String.replace()` sobre el bundle minificado lo corrompe.** El código contiene
la secuencia `$&`, que `replace()` interpreta como "el texto encontrado". Hay que
pasar el reemplazo como función; así lo hace `FRONT/scripts/demo-un-archivo.mjs`.

**El servidor de pruebas de Python no sirve para la demo.** En Windows entrega los
`.js` como `text/plain` y el navegador los rechaza; además se cuelga con las
conexiones persistentes. Usar `http-server` u otro que fije el tipo MIME.

### GitHub

**GitHub Pages hay que habilitarlo a mano.** Settings → Pages → Source:
"GitHub Actions". Sin eso el despliegue falla con `status: 404` aunque el build
pase.

**Reintentar un run viejo de Pages no sirve.** El artefacto dura un día; "Re-run
failed jobs" sobre un run anterior falla con `No artifacts named "github-pages"`.
Usar "Re-run all jobs".

### Entorno local (Windows)

**Composer falla con `curl error 60`.** Hay un antivirus que intercepta TLS y su
certificado raíz solo está en el almacén de Windows. Se resuelve exportando las
raíces de Windows a un archivo PEM y pasándoselo a PHP:

```bash
php -d curl.cainfo=RUTA/win-roots.pem composer.phar install
```
