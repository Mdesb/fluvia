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
LANCES=""
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

# ⚠ DEUX CONTROLES NE PEUVENT PAS PORTER LE MEME NUMERO.
#
# Le 30/08, deux sessions ont attribue « n°29 » a deux controles differents, le meme soir, sans se
# voir. Un compteur partage sans verrou, incremente par neuf sessions : le telescopage n'etait pas
# une faute, c'etait une question de temps.
#
# Le numero du LIBELLE est l'identite durable — c'est lui qu'on cite dans les messages de commit et
# qu'on cherche des mois plus tard. Un numero qui designe deux choses est donc un NOM QUI MENT,
# exactement la famille que ce depot traque partout ailleurs.
#
# ⚠ Une premiere version faisait numeroter le lanceur par POSITION. C'etait pire : les libelles
# portent deja leur numero, et l'affichage en montrait deux qui se contredisaient —
# `▶ n°28 — Classes CSS declarees (n°16)`. On ne renumerote donc pas : on refuse le doublon.
#
# Ce controle-la n'a pas de numero. Il porte un libelle, et c'est deliberement le seul.
verifier_numeros_uniques() {
    local doublons
    doublons="$(printf '%s\n' "$@" \
        | grep -oE 'n[°o][0-9]+' \
        | sort | uniq -d)"

    [ -z "$doublons" ] && return 0

    echo "═════════════════════════════════════════════════════════════" >&2
    echo "✗ NUMEROS EN DOUBLE dans les libelles de garde-fous :" >&2
    printf '    %s\n' $doublons >&2
    echo "" >&2
    echo "  Un numero qui designe deux controles est un nom qui ment : on le cite" >&2
    echo "  dans des messages de commit, et il ne designe plus rien." >&2
    echo "  Prends le suivant libre, et renomme celui qui n'est cite nulle part." >&2
    echo "═════════════════════════════════════════════════════════════" >&2

    return 1
}

LIBELLES=""

executer() {
    local nom="$1"; shift
    LIBELLES="$LIBELLES|$nom"
    TOTAL=$((TOTAL + 1))

    # Trace du SCRIPT réellement lancé, pour le filet de complétude en fin de course. On lit les
    # arguments plutôt que le libellé : le libellé est décoratif, le chemin ne ment pas.
    local arg
    for arg in "$@"; do
        case "$arg" in
            */garde-fou-*|garde-fou-*|bin/garde-fou-*)
                LANCES="$LANCES $(basename "$arg")"
                ;;
        esac
    done
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
    echo "IGNORÉ : phpunit absent (dépendances de dev retirées par le dernier déploiement)."
    echo "  Ce contrôle n'a PAS tourné. Pour le lancer : ./infra/reinstaller-dev.sh"
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

# 20. Le code de production ne dépend d'aucun paquet de développement.
#     `symfony/http-client` etait en `require-dev` alors que huit classes de production s'en
#     servaient : OCR Anthropic, les quatre adaptateurs Bluesky/Mastodon, l'annuaire des
#     entreprises, le calendrier scolaire. Sur un deploiement `--no-dev`, aucune ne pouvait
#     fonctionner — et le service `http_client` etant construit par le conteneur, le transport du
#     mailer explosait avec, d'ou un 500 sur « mot de passe oublie ».
#
#     Les tests tournent avec les dependances de dev : le defaut est invisible partout ou on le
#     cherche, et visible seulement la ou personne ne regarde. Pas de ligne de base, meme raison
#     que le n°4.
executer "Dépendances de dev (n°20)" php_racine bin/garde-fou-dependances-dev.php

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

# 10. Un `DEFAULT` posé en migration doit être déclaré au mapping (D32). Sans quoi la colonne
#     ressort en `CHANGE` dans le diff de CHAQUE session, éternellement : chacune ramasse la
#     dérive des autres et la présente comme son propre travail. Famille trouvée par claude-F le
#     25/08 en vérifiant sa migration sur une base repartie de zéro.
if [ -n "${REFERENCE:-}" ]; then
    executer "Défauts au mapping (D32)" php_racine bin/garde-fou-defauts-mapping.php "--contre=$REFERENCE"
