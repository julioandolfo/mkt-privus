#!/bin/sh

# =====================================================
# Entrypoint roda como ROOT para ter permissao no volume
# PHP-FPM inicia como root e faz o drop para www nos workers
# Outros processos (worker, scheduler) usam su-exec
# =====================================================

# NAO usar set -e aqui! Queremos que o PHP-FPM inicie
# mesmo que algum passo de setup falhe.

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

# Garantir permissoes do storage e cache (CRITICAL: must succeed for logging to work)
echo "==> Ajustando permissoes de storage e cache..."
chown -R www:www /var/www/html/storage /var/www/html/bootstrap/cache 2>&1 || echo "==> ERRO: chown falhou no storage!"
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache 2>&1 || echo "==> ERRO: chmod falhou no storage!"

# Garantir que o diretorio de logs existe e tem permissao
mkdir -p /var/www/html/storage/logs
mkdir -p /var/www/html/storage/framework/sessions
mkdir -p /var/www/html/storage/framework/views
mkdir -p /var/www/html/storage/framework/cache/data

# Criar arquivos de log com permissoes corretas
touch /var/www/html/storage/logs/laravel.log
touch /var/www/html/storage/logs/php-errors.log
chown www:www /var/www/html/storage/logs/laravel.log /var/www/html/storage/logs/php-errors.log
chmod 664 /var/www/html/storage/logs/laravel.log /var/www/html/storage/logs/php-errors.log

# Verificar se o storage realmente ficou gravavel
TEST_FILE="/var/www/html/storage/logs/.perm-test"
if su-exec www:www touch "$TEST_FILE" 2>/dev/null; then
    rm -f "$TEST_FILE"
    echo "==> Permissoes de storage: OK"
else
    echo "==> ALERTA: storage NAO gravavel pelo usuario www! Tentando chmod 777..."
    chmod -R 777 /var/www/html/storage 2>/dev/null || true
fi

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

    echo "==> [app] Rodando migrations (timeout: 120s)..."
    timeout 120 su-exec www:www php artisan migrate --force 2>&1 || echo "==> AVISO: Migrations falharam ou timeout (continuando de qualquer forma)"

    echo "==> [app] Cacheando configuracoes..."
    su-exec www:www php artisan config:cache 2>&1 || echo "==> AVISO: config:cache falhou"
    su-exec www:www php artisan route:cache 2>&1 || echo "==> AVISO: route:cache falhou"
    su-exec www:www php artisan view:cache 2>&1 || echo "==> AVISO: view:cache falhou"

    echo "==> [app] Storage link..."
    su-exec www:www php artisan storage:link 2>/dev/null || true

    echo "==> [app] Seed templates de metricas sociais..."
    timeout 60 su-exec www:www php artisan social:sync-insights --seed-templates 2>&1 || echo "==> AVISO: seed-templates falhou ou timeout"

    echo "==> [app] Iniciando PHP-FPM..."
    # PHP-FPM DEVE iniciar como root - ele faz o drop para www nos workers via pool config
    exec "$@"
fi

# Para outros processos (worker, scheduler), rodar como www
exec su-exec www:www "$@"
