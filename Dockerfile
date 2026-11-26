# GorilaGym · imagen para Dokploy (o cualquier Docker)
FROM php:8.3-apache

# Extensiones: pdo_sqlite, openssl, mbstring, fileinfo, json y curl ya vienen en la imagen oficial.
RUN apt-get update \
 && apt-get install -y --no-install-recommends libzip-dev libpng-dev libjpeg62-turbo-dev libwebp-dev libfreetype6-dev msmtp ca-certificates \
 && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
 && docker-php-ext-install -j"$(nproc)" zip gd \
 && apt-get purge -y --auto-remove libzip-dev libpng-dev libjpeg62-turbo-dev libwebp-dev libfreetype6-dev \
 && apt-get install -y --no-install-recommends libzip4 libpng16-16 libjpeg62-turbo libwebp7 libfreetype6 \
 && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite headers expires deflate mime remoteip \
 && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY docker/php.ini $PHP_INI_DIR/conf.d/zz-gorilagym.ini
COPY docker/apache.conf /etc/apache2/conf-enabled/zz-gorilagym.conf
COPY docker/entrypoint.sh /usr/local/bin/gorilagym-entrypoint
RUN chmod +x /usr/local/bin/gorilagym-entrypoint

ENV GG_CONFIG_FILE=/var/www/html/private/storage/config.php
WORKDIR /var/www/html
COPY --chown=www-data:www-data . /var/www/html
# Copia de respaldo de los .htaccess / .gitkeep para restaurarlos si el volumen llega vacío
RUN mkdir -p /opt/gorilagym && cp -a media /opt/gorilagym/media && cp -a private/storage /opt/gorilagym/storage \
 && rm -rf docker Dockerfile docker-compose.yml .dockerignore DOKPLOY.md \
 && chmod 755 /var/www/html

VOLUME ["/var/www/html/private/storage", "/var/www/html/media"]
EXPOSE 80
ENTRYPOINT ["gorilagym-entrypoint"]
CMD ["apache2-foreground"]
