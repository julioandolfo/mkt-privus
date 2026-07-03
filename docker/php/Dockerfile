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
# -j2 em vez de -j$(nproc): o Coolify builda app, worker e scheduler em
# PARALELO (mesma imagem base, 3 stages independentes), cada um chamando
# este ext-install. Com -j$(nproc) cada stage usa todos os CPUs do builder
# helper, então o consumo total = nproc × 3 stages — estoura RAM/CPU e o
# builder mata um deles com exit 255 (deploy 23/06). -j2 mantém compilação
# razoavelmente rápida sem inundar o builder.
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j2 \
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
# Versão fixa 6.1.0 (estável, PHP 8.0-8.4) como PRIMÁRIA, mas com
# resiliência: o pecl.php.net tem blips de rede intermitentes no momento
# do build (deploy 28/06 quebrou aqui SEM mudança de código; o de 27/06
# com o MESMO Dockerfile passou — falha transitória clássica).
# Estratégia: refresca o canal, tenta 6.1.0 duas vezes (retry de rede
# com pausa) e, por último, cai para "latest" (que sempre existe no
# índice) — assim um blip ou uma versão sumida não trava o deploy.
# `printf "\n"` responde default a prompt interativo do pecl.
RUN set -eux; \
    pecl channel-update pecl.php.net || true; \
    ok=0; \
    for target in redis-6.1.0 redis-6.1.0 redis; do \
        if printf "\n" | pecl install -o -f "$target"; then ok=1; break; fi; \
        echo ">> pecl redis ($target) falhou, aguardando 5s..."; sleep 5; \
    done; \
    [ "$ok" = "1" ]; \
    docker-php-ext-enable redis; \
    rm -rf /tmp/pear

# Instalar Imagick extension (para conversao de imagens via PHP)
# Versão fixa 3.8.0: primeira release com suporte OFICIAL a ImageMagick 7
# (o Alpine atual instala IM 7.1.x; a 3.7.0 só suporta IM6 e quebra o
# compile). Mesma estratégia de resiliência do redis: retry + fallback
# para latest, para sobreviver a blips do pecl.php.net.
RUN set -eux; \
    ok=0; \
    for target in imagick-3.8.0 imagick-3.8.0 imagick; do \
        if printf "\n" | pecl install -o -f "$target"; then ok=1; break; fi; \
        echo ">> pecl imagick ($target) falhou, aguardando 5s..."; sleep 5; \
    done; \
    [ "$ok" = "1" ]; \
    docker-php-ext-enable imagick; \
    rm -rf /tmp/pear

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

# Sem HEALTHCHECK aqui de propósito: a porta 9000 fala FastCGI binário
# (não HTTP), tentar healthcheck via curl/nc dá falso negativo. O
# healthcheck end-to-end fica no container nginx (curl http://nginx/up),
# que naturalmente cobre o app: se o FPM estiver morto, o nginx retorna
# 502 e o container nginx fica unhealthy — sinal suficiente pro Coolify.

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
