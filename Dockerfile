# Image de base PHP 8.2 avec Apache
FROM php:8.2-apache

# Informations du mainteneur
LABEL maintainer="clement.fevre@example.com"
LABEL description="Proxy OAuth 2.0 Device Flow pour Enedis Data Hub"

# Installation des dépendances système
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
    libpq-dev \
    libssl-dev \
    curl \
    && docker-php-ext-install \
    pdo \
    pdo_pgsql \
    zip \

    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Installation de Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Configuration Apache
RUN a2enmod rewrite headers ssl

# Copier la configuration Apache personnalisée
COPY docker/apache/vhost.conf /etc/apache2/sites-available/000-default.conf

# Définir le répertoire de travail
WORKDIR /var/www/html

# Copier le manifeste de dépendances
COPY composer.json ./

# Installer les dépendances (génère composer.lock en build)
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --no-progress \
    && composer dump-autoload --optimize

# Copier le reste du code
COPY . .

# Permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Créer les répertoires nécessaires pour Symfony
RUN mkdir -p var/cache var/log \
    && chown -R www-data:www-data var/ \
    && chmod -R 775 var/

# Exposer le port 80
EXPOSE 80

# Commande de démarrage
CMD ["apache2-foreground"]
