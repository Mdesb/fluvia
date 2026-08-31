#!/usr/bin/env bash
# ==============================================================================================
#  Création de la flotte — worktrees, configurations isolées, lanceurs, hooks.
#
#  Idempotent : relançable autant de fois que voulu, il ne recrée que ce qui manque et ne touche
#  jamais à ce qui existe. C'est volontaire — un script d'amorçage qu'on n'ose pas relancer est un
#  script qu'on finit par exécuter à la main, une étape sur deux.
#
#  Usage :  bash infra/creer-flotte.sh            # crée tout ce qui manque
#           bash infra/creer-flotte.sh --verifier # ne crée rien, dit seulement ce qui manque
# ==============================================================================================

set -uo pipefail

BARE="/home/debian/billetterie.git"
CLONE="/home/debian/billetterie"
WT="/home/debian/wt"
CFG="/home/debian/claude-cfg"
LANCEURS="/home/debian"

NOUVELLES="D E F G H I"
VERIFIER=0
[ "${1:-}" = "--verifier" ] && VERIFIER=1

vert()  { printf '  \033[32m✓\033[0m %s\n' "$1"; }
jaune() { printf '  \033[33m·\033[0m %s\n' "$1"; }
rouge() { printf '  \033[31m✗\033[0m %s\n' "$1"; }

echo "══════════════════════════════════════════════════════════════"
echo "  Création de la flotte — six sessions"
[ "$VERIFIER" = 1 ] && echo "  MODE VÉRIFICATION : rien ne sera créé"
echo "══════════════════════════════════════════════════════════════"

# --- Contrôles préalables ---------------------------------------------------------------------
echo
echo "── Préalables"
MANQUE=0
[ -d "$BARE" ] || { rouge "dépôt nu introuvable : $BARE"; MANQUE=1; }
command -v git >/dev/null || { rouge "git absent"; MANQUE=1; }
command -v docker >/dev/null || jaune "docker absent — les sessions ne pourront pas tester"
[ "$MANQUE" = 1 ] && { echo; rouge "Préalables non réunis, arrêt."; exit 1; }
vert "dépôt nu présent"
vert "git disponible"

# --- Worktrees --------------------------------------------------------------------------------
echo
echo "── Worktrees"
for L in $NOUVELLES; do
    ID="claude-$L"
    CHEMIN="$WT/$ID"

    if [ -d "$CHEMIN" ]; then
        jaune "$ID — déjà présent, laissé tel quel"
        continue
    fi
    if [ "$VERIFIER" = 1 ]; then
        jaune "$ID — à créer"
        continue
    fi

    # La branche part de `main` : chaque session démarre sur l'état intégré, jamais sur une autre
    # branche de travail dont elle hériterait les lots en vol.
    if git --git-dir="$BARE" show-ref --verify --quiet "refs/heads/$ID"; then
        git -C "$CLONE" worktree add "$CHEMIN" "$ID" >/dev/null 2>&1
    else
        git -C "$CLONE" worktree add "$CHEMIN" -b "$ID" main >/dev/null 2>&1
    fi

    if [ -d "$CHEMIN" ]; then
        # Identité de commit, sinon le premier commit échoue sur "empty ident name".
        #
        # `--worktree` N'EST PAS FACULTATIF. `.git/config` est PARTAGÉ par tous les worktrees d'un
        # même dépôt : sans lui, chaque tour de boucle écrase le précédent et les neuf sessions
        # finissent avec l'identité de la dernière. C'est arrivé le 24/08 — trente commits attribués
        # à `claude-I` qui étaient ceux de G et de H. Trouvé par claude-H, confirmé par claude-G.
        git -C "$CLONE" config extensions.worktreeConfig true
        git -C "$CHEMIN" config --worktree user.name "$ID"
        git -C "$CHEMIN" config --worktree user.email "$ID@billetterie.local"
        vert "$ID — worktree créé sur la branche $ID"
    else
        rouge "$ID — échec de création"
    fi
done

# --- Configurations isolées -------------------------------------------------------------------
echo
echo "── Configurations d'authentification"
for L in $NOUVELLES; do
    ID="claude-$L"
    if [ -d "$CFG/$L" ]; then
        jaune "$L — déjà présent"
    elif [ "$VERIFIER" = 1 ]; then
        jaune "$L — à créer"
    else
        mkdir -p "$CFG/$L" && vert "$L — répertoire de configuration créé"
    fi
