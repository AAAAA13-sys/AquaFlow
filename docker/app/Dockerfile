# AquaFlow application tier (Tier 2) - Laravel + PHP 8.2 + Apache
# Build context: repository root.
FROM composer:2 AS vendor

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-interaction \
        --no-scripts \
        --prefer-dist \
        --optimize-autoloader

FROM php:8.2-apache

# PDO MySQL driver + hardened Apache defaults
RUN docker-php-ext-install pdo_mysql \
    && a2enmod headers rewrite \
    && { \
         echo 'ServerTokens Prod'; \
         echo 'ServerSignature Off'; \
         echo 'expose_php Off'; \
         echo 'Header always set X-Content-Type-Options "nosniff"'; \
         echo 'Header always set Referrer-Policy "same-origin"'; \
       } > /etc/apache2/conf-available/aquaflow-hardening.conf \
    && a2enconf aquaflow-hardening

# Serve Laravel's public/ directory instead of the framework root
RUN sed -ri 's!/var/www/html!/var/www/html/public!g' \
        /etc/apache2/sites-available/000-default.conf \
        /etc/apache2/apache2.conf \
    && { \
         echo '<Directory /var/www/html/public>'; \
         echo '  AllowOverride All'; \
         echo '  Require all granted'; \
         echo '</Directory>'; \
       } > /etc/apache2/conf-available/aquaflow-docroot.conf \
    && a2enconf aquaflow-docroot

WORKDIR /var/www/html

COPY . .
COPY --from=vendor /app/vendor ./vendor

RUN chown -R www-data:www-data storage bootstrap/cache \
    && php artisan config:clear

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=25s --retries=3 \
    CMD php -r "exit(@file_get_contents('http://127.0.0.1/api/health') !== false ? 0 : 1);"
