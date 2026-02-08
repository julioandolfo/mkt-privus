FROM php:8.3-fpm-alpine

# Instalar dependencias do sistema
RUN apk add --no-cache \
    git \
    curl \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    oniguruma-dev \
    libxml2-dev \
    zip \
    unzip \
    libzip-dev \
    icu-dev \
    linux-headers \
    $PHPIZE_DEPS

# Instalar extensoes PHP
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        mbstring \
        exif \
        pcntl \
        bcmath \
        gd \
        zip \
        intl \
        opcache \
        sockets

# Instalar Redis extension
RUN pecl install redis && docker-php-ext-enable redis

# Instalar Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Configurar PHP para producao
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Configuracoes customizadas do PHP
COPY docker/php/php-custom.ini $PHP_INI_DIR/conf.d/99-custom.ini

# Criar usuario para o Laravel
RUN addgroup -g 1000 -S www && \
    adduser -u 1000 -S www -G www

# Diretorio de trabalho
WORKDIR /var/www/html

# Copiar arquivos do projeto
COPY --chown=www:www . .

# Instalar dependencias do Composer (producao)
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Instalar dependencias Node e buildar assets
RUN apk add --no-cache nodejs npm && \
    npm ci --legacy-peer-deps && \
    npm run build && \
    rm -rf node_modules && \
    apk del nodejs npm

# Permissoes
RUN chown -R www:www /var/www/html/storage /var/www/html/bootstrap/cache

USER www

EXPOSE 9000

# Cachear config/routes/views e rodar migrations no startup
CMD sh -c "php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan migrate --force && php-fpm"
