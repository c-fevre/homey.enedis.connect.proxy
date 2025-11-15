#!/usr/bin/env bash
set -e

# Script de test du flux Device Code + CONSENT sur le VPS
# Usage: ./test-flow.sh

echo "=== Test du flux OAuth Device Code + CONSENT ==="
echo ""

# 1) Configuration
BASE_URL="https://homey-enedis-proxy.clement-fevre.com"
# Alternative locale: BASE_URL="http://127.0.0.1:8080"

CLIENT_ID="$(grep -E '^CLIENT_ID=' .env | sed 's/^CLIENT_ID=//' | tr -d '"')"

if [ -z "$CLIENT_ID" ]; then
  echo "Erreur: CLIENT_ID introuvable dans .env"
  exit 1
fi

echo "BASE_URL: $BASE_URL"
echo "CLIENT_ID: ${CLIENT_ID:0:10}..."
echo ""

# 2) Générer device_code
echo "=== Étape 1: Génération du device_code ==="
curl -sS "${BASE_URL}/device/code" -d client_id="${CLIENT_ID}" > gen.json
cat gen.json | jq .
echo ""

DEVICE_CODE="$(jq -r .device_code gen.json)"
USER_CODE="$(jq -r .user_code gen.json)"
VERIFY_URI="$(jq -r .verification_uri gen.json)"

echo "DEVICE_CODE: ${DEVICE_CODE}"
echo "USER_CODE: ${USER_CODE}"
echo ""
echo "👉 Ouvre dans ton navigateur: ${VERIFY_URI}?code=${USER_CODE}"
echo "   Ou: ${BASE_URL}/device?code=${USER_CODE}"
echo ""
echo "Appuie sur Entrée après avoir validé le consentement..."
read -r

# 3) Polling pour récupérer les tokens
echo ""
echo "=== Étape 2: Polling pour récupérer les tokens ==="
for i in {1..30}; do
  echo "Tentative $i/30..."
  curl -sS "${BASE_URL}/device/token" \
    -d grant_type='urn:ietf:params:oauth:grant-type:device_code' \
    -d client_id="${CLIENT_ID}" \
    -d device_code="${DEVICE_CODE}" > token.json

  if jq -e '.access_token' token.json >/dev/null 2>&1; then
    echo "✅ Tokens obtenus !"
    cat token.json | jq .
    break
  else
    ERROR="$(jq -r .error token.json 2>/dev/null || echo 'unknown')"
    if [ "$ERROR" = "authorization_pending" ]; then
      echo "  Authorization pending... retry dans 2s"
      sleep 2
    else
      echo "❌ Erreur: $(cat token.json)"
      exit 1
    fi
  fi
done

ACCESS_TOKEN="$(jq -r .access_token token.json)"
REFRESH_TOKEN="$(jq -r .refresh_token token.json)"
USAGE_POINTS_ID="$(jq -r .usage_points_id token.json)"
USAGE_POINT_ID="$(echo "${USAGE_POINTS_ID}" | awk -F',' '{print $1}')"

echo ""
echo "ACCESS_TOKEN: ${ACCESS_TOKEN:0:20}..."
echo "REFRESH_TOKEN: ${REFRESH_TOKEN:0:20}..."
echo "USAGE_POINTS_ID: ${USAGE_POINTS_ID}"
echo "USAGE_POINT_ID: ${USAGE_POINT_ID}"
echo ""

# 4) Appel Data
echo "=== Étape 3: Appel Data (consommation journalière) ==="
START_DATE="2025-11-01"
END_DATE="2025-11-15"

curl -sS "${BASE_URL}/data/proxy/metering_data_v5/daily_consumption?usage_point_id=${USAGE_POINT_ID}&start=${START_DATE}&end=${END_DATE}" \
  -H "Authorization: Bearer ${ACCESS_TOKEN}" > data.json

cat data.json | jq .
echo ""

if jq -e '.meter_reading' data.json >/dev/null 2>&1; then
  echo "✅ Données récupérées avec succès !"
else
  echo "⚠️  Pas de meter_reading dans la réponse (vérifier les dates ou le PDL)"
fi

echo ""
echo "=== Fin du test ==="
