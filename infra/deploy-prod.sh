#!/usr/bin/env bash
#
# Déploiement / mise à jour de la PRODUCTION — conteneur LXD chez IT Cotation (Fabrice).
# À lancer depuis la racine de l'arbre de production (/srv/fluvia/app), dans le conteneur :
#
#   ./infra/deploy-prod.sh
#
# Dérivé de infra/deploy-preprod.sh. Les contrôles sont les mêmes ; leurs raisons, datées, sont
# écrites là-bas et ne sont pas recopiées ici. Ce qui diffère est commenté sur place :
#   - le code arrive par un dépôt nu LOCAL, pas par GitHub (aucun accès GitHub depuis ce serveur) ;
#   - les secrets sont TOUS générés au premier déploiement, y compris ceux de la base ;
#   - pas de Nginx sur l'hôte : le frontend est servi par le service `front` (port 80 du conteneur) ;
#   - rien de ce qui concerne les suites de tests : il n'y en a pas ici.
#
set -euo pipefail

cd "$(dirname "$0")/.."
REPO_ROOT="$(pwd)"
ENV_FILE="infra/.env.prod"
# Monté en lecture seule par le service `front` : EXPORTE, pour que compose et ce script parlent du
# même répertoire. Un WEB_ROOT différent des deux côtés publierait le frontend là où personne ne lit.
export WEB_ROOT="${WEB_ROOT:-/srv/fluvia/www}"
export BACKUP_DIR="${BACKUP_DIR:-/srv/fluvia/sauvegardes}"
URL_PUBLIQUE="${URL_PUBLIQUE:-https://portal.fluvia-app.com}"
COMPOSE=(docker compose -f infra/compose.prod.yaml --env-file "$ENV_FILE")
# Lu par infra/verifier-droits-var.sh, qui vise la préprod par défaut.
export FLUVIA_COMPOSE_FILE="infra/compose.prod.yaml"
export FLUVIA_ENV_FILE="$ENV_FILE"

log() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }

# ── ON NE SERT QUE CE QUI EST DANS `main` — DU DEPOT LOCAL ─────────────────────────────────────
#
# En préprod, la référence est `origin/main` sur GitHub. Ce serveur n'a PAS accès à GitHub : le code
# y est poussé dans un dépôt nu local (/srv/fluvia/fluvia.git), et cet arbre en est un clone. Son
# `origin` EST ce dépôt nu. La règle ne change donc pas — « ce qui est servi est lisible dans main » —
# seule la main qui la tient change : c'est le `main` du dépôt nu, celui qu'on a poussé.
#
# ⚠ ET L'ARBRE DOIT ETRE PROPRE. En préprod, l'arbre est un point d'intégration et le contrôle
# d'égalité de HEAD suffit à attraper un commit local. Ici une modification NON COMMITEE — un
# correctif tapé à chaud dans le conteneur — serait servie avec HEAD == main : le contrôle passerait,
# et le code en ligne ne serait lisible nulle part. Les fichiers ignorés (app/.env.local) ne comptent
# pas : ils sont la configuration de cette installation, pas du code.
log "Vérification : ce qui va être servi est-il le main du dépôt local ?"
if ! git fetch origin --quiet; then
    echo
    echo "✗ Déploiement refusé : impossible de lire le dépôt local (origin)."
    echo "    origin = $(git remote get-url origin 2>/dev/null || echo '<non défini>')"
    echo "  → l'arbre doit être un clone de /srv/fluvia/fluvia.git (voir infra/README.md)."
    exit 1
fi

if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
    echo
    echo "✗ Déploiement refusé : l'arbre porte des modifications non commitées."
    git status --short --untracked-files=no | sed 's/^/    /'
    echo
    echo "  Servies, elles ne seraient lisibles dans aucune branche. Commitez-les ailleurs,"
    echo "  poussez-les dans main, puis :   git checkout -- . && git pull --ff-only"
    exit 1
fi

TETE_LOCALE="$(git rev-parse HEAD)"
TETE_MAIN="$(git rev-parse origin/main)"

