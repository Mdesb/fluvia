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

# ── CE QUE LA PREPROD SERT, LISIBLE EN UNE REQUETE ──────────────────────────────────────────────
#
# Entre une fusion et le deploiement suivant, la preprod montre l'etat precedent -- la suite de
# tests dure cinquante minutes. Quiconque la lit pendant cette fenetre juge du travail deja livre
# sur un build qui ne le contient pas, et n'a aucun moyen de s'en apercevoir.
#
# Le 30/08 cela a coute une enquete complete a une session pour conclure qu'un bouton etait absent
# du BUILD et non du code. Avec ce fichier, la question se pose en une requete :
#
#     curl -s https://smartaccess.hector-conseil.com/version.json
#
# ⚠ PUBLIE APRES LE RSYNC, comme le tableau de bord : `--delete` efface tout ce qui n'est pas le
# front, et un marqueur efface serait pire qu'absent -- il aurait existe une fois.
log "Publication du marqueur de version"
printf '{"commit":"%s","branche":"%s","construit":"%s","sujet":"%s"}\n' \
    "$(git rev-parse --short HEAD)" \
    "$(git rev-parse --abbrev-ref HEAD)" \
    "$(date -Is)" \
    "$(git log -1 --format=%s | tr -d '"' | cut -c1-120)" \
    > "$WEB_ROOT/version.json"

# ── LE COURRIEL PART-IL VRAIMENT ? ──────────────────────────────────────────────────────────────
#
# `MAILER_DSN=null://null` avale tout en silence : les six expediteurs du depot s'executent, ne
# levent rien, et aucun message ne part. Un correctif de notification se lira alors comme un
# correctif qui ne marche pas, alors que c'est le transport qui parle.
#
# On ne configure rien ici -- le choix du prestataire appartient a l'exploitant, et un envoi reel
# depuis une preproduction ecrirait a de vraies personnes. On le DIT, c'est tout.
DSN="$("${COMPOSE[@]}" exec -T php php bin/console debug:dotenv MAILER_DSN 2>/dev/null | grep -oE 'null://null|smtp://[^ ]*|sendmail://[^ ]*' | head -1)"
if [ "$DSN" = "null://null" ] || [ -z "$DSN" ]; then
    printf '\n\033[1;33m⚠ AUCUN COURRIEL NE PARTIRA DE CETTE INSTANCE.\033[0m\n'
    echo "  MAILER_DSN vaut « null://null » : le transport nul accepte tout et n'envoie rien."
    echo "  Concerne : mot de passe oublie, invitation d'utilisateur, liste d'attente,"
    echo "  confirmation de commande, relance de panier, rapport planifie."
    echo "  Une invitation qui ne part pas laisse le compte « invite » indefiniment."
fi

# ── LES TACHES PLANIFIEES TOURNENT-ELLES ? ──────────────────────────────────────────────────────
#
# Ni crontab, ni timer systemd, ni conteneur worker ne lance `platform:scheduled-tasks:run` sur cette
# machine. Vingt-et-une taches sont declarees et aucune ne s'execute.
#
# Toutes ne sont pas critiques -- certaines ne font que marquer un enregistrement dont l'effet est
# deja calcule a la lecture. Mais `sepa:preavis:annoncer` est le SEUL emetteur de preavis de
# prelevement, et `GenerationRemiseHandler` exclut de la remise toute echeance non couverte par un
# preavis delivre. Sans ordonnanceur, aucun prelevement ne peut partir.
#
# On ne demarre rien ici : vingt-et-une taches qui rattrapent des semaines d'arriere d'un coup
# meritent qu'on sache d'abord ce qu'elles feraient. On le DIT, c'est tout.
TACHES_LANCEES=0
command -v crontab >/dev/null 2>&1 && crontab -l 2>/dev/null | grep -q 'scheduled-tasks' && TACHES_LANCEES=1
systemctl list-timers --all 2>/dev/null | grep -q 'fluvia\|billetterie' && TACHES_LANCEES=1
docker ps --format '{{.Names}}' 2>/dev/null | grep -qiE 'worker|scheduler' && TACHES_LANCEES=1

if [ "$TACHES_LANCEES" = "0" ]; then
    printf '\n\033[1;33m⚠ AUCUNE TÂCHE PLANIFIÉE NE S EXÉCUTE SUR CETTE MACHINE.\033[0m\n'
    echo "  Ni cron, ni timer systemd, ni conteneur worker ne lance l ordonnanceur."
    echo "  Conséquence mesurée : sepa:preavis:annoncer est le seul émetteur de préavis de"
    echo "  prélèvement, et une échéance sans préavis délivré est EXCLUE de la remise."
    echo "  Aucun prélèvement ne peut donc partir — le système refuse de débiter sans prévenir."
    echo "  ⚠ Démarrer l ordonnanceur ne suffira pas : sans transport de courriel, le préavis"
    echo "  sort en « journalisé » et l échéance reste exclue. Le transport d abord."
fi

log "État de la stack"
"${COMPOSE[@]}" ps

log "Déploiement terminé - $REPO_ROOT"
