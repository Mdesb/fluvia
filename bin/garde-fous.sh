#!/usr/bin/env bash
#
# Lance tous les garde-fous (C4). À exécuter depuis la racine du dépôt.
#
#   ./bin/garde-fous.sh                  # contrôle local
#   ./bin/garde-fous.sh origin/main      # contrôle avec cliquet contre une référence (CI)
#
# Ne s'arrête pas au premier échec : on veut la liste complète de ce qu'il y a à corriger,
# pas un défaut à la fois. Code de sortie 1 si au moins un garde-fou échoue.

set -uo pipefail

REFERENCE="${1:-}"

# Référence du contrôle de nommage. `origin/main` n'existe pas partout : un worktree du dépôt nu
# — `/home/debian/wt/main`, là où se font les intégrations — n'a aucun remote. Le garde-fou
# refusait alors de s'exécuter, à juste titre (il ne veut pas rendre un vert qui ne veut rien
# dire), mais le résultat était un lanceur inutilisable à l'endroit qui compte le plus. On prend
# la première référence qui existe réellement, et on dit laquelle.
REFERENCE_NOMMAGE="$REFERENCE"
if [ -z "$REFERENCE_NOMMAGE" ]; then
    for candidat in origin/main main HEAD; do
        if git rev-parse --verify --quiet "$candidat" >/dev/null 2>&1; then
            REFERENCE_NOMMAGE="$candidat"
            break
        fi
    done
    [ "$REFERENCE_NOMMAGE" != "origin/main" ] && [ -n "$REFERENCE_NOMMAGE" ] \
        && echo "· Nommage : « origin/main » introuvable ici, référence retenue : « $REFERENCE_NOMMAGE »."
fi
ECHECS=0
TOTAL=0

# PHP : binaire local s'il existe, sinon l'image du projet (le VPS n'a pas de PHP hors conteneur).
#
# Le montage conteneur n'est pas anodin. Dans un worktree git, `.git` est un *fichier* qui pointe vers
# le dépôt principal par chemin absolu ; monter seulement le worktree rend donc `git` inopérant à
# l'intérieur, et le cliquet `--contre` ne peut plus lire sa référence. On monte le répertoire courant
# à son chemin réel (et non sous /repo) et, s'il s'agit d'un worktree, le dépôt commun avec lui.
RACINE="$(pwd)"
SANS_PHP_LOCAL=0

if ! command -v php >/dev/null 2>&1; then
    SANS_PHP_LOCAL=1
    MONTAGES="-v $RACINE:$RACINE"
    if [ -f .git ]; then
        COMMUN="$(sed -n 's/^gitdir: //p' .git | sed 's#/worktrees/.*##')"
        [ -n "$COMMUN" ] && MONTAGES="$MONTAGES -v $COMMUN:$COMMUN"
    fi
    # Sans le nom de l'image : il doit rester le DERNIER argument de `docker run`, sinon les options
    # qui suivent (`-w`) sont passées à la commande du conteneur au lieu de docker.
    DOCKER_BASE="docker run --rm --network none -u $(id -u):$(id -g) $MONTAGES"
    IMAGE="billetterie-preprod-php"
fi

# Deux répertoires de travail : le garde-fou tourne depuis la racine (il lit app/src et bin/),
# phpunit depuis app/ (sa configuration y vit). D'où deux lanceurs plutôt qu'un `cd` global.
php_racine() {
    if [ "$SANS_PHP_LOCAL" -eq 1 ]; then
        $DOCKER_BASE -w "$RACINE" "$IMAGE" php "$@"
    else
        (cd "$RACINE" && php "$@")
    fi
}

php_app() {
    if [ "$SANS_PHP_LOCAL" -eq 1 ]; then
        $DOCKER_BASE -w "$RACINE/app" "$IMAGE" php "$@"
    else
        (cd "$RACINE/app" && php "$@")
    fi
}

executer() {
    local nom="$1"; shift
    TOTAL=$((TOTAL + 1))
    echo "─────────────────────────────────────────────────────────────"
    echo "▶ $nom"
    echo "─────────────────────────────────────────────────────────────"
    if "$@"; then
        return 0
    fi
    ECHECS=$((ECHECS + 1))
    return 1
}

# 0. TOPOLOGIE — il passe avant les autres parce qu'il conditionne leur existence.
#
# Les sept contrôles suivants ne valent que s'ils sont TRAVERSÉS. Le 24/08, six sessions de la
# flotte étaient des worktrees du dépôt nu : leurs commits entraient dans les refs partagées sans
# push, donc sans `pre-receive`. Sept garde-fous verts, et rien qui les exécute.
#
# Il refuse de démarrer plutôt que d'avertir : une session qui écrit sans barrière est pire
# qu'une session à l'arrêt, elle donne l'illusion du contrôle. Demandé par l'intégrateur le 24/08.
if [ -x "$RACINE/bin/garde-fou-topologie.sh" ]; then
    if ! executer "Topologie (D28/D29)" bash "$RACINE/bin/garde-fou-topologie.sh"; then
        echo
        echo "─────────────────────────────────────────────────────────────"
        echo "✗ Arrêt immédiat : la topologie ne garantit pas que les contrôles seront exécutés."
        echo "  Les lancer maintenant produirait un vert qui ne protège personne."
        exit 1
    fi
fi

# 1. Cloisonnement (D3/D8) — le garde-fou n°1.
if [ -n "$REFERENCE" ]; then
    executer "Cloisonnement (D3/D8)" php_racine bin/garde-fou-cloisonnement.php "--contre=$REFERENCE"
else
    executer "Cloisonnement (D3/D8)" php_racine bin/garde-fou-cloisonnement.php
fi

