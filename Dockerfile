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

# 6. Install dependencies WITHOUT triggering scripts during build time
ENV APP_ENV=prod
RUN composer install \
    --no-dev \
    --optimize-autoloader \
    --no-interaction \
    --no-scripts

# 7. Configure production PHP engine limits
RUN echo "upload_max_filesize=100M" > /usr/local/etc/php/conf.d/uploads.ini && \
    echo "post_max_size=108M" >> /usr/local/etc/php/conf.d/uploads.ini && \
    echo "memory_limit=512M" >> /usr/local/etc/php/conf.d/uploads.ini && \
    echo "max_execution_time=300" >> /usr/local/etc/php/conf.d/uploads.ini

# FIX: Create an empty .env file so Symfony's Dotenv file validation passes
RUN touch .env

# 8. Enforce proper ownership for Apache web server access
RUN chown -R www-data:www-data /srv/app

EXPOSE 80

# 9. Compile the Symfony cache at RUNTIME using Render's live dashboard variables
CMD ["sh", "-c", "php bin/console cache:clear --env=prod && apache2-foreground"]
