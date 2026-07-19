FROM php:8.3-fpm

RUN apt-get update && apt-get install -y --no-install-recommends \
        nginx \
        supervisor \
        libvips-dev \
        libvips-tools \
        libheif-plugin-aomenc \
        libpq-dev \
        libzip-dev \
        git \
        unzip \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-configure pgsql \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql zip \
    && pecl install redis vips \
    && docker-php-ext-enable redis vips

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY docker/nginx.conf /etc/nginx/sites-available/default
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/gallery.ini

EXPOSE 80

CMD ["/usr/bin/supervisord", "-n", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
