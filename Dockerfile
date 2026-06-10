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

# Copy project FIRST (important for Symfony scripts like bin/console)
COPY . .

# Install dependencies AFTER full code is present
RUN composer install \
    --no-dev \
    --optimize-autoloader \
    --no-interaction

# PHP limits
RUN echo "upload_max_filesize=100M" > /usr/local/etc/php/conf.d/uploads.ini && \
    echo "post_max_size=108M" >> /usr/local/etc/php/conf.d/uploads.ini && \
    echo "memory_limit=512M" >> /usr/local/etc/php/conf.d/uploads.ini && \
    echo "max_execution_time=300" >> /usr/local/etc/php/conf.d/uploads.ini

# Permissions
RUN chown -R www-data:www-data /srv/app

EXPOSE 80

CMD ["apache2-foreground"]
