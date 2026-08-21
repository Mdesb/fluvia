#!/usr/bin/env bash
#
# Lance tous les garde-fous (C4). À exécuter depuis la racine du dépôt.
#
#   ./bin/garde-fous.sh                  # contrôle local
#   ./bin/garde-fous.sh origin/main      # contrôle avec cliquet contre une référence (CI)
#
# Ne s'arrête pas au premier échec : on veut la liste complète de ce qu'il y a à corriger,
# pas un défaut à la fois. Code de sortie 1 si au moins un garde-fou échoue.

set -uo pipefail

REFERENCE="${1:-}"
ECHECS=0
TOTAL=0

# PHP : binaire local s'il existe, sinon l'image du projet (le VPS n'a pas de PHP hors conteneur).
#
# Le montage conteneur n'est pas anodin. Dans un worktree git, `.git` est un *fichier* qui pointe vers
# le dépôt principal par chemin absolu ; monter seulement le worktree rend donc `git` inopérant à
# l'intérieur, et le cliquet `--contre` ne peut plus lire sa référence. On monte le répertoire courant
# à son chemin réel (et non sous /repo) et, s'il s'agit d'un worktree, le dépôt commun avec lui.
RACINE="$(pwd)"
SANS_PHP_LOCAL=0

if ! command -v php >/dev/null 2>&1; then
    SANS_PHP_LOCAL=1
    MONTAGES="-v $RACINE:$RACINE"
    if [ -f .git ]; then
        COMMUN="$(sed -n 's/^gitdir: //p' .git | sed 's#/worktrees/.*##')"
        [ -n "$COMMUN" ] && MONTAGES="$MONTAGES -v $COMMUN:$COMMUN"
    fi
    # Sans le nom de l'image : il doit rester le DERNIER argument de `docker run`, sinon les options
    # qui suivent (`-w`) sont passées à la commande du conteneur au lieu de docker.
    DOCKER_BASE="docker run --rm --network none -u $(id -u):$(id -g) $MONTAGES"
    IMAGE="billetterie-preprod-php"
fi

# Deux répertoires de travail : le garde-fou tourne depuis la racine (il lit app/src et bin/),
# phpunit depuis app/ (sa configuration y vit). D'où deux lanceurs plutôt qu'un `cd` global.
php_racine() {
    if [ "$SANS_PHP_LOCAL" -eq 1 ]; then
        $DOCKER_BASE -w "$RACINE" "$IMAGE" php "$@"
    else
        (cd "$RACINE" && php "$@")
    fi
}

php_app() {
    if [ "$SANS_PHP_LOCAL" -eq 1 ]; then
        $DOCKER_BASE -w "$RACINE/app" "$IMAGE" php "$@"
    else
        (cd "$RACINE/app" && php "$@")
    fi
}

executer() {
    local nom="$1"; shift
    TOTAL=$((TOTAL + 1))
    echo "─────────────────────────────────────────────────────────────"
    echo "▶ $nom"
    echo "─────────────────────────────────────────────────────────────"
    if "$@"; then
        return 0
    fi
    ECHECS=$((ECHECS + 1))
    return 1
}

# 1. Cloisonnement (D3/D8) — le garde-fou n°1.
if [ -n "$REFERENCE" ]; then
    executer "Cloisonnement (D3/D8)" php_racine bin/garde-fou-cloisonnement.php "--contre=$REFERENCE"
else
    executer "Cloisonnement (D3/D8)" php_racine bin/garde-fou-cloisonnement.php
fi

# 2. Manifeste (RG-PLAT-06) — déjà couvert côté noyau par ManifestCatalogueTest : on l'appelle,
#    on ne le réimplémente pas (consigne de l'intégrateur, MESSAGES.md du 19/08).
if [ -f app/vendor/bin/phpunit ]; then
    executer "Manifeste vs catalogue (RG-PLAT-06)" \
        php_app vendor/bin/phpunit --filter ManifestCatalogueTest
else
    echo "─────────────────────────────────────────────────────────────"
    echo "▶ Manifeste vs catalogue (RG-PLAT-06)"
    echo "─────────────────────────────────────────────────────────────"
    echo "IGNORÉ : app/vendor absent. Installe les dépendances de dev, sinon ce contrôle ne tourne pas."
    echo "         ./infra/test-stack.sh up <token>"
fi

# 3. Nommage anglais (D5) — uniquement sur les fichiers AJOUTÉS : l'existant est français et le
#    reste jusqu'au retrofit. Contrairement au n°1, celui-ci n'a pas eu besoin de ligne de base :
#    il ne trouve rien sur le neuf existant, donc il s'installe au vert.
executer "Nommage anglais (D5)" php_racine bin/garde-fou-nommage-anglais.php "--contre=${REFERENCE:-origin/main}"

# 4. Aucun secret cryptographique en valeur par défaut.
#    Contrairement au n°1, celui-ci n'a pas de ligne de base et n'en aura pas : une clé en dur n'est
#    pas une dette qu'on étale, c'est un secret publié. Il est ROUGE tant que
#    Facturation/Nf525/ScellementFactureHandler n'est pas passé à #[Autowire(env:)] — c'est voulu.
executer "Secrets en dur" php_racine bin/garde-fou-secrets.php

# 5. i18n : pas de chaîne d'UI en dur — SANS OBJET tant que la couche i18n n'existe pas (aucun
#    catalogue, aucun usage du traducteur dans app/src). Acté par l'intégrateur le 21/08.
# 6. CSRF — SANS OBJET : tous les pare-feux sont `stateless: true` et l'authentification est un JWT
#    en en-tête, qui n'est pas un identifiant ambiant. Acté par l'intégrateur le 21/08.

echo "─────────────────────────────────────────────────────────────"
if [ "$ECHECS" -gt 0 ]; then
    echo "✗ $ECHECS garde-fou(s) en échec sur $TOTAL."
    exit 1
fi
echo "✓ $TOTAL garde-fou(s) OK."
