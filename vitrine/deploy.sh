#!/usr/bin/env bash
#
# Déploie le site vitrine de Fluvia sur l'hôte qui le sert.
#
# POURQUOI UN SCRIPT POUR TROIS FICHIERS. Parce qu'il y en aura plus de trois, et surtout parce que
# la question « ce qui est en ligne est-il ce que je viens d'écrire ? » ne se répond pas de mémoire.
# Le script copie, puis RELIT la page servie et vérifie qu'elle porte bien le marqueur du dépôt :
# une copie qui échoue en silence laisse en ligne la version d'avant, et rien ne le dit.
#
# Usage : ./vitrine/deploy.sh    (depuis la racine du dépôt, sur le VPS)

set -euo pipefail

SOURCE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CIBLE="/var/www/fluvia-vitrine"
HOTE="https://vitrine.hector-conseil.com"

if [ ! -f "$SOURCE/index.html" ]; then
    echo "Erreur : $SOURCE/index.html est introuvable." >&2
    exit 1
fi

sudo mkdir -p "$CIBLE"
sudo cp "$SOURCE/index.html" "$SOURCE/styles.css" "$SOURCE/tarifs.js" "$CIBLE/"
sudo chown -R www-data:www-data "$CIBLE"

# LE TÉMOIN. On relit la page servie par nginx, pas le fichier qu'on vient de copier — c'est la
# seule mesure qui traverse tout ce qui peut mentir entre les deux (droits, cache, mauvais `root`,
# vhost qui ne correspond pas). Le marqueur est le titre : il change à chaque refonte de la page,
# donc un déploiement partiel se voit.
ATTENDU="$(grep -o '<title>[^<]*</title>' "$SOURCE/index.html")"
SERVI="$(curl -fsS "$HOTE/" | grep -o '<title>[^<]*</title>' || true)"

if [ "$ATTENDU" != "$SERVI" ]; then
    echo "ÉCHEC : la page servie ne correspond pas à la source." >&2
    echo "  attendu : $ATTENDU" >&2
    echo "  servi   : ${SERVI:-<aucun titre lu>}" >&2
    exit 1
fi

echo "Déployé et vérifié sur $HOTE — $ATTENDU"
