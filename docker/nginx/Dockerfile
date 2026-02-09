# Stage 1: Buildar os assets usando o mesmo Dockerfile do PHP
FROM php:8.3-fpm-alpine AS builder

RUN apk add --no-cache \
    git curl libpng-dev libjpeg-turbo-dev freetype-dev \
    oniguruma-dev libxml2-dev zip unzip libzip-dev icu-dev \
    linux-headers $PHPIZE_DEPS nodejs npm

RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) pdo_mysql mbstring bcmath gd zip intl

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY . .
RUN composer install --no-dev --optimize-autoloader --no-interaction
RUN npm ci --legacy-peer-deps && npm run build

# Stage 2: Nginx final com assets compilados
FROM nginx:alpine

# Copiar configuracao do nginx
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf

# Copiar arquivos publicos do repositorio (favicon, robots, etc)
COPY public /var/www/html/public

# Copiar assets compilados pelo Vite (JS, CSS) do stage builder
COPY --from=builder /var/www/html/public/build /var/www/html/public/build

EXPOSE 80

CMD ["nginx", "-g", "daemon off;"]
