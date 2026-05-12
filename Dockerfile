# Web of Influence — single combined image (nginx + php-fpm)
# Built by docker-compose on the host; routed via nginx-proxy-manager.

FROM php:8.3-fpm-alpine

RUN apk add --no-cache nginx supervisor curl ca-certificates \
 && docker-php-ext-install pdo_mysql mysqli \
 && mkdir -p /run/nginx /var/log/supervisor /var/www/html

WORKDIR /var/www/html

# Pre-built React assets and runtime config
COPY deploy/dist/         /var/www/html/
COPY deploy/app-config.js /var/www/html/app-config.js

# PHP API (config.php returns [] — secrets come from env at runtime)
COPY deploy/php-api/      /var/www/html/php-api/

# CSV data exposed as /data for the Admin UI's server-side imports
COPY csv_data/            /var/www/html/data/

# nginx and supervisord
COPY docker/nginx.conf       /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisord.conf

RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s \
  CMD curl -fsS http://localhost/ >/dev/null || exit 1

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]