if [ "$TETE_LOCALE" != "$TETE_MAIN" ]; then
    AVANCE="$(git rev-list --count origin/main..HEAD)"
    RETARD="$(git rev-list --count HEAD..origin/main)"
    echo
    echo "✗ Déploiement refusé : cet arbre n'est pas le main du dépôt local."
    echo "    ici et pas dans main : $AVANCE commit(s)"
    echo "    dans main et pas ici : $RETARD commit(s)"
    echo
    [ "$RETARD" != "0" ] && echo "  → récupérer ce qui a été poussé :   git pull --ff-only origin main"
    [ "$AVANCE" != "0" ] && echo "  → un commit fait ICI n'a rien à faire en production : il doit passer par main."
    exit 1
fi

# ── LES SECRETS, GENERES UNE FOIS, JAMAIS REGENERES ─────────────────────────────────────────────
#
# Même règle que la préprod (une clé par installation, jamais régénérée : la changer rend
# indéchiffrable ce qui a été chiffré avant). Deux différences :
#
# 1. LA BASE AUSSI. En préprod, APP_SECRET, les mots de passe de la base et la phrase JWT ont été
#    saisis à la main une fois. Ici on part d'une installation vierge, et aucun humain n'a à les
#    connaître : ils naissent dans ce fichier et n'en sortent pas. RIEN N'EST AFFICHE, seul le NOM
#    de ce qui vient d'être créé. En hexadécimal pour ceux qui finissent dans une URL
#    (DATABASE_URL) : un `/` ou un `+` de base64 y casserait l'analyse sans un mot d'erreur clair.
#
# 2. ON REMPLACE LA LIGNE VIDE, ON N'AJOUTE PAS UNE SECONDE LIGNE. La boucle de la préprod ajoute
#    `CLE=valeur` en fin de fichier quand `CLE=` existe déjà vide (copie de l'exemple) : le fichier
#    porte alors deux lignes pour la même clé, et tout repose sur « la dernière gagne ».
#
# ⚠ SIGNATURE_SEAL_KEY est dans la liste. Elle MANQUE à la boucle de deploy-preprod.sh alors que
# compose l'exige (`:?`) : sur une installation neuve, la préprod refuserait de démarrer.
log "Secrets (génération au premier déploiement)"
if [ ! -f "$ENV_FILE" ]; then
    ( umask 077 && cp infra/env.prod.example "$ENV_FILE" )
    echo "  + $ENV_FILE (créé depuis infra/env.prod.example)"
fi
chmod 600 "$ENV_FILE"

poser() { # $1 = nom, $2 = valeur ; n'écrit que si la clé est absente ou vide
    if grep -q "^$1=." "$ENV_FILE"; then
        return 0
    fi
    local tmp
    tmp="$(mktemp)"
    grep -v "^$1=" "$ENV_FILE" > "$tmp" || true
    printf '%s=%s\n' "$1" "$2" >> "$tmp"
    cat "$tmp" > "$ENV_FILE"   # `cat >` et non `mv` : le fichier garde ses droits (600)
    rm -f "$tmp"
    echo "  + $1 (absente, générée)"
}

for cle in APP_SECRET DB_PASSWORD DB_ROOT_PASSWORD JWT_PASSPHRASE; do
    poser "$cle" "$(openssl rand -hex 32)"
done
# 32 octets base64 : ces valeurs passent par `base64_decode()` (RG-DMS-25), voir la préprod.
for cle in DMS_ENCRYPTION_KEY MFA_ENCRYPTION_KEY SEPA_IBAN_KEY OCR_API_KEY_ENCRYPTION_KEY SOCIAL_TOKEN_ENCRYPTION_KEY INTEGRATIONS_WEBHOOK_KEY NF525_SEAL_KEY NF525_COMPTA_SEAL_KEY NF525_FACTURATION_SEAL_KEY SUPPORT_HMAC_KEY SEPA_IBAN_HMAC_KEY PUBLIC_API_SUPPORT_REF_KEY PARTNER_WEBHOOK_KEY SIGNATURE_SEAL_KEY; do
    poser "$cle" "$(openssl rand -base64 32)"
done

mkdir -p "$WEB_ROOT" "$BACKUP_DIR"

