#!/usr/bin/env bash
#
# Superviseur d'une instance Claude — la relance tant qu'elle a du travail.
#
#   ./infra/superviseur-claude.sh claude-B
#   ./infra/superviseur-claude.sh claude-C
#
# Pourquoi ce script existe : les instances ne s'arrêtent pas au hasard, elles s'arrêtent quand leur
# session se termine (crédits, durée) ou quand elles attendent l'intégrateur. Aucune consigne ne
# relance une session terminée — il faut un processus extérieur.
#
# Trois garde-fous, chacun payé par une mésaventure réelle :
#   1. Un VERROU par identité. Deux instances de la même identité écrivant le même worktree, c'est
#      arrivé le 19/08 entre deux claude-A : elles ne se sont pas écrasées par chance, pas par
#      conception, et personne ne l'a vu avant d'inspecter le disque.
#   2. Un DÉLAI CROISSANT si la session meurt vite. Sans crédits, `claude -p` échoue en deux
#      secondes ; une boucle serrée ferait tourner la machine à vide toute la nuit.
#   3. Un FICHIER D'ARRÊT. « Comment on l'éteint » est la première question qu'on se pose à 3 h du
#      matin, et `kill -9` sur un agent en plein push est une mauvaise réponse.

set -uo pipefail

IDENTITE="${1:-}"
if [[ ! "$IDENTITE" =~ ^claude-[A-C]$ ]]; then
    echo "usage: $0 <claude-A|claude-B|claude-C>" >&2
    exit 2
fi

RACINE=/home/debian
LANCEUR="$RACINE/${IDENTITE}.sh"
DOSSIER="$RACINE/superviseur"
VERROU="$DOSSIER/${IDENTITE}.lock"
ARRET="$DOSSIER/STOP-${IDENTITE}"
JOURNAL="$DOSSIER/${IDENTITE}.log"

[[ -x "$LANCEUR" ]] || { echo "lanceur introuvable ou non exécutable : $LANCEUR" >&2; exit 2; }
mkdir -p "$DOSSIER"

# --- Garde-fou 1 : une seule instance par identité -----------------------------------------------
exec 9>"$VERROU"
if ! flock -n 9; then
    echo "Un superviseur tourne déjà pour $IDENTITE. Deux instances de la même identité" >&2
    echo "écriraient le même worktree — c'est exactement la collision qu'on veut éviter." >&2
    exit 1
fi

# --- La consigne permanente ----------------------------------------------------------------------
# Chaque exécution repart de zéro : la consigne doit se suffire à elle-même.
CONSIGNE="Tu es ${IDENTITE} sur le projet billetterie. Ton worktree est /home/debian/wt/${IDENTITE}.

Au démarrage, dans cet ordre :
1. git fetch origin && git rebase origin/main
2. Lis COORDINATION/MESSAGES.md en entier — les messages qui te sont adressés priment sur tout le reste.
3. Lis COORDINATION/TASKS.md et prends la tâche qui t'est assignée.

Ensuite tu travailles, tu testes (./infra/test-stack.sh up <ton token> puis run), tu commites avec un
staging explicite — jamais git add -A — et tu POUSSES : git push origin ${IDENTITE}. Un commit non
poussé est invisible de tous, ça s'est déjà produit.

Puis tu signales ce que tu as fait dans COORDINATION/MESSAGES.md, avec le décompte exact de tes tests
(N tests, M assertions, et le nombre de skips s'il y en a — un test qui skippe n'est pas un test qui
passe).

RÈGLE ESSENTIELLE : ne t'arrête JAMAIS en attendant une réponse de l'intégrateur. Si ta tâche
principale dépend de son arbitrage, pose ta question dans MESSAGES.md ET prends immédiatement ta tâche
de repli, indiquée dans ton brief ou à défaut la première tâche libre du tableau qui touche ton
périmètre. Tu poses la question, tu n'attends pas la réponse.

N'écris que dans les dossiers dont tu es propriétaire selon COORDINATION/OWNERS.md. Pour toucher au
module d'un autre, annonce-le dans MESSAGES.md d'abord."

# --- La boucle ------------------------------------------------------------------------------------
echecs=0
cycle=0

echo "=== superviseur $IDENTITE démarré le $(date '+%d/%m %H:%M:%S') ===" >> "$JOURNAL"

while true; do
    if [[ -f "$ARRET" ]]; then
        echo "=== arrêt demandé ($ARRET) le $(date '+%d/%m %H:%M:%S') ===" >> "$JOURNAL"
        break
    fi

    cycle=$((cycle + 1))
    debut=$(date +%s)
    echo "--- cycle $cycle démarré le $(date '+%d/%m %H:%M:%S') ---" >> "$JOURNAL"

    "$LANCEUR" -p "$CONSIGNE" >> "$JOURNAL" 2>&1
    code=$?

    duree=$(( $(date +%s) - debut ))
    echo "--- cycle $cycle terminé (code $code, ${duree}s) ---" >> "$JOURNAL"

    # Garde-fou 2 : une session qui meurt en moins d'une minute n'a pas travaillé. Crédits épuisés,
    # authentification expirée, erreur de configuration — on espace au lieu d'insister.
    if (( duree < 60 )); then
        echecs=$((echecs + 1))
        exposant=$(( echecs > 5 ? 5 : echecs ))
        attente=$(( 60 * (2 ** exposant) ))   # 2 min, 4, 8, 16, 32 min — plafonné
        echo "    session trop courte (${duree}s), $echecs d'affilée — pause de $((attente / 60)) min" >> "$JOURNAL"
    else
        echecs=0
        attente=15
    fi

    sleep "$attente"
done

echo "=== superviseur $IDENTITE arrêté le $(date '+%d/%m %H:%M:%S') ===" >> "$JOURNAL"
