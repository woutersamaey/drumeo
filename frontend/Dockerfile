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
    && echo "memory_limit=256M" > /usr/local/etc/php/conf.d/memory.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app

COPY composer.json composer.lock /app/
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts

COPY . /app
COPY --from=css /out/app.css /app/public/assets/app.css
RUN sed -i 's/^user = www-data/user = root/; s/^group = www-data/group = root/' /usr/local/etc/php-fpm.d/www.conf \
    && sed -i 's/^pm.max_children = .*/pm.max_children = 20/; s/^pm.start_servers = .*/pm.start_servers = 4/; s/^pm.min_spare_servers = .*/pm.min_spare_servers = 2/; s/^pm.max_spare_servers = .*/pm.max_spare_servers = 8/' /usr/local/etc/php-fpm.d/www.conf \
    && printf '\n[www]\nclear_env = no\n' >> /usr/local/etc/php-fpm.d/zz-docker.conf

ENV CATALOG_PATH=/app/catalog.json \
    MEDIA_DIR=/media/nas \
    IMAGE_CACHE_DIR=/media/img-cache \
    NOTATION_PATH=/app/notation.pdf

CMD ["php-fpm", "-F", "-R"]
