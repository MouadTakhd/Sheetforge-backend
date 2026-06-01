# Target: app_php (Matches your docker-compose.yml)
FROM php:8.2-apache AS app_php

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

# 7. Copy application code and set permissions
COPY . /srv/app
RUN chown -R www-data:www-data /srv/app