# 2. Manifeste (RG-PLAT-06) — déjà couvert côté noyau par ManifestCatalogueTest : on l'appelle,
#    on ne le réimplémente pas (consigne de l'intégrateur, MESSAGES.md du 19/08).
if [ -f app/vendor/bin/phpunit ]; then
    executer "Manifeste vs catalogue (RG-PLAT-06)" \
        php_app vendor/bin/phpunit --filter ManifestCatalogueTest
else
    echo "─────────────────────────────────────────────────────────────"
    echo "▶ Manifeste vs catalogue (RG-PLAT-06)"
    echo "─────────────────────────────────────────────────────────────"
    echo "IGNORÉ : app/vendor absent. Installe les dépendances de dev, sinon ce contrôle ne tourne pas."
    echo "         ./infra/test-stack.sh up <token>"
fi

# 3. Nommage anglais (D5) — uniquement sur les fichiers AJOUTÉS : l'existant est français et le
#    reste jusqu'au retrofit. Contrairement au n°1, celui-ci n'a pas eu besoin de ligne de base :
#    il ne trouve rien sur le neuf existant, donc il s'installe au vert.
executer "Nommage anglais (D5)" php_racine bin/garde-fou-nommage-anglais.php "--contre=$REFERENCE_NOMMAGE"

# 4. Aucun secret cryptographique en valeur par défaut.
#    Contrairement au n°1, celui-ci n'a pas de ligne de base et n'en aura pas : une clé en dur n'est
#    pas une dette qu'on étale, c'est un secret publié. Il est ROUGE tant que
#    Facturation/Nf525/ScellementFactureHandler n'est pas passé à #[Autowire(env:)] — c'est voulu.
executer "Secrets en dur" php_racine bin/garde-fou-secrets.php

# 5. Couverture de périmètre en LECTURE. Les quatre précédents surveillent les écritures ; celui-ci
#    surveille ce qu'aucun d'eux ne pouvait voir — une entité exposée que rien ne permet de filtrer.
#    C'est ce trou qui laissait `GET /ecritures-comptables` renvoyer le grand livre de tous les
#    établissements : pas de Processor fautif, juste aucune extension.
executer "Couverture de périmètre (lecture)" php_racine bin/garde-fou-couverture-perimetre.php

# 6. Événements du catalogue sans émetteur (D2/D22). Deux régimes délibérément distincts : un abonné
#    qui écoute un événement que personne német est un ÉCHEC DUR, sans ligne de base — cest le seul
#    défaut du projet quaucun test ne peut attraper, parce quun abonné inerte ne casse rien, il ne
#    fait rien. Le stock de noms déclarés-mais-pas-encore-émis, lui, est légitime (D2, le contrat
#    précède le code) : on le gèle et on le fait décroître. RR-1 et SF-1 sont ce décompte.
if [ -n "${REFERENCE:-}" ]; then
    executer "Événements orphelins (D2/D22)" php_racine bin/garde-fou-evenements-orphelins.php "--contre=$REFERENCE"
else
    executer "Événements orphelins (D2/D22)" php_racine bin/garde-fou-evenements-orphelins.php
fi

# 7. Conformité des charges utiles au catalogue (D2). Le n°6 vérifie qu'un événement annoncé finit
#    par être émis ; celui-ci vérifie qu'il est émis avec ce qui a été promis. La panne visée est la
#    même famille, silencieuse : un abonné codé contre le catalogue qui lit `null` parce que la clé
#    ne porte pas le nom annoncé. Aucun test ne peut l'attraper — RG-PLAT-06 ne lit que les noms
#    d'événements, jamais la colonne charge utile.
#    La comparaison se fait sur une forme canonique : le catalogue est en snake_case et le code en
#    camelCase sur TOUT le dépôt, refuser cet écart rendrait le contrôle insatisfaisable.
if [ -n "${REFERENCE:-}" ]; then
    executer "Charges utiles vs catalogue (D2)" php_racine bin/garde-fou-charges-utiles.php "--contre=$REFERENCE"
else
    executer "Charges utiles vs catalogue (D2)" php_racine bin/garde-fou-charges-utiles.php
fi

# 8. Écriture qui traverse la frontière (D3/D8). Une entité que rien ne permet de cloisonner ne doit
#    pas porter une relation ÉCRIVABLE vers une entité qui, elle, l'est : il n'y a alors de frontière
#    ni en lecture ni en écriture. Ces cas échappent au n°1 par construction — il cherche un `find()`
#    depuis l'entrée client dans un Processor, or il n'y a pas de Processor : le sérialiseur
#    désérialise l'IRI directement dans l'entité. Né de `SousReseau`, un accès fédéré dont la
#    ManyToMany vers `EspaceAcces` est écrivable et pilote un franchissement de porte.
if [ -n "${REFERENCE:-}" ]; then
    executer "Écriture transfrontière (D3/D8)" php_racine bin/garde-fou-ecriture-transfrontiere.php "--contre=$REFERENCE"
else
    executer "Écriture transfrontière (D3/D8)" php_racine bin/garde-fou-ecriture-transfrontiere.php
fi

# 5. i18n : pas de chaîne d'UI en dur — SANS OBJET tant que la couche i18n n'existe pas (aucun
#    catalogue, aucun usage du traducteur dans app/src). Acté par l'intégrateur le 21/08.
# 6. CSRF — SANS OBJET : tous les pare-feux sont `stateless: true` et l'authentification est un JWT
#    en en-tête, qui n'est pas un identifiant ambiant. Acté par l'intégrateur le 21/08.

echo "─────────────────────────────────────────────────────────────"
if [ "$ECHECS" -gt 0 ]; then
    echo "✗ $ECHECS garde-fou(s) en échec sur $TOTAL."
    exit 1
fi
echo "✓ $TOTAL garde-fou(s) OK."
