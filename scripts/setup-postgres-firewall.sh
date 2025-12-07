#!/bin/bash
# Configuration du pare-feu pour PostgreSQL
# Autorise uniquement l'IP whitelistée à accéder au port 5434

# IP autorisée
ALLOWED_IP="176.147.54.225"

echo "Configuration du pare-feu UFW pour PostgreSQL..."

# Vérifier si UFW est installé
if ! command -v ufw &> /dev/null; then
    echo "UFW n'est pas installé. Installation..."
    sudo apt-get update
    sudo apt-get install -y ufw
fi

# Autoriser l'IP spécifique sur le port 5434
echo "Autorisation de l'IP ${ALLOWED_IP} sur le port 5434..."
sudo ufw allow from ${ALLOWED_IP} to any port 5434 proto tcp

# Activer UFW si ce n'est pas déjà fait
sudo ufw --force enable

# Afficher le statut
echo ""
echo "Configuration terminée. Statut UFW:"
sudo ufw status numbered

echo ""
echo "Pour DataGrip, utilisez:"
echo "  Host: $(curl -s ifconfig.me)"
echo "  Port: 5434"
echo "  Database: enedis_oauth_proxy_db"
echo "  User: enedis_proxy_admin"
echo "  Password: (voir .env)"
