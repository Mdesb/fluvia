#!/bin/bash
#
# RÉCUPÈRE LES PILES DE TEST ABANDONNÉES — le 04/09, elles avaient rempli le pool Docker.
#
# ── CE QUI S'EST PASSÉ ──────────────────────────────────────────────────────────────────────────
#
# `test-stack.sh up <jeton>` crée un réseau et une base ; `down` existe et personne ne l'appelle.
# Vingt-neuf piles s'étaient accumulées, de 43 heures à 10 jours d'inactivité, et elles tenaient
# les TRENTE ET UNE plages que Docker alloue par défaut :
#
#     172.17.0.0/12 en /16   15 plages
#     192.168.0.0/16 en /20  16 plages
#
# `docker network prune` ne libérait rien — aucun réseau n'était vide, chacun portait sa base. Le
# pool a été agrandi le même jour (`/etc/docker/daemon.json`, 10.201.0.0/16 en /24 = 256 plages),
# mais un pool plus grand se remplit aussi : il fallait aussi que quelque chose vide.
#
# ── ⚠ LA MESURE ÉVIDENTE ÉTAIT MORTE, ET ELLE NE LE DISAIT PAS ──────────────────────────────────
#
# Première version : lire la date de dernière écriture dans `/var/lib/mysql`, pour distinguer une
# pile abandonnée d'une pile qui dort entre deux suites. Vérifié juste après le redémarrage du
# démon : **MariaDB réécrit tout au démarrage**, jusqu'aux `.ibd` applicatifs. Les vingt-huit bases
# annonçaient « inactive depuis 0 h » à la seconde où le démon est reparti.
#
# Ce récupérateur-là n'aurait rien détruit — il épargne quand il ne sait pas — mais il n'aurait
# plus rien récupéré, **en continuant d'annoncer qu'il le faisait**. Un outil qui ment dans le sens
# rassurant est plus dur à débusquer qu'un outil qui casse.
#
# On mesure donc ce qu'on veut savoir au lieu de l'inférer : `test-stack.sh` écrit l'horodatage dans
# `/var/lib/piles-test/<jeton>` à chaque `up` et chaque `run`. Hors du conteneur, donc insensible à
# ses redémarrages.
#
# ── CE QU'IL ÉPARGNE, ET POURQUOI ───────────────────────────────────────────────────────────────
#
# ⚠ UNE PILE SANS MARQUE N'EST PAS UNE PILE MORTE : c'en est une d'avant la marque. On lui en pose
# une à l'instant et on la garde — le compte commence là. Détruire le travail d'un pair ne coûte
# pas la même chose qu'une plage de plus.
#
# ⚠ UNE PILE DONT UNE SUITE TOURNE N'EST JAMAIS TOUCHÉE, même si sa marque est vieille : une suite
# longue lit plus qu'elle n'écrit, et elle dure plus d'une heure.
#
# ── CE QUE ÇA DÉTRUIT ───────────────────────────────────────────────────────────────────────────
#
# La base de test et son réseau. PAS le worktree, PAS le code, PAS les commits, PAS les clés JWT.
# `./infra/test-stack.sh up <jeton>` reconstruit tout en une commande.
#
# Usage :
#   ./infra/recuperer-piles-test.sh                 # simulation, n'écrit rien
#   ./infra/recuperer-piles-test.sh --pour-de-vrai
#   SEUIL_JOURS=3 ./infra/recuperer-piles-test.sh --pour-de-vrai
#
set -u

SEUIL_JOURS=${SEUIL_JOURS:-7}
MARQUES=/var/lib/piles-test
RACINE="$(cd "$(dirname "$0")/.." && pwd)"
VRAI=0
[ "${1:-}" = "--pour-de-vrai" ] && VRAI=1

MAINTENANT=$(date +%s)
CANDIDATS=""
NB_GARDEES=0

echo "── Récupération des piles de test · seuil ${SEUIL_JOURS} jour(s) · $(date -u '+%Y-%m-%d %H:%M:%SZ')"

mkdir -p "$MARQUES" 2>/dev/null || true

for CONTENEUR in $(docker ps --format '{{.Names}}' | grep -- '-db$' | sort); do
    JETON=${CONTENEUR%-db}

    if docker ps --format '{{.Names}}' | grep -qx "${JETON}-run"; then
        printf '  gardée      %-16s une suite tourne\n' "$JETON"
        NB_GARDEES=$((NB_GARDEES + 1))
        continue
    fi

    MARQUE="$MARQUES/$JETON"

    if [ ! -f "$MARQUE" ]; then
        date -u +%s > "$MARQUE" 2>/dev/null || true
        printf '  gardée      %-16s aucune marque — le compte commence maintenant\n' "$JETON"
        NB_GARDEES=$((NB_GARDEES + 1))
        continue
    fi

    QUAND=$(cat "$MARQUE" 2>/dev/null)
    case "$QUAND" in
        ''|*[!0-9]*)
            printf '  gardée      %-16s marque illisible\n' "$JETON"
            NB_GARDEES=$((NB_GARDEES + 1))
            continue
            ;;
    esac

    HEURES=$(( (MAINTENANT - QUAND) / 3600 ))

    if [ "$HEURES" -ge $(( SEUIL_JOURS * 24 )) ]; then
        printf '  À DÉMONTER  %-16s inactive depuis %d j %d h\n' "$JETON" "$(( HEURES / 24 ))" "$(( HEURES % 24 ))"
        CANDIDATS="$CANDIDATS $JETON"
    else
        printf '  gardée      %-16s inactive depuis %d j %d h\n' "$JETON" "$(( HEURES / 24 ))" "$(( HEURES % 24 ))"
        NB_GARDEES=$((NB_GARDEES + 1))
    fi
done

NB=$(echo $CANDIDATS | wc -w)
echo "  ${NB} à démonter, ${NB_GARDEES} gardée(s). Réseaux bridge : $(docker network ls --filter driver=bridge -q | wc -l) / 256."

[ "$NB" -eq 0 ] && exit 0

if [ "$VRAI" -eq 0 ]; then
    echo "  SIMULATION — rien n'a été touché. Relance avec --pour-de-vrai."
    exit 0
fi

for JETON in $CANDIDATS; do
    echo "  ── démontage de $JETON"
    (cd "$RACINE" && ./infra/test-stack.sh down "$JETON" 2>&1 | tail -1 | sed 's/^/     /')
done

echo "  Réseaux bridge restants : $(docker network ls --filter driver=bridge -q | wc -l)"