else
    executer "Défauts au mapping (D32)" php_racine bin/garde-fou-defauts-mapping.php
fi

# 12. Une entité ne doit pas laisser écrire son PROPRE établissement (D41). L'entité est
#     cloisonnée, mais le champ qui la rattache est modifiable depuis le corps de la requête :
#     l'appelant choisit à quel établissement elle appartient. Trouvé par claude-H sur
#     PointDeVente. Cliquet séparé du n°8 — ajouter une règle à un cliquet existant relève
#     toujours son plafond, et il ne peut pas distinguer une dette qui grossit d'une règle qui
#     mesure ce qui n'était pas compté.
# 13. Une reference libre ne se compare ni en DQL ni par filtre (D58). Ce controle ne depend d'aucune
#     comparaison avec un etat anterieur : il lit l'arbre courant, donc il tourne TOUJOURS -- avec ou
#     sans reference.
#
#     QUATRIEME OUBLI SUR LA MEME LISTE, ET LE PLUS INSTRUCTIF DES QUATRE.
#
#     Je l'ai oublie dans hooks/pre-receive (non), puis ici (attrape par claude-D apres une poussee
#     refusee sur un vert local), puis dans hooks/pre-commit (attrape par le hook lui-meme le jour ou
#     je venais de l'installer). Trois absences -- et un filet les voit toutes les trois.
#
#     Le quatrieme etait une PRESENCE INOPERANTE : mon correctif precedent avait pose l'appel A
#     L'INTERIEUR du `if [ -n "$REFERENCE" ]` ci-dessous, avec un commentaire qui disait exactement
#     l'inverse de ce que le code faisait. Le controle ne tournait donc que si on passait une
#     reference. `./bin/garde-fous.sh origin/main` : vert. `./bin/garde-fous.sh` : le filet criait.
#
#     Aucun filet ne voit ca. Un controle absent se compte ; un controle present mais place sous une
#     condition fausse la moitie du temps ne se distingue pas d'un controle qui tourne. Trouve par
#     claude-G, parce qu'elle et moi lancions le meme script differemment -- c'est-a-dire par hasard.
executer "Références libres (D58)" php_racine bin/garde-fou-references-libres.php

# Une propriete declaree dans un `#[ApiFilter]` doit EXISTER. API Platform ignore une propriete
# inconnue en silence : le filtre est publie dans la documentation, le parametre est accepte, et la
# collection sort ENTIERE. Trouve par allaccess-c2 sur `OpeningSlot`, ou le filtre disait `day` et la
# propriete s'appelait `weekday` -- quatrieme occurrence du meme renommage, manquee par trois
# balayages successifs qui couvraient chacun un endroit ou le mot pouvait vivre.
#
# ⚠ CE DEFAUT-LA NE REMONTE JAMAIS EN RECLAMATION. Un filtre casse qui rend une liste VIDE finit par
# etre signale : une absence intrigue. Celui-ci rend TOUT -- ca ressemble a des donnees, ca arrive, ca
# a la bonne forme, et personne ne le remet en cause.
executer "Filtres déclarés" php_racine bin/garde-fou-filtres-declares.php

# Le prefixe `/editor/` ne protege rien, et il a tout l'air du contraire. Aucune regle
# `access_control` ne vise `^/api/editor` : la garde est portee par CHAQUE operation, via un provider
# ou un processor qui appelle `assertEditor()`. Deux routes voisines suffisent a montrer le piege --
# `/editor/plans` est PUBLIQUE (le catalogue que lit un prospect) tandis que `/editor/catalog/plans`
# est reservee a l'editeur. Un mot d'ecart, deux publics opposes.
#
# ⚠ CE N'EST PAS UN DEFAUT PRESENT, C'EST CELUI D'APRES. Les vingt-deux routes actuelles sont dans le
# bon camp ; ce qu'on empeche, c'est que la prochaine herite de l'APPARENCE de surete sans en heriter
# de la surete. Ouvrir une route publique sous ce prefixe reste possible -- le tunnel de vente en a
# besoin -- mais devient un geste ecrit dans `EXCEPTIONS`, donc relu.
executer "Routes éditeur" php_racine bin/garde-fou-routes-editeur.php

