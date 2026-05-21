FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
    git unzip zip libzip-dev libpq-dev gettext-base \
    && docker-php-ext-install pdo pdo_pgsql pdo_mysql zip \
    && a2enmod rewrite headers

COPY --from=composer:2.9.5 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader \
    --no-scripts

COPY . .

RUN composer dump-autoload --optimize \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

RUN cat > /etc/apache2/sites-available/000-default.conf.template <<'EOF'
<VirtualHost *:${PORT}>
    ServerAdmin webmaster@localhost
    DocumentRoot /var/www/html/public

    <Directory /var/www/html/public>
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog /proc/self/fd/2
    CustomLog /proc/self/fd/1 combined
</VirtualHost>
EOF

RUN cat > /etc/apache2/ports.conf.template <<'EOF'
Listen ${PORT}
EOF

EXPOSE 10000

CMD bash -c 'export PORT=${PORT:-10000} && \
    envsubst < /etc/apache2/ports.conf.template > /etc/apache2/ports.conf && \
    envsubst < /etc/apache2/sites-available/000-default.conf.template > /etc/apache2/sites-available/000-default.conf && \
    php artisan config:clear && \
    php artisan cache:clear && \
    php artisan route:clear && \
    php artisan config:cache && \
    apache2-foreground'
