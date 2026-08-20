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
if command -v php >/dev/null 2>&1; then
    PHP="php"
else
    PHP="docker run --rm --network none -u $(id -u):$(id -g) -v $(pwd):/repo -w /repo billetterie-preprod-php php"
fi

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
    executer "Cloisonnement (D3/D8)" $PHP bin/garde-fou-cloisonnement.php "--contre=$REFERENCE"
else
    executer "Cloisonnement (D3/D8)" $PHP bin/garde-fou-cloisonnement.php
fi

# 2. Manifeste (RG-PLAT-06) — déjà couvert côté noyau par ManifestCatalogueTest : on l'appelle,
#    on ne le réimplémente pas (consigne de l'intégrateur, MESSAGES.md du 19/08).
if [ -f app/vendor/bin/phpunit ]; then
    executer "Manifeste vs catalogue (RG-PLAT-06)" \
        bash -c 'cd app && vendor/bin/phpunit --filter ManifestCatalogueTest'
else
    echo "─────────────────────────────────────────────────────────────"
    echo "▶ Manifeste vs catalogue (RG-PLAT-06)"
    echo "─────────────────────────────────────────────────────────────"
    echo "IGNORÉ : app/vendor absent. Installe les dépendances de dev, sinon ce contrôle ne tourne pas."
    echo "         ./infra/test-stack.sh up <token>"
fi

# 3. Nommage anglais (D5)  — à venir
# 4. i18n : pas de chaîne d'UI en dur — à venir
# 5. CSRF / sécurité de base — à venir

echo "─────────────────────────────────────────────────────────────"
if [ "$ECHECS" -gt 0 ]; then
    echo "✗ $ECHECS garde-fou(s) en échec sur $TOTAL."
    exit 1
fi
echo "✓ $TOTAL garde-fou(s) OK."