# Un frontal qui lit une propriete que le serveur n'envoie jamais.
#
# `Reservation::$ressourceAffectee` porte `reservation:read` ; `Ressource::$libelle` porte
# `ressource:read` et `creneau:read`, pas celui-la. La relation ne rend donc qu'un identifiant, et
# l'ecran affichait « Affectée : undefined » — sur l'ecran qui sert justement a savoir quelle
# chambre a ete donnee. Le champ existe, la relation existe, le groupe existe : c'est la
# COMBINAISON qui manque, et aucune des trois pieces n'est fautive isolement.
#
# La question se pose du FRONTAL vers le serveur, sinon elle n'est pas decidable : partir du serveur
# demandait d'inferer le type d'un objet en JavaScript (essai fait, 327 resultats presque tous faux).
# Retournee, elle se tranche par un quantificateur universel — si AUCUNE source possible d'une
# propriete nommee X ne rend Y lisible, la lecture vaut `undefined` quelle que soit son origine.
executer "Lectures indéfinies" php_racine bin/garde-fou-relations-nues.php

if [ -n "${REFERENCE:-}" ]; then
    executer "Établissement écrivable (D41)" php_racine bin/garde-fou-etablissement-ecrivable.php "--contre=$REFERENCE"
else
    executer "Établissement écrivable (D41)" php_racine bin/garde-fou-etablissement-ecrivable.php
fi

# 13. Une suppression dans un `up()` de migration doit être voulue, et le dire (D32).
#     `migrations:diff` compare les métadonnées à la base ENTIÈRE : il ramasse la dérive des
#     autres sessions et la présente comme le travail de l'auteur. Préventif — aucun DROP de
#     dérive n'a jamais été commité, les dix gelés sont des consolidations délibérées d'août.
if [ -n "${REFERENCE:-}" ]; then
    executer "Suppressions en migration (D32)" php_racine bin/garde-fou-drop-migrations.php "--contre=$REFERENCE"
else
    executer "Suppressions en migration (D32)" php_racine bin/garde-fou-drop-migrations.php
fi

# 16. Lier un OBJET à un paramètre de requête sans dire son type (D58).
#     Doctrine passe l'identifiant SANS son type `uuid` : la requête reste valide et compte zéro,
#     sans exception ni avertissement. Deux modules en sont morts en silence le 28/08 — le solde
#     de fidélité ne bougeait jamais, la file d'attente Smart Flow donnait le rang 1 à tout le
#     monde. Une comparaison mal typée ne produit pas d'erreur, elle produit un vide.
if [ -n "${REFERENCE:-}" ]; then
    executer "Liaisons d'objet (D58)" php_racine bin/garde-fou-liaisons-objet.php "--contre=$REFERENCE"
    executer "Nullable sur colonne non nulle" php_racine bin/garde-fou-nullable-non-nul.php "--contre=$REFERENCE"
    executer "Vacuite des tests de cloisonnement" php_racine bin/garde-fou-vacuite-tests.php
    executer "Espacement en ligne" php_racine bin/garde-fou-espacement-en-ligne.php
    executer "Champ de cloisonnement" php_racine bin/garde-fou-champ-cloisonnement.php
    executer "Creations irreversibles" php_racine bin/garde-fou-post-sans-suppression.php
    executer "Filtres muets" php_racine bin/garde-fou-filtres-muets.php
    executer "Appels du frontal dans le vide (n°33)" php_racine bin/garde-fou-appels-dans-le-vide.php
else
    executer "Liaisons d'objet (D58)" php_racine bin/garde-fou-liaisons-objet.php
    executer "Nullable sur colonne non nulle" php_racine bin/garde-fou-nullable-non-nul.php
    executer "Vacuite des tests de cloisonnement" php_racine bin/garde-fou-vacuite-tests.php
    executer "Espacement en ligne" php_racine bin/garde-fou-espacement-en-ligne.php
    executer "Champ de cloisonnement" php_racine bin/garde-fou-champ-cloisonnement.php
    executer "Creations irreversibles" php_racine bin/garde-fou-post-sans-suppression.php
    executer "Filtres muets" php_racine bin/garde-fou-filtres-muets.php
    executer "Appels du frontal dans le vide (n°33)" php_racine bin/garde-fou-appels-dans-le-vide.php