log "Construction / démarrage des conteneurs"
"${COMPOSE[@]}" build
"${COMPOSE[@]}" up -d

log "Dépendances Composer (sans les paquets de dev)"
"${COMPOSE[@]}" exec -T php composer install --no-dev --optimize-autoloader --no-interaction

# Les clés JWT ne sont pas versionnées : génération au premier déploiement uniquement — les
# regénérer invaliderait tous les jetons émis.
if ! "${COMPOSE[@]}" exec -T php test -f config/jwt/private.pem; then
    log "Génération des clés JWT (premier déploiement)"
    "${COMPOSE[@]}" exec -T php php bin/console lexik:jwt:generate-keypair --no-interaction
    "${COMPOSE[@]}" exec -T php chown -R www-data:www-data config/jwt
    "${COMPOSE[@]}" exec -T php chmod 640 config/jwt/private.pem config/jwt/public.pem
fi

log "Migrations de base"
"${COMPOSE[@]}" exec -T php php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

# Mêmes semis que la préprod, idempotents et qui ne réécrivent jamais une ligne existante (raisons
# dans deploy-preprod.sh) : sans eux, l'aide et le site partent vides en annonçant un succès.
log "Base de connaissance (doc vivante -> articles d'aide)"
"${COMPOSE[@]}" exec -T php php bin/console support:importer-aide --no-interaction
log "Referentiel des metiers"
"${COMPOSE[@]}" exec -T php php bin/console website:trades:seed --no-interaction
log "Contenus du site (blocs editables)"
"${COMPOSE[@]}" exec -T php php bin/console website:blocks:seed --no-interaction

log "Préchauffage du cache Symfony"
"${COMPOSE[@]}" exec -T php php bin/console cache:clear --env=prod --no-debug
"${COMPOSE[@]}" exec -T php php bin/console messenger:stop-workers --env=prod --no-debug
"${COMPOSE[@]}" exec -T php php bin/console cache:warmup --env=prod --no-debug

# Composer et cache:warmup tournent en root : sans ce chown, l'API rend des 500 (01/09 en préprod).
log "Droits sur var/"
"${COMPOSE[@]}" exec -T php chown -R www-data:www-data /app/var
# ⚠ ET ON VERIFIE QU'IL A FAIT CE QU'IL ANNONCE (FLUVIA_COMPOSE_FILE l'oriente vers la prod).
./infra/verifier-droits-var.sh

# Écrit DANS le conteneur, avant le redémarrage : FPM le chargera avec le code (voir la préprod).
log "Marqueur de version pour PHP"
"${COMPOSE[@]}" exec -T \
    -e MARQUEUR_COMMIT="$(git rev-parse --short HEAD)" \
    -e MARQUEUR_ECRIT="$(date -Is)" \
    php sh -c 'mkdir -p /app/var && printf "<?php\n\nreturn [\"commit\" => \"%s\", \"ecrit\" => \"%s\"];\n" "$MARQUEUR_COMMIT" "$MARQUEUR_ECRIT" > /app/var/build-version.php'

log "Redémarrage de PHP-FPM (opcache)"
"${COMPOSE[@]}" restart php

# Construit dans un conteneur jetable : pas de Node.js à maintenir dans le conteneur LXD.
# ⚠ Il s'ajoute à la stack pendant sa durée : c'est lui qui consomme la marge du plafond de 6 Go
# (voir « BUDGET MEMOIRE » dans compose.prod.yaml).
log "Construction du frontend (React / Vite)"
docker run --rm \
    -v "$REPO_ROOT/frontend":/app -w /app \
    -u "$(id -u):$(id -g)" \
    -e HOME=/tmp -e npm_config_cache=/tmp/.npm \
    node:20-alpine sh -c 'npm ci --no-audit --no-fund && npm run build'

log "Service worker : nom de cache au commit"
sed -i "s/fluvia-__COMMIT__/fluvia-$(git rev-parse --short HEAD)/" frontend/dist/sw.js
if grep -q '__COMMIT__' frontend/dist/sw.js; then
    echo "✗ Le jeton de version du service worker n'a pas été substitué." >&2
    exit 1
