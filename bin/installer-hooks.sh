#!/usr/bin/env bash
#
# Installe (ou retire) le hook `pre-receive` sur le dépôt bare — C16.
#
#   ./bin/installer-hooks.sh /home/debian/billetterie.git
#   ./bin/installer-hooks.sh /home/debian/billetterie.git --retirer
#   ./bin/installer-hooks.sh /home/debian/billetterie.git --etat
#
# Le hook est VERSIONNÉ dans hooks/ et copié à l'installation : un hook qui ne vit que sur le serveur
# est un bout de logique que personne ne relit, que personne ne teste, et que la prochaine
# réinstallation efface sans laisser de trace.

set -euo pipefail

BARE="${1:-}"
ACTION="${2:-installer}"

if [ -z "$BARE" ]; then
    echo "usage: $0 <chemin-du-depot-bare> [--retirer|--etat]" >&2
    echo "       $0 <chemin-d-un-worktree>  --pre-commit [--retirer]" >&2
    exit 2
fi

# ─── Qui est réellement couvert ? ───────────────────────────────────────────────────────────
#
# ⚠ Ce VPS héberge DEUX dépôts, et la distinction n'est pas cosmétique :
#
#   /home/debian/billetterie.git       le dépôt nu (origin) — `wt/main` en est un worktree
#   /home/debian/billetterie/.git      un clone du précédent — `wt/claude-*` en sont les worktrees
#
# Les hooks vivent dans le répertoire COMMUN du dépôt, et git ne lit jamais ceux d'un autre. Un
# `pre-commit` posé sur le nu couvre donc `wt/main` et personne d'autre ; posé sur le clone, il
# couvre les worktrees des instances et pas `main`. Le 24/08 j'ai annoncé à l'intégrateur que
# l'installation engagerait claude-B : c'était faux, faute d'avoir vu qu'il y a deux dépôts.
#
# D'où cette fonction : ne jamais annoncer « installé » sans dire pour qui.
worktrees_couverts() { # worktrees_couverts <repertoire-commun>
    local commun="$1" w liste=""
    for w in /home/debian/wt/*/; do
        [ -d "$w" ] || continue
        local c; c="$(git -C "$w" rev-parse --git-common-dir 2>/dev/null)" || continue
        case "$c" in /*) ;; *) c="$w$c" ;; esac
        if [ "$(readlink -f "$c")" = "$(readlink -f "$commun")" ]; then
            liste="$liste $(basename "$w")"
        fi
    done
    [ -n "$liste" ] && echo "$liste" || echo " (aucun worktree connu)"
}

# ─── pre-commit : pour un WORKTREE, pas pour le bare ───────────────────────────────────────────
# `pre-receive` ne s'exécute que sur un push. Quand une branche est checked out dans un worktree du
# bare, qui y commite met à jour la référence sans push — donc sans contrôle. C'est le cas de `main`
# depuis qu'il vit dans /home/debian/wt/main.
if [ "$ACTION" = "--pre-commit" ]; then
    # ⚠ Le répertoire COMMUN, pas `--git-dir`. Pour un worktree, `--git-dir` renvoie
    # `<bare>/worktrees/<nom>`, et git n'y cherche JAMAIS les hooks : il les lit dans le
    # répertoire commun. Un `pre-commit` posé à l'ancien emplacement était installé, visible,
    # documenté — et silencieusement ignoré. Vérifié le 24/08 sur un dépôt jetable : le hook
    # per-worktree laisse passer le commit, celui du répertoire commun s'exécute.
    #
    # Conséquence assumée : le hook devient commun à tous les worktrees du dépôt. L'interrupteur
    # reste per-worktree — `$(git rev-parse --git-dir)/GARDE-FOUS-DESACTIVES` — donc chacun peut
    # se retirer sans priver les autres.
    GITDIR="$(git -C "$BARE" rev-parse --git-common-dir 2>/dev/null)" || {
        echo "✗ $BARE n'est pas un dépôt git." >&2
        exit 2
    }
    # Chemin relatif renvoyé pour un worktree principal : on le rend absolu.
    case "$GITDIR" in /*) ;; *) GITDIR="$BARE/$GITDIR" ;; esac

    SOURCE_PC="$(cd "$(dirname "$0")/.." && pwd)/hooks/pre-commit"
    CIBLE_PC="$GITDIR/hooks/pre-commit"

    if [ "${3:-}" = "--retirer" ]; then
        rm -f "$CIBLE_PC"
        echo "✓ pre-commit retiré de $BARE."
        exit 0
    fi

    mkdir -p "$GITDIR/hooks"
    cp "$SOURCE_PC" "$CIBLE_PC"
    chmod +x "$CIBLE_PC"
    echo "✓ pre-commit installé : $CIBLE_PC"
    echo "  Worktrees couverts :$(worktrees_couverts "$GITDIR")"
    echo "  Les autres appartiennent à l'AUTRE dépôt et ne sont pas concernés — voir l'en-tête."
    echo
    echo "  Il contrôle les commits faits dans les worktrees de ce dépôt — ceux que pre-receive"
    echo "  ne voit jamais, faute de push. Pour t'en retirer sans priver les autres :"
    echo "      touch \$(git rev-parse --git-dir)/GARDE-FOUS-DESACTIVES"
    echo "  Contournable par « git commit --no-verify » : c'est un filet, pas une barrière."
    exit 0
fi

if [ ! -d "$BARE" ] || [ ! -d "$BARE/hooks" ]; then
    echo "✗ $BARE ne ressemble pas à un dépôt bare (pas de hooks/)." >&2
    exit 2
fi

RACINE_HOOKS="$(cd "$(dirname "$0")/.." && pwd)/hooks"
SOURCE="$RACINE_HOOKS/pre-receive"
CIBLE="$BARE/hooks/pre-receive"
# `post-receive` réinstalle `pre-receive` quand main le modifie (D28). Il doit être posé ici,
# sinon le hook censé corriger « versionné mais jamais installé » souffre de ce défaut même.
SOURCE_POST="$RACINE_HOOKS/post-receive"
CIBLE_POST="$BARE/hooks/post-receive"
INTERRUPTEUR="$BARE/hooks/GARDE-FOUS-DESACTIVES"

case "$ACTION" in
    --etat)
        # ⚠ Sans ce contrôle, `--etat` lancé sur un worktree lit le dossier `hooks/` VERSIONNÉ du
        # projet — la source — et annonce « hook installé ». C'est un faux positif du genre que
        # ces scripts existent pour éliminer : il rend un vert au nom d'une installation qui n'a
        # pas eu lieu. Les hooks serveur ne vivent que sur un dépôt nu.
        if [ "$(git -C "$BARE" rev-parse --is-bare-repository 2>/dev/null)" != "true" ]; then
            echo "cible     : $BARE n'est pas un dépôt nu."
            echo "            pre-receive et post-receive n'y existent pas — ils vivent sur le dépôt nu."
            COMMUN_WT="$(git -C "$BARE" rev-parse --git-common-dir 2>/dev/null)"
            case "$COMMUN_WT" in /*) ;; *) COMMUN_WT="$BARE/$COMMUN_WT" ;; esac
            if [ -f "$COMMUN_WT/hooks/pre-commit" ]; then
                echo "pre-commit: installé ($COMMUN_WT/hooks/pre-commit)"
                echo "            couvre :$(worktrees_couverts "$COMMUN_WT")"
            else
                echo "pre-commit: absent pour ce dépôt — les commits faits sans push ne sont pas contrôlés."
                echo "            couvrirait :$(worktrees_couverts "$COMMUN_WT")"
            fi
            exit 0
        fi
        if [ -f "$CIBLE" ]; then
            echo "hook      : installé ($CIBLE)"
            if cmp -s "$SOURCE" "$CIBLE"; then
                echo "version   : à jour"
            else
                echo "version   : DIFFÉRENTE de hooks/pre-receive — réinstalle pour synchroniser"
            fi
        else
            echo "hook      : absent"
        fi
        if [ -f "$CIBLE_POST" ]; then
            if cmp -s "$SOURCE_POST" "$CIBLE_POST"; then
                echo "post-recv : installé, à jour"
            else
                echo "post-recv : installé, DIFFÉRENT de hooks/post-receive"
            fi
        else
            echo "post-recv : absent — la réinstallation automatique (D28) ne tourne pas"
        fi
        COMMUN_ETAT="$(git -C "$BARE" rev-parse --git-common-dir 2>/dev/null)"
        case "$COMMUN_ETAT" in /*) ;; *) COMMUN_ETAT="$BARE/$COMMUN_ETAT" ;; esac
        if [ -f "$COMMUN_ETAT/hooks/pre-commit" ]; then
            echo "pre-commit: installé ($COMMUN_ETAT/hooks/pre-commit)"
            echo "            couvre :$(worktrees_couverts "$COMMUN_ETAT")"
        else
            echo "pre-commit: absent — les commits faits sans push ne sont pas contrôlés"
        fi
        if [ -f "$INTERRUPTEUR" ]; then
            echo "état      : DÉSACTIVÉ par $INTERRUPTEUR"
            echo "            posé le $(stat -c %y "$INTERRUPTEUR" 2>/dev/null | cut -d. -f1)"
        else
            echo "état      : actif"
        fi
        ;;

    --retirer)
        rm -f "$CIBLE" "$CIBLE_POST"
        echo "✓ Hooks retirés. Les push ne sont plus contrôlés, et la réinstallation"
        echo "  automatique (D28) ne tourne plus non plus."
        ;;

    installer)
        if [ ! -f "$SOURCE" ]; then
            echo "✗ Source introuvable : $SOURCE" >&2
            exit 2
        fi
        cp "$SOURCE" "$CIBLE"
        chmod +x "$CIBLE"
        echo "✓ Hook installé : $CIBLE"
        if [ -f "$SOURCE_POST" ]; then
            cp "$SOURCE_POST" "$CIBLE_POST"
            chmod +x "$CIBLE_POST"
            echo "✓ Hook installé : $CIBLE_POST (réinstallation automatique, D28)"
        fi
        echo
        echo "  Il refuse un push dont les garde-fous échouent — pour tout le monde, sans recours"
        echo "  côté client (--no-verify n'agit pas sur pre-receive)."
        echo
        echo "  Sortie de secours : touch $INTERRUPTEUR"
        echo "  Si elle sert, dis pourquoi dans COORDINATION/MESSAGES.md — sinon elle devient permanente."
        ;;

    *)
        echo "action inconnue : $ACTION (attendu: --retirer, --etat, ou rien)" >&2
        exit 2
        ;;
esac
