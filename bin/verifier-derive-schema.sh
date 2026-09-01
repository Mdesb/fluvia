#!/usr/bin/env bash
#
# Dérive du schéma — ce qu'une base bâtie par les migrations reproche au mapping (D32).
#
# ── POURQUOI CE SCRIPT PLUTÔT QU'UN GARDE-FOU ───────────────────────────────────────────────────
#
# `bin/garde-fou-defauts-mapping.php` compare des FICHIERS : le `DEFAULT` écrit dans une migration
# contre l'`options: ['default' => …]` du mapping. Ça marche, parce que les deux valeurs sont écrites
# noir sur blanc de part et d'autre.
#
# **Les index, non.** Le nom que Doctrine attend dépend de son propre algorithme et du mapping résolu,
# pas d'un texte. Mesuré le 01/09 : une base bâtie par les migrations propose **71 renommages**
# d'index ; la lecture statique la plus fine que j'aie su écrire en signalait **220**, dont 61
# seulement parmi les 71 réels. Un cliquet là-dessus aurait gelé cent cinquante non-défauts.
#
# La question « cet index dérive-t-il ? » se décide donc contre une **base**. Ce script la pose.
#
# ── CE QU'IL FAIT, ET CE QU'IL COÛTE ────────────────────────────────────────────────────────────
#
# Il monte une pile, vide la base, la rebâtit **par les migrations** — et non par le mapping, sinon
# elle correspondrait par construction et ne dirait rien — puis demande à Doctrine ce qu'il changerait.
# Il démonte la pile ensuite, toujours, y compris en cas d'erreur.
#
# Ce n'est pas un garde-fou : trop lent pour une poussée, et il lui faut une base. On le lance quand on
# veut savoir, pas à chaque commit.
#
# Usage :
#   bin/verifier-derive-schema.sh              # jeton « deriveC » par défaut
#   bin/verifier-derive-schema.sh monJeton     # pour ne pas marcher sur la pile d'un autre
#   bin/verifier-derive-schema.sh monJeton --garder   # laisse la pile montée pour inspecter

set -uo pipefail

RACINE="$(cd "$(dirname "$0")/.." && pwd)"
JETON="${1:-deriveC}"
GARDER=0
for a in "$@"; do [ "$a" = "--garder" ] && GARDER=1; done

RESEAU="${JETON}-net"
BASE="app_test${JETON}"
IMAGE="${PHP_IMAGE:-billetterie-preprod-php}"
URL="mysql://app:app@db:3306/${BASE}?serverVersion=11.4.2-MariaDB&charset=utf8mb4"

demonter() {
    if [ "$GARDER" -eq 1 ]; then
        echo "· Pile « $JETON » laissée montée (--garder). Démonte-la : infra/test-stack.sh down $JETON"
        return
    fi
    echo "· Démontage de la pile « $JETON »…"
    bash "$RACINE/infra/test-stack.sh" down "$JETON" >/dev/null 2>&1
}
trap demonter EXIT

console() {
    docker run --rm --network "$RESEAU" -u "$(id -u):$(id -g)" \
        -e "DATABASE_URL=$URL" -v "$RACINE:/repo" -w /repo/app \
        "$IMAGE" php bin/console "$@" 2>&1
}

# --- Le pool avant tout : monter une pile de plus quand il en reste trois est le meilleur moyen de
#     bloquer neuf sessions.
RESTANTS=$(( 31 - $(docker network ls -q | wc -l) ))
if [ "$RESTANTS" -lt 3 ]; then
    echo "✗ Il ne reste que ~$RESTANTS réseaux Docker sur 31 — je ne monte pas de pile de plus."
    echo "  Ramasse les piles oubliées : bin/ramasser-piles-test.sh --age=8"
    exit 2
fi

echo "── Pile « $JETON »"
bash "$RACINE/infra/test-stack.sh" up "$JETON" >/dev/null 2>&1 || { echo "✗ Montage impossible."; exit 1; }

# ⚠ `schema:drop` ne supprime PAS les séquences : une migration qui en crée une échoue ensuite sur
#    « already exists ». On repart donc de la base elle-même.
echo "── Base vierge"
docker exec "${JETON}-db" mariadb -uroot -proot \
    -e "DROP DATABASE IF EXISTS ${BASE}; CREATE DATABASE ${BASE} CHARACTER SET utf8mb4;" >/dev/null 2>&1

echo "── Reconstruction par les migrations"
MIGRATION="$(console doctrine:migrations:migrate --no-interaction)"
if ! printf %s "$MIGRATION" | grep -q "Successfully migrated"; then
    echo "✗ Les migrations n'ont pas abouti — la mesure n'aurait aucun sens."
    echo "  Voici pourquoi, plutôt que de te laisser deviner :"
    printf %s "$MIGRATION" | tail -8 | sed "s/^/    /"
    exit 1
fi

echo "── Ce que Doctrine reprocherait à cette base"
DIFF="$(console doctrine:schema:update --dump-sql --complete)"

renommages=$(printf '%s\n' "$DIFF" | grep -c "RENAME INDEX" || true)
suppressions=$(printf '%s\n' "$DIFF" | grep -cE "DROP (INDEX|FOREIGN KEY|COLUMN)" || true)
changements=$(printf '%s\n' "$DIFF" | grep -oE "CHANGE [a-z_]+ " | wc -l)
total=$(printf '%s\n' "$DIFF" | grep -c ";" || true)

echo
printf "  %-34s %s\n" "renommages d'index" "$renommages"
printf "  %-34s %s\n" "suppressions" "$suppressions"
printf "  %-34s %s\n" "changements de colonne" "$changements"
printf "  %-34s %s\n" "total d'instructions" "$total"
echo

if [ "$renommages" -gt 0 ]; then
    echo "  Index nommés à la main que le mapping ne déclare pas — chacun ressort dans le diff de"
    echo "  TOUTE session qui régénère une migration, indéfiniment (D32) :"
    echo
    printf '%s\n' "$DIFF" | grep -oE "RENAME INDEX [a-zA-Z_0-9]+" | sed 's/RENAME INDEX /    /' | sort -u | head -20
    reste=$(( renommages - $(printf '%s\n' "$DIFF" | grep -oE "RENAME INDEX [a-zA-Z_0-9]+" | sort -u | wc -l) ))
    [ "$reste" -gt 0 ] && echo "    … et $reste occurrence(s) répétée(s)"
    echo
    echo "  Le correctif est une ligne par index, sur l'entité :"
    echo "      #[ORM\\Index(name: 'idx_ma_table_ma_colonne', fields: ['maColonne'])]"
fi

if [ "$total" -eq 0 ]; then
    echo "  Aucune dérive : la base bâtie par les migrations correspond au mapping."
fi

exit 0
