#!/bin/bash

# Script de setup Docker pour le proxy OAuth Enedis
# Usage: ./docker-setup.sh

set -e

echo "🚀 Setup du Proxy OAuth Enedis avec Docker"
echo "==========================================="
echo ""

# Couleurs pour les messages
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Fonction pour afficher les messages
info() {
    echo -e "${GREEN}✓${NC} $1"
}

warn() {
    echo -e "${YELLOW}⚠${NC} $1"
}

error() {
    echo -e "${RED}✗${NC} $1"
}

# Vérifier que Docker est installé
if ! command -v docker &> /dev/null; then
    error "Docker n'est pas installé !"
    echo "Installe Docker Desktop depuis : https://www.docker.com/products/docker-desktop"
    exit 1
fi

info "Docker est installé : $(docker --version)"

# Vérifier que Docker Compose est disponible
if ! docker compose version &> /dev/null; then
    error "Docker Compose n'est pas disponible !"
    exit 1
fi

info "Docker Compose est disponible : $(docker compose version)"

echo ""
echo "📝 Configuration du fichier .env"
echo "================================"

# Vérifier si .env existe
if [ -f .env ]; then
    warn "Le fichier .env existe déjà"
    read -p "Voulez-vous le recréer ? (y/N) " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        info "Utilisation du .env existant"
    else
        cp .env.docker .env
        info ".env créé depuis .env.docker"
    fi
else
    cp .env.docker .env
    info ".env créé depuis .env.docker"
fi

# Générer un APP_SECRET aléatoire
APP_SECRET=$(openssl rand -hex 32)
if [[ "$OSTYPE" == "darwin"* ]]; then
    # macOS
    sed -i '' "s/changeme_generate_with_openssl_rand_hex_32/$APP_SECRET/" .env
else
    # Linux
    sed -i "s/changeme_generate_with_openssl_rand_hex_32/$APP_SECRET/" .env
fi
info "APP_SECRET généré automatiquement"

echo ""
warn "⚠️  IMPORTANT : Tu dois configurer les credentials Enedis dans .env"
echo "   CLIENT_ID et CLIENT_SECRET à obtenir sur https://datahub-enedis.fr"
echo ""

read -p "Appuie sur Entrée quand tu as configuré CLIENT_ID et CLIENT_SECRET dans .env..."

echo ""
echo "🐳 Construction des images Docker"
echo "=================================="

docker compose build

info "Images Docker construites"

echo ""
echo "🚀 Démarrage des conteneurs"
echo "==========================="

docker compose up -d

info "Conteneurs démarrés"

echo ""
echo "⏳ Attente que PostgreSQL soit prêt..."

# Attendre que PostgreSQL soit healthy
timeout=30
counter=0
while [ $counter -lt $timeout ]; do
    if docker compose exec -T db pg_isready -U enedis_user &> /dev/null; then
        info "PostgreSQL est prêt !"
        break
    fi
    sleep 1
    counter=$((counter + 1))
    echo -n "."
done

if [ $counter -eq $timeout ]; then
    error "Timeout : PostgreSQL n'a pas démarré"
    exit 1
fi

echo ""
echo "📊 Initialisation de la base de données"
echo "======================================="

# La table cache sera créée automatiquement par le code PHP
info "La table 'cache' sera créée automatiquement au premier accès"

echo ""
echo "✅ Installation terminée !"
echo "=========================="
echo ""
info "Application disponible sur : http://localhost:8080"
info "Adminer (DB UI) disponible sur : http://localhost:8081"
echo ""
echo "🧪 Pour tester :"
echo "   curl -X POST http://localhost:8080/device/code -d 'client_id=ton_client_id'"
echo ""
echo "📋 Commandes utiles :"
echo "   docker compose logs -f app    # Voir les logs de l'app"
echo "   docker compose logs -f db     # Voir les logs PostgreSQL"
echo "   docker compose down           # Arrêter les conteneurs"
echo "   docker compose restart app    # Redémarrer l'app"
echo ""
info "Consulte GUIDE_TEST_LOCAL.md pour plus de détails"
