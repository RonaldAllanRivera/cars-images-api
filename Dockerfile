FROM php:8.2-apache

ARG WWWUSER=1000
ARG WWWGROUP=1000

# System deps + PHP extensions required by Laravel + Filament
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        curl \
        libpng-dev \
        libjpeg-dev \
        libfreetype6-dev \
        libonig-dev \
        libxml2-dev \
        libzip-dev \
        libicu-dev \
        libssl-dev \
        unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        mbstring \
        exif \
        pcntl \
        bcmath \
        gd \
        intl \
        zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Enable mod_rewrite so Laravel's public/.htaccess works.
RUN a2enmod rewrite

# Apache vhost: DocumentRoot -> /var/www/html/public, AllowOverride All
COPY docker/apache/000-default.conf /etc/apache2/sites-available/000-default.conf

# Composer (from official image)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Align www-data UID/GID with host user so bind-mounted files stay host-owned.
RUN usermod -u ${WWWUSER} www-data \
    && groupmod -g ${WWWGROUP} www-data

WORKDIR /var/www/html

# Copy app source for the build-time composer install. The bind-mount in
# docker-compose.yml will overlay /var/www/html at runtime; the anonymous
# volume on /var/www/html/vendor preserves the install below.
COPY . /var/www/html

RUN composer install \
        --prefer-dist \
        --no-interaction \
        --optimize-autoloader \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 80
