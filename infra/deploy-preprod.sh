#!/usr/bin/env bash
#
# Déploiement / mise à jour de la préprod.
# À lancer depuis la racine du dépôt sur le VPS, avec l'utilisateur applicatif :
#
#   ./infra/deploy-preprod.sh
#
set -euo pipefail

cd "$(dirname "$0")/.."
REPO_ROOT="$(pwd)"
COMPOSE=(docker compose -f infra/compose.preprod.yaml --env-file infra/.env.preprod)

log() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }

[[ -f infra/.env.preprod ]] || {
    echo "infra/.env.preprod introuvable. Copie infra/env.preprod.example et remplis les secrets."
    exit 1
}

log "Récupération du code"
git pull --ff-only

log "Construction / démarrage des conteneurs"
"${COMPOSE[@]}" build
"${COMPOSE[@]}" up -d

log "Dépendances Composer (sans les paquets de dev)"
"${COMPOSE[@]}" exec -T php composer install --no-dev --optimize-autoloader --no-interaction

log "Migrations de base"
"${COMPOSE[@]}" exec -T php php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

log "Préchauffage du cache Symfony"
"${COMPOSE[@]}" exec -T php php bin/console cache:clear --env=prod --no-debug
"${COMPOSE[@]}" exec -T php php bin/console cache:warmup --env=prod --no-debug

# Composer et cache:warmup tournent en root dans le conteneur, alors que les
# workers FPM tournent en www-data : sans ce chown, l'app ne peut plus écrire ses
# logs ni son cache et renvoie des 500.
log "Droits sur var/"
"${COMPOSE[@]}" exec -T php chown -R www-data:www-data /app/var

# opcache tourne avec validate_timestamps=0 (cf. docker/php/conf.d/zz-opcache.ini) :
# sans redémarrage du master FPM, le code servi resterait celui d'avant le déploiement.
log "Redémarrage de PHP-FPM (opcache)"
"${COMPOSE[@]}" restart php

log "État de la stack"
"${COMPOSE[@]}" ps

log "Déploiement terminé - $REPO_ROOT"
