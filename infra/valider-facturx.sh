#!/usr/bin/env bash
#
# VALIDER UN FACTUR-X CONTRE LES OUTILS DE RÉFÉRENCE.
#
# ── POURQUOI CE SCRIPT N'EST PAS UN GARDE-FOU ───────────────────────────────────────────────────
#
# Il tire des images Docker, il lit des artefacts externes, il prend des dizaines de secondes. Le
# mettre en `pre-receive` bloquerait chaque poussée sur une dépendance réseau — et le jour où le
# registre est lent, personne ne peut plus livrer. On le lance quand on veut SAVOIR, comme
# `bin/verifier-derive-schema.sh`.
#
# ── ⚠ CE QU'IL PROUVE PAR COUCHE, ET CE QU'IL NE MÉLANGE PAS ───────────────────────────────────
#
#     PDF/A-3B     veraPDF, l'implémentation de référence   → l'ENVELOPPE est conforme
#     EN 16931     schematron officiel (à venir)            → le CONTENU de la facture est valide
#     Factur-X     validateur FNFE (à venir)                → le COUPLE est accepté en France
#
# Les trois répondent à des questions différentes. Un `PASS` PDF/A ne dit rien du contenu : un XML
# vide dans un PDF/A parfait passe cette couche et se fait refuser à la suivante.
#
# ── ⚠ ET IL PROUVE SON PROPRE INSTRUMENT ───────────────────────────────────────────────────────
#
# Un validateur mal invoqué — mauvais profil, fichier introuvable, règles non chargées — peut rendre
# « PASS » sans avoir rien vérifié. Chaque couche est donc jouée DEUX fois : sur un fichier qui doit
# passer, et sur un témoin qui doit ÉCHOUER. Si le témoin passe, le verdict entier est annulé.
#
# Mesuré le 02/09 : `PASS ... 3b` sur le Factur-X, `FAIL` (rc=1) sur un PDF ordinaire.
set -euo pipefail

# ⚠ ÉPINGLÉ PAR EMPREINTE, PAS PAR ÉTIQUETTE. `verapdf/cli:latest` change sous les pieds : un jour le
# verdict bougerait sans qu'une ligne du produit ait changé, et on chercherait la régression chez
# nous. L'empreinte fige l'outil ; la relever est une décision qui se voit en revue.
VERAPDF="verapdf/cli@sha256:d5ee329657cf9bc4b2400392dd54c7d0a0ce9980ff6fa2da5590eebeec007cdb"

RACINE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TRAVAIL="${TMPDIR:-/tmp}/facturx-validation"
IMAGE_PHP="billetterie-preprod-php"

rm -rf "$TRAVAIL"
mkdir -p "$TRAVAIL"

echo "── Fabrication des fichiers à valider ──"

# ⚠ LA DONNÉE VIENT DES TESTS, PAS DE LA PRODUCTION. Aucune facture réelle n'est émettable
# aujourd'hui (l'adresse de l'acheteur manque partout), et inventer une adresse pour se fabriquer un
# cas vert validerait un document que le produit ne sait pas encore produire.
docker run --rm -u "$(id -u):$(id -g)" \
    -v "$RACINE:/repo" -v "$TRAVAIL:/sortie" -w /repo/app \
    "$IMAGE_PHP" php fabriquer-temoin-facturx.php /sortie/facturx.pdf

# Le témoin négatif : un PDF ordinaire, qui ne DOIT PAS passer.
docker run --rm -u "$(id -u):$(id -g)" \
    -v "$RACINE:/repo" -v "$TRAVAIL:/sortie" -w /repo/app \
    "$IMAGE_PHP" php -r '
        require "vendor/autoload.php";
        $d = new Dompdf\Dompdf();
        $d->loadHtml("<html><body><p>PDF ordinaire</p></body></html>", "UTF-8");
        $d->render();
        file_put_contents("/sortie/temoin-negatif.pdf", (string) $d->output());
    '

echo
echo "── Couche 1 : PDF/A-3B (veraPDF) ──"

# `--network none` : le validateur n'a besoin que du fichier. Lui couper le réseau est gratuit et
# retire toute question sur ce qu'il enverrait ailleurs.
verapdf() {
    docker run --rm --network none -v "$TRAVAIL:/data" "$VERAPDF" --format text -f 3b "/data/$1"
}

CODE_ATTENDU=0
SORTIE_OK="$(verapdf facturx.pdf || true)"
echo "  $SORTIE_OK"

SORTIE_TEMOIN="$(verapdf temoin-negatif.pdf || true)"
echo "  $SORTIE_TEMOIN   ← témoin, doit ÉCHOUER"

if [[ "$SORTIE_TEMOIN" != FAIL* ]]; then
    echo
    echo "✗ INSTRUMENT MORT : le témoin négatif a PASSÉ."
    echo
    echo "  Un PDF ordinaire n'est pas du PDF/A-3B. S'il passe, c'est que veraPDF ne vérifie rien —"
    echo "  mauvais profil, règles non chargées, fichier introuvable. Le « PASS » de la ligne au-"
    echo "  dessus ne vaut alors RIEN, et c'est le genre de vert qu'on croit."
    exit 1
fi

if [[ "$SORTIE_OK" != PASS* ]]; then
    echo
    echo "✗ Le Factur-X produit n'est PAS conforme PDF/A-3B."
    echo "  Détail complet :"
    docker run --rm --network none -v "$TRAVAIL:/data" "$VERAPDF" -f 3b /data/facturx.pdf || true
    exit 1
fi

echo "  ✓ PDF/A-3B conforme, et le témoin négatif a bien échoué."

echo
echo "── Couches 2 et 3 : NON EXÉCUTÉES ──"
echo
echo "  ⚠ Le XML n'a PAS été validé contre le schematron d'EN 16931 (~100 règles BR-xx), ni le"
echo "    couple contre le validateur Factur-X de la FNFE."
echo
echo "  PDF/A-3B dit que l'ENVELOPPE est conforme. Il ne dit rien du CONTENU : un XML vide dans un"
echo "  PDF/A parfait passe cette couche. Ne pas lire ce verdict comme « la facture est valide »."
echo
echo "  Fichiers conservés pour la suite : $TRAVAIL"
exit "$CODE_ATTENDU"
