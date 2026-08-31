#!/usr/bin/env bash
#
# Réinstalle les hooks du dépôt nu quand `main` en porte une version différente (D28).
#
# ── POURQUOI CE SCRIPT EST SÉPARÉ DES HOOKS ─────────────────────────────────────────────────────
#
# `main` change de deux façons, et une seule était couverte :
#
#   · par **push** — une branche de session poussée puis fusionnée ailleurs. `post-receive` s'exécute.
#   · par **merge dans `wt/main`** — l'intégrateur fusionne dans son worktree, la ref est mise à jour
#     immédiatement, **sans réception**. `post-receive` ne s'exécute JAMAIS.
#
# Le reflog de `main` du 25/08 ne contient que des `merge` : le second chemin est *le* chemin normal,
# et ma réinstallation automatique y était inerte depuis le premier jour. C'est l'avertissement
# d'obsolescence qui l'a signalé — le filet a rattrapé la défaillance du mécanisme qu'il doublait.
#
# La logique vit donc ici, appelée par `post-receive`, `post-commit` et `post-merge`. Trois
# déclencheurs, **un seul énoncé** : c'est la leçon des listes redites qui se désynchronisent.
#
# ── GARDE-CORPS ─────────────────────────────────────────────────────────────────────────────────
#
#   1. On n'agit que si le dépôt commun est **nu** — c'est là que vivent les hooks partagés.
#   2. `bash -n` avant remplacement : un hook fautif n'est pas installé, celui en place est conservé.
#   3. Sauvegarde en `<hook>.precedent`, restaurable par un simple `cp`.
#   4. `mv` et jamais `cp` : bash lit un script au fur et à mesure, écraser l'inode d'un script en
#      cours d'exécution fait interpréter la suite depuis les nouveaux octets à l'ancien décalage.
#   5. L'interrupteur `GARDE-FOUS-DESACTIVES` est respecté : si quelqu'un a mis la barrière hors
#      service, ce n'est pas à ce script de la remettre.
#
# Sortie toujours 0 : il ne doit jamais faire échouer l'opération qui l'a déclenché.

set -uo pipefail

