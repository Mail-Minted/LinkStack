FROM php:8.4-apache

# gd needs the image libs; zip needs libzip. bcmath/gd/zip are the
# composer.json ext requirements; exif backs image-orientation handling.
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpng-dev libjpeg62-turbo-dev libwebp-dev libfreetype6-dev \
        libzip-dev unzip \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
    && docker-php-ext-install -j"$(nproc)" gd bcmath zip exif \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

# LinkStack serves from the app root (shared-hosting layout) and relies on
# .htaccess for routing AND for denying access to dotfiles / *.sqlite —
# Apache with AllowOverride All is therefore load-bearing, not cosmetic.
RUN printf '<Directory /var/www/html>\n    AllowOverride All\n    Require all granted\n</Directory>\n' \
        > /etc/apache2/conf-available/zz-linkstack.conf \
    && a2enconf zz-linkstack

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY . .

RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress

COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

ENTRYPOINT ["/entrypoint.sh"]
