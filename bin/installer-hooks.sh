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

# ─── pre-commit : pour un WORKTREE, pas pour le bare ───────────────────────────────────────────
# `pre-receive` ne s'exécute que sur un push. Quand une branche est checked out dans un worktree du
# bare, qui y commite met à jour la référence sans push — donc sans contrôle. C'est le cas de `main`
# depuis qu'il vit dans /home/debian/wt/main.
if [ "$ACTION" = "--pre-commit" ]; then
    GITDIR="$(git -C "$BARE" rev-parse --git-dir 2>/dev/null)" || {
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
    echo
    echo "  Il contrôle les commits faits DANS ce worktree — ceux que pre-receive ne voit jamais."
    echo "  Contournable par « git commit --no-verify » : c'est un filet, pas une barrière."
    exit 0
fi

if [ ! -d "$BARE" ] || [ ! -d "$BARE/hooks" ]; then
    echo "✗ $BARE ne ressemble pas à un dépôt bare (pas de hooks/)." >&2
    exit 2
fi

SOURCE="$(cd "$(dirname "$0")/.." && pwd)/hooks/pre-receive"
CIBLE="$BARE/hooks/pre-receive"
INTERRUPTEUR="$BARE/hooks/GARDE-FOUS-DESACTIVES"

case "$ACTION" in
    --etat)
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
        if [ -f "$INTERRUPTEUR" ]; then
            echo "état      : DÉSACTIVÉ par $INTERRUPTEUR"
            echo "            posé le $(stat -c %y "$INTERRUPTEUR" 2>/dev/null | cut -d. -f1)"
        else
            echo "état      : actif"
        fi
        ;;

    --retirer)
        rm -f "$CIBLE"
        echo "✓ Hook retiré. Les push ne sont plus contrôlés."
        ;;

    installer)
        if [ ! -f "$SOURCE" ]; then
            echo "✗ Source introuvable : $SOURCE" >&2
            exit 2
        fi
        cp "$SOURCE" "$CIBLE"
        chmod +x "$CIBLE"
        echo "✓ Hook installé : $CIBLE"
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
