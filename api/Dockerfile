# Imagem de produção para o Coolify (build por Dockerfile). Um único
# container expõe a porta 80; Coolify cuida de TLS/domínio via Traefik.
#
# Migrações NÃO rodam automaticamente no boot — configurar como
# "Post-deployment command" no Coolify: php artisan migrate --force
# (mantém o gate de "backup antes de alterações" do plan.md).

FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --prefer-dist \
    --optimize-autoloader

FROM php:8.4-fpm-alpine AS app

RUN apk add --no-cache \
        nginx \
        supervisor \
        postgresql-libs \
        libzip \
        icu-libs \
    && apk add --no-cache --virtual .build-deps \
        postgresql-dev \
        libzip-dev \
        icu-dev \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql \
        pgsql \
        bcmath \
        opcache \
        zip \
        intl \
    && apk del .build-deps

WORKDIR /var/www/html

COPY --from=vendor /app/vendor ./vendor
COPY . .

RUN mkdir -p storage/framework/{cache,sessions,testing,views} storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache \
    # Regenera o cache de descoberta de pacotes a partir do vendor --no-dev
    # desta imagem — o do host (com pacotes de dev) fica fora do contexto
    # (.dockerignore) e não deve ser reaproveitado aqui.
    && php artisan package:discover --ansi

COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/timersmit.ini

EXPOSE 80

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
