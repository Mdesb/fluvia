#!/bin/sh
#
# L'APPELANT PÉRIODIQUE QUI MANQUAIT.
#
# Vingt-deux tâches sont cataloguées, `platform:scheduler:run` sait les exécuter, et **rien ne
# l'appelait**. Mesuré le 31/08 : les vingt-deux à « JAMAIS ». Ce n'était pas un retard, c'était une
# absence — deux élévations de privilèges qui n'expiraient pas, et des délégations de droits périmées
# qui restaient actives.
#
# ── LA LISTE BLANCHE EST LE CŒUR DE CE FICHIER, PAS LA BOUCLE ───────────────────────────────────
#
# On n'appelle PAS `platform:scheduler:run` nu : il exécuterait les vingt-deux. On nomme celles que
# Maxime a autorisées, une par une, et l'ajout d'une ligne est une décision qui se voit en revue.
#
# ⚠ `--only` CONTOURNE LA GARDE `safeOnFirstRun`. La commande considère qu'un appel nommément ciblé
# est supervisé, donc elle ne refuse plus le premier passage d'une tâche qui rattraperait tout
# l'historique d'un coup. Cette liste ne doit donc contenir QUE des tâches sûres au premier passage —
# la garde qui protégeait ailleurs ne protège pas ici.
#
# ⚠ EN PARTICULIER, `reservation:no-show:basculer` NE DOIT JAMAIS Y ENTRER (D95). Aucun écran n'écrit
# la présence : `isPresenceConfirmee()` est faux pour toute réservation ayant jamais existé, donc la
# tâche facturerait une absence à des gens venus. Six en base aujourd'hui, vingt sur un créneau réel.
# La condition de levée est qu'un écran appelle `/emarger` — pas avant.
#
# ── CE QU'IL FAUT SAVOIR AVANT D'EN AJOUTER UNE ─────────────────────────────────────────────────
#
# `./infra/ordonnanceur.sh --lister` dit ce qui est autorisé ici.
# `php bin/console platform:scheduler:run --status` dit ce qui a tourné, quand, et avec quels échecs.
#
# Le second est la seule réponse à « est-ce que ça tourne vraiment ? ». Un ordonnanceur qui démarre
# sans erreur ne prouve pas qu'il exécute quoi que ce soit : il remplace une absence CONNUE par une
# présence SUPPOSÉE, et c'est un échange perdant.
set -eu

# ── LES TÂCHES AUTORISÉES ───────────────────────────────────────────────────────────────────────
#
# Arbitrées par Maxime le 31/08, relayées par allaccess-b8. Les deux seules à la fois critiques et
# sans effet au dehors : elles révoquent des droits qui auraient dû l'être, et se défont en
# réattribuant.
#
#   securite:delegations:expirer      5 min · une délégation dont la date de fin est passée reste
#                                             active aujourd'hui
#   autorisation:escalades:expirer    5 min · une élévation accordée pour une opération reste ouverte
#
# Le catalogue le dit sans détour : « ne pas tourner ici n'est pas un retard, c'est une faille ».
# Chaque tache entre ici APRES avoir ete vue mordre ET epargner. Une tache planifiee tourne sans
# personne devant l'ecran : son sur-declenchement ne se voit que chez le client qu'elle a leve.
#
#   securite:delegations:expirer      une delegation de droits qui n'expire jamais
#   autorisation:escalades:expirer    une elevation temporaire qui ne se termine jamais
#   boutique:liberer-paniers-expires  un panier abandonne retient sa place indefiniment ; les
#                                     billets qu'il bloque ne sont vendus a personne.
#                                     Epargne prouvee le 31/08 : borne temporelle retiree ->
#                                     `testElleEpargneUnPanierEncoreValide` echoue seul.
#   personnel:recalculer-fenetres-badges  la fenetre de validite d'un badge staff ne suit plus les
#                                     shifts : un agent garde un acces qu'il n'a plus, ou perd
#                                     celui qu'il devrait avoir.
#                                     ⚠ Le cron appelle `recalculerTous()`, qui prend TOUS les
#                                     badges actifs -- le tri des modes se fait dedans, dans une
#                                     branche que rien ne testait. Un badge PERMANENT s'y serait
#                                     retrouve borne dans le passe : quelqu'un qui ne peut plus
#                                     entrer dans le batiment, toutes les cinq minutes, en silence.
#                                     Epargne prouvee le 31/08 : branche `Permanent` neutralisee
#                                     -> le test neuf echoue seul.
TACHES_AUTORISEES="securite:delegations:expirer autorisation:escalades:expirer boutique:liberer-paniers-expires personnel:recalculer-fenetres-badges"

