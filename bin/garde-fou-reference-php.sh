#!/bin/sh
# app/config/reference.php NE SE COMMITE PAS — garde-fou n°56 (audit du 14/09, geste 12).
#
# ── LE CONSTAT ──────────────────────────────────────────────────────────────────────────────────
#
# `app/config/reference.php` (1820 lignes) est REGENERE par la suite de tests. La seule protection
# etait une convention humaine, ecrite dans CLAUDE.md : « git checkout -- app/config/reference.php
# avant chaque commit ; ne jamais git add -A ». Un oubli commite 1820 lignes generees, et — pire —
# ratisse au passage la derive des huit autres sessions dont la suite a aussi touche le fichier.
# L'audit du 14/09 (relecteur, MEDIUM) l'a pose : « rien ne garantit dans le depot lui-meme que
# cette etape n'est pas oubliee un jour ; c'est une convention purement humaine ».
#
# ── LA REGLE ────────────────────────────────────────────────────────────────────────────────────
#
# Le fichier est present dans la reference (`origin/main` par defaut) et il y reste tel quel. Une
# branche qui le MODIFIE par rapport a la reference est refusee. S'il faut vraiment le changer
# (rarissime : ce n'est pas un fichier qu'on edite a la main), l'interrupteur `--no-verify` ou
# GARDE-FOUS-DESACTIVES existe, et ca se justifie dans le suivi.
#
# ── POURQUOI ON COMPARE REFERENCE↔HEAD, ET PAS L'ARBRE DE TRAVAIL ─────────────────────────────────
#
# `migrations-immuables.sh` compare la reference a l'arbre de travail : une migration n'est jamais
# regeneree, donc arbre == commite, aucun faux positif. ICI c'est l'inverse : `reference.php` est
# regenere a CHAQUE passage de tests, donc l'arbre de travail differe presque toujours de la
# reference. Comparer l'arbre rendrait ce garde-fou ROUGE en permanence en local — et un
# avertissement toujours rouge s'apprend a sauter (c'est arrive ici, plusieurs fois). Le defaut
# reel n'est pas « l'arbre est sale » (c'est normal, on fait `git checkout --` apres) : c'est
# « le fichier modifie est ENTRE dans un commit ». On regarde donc ce qui est COMMITE (HEAD) contre
# la reference, jamais l'arbre.
#
# ── POURQUOI EN SHELL, ET AU PRE-RECEIVE ──────────────────────────────────────────────────────────
#
# C'est une verification git (un diff contre une reference). Le conteneur PHP du pre-commit monte le
# worktree dont le `.git` est un fichier hors du montage : `git` n'y resout aucune reference, le
# controle s'y annoncerait NON EXECUTE. Il vit donc la ou git est chez lui : `bin/garde-fous.sh` et
# le hook `pre-receive` (la vraie barriere au push). Comme `migrations-immuables.sh`.
#
# Usage :  bin/garde-fou-reference-php.sh [--contre=origin/main]

set -eu

RACINE="$(cd "$(dirname "$0")/.." && pwd)"
REFERENCE="origin/main"
CHEMIN="app/config/reference.php"

for option in "$@"; do
    case "$option" in
        --contre=*) REFERENCE="${option#--contre=}" ;;
    esac
done

cd "$RACINE"

# ⚠ SANS REFERENCE ATTEIGNABLE, ON NE CONCLUT PAS. Un arbre detache, un clone sans `origin` :
# comparer contre rien declarerait le fichier intact.
if ! git rev-parse --verify "$REFERENCE" >/dev/null 2>&1; then
    echo "reference.php : non execute — reference « $REFERENCE » introuvable dans cet arbre."
    echo "  Comparer contre rien declarerait le fichier intact."
    exit 0
fi

# ⚠ LE FICHIER DOIT EXISTER DANS LA REFERENCE, SINON ON NE MESURE RIEN. S'il en est absent, c'est le
# chemin ou la reference qui est faux — pas une raison de rendre un vert.
if ! git cat-file -e "$REFERENCE:$CHEMIN" 2>/dev/null; then
    echo "✗ reference.php : « $CHEMIN » absent de « $REFERENCE » — rien n'a ete mesure." >&2
    echo "  Le fichier est suivi depuis longtemps ; verifier le chemin avant de conclure." >&2
    exit 2
fi

# On compare ce qui est COMMITE (HEAD) a la reference — jamais l'arbre de travail (voir l'en-tete).
MODIFIE="$(git diff --name-only "$REFERENCE" HEAD -- "$CHEMIN" 2>/dev/null || true)"

if [ -n "$MODIFIE" ]; then
    echo "✗ app/config/reference.php a ete COMMITE avec des modifications." >&2
    echo "" >&2
    echo "  Ce fichier est regenere par la suite de tests : il ne doit jamais entrer dans un commit." >&2
    echo "  Un commit qui le contient embarque 1820 lignes generees, et ratisse la derive des autres" >&2
    echo "  sessions dont la suite a aussi touche le fichier." >&2
    echo "" >&2
    echo "  Retire-le du commit et restaure la version de la reference :" >&2
    echo "      git checkout $REFERENCE -- $CHEMIN" >&2
    echo "  puis refais ton commit. (Regle CLAUDE.md : jamais « git add -A » ; git checkout -- avant chaque add.)" >&2
    exit 1
fi

echo "reference.php : OK — non modifie par rapport a « $REFERENCE »."
exit 0