fi

# 5. i18n : pas de chaîne d'UI en dur — SANS OBJET tant que la couche i18n n'existe pas (aucun
#    catalogue, aucun usage du traducteur dans app/src). Acté par l'intégrateur le 21/08.
# 6. CSRF — SANS OBJET : tous les pare-feux sont `stateless: true` et l'authentification est un JWT
#    en en-tête, qui n'est pas un identifiant ambiant. Acté par l'intégrateur le 21/08.

# 11. Droits du frontend (D39) — écrit par claude-H, branché ici.
#
# La comparaison brute `droits.includes('caisse.lire')` ignore la permission joker `*.lire` : quatre
# occurrences ont enfermé Maxime hors de son propre logiciel. Le script vérifie aussi que les
# composants qui affichent des droits les reçoivent réellement en propriété.
#
# ⚠ Il tourne sur l'HÔTE : `node` n'est pas dans l'image PHP. S'il manque, on le dit — un contrôle
# sauté qui se tait laisse croire qu'il a validé.
if [ -f "$RACINE/frontend/scripts/verifier-droits.mjs" ]; then
    if command -v node >/dev/null 2>&1; then
        executer "Droits du frontend (D39)" sh -c "cd '$RACINE/frontend' && node scripts/verifier-droits.mjs"
    else
        echo "─────────────────────────────────────────────────────────────"
        echo "▶ Droits du frontend (D39)"
        echo "─────────────────────────────────────────────────────────────"
        echo "· IGNORÉ — « node » indisponible ici. Le contrôle n'a PAS tourné."
    fi
fi

# CLASSES CSS DÉCLARÉES (n°16) — un écran qui s'affiche n'est pas un écran qui est stylé.
#
# Le 28/08, quatre noms de classe — `page-head`, `panel`, `panel-h`, `alert` — étaient employés 136
# fois dans 22 fichiers et déclarés dans AUCUNE règle de `styles.css`. Neuf écrans entiers sortaient
# sans cadre, sans en-tête et sans couleur d'erreur.
#
# Ce défaut ne casse rien : le navigateur ignore une classe inconnue sans un mot, le build passe,
# les tests passent. Il ne se voit que sur un écran ouvert, et il y ressemble à un dessin bâclé
# plutôt qu'à une panne — donc personne ne va lire la feuille de style.
#
# Il tourne sur l'HÔTE comme les deux contrôles suivants : node n'est pas dans l'image PHP.
# PROFIL CHARGÉ AVANT LE PREMIER RENDU — l'invariant dont dépendent 75 contrôles de droits.
#
# `aLeDroit(droits, code)` rend `false` aussi bien pour « refusé » que pour « pas encore chargé ».
# Le 29/08, ça a renvoyé à la caisse quiconque ouvrait un lien profond vers un écran protégé : la
# garde d'onglet lisait des droits vides et concluait « permission absente » avant que /me réponde.
#
# Ce qui rend les soixante-quinze AUTRES appels sûrs n'est pas la fonction, c'est un invariant :
# aucun écran ne se monte avant que le profil soit là. Ce contrôle vérifie ce fait-là, pas une
# convention — et il échoue le jour où quelqu'un le brise sans le savoir.
if [ -f "$RACINE/frontend/scripts/verifier-profil-charge.mjs" ]; then
    if command -v node >/dev/null 2>&1; then
        executer "Profil chargé avant le rendu" sh -c "cd '$RACINE/frontend' && node scripts/verifier-profil-charge.mjs"
    else
        echo "─────────────────────────────────────────────────────────────"
        echo "▶ Profil chargé avant le rendu"
        echo "─────────────────────────────────────────────────────────────"
        echo "· IGNORÉ — « node » indisponible ici. Le contrôle n'a PAS tourné."
    fi
