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
| Demo para testers | https://acostaalex10.github.io/SCGO/ | Frontend sin backend, con datos simulados | `testing` (congelada) |

Todo lo que llega a `main` se despliega. Por eso `main` solo cambia por pull
request con el CI en verde (ver [CONTRIBUTING.md](../CONTRIBUTING.md)).

La demo se publica con `.github/workflows/pages-testing.yml` en cada push a
`testing`, y compila con `BASE_PATH=./`, `VITE_HASH_ROUTER=1` y `VITE_MOCK=1`.
**`testing` no se toca**: es lo que usan los testers.

**Comprobación rápida:**

```bash
curl -s https://ingenieria-en-software-proyecto.onrender.com/api/health
curl -s -o /dev/null -w "%{http_code}\n" https://acostaalex10.github.io/SCGO/
```

`/api/health` ejecuta `SELECT 1` contra la base: si contesta
`{"status":"ok","db":"ok"}`, la API y la base andan. Si la base no responde,
devuelve un 500 con `"referencia"`, y el detalle queda en el log de Render.

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

**`JWT_SECRET` es obligatorio y tiene que tener al menos 32 caracteres.** Sin un
valor válido la API no atiende ningún pedido: contesta 500 y el log dice por
qué. También rechaza los dos valores de ejemplo que figuran en el repositorio.
Para generar uno:

```bash
php -r "echo bin2hex(random_bytes(32));"
```

Cambiarlo cierra todas las sesiones abiertas: cada usuario vuelve a entrar una vez.

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

## 3. Cuando una cuenta queda bloqueada

Después de cinco contraseñas equivocadas seguidas, la cuenta exige esperar
antes de cada intento nuevo: primero 1 minuto, y la espera se duplica con cada
fallo hasta un máximo de 15. El usuario ve cuánto le falta. Pasados 15 minutos
sin fallos el contador se reinicia solo, y un login correcto lo borra.

Si hace falta desbloquear una cuenta antes, se borra su fila de
`intento_login`. La clave no es el email sino su hash, así que se busca así:

```sql
DELETE FROM intento_login
WHERE clave = CONCAT('cuenta:', SHA2(LOWER(TRIM('usuario@empresa.com')), 256));
```

Cualquiera que conozca un email puede bloquear esa cuenta a propósito, como con
cualquier límite por cuenta. Por eso la espera tiene tope: en el peor caso, el
dueño espera 15 minutos.

**Pedidos de recuperación de contraseña.** `/auth/olvide` manda como mucho un
correo cada 5 minutos por cuenta. No usa `intento_login`: la condición está en el
mismo UPDATE que guarda el token, así que no hay contador que limpiar ni forma de
dejar a alguien sin poder entrar pidiendo recuperaciones de su cuenta.

La pantalla dice lo mismo de siempre aunque el correo no salga, así que el
síntoma es "pedí el mail y no llega". Antes de buscar un problema en Brevo,
conviene descartar que sea esto:

```sql
SELECT email, reset_expira FROM usuario WHERE email = 'usuario@empresa.com';
```

Si `reset_expira` está dentro de los próximos 55 a 60 minutos, el pedido es
reciente y el correo ya salió: hay que buscarlo en la casilla, no volver a
pedirlo. Para permitir un pedido nuevo en el acto:

```sql
UPDATE usuario SET reset_expira = NULL WHERE email = 'usuario@empresa.com';
```

---

## 4. Cuando la API devuelve un error 500

El usuario no ve ningún detalle interno, solo esto:

```json
{"error": "Error interno del servidor", "referencia": "21b4bf58abf2"}
```

El detalle completo (tipo de error, mensaje, archivo y línea) queda en el log con
**la misma referencia**. En Render: servicio → *Logs* → buscar el código.

Para ver los errores en pantalla mientras se desarrolla en local, poner
`APP_DEBUG=1` en `back/.env`. En producción queda en `0`.

---

## 5. Cómo verificar que todo anda

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

## 6. Problemas conocidos

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

### Despliegue

**La CSP del frontend tiene escrita la URL de la API.** En `FRONT/vercel.json`,
la directiva `connect-src` nombra a
`https://ingenieria-en-software-proyecto.onrender.com`. Es a propósito: el sentido
de la CSP es decir exactamente a dónde puede hablar la aplicación. Pero si alguna
vez cambia la URL del backend, **no alcanza con cambiar `VITE_API_URL` en
Vercel**: hay que cambiar también esa línea, o el navegador bloquea todos los
pedidos y la aplicación queda muda sin ningún error del servidor. El síntoma es
un error de CSP en la consola del navegador, no un 500.

**Los PR de Dependabot se despliegan como cualquier otro.** Los lunes abre PRs
con actualizaciones de dependencias (y una vez por mes, de las actions). Que los
abra un bot no los vuelve seguros: al mergearlos van a producción. Los de
versiones menores y parches vienen agrupados y alcanza con que el CI esté en
verde. Los de una versión mayor vienen sueltos, y antes de mergearlos hay que
leer qué rompe, como se hizo con las actions en el PR #13.

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
