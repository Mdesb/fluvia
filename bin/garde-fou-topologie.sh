#!/usr/bin/env bash
#
# Garde-fou n°0 — topologie : par où passent les commits de ce worktree ? (D28, D29)
#
# **C'est le garde-fou qui s'exécute avant les autres, parce qu'il conditionne leur existence.** Les
# sept contrôles suivants ne valent que s'ils sont *traversés*. Le 24/08, six sessions de la flotte
# ont été créées comme worktrees du dépôt **nu** : leurs commits entraient directement dans les refs
# partagées, **sans push, donc sans `pre-receive`**. Sept garde-fous verts, et rien qui les exécute.
#
# Une session qui écrit sans garde-fou est pire qu'une session à l'arrêt : elle donne l'illusion du
# contrôle. D'où le refus de démarrer plutôt que l'avertissement.
#
# ── L'INVARIANT ─────────────────────────────────────────────────────────────────────────────────
#
# Tout commit qui atteint les refs partagées doit franchir une barrière. Il n'y a que deux chemins :
#
#   1. **Le worktree appartient à un CLONE** — ses commits n'atteignent le dépôt partagé que par un
#      `git push`, donc par `pre-receive`, qui n'est pas contournable côté client. C'est le cas normal
#      d'une session de travail.
#
#   2. **Le worktree appartient au dépôt NU lui-même** — commiter met à jour la ref partagée
#      immédiatement, sans push. Aucun `pre-receive` ne s'exécute jamais. Le seul contrôle possible
#      est `pre-commit`, et il est contournable (`--no-verify`).
#
# Le cas 2 est **toléré pour le seul worktree d'intégration** (`main`), parce que l'intégrateur y
# fusionne et que `pre-commit` y est installé exprès. Il est **refusé pour toute session de travail** :
# c'est exactement la configuration qui a rendu six sessions invisibles aux garde-fous.
#
# ── CE QUE CE CONTRÔLE NE PRÉTEND PAS FAIRE ─────────────────────────────────────────────────────
#
# Il vérifie le *chemin*, pas la bonne foi. `--no-verify` sur le worktree d'intégration, ou un
# `push --force` (interdit par D30), restent hors de sa portée. Il répond à une seule question, et il
# y répond de façon vérifiable : « ce que j'écris ici peut-il atteindre les autres sans être vu ? »

set -uo pipefail

RACINE="$(pwd)"

if ! git rev-parse --git-dir >/dev/null 2>&1; then
    echo "Topologie : SANS OBJET — pas un dépôt git."
    exit 0
fi

