FROM php:8.3-fpm-alpine

ENV TZ=America/Sao_Paulo
RUN apk add --no-cache tzdata && \
    cp /usr/share/zoneinfo/America/Sao_Paulo /etc/localtime && \
    echo "America/Sao_Paulo" > /etc/timezone

# Instalar dependencias do sistema
# librsvg: conversor SVG nativo (rsvg-convert) - mais confiavel que ImageMagick para SVG
# imagemagick: utilitario geral de imagens
RUN apk add --no-cache \
    git \
    curl \
    netcat-openbsd \
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
    su-exec \
    imagemagick \
    imagemagick-dev \
    librsvg \
    libgomp \
    $PHPIZE_DEPS

# Habilitar SVG no ImageMagick (bloqueado por padrao no Alpine por seguranca)
RUN if [ -f /etc/ImageMagick-7/policy.xml ]; then \
        sed -i 's/rights="none" pattern="SVG"/rights="read|write" pattern="SVG"/' /etc/ImageMagick-7/policy.xml; \
    fi; \
    if [ -f /etc/ImageMagick-6/policy.xml ]; then \
        sed -i 's/rights="none" pattern="SVG"/rights="read|write" pattern="SVG"/' /etc/ImageMagick-6/policy.xml; \
    fi

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

# Instalar Imagick extension (para conversao de imagens via PHP)
RUN pecl install imagick && docker-php-ext-enable imagick

# Instalar Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Configurar PHP para producao
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Configuracoes customizadas do PHP
COPY docker/php/php-custom.ini $PHP_INI_DIR/conf.d/99-custom.ini

# Criar usuario para o Laravel
RUN addgroup -g 1000 -S www && \
    adduser -u 1000 -S www -G www

# Configurar PHP-FPM para usar o usuario www (padrão é www-data) e aplicar
# nosso pool customizado (limites de workers + reciclagem de memória).
# Sem pm.max_requests workers vivem para sempre e acumulam leak de
# Imagick/OPcache até derrubar o container (foi o que aconteceu no
# incidente de 19/06: 6h de silêncio + queda sem crash visível).
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/www.conf

# Healthcheck via FPM ping (responde "pong" se o pool está vivo).
# O Docker marca o container unhealthy se 3 tentativas seguidas falharem,
# permitindo que o Coolify ou o orquestrador detecte o app travado antes
# do restart-loop começar.
HEALTHCHECK --interval=30s --timeout=5s --start-period=90s --retries=3 \
    CMD php-fpm -t 2>/dev/null && \
        (echo -e "GET /fpm-ping HTTP/1.0\r\nHost: localhost\r\n\r\n" | nc -w 2 127.0.0.1 9000 | grep -q pong) || exit 1

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

# Salvar copia dos assets compilados (antes do volume montar por cima)
RUN cp -r /var/www/html/public/build /var/www/html/build-assets

# Copiar entrypoint
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Permissoes
RUN chown -R www:www /var/www/html/storage /var/www/html/bootstrap/cache

# NAO usar USER www aqui - o entrypoint roda como root para
# ter permissao de limpar o volume compartilhado, depois usa
# su-exec para dropar para www antes de iniciar o processo

EXPOSE 9000

ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]
