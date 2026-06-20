FROM nginx:alpine

ENV TZ=America/Sao_Paulo
RUN apk add --no-cache tzdata curl && \
    cp /usr/share/zoneinfo/America/Sao_Paulo /etc/localtime && \
    echo "America/Sao_Paulo" > /etc/timezone

# Copiar configuracao do nginx
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf

# Copiar arquivos publicos base (favicon, robots, index.php, etc.)
# Os assets compilados (build/) virao via volume compartilhado em runtime
COPY public /var/www/html/public

# Healthcheck: bate em /up (rota nativa do Laravel 12 — health check)
# através do nginx. Cobre os 2 problemas comuns:
#   1) nginx vivo mas PHP-FPM travado (curl recebe 502/504)
#   2) PHP-FPM responde mas o Laravel falha no boot (curl recebe 500)
# Em qualquer caso o container fica unhealthy e o Coolify pode reagir
# ANTES de entrar em restart-loop silencioso.
HEALTHCHECK --interval=30s --timeout=10s --start-period=120s --retries=3 \
    CMD curl -fsS http://127.0.0.1/up >/dev/null || exit 1

EXPOSE 80

CMD ["nginx", "-g", "daemon off;"]