done
echo
jaune "Chaque session s'authentifie séparément à son premier lancement."

# --- Lanceurs ---------------------------------------------------------------------------------
echo
echo "── Lanceurs"
for L in $NOUVELLES; do
    ID="claude-$L"
    SCRIPT="$LANCEURS/$ID.sh"

    if [ -f "$SCRIPT" ]; then
        jaune "$ID.sh — déjà présent"
        continue
    fi
    if [ "$VERIFIER" = 1 ]; then
        jaune "$ID.sh — à créer"
        continue
    fi

    cat > "$SCRIPT" <<LANCEUR
#!/usr/bin/env bash
# Lanceur $ID — authentification isolée + son worktree.
export CLAUDE_CONFIG_DIR=$CFG/$L
# L'identité de commit, en plus de la config par worktree : ces variables l'emportent sur
# `user.name` et, contrairement à elle, survivent à un worktree recréé.
export GIT_AUTHOR_NAME=$ID
export GIT_AUTHOR_EMAIL=$ID@billetterie.local
export GIT_COMMITTER_NAME=$ID
export GIT_COMMITTER_EMAIL=$ID@billetterie.local
cd $WT/$ID
exec claude "\$@"
LANCEUR
    chmod +x "$SCRIPT"
    vert "$ID.sh — créé"
done

# --- Dépendances ------------------------------------------------------------------------------
echo
echo "── Dépendances PHP"
if [ "$VERIFIER" = 1 ]; then
    for L in $NOUVELLES; do
        [ -d "$WT/claude-$L/app/vendor" ] && jaune "claude-$L — vendor présent" || jaune "claude-$L — vendor à installer"
    done
else
    for L in $NOUVELLES; do
        ID="claude-$L"
        if [ -d "$WT/$ID/app/vendor" ]; then
            jaune "$ID — vendor déjà présent"
            continue
        fi
        if ! command -v docker >/dev/null; then
            jaune "$ID — docker absent, installation reportée"
            continue
        fi
        # Sans vendor, la session ne peut rien tester — et une session qui ne teste pas fusionne
        # à l'aveugle. On le fait ici plutôt que de le découvrir au premier lot.
        echo "     $ID : installation en cours…"
        (cd "$WT/$ID/app" && docker run --rm -v "$PWD":/app -w /app composer:2 \
            install --no-interaction --no-progress >/dev/null 2>&1) \
            && vert "$ID — dépendances installées" || rouge "$ID — échec de l'installation"
    done
fi

# --- Hooks ------------------------------------------------------------------------------------
echo
echo "── Hooks (D28 : versionnés ET installés)"
if [ "$VERIFIER" = 1 ]; then
    for H in pre-receive post-receive pre-commit; do
        if [ -f "$WT/main/hooks/$H" ] && diff -q "$WT/main/hooks/$H" "$BARE/hooks/$H" >/dev/null 2>&1; then
            vert "$H — installé et à jour"
        else
            jaune "$H — à (ré)installer"
        fi
    done
else
    for H in pre-receive post-receive pre-commit; do
        if [ -f "$WT/main/hooks/$H" ]; then
            cp "$WT/main/hooks/$H" "$BARE/hooks/$H" && chmod +x "$BARE/hooks/$H" && vert "$H — installé"
        fi
    done
fi

# --- Vérification finale ----------------------------------------------------------------------
echo
echo "── Vérification"
PRETES=0
for L in $NOUVELLES; do
    ID="claude-$L"
    OK=1
    [ -d "$WT/$ID" ] || OK=0
    [ -d "$CFG/$L" ] || OK=0
    [ -f "$LANCEURS/$ID.sh" ] || OK=0
    if [ "$OK" = 1 ]; then vert "$ID — prête"; PRETES=$((PRETES+1)); else rouge "$ID — incomplète"; fi
done

echo
echo "══════════════════════════════════════════════════════════════"
if [ "$VERIFIER" = 1 ]; then
    echo "  Vérification terminée. Relancer sans --verifier pour créer."
else
    echo "  $PRETES session(s) sur 6 prêtes."
    echo
    echo "  Lancement, un terminal par session :"
    for L in $NOUVELLES; do echo "      $LANCEURS/claude-$L.sh"; done
    echo
    echo "  Puis coller le brief de chacune (document de lancement)."
fi
echo "══════════════════════════════════════════════════════════════"
