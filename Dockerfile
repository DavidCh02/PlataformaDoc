# PlataformaDoc en Railway: PHP 8.2 + Node 22 + Chromium (exportar PDF).
# Se usa Dockerfile en vez de Nixpacks porque el PHP de Nixpacks no trae
# ext-zip (PhpWord y los metadatos del .docx la exigen).
# NOTA: el COPY va primero a propósito para que el build funcione aunque el
# .dockerignore no esté commiteado (las dependencias se reinstalan encima).
FROM php:8.2-cli-bookworm

# Sistema: git/unzip (composer), librerías de extensiones PHP, Node.js,
# Chromium + fuentes (para que el PDF no salga con cuadritos).
RUN apt-get update && apt-get install -y --no-install-recommends \
    git unzip curl ca-certificates \
    libzip-dev libpng-dev libjpeg-dev libfreetype6-dev libonig-dev libxml2-dev \
    chromium fonts-dejavu-core \
 && curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
 && apt-get install -y --no-install-recommends nodejs \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j$(nproc) pdo_mysql mbstring zip exif pcntl gd bcmath \
 && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY . .
RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
 && composer install --no-dev --optimize-autoloader --no-interaction --no-progress --no-scripts \
 && composer dump-autoload --optimize \
 && php artisan package:discover --ansi \
 && npm ci --no-audit --no-fund \
 && npm run build \
 && php artisan optimize

EXPOSE 8080

# BROWSERSHOT_CHROME_PATH se resuelve aquí (/usr/bin/chromium en Debian).
# $PORT lo inyecta Railway. No fijar PORT manualmente.
CMD export BROWSERSHOT_CHROME_PATH=$(which chromium) && php artisan migrate --force && php artisan serve --host=0.0.0.0 --port=$PORT
