FROM node:22-alpine AS css
WORKDIR /css
COPY package.json package-lock.json* ./
COPY src/input.css ./src/input.css
COPY public/assets/app.js ./public/assets/app.js
COPY views ./views
RUN npm install \
    && npx @tailwindcss/cli -i src/input.css -o /out/app.css --minify

FROM php:8.4-fpm-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip git libzip-dev libjpeg62-turbo-dev libpng-dev libwebp-dev zlib1g-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install pdo_mysql zip gd \
    && rm -rf /var/lib/apt/lists/* \
    && cp /usr/local/etc/php/php.ini-production /usr/local/etc/php/php.ini

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/zz-root.conf /usr/local/etc/php-fpm.d/zz-root.conf

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app

COPY composer.json composer.lock /app/
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts

COPY . /app
# Classes added since the composer step (PSR-4, so the classmap is only a speed-up).
RUN composer dump-autoload --no-dev --optimize --no-scripts
COPY --from=css /out/app.css /app/public/assets/app.css

ENV CATALOG_PATH=/app/catalog.json \
    THUMBS_DIR=/app/thumbs \
    MEDIA_DIR=/media/nas \
    IMAGE_CACHE_DIR=/media/img-cache \
    NOTATION_PATH=/app/notation.pdf

CMD ["php-fpm", "-F", "-R"]
