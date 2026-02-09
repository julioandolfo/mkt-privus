#!/bin/sh
set -e

# Apenas o container principal (php-fpm) faz setup
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
    php artisan migrate --force 2>&1 || echo "AVISO: Migrations falharam"

    echo "==> [app] Cacheando configuracoes..."
    php artisan config:cache 2>&1 || echo "AVISO: config:cache falhou"
    php artisan route:cache 2>&1 || echo "AVISO: route:cache falhou"
    php artisan view:cache 2>&1 || echo "AVISO: view:cache falhou"

    echo "==> [app] Storage link..."
    php artisan storage:link 2>/dev/null || true

    echo "==> [app] Iniciando PHP-FPM..."
fi

exec "$@"
