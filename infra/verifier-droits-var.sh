#!/bin/sh
#
# `/app/var` DOIT APPARTENIR A www-data. SINON LA PREPRODUCTION REND 500, ET PAS TOUT DE SUITE.
#
# ── CE QUI EST ARRIVE LE 01/09 ──────────────────────────────────────────────────────────────────
#
# J'ai lance `cache:clear` seul, en root, pour deboguer un garde-fou. `/app/var/cache/prod` est
# repasse a root ; PHP-FPM tourne en www-data et ne pouvait plus reecrire son cache de routage.
# Chaque requete retentait, echouait, rendait 500 : TOUTE l'API, pendant environ quarante minutes.
#
# La commande etait juste. Ce qui manquait etait APRES elle : `deploy-preprod.sh` fait suivre ce meme
# `cache:clear` d'un `chown -R www-data:www-data /app/var` (ligne 170).
#
# ⚠ ET LE `cache:clear` N'ETAIT QUE LA VERSION BRUYANTE DU PROBLEME.
#
# CHAQUE `docker compose exec -T php php bin/console ...` s'execute en ROOT et laisse des entrees
# root dans `/app/var/cache/prod/pools`. Mesure du 01/09, apres un deploiement propre : sept
# fichiers, tous ecrits par les commandes de diagnostic de la session en cours. Ils ne cassent rien
# tant que personne n'a besoin de les REECRIRE -- et le jour ou FPM en a besoin, c'est un 500.
#
# Autrement dit la preproduction n'est pas « saine » ou « cassee » : elle est AMORCEE. Un cache
# complet masque entierement le probleme, parce que FPM ne fait que lire. Le premier defaut de cache
# le revele. C'est pour ca que ce controle tourne a chaque cycle de l'ordonnanceur et pas seulement
# au deploiement : la derive se produit ENTRE les deploiements, pas pendant.
#
# ── CE SCRIPT NE REPARE PAS TOUT SEUL ───────────────────────────────────────────────────────────
#
# `--reparer` existe, mais il faut le demander. Un chown automatique a chaque cycle effacerait la
# seule trace qu'une commande a ete lancee hors du script -- on reparerait le symptome en supprimant
# la preuve, et personne n'apprendrait jamais que le geste manquait.
#
# ── DEUX CONTEXTES, UN SEUL FICHIER ─────────────────────────────────────────────────────────────
#
# L'ordonnanceur tourne DANS le conteneur (`/app/var` existe) ; un humain l'appelle depuis l'hote
# (il faut passer par `docker compose exec`). Le script detecte lequel des deux, pour qu'il n'y ait
# jamais deux versions de la meme regle a maintenir en parallele.
set -eu

ATTENDU="www-data"
CIBLE="/app/var"

usage() {
    echo "usage: $0 [--reparer] [--temoin]"
    echo
    echo "  (sans option)  compte les entrees de $CIBLE qui n'appartiennent pas a $ATTENDU."
    echo "                 Sort 1 s'il y en a. Ne modifie rien."
    echo "  --reparer      applique chown -R $ATTENDU:$ATTENDU $CIBLE. A demander explicitement."
    echo "  --temoin       prouve que le detecteur sait VOIR et sait SE TAIRE (voir plus bas)."
}

# Execute une commande shell dans le bon contexte.
dans_le_conteneur() {
    if [ -d "$CIBLE" ]; then
        sh -c "$1"
    else
        REPO="$(cd "$(dirname "$0")/.." && pwd)"
        cd "$REPO"
        docker compose -f infra/compose.preprod.yaml --env-file infra/.env.preprod \
            exec -T php sh -c "$1"
    fi
}

compter() {
    dans_le_conteneur "find $CIBLE ! -user $ATTENDU 2>/dev/null | wc -l" | tr -d ' \r'
}

