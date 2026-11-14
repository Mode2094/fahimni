# Pinned to the exact images used and verified locally (2026-09-10):
#   php:8.4-fpm-alpine   → PHP 8.4.25   sha256:4973…
#   composer:2           → Composer 2.10.2
# Bump by re-tagging and re-verifying the clean build in tests.
FROM php@sha256:49734670eccf414af884c2a0c2e558401e228615f8028f1c9fca30a0d4fb1bc2 AS base

RUN apk add --no-cache \
    postgresql-dev \
    postgresql-client \
    libzip-dev \
    zip \
    unzip \
    curl \
    git \
    supervisor \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    oniguruma-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_pgsql zip bcmath opcache pcntl gd mbstring

# redis extension pinned (latest stable for PHP 8.4 at pin time).
RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && pecl install redis-6.2.0 \
    && docker-php-ext-enable redis \
    && apk del .build-deps

# Production PHP settings (uploads sized for 5G video uploads, opcache on).
COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-lms.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/www.conf

COPY --from=composer@sha256:d020706319701a44468968321dccd0fce6620190159a7a9ec195d78e6e971c71 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . .

# البناء بلا .env: نمنع الفحوصات الصارمة لبيئة الإنتاج أثناء التثبيت
# (docker-compose يفرض APP_ENV=production عند التشغيل الفعلي).
ENV APP_ENV=local

RUN composer install --no-dev --optimize-autoloader --no-interaction \
    && chown -R www-data:www-data storage bootstrap/cache \
    && ln -sfn /var/www/html/storage/app/public /var/www/html/public/storage

FROM base AS app

# Never run PHP as root: all runtime stages drop to www-data.
USER www-data

EXPOSE 9000

# Production runtime: compile config/route/view/event caches once so every
# request skips interpretation. Values come from container env (env_file),
# never from a .env file inside the image.
#
# Belt-and-suspenders for the writable-dir skeleton: the dirs are committed
# (see .gitignore) and baked into the image, but an unexpected/empty volume
# layout must never be able to abort startup. If storage/framework/views is
# missing, config('view.compiled') becomes false and `optimize` exits non-zero,
# so php-fpm would never start. mkdir -p is a no-op in the normal case.
CMD ["sh", "-c", "mkdir -p storage/framework/views storage/framework/cache/data storage/framework/sessions storage/logs && php artisan optimize && php-fpm"]

FROM base AS scheduler

USER www-data

CMD ["php", "artisan", "schedule:work"]

FROM base AS horizon

USER www-data

CMD ["php", "artisan", "horizon"]
