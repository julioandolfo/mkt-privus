FROM nginx:alpine

# Copiar configuração do nginx
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf

# Copiar arquivos públicos (assets, favicon, etc.)
COPY public /var/www/html/public

EXPOSE 80

CMD ["nginx", "-g", "daemon off;"]
