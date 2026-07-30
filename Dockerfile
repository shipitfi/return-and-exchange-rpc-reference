FROM php:8.5-cli-bookworm

RUN apt-get update \
    && apt-get install --no-install-recommends --yes \
        libicu-dev \
        libonig-dev \
        libsqlite3-dev \
        libzip-dev \
        unzip \
    && docker-php-ext-install -j"$(nproc)" intl mbstring pcntl pdo_sqlite zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY . .

RUN composer install \
        --classmap-authoritative \
        --no-interaction \
        --no-progress \
        --prefer-dist \
    && chown -R www-data:www-data /app

USER www-data

EXPOSE 8000

HEALTHCHECK --interval=2s --timeout=2s --start-period=20s --retries=15 \
    CMD php -r 'exit(@file_get_contents("http://127.0.0.1:8000/up") === false ? 1 : 0);'

CMD ["sh", "-c", "test -f .env || cp .env.example .env; test -f database/database.sqlite || touch database/database.sqlite; if ! grep -q '^APP_KEY=base64:' .env; then php artisan key:generate --force --no-interaction; fi; php artisan migrate --force --no-interaction; exec php artisan serve --host=0.0.0.0 --port=8000"]
