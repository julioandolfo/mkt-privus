FROM nginx:alpine

ENV TZ=America/Sao_Paulo
RUN apk add --no-cache tzdata && \
    cp /usr/share/zoneinfo/America/Sao_Paulo /etc/localtime && \
    echo "America/Sao_Paulo" > /etc/timezone

# Copiar configuracao do nginx
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf

# Copiar arquivos publicos base (favicon, robots, index.php, etc.)
# Os assets compilados (build/) virao via volume compartilhado em runtime
COPY public /var/www/html/public

EXPOSE 80

CMD ["nginx", "-g", "daemon off;"]
