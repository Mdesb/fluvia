#!/usr/bin/env bash
# Stack de test isolée par instance (PLAYBOOK §7.3).
#
# Chaque Claude a son réseau, sa base et ses clés JWT : deux instances qui testent en même temps ne
# doivent jamais partager une base, sinon la suite de l'une détruit les fixtures de l'autre.
#
#   ./infra/test-stack.sh up      claudeA   # crée réseau + base + clés, monte le schéma de test
#   ./infra/test-stack.sh run     claudeA   # lance la suite (arguments supplémentaires transmis)
#   ./infra/test-stack.sh down    claudeA   # supprime réseau + base (les clés JWT restent)
#
# ⚠ Repasse par `up` avant de tester un AUTRE module. Les classes de base font `dropSchema` puis
#   `createSchema`, et ce couple ne nettoie pas toujours une base laissée par un autre module : on
#   obtient alors « Base table or view already exists » au premier setUp. Le symptôme ressemble à une
#   régression du code ; ce n'en est pas une. `up` remet la base à plat en une vingtaine de secondes.
#
# Le worktree courant est déduit de l'emplacement du script — pas de chemin en dur, le script marche
# à l'identique depuis /home/debian/wt/claude-A, .../claude-B, etc.

set -euo pipefail

ACTION="${1:-}"
TOKEN="${2:-}"

if [ -z "$ACTION" ] || [ -z "$TOKEN" ]; then
    echo "usage: $0 {up|run|down} <TEST_TOKEN>   (ex. $0 up claudeA)" >&2
    exit 2
fi

shift 2 || true

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
APP="$REPO/app"
NET="${TOKEN}-net"
DB="${TOKEN}-db"

# ⚠ LE JETON N'ISOLAIT PAS LE CONTENEUR COMPILE.
#
# Reseau, base, nom de conteneur : tout etait separe, sauf `var/cache/test`, partage par toutes les
# executions et purge par chacune au demarrage. Deux sessions simultanees se detruisaient donc le
# cache mutuellement, et l'une pouvait lire un conteneur compile a moitie par l'autre.
#
# Le 31/08, ca s'est presente comme six tests en 404 sur une route qui existait — donc comme une
# regression du voisin, pas comme un defaut d'environnement.
#
# `App\Kernel::getCacheDir()` lit `TEST_TOKEN` (deja transmis a chaque conteneur) et rend ce chemin.
CACHE="$APP/var/cache/test-$(printf '%s' "$TOKEN" | tr -cd 'A-Za-z0-9_-')"
PHP_IMAGE="${PHP_IMAGE:-billetterie-preprod-php}"
DB_IMAGE="${DB_IMAGE:-mariadb:11.4}"
DATABASE_URL="mysql://app:app@db:3306/app?serverVersion=11.4.2-MariaDB&charset=utf8mb4"

# Exécute une commande dans un conteneur PHP jetable, branché sur le réseau de l'instance.
#
# Le `zz-memory.ini` du projet est monté explicitement : l'image porte le défaut de PHP (128M), et la
# suite le dépasse — le schéma est recréé par classe de test sur ~90 tables. Sans ce montage, la suite
# meurt en « Allowed memory size exhausted » au bout de quelques centaines de tests, ce qui se lit
# comme une régression alors que c'est un défaut d'environnement.
#
# ⚠ ET LE CONTENEUR PORTE LE NOM DU JETON, CE QUI REFUSE UNE SECONDE EXECUTION SIMULTANEE.
#
# Deux `run` sur le meme jeton partagent la meme base, et `SchemaDuHarnais` TRUNCATE au demarrage de
# chaque classe : la seconde vide les tables sous les pieds de la premiere. Le verdict des deux perd
# toute valeur — un faux rouge coute une heure, un faux vert coute la confiance dans la suite
# entiere. Commis le 30/08, et rien ne l'avait signale.
#
# Avec un nom fixe, Docker refuse la seconde avec « name is already in use ». Le message n'est pas
# limpide, d'ou la garde explicite ci-dessous qui le traduit avant que Docker ne s'en charge.
php_run() {
    if [ -n "$(docker ps -q --filter "name=^${TOKEN}-run$" 2>/dev/null)" ]; then
        echo "✗ Une exécution tourne déjà sur le jeton « $TOKEN » (conteneur ${TOKEN}-run)." >&2
        echo "  Deux exécutions sur le même jeton partagent la même base et se corrompent :" >&2
        echo "  la seconde TRUNCATE les tables de la première. Le verdict des deux serait faux." >&2
        echo "  Remède : attendre la fin, ou lancer sur un autre jeton (ex. ${TOKEN}2)." >&2
        exit 1
    fi

    docker run --rm --name "${TOKEN}-run" --network "$NET" -u "$(id -u):$(id -g)" \
        -e "TEST_TOKEN=$TOKEN" \
        -e "DATABASE_URL=$DATABASE_URL" \
        -v "$REPO:/repo" \
        -v "$REPO/docker/php/conf.d/zz-memory.ini:/usr/local/etc/php/conf.d/zz-memory.ini:ro" \
        -w /repo/app \
        "$PHP_IMAGE" "$@"
}

