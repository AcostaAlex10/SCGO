# Despliegue en la nube (plan gratuito)

Guía para publicar SGSO y que sea accesible desde cualquier lado, sin depender
de una PC encendida. Todo con capas gratuitas.

## Arquitectura

La app son **3 piezas** y cada una va a un servicio distinto:

| Pieza | Servicio sugerido (gratis) | Notas |
|---|---|---|
| **Frontend** (React/Vite) | **Vercel** | Siempre activo. `vercel.json` ya incluido para el ruteo del SPA. |
| **Backend** (PHP + Apache) | **Render** (Web Service free, tipo **Docker**) | Usa `back/Dockerfile`. El plan free "duerme" tras ~15 min sin uso y tarda unos segundos en despertar. |
| **Base de datos** (MySQL/MariaDB) | **Aiven** o **Clever Cloud** (MySQL gratis) | El SQL es compatible MySQL/MariaDB. |

> Todo se despliega desde la rama del repo en GitHub. Conviene mergear primero a `main`.

---

## Paso 1 — Base de datos en la nube

1. Crear una cuenta en un proveedor de **MySQL gratis** (ej. [Aiven](https://aiven.io) o [Clever Cloud](https://www.clever-cloud.com)).
2. Crear una base MySQL. Anotar los datos de conexión: **host, puerto, usuario, contraseña, nombre de la base**.
3. Cargar el esquema y el usuario admin **desde tu PC**, apuntando a la base remota:
   - En `back/.env`, poner los datos de la base remota y `DB_SSL=true`.
   - Ejecutar:
     ```bash
     php back/sql/migrar.php   # crea las tablas (idempotente)
     php back/sql/seed.php     # crea el usuario admin con contraseña hasheada
     ```
   - Volver a dejar tu `.env` local como estaba si seguís desarrollando contra tu base local.

---

## Paso 2 — Backend en Render

1. En [Render](https://render.com) → **New → Web Service** → conectar el repo de GitHub.
2. Configurar:
   - **Language / Runtime**: `Docker`
   - **Root Directory**: `back`
   - **Dockerfile Path**: `back/Dockerfile`
3. En **Environment** cargar las variables (las mismas de `.env`, pero con los datos de la base remota):

   | Variable | Valor |
   |---|---|
   | `DB_HOST` / `DB_PORT` / `DB_USER` / `DB_PASSWORD` / `DB_NAME` | los de la base en la nube |
   | `DB_SSL` | `true` |
   | `JWT_SECRET` | una clave larga y secreta |
   | `JWT_SEGUNDOS` | `28800` (8 horas) |
   | `BREVO_API_KEY` / `BREVO_SENDER` | credenciales para el correo de recuperación de contraseña |
   | `CORS_ORIGIN` | *(se completa en el Paso 4)* |

4. Deploy. Render da una URL tipo `https://sgso-backend.onrender.com`. Probar `https://.../api/health` → debe responder `{"status":"ok"}`.

---

## Paso 3 — Frontend en Vercel

1. En [Vercel](https://vercel.com) → **Add New → Project** → importar el repo.
2. Configurar:
   - **Root Directory**: `FRONT`
   - **Framework Preset**: Vite (lo detecta solo)
   - **Install Command**: `npm install --legacy-peer-deps`
3. En **Environment Variables** agregar:

   | Variable | Valor |
   |---|---|
   | `VITE_API_URL` | la URL del backend de Render + `/api` (ej. `https://sgso-backend.onrender.com/api`) |

4. Deploy. Vercel da una URL tipo `https://sgso.vercel.app`.

---

## Paso 4 — Conectar frontend y backend (CORS)

1. Volver a Render → variable `CORS_ORIGIN` = la URL de Vercel (ej. `https://sgso.vercel.app`).
2. Re-deploy del backend para que tome el cambio.

---

## Checklist final

- [ ] `https://.../api/health` responde OK.
- [ ] Abrir la URL de Vercel → redirige a `/login`.
- [ ] Login con el usuario creado por `seed.php` → entra al dashboard.
- [ ] El frontend consume la API de Render sin errores de CORS.

> **CORS:** `back/src/Cors.php` devuelve el `Origin` de la petición y solo usa
> `CORS_ORIGIN` cuando la petición no trae `Origin`. Es decir, hoy la API responde
> a cualquier origen. Para restringirla de verdad hay que validar el `Origin`
> recibido contra `CORS_ORIGIN` antes de reflejarlo.

> **Nota para la cátedra:** el backend es PHP sobre MariaDB, como exige la
> asignatura. La base de Aiven puede ser MySQL o MariaDB: el esquema usa solo
> sintaxis compatible con ambas y el acceso a datos es por PDO.
