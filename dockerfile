FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
    git unzip zip libzip-dev libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql pdo_mysql zip \
    && a2enmod rewrite

COPY --from=composer:2.9.5 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts

COPY . .

RUN composer dump-autoload --optimize \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache \
    && sed -ri -e 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!/var/www/html/public!g' /etc/apache2/apache2.conf

EXPOSE 10000

CMD bash -c 'sed -i "s/Listen 80/Listen ${PORT:-10000}/" /etc/apache2/ports.conf && \
             sed -i "s/:80>/:${PORT:-10000}>/" /etc/apache2/sites-available/000-default.conf && \
             php artisan config:clear && \
             php artisan cache:clear && \
             php artisan route:clear && \
             php artisan config:cache && \
             apache2-foreground'
