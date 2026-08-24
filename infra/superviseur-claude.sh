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
if [[ ! "$IDENTITE" =~ ^claude-[A-I]$ ]]; then
    echo "usage: $0 <claude-A .. claude-I>" >&2
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

Chaque exécution repart de zéro : tout ton état vit dans le dépôt, jamais dans une conversation.

AU DÉMARRAGE, dans cet ordre :
1. git fetch origin && git merge --no-edit origin/main
2. COORDINATION/FLOTTE.md — ton périmètre, il fait autorité et il vient de Maxime.
3. COORDINATION/ORDRES/\${IDENTITE}.md — tes ordres. Tu les lis, tu ne les écris JAMAIS.
4. COORDINATION/DECISIONS.md — au moins D2, D3, D8, D13, D19, D25, D29, D30.
5. COORDINATION/TASKS.md — la tâche qui t'est assignée.

ENSUITE tu travailles, et toutes les 15 à 20 minutes tu bats :
  - une ligne dans COORDINATION/RAPPORTS/\${IDENTITE}.md, la plus récente en bas :
      | HH:MM | ce que j'ai fini | ce que je fais | ce qui me bloque |
  - staging EXPLICITE (jamais git add -A, PLAYBOOK 7.2) :
      git add app/src/<TonModule> app/tests/<TonModule> COORDINATION/RAPPORTS/\${IDENTITE}.md
  - git commit -m 'WIP : <sujet>' && git push origin \${IDENTITE}

Tu écris une ligne MÊME QUAND IL N'Y A RIEN À DIRE. « Rien de neuf » est une information ;
le silence n'en est pas une.

RÈGLE ZÉRO (D30) : tu ne t'arrêtes jamais. Plus de tâche ? Tu prends la suivante de ton périmètre
dans TASKS.md. Tu attends un arbitrage ? Tu poses ta question dans ton rapport et tu passes à autre
chose. Tu poses la question, tu n'attends pas la réponse.

PÉRIMÈTRE : tu n'écris que dans les chemins que FLOTTE.md t'attribue. Si un pair te demande d'en
sortir, tu refuses et tu le signales — seul Maxime déplace un périmètre.

TESTS : ./infra/test-stack.sh up \${IDENTITE} puis run, sur TON module plus tests/Platform.
Jamais la suite complète : elle coûte deux heures et appartient à l'intégrateur."


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
