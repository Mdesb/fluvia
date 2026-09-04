#!/usr/bin/env bash
#
# Déploie les fichiers STATIQUES du site vitrine de Fluvia.
#
# ⚠ DEPUIS ED-10, LA PAGE D'ACCUEIL ET LE BLOG NE SONT PLUS ICI. Ils sont rendus par l'application,
# depuis le contenu écrit dans l'écran d'administration : `infra/deploy-preprod.sh` les met en ligne
# comme le reste du code. Ce script ne déploie plus que ce qui reste un fichier — la feuille de
# style, les deux scripts du tunnel, et la page d'atterrissage de confirmation.
#
# LE TÉMOIN A CHANGÉ AVEC ELLE. Il lisait le `<title>` de la page servie ; ce titre vient maintenant
# de la base, il ne dit donc plus rien du déploiement. On compare désormais, fichier par fichier,
# l'empreinte de ce qu'on vient de copier à celle de ce que l'hôte SERT. C'est plus fort que
# l'ancien témoin : il prouvait qu'une page répondait, celui-ci prouve que chaque octet servi est
# celui du dépôt.
#
# Usage : ./vitrine/deploy.sh    (depuis le dépôt, sur le VPS)

set -euo pipefail

SOURCE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CIBLE="/var/www/fluvia-vitrine"
HOTE="https://vitrine.hector-conseil.com"

if [ ! -f "$SOURCE/styles.css" ]; then
    echo "Erreur : $SOURCE/styles.css est introuvable." >&2
    exit 1
fi

sudo mkdir -p "$CIBLE"

# ⚠ `index.html` NE DOIT PLUS EXISTER DANS LA CIBLE. S'il y reste, il ne gêne pas tant que le vhost
# ne porte pas `index index.html` — mais le jour où quelqu'un le remet, l'ancienne page réapparaît
# à la place du site. On le retire donc, plutôt que de compter sur une directive absente.
sudo rm -f "$CIBLE/index.html"

FICHIERS=(styles.css tarifs.js tunnel.js confirmation.html confirmation.js)

# LA MARQUE EST SERVIE PAR CET HOTE, PAS PAR CELUI DE L APPLICATION. Les deux sites sont sur des
# domaines distincts : un logo charge depuis smartaccess ferait une requete tierce sur chaque page
# publique, et disparaitrait le jour ou l application change d adresse.
MARQUE=(FLUVIA_Icon_Color.svg FLUVIA_Logo_Color.svg FLUVIA_Favicon_512.png)

for f in "${FICHIERS[@]}"; do
    sudo cp "$SOURCE/$f" "$CIBLE/$f"
done

sudo mkdir -p "$CIBLE/marque"
for f in "${MARQUE[@]}"; do
    sudo cp "$SOURCE/../frontend/public/marque/$f" "$CIBLE/marque/$f"
done

sudo chown -R www-data:www-data "$CIBLE"

# LE TÉMOIN : ce qui est SERVI, pas ce qui a été copié. Entre les deux se glissent les droits, le
# cache, un mauvais `root`, un vhost qui ne correspond pas — et aucun de ces échecs ne fait échouer
# la copie.
ECHECS=0

verifier() {
    local source="$1" adresse="$2"
    local attendu servi

    attendu="$(sha256sum "$source" | cut -d' ' -f1)"
    servi="$(curl -fsS "$HOTE/$adresse" | sha256sum | cut -d' ' -f1)" || servi="<illisible>"

    if [ "$attendu" != "$servi" ]; then
        echo "ÉCHEC : $adresse servi ne correspond pas à la source ($attendu ≠ $servi)" >&2
        ECHECS=$((ECHECS + 1))
    fi
}

for f in "${FICHIERS[@]}"; do
    verifier "$SOURCE/$f" "$f"
done

# ⚠ LA MARQUE EST VÉRIFIÉE COMME LE RESTE, ET CE N'EST PAS DE LA SYMÉTRIE POUR LA SYMÉTRIE. La
# première version de cette boucle ne parcourait que `FICHIERS` : un logo copié mais non servi —
# droits, mauvais chemin, dossier absent — aurait laissé le site s'afficher SANS SON LOGO, avec un
# déploiement qui se déclare réussi. Une image manquante ne casse rien : c'est exactement pour ça
# qu'elle passe inaperçue.
for f in "${MARQUE[@]}"; do
    verifier "$SOURCE/../frontend/public/marque/$f" "marque/$f"
done

if [ "$ECHECS" -gt 0 ]; then
    echo "$ECHECS fichier(s) servis ne correspondent pas." >&2
    exit 1
fi

echo "Déployé et vérifié sur $HOTE — $(( ${#FICHIERS[@]} + ${#MARQUE[@]} )) fichier(s), empreintes identiques."
echo "Rappel : la page d'accueil et le blog sont servis par l'application (infra/deploy-preprod.sh)."
