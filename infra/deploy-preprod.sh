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
WEB_ROOT="${WEB_ROOT:-/var/www/smartaccess}"   # racine servie par le Nginx de l'hôte
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

# Les clés JWT ne sont pas versionnées (et ne doivent pas l'être) : elles
# n'arrivent donc jamais par git sur un serveur neuf. Sans elles, toute
# connexion echoue en 500 (JWTEncodeFailureException). Génération au premier
# déploiement uniquement — les regénérer invaliderait tous les jetons émis.
if ! "${COMPOSE[@]}" exec -T php test -f config/jwt/private.pem; then
    log "Génération des clés JWT (premier déploiement)"
    "${COMPOSE[@]}" exec -T php php bin/console lexik:jwt:generate-keypair --no-interaction
    # Les workers FPM tournent en www-data et doivent pouvoir lire les clés.
    "${COMPOSE[@]}" exec -T php chown -R www-data:www-data config/jwt
    "${COMPOSE[@]}" exec -T php chmod 640 config/jwt/private.pem config/jwt/public.pem
fi

log "Migrations de base"
"${COMPOSE[@]}" exec -T php php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

# LA BASE DE CONNAISSANCE GENERIQUE, POSEE A CHAQUE DEPLOIEMENT.
#
# Les dix-huit articles de `app/docs/aide/**` sont tous en portee GLOBALE : ils ne sont donc pas la
# documentation d'un client, ils sont celle du produit, et tout etablissement les voit. Sans cette
# ligne ils ne quittaient jamais le depot — la commande existait, l'ecran d'assistance existait, et
# un exploitant qui ouvrait « Base de connaissances » trouvait une liste vide.
#
# `--strict` volontairement ABSENT : un article de doc mal forme ne doit pas faire echouer un
# deploiement. Le resume imprime dit ce qui est passe et ce qui ne l'est pas ; le reste de la mise
# en ligne continue.
log "Base de connaissance (doc vivante -> articles d'aide)"
"${COMPOSE[@]}" exec -T php php bin/console support:importer-aide --no-interaction

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

# Le frontend est construit dans un conteneur jetable : pas de Node.js à
# installer ni à maintenir sur l'hôte, et la version du builder est figée.
# -u évite que node_modules/ et dist/ appartiennent à root.
log "Construction du frontend (React / Vite)"
docker run --rm \
    -v "$REPO_ROOT/frontend":/app -w /app \
    -u "$(id -u):$(id -g)" \
    -e HOME=/tmp -e npm_config_cache=/tmp/.npm \
    node:20-alpine sh -c 'npm ci --no-audit --no-fund && npm run build'

log "Publication du frontend"
rsync -a --delete frontend/dist/ "$WEB_ROOT/"

# Le tableau de bord d'avancement, que Maxime consulte. Il est publie APRES le rsync ci-dessus, et
# c'est la raison d'etre de ces trois lignes : `--delete` efface tout ce qui n'est pas le front.
#
# Il avait ete publie une fois, puis efface par le deploiement suivant. Personne ne l'a vu partir --
# une page qui disparait ne previent pas, contrairement a une page qui casse. Neuf jours plus tard,
# l'URL que Maxime « gardait precieusement » ne servait plus rien.
if [ -f avancement-dev.html ]; then
    log "Publication du tableau de bord"
    cp avancement-dev.html "$WEB_ROOT/avancement-dev.html"
fi

log "État de la stack"
"${COMPOSE[@]}" ps

log "Déploiement terminé - $REPO_ROOT"
