# Image de base PHP 8.4 avec Apache (8.2 est en fin de vie le 31/12/2026 ; 8.4 est
# maintenue jusqu'à fin 2028).
# Version épinglée : 8.4.27 corrige php2shell (CVE-2026-109618, CVE-2026-109619,
# RCE non authentifiée, mod_php le plus exposé). Toute version antérieure est vulnérable.
# Le test e2e « PHP corrigé contre php2shell » échoue si l'image régresse.
FROM php:8.4.27-apache

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

# Supprimer l'avertissement "Could not reliably determine the server's fully qualified domain name"
# en définissant un ServerName global (avant le démarrage d'Apache)
RUN echo "ServerName localhost" > /etc/apache2/conf-available/servername.conf \
    && a2enconf servername

# Copier la configuration Apache personnalisée
COPY docker/apache/vhost.conf /etc/apache2/sites-available/000-default.conf

# Durcissement PHP (source unique des réglages de requête, voir le fichier)
COPY docker/php/conf.d/zz-hardening.ini $PHP_INI_DIR/conf.d/zz-hardening.ini

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
