#!/usr/bin/env bash
#
# Ramasseur de piles de test — supprime ce que personne n'a démonté (D30, incident du 24/08).
#
# ── LE PROBLÈME ─────────────────────────────────────────────────────────────────────────────────
#
# `infra/test-stack.sh` sait monter et démonter. **Le démontage existe comme commande, et personne ne
# l'appelle.** Le 24/08 au matin, vingt-six piles oubliées ont saturé le pool d'adresses Docker et
# plus personne n'a pu tester. Le 24/08 au soir, vingt-deux piles, **27 réseaux sur les ~31** que le
# pool par défaut permet : quatre créneaux avant la même panne.
#
# C'est le troisième mécanisme de la journée qui existe sans tourner, après le garde-fou de topologie
# et la réinstallation des hooks. Le motif se répète : une commande qu'il faut penser à lancer n'est
# pas un mécanisme, c'est une intention.
#
# ── CE QUI DÉCIDE, ET POURQUOI CE N'EST PAS L'ÂGE ───────────────────────────────────────────────
#
# La crainte légitime est de tuer une pile **sous une session qui teste** : elle y perdrait son
# verdict. L'âge de création ne répond pas à cette crainte — une pile montée il y a cinq jours peut
# avoir servi il y a dix minutes.
#
# On lit donc la **dernière activité** : l'horodatage de la dernière ligne de journal du conteneur de
# base. Une suite qui tourne écrit ; une pile abandonnée est muette. À défaut de journal, on retombe
# sur la date de création, et on le dit dans la sortie plutôt que de le taire.
#
# ── CE QU'IL NE TOUCHE JAMAIS ───────────────────────────────────────────────────────────────────
#
# La préprod et Vespera ne sont pas des piles de test. Elles sont exclues **par nom**, et le contrôle
# est fait avant toute autre chose : une erreur ici ne coûte pas un verdict de test, elle coûte un
# service.
#
# Usage :
#   bin/ramasser-piles-test.sh                 # montre ce qui serait ramassé, ne touche à rien
#   bin/ramasser-piles-test.sh --faire         # ramasse
#   bin/ramasser-piles-test.sh --age=2 --faire # seuil d'inactivité en heures (défaut 6)
#   bin/ramasser-piles-test.sh --moi=claudeC   # se limite à ses propres piles

set -uo pipefail

RACINE="$(cd "$(dirname "$0")/.." && pwd)"
DEMONTEUR="$RACINE/infra/test-stack.sh"

# Protégées par nom. Tout ce qui n'a pas la forme d'une pile de test est de toute façon ignoré ; cette
# liste est la seconde barrière, pas la première.
PROTEGEES="billetterie-preprod vespera"

SEUIL_HEURES=6
FAIRE=0
MOI=""

for opt in "$@"; do
    case "$opt" in
        --faire)   FAIRE=1 ;;
        --age=*)   SEUIL_HEURES="${opt#--age=}" ;;
        --moi=*)   MOI="${opt#--moi=}" ;;
        -h|--help) sed -n '2,40p' "$0"; exit 0 ;;
        *) echo "option inconnue : $opt" >&2; exit 2 ;;
    esac
done

if ! command -v docker >/dev/null 2>&1; then
    echo "docker indisponible — rien à ramasser."
    exit 0
fi

TOTAL_RESEAUX="$(docker network ls -q | wc -l | tr -d ' ')"
MAINTENANT="$(date +%s)"
SEUIL_S=$((SEUIL_HEURES * 3600))

echo "Réseaux Docker : $TOTAL_RESEAUX (le pool par défaut en permet ~31)."
echo "Seuil d'inactivité : ${SEUIL_HEURES} h. Mode : $([ "$FAIRE" -eq 1 ] && echo 'RAMASSAGE' || echo 'simulation, rien ne sera supprimé')."
echo

RAMASSEES=0
GARDEES=0

