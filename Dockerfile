FROM php:8.2-apache
WORKDIR /srv/app

# 1. Install system dependencies (includes tesseract for OCR features)
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libicu-dev \
    libpq-dev \
    libzip-dev \
    curl \
    openssl \
    tesseract-ocr \
    && rm -rf /var/lib/apt/lists/*

# 2. Install PHP extensions
RUN docker-php-ext-install intl pdo_pgsql zip opcache

# 3. Configure Apache: rewrite module + headers module for reverse proxy
RUN a2enmod rewrite headers remoteip
ENV APACHE_DOCUMENT_ROOT=/srv/app/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
    /etc/apache2/sites-available/*.conf && \
    sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' \
    /etc/apache2/apache2.conf \
    /etc/apache2/conf-available/*.conf

# 4. Configure Apache to trust reverse proxy headers (Back4App load balancer)
RUN echo '<IfModule mod_remoteip.c>\n\
    RemoteIPHeader X-Forwarded-For\n\
    RemoteIPTrustedProxy 10.0.0.0/8\n\
    RemoteIPTrustedProxy 172.16.0.0/12\n\
    RemoteIPTrustedProxy 192.168.0.0/16\n\
</IfModule>' > /etc/apache2/conf-available/remoteip.conf && \
    a2enconf remoteip

# 5. Bring in Composer v2
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# 6. Copy the project source files
COPY . .

# 7. Install dependencies (--no-scripts avoids cache:clear which needs DATABASE_URL)
ENV APP_ENV=prod
RUN composer install \
    --no-dev \
    --optimize-autoloader \
    --no-interaction \
    --no-scripts

# 8. Configure PHP for production
RUN echo "upload_max_filesize=100M" > /usr/local/etc/php/conf.d/uploads.ini && \
    echo "post_max_size=108M" >> /usr/local/etc/php/conf.d/uploads.ini && \
    echo "memory_limit=512M" >> /usr/local/etc/php/conf.d/uploads.ini && \
    echo "max_execution_time=300" >> /usr/local/etc/php/conf.d/uploads.ini && \
    echo "opcache.preload=/srv/app/config/preload.php" >> /usr/local/etc/php/conf.d/uploads.ini && \
    echo "opcache.preload_user=www-data" >> /usr/local/etc/php/conf.d/uploads.ini && \
    echo "realpath_cache_size=4096K" >> /usr/local/etc/php/conf.d/uploads.ini && \
    echo "realpath_cache_ttl=600" >> /usr/local/etc/php/conf.d/uploads.ini

# 9. Generate JWT keys at build time using a default passphrase.
#    The runtime JWT_PASSPHRASE env var MUST match this value.
ARG JWT_BUILD_PASSPHRASE=change_me_in_production
RUN mkdir -p config/jwt && \
    openssl genrsa -out config/jwt/private.pem -aes256 \
        -passout pass:${JWT_BUILD_PASSPHRASE} 4096 && \
    openssl rsa -pubout \
        -in config/jwt/private.pem \
        -out config/jwt/public.pem \
        -passin pass:${JWT_BUILD_PASSPHRASE}

# 10. Create required directories and set permissions
RUN touch .env && \
    mkdir -p var/cache var/log && \
    chown -R www-data:www-data /srv/app && \
    chmod -R 775 var/

# Back4App reads the EXPOSE value or uses PORT env var
ENV PORT=80
EXPOSE ${PORT}

# 11. Runtime entrypoint: configure port, warmup cache, start Apache
CMD ["sh", "-c", "\
    sed -i \"s/Listen 80/Listen ${PORT}/g\" /etc/apache2/ports.conf && \
    sed -i \"s/:80/:${PORT}/g\" /etc/apache2/sites-available/*.conf && \
    mkdir -p var/cache/prod var/log && \
    chmod -R 777 var/ && \
    php bin/console cache:warmup --env=prod 2>&1 || true && \
    apache2-foreground"]
