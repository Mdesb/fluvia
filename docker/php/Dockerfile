FROM php:8.4-fpm-bookworm

# Résilience réseau : apt réessaie les téléchargements qui échouent
RUN echo 'Acquire::Retries "8";' > /etc/apt/apt.conf.d/80retries

# Installeur d'extensions PHP (gère proprement les dépendances de build et le nettoyage)
COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/bin/

# Extensions requises par Symfony / Doctrine (MariaDB) / API Platform
RUN install-php-extensions pdo_mysql intl zip opcache

# Outils de base + Composer
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /app