case "$ACTION" in
up)
    docker network inspect "$NET" >/dev/null 2>&1 || docker network create "$NET" >/dev/null
    echo "réseau  : $NET"

    if ! docker ps --format '{{.Names}}' | grep -qx "$DB"; then
        docker rm -f "$DB" >/dev/null 2>&1 || true
        docker run -d --name "$DB" --network "$NET" --network-alias db \
            -e MARIADB_ROOT_PASSWORD=root \
            -e MARIADB_DATABASE=app \
            -e MARIADB_USER=app \
            -e MARIADB_PASSWORD=app \
            --health-cmd='healthcheck.sh --connect --innodb_initialized' \
            --health-interval=3s --health-retries=20 \
            "$DB_IMAGE" >/dev/null
    fi

    printf 'base    : %s ' "$DB"
    for _ in $(seq 1 40); do
        if [ "$(docker inspect -f '{{.State.Health.Status}}' "$DB" 2>/dev/null)" = healthy ]; then
            break
        fi
        printf '.'
        sleep 2
    done
    echo ' prête'

    # `app` ne peut créer que la base `app` par défaut ; la suite travaille sur `app_test<TOKEN>`.
    docker exec "$DB" mariadb -uroot -proot -e \
        "GRANT ALL PRIVILEGES ON \`app\_test%\`.* TO 'app'@'%'; FLUSH PRIVILEGES;"
    echo "droits  : app peut créer app_test%"

    # Clés JWT : ignorées par git (config/jwt/*.pem), donc absentes de tout worktree neuf. Sans elles,
    # chaque test authentifié échoue en JWTEncodeFailureException — le symptôme est bruyant mais la
    # cause est invisible, d'où cette étape explicite.
    # ⚠ `test-private.pem`, PAS `private.pem`. `.env.test` pointe la cle prefixee `test-` ; garder
    # `private.pem` ici faisait relancer la generation a chaque `up`, et la commande sort en erreur
    # quand les cles existent — `set -e` arretait alors le script AVANT le montage du schema, sans
    # qu'aucun message ne parle de cles. Corrige le 28/08 apres etre tombe dedans.
    if [ ! -f "$APP/config/jwt/test-private.pem" ]; then
        php_run php bin/console lexik:jwt:generate-keypair --no-interaction --env=test >/dev/null
        echo "clés JWT: générées"
    else
        echo "clés JWT: déjà présentes"
    fi

    php_run php bin/console doctrine:database:create --env=test --if-not-exists >/dev/null
    php_run php bin/console doctrine:schema:drop --env=test --force --full-database >/dev/null 2>&1 || true
    php_run php bin/console doctrine:schema:create --env=test >/dev/null

    # `doctrine:schema:create` monte les TABLES depuis le mapping, et ignore les objets que le mapping
    # ne decrit pas. La sequence native `acces_snapshot_seq` en fait partie : elle est posee par la
    # migration Version20260817192240, que le harnais ne rejoue jamais.
    #
    # Faute de quoi deux fixtures la creaient elles-memes, defensivement (`AccesFixtures`,
    # `PersonnelFixtures`). Ca marchait, et ca posait un probleme qu'on ne voyait pas : un `CREATE`
    # provoque une VALIDATION IMPLICITE en MySQL. Une fixture qui fait du DDL referme donc toute
    # transaction englobante AU MILIEU du chargement -- et tout ce qui a ete purge avant est perdu,
    # transaction ou pas. Signale par claude-D en repondant a la question « comment protege-t-on
    # contre une purge suivie d'un echec ». La reponse etait : pas comme ca, tant que ceci existe.
    #
    # Une fixture est un jeu de DONNEES. Le schema appartient au harnais et aux migrations.
    # ⚠ NE PAS REPASSER PAR `bin/console` ICI. La version precedente appelait
    # `doctrine:query:sql`, qui N'EXISTE PAS dans cette version de Doctrine, et masquait l'echec par
    # `|| true` : le harnais annoncait « schema monte » sans avoir pose la sequence. La chaine qui
    # s'ensuivait ne nommait jamais la cause -- fixture qui cree la sequence en DDL, validation
    # implicite MariaDB, transaction de l'executeur perdue, et un message final qui accuse le pilote
    # (« There is no active transaction »).
    docker exec "$DB" mariadb -uroot -proot "app_test$TOKEN" -e \
        "CREATE SEQUENCE IF NOT EXISTS acces_snapshot_seq START WITH 1 INCREMENT BY 1"
    echo "sequence: acces_snapshot_seq"
    echo "schéma  : app_test$TOKEN monté"
    ;;

