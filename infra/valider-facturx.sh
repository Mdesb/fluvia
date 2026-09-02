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
#     EN 16931     schematron officiel du CEN               → le CONTENU de la facture est valide
#     Factur-X     validateur FNFE (à venir)                → le COUPLE est accepté en France
#
# Les trois répondent à des questions différentes. Un `PASS` PDF/A ne dit rien du contenu : un XML
# vide dans un PDF/A parfait passe la première couche et se fait refuser à la deuxième — ce qui est
# arrivé, quatre règles enfreintes d'un coup, le 02/09.
#
# ── ⚠ ET IL PROUVE SON PROPRE INSTRUMENT, COUCHE PAR COUCHE ────────────────────────────────────
#
# Un validateur mal invoqué — mauvais profil, artefact absent, XSLT qui ne s'applique à rien — peut
# rendre « PASS » ou « 0 échec » sans avoir rien vérifié. Chaque couche est donc jouée sur un fichier
# qui doit passer ET sur un témoin qui doit échouer. Si un témoin se comporte mal, le verdict est
# ANNULÉ, quel qu'il soit.
set -euo pipefail

# ⚠ ÉPINGLÉ PAR EMPREINTE, PAS PAR ÉTIQUETTE. `verapdf/cli:latest` change sous les pieds : un jour le
# verdict bougerait sans qu'une ligne du produit ait changé, et on chercherait la régression chez
# nous. L'empreinte fige l'outil ; la relever est une décision qui se voit en revue.
VERAPDF="verapdf/cli@sha256:d5ee329657cf9bc4b2400392dd54c7d0a0ce9980ff6fa2da5590eebeec007cdb"
JRE="eclipse-temurin@sha256:4cbffea0432e0209a002c816a9fad6557d83147e56d5df6a73cdeec3c03ea522"

# ⚠ ARTEFACTS TÉLÉCHARGÉS UNE FOIS, EMPREINTES VÉRIFIÉES À LA RÉCEPTION :
#
#   en16931-cii-1.3.16.zip  sha256 1cd53cb8a84d38aedc82c0caede217da983a7934dd663f793a092fd66443c561
#                           publié par ConnectingEurope/eInvoicing-EN16931, version 1.3.16
#   Saxon-HE-12.10.jar      sha1   1d6492dbfa32ddb2cd432fa3fbb940bf8137a5d2   (publié par Maven)
#   xmlresolver-5.3.3.jar   sha1   vérifié contre Maven au téléchargement
#
# Un artefact de validation qu'on ne vérifie pas transforme le validateur lui-même en angle mort.
VALIDATEURS="${VALIDATEURS:-/home/debian/validateurs}"
SCHEMATRON="en16931-cii-1.3.16/xslt/EN16931-CII-validation.xslt"

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

# Le témoin négatif du PDF/A : un PDF ordinaire, qui ne DOIT PAS passer.
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
    echo "✗ Le Factur-X produit n'est PAS conforme PDF/A-3B. Détail :"
    docker run --rm --network none -v "$TRAVAIL:/data" "$VERAPDF" -f 3b /data/facturx.pdf || true
    exit 1
fi

echo "  ✓ PDF/A-3B conforme, et le témoin négatif a bien échoué."

echo
echo "── Couche 2 : EN 16931 (schematron officiel du CEN) ──"

if [ ! -f "$VALIDATEURS/$SCHEMATRON" ]; then
    echo "  ✗ NON EXÉCUTÉE — artefacts absents de $VALIDATEURS."
    echo "    Le contrôle n'a PAS tourné : ne pas lire l'absence de rouge comme un vert."
    exit 1
fi

cp "$TRAVAIL/facturx.xml" "$VALIDATEURS/a-valider.xml"

saxon() {
    docker run --rm --network none -v "$VALIDATEURS:/v" -w /v "$JRE" \
        java -cp '/v/Saxon-HE-12.10.jar:/v/xmlresolver-5.3.3.jar' net.sf.saxon.Transform \
        -s:"$1" -xsl:"$SCHEMATRON" -o:"/v/$2" >/dev/null 2>&1
}

echecs() {
    # ⚠ `grep -c ... || echo 0` IMPRIMAIT DEUX VALEURS. `grep -c` rend rc=1 quand il compte zero,
    # donc le repli s'executait EN PLUS de la sortie « 0 » — et la comparaison `[ "$N" != "0" ]`
    # voyait deux valeurs collees au lieu d'une. Le garde-fou d'instrument a refuse de conclure,
    # ce qui etait le bon comportement ; sans lui on aurait lu un verdict fabrique par un bug de
    # comptage — un chiffre faux qui a l'air d'un chiffre.
    local n
    n="$(grep -c 'svrl:failed-assert' "$VALIDATEURS/$1" 2>/dev/null || true)"
    echo "${n:-0}"
}

# ⚠ TROIS PASSAGES, PAS UN. L'exemple officiel du paquet prouve que le montage marche ; le témoin
# cassé prouve qu'il sait REFUSER ; notre fichier est jugé entre les deux. Sans les deux premiers,
# un « 0 échec » ne distinguerait pas une facture valide d'un XSLT qui ne s'applique à rien.
saxon "en16931-cii-1.3.16/examples/CII_business_example_01.xml" "r-officiel.xml"
N_OFFICIEL="$(echecs r-officiel.xml)"

saxon "temoin-casse.xml" "r-casse.xml"
N_CASSE="$(echecs r-casse.xml)"