fi
if ! grep -q "fluvia-$(git rev-parse --short HEAD)" frontend/dist/sw.js; then
    echo "✗ Le service worker ne porte pas le commit courant après substitution." >&2
    exit 1
fi

log "Publication du frontend"
rsync -a --delete frontend/dist/ "$WEB_ROOT/"

# Publié APRES le rsync : `--delete` efface tout ce qui n'est pas le front.
log "Publication du marqueur de version"
COMMIT_DEPLOYE="$(git rev-parse --short HEAD)"
printf '{"commit":"%s","branche":"%s","construit":"%s","sujet":"%s"}\n' \
    "$COMMIT_DEPLOYE" \
    "$(git rev-parse --abbrev-ref HEAD)" \
    "$(date -Is)" \
    "$(git log -1 --format=%s | tr -d '"' | cut -c1-120)" \
    > "$WEB_ROOT/version.json"

# ── LE CONTRAT DE FABRICE, D'ABORD EN LOCAL ─────────────────────────────────────────────────────
#
# Sa liste de contrôle : `curl -I http://127.0.0.1/` -> 200 dans le conteneur. On le fait avant la
# boucle publique : si ceci échoue, le défaut est ICI (front, web, php) ; si ceci passe et la boucle
# publique échoue, il est en amont (proxy, DNS, certificat) — et c'est Fabrice qu'on appelle.
log "Contrat réseau : le port 80 du conteneur répond-il ?"
CODE_LOCAL="$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1/ || true)"
if [ "$CODE_LOCAL" != "200" ]; then
    echo "✗ http://127.0.0.1/ rend « ${CODE_LOCAL:-rien} » au lieu de 200 : le service front ne sert pas."
    echo "    ${COMPOSE[*]} logs --tail 50 front"
    exit 1
fi
echo "  ✓ http://127.0.0.1/ -> 200"