run)
    # Le cache de metadonnees d'API Platform survit d'une execution a l'autre. Le 24/08 il a
    # produit un **faux echec** : les champs ajoutes par un lot recent n'etaient pas serialises,
    # alors que le code etait juste. Le symetrique est pire — un cache perime peut masquer une
    # vraie regression et rendre la suite verte a tort.
    #
    # On purge donc avant chaque execution. Cela coute un demarrage a froid ; c'est le prix d'un
    # verdict auquel on peut se fier, et D20 a deja tranche que la fiabilite passe avant la vitesse.
    rm -rf "$CACHE" 2>/dev/null || true

    # ⚠ LE DEPLOIEMENT RETIRE PHPUNIT, ET LE MESSAGE DE DOCKER NE LE DIT PAS.
    #
    # `deploy-preprod.sh` lance `composer install --no-dev` -- c'est juste pour la production. Sans
    # cette garde, la suite echoue sur « exec: vendor/bin/phpunit: not found », qui ne nomme ni la
    # cause ni le remede. Arrive trois fois le 30/08, dont deux relances completes pour rien.
    #
    # On ne reinstalle pas a la place de l'operateur : un harnais qui repare silencieusement l'etat
    # de la machine finit par cacher autre chose. On dit ce qui manque et pourquoi.
    if [ ! -x "$APP/vendor/bin/phpunit" ]; then
        echo "✗ phpunit est absent : les dépendances de dev ont été retirées." >&2
        echo "  Cause : le dernier déploiement a lancé « composer install --no-dev »." >&2
        echo "  Remède : ./infra/reinstaller-dev.sh" >&2
        exit 1
    fi

    # ⚠ UN MARQUEUR TANT QUE LA SUITE TOURNE — ET LE DEPLOIEMENT NE LE LIT PAS.
    #
    # Cette phrase disait « pour que le deploiement puisse le voir ». C'est faux, et ce n'est pas un
    # manque : `deploy-preprod.sh` detecte les suites en lisant les MONTAGES des conteneurs, ce qui
    # est meilleur — un marqueur survit a un processus tue, un conteneur non. Le marqueur reste utile
    # a un humain qui cherche ce qui tourne ; il n'est le contrat de personne.
    #
    # `deploy-preprod.sh` retire phpunit (`composer install --no-dev`). Une suite en cours meurt
    # alors en plein milieu, avec un message qui accuse l'operateur de ne pas avoir reinstalle --
    # alors qu'il l'avait fait. Constate le 31/08 : deux sessions, quarante secondes d'ecart.
    #
    # On ne supprime pas la collision : la preprod sans dependances de dev est le SEUL endroit ou
    # se voit la classe de defaut que le garde-fou n°20 traque. On la rend visible.
    MARQUEUR_SUITE="/tmp/suite-en-cours-$TOKEN"
    printf '%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ) jeton=$TOKEN" > "$MARQUEUR_SUITE"
    trap 'rm -f "$MARQUEUR_SUITE"' EXIT INT TERM

    php_run vendor/bin/phpunit "$@"
    ;;

down)
    rm -rf "$CACHE" 2>/dev/null || true
    docker rm -f "$DB" >/dev/null 2>&1 || true

    # ⚠ CE `|| true` ANNONCAIT UNE SUPPRESSION QUI N'AVAIT PAS EU LIEU.
    #
    # `docker network rm` echoue quand des conteneurs sont encore attaches — une execution bloquee,
    # par exemple. Le `|| true` avalait l'echec et le script disait « stack supprimee ». Le reseau
    # restait la, et le `up` suivant faisait repartir les executions bloquees dessus. Constate le
    # 30/08 : le message etait faux depuis le premier jour.
    #
    # On ne force pas : supprimer d'autorite le conteneur de quelqu'un d'autre serait pire que de le
    # signaler. On dit ce qui reste, et qui.
    if docker network rm "$NET" >/dev/null 2>&1; then
        echo "stack $TOKEN supprimée (les clés JWT du worktree sont conservées)"
    else
        RESTANTS="$(docker network inspect "$NET" --format '{{range .Containers}}{{.Name}} {{end}}' 2>/dev/null || true)"
        if [ -z "$RESTANTS" ]; then
            echo "stack $TOKEN supprimée (le réseau $NET n'existait pas)"
        else
            echo "⚠ Base supprimée, mais le réseau $NET SUBSISTE : des conteneurs y sont attachés." >&2
            echo "  Restants : $RESTANTS" >&2
            echo "  Un « up » sur ce jeton les ferait repartir sur la base neuve et fausserait tout." >&2
            echo "  Arrête-les puis relance « down », ou travaille sur un autre jeton." >&2
            exit 1
        fi
    fi
    ;;

*)
    echo "action inconnue : $ACTION" >&2
    exit 2
    ;;
esac
