#!/usr/bin/env bash
#
# Garde-fou n°38 — aucun marqueur de conflit ne part dans un commit.
#
# ── LE CONSTAT, ET POURQUOI IL A FALLU CINQ FOIS POUR LE POSER ─────────────────────────────────
#
# `COORDINATION/TASKS.md` a vécu dans `main` avec neuf marqueurs de conflit non résolus, et toute
# une section en double — T25 à T29 apparaissant deux fois, avec **deux versions divergentes de
# T27**. Une session qui lisait la mauvaise croyait le lot inachevé.
#
# ⚠ **CE QUI REND CE CAS DIFFÉRENT DES QUATRE PRÉCÉDENTS : LE MARKDOWN N'A PAS DE COMPILATEUR.**
#
# Un `.jsx` cassé fait échouer le build. Un `.php` cassé fait échouer `php -l`. Un `.md` cassé se lit
# comme si de rien n'était — et on croit son contenu. C'est la cinquième fusion sans conflit signalé
# qui produit un fichier incorrect en deux jours, et la première où les marqueurs eux-mêmes ont
# traversé jusqu'à `main`.
#
# Relevé par `allaccess-37`, qui a réparé le fichier et laissé le contrôle à prendre.
#
# ── POURQUOI CES TROIS MOTIFS, ET PAS D'AUTRES ─────────────────────────────────────────────────
#
# Un marqueur de conflit occupe une **ligne entière** et commence en colonne 1 :
#
#     <<<<<<< suivi d'un espace et d'un nom de branche
#     =======  seul sur sa ligne
#     >>>>>>> suivi d'un espace et d'un nom de branche
#
# ⚠ `=======` seul est le seul des trois qui puisse exister légitimement : c'est aussi la forme d'un
# **titre Markdown souligné**. Mesuré avant d'écrire ce contrôle — le dépôt n'en contient aucun, et
# aucune ligne de sept `=` exactement. Le motif est donc exact : `^=======$`, ni plus ni moins de
# sept, rien après. Un soulignement de titre fait la largeur du titre, donc rarement sept pile ; et
# s'il en fait sept, le contrôle le dira et on renommera le titre — ce qui coûte moins qu'un fichier
# de coordination faux.
#
# Usage :
#   ./bin/garde-fou-marqueurs-de-conflit.sh
set -euo pipefail

cd "$(dirname "$0")/.."

MOTIF='^(<<<<<<< |>>>>>>> |=======$)'

# ⚠ ON INTERROGE L'INDEX GIT, PAS LE DISQUE.
#
# `git grep` ne lit que ce qui est suivi : un fichier de travail non versionné, un `node_modules`, un
# `vendor/` ne peuvent pas déclencher ce contrôle. Un `grep -r` l'aurait fait, et un garde-fou qui
# crie sur des fichiers qui ne partiront jamais s'apprend à sauter.
TROUVES="$(git grep -nE "$MOTIF" -- . 2>/dev/null || true)"

if [ -n "$TROUVES" ]; then
    echo
    echo "=== ÉCHEC — marqueur de conflit non résolu ==="
    echo
    echo "$TROUVES" | head -40
    echo
    echo "  Une fusion a été laissée à moitié. Le fichier porte les DEUX versions, séparées par"
    echo "  ces marqueurs — et la moitié qui compte dépend de qui lit."
    echo
    echo "  ⚠ Sur un fichier de code, un compilateur ou un linteur l'aurait dit. Sur un .md, un"
    echo "    .json de configuration ou un .yaml, rien ne le dit : le fichier se lit comme si de"
    echo "    rien n'était, et on croit son contenu."
    echo
    echo "  Reprends la fusion : garde une seule version, retire les trois lignes de marqueurs."
    exit 1
fi

# ⚠ ON ANNONCE CE QU'ON A LU, PAS SEULEMENT QU'ON N'A RIEN TROUVÉ.
#
# Un périmètre qui rétrécit en silence est un contrôle qui s'éteint sans le dire. Si ce nombre
# tombe un jour à zéro, c'est le contrôle qu'il faut regarder, pas le dépôt.
NB="$(git grep -lI '' -- . 2>/dev/null | wc -l | tr -d ' ')"
echo "Marqueurs de conflit : aucun. $NB fichier(s) texte suivi(s) lu(s)."
