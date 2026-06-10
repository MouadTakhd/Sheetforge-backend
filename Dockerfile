# Target: app_php (Matches your docker-compose.yml)
FROM php:8.2-apache AS app_php

COPY composer.json composer.lock ./

RUN composer install --no-dev --optimize-autoloader

COPY . .

RUN { \
    echo 'upload_max_filesize = 100M'; \
    echo 'post_max_size = 108M'; \
    echo 'memory_limit = 512M'; \
    echo 'max_execution_time = 300'; \
} > /usr/local/etc/php/conf.d/sheetforge-limits.ini

# 1. Set the working directory
WORKDIR /srv/app

# 2. Install required system dependencies and PostgreSQL tools
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libicu-dev \
    libpq-dev \
    libzip-dev \
    && rm -rf /var/lib/apt/lists/*

# 3. Install required PHP extensions for Symfony and Postgres
RUN docker-php-ext-install \
    intl \
    pdo_pgsql \
    zip \
    opcache

# 4. Enable Apache mod_rewrite (required for Symfony routing)
RUN a2enmod rewrite

# 5. Point Apache to Symfony's public directory instead of the default html folder
ENV APACHE_DOCUMENT_ROOT /srv/app/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# 6. Install Composer globally
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
RUN echo "upload_max_filesize = 100M" >> /usr/local/etc/php/conf.d/docker-php-ext-uploads.ini \
    && echo "post_max_size = 108M" >> /usr/local/etc/php/conf.d/docker-php-ext-uploads.ini \
    && echo "memory_limit = 512M" >> /usr/local/etc/php/conf.d/docker-php-ext-uploads.ini \
    && echo "max_execution_time = 300" >> /usr/local/etc/php/conf.d/docker-php-ext-uploads.ini

# If your skeleton is running FrankenPHP, append the server-level request body allowance too:
ENV FRANKENPHP_CONFIG="client_max_body_size 100M"
# 7. Copy application code and set permissions
COPY . /srv/app
RUN chown -R www-data:www-data /srv/app
