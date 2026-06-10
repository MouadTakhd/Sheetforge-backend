FROM php:8.2-apache

WORKDIR /srv/app

# System dependencies
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libicu-dev \
    libpq-dev \
    libzip-dev \
    && rm -rf /var/lib/apt/lists/*

# PHP extensions
RUN docker-php-ext-install \
    intl \
    pdo_pgsql \
    zip \
    opcache

# Apache
RUN a2enmod rewrite

ENV APACHE_DOCUMENT_ROOT=/srv/app/public

RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
    /etc/apache2/sites-available/*.conf && \
    sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' \
    /etc/apache2/apache2.conf \
    /etc/apache2/conf-available/*.conf

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Install dependencies first for Docker cache
COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --optimize-autoloader \
    --no-interaction

# Copy application
COPY . .

# PHP limits
RUN { \
    echo 'upload_max_filesize = 100M'; \
    echo 'post_max_size = 108M'; \
    echo 'memory_limit = 512M'; \
    echo 'max_execution_time = 300'; \
} > /usr/local/etc/php/conf.d/sheetforge-limits.ini

# Permissions
RUN chown -R www-data:www-data /srv/app

# Symfony cache warmup (optional)
RUN mkdir -p var/cache var/log && \
    chown -R www-data:www-data var

EXPOSE 80

CMD ["apache2-foreground"]