fi

# ── DEUX CONTROLES QUE LE PUSH EXIGEAIT ET QUE CE LANCEUR NE FAISAIT PAS TOURNER ────────────────
#
# `hooks/pre-receive` appelle sept scripts ; ce fichier n'en appelait que cinq. Manquaient
# `verifier-formats.mjs` et `verifier-imports.mjs`. Consequence vecue le 29/08 : « 22 garde-fous
# OK » en local sur un ecran de caisse qui appelait `aLeDroit()` sans l'importer -- le push aurait
# ete refuse, et surtout l'ecran aurait plante au rendu.
#
# LE SENS DE L'ECART COMPTE. L'inverse -- un controle ici et pas dans le hook -- se voit tout de
# suite : le push passe et on s'etonne. Celui-ci ne se voit JAMAIS en local ; il transforme un
# lanceur vert en fausse assurance, ce qui est pire que pas de lanceur du tout.
#
# `verifier-imports` merite particulierement d'etre ici : il attrape ce que le build ne peut pas
# voir. Vite ne fait pas d'analyse de portee sur le JSX, un identifiant inconnu n'existe qu'au
# rendu. Le decouvrir au push, c'est le decouvrir apres avoir cru le travail fini.
if [ -f "$RACINE/frontend/scripts/verifier-imports.mjs" ]; then
    if command -v node >/dev/null 2>&1; then
        executer "Imports manquants (n°10)" sh -c "cd '$RACINE/frontend' && node scripts/verifier-imports.mjs"
    else
        echo "─────────────────────────────────────────────────────────────"
        echo "▶ Imports manquants"
        echo "─────────────────────────────────────────────────────────────"
        echo "· IGNORÉ — « node » indisponible ici. Le contrôle n'a PAS tourné."
    fi
fi

if [ -f "$RACINE/frontend/scripts/verifier-formats.mjs" ]; then
    if command -v node >/dev/null 2>&1; then
        executer "Formats d'écriture (n°11)" sh -c "cd '$RACINE/frontend' && node scripts/verifier-formats.mjs"
    else
        echo "─────────────────────────────────────────────────────────────"
        echo "▶ Formats d'écriture"
        echo "─────────────────────────────────────────────────────────────"
        echo "· IGNORÉ — « node » indisponible ici. Le contrôle n'a PAS tourné."
    fi
fi

# Un bouton dont le contenu est un symbole et qui ne porte ni `aria-label`, ni `aria-labelledby`,
# ni `title` : un lecteur d'ecran annonce « bouton » et rien d'autre, et le seul moyen de savoir ce
# qu'il declenche est de l'essayer.
#
# ⚠ Neuf boutons `↻` etaient dans ce cas. La ligne T9 du tableau annoncait « 6 fichiers sur 118
# portent un alt » — vrai et trompeur : seuls six fichiers contiennent une image, et AUCUNE ne
# manque d'`alt`. Ce qui manquait etait ailleurs, et personne ne l'avait compte.
if [ -f "$RACINE/frontend/scripts/verifier-boutons-nommes.mjs" ]; then
    if [ -d "$RACINE/frontend/node_modules" ]; then
        executer "Boutons nommés" sh -c "cd '$RACINE/frontend' && node scripts/verifier-boutons-nommes.mjs"
    fi
fi

if [ -f "$RACINE/frontend/scripts/verifier-classes.mjs" ]; then
    if command -v node >/dev/null 2>&1; then
        executer "Classes CSS déclarées (n°16)" sh -c "cd '$RACINE/frontend' && node scripts/verifier-classes.mjs"
    else
        echo "─────────────────────────────────────────────────────────────"
        echo "▶ Classes CSS déclarées (n°16)"
        echo "─────────────────────────────────────────────────────────────"
        echo "· IGNORÉ — « node » indisponible ici. Le contrôle n'a PAS tourné."
    fi
fi

