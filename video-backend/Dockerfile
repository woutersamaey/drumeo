FROM php:8.4-fpm-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ffmpeg \
        libfcgi-bin \
        gosu \
        unzip \
        git \
    && rm -rf /var/lib/apt/lists/* \
    && ffmpeg -version >/dev/null

WORKDIR /app

COPY composer.json composer.lock /app/
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --optimize-autoloader

COPY . /app
COPY docker/php.ini /usr/local/etc/php/conf.d/drumeo.ini
RUN composer dump-autoload --optimize --no-dev \
    && mkdir -p /media/bron /media/cache /media/meta \
    && chmod +x /app/docker/entrypoint.sh /app/bin/*.php \
    && sed -i 's/^user = www-data/user = root/; s/^group = www-data/group = root/' /usr/local/etc/php-fpm.d/www.conf \
    && sed -i 's/^pm.max_children = .*/pm.max_children = 16/; s/^pm.start_servers = .*/pm.start_servers = 4/; s/^pm.min_spare_servers = .*/pm.min_spare_servers = 2/; s/^pm.max_spare_servers = .*/pm.max_spare_servers = 8/' /usr/local/etc/php-fpm.d/www.conf \
    && printf '\n[www]\nclear_env = no\n' >> /usr/local/etc/php-fpm.d/zz-docker.conf \
    && chown -R www-data:www-data /media/cache /media/meta

ENV SOURCE_DIR=/media/bron \
    CACHE_DIR=/media/cache \
    METADATA_DIR=/media/meta \
    API_HOST=0.0.0.0 \
    API_PORT=8080 \
    FFMPEG_VCODEC=libx264

ENTRYPOINT ["/app/docker/entrypoint.sh"]
CMD ["php-fpm"]
