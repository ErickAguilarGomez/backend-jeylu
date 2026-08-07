FROM php:8.4-fpm-alpine AS builder

WORKDIR /app

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

RUN apk add --no-cache zip unzip git mysql-client freetype-dev libjpeg-turbo-dev libpng-dev zlib-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo pdo_mysql gd

COPY composer.json composer.lock ./

RUN composer install --no-dev --prefer-dist --optimize-autoloader --no-scripts

COPY . .

FROM php:8.4-fpm-alpine AS runner

WORKDIR /app

RUN apk add --no-cache mysql-client freetype-dev libjpeg-turbo-dev libpng-dev zlib-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo pdo_mysql gd
    
COPY --from=builder --chown=www-data:www-data /app /app

USER www-data

EXPOSE 9000

CMD ["php-fpm"]