# CACHE DU SERVICE WORKER (n°18) — le seul défaut de ce dépôt qui ne ressemble pas à une panne.
#
# Un service worker qui sert une réponse d'API périmée ne lève rien, ne ralentit rien, n'écrit rien
# dans aucun journal. Un caissier voit un solde de carte ou une liste de passages vieux de dix
# minutes, et RIEN à l'écran ne lui dit qu'ils sont vieux. Il encaisse, il laisse entrer, il refuse
# une entrée. Un logiciel de caisse hors ligne qui ment est pire qu'un logiciel de caisse
# indisponible.
#
# `sw.js` n'était chargé par aucun test et ne passe par aucun build : rien ne l'empêchait. Le jour
# où quelqu'un ajoutera « le mode hors ligne » de bonne foi, il touchera ce fichier.
#
# Le contrôle EXÉCUTE le service worker dans un `vm` et lui envoie des requêtes synthétiques, au
# lieu de lire son texte. La différence n'est pas cosmétique : une branche de cache attrape-tout
# placée APRÈS la liste des routes métier ne fuit pas, la même placée AVANT sert `/api` depuis le
# cache. L'ordre est tout, et aucune expression régulière ne le voit.
#
# Il tourne sur l'HÔTE comme les autres contrôles front : node n'est pas dans l'image PHP.
# DATES LOCALES (n°31) — `toISOString().slice(0, 10)` rend la veille entre minuit et deux heures.
#
# Douze occurrences vivantes le 30/08/2026, sur des champs qui DATENT DES FAITS : facture
# fournisseur, signature de mandat SEPA, exécution d'un prélèvement, rejet bancaire, entrée d'un
# employé. Le remède existait déjà — `jourLocal()` dans `components/Liste.jsx` — avec le commentaire
# qui l'explique. Le savoir était posé à un endroit et douze autres l'ignoraient : c'est exactement
# ce qu'un garde-fou attrape et qu'un commentaire ne peut pas.
if [ -f "$RACINE/frontend/scripts/verifier-dates-locales.mjs" ]; then
    if command -v node >/dev/null 2>&1; then
        executer "Dates locales (n°31)" sh -c "cd '$RACINE/frontend' && node scripts/verifier-dates-locales.mjs"
    else
        echo "─────────────────────────────────────────────────────────────"
        echo "▶ Dates locales (n°31)"
        echo "─────────────────────────────────────────────────────────────"
        echo "· IGNORÉ — « node » indisponible ici. Le contrôle n'a PAS tourné."
    fi
fi

if [ -f "$RACINE/frontend/scripts/verifier-cache.mjs" ]; then
    if command -v node >/dev/null 2>&1; then
        executer "Cache du service worker (n°18)" sh -c "cd '$RACINE/frontend' && node scripts/verifier-cache.mjs"
    else
        echo "─────────────────────────────────────────────────────────────"
        echo "▶ Cache du service worker (n°18)"
        echo "─────────────────────────────────────────────────────────────"
        echo "· IGNORÉ — « node » indisponible ici. Le contrôle n'a PAS tourné."
    fi
fi

# ÉCART CLIENT/SERVEUR (n°15) — une opération neuve a un écran, ou dit pourquoi elle n'en a pas.
#
# Le quinzième contrôle, et le premier qui ne porte pas sur la correction du code mais sur le fait
# qu'il SERVE à quelqu'un. `mesurer-ecart.mjs` constatait depuis une semaine ; un constat n'arrête
# rien, et le nombre d'opérations exposées est passé de 1 042 à 1 086 pendant qu'on le regardait.
#
# Il tourne sur l'HÔTE comme les contrôles de droits : node n'est pas dans l'image PHP.
if [ -f "$RACINE/frontend/scripts/garde-fou-ecart.mjs" ]; then
    if command -v node >/dev/null 2>&1; then
        executer "Écart client/serveur (n°15)" sh -c "cd '$RACINE/frontend' && node scripts/garde-fou-ecart.mjs"
    else
        echo "─────────────────────────────────────────────────────────────"
        echo "▶ Écart client/serveur (n°15)"
        echo "─────────────────────────────────────────────────────────────"
        echo "· IGNORÉ — « node » indisponible ici. Le contrôle n'a PAS tourné."
    fi
fi

