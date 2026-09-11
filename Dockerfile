# Apartar — imagen de aplicación (REQ-INF-001, REQ-INF-006 RB-1/RB-2).
#
# Cuatro etapas:
#   base    — PHP 8.3-fpm-alpine con todas las extensiones del stack.
#   vendor  — dependencias de Composer (sin dev) para la imagen final.
#   assets  — build de Vite con Node 22; node_modules nunca llega al runtime.
#   runtime — imagen servible: código + vendor + public/build.
#
# La misma imagen sirve a los servicios `app`, `queue` y `reverb`; lo que
# cambia entre ellos es CONTAINER_ROLE (ver docker/entrypoint.sh).

# ---------------------------------------------------------------------------
# base
# ---------------------------------------------------------------------------
FROM php:8.3-fpm-alpine AS base

# Extensiones exigidas por el stack (plan T-002, "Hallazgos de T-001"):
#   pdo_mysql  MySQL 8.0
#   bcmath     bcmul escala 8 en la conversión a VES (REQ-FX-004 RB-5)
#   exif + gd  spatie/laravel-medialibrary (REQ-CAT-005)
#   zip        maatwebsite/excel (REQ-BOF-043)
#   intl       formato de números y fechas por locale
#   pcntl      señales de queue:work (REQ-INF-003 RB-3)
#   opcache    rendimiento
#   redis      caché, sesión, cola y broadcast (REQ-INF-004 RB-1)
RUN apk add --no-cache \
        freetype \
        icu-libs \
        libjpeg-turbo \
        libpng \
        libzip \
        tzdata \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        freetype-dev \
        icu-dev \
        libjpeg-turbo-dev \
        libpng-dev \
        libzip-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        exif \
        gd \
        intl \
        opcache \
        pcntl \
        pdo_mysql \
        zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    # pcov y no xdebug: el umbral de cobertura del dominio (REQ-QAX-003 RB-1)
    # se mide en cada ejecución de la suite y pcov cuesta un orden de magnitud
    # menos. Queda desactivado salvo que `pest --coverage` lo encienda.
    && pecl install pcov \
    && docker-php-ext-enable pcov \
    && apk del .build-deps \
    && rm -rf /tmp/pear

COPY docker/php.ini /usr/local/etc/php/conf.d/apartar.ini
COPY docker/opcache.ini /usr/local/etc/php/conf.d/opcache.ini

WORKDIR /var/www/html

# ---------------------------------------------------------------------------
# vendor
# ---------------------------------------------------------------------------
FROM base AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY composer.json composer.lock ./

# --no-dev: las dependencias de desarrollo no llegan al runtime (REQ-INF-006 RB-1).
# --no-scripts / --no-autoloader: todavía no está el código de la aplicación.
RUN composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --no-scripts \
        --no-autoloader \
        --prefer-dist

# Con el código presente, el classmap optimizado ya cubre app/ y database/.
COPY app ./app
COPY bootstrap ./bootstrap
COPY database ./database
COPY routes ./routes

# --no-scripts: package:discover corre en el runtime, que es donde vive el
# bootstrap/cache definitivo.
RUN composer dump-autoload --no-dev --optimize --no-scripts --no-interaction

# ---------------------------------------------------------------------------
# assets
# ---------------------------------------------------------------------------
FROM node:22-alpine AS assets

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY resources ./resources
COPY public ./public
COPY vite.config.js tsconfig.json components.json ./

# Los assets se compilan en el build, no en el arranque (REQ-INF-006 RB-2).
RUN npm run build

# ---------------------------------------------------------------------------
# runtime
# ---------------------------------------------------------------------------
FROM base AS runtime

COPY . .
COPY --from=vendor /var/www/html/vendor ./vendor
COPY --from=assets /app/public/build ./public/build

# Las recetas de calidad viven como scripts de composer (decisión de T-003) y
# `make check` las ejecuta dentro de este contenedor. Sin el binario, el
# Makefile tendría que repetir cada comando y habría dos definiciones de la
# misma comprobación. T-087 decide si la imagen de producción lo conserva.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint \
    && mkdir -p storage/app/public storage/framework/cache/data \
        storage/framework/sessions storage/framework/views storage/logs \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

# CONTAINER_ROLE decide qué arranca y quién migra (REQ-INF-001 RB-7).
ENV CONTAINER_ROLE=app

EXPOSE 9000

ENTRYPOINT ["entrypoint"]
