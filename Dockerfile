FROM php:8.2-apache

WORKDIR /srv/app

# System deps
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libicu-dev \
    libpq-dev \
    libzip-dev \
    curl \
    && rm -rf /var/lib/apt/lists/*

# PHP extensions
RUN docker-php-ext-install intl pdo_pgsql zip opcache

# Apache config
RUN a2enmod rewrite

ENV APACHE_DOCUMENT_ROOT=/srv/app/public

RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
    /etc/apache2/sites-available/*.conf && \
    sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' \
    /etc/apache2/apache2.conf \
    /etc/apache2/conf-available/*.conf

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Copy project
COPY . .

# Create a minimal .env so Symfony doesn't crash during build
RUN echo "APP_ENV=prod\nAPP_SECRET=placeholder" > .env

# Install dependencies
RUN composer install \
    --no-dev \
    --optimize-autoloader \
    --no-interaction \
    --no-scripts

# PHP limits
RUN echo "upload_max_filesize=100M" > /usr/local/etc/php/conf.d/uploads.ini && \
    echo "post_max_size=108M" >> /usr/local/etc/php/conf.d/uploads.ini && \
    echo "memory_limit=512M" >> /usr/local/etc/php/conf.d/uploads.ini && \
    echo "max_execution_time=300" >> /usr/local/etc/php/conf.d/uploads.ini

# Permissions
RUN chown -R www-data:www-data /srv/app

EXPOSE 80

# Warm cache at runtime when real env vars are available, then start Apache
CMD ["sh", "-c", "php bin/console cache:warmup --env=prod && apache2-foreground"]
