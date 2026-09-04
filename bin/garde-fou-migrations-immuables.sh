#!/bin/sh
# UNE MIGRATION DÉJÀ PARTIE NE SE RÉÉCRIT PAS — garde-fou n°50.
#
# ── L'INCIDENT QUI L'A FAIT ÉCRIRE, LE 04/09 ────────────────────────────────────────────────────
#
# Un pair avait créé `Version20260904160000.php` (administration du site vitrine :
# `website_blog_post`, `website_blog_category`). Une heure plus tard, j'ai posé une migration à moi
# en choisissant un nom à la main — le même — et un `cp` l'a ÉCRASÉE en silence.
#
# ⚠ RIEN N'A PRÉVENU. `cp` ne dit rien ; `git add -A` a mis l'écrasement en scène comme une simple
# MODIFICATION ; la fusion n'a produit aucun conflit, parce que du point de vue de git un fichier
# existant avait changé, ce qui est banal.
#
# Deux dégâts, opposés en symptôme :
#
#   · la migration du pair a perdu son TEXTE. Elle avait déjà tourné, donc ses tables existent en
#     préproduction — mais une base neuve ne les aurait jamais créées. Le schéma appliqué et son
#     historique avaient cessé de dire la même chose.
#
#   · la mienne n'aurait JAMAIS tourné. Doctrine avait enregistré la version comme exécutée ;
#     `doctrine:migrations:status` annonçait « Already at latest version » pendant que mes colonnes
#     n'existaient pas, et le déploiement s'était déclaré réussi.
#
# ⚠ CE QUI L'A MONTRÉ N'EST NI LE DÉPLOIEMENT NI DOCTRINE, C'EST UN `SELECT` SUR LA COLONNE
# ATTENDUE. Les deux instruments qui avaient l'air de répondre répondaient à une autre question :
# « le script est allé au bout » et « la ligne est dans la table des versions ».
#
# ── POURQUOI EN SHELL ET PAS EN PHP ─────────────────────────────────────────────────────────────
#
# La première version était un `bin/garde-fou-*.php`. Elle s'annonçait NON EXÉCUTÉE partout : le
# conteneur PHP monte le worktree, dont le `.git` est un FICHIER pointant hors du montage — `git`
# y est installé mais ne résout aucune référence. Un contrôle qui saute toujours ne vaut rien.
# C'est une vérification git ; elle vit là où git est chez lui.
#
# ── LA RÈGLE ────────────────────────────────────────────────────────────────────────────────────
#
# Une migration présente dans la référence (`origin/main` par défaut) est IMMUABLE. Elle a pu
# s'exécuter quelque part ; la réécrire fait diverger ce qui a tourné de ce qui est écrit, et
# Doctrine ne la rejouera jamais.
#
# Un `up()` fautif se corrige par une migration NEUVE qui répare, jamais en réécrivant l'ancienne.
#
# ── CE QU'IL NE DIT PAS ─────────────────────────────────────────────────────────────────────────
#
# Il ne juge aucun contenu. Une migration neuve est libre ; une SUPPRESSION n'est pas son affaire
# (c'est le n°13). Il ne regarde qu'une chose : un fichier de `app/migrations/` qui existe des deux
# côtés et dont le contenu a changé.
#
# Usage :  bin/garde-fou-migrations-immuables.sh [--contre=origin/main]

set -eu

RACINE="$(cd "$(dirname "$0")/.." && pwd)"
REFERENCE="origin/main"

for option in "$@"; do
    case "$option" in
        --contre=*) REFERENCE="${option#--contre=}" ;;
    esac
done

cd "$RACINE"

# ⚠ SANS RÉFÉRENCE ATTEIGNABLE, ON NE CONCLUT PAS. Un arbre détaché, un clone sans `origin` :
# comparer contre rien déclarerait toutes les migrations intactes.
if ! git rev-parse --verify "$REFERENCE" >/dev/null 2>&1; then
    echo "Migrations immuables : non exécuté — référence « $REFERENCE » introuvable dans cet arbre."
    echo "  Comparer contre rien déclarerait toutes les migrations intactes."
    exit 0
fi

LUES=$(git ls-tree --name-only "$REFERENCE" app/migrations/ | grep -c '\.php$' || true)

# ⚠ ZÉRO MIGRATION LUE N'EST PAS UN VERT. Le dépôt en compte plus de cent-soixante ; un zéro
# signifie que le chemin ou la référence est faux, pas que tout va bien.
if [ "$LUES" -eq 0 ]; then
    echo "✗ Migrations immuables : ZÉRO migration lue dans « $REFERENCE » — rien n'a été mesuré." >&2
    echo "  Le dépôt en compte plus de cent-soixante. Vérifier le chemin avant de conclure." >&2
    exit 2
fi

# `--diff-filter=M` : uniquement les fichiers MODIFIÉS. Un ajout est légitime, une suppression
# relève du n°13.
MODIFIEES=$(git diff --name-only --diff-filter=M "$REFERENCE" -- app/migrations/ | grep '\.php$' || true)

if [ -n "$MODIFIEES" ]; then
    echo "✗ Migrations déjà parties et RÉÉCRITES — ce qui a tourné ne dit plus ce qui est écrit :" >&2
    echo "" >&2
    echo "$MODIFIEES" | sed 's/^/    /' >&2
    echo "" >&2
    echo "  Une migration présente dans « $REFERENCE » a pu s'exécuter quelque part. La réécrire fait" >&2
    echo "  diverger le schéma appliqué de son historique — et Doctrine ne la rejouera JAMAIS, puisque" >&2
    echo "  sa version est déjà enregistrée comme exécutée." >&2
    echo "  Un up() fautif se corrige par une migration NEUVE qui répare, jamais en réécrivant." >&2
    echo "" >&2
    echo "  ⚠ Si c'est un écrasement par collision de nom — le cas du 04/09 — restaurez l'original :" >&2
    echo "      git show $REFERENCE:<chemin> > <chemin>" >&2
    echo "    puis republiez la vôtre sous un numéro de version LIBRE." >&2
    exit 1
fi

echo "Migrations immuables : OK — $LUES migration(s) de « $REFERENCE » comparée(s), aucune réécrite."
exit 0