saxon "a-valider.xml" "r-notre.xml"
N_NOTRE="$(echecs r-notre.xml)"

echo "  exemple officiel du CEN : $N_OFFICIEL échec(s)   ← doit être 0"
echo "  témoin cassé            : $N_CASSE échec(s)   ← doit être > 0"
echo "  NOTRE facture           : $N_NOTRE échec(s)"

if [ "$N_OFFICIEL" != "0" ] || [ "$N_CASSE" = "0" ]; then
    echo
    echo "✗ INSTRUMENT MORT : le montage du schematron ne mesure pas ce qu'il annonce."
    echo "    un exemple officiel qui échoue  → le montage est faux, pas notre facture"
    echo "    un témoin cassé qui passe       → le XSLT ne s'applique à rien"
    echo
    echo "  Le verdict sur notre facture est ANNULÉ, quel qu'il soit."
    exit 1
fi

if [ "$N_NOTRE" != "0" ]; then
    echo
    echo "✗ Notre facture est refusée par EN 16931 :"
    sed -n 's|.*<svrl:text>\(.*\)</svrl:text>.*|    - \1|p' "$VALIDATEURS/r-notre.xml" | head -20
    exit 1
fi

echo "  ✓ EN 16931 : aucune règle enfreinte, et les deux témoins se comportent comme attendu."

echo
echo "── Couche 3 : Factur-X (Mustangproject 2.26.0) ──"

MUSTANG="$VALIDATEURS/Mustang-CLI-2.26.0.jar"

if [ ! -f "$MUSTANG" ]; then
    echo "  ✗ NON EXÉCUTÉE — $MUSTANG absent."
    echo "    Le contrôle n'a PAS tourné : ne pas lire l'absence de rouge comme un vert."
    exit 1
fi

cp "$TRAVAIL/facturx.pdf" "$VALIDATEURS/a-valider.pdf"
cp "$TRAVAIL/temoin-negatif.pdf" "$VALIDATEURS/temoin-negatif.pdf"

mustang() {
    docker run --rm --network none -v "$VALIDATEURS:/v" -w /v "$JRE"         java -jar /v/Mustang-CLI-2.26.0.jar --action validate --source "/v/$1" 2>&1
}

# ⚠ ON LIT LES VERDICTS PAR SECTION, JAMAIS LE RÉSUMÉ GLOBAL.
#
# Mesuré le 02/09 : le rapport rendait `<summary status="valid"/>` au niveau document alors que sa
# section `<pdf>` disait `invalid` avec huit erreurs. Un outil peut mentir comme un test peut mentir ;
# on interroge la partie qui répond à la question qu'on pose.
section() {
    python3 "$RACINE/infra/lire-verdict-facturx.py" "$1"
}


# ⚠ `|| true` EST INDISPENSABLE, ET IL A ETE APPRIS EN CASSANT CE SCRIPT. Le validateur sort en
# ERREUR pour un fichier invalide — ce qui est exactement ce qu'on lui demande sur le temoin. Sous
# `set -euo pipefail`, cet echec ATTENDU tuait le script avant la moindre ligne de verdict, et la
# couche 3 s'arretait juste apres son titre. Une garde qui meurt de ce qu'elle mesure ne mesure rien.
mustang a-valider.pdf > "$TRAVAIL/mustang.txt" || true
mustang temoin-negatif.pdf > "$TRAVAIL/mustang-temoin.txt" || true

PDF_NOTRE="$(section < "$TRAVAIL/mustang.txt" pdf)"
XML_NOTRE="$(section < "$TRAVAIL/mustang.txt" xml)"
PDF_TEMOIN="$(section < "$TRAVAIL/mustang-temoin.txt" pdf)"

echo "  NOTRE facture  : pdf=${PDF_NOTRE:-?}  xml=${XML_NOTRE:-?}"
echo "  témoin (PDF nu): pdf=${PDF_TEMOIN:-?}   ← doit être invalid"

if [ "$PDF_TEMOIN" != "invalid" ]; then
    echo
    echo "✗ INSTRUMENT MORT : un PDF ordinaire est déclaré valide comme Factur-X."
    echo "  Le verdict sur notre facture est ANNULÉ."
    exit 1
fi

if [ "$PDF_NOTRE" != "valid" ] || [ "$XML_NOTRE" != "valid" ]; then
    echo
    echo "✗ Le Factur-X est refusé :"
    grep -oE "ERROR [^\"]*" "$TRAVAIL/mustang.txt" | head -10 | sed "s/^/    /"
    exit 1
fi

echo "  ✓ Factur-X : les deux sections valides, et un PDF ordinaire est bien refusé."

# ⚠ LES AVERTISSEMENTS NE SONT PAS DU BRUIT : c'est le profil FRANÇAIS (BR-FR, XP Z12-012).
# Ils ne bloquent pas ce validateur aujourd'hui et bloqueront un dépôt réel. Les taire ferait croire
# qu'il ne reste rien à faire.
N_AVERT="$(grep -c "<warning" "$TRAVAIL/mustang.txt" || true)"
if [ "${N_AVERT:-0}" != "0" ]; then
    echo
    echo "  ⚠ ${N_AVERT} avertissement(s) du profil FRANÇAIS — non bloquants ici, à traiter avant un dépôt réel :"
    grep -oE "BR-FR-[0-9]+/BT-[0-9]+ : [^[]*" "$TRAVAIL/mustang.txt" | sort -u | head -8 | sed "s/^/      /"
fi

echo
echo "  Fichiers conservés : $TRAVAIL et $VALIDATEURS"
