#!/bin/sh
#
# SAUVEGARDE DE LA BASE DE PRÉPRODUCTION.
#
# ── CE QU'IL N'Y AVAIT PAS, ET CE QUE ÇA A COÛTÉ ────────────────────────────────────────────────
#
# Mesuré par `allaccess-b8` le 31/08 : aucune sauvegarde, aucune tâche qui en fasse, et
# `doctrine:fixtures:load` ABSENTE en préproduction — `composer install --no-dev` retire le paquet.
#
# Les 38 classes de fixtures décrivent tout le jeu de démonstration et **ne peuvent pas être
# rejouées**. Le volume `db_data` est persistant, ce qui est de la durabilité, pas de la
# récupérabilité : il survit à un redémarrage, pas à un `DELETE`.
#
# Deux écritures irréversibles y ont été faites le même jour — un parc de patins fantôme créé par une
# sonde à corps vide, et 25 € rechargés pour de bon sur le porte-monnaie d'un client de
# démonstration. Aucune opération inverse n'existe dans l'API pour l'une ni pour l'autre.
#
# ⚠ ET LA DÉMONSTRATION DÉRIVE. Ce n'est pas seulement un filet contre l'erreur : sans restauration,
# le bruit s'accumule dans tous les écrans que Maxime montre à des tiers.
#
# ── LA MOITIÉ QUI MANQUE À LA PLUPART DES SAUVEGARDES ───────────────────────────────────────────
#
# Une sauvegarde qui écrit un fichier vide est PIRE que pas de sauvegarde : elle remplace une absence
# connue par une présence supposée. On vérifie donc chaque fichier produit — taille non nulle ET
# présence d'une table qu'on sait exister — et on refuse de le garder s'il ne passe pas.
#
# ── RESTAURER ───────────────────────────────────────────────────────────────────────────────────
#
# Une sauvegarde dont personne ne connaît la commande de restauration n'en est pas une :
#
#     gunzip -c /home/debian/sauvegardes-billetterie/<fichier>.sql.gz \
#       | docker exec -i billetterie-preprod-db-1 mariadb -u root -p"$DB_ROOT_PASSWORD" "$DB_NAME"
#
set -eu

DEST="${SAUVEGARDE_DEST:-/sauvegardes}"
JOURS="${SAUVEGARDE_RETENTION_JOURS:-14}"
INTERVALLE="${SAUVEGARDE_INTERVALLE:-86400}"

# ⚠ Une table qu'on sait exister : c'est le témoin positif. Sans lui, un dump d'une base VIDE
# passerait le contrôle de taille et serait gardé comme valide.
TABLE_TEMOIN="${SAUVEGARDE_TABLE_TEMOIN:-org_etablissement}"

sauvegarder() {
    horodatage="$(date -u +%Y%m%dT%H%M%SZ)"
    fichier="$DEST/billetterie-$horodatage.sql.gz"
    partiel="$fichier.partiel"

    # On écrit d'abord sous un nom PARTIEL : un fichier interrompu ne doit jamais porter le nom d'une
    # sauvegarde valide. Il n'est renommé qu'après vérification.
    if ! mariadb-dump --host=db --user=root --password="$DB_ROOT_PASSWORD" \
            --single-transaction --routines --triggers --events \
            "$DB_NAME" 2>"$DEST/.derniere-erreur" | gzip > "$partiel"; then
        echo "[sauvegarde] ✗ ÉCHEC du dump — voir $DEST/.derniere-erreur" >&2
        rm -f "$partiel"
        return 1
    fi

    octets="$(wc -c < "$partiel")"
    if [ "$octets" -lt 1024 ]; then
        echo "[sauvegarde] ✗ REFUSÉE : $octets octets, c'est vide ou tronqué." >&2
        rm -f "$partiel"
        return 1
    fi

    # ⚠ LE TÉMOIN POSITIF. Une base vidée produit un dump syntaxiquement valide et parfaitement
    # inutile. On exige d'y retrouver une table qu'on sait exister.
    if ! gunzip -c "$partiel" | grep -q "$TABLE_TEMOIN"; then
        echo "[sauvegarde] ✗ REFUSÉE : la table témoin « $TABLE_TEMOIN » est absente du dump." >&2
        echo "[sauvegarde]   Le fichier est syntaxiquement valide et ne contient pas la base." >&2
        rm -f "$partiel"
        return 1
    fi

    mv "$partiel" "$fichier"
    echo "[sauvegarde] ✓ $fichier ($octets octets, témoin « $TABLE_TEMOIN » présent)"
}

purger() {
    # `-mtime +N` sur les fichiers VALIDES seulement : un partiel oublié se nettoie à part, pour que
    # sa présence reste visible plutôt qu'effacée en silence.
    find "$DEST" -name 'billetterie-*.sql.gz' -type f -mtime "+$JOURS" -print -delete
    find "$DEST" -name '*.partiel' -type f -mtime +1 -print -delete
}

mkdir -p "$DEST"

echo "[sauvegarde] démarrage · destination $DEST · rétention ${JOURS} jours · toutes les ${INTERVALLE}s"

# ⚠ UNE SAUVEGARDE IMMÉDIATE AU DÉMARRAGE. Attendre le premier intervalle laisserait une journée
# entière sans filet — et c'est précisément la journée où l'on vient de découvrir qu'il n'y en avait
# pas.
while true; do
    sauvegarder || echo "[sauvegarde] la prochaine tentative aura lieu dans ${INTERVALLE}s" >&2
    purger || true
    sleep "$INTERVALLE"
done
