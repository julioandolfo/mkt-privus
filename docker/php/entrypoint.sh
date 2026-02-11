#!/bin/sh
set -e

# =====================================================
# Entrypoint roda como ROOT para ter permissao no volume
# PHP-FPM inicia como root e faz o drop para www nos workers
# Outros processos (worker, scheduler) usam su-exec
# =====================================================

# Copiar assets compilados para o volume compartilhado
if [ -d "/var/www/html/build-assets" ]; then
    echo "==> Copiando assets Vite para volume compartilhado..."
    rm -rf /var/www/html/public/build/* 2>/dev/null || true
    rm -rf /var/www/html/public/build/.vite 2>/dev/null || true
    mkdir -p /var/www/html/public/build
    cp -a /var/www/html/build-assets/. /var/www/html/public/build/
    chown -R www:www /var/www/html/public/build
    echo "==> Assets copiados com sucesso."
    echo "==> Manifest: $(ls -la /var/www/html/public/build/.vite/manifest.json 2>/dev/null || echo 'NAO ENCONTRADO')"
fi

# Garantir permissoes do storage e cache
chown -R www:www /var/www/html/storage /var/www/html/bootstrap/cache 2>/dev/null || true

# Apenas o container principal (php-fpm) faz setup do banco
if [ "$1" = "php-fpm" ]; then

    echo "==> [app] Aguardando MySQL..."
    MAX_TRIES=30
    COUNT=0
    while ! php -r "new PDO('mysql:host=${DB_HOST:-mysql};port=${DB_PORT:-3306}', '${DB_USERNAME:-root}', '${DB_PASSWORD:-}');" 2>/dev/null; do
        COUNT=$((COUNT + 1))
        if [ $COUNT -ge $MAX_TRIES ]; then
            echo "==> AVISO: MySQL nao disponivel apos ${MAX_TRIES} tentativas, continuando..."
            break
        fi
        echo "    MySQL nao pronto, tentativa ${COUNT}/${MAX_TRIES}..."
        sleep 2
    done

    echo "==> [app] Rodando migrations..."
    su-exec www:www php artisan migrate --force 2>&1 || echo "AVISO: Migrations falharam"

    echo "==> [app] Cacheando configuracoes..."
    su-exec www:www php artisan config:cache 2>&1 || echo "AVISO: config:cache falhou"
    su-exec www:www php artisan route:cache 2>&1 || echo "AVISO: route:cache falhou"
    su-exec www:www php artisan view:cache 2>&1 || echo "AVISO: view:cache falhou"

    echo "==> [app] Storage link..."
    su-exec www:www php artisan storage:link 2>/dev/null || true

    echo "==> [app] Iniciando PHP-FPM..."
    # PHP-FPM DEVE iniciar como root - ele faz o drop para www nos workers via pool config
    exec "$@"
fi

# Para outros processos (worker, scheduler), rodar como www
exec su-exec www:www "$@"