COMMUN="$(git rev-parse --git-common-dir 2>/dev/null)"
case "$COMMUN" in /*) ;; *) COMMUN="$RACINE/$COMMUN" ;; esac
COMMUN="$(readlink -f "$COMMUN")"

EST_NU="$(git -C "$COMMUN" rev-parse --is-bare-repository 2>/dev/null || echo inconnu)"
BRANCHE="$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo '?')"
ORIGINE="$(git remote get-url origin 2>/dev/null || echo '')"

# ── Cas 2 : worktree du dépôt nu ────────────────────────────────────────────────────────────────
if [ "$EST_NU" = "true" ]; then
    if [ "$BRANCHE" = "main" ]; then
        if [ -x "$COMMUN/hooks/pre-commit" ]; then
            echo "Topologie : OK — worktree d'intégration sur le dépôt nu, contrôlé par pre-commit."
            exit 0
        fi
        echo
        echo "=== ÉCHEC — worktree d'intégration sans pre-commit ==="
        echo
        echo "  Ce worktree appartient au dépôt nu ($COMMUN) : commiter met à jour la ref partagée"
        echo "  SANS push, donc sans que \`pre-receive\` ne s'exécute jamais. Le seul contrôle possible"
        echo "  est \`pre-commit\`, et il n'est pas installé."
        echo
        echo "  Installe-le :  bash bin/installer-hooks.sh $COMMUN --pre-commit"
        exit 1
    fi

    # Ce qui reste comme contrôle dépend d'un fait, pas d'une supposition : y a-t-il un `pre-commit`
    # installé sur ce dépôt ? Le 25/08 ce message annonçait « aucune barrière » dans les deux cas,
    # alors que `pre-commit` s'exécutait bel et bien pour claude-G — sept garde-fous, code 0. Le refus
    # restait juste, la raison était fausse, et un garde-fou qui exagère son constat use la confiance
    # qu'on lui accorde autant que celui qui donne un mauvais conseil.
    echo
    if [ -x "$COMMUN/hooks/pre-commit" ]; then
        echo "=== ÉCHEC — cette session contourne la seule barrière non contournable ==="
    else
        echo "=== ÉCHEC — cette session écrit sans franchir aucune barrière ==="
    fi
    echo
    echo "  Worktree      : $RACINE (branche $BRANCHE)"
    echo "  Dépôt commun  : $COMMUN  ← c'est le dépôt NU"
    echo "  origin        : ${ORIGINE:-aucun}"
    echo
    echo "  Commiter ici met à jour la ref partagée immédiatement, sans push. \`pre-receive\` ne"
    echo "  s'exécute donc JAMAIS. C'est ce qui est arrivé à six sessions le 24/08."
    echo
    if [ -x "$COMMUN/hooks/pre-commit" ]; then
        echo "  Ce qui reste : \`pre-commit\` EST installé sur ce dépôt et il tourne. Mais il se"
        echo "  contourne côté client — \`git commit --no-verify\` suffit — là où \`pre-receive\` ne se"
        echo "  contourne pas. Et il ne lance ni le contrôle de topologie ni celui du manifeste."
    else
        echo "  Et RIEN ne le remplace : aucun \`pre-commit\` sur ce dépôt. Ce que tu écris entre dans"
        echo "  les refs partagées sans qu'aucun garde-fou ne tourne — ils sont verts et personne ne"
        echo "  les lance."
    fi
    echo
    echo "  Une session de travail doit être un worktree d'un CLONE, dont l'origine est le dépôt nu."
    echo "  Seul le worktree d'intégration (\`main\`) a le droit d'être sur le nu, et il est contrôlé"
    echo "  par \`pre-commit\`."
    echo
    echo "  Recrée ce worktree depuis le clone, puis reprends ton travail (rien n'est perdu : commite"
    echo "  d'abord, ta branche est déjà dans le dépôt)."
    exit 1
fi

# ── Cas 1 : worktree d'un clone ─────────────────────────────────────────────────────────────────
if [ -z "$ORIGINE" ]; then
    echo
    echo "=== ÉCHEC — aucun remote « origin » ==="
    echo
    echo "  Worktree     : $RACINE (branche $BRANCHE)"
    echo "  Dépôt commun : $COMMUN"
    echo
    echo "  Sans origin, rien de ce qui est écrit ici n'atteint les autres — et rien n'est contrôlé."
    echo "  Déclare le dépôt nu :  git remote add origin /home/debian/billetterie.git"
    exit 1
fi

# L'origine est-elle un dépôt local dont on peut inspecter les hooks ? En CI elle ne l'est pas, et
# c'est normal : la barrière y est le workflow, pas `pre-receive`. On ne transforme pas une
# différence d'environnement en échec.
if [ ! -d "$ORIGINE" ]; then
    echo "Topologie : OK — origin « $ORIGINE » n'est pas un dépôt local (CI ?), pre-receive non vérifiable."
    exit 0
fi

if [ ! -x "$ORIGINE/hooks/pre-receive" ]; then
    echo
    echo "=== ÉCHEC — le dépôt d'origine n'a pas de pre-receive installé ==="
    echo
    echo "  origin : $ORIGINE"
    echo
    echo "  Les push depuis ce worktree ne seraient contrôlés par rien. Installe la barrière :"
    echo "      bash bin/installer-hooks.sh $ORIGINE"
    exit 1
fi

echo "Topologie : OK — worktree d'un clone, push contrôlé par pre-receive sur $ORIGINE."
exit 0