INTERVALLE="${ORDONNANCEUR_INTERVALLE:-60}"

# ⚠ LA LISTE QUE CE SHELL PORTE N'EST PAS FORCEMENT CELLE DU FICHIER.
#
# L'affectation ci-dessus s'evalue une fois, au demarrage. La boucle tourne ensuite sur la variable.
# Ajouter une tache au fichier ne change donc RIEN tant que le conteneur n'a pas redemarre -- et le
# journal continue d'afficher un succes par tache de l'ancienne liste, ce qui se lit exactement
# comme un ordonnanceur en bonne sante.
#
# Le 31/08, `personnel:recalculer-fenetres-badges` est resté inerte neuf heures pour cette raison,
# pendant que le journal disait « ok » trois fois par minute.
#
# On fige donc ce que ce shell porte, et on le compare au fichier a chaque cycle. La divergence
# devient une ERREUR repetee, pas un silence.
LISTE_AU_DEMARRAGE="$TACHES_AUTORISEES"

liste_du_fichier() {
    # L'affectation est sur une seule ligne, entre guillemets doubles. On prend la premiere.
    sed -n 's/^TACHES_AUTORISEES="\(.*\)"$/\1/p' "$0" | head -1
}

if [ "${1:-}" = "--lister" ]; then
    echo "Tâches autorisées dans cet ordonnanceur :"
    for t in $TACHES_AUTORISEES; do
        echo "  · $t"
    done
    echo
    echo "⚠ Cette liste est celle du FICHIER. Un conteneur déjà démarré porte la sienne, figée à"
    echo "  son démarrage : il faut le redémarrer pour qu'un ajout prenne effet. La boucle le crie"
    echo "  à chaque cycle quand les deux divergent."
    echo
    echo "Ce qui a réellement tourné :"
    echo "  php bin/console platform:scheduler:run --status"
    exit 0
fi

# ⚠ REFUS EXPLICITE PLUTÔT QUE LISTE VIDE. Une boucle qui tourne sur rien ressemble en tout point à
# une boucle qui travaille : mêmes journaux, même conteneur vivant, même « up » dans `docker ps`.
if [ -z "$TACHES_AUTORISEES" ]; then
    echo "✗ Aucune tâche autorisée : cet ordonnanceur n'aurait rien à faire." >&2
    echo "  Une boucle qui tourne sur une liste vide se lit comme une boucle qui travaille." >&2
    exit 1
fi

echo "[ordonnanceur] démarrage · intervalle ${INTERVALLE}s · $(echo "$TACHES_AUTORISEES" | wc -w) tâche(s)"
for t in $TACHES_AUTORISEES; do
    echo "[ordonnanceur]   · $t"
done

while true; do
    # ── LA LISTE A-T-ELLE BOUGE SOUS NOS PIEDS ? ────────────────────────────────────────────────
    # Ce controle ne repare rien : il refuse seulement que l'ecart reste muet. Il coute un `sed` par
    # cycle, contre neuf heures d'une tache que tout le monde croyait active.
    au_fichier="$(liste_du_fichier)"
    if [ -n "$au_fichier" ] && [ "$au_fichier" != "$LISTE_AU_DEMARRAGE" ]; then
        echo "[ordonnanceur] ✗ LISTE PERIMEE — ce conteneur exécute $(echo "$LISTE_AU_DEMARRAGE" | wc -w) tâche(s), le fichier en déclare $(echo "$au_fichier" | wc -w)." >&2
        echo "[ordonnanceur]   porté par ce shell : $LISTE_AU_DEMARRAGE" >&2
        echo "[ordonnanceur]   déclaré au dépôt   : $au_fichier" >&2
        echo "[ordonnanceur]   Tant que ce conteneur n'a pas redémarré, l'écart ne tourne PAS." >&2
    fi

    for tache in $TACHES_AUTORISEES; do
        # `set -e` est actif : sans cette forme, l'échec d'une tâche tuerait la boucle et arrêterait
        # les autres. Les tâches sont indépendantes — laisser la première en échec empêcher la
        # seconde transformerait un incident en panne.
        code=0
        php bin/console platform:scheduler:run --only="$tache" || code=$?

        # ⚠ ON NE FAIT PAS SILENCE SUR L'ÉCHEC. Un « || true » nu transformerait une panne en
        # comportement : la boucle continuerait, le conteneur resterait vert, et personne ne saurait.
        # L'échec est aussi compté dans la trace, que `--status` affiche.
        if [ "$code" -ne 0 ]; then
            echo "[ordonnanceur] ÉCHEC $tache (code $code) — détail : platform:scheduler:run --status" >&2
        fi
    done

    sleep "$INTERVALLE"
done
