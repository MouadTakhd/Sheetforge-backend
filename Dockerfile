FROM php:8.2-apache
WORKDIR /srv/app

# 1. Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libicu-dev \
    libpq-dev \
    libzip-dev \
    curl \
    openssl \
    && rm -rf /var/lib/apt/lists/*

# 2. Install PHP extensions
RUN docker-php-ext-install intl pdo_pgsql zip opcache

# 3. Configure Apache document root for Symfony
RUN a2enmod rewrite
ENV APACHE_DOCUMENT_ROOT=/srv/app/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
    /etc/apache2/sites-available/*.conf && \
    sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' \
    /etc/apache2/apache2.conf \
    /etc/apache2/conf-available/*.conf

# 4. Bring in Composer v2
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# 5. Copy the project source files
COPY . .

# 6. Install dependencies
ENV APP_ENV=prod
RUN composer install \
    --no-dev \
    --optimize-autoloader \
    --no-interaction \
    --no-scripts

# 7. Configure PHP limits
RUN echo "upload_max_filesize=100M" > /usr/local/etc/php/conf.d/uploads.ini && \
    echo "post_max_size=108M" >> /usr/local/etc/php/conf.d/uploads.ini && \
    echo "memory_limit=512M" >> /usr/local/etc/php/conf.d/uploads.ini && \
    echo "max_execution_time=300" >> /usr/local/etc/php/conf.d/uploads.ini

# 8. Generate JWT keys
RUN mkdir -p config/jwt && \
    openssl genrsa -out config/jwt/private.pem -aes256 \
        -passout pass:ec4f03ebadee29c5cdad3be69fa8bbf7b5468172f52e025ba8127f34f90e747a 4096 && \
    openssl rsa -pubout \
        -in config/jwt/private.pem \
        -out config/jwt/public.pem \
        -passin pass:ec4f03ebadee29c5cdad3be69fa8bbf7b5468172f52e025ba8127f34f90e747a && \
    chown -R www-data:www-data config/jwt

# 9. Create empty .env and set permissions
RUN touch .env
RUN chown -R www-data:www-data /srv/app

EXPOSE 80

# 10. Clear cache at runtime and start Apache
CMD ["sh", "-c", "php bin/console cache:clear --env=prod 2>&1 || true && apache2-foreground"]
