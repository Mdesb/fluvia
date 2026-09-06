#!/usr/bin/env bash
# Propage origin/main vers le miroir PUBLIC github.com/Mdesb/fluvia, EN RETIRANT ce qui ne doit
# jamais être public : le doc d'accès `infra/ACCES-VPS.md` et l'IP/host du VPS.
#
# ── POURQUOI CE N'EST PAS UN SIMPLE `git push github main` ────────────────────────────────────────
#
# GitHub est public ; `origin` (le bare, privé, sur le VPS) garde dans son historique l'IP du VPS et
# a porté le doc d'accès. On ne réécrit PAS `origin` (neuf sessions poussent dessus, et son `pre-receive`
# refuse à raison toute réécriture d'historique). On publie donc une version FILTRÉE : `git filter-repo`
# retire le fichier de tous les commits et remplace les chaînes sensibles par un placeholder. Le résultat
# est déterministe (mêmes SHA d'un run à l'autre), donc après la première publication forcée les suivantes
# sont des fast-forward.
#
# ⚠ Les règles de remplacement contiennent l'IP : elles vivent HORS dépôt (`$RULES`), jamais suivies.
# ⚠ Ce script est le SEUL chemin autorisé vers `github`. Ne pousse jamais `github main` à la main.
set -euo pipefail

BARE="/home/debian/billetterie.git"
RULES="${FLUVIA_SCRUB_RULES:-$HOME/.fluvia-scrub-rules}"
WORK="/home/debian/.fluvia-pub-mirror"
GITHUB="git@github.com:Mdesb/fluvia.git"
export PATH="$HOME/.local/bin:$PATH"

command -v git-filter-repo >/dev/null 2>&1 || { echo "✗ git-filter-repo introuvable (PATH=$PATH)"; exit 1; }
[ -f "$RULES" ] || { echo "✗ règles de scrub absentes : $RULES (hors dépôt, à recréer sur le VPS)"; exit 1; }

echo "▶ clone jetable de main depuis le bare"
rm -rf "$WORK"
git clone -q --single-branch --branch main "$BARE" "$WORK"
cd "$WORK"

echo "▶ filtrage : retrait d'ACCES-VPS.md + scrub IP/host"
git filter-repo --force --invert-paths --path infra/ACCES-VPS.md --replace-text "$RULES" >/dev/null

echo "▶ contrôle : plus aucune trace avant de publier"
# Chaque chaîne sensible (partie gauche des règles « motif==>placeholder ») doit avoir disparu de
# TOUS les commits. Un seul reste = on annule plutôt que de publier du sale.
sed 's/==>.*//' "$RULES" | while IFS= read -r motif; do
  [ -z "$motif" ] && continue
  if git rev-list --all | while read -r c; do git grep -qI -e "$motif" "$c" 2>/dev/null && echo x; done | grep -q x; then
    echo "✗ « $motif » subsiste après filtrage — publication annulée"; exit 2
  fi
done
git cat-file -e HEAD:infra/ACCES-VPS.md 2>/dev/null && { echo "✗ ACCES-VPS.md encore présent — annulé"; exit 2; } || true

echo "▶ publication vers le miroir public"
git remote add github "$GITHUB"
git push --force github main

echo "✓ github/main publié, propre : $(git rev-parse --short HEAD)"
