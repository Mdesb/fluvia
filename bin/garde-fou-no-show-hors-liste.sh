#!/bin/sh
# `reservation:no-show:basculer` RESTE HORS DE LA LISTE BLANCHE DE L'ORDONNANCEUR — garde-fou n°57 (D95).
#
# ── LE CONSTAT ──────────────────────────────────────────────────────────────────────────────────
#
# D95 interdit cette tâche tant qu'aucune présence n'est écrite : sans présence confirmée, elle
# facture une absence à des gens venus. L'en-tête d'`infra/ordonnanceur.sh` le disait en toutes
# lettres, et la tâche est quand même entrée dans `TACHES_AUTORISEES` le 04/09 (4a86eb88). Elle y est
# restée jusqu'au 07/10 : 10 réservations en `no_show_facture`, 140,00 € « à facturer », 0 présence
# en base. Un commentaire n'a rien empêché ; ce contrôle refuse.
#
# ── LA RÈGLE ────────────────────────────────────────────────────────────────────────────────────
#
# Aucune ligne de CODE d'`infra/ordonnanceur.sh` ne nomme la tâche. Pas seulement l'affectation : une
# seconde affectation ou un `for` qui l'ajoute la ferait tourner aussi. Les commentaires, eux, peuvent
# la nommer — l'en-tête le fait, et doit continuer.
#
# ── LA LEVÉE ────────────────────────────────────────────────────────────────────────────────────
#
# Les deux moitiés de D95, mesurées : un écran appelle `/emarger` (vrai depuis le 31/08), ET une
# présence confirmée existe en base (zéro le 07/10). Puis une décision de Maxime au journal, et ce
# contrôle retiré dans le même commit que la tâche ajoutée.
#
# Usage :  bin/garde-fou-no-show-hors-liste.sh
set -eu
cd "$(dirname "$0")/.."

FICHIER="infra/ordonnanceur.sh"
TACHE="reservation:no-show:basculer"

# ⚠ UNE LISTE INTROUVABLE N'EST PAS UNE LISTE SANS LA TÂCHE. Fichier déplacé, affectation réécrite
# autrement : rien n'a été lu, donc rien n'est prouvé.
if ! grep -q '^TACHES_AUTORISEES="' "$FICHIER" 2>/dev/null; then
    echo "✗ No-show hors liste : aucune affectation TACHES_AUTORISEES=\"…\" dans $FICHIER — rien mesuré." >&2
    echo "  Si la forme a changé, adapte ce contrôle avec elle." >&2
    exit 2
fi

TROUVES="$(grep -nE "^[[:space:]]*[^#[:space:]].*$TACHE" "$FICHIER" || true)"
if [ -n "$TROUVES" ]; then
    echo "✗ $TACHE est dans TACHES_AUTORISEES ($FICHIER) :" >&2
    echo "$TROUVES" | cut -c1-160 | sed 's/^/    /' >&2
    echo "" >&2
    echo "  D95 l'interdit : sans présence confirmée, la tâche facture une absence à des gens venus." >&2
    echo "  Du 04/09 au 07/10 elle a basculé 10 réservations en no_show_facture, pour 0 présence en base." >&2
    echo "" >&2
    echo "  Condition de levée (D95), les deux moitiés mesurées, pas supposées :" >&2
    echo "    1. un écran appelle /emarger ;" >&2
    echo "    2. une présence confirmée existe en base." >&2
    echo "  Puis une décision de Maxime au journal qui lève D95, et ce contrôle retiré avec." >&2
    exit 1
fi

echo "No-show hors liste (n°57) : OK — $TACHE absente du code de $FICHIER."
