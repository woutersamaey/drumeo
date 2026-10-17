FROM php:8.4-fpm-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ffmpeg \
        libfcgi-bin \
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
COPY docker/zz-root.conf /usr/local/etc/php-fpm.d/zz-root.conf
RUN composer dump-autoload --optimize --no-dev \
    && mkdir -p /media/bron /media/cache /media/meta \
    && chmod +x /app/docker/entrypoint.sh /app/bin/*.php \
    && php-fpm -t -R

# Runs as root (compose: user "0:0"), so no chown of the data directories.
ENV SOURCE_DIR=/media/bron \
    CACHE_DIR=/media/cache \
    METADATA_DIR=/media/meta \
    FFMPEG_VCODEC=libx264

ENTRYPOINT ["/app/docker/entrypoint.sh"]
CMD ["php-fpm"]