# ── LE TEMOIN ───────────────────────────────────────────────────────────────────────────────────
#
# ⚠ UN DETECTEUR QUI SIGNALE SE PROUVE PAR CE QU'IL EPARGNE AUTANT QUE PAR CE QU'IL ATTRAPE.
#
# Un `find` mal ecrit -- mauvais chemin, `-user` sur un nom inexistant, `maxdepth` de trop -- rend
# zero, et zero se lit comme « tout va bien ». J'ai fait exactement cette faute une heure avant
# d'ecrire ce fichier : un `-maxdepth 2` excluait `cache/prod`, qui est a la profondeur 3. La mesure
# disait « aucun ecart » sur un arbre qui en portait quarante mille.
#
# Le temoin pose donc un fichier root VOLONTAIRE et exige que le compte MONTE, puis le retire et
# exige qu'il redescende. Les deux moities comptent : la premiere prouve qu'il voit, la seconde
# qu'il ne signale pas au hasard.
temoin() {
    base="$(compter)"
    echo "temoin · ligne de base : $base entree(s) hors $ATTENDU"

    marque="$CIBLE/.temoin-droits-$$"
    dans_le_conteneur "install -o root -g root -m 644 /dev/null $marque"

    avec="$(compter)"
    echo "temoin · avec un fichier root pose : $avec"
    if [ "$avec" -le "$base" ]; then
        dans_le_conteneur "rm -f $marque"
        echo "✗ TEMOIN EN ECHEC : le detecteur n'a pas vu un fichier root qu'on venait de poser." >&2
        echo "  Tout zero qu'il rendrait serait donc sans valeur." >&2
        exit 1
    fi

    dans_le_conteneur "rm -f $marque"
    apres="$(compter)"
    echo "temoin · apres retrait : $apres"
    if [ "$apres" -ne "$base" ]; then
        echo "✗ TEMOIN EN ECHEC : le compte n'est pas revenu a sa ligne de base ($base -> $apres)." >&2
        echo "  Le detecteur compte autre chose que ce qu'il annonce." >&2
        exit 1
    fi

    echo "✓ Temoin : le detecteur voit ce qu'il doit voir, et se tait sur le reste."
    exit 0
}

case "${1:-}" in
    --temoin) temoin ;;
    -h|--help) usage; exit 0 ;;
esac

n="$(compter)"

if [ "${1:-}" = "--reparer" ]; then
    if [ "$n" -eq 0 ]; then
        echo "Rien a reparer : $CIBLE appartient entierement a $ATTENDU."
        exit 0
    fi
    dans_le_conteneur "chown -R $ATTENDU:$ATTENDU $CIBLE"
    apres="$(compter)"
    if [ "$apres" -ne 0 ]; then
        echo "✗ Le chown a tourne et il reste $apres entree(s) hors $ATTENDU." >&2
        exit 1
    fi
    echo "✓ Repare : $n entree(s) rendues a $ATTENDU."
    exit 0
fi

if [ "$n" -eq 0 ]; then
    echo "Droits sur var/ : OK — tout $CIBLE appartient a $ATTENDU."
    exit 0
fi

echo "✗ DROITS SUR var/ : $n entree(s) de $CIBLE n'appartiennent pas a $ATTENDU." >&2
dans_le_conteneur "find $CIBLE ! -user $ATTENDU -printf '    %u %p\n' 2>/dev/null | head -8" >&2
echo >&2
echo "  PHP-FPM tourne en $ATTENDU. Il ne peut pas REECRIRE ces entrees — il peut encore les lire," >&2
echo "  donc l'API repond tant que le cache est complet. Le premier defaut de cache rend 500." >&2
echo >&2
echo "  Cause quasi certaine : une commande lancee en root dans le conteneur (cache:clear," >&2
echo "  composer, bin/console) sans le chown qui la suit dans deploy-preprod.sh." >&2
echo >&2
echo "      ./infra/verifier-droits-var.sh --reparer" >&2
exit 1
