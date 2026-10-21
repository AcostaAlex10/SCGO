# Backend PHP (SGSO) sobre Apache. Imagen pensada para Render (modo Docker)
# u cualquier hosting que corra contenedores. Apache es multi-hilo, asi que
# no tiene el bloqueo del servidor de desarrollo "php -S".

FROM php:8.3-apache

# PHP 8.3.36 corrige dos fallas críticas de ejecución remota de código
# (GHSA-w8hw-w9fx-j79w y GHSA-6wmf-4rv5-p3rp, aviso de php.net del 2026-10-21),
# explotables antes de que corra la aplicación y peores con mod_php. El tag
# 8.3-apache sigue al último parche, pero un build con caché puede reusar una
# imagen base vieja: si pasa, el build falla en vez de desplegarla. El mínimo
# va por rama, así que sigue protegiendo si se cambia el tag a 8.4 o 8.5
# (B-07); una rama que no está en la lista también corta el build.
RUN php -r '$min = ["8.2" => "8.2.35", "8.3" => "8.3.36", "8.4" => "8.4.27", "8.5" => "8.5.12"][PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION] ?? null; if ($min === null || version_compare(PHP_VERSION, $min, "<")) { fwrite(STDERR, "PHP " . PHP_VERSION . " no tiene el parche del 2026-10-21 (mínimo " . ($min ?? "desconocido") . "). Construir sin caché.\n"); exit(1); }'

# Configuración de producción de PHP: registra los errores en vez de mostrarlos
# (plan de producto, A-04). Sin esto la imagen no carga ningún php.ini y PHP
# muestra los errores al cliente.
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# php.ini-production deja expose_php en On, así que cada respuesta anunciaba la
# versión exacta de PHP. Lo mismo hace Apache con la suya. Son datos que le
# ahorran trabajo a quien busca vulnerabilidades conocidas de esa versión.
#
# session.upload_progress es la mitigación que recomienda el mismo aviso para
# la segunda falla. La API usa JWT y no abre sesiones, así que no lo necesita.
RUN printf 'expose_php = Off\nsession.upload_progress.enabled = Off\n' > "$PHP_INI_DIR/conf.d/zz-scgo.ini" \
 && sed -ri 's/^ServerTokens .*/ServerTokens Prod/; s/^ServerSignature .*/ServerSignature Off/' \
      /etc/apache2/conf-available/security.conf

# Extension para conectarse a MariaDB / MySQL (PDO).
RUN docker-php-ext-install pdo_mysql

# Dejar la raiz del sitio en public/ y enrutar TODO lo que no sea un archivo
# real a index.php mediante FallbackResource (no depende de .htaccess ni de
# que AllowOverride este activo: es robusto en cualquier Apache).
RUN a2enmod rewrite \
 && sed -ri 's!DocumentRoot /var/www/html!DocumentRoot /var/www/html/public!' /etc/apache2/sites-available/000-default.conf \
 && printf '<Directory /var/www/html/public>\n    AllowOverride All\n    Require all granted\n    FallbackResource /index.php\n</Directory>\n' > /etc/apache2/conf-available/sgso.conf \
 && a2enconf sgso

# Copiar el codigo del backend (el contexto de build es la carpeta back/).
COPY . /var/www/html/

# Autoload PSR-4 (ADR-001 §5.1). El backend no tiene dependencias de produccion,
# asi que esto NO descarga nada: solo genera vendor/autoload.php, que es lo que
# index.php requiere. Sin este paso la API arranca con "class not found".
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Render (y otros) inyectan el puerto en la variable $PORT. Apache debe
# escuchar en ese puerto. Lo ajustamos al arrancar.
RUN printf '#!/bin/sh\nset -e\nP="${PORT:-80}"\nsed -ri "s/^Listen .*/Listen ${P}/" /etc/apache2/ports.conf\nsed -ri "s/<VirtualHost \\*:[0-9]+>/<VirtualHost *:${P}>/" /etc/apache2/sites-available/000-default.conf\nexec apache2-foreground\n' > /usr/local/bin/start.sh \
 && chmod +x /usr/local/bin/start.sh

CMD ["/usr/local/bin/start.sh"]