# ── LES DEUX BOUCLES, COMME EN PREPROD ──────────────────────────────────────────────────────────
#
# On relit l'URL PUBLIQUE et l'on exige d'y retrouver le commit construit (frontal), puis on le
# redemande à PHP (serveur). Raisons et historique : deploy-preprod.sh.
#
# ⚠ ICI LA LECTURE SORT DU CONTENEUR ET Y REVIENT par le proxy de Fabrice. Un échec peut donc venir
# de l'amont même quand tout ce qui est ici est juste — le contrôle local ci-dessus permet de le dire.
log "Boucle : l'URL publique rend-elle ce qu'on vient de construire ?"
SERVI="$(curl -sf -H 'Accept: application/json' "$URL_PUBLIQUE/version.json" 2>/dev/null \
    | sed -n 's/.*"commit":"\([^"]*\)".*/\1/p' || true)"

TENTATIVES_CHARGE=10
CHARGE=""
for _ in $(seq 1 "$TENTATIVES_CHARGE"); do
    CHARGE="$(curl -sf -H 'Accept: application/json' "$URL_PUBLIQUE/api/plateforme/version-chargee" 2>/dev/null \
        | sed -n 's/.*"commit":"\([^"]*\)".*/\1/p' || true)"
    [ -n "$CHARGE" ] && break
    sleep 2
done

if [ "$CHARGE" = "$COMMIT_DEPLOYE" ] && [ "$SERVI" = "$COMMIT_DEPLOYE" ]; then
    echo "  ✓ les deux boucles ont bouclé sur $COMMIT_DEPLOYE"
    echo "      servi par $URL_PUBLIQUE : $SERVI"
    echo "      chargé par PHP          : $CHARGE"
fi

if [ "$CHARGE" != "$COMMIT_DEPLOYE" ]; then
    echo
    echo "✗ PHP ne sert pas le code qu'on vient de déployer."
    echo "    déployé ici          : $COMMIT_DEPLOYE"
    echo "    chargé par PHP       : ${CHARGE:-<rien ou illisible>}"
    echo
    echo "      docker compose -p fluvia-prod restart php"
    echo
fi

if [ "$SERVI" != "$COMMIT_DEPLOYE" ]; then
    echo
    echo "✗ La boucle n'a pas bouclé."
    echo "    construit ici : $COMMIT_DEPLOYE"
    echo "    servi par $URL_PUBLIQUE : ${SERVI:-<rien ou illisible>}"
    echo
    echo "  Le port 80 local répond (contrôle ci-dessus) : le défaut est EN AMONT."
    echo "    · le proxy de l'hôte ne route pas encore ce domaine vers ce conteneur ;"
    echo "    · un cache devant ;"
    echo "    · le conteneur ne peut pas joindre sa propre URL publique (retour par l'IP publique)."
    echo "  → à voir avec Fabrice. Le marqueur reste SANS le champ « boucle »."
    exit 1
fi

printf '{"commit":"%s","branche":"%s","construit":"%s","boucle":"%s","sujet":"%s"}\n' \
    "$COMMIT_DEPLOYE" \
    "$(git rev-parse --abbrev-ref HEAD)" \
    "$(date -Is)" \
    "$URL_PUBLIQUE" \
    "$(git log -1 --format=%s | tr -d '"' | cut -c1-120)" \
    > "$WEB_ROOT/version.json"

# ── LE COURRIEL PART-IL VRAIMENT ? ──────────────────────────────────────────────────────────────
#
# Décision de Maxime (28/10) : pas de transport pour l'instant, MAILER_DSN=null://null. On le DIT à
# chaque déploiement : en production, c'est un client qui ne reçoit pas sa confirmation de commande.
DSN="$("${COMPOSE[@]}" exec -T php php bin/console debug:dotenv MAILER_DSN 2>/dev/null | grep -oE 'null://null|smtp://[^ ]*|sendmail://[^ ]*' | head -1)"
if [ "$DSN" = "null://null" ] || [ -z "$DSN" ]; then
    printf '\n\033[1;33m⚠ AUCUN COURRIEL NE PARTIRA DE LA PRODUCTION.\033[0m\n'
    echo "  MAILER_DSN vaut « null://null » : le transport nul accepte tout et n'envoie rien."
    echo "  Concerne : mot de passe oublie, invitation d'utilisateur, liste d'attente,"
    echo "  confirmation de commande, relance de panier, rapport planifie."
fi

# Les liens des courriels viennent de FRONT_BASE_URL, pas de `Host` (voir la préprod).
FRONT_URL="$("${COMPOSE[@]}" exec -T php php bin/console debug:dotenv FRONT_BASE_URL 2>/dev/null | grep -oE 'https?://[^ ]+' | head -1)"
case "${FRONT_URL:-}" in
    "$URL_PUBLIQUE") ;;
    *)
        printf '\n\033[1;33m⚠ LES LIENS ENVOYÉS PAR COURRIEL NE POINTENT PAS VERS LA PRODUCTION.\033[0m\n'
        echo "  FRONT_BASE_URL vaut « ${FRONT_URL:-<non définie>} », attendu « $URL_PUBLIQUE »."
        echo "  À poser dans app/.env.local AVANT de brancher un transport de courriel."
        ;;
esac

# ── LES TACHES PLANIFIEES TOURNENT-ELLES ? ──────────────────────────────────────────────────────
#
# ⚠ ON CHERCHE `scheduler`, ET LUI SEUL. La préprod cherche `worker|scheduler` : le conteneur des
# webhooks partenaires s'appelle `worker` et tourne TOUJOURS, donc l'avertissement ne peut pas
# sortir, même ordonnanceur arrêté. Ici l'ordonnanceur n'est pas démarré au premier déploiement
# (profil) : ce contrôle doit pouvoir le dire.
if ! docker ps --format '{{.Names}}' 2>/dev/null | grep -q -- '-scheduler-'; then
    printf '\n\033[1;33m⚠ L ORDONNANCEUR NE TOURNE PAS.\033[0m\n'
    echo "  Aucune tâche planifiée ne s exécute (préavis SEPA, paniers expirés, RGPD...)."
    echo "  Il se démarre à la main, c est un effet (voir compose.prod.yaml) :"
    echo "    ${COMPOSE[*]} --profile ordonnanceur up -d scheduler"
fi

log "État de la stack"
"${COMPOSE[@]}" ps

log "Déploiement terminé - $REPO_ROOT"