for reseau in $(docker network ls --format '{{.Name}}' | sort); do
    # Forme d'une pile de test, et rien d'autre.
    case "$reseau" in
        *-net) ;;
        *) continue ;;
    esac

    jeton="${reseau%-net}"

    protegee=0
    for p in $PROTEGEES; do
        case "$jeton" in "$p"*) protegee=1 ;; esac
    done
    if [ "$protegee" -eq 1 ]; then
        printf "  %-14s PROTÉGÉE (%s)\n" "$jeton" "ce n'est pas une pile de test"
        continue
    fi

    [ -n "$MOI" ] && [ "$jeton" != "$MOI" ] && continue

    conteneur="${jeton}-db"

    # Dernière activité. Trois sources, de la plus parlante à la plus faible — et on DIT laquelle a
    # servi, parce qu'un verdict rendu sur la source faible ne vaut pas celui rendu sur la bonne.
    #
    # 1. Le mtime des fichiers InnoDB. C'est le seul témoin réel : le moteur les touche quand la suite
    #    travaille. Sur une pile montée à 03:32 et utilisée jusqu'à 03:49, il donne 03:49.
    # 2. Le journal du conteneur. ⚠ MariaDB écrit sur STDERR — un `2>/dev/null` ici rend le signal
    #    toujours vide et fait retomber en silence sur la source faible. C'était le cas de ma première
    #    version. Et même lu correctement, il ne porte que des lignes de DÉMARRAGE : il ne distingue
    #    pas une pile utilisée d'une pile oubliée. Gardé en second recours, pas mieux.
    # 3. La date de création. Elle ne dit rien de l'usage : une pile de cinq jours a pu servir il y a
    #    dix minutes. Dernier recours assumé.
    source_date="innodb"
    ts="$(docker exec "$conteneur" sh -c 'stat -c %Y /var/lib/mysql/ibdata1 2>/dev/null' 2>/dev/null | tr -d '\r')"

    if ! [ "${ts:-0}" -gt 0 ] 2>/dev/null; then
        source_date="journal"
        dernier="$(docker logs --tail 1 -t "$conteneur" 2>&1 | tail -1 | awk '{print $1}')"
        ts="$(date -d "$dernier" +%s 2>/dev/null || echo 0)"
    fi

    if ! [ "${ts:-0}" -gt 0 ] 2>/dev/null; then
        source_date="création"
        dernier="$(docker inspect -f '{{.Created}}' "$conteneur" 2>/dev/null)"
        ts="$(date -d "$dernier" +%s 2>/dev/null || echo 0)"
    fi

    if ! [ "${ts:-0}" -gt 0 ] 2>/dev/null; then
        source_date="réseau"
        dernier="$(docker network inspect -f '{{.Created}}' "$reseau" 2>/dev/null)"
        ts="$(date -d "$dernier" +%s 2>/dev/null || echo 0)"
    fi

    ts="${ts:-0}"
    if [ "$ts" -eq 0 ]; then
        printf "  %-14s GARDÉE — date d'activité illisible, on ne devine pas\n" "$jeton"
        GARDEES=$((GARDEES + 1))
        continue
    fi

    inactif=$(( (MAINTENANT - ts) / 3600 ))

    if [ "$inactif" -lt "$SEUIL_HEURES" ]; then
        printf "  %-14s GARDÉE — active il y a %s h (%s)\n" "$jeton" "$inactif" "$source_date"
        GARDEES=$((GARDEES + 1))
        continue
    fi

    if [ "$FAIRE" -eq 0 ]; then
        printf "  %-14s à ramasser — inactive depuis %s h (%s)\n" "$jeton" "$inactif" "$source_date"
        RAMASSEES=$((RAMASSEES + 1))
        continue
    fi

    printf "  %-14s ramassage (inactive %s h, %s) : " "$jeton" "$inactif" "$source_date"
    if [ -x "$DEMONTEUR" ]; then
        # On passe par le démonteur existant plutôt que par `docker rm` : c'est lui qui sait ce qu'une
        # pile comporte, et une seconde implémentation finirait par diverger de la première.
        if "$DEMONTEUR" down "$jeton" >/dev/null 2>&1; then
            echo "démontée"
            RAMASSEES=$((RAMASSEES + 1))
        else
            echo "ÉCHEC du démontage — laissée en place"
            GARDEES=$((GARDEES + 1))
        fi
    else
        echo "ÉCHEC : $DEMONTEUR introuvable"
        GARDEES=$((GARDEES + 1))
    fi
done

echo
if [ "$FAIRE" -eq 0 ]; then
    echo "$RAMASSEES pile(s) seraient ramassées, $GARDEES gardée(s)."
    [ "$RAMASSEES" -gt 0 ] && echo "Pour le faire : $0 --age=$SEUIL_HEURES --faire"
else
    echo "$RAMASSEES pile(s) ramassées, $GARDEES gardée(s)."
    echo "Réseaux restants : $(docker network ls -q | wc -l | tr -d ' ')."
fi

exit 0
