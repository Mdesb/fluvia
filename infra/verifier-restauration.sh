#!/bin/sh
# PREUVE DE RESTAURATION — dans une base JETABLE, jamais par-dessus la vraie.
#
# Une sauvegarde que personne n'a restauree n'est pas une sauvegarde : c'est un fichier de 800 Ko
# dont on suppose le contenu. Le seul controle qui vaille est de la relire ailleurs et d'y compter
# des lignes.
#
# ⚠ La base d'essai est creee puis SUPPRIMEE. Elle ne porte jamais le nom de la base reelle, et
# aucune commande de ce script ne mentionne cette derniere autrement qu'en lecture.
set -eu

BASE_ESSAI="essai_restauration_$(date -u +%s)"
DERNIERE="$(ls -1t /sauvegardes/billetterie-*.sql.gz 2>/dev/null | head -1)"

if [ -z "$DERNIERE" ]; then
    echo "✗ Aucune sauvegarde a restaurer." >&2
    exit 1
fi

echo "restauration de $DERNIERE dans $BASE_ESSAI"

mariadb --host=db --user=root --password="$DB_ROOT_PASSWORD" \
    -e "CREATE DATABASE \`$BASE_ESSAI\`"

gunzip -c "$DERNIERE" | mariadb --host=db --user=root --password="$DB_ROOT_PASSWORD" "$BASE_ESSAI"

echo "--- ce qu'on retrouve dans la base restauree ---"
mariadb --host=db --user=root --password="$DB_ROOT_PASSWORD" "$BASE_ESSAI" -e "
  SELECT 'tables'        AS quoi, COUNT(*) AS n FROM information_schema.tables WHERE table_schema='$BASE_ESSAI'
  UNION ALL SELECT 'etablissements', COUNT(*) FROM org_etablissement
  UNION ALL SELECT 'produits',       COUNT(*) FROM off_produit
  UNION ALL SELECT 'factures',       COUNT(*) FROM facturation_facture
  UNION ALL SELECT 'passages',       COUNT(*) FROM acces_passage;"

mariadb --host=db --user=root --password="$DB_ROOT_PASSWORD" \
    -e "DROP DATABASE \`$BASE_ESSAI\`"

echo "base d'essai supprimee"
