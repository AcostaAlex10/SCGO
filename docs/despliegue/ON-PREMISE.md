# Despliegue On-Premise (en el servidor de la empresa)

Guía para correr SCGO en un servidor propio de la empresa (un equipo en su
red local / rack), con **PHP + MariaDB + Apache**. Es el destino final del
sistema: queda siempre disponible en la red de la empresa y se reinicia solo
tras un corte de luz.

## Componentes

| Pieza | Tecnología | Dónde corre |
|-------|-----------|-------------|
| Base de datos | **MariaDB** | El servidor (puerto 3306) |
| Backend (API REST) | **PHP** (carpeta `back/`) | Apache, en el servidor |
| Frontend (SPA) | **React** ya compilado (`FRONT/dist`) | Apache, en el servidor |

La forma más simple en Windows es **XAMPP**, que trae Apache + PHP + MariaDB
en un solo instalador. (En Linux sería Apache/Nginx + PHP-FPM + MariaDB.)

---

## 0) Requisitos en el servidor

- Instalar **XAMPP** (incluye Apache, PHP 8 y MariaDB): https://www.apachefriends.org
- Instalar **Node.js** solo para *compilar* el frontend una vez (no hace falta
  que quede corriendo): https://nodejs.org

> Anotá la **IP local del servidor** (ej. `ipconfig` → algo como `192.168.1.50`).
> Las demás máquinas de la empresa van a entrar por esa IP.

---

## 1) Base de datos (MariaDB)

1. Abrir el **panel de XAMPP** → arrancar **MySQL** (es MariaDB).
2. Entrar a phpMyAdmin (http://localhost/phpmyadmin) o DBeaver y crear la base:
   ```sql
   CREATE DATABASE sgso CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```
3. Ejecutar el esquema y el seed (desde la carpeta `back`):
   ```bash
   # crea las tablas usuario y proyecto
   # (importar back/sql/schema.sql en phpMyAdmin, o por consola)
   php sql/seed.php
   ```
   El seed crea el admin de prueba (`admin@sgso.com` / `admin123`, hasheado) y
   carga proyectos de ejemplo.

> Definí una contraseña para el usuario `root` de MariaDB (en XAMPP viene vacía).
> Esa contraseña va en el `.env` del backend (siguiente paso).

---

## 2) Backend PHP (`back/`)

1. Copiar la carpeta **`back/`** al servidor (por ejemplo a `C:\sgso\back`).
2. Crear el archivo **`back/.env`** a partir de `back/.env.example`:
   ```env
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_NAME=sgso
   DB_USER=root
   DB_PASSWORD=LA_CLAVE_DE_TU_MARIADB
   DB_SSL=false

   JWT_SECRET=una_clave_larga_y_secreta_distinta_por_servidor
   JWT_SEGUNDOS=28800

   # IP del server donde se sirve el frontend (ver paso 3)
   CORS_ORIGIN=http://192.168.1.50
   ```
3. Publicar el backend en Apache. La raíz del backend debe ser **`back/public`**.
   Editar `C:\xampp\apache\conf\extra\httpd-vhosts.conf` y agregar:
   ```apache
   # API de SCGO en el puerto 8080
   Listen 8080
   <VirtualHost *:8080>
       DocumentRoot "C:/sgso/back/public"
       <Directory "C:/sgso/back/public">
           AllowOverride All
           Require all granted
       </Directory>
   </VirtualHost>
   ```
   (El `back/public/.htaccess` ya reenvía todo a `index.php`.)
4. Reiniciar Apache. Probar en el navegador del servidor:
   `http://localhost:8080/api/health` → debe responder `{"status":"ok"}`.

---

## 3) Frontend (`FRONT/`)

El frontend se **compila una vez** y se sirve como archivos estáticos.

1. En la carpeta `FRONT`, crear `FRONT/.env` con la URL del backend (¡con la IP
   del servidor, no `localhost`, para que el resto de la red lo alcance!):
   ```env
   VITE_API_URL=http://192.168.1.50:8080/api
   ```
2. Compilar:
   ```bash
   cd FRONT
   npm install --legacy-peer-deps
   npm run build
   ```
   Esto genera la carpeta **`FRONT/dist`**.
3. Copiar el contenido de `FRONT/dist` a la carpeta pública de Apache
   (`C:\xampp\htdocs\sgso`, por ejemplo).
4. Para que el ruteo de la SPA funcione (que `/login`, `/proyectos`, etc.
   carguen aunque se refresque la página), crear `C:\xampp\htdocs\sgso\.htaccess`:
   ```apache
   RewriteEngine On
   RewriteCond %{REQUEST_FILENAME} !-f
   RewriteCond %{REQUEST_FILENAME} !-d
   RewriteRule ^ index.html [L]
   ```
5. El frontend queda en `http://192.168.1.50/sgso`.

---

## 4) Que arranque solo tras un corte de luz

Esta es la clave de tener un servidor propio: configurar Apache y MariaDB como
**servicios de Windows** para que inicien automáticamente al prender la máquina.

1. En el **panel de XAMPP**, hacer clic en las cruces (X) a la izquierda de
   **Apache** y **MySQL** → "Install" → quedan como servicios de Windows.
2. Abrir `services.msc` y verificar que ambos estén en **Tipo de inicio:
   Automático**.

Así, si se corta la luz y el servidor se reinicia, MariaDB y Apache (con el
backend y el frontend) vuelven a levantar **sin que nadie tenga que tocar nada**.

---

## 5) Acceso desde la red de la empresa

- Abrir en el **Firewall de Windows** los puertos **80** (frontend) y **8080**
  (backend) para conexiones entrantes en la red local.
- Desde cualquier PC de la empresa: entrar a `http://192.168.1.50/sgso`.
- (Opcional, acceso desde fuera de la empresa) Configurar en el router un
  *port forwarding* o una VPN. Eso ya depende de la infraestructura de red de
  la empresa.

---

## Checklist final

- [ ] MariaDB corriendo, base `sgso` con tablas y admin (seed).
- [ ] `http://localhost:8080/api/health` responde OK en el servidor.
- [ ] Frontend compilado y copiado a htdocs, con su `.htaccess`.
- [ ] `VITE_API_URL` y `CORS_ORIGIN` usan la **IP del servidor**.
- [ ] Apache y MariaDB instalados como **servicios automáticos**.
- [ ] Puertos 80 y 8080 abiertos en el firewall.
- [ ] Login OK desde otra PC de la red con `admin@sgso.com` / `admin123`.

> **Seguridad mínima para producción:** cambiar la contraseña del admin y del
> usuario root de MariaDB, usar un `JWT_SECRET` largo y único, y restringir
> `CORS_ORIGIN` al dominio/IP real del frontend.
