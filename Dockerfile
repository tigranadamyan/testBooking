# Простой образ для тестового задания: PHP + sqlite + artisan serve.
FROM php:8.3-cli

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# composer распаковывает zip-дистрибу — нужен unzip.
RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/*

# Сначала код — потом зависимости (post-autoload-dump гоняет artisan).
COPY . .
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Подготовка окружения на этапе сборки.
RUN cp .env.example .env \
    && php artisan key:generate --force \
    && touch database/database.sqlite

EXPOSE 8000

# При каждом старте — чистая демо-база с сидом, затем сервер.
CMD ["sh", "-c", "php artisan migrate:fresh --seed --force && php artisan serve --host=0.0.0.0 --port=8000"]
