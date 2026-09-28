#!/usr/bin/env bash
# Tests de bout en bout du proxy contre un faux serveur Enedis.
# Prérequis : Docker, Node >= 18.
# Usage : ./tests/e2e/run.sh            (démarre, teste, arrête)
#         KEEP=1 ./tests/e2e/run.sh     (laisse les conteneurs démarrés)
set -euo pipefail
cd "$(dirname "$0")"

COMPOSE="docker compose -f docker-compose.e2e.yml"

cleanup() {
  if [ "${KEEP:-0}" != "1" ]; then
    $COMPOSE down -v --remove-orphans >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT

$COMPOSE up -d --build --wait

status=0
node --test e2e.test.mjs || status=$?

if [ "$status" != "0" ]; then
  echo "---- logs proxy-auto ----"
  $COMPOSE logs --no-color --tail 80 proxy-auto || true
fi
exit "$status"