# ⚠ FILET DE COMPLÉTUDE DU LANCEUR — et il couvre TOUTES les extensions.
#
# Les deux hooks ont déjà ce filet, mais ils globent `bin/garde-fou-*.php`. Le garde-fou de
# topologie est un `.sh` — il n'entrait donc dans aucun des deux, et le lanceur, seul endroit où
# il s'exécute, n'avait pas de filet du tout. Retirer son appel n'aurait rien déclenché.
#
# C'est la classe de défaut corrigée les 24/08 dans `pre-receive` puis `pre-commit`, et je l'ai
# réintroduite le lendemain en choisissant une extension. D'où le glob sans `.php` ici : un
# garde-fou est un garde-fou, quel que soit le langage dans lequel il est écrit.
for chemin in "$RACINE"/bin/garde-fou-*; do
    [ -f "$chemin" ] || continue
    nom="$(basename "$chemin")"
    case " $LANCES " in
        *" $nom "*) ;;
        *)
            echo "─────────────────────────────────────────────────────────────"
            echo "✗ Garde-fou présent dans bin/ mais jamais lancé par ce script : $nom"
            echo "  Ajoute son appel dans bin/garde-fous.sh — un contrôle qui ne tourne pas rend"
            echo "  un vert au nom d'une vérification qui n'a pas eu lieu."
            ECHECS=$((ECHECS + 1))
            ;;
    esac
done

# ⚠ ET LE MEME FILET POUR LES CONTROLES FRONTAUX, QUI N'EN AVAIENT AUCUN.
#
# Ce lanceur en appelle huit, un par bloc `if [ -f ... ]`. Un neuvieme ajoute au depot n'y serait
# pas, et rien ne le dirait : la sortie afficherait « ✓ N garde-fou(s) OK » en l'ayant ignore.
#
# Mesure du 31/08 : `verifier-dates-locales.mjs` etait cable ici et dans `pre-receive`, ABSENT de
# `pre-commit`. Trois listes, et aucune ne savait dire qu'il manquait a une autre.
#
# Le predicat vise l'APPEL, pas la mention : chercher le nom du script serait satisfait par un
# commentaire qui le nomme sans l'appeler. On cherche `node scripts/<nom>`, forme d'invocation
# reelle et unique.
#
# Le nom distingue les deux familles du repertoire :
#     verifier-*.mjs · garde-fou-*.mjs   des CONTROLES, ils doivent tourner
#     mesurer-*.mjs                      des SONDES, lancees a la main
for chemin in "$RACINE"/frontend/scripts/verifier-*.mjs "$RACINE"/frontend/scripts/garde-fou-*.mjs; do
    [ -f "$chemin" ] || continue
    nom="$(basename "$chemin")"
    if ! grep -q "node scripts/$nom" "$RACINE/bin/garde-fous.sh"; then
        echo "═════════════════════════════════════════════════════════════" >&2
        echo "✗ Controle frontal present dans l'arbre mais jamais lance par ce script : $nom" >&2
        echo "" >&2
        echo "  Un lanceur qui ignore un controle rend un vert au nom d'une verification" >&2
        echo "  qui n'a pas eu lieu." >&2
        echo "" >&2
        echo "  Ajoute son bloc ici, ET dans les deux autres listes :" >&2
        echo "    hooks/pre-commit      (ligne « lancer_front $nom »)" >&2
        echo "    hooks/pre-receive     (liste « for script in ... »)" >&2
        echo "═════════════════════════════════════════════════════════════" >&2
        ECHECS=$((ECHECS + 1))
    fi
done

# Les libelles ne sont tous connus qu'ici : le controle des doublons ne peut pas se faire plus tot.
if ! verifier_numeros_uniques $(printf '%s' "$LIBELLES" | tr '|' ' '); then
    ECHECS=$((ECHECS + 1))
fi

echo "─────────────────────────────────────────────────────────────"
if [ "$ECHECS" -gt 0 ]; then
    echo "✗ $ECHECS garde-fou(s) en échec sur $TOTAL."
    exit 1
fi
echo "✓ $TOTAL garde-fou(s) OK."