COMMUN="$(git rev-parse --git-common-dir 2>/dev/null)" || exit 0
case "$COMMUN" in /*) ;; *) COMMUN="$(pwd)/$COMMUN" ;; esac
COMMUN="$(readlink -f "$COMMUN")"

# ⚠ Appelé depuis un hook, git a exporté GIT_DIR, GIT_INDEX_FILE et consorts — et **l'environnement
# l'emporte sur `git -C` et `--git-dir`**. Sans ce nettoyage, le test « ce dépôt est-il nu ? »
# interrogeait le répertoire du worktree au lieu du dépôt commun, répondait « false », et le script
# sortait en silence : réinstallation jamais faite, aucun message. Encore un mécanisme inerte qui ne
# le disait pas — la panne exacte que ce fichier existe pour empêcher.
#
# On relève le chemin AVANT de nettoyer : c'est la seule information qu'on tire de l'environnement.
unset GIT_DIR GIT_WORK_TREE GIT_INDEX_FILE GIT_QUARANTINE_PATH GIT_PREFIX GIT_OBJECT_DIRECTORY

[ -f "$COMMUN/hooks/GARDE-FOUS-DESACTIVES" ] && exit 0

# ── DEPOT NU ET CHECKOUT DE TRAVAIL : DEUX JEUX DE HOOKS, PAS UN ────────────────────────────────
#
# Ce script sortait ici si le depot commun n'etait pas nu. Il ne rafraichissait donc QUE les hooks
# du depot nu -- et le `pre-commit` des checkouts de travail, la ou tout le monde commite en
# realite, gelait indefiniment.
#
# Constate le 29/08 : le `pre-commit` installe dans le checkout de preprod datait d'avant l'ajout du
# garde-fou de vacuite. Celui-ci etait present dans `bin/` depuis des heures et n'avait jamais ete
# lance par un commit local. Rien ne cassait, rien n'alertait : un nouveau garde-fou etait
# simplement inerte, chez tout le monde, jusqu'au prochain push.
#
# Le `pre-receive` du depot nu reste le vrai filet -- celui qu'on ne peut pas contourner. Mais le
# `pre-commit` est le retour RAPIDE : il dit la faute pendant qu'on l'ecrit, pas dix commits plus
# tard. Le laisser geler, c'est garder le filet et perdre l'avertissement.
#
# Les hooks installes different selon le cas, parce que leur nature differe : `pre-receive` et
# `post-receive` ne s'executent qu'a la reception et n'ont aucun sens dans un checkout ; `pre-commit`
# doit y etre pose meme s'il n'y etait pas encore, alors que sur le depot nu on ne l'ajoute que s'il
# y est deja -- l'ajouter la serait un changement de comportement, pas une mise a jour.
#
# ⚠ Pour un worktree lie, `--git-common-dir` designe le `.git` du depot principal : les hooks sont
# donc PARTAGES entre tous les worktrees d'un meme checkout. Un seul rafraichissement les couvre.
if [ "$(git -C "$COMMUN" rev-parse --is-bare-repository 2>/dev/null)" = "true" ]; then
    HOOKS="pre-receive post-receive post-commit post-merge"
    [ -f "$COMMUN/hooks/pre-commit" ] && HOOKS="$HOOKS pre-commit"
else
    # NON NU : seuls les hooks cote client, et `pre-commit` inconditionnellement.
    HOOKS="pre-commit post-commit post-merge"
fi

for nom in $HOOKS; do
    CIBLE="$COMMUN/hooks/$nom"
    SAUVEGARDE="$COMMUN/hooks/$nom.precedent"

    CANDIDAT="$(mktemp "$COMMUN/hooks/.$nom.nouveau.XXXXXX")" || continue

    if ! git --git-dir="$COMMUN" show "refs/heads/main:hooks/$nom" > "$CANDIDAT" 2>/dev/null \
       || [ ! -s "$CANDIDAT" ]; then
        rm -f "$CANDIDAT"          # `main` ne versionne pas ce hook : ne rien installer, ne rien supprimer.
        continue
    fi

    if cmp -s "$CANDIDAT" "$CIBLE"; then
        rm -f "$CANDIDAT"          # déjà à jour : le cas normal, on reste silencieux.
        continue
    fi

    if ! bash -n "$CANDIDAT" 2>/dev/null; then
        rm -f "$CANDIDAT"
        echo ""
        echo "⚠ RÉINSTALLATION REFUSÉE : hooks/$nom de main ne passe pas \`bash -n\`."
        echo "⚠ Le hook installé est CONSERVÉ — périmé, mais fonctionnel."
        continue
    fi

    AVANT="-"
    if [ -f "$CIBLE" ]; then
        AVANT="$(grep -o 'garde-fou-[a-z-]*\.php' "$CIBLE" 2>/dev/null | sort -u | wc -l | tr -d ' ')"
        cp "$CIBLE" "$SAUVEGARDE"
    fi
    APRES="$(grep -o 'garde-fou-[a-z-]*\.php' "$CANDIDAT" 2>/dev/null | sort -u | wc -l | tr -d ' ')"

    chmod +x "$CANDIDAT"
    if ! mv -f "$CANDIDAT" "$CIBLE"; then
        rm -f "$CANDIDAT"
        echo "✗ Réinstallation de hooks/$nom ÉCHOUÉE — version précédente dans $SAUVEGARDE."
        continue
    fi

    echo ""
    echo "✓ hooks/$nom réinstallé automatiquement depuis main (D28)."
    if [ "$nom" = "pre-receive" ] || [ "$nom" = "pre-commit" ]; then
        echo "  Garde-fous référencés : $AVANT → $APRES."
    fi
    echo "  Version précédente conservée : $SAUVEGARDE"
done

exit 0
