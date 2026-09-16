# Backend PHP (SGSO) sobre Apache. Imagen pensada para Render (modo Docker)
# u cualquier hosting que corra contenedores. Apache es multi-hilo, asi que
# no tiene el bloqueo del servidor de desarrollo "php -S".

FROM php:8.3-apache

# Configuración de producción de PHP: registra los errores en vez de mostrarlos
# (plan de producto, A-04). Sin esto la imagen no carga ningún php.ini y PHP
# muestra los errores al cliente.
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# php.ini-production deja expose_php en On, así que cada respuesta anunciaba la
# versión exacta de PHP. Lo mismo hace Apache con la suya. Son datos que le
# ahorran trabajo a quien busca vulnerabilidades conocidas de esa versión.
RUN printf 'expose_php = Off\n' > "$PHP_INI_DIR/conf.d/zz-scgo.ini" \
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
