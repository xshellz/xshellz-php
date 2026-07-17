# Local development / test image (see docker-compose.yml).
# Pinned to PHP 8.3 CLI so local runs match the newest CI matrix entry.
FROM php:8.3-cli

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# git + unzip: composer needs one of them to install dists.
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*

# pcov: coverage driver for the Pest --coverage --min=80 gate.
RUN pecl install pcov && docker-php-ext-enable pcov
