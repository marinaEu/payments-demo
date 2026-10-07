# Containerfile per Checkout Custom API (Laravel 12 / PHP 8.3)
FROM php:8.3-cli-alpine

# Installa il gestore di estensioni ufficiale per PHP Alpine
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

# Installa le estensioni richieste: pdo_sqlite, sqlite3, redis, zip, bcmath, pcntl
RUN install-php-extensions pdo_sqlite sqlite3 redis zip bcmath pcntl

# Installa Composer da immagine ufficiale
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html

# Copia il sorgente dell'applicazione
COPY . .

# Installa le dipendenze del progetto
RUN composer install --no-interaction --prefer-dist --optimize-autoloader

# Garantisce i corretti permessi per storage e cache
RUN chmod -R 777 storage bootstrap/cache

EXPOSE 8000

# Esegue migrazioni, seeder di catalogo e avvia il server API
CMD ["sh", "-c", "php artisan migrate --force && php artisan db:seed --force && php artisan serve --host=0.0.0.0 --port=8000"]
