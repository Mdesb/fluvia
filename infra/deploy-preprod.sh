#!/usr/bin/env bash
#
# Déploiement / mise à jour de la préprod.
# À lancer depuis la racine du dépôt sur le VPS, avec l'utilisateur applicatif :
#
#   ./infra/deploy-preprod.sh
#
set -euo pipefail

cd "$(dirname "$0")/.."
REPO_ROOT="$(pwd)"
WEB_ROOT="${WEB_ROOT:-/var/www/smartaccess}"   # racine servie par le Nginx de l'hôte
COMPOSE=(docker compose -f infra/compose.preprod.yaml --env-file infra/.env.preprod)

log() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }

[[ -f infra/.env.preprod ]] || {
    echo "infra/.env.preprod introuvable. Copie infra/env.preprod.example et remplis les secrets."
    exit 1
}

# ── ON NE SERT QUE CE QUI EST DANS `main` ───────────────────────────────────────────────────────
#
# Cette ligne faisait `git pull --ff-only`, ce qui decrivait un SUIVEUR de `main`. Cet arbre est un
# POINT D'INTEGRATION : on y fusionne les branches, on lance la suite, puis on pousse. Le `pull`
# echouait donc des qu'il y avait des commits d'avance -- mais l'echec n'etait pas le probleme.
#
# ⚠ LE VRAI RISQUE ETAIT DE REUSSIR. Un `pull` qui passe pendant que l'arbre porte du travail non
# pousse deploie ce travail SANS QU'IL SOIT DANS `main` : ce qui est servi n'est alors lisible nulle
# part. C'est arrive le 30/08 -- « Declarer un bassin » etait servi et absent de `main`, et deux
# sessions en ont tire des conclusions fausses en supposant l'inverse.
#
# La question posee par allaccess-b8 -- « ou regarder pour savoir si une chose est livree ? » -- a
# desormais une reponse unique : `origin/main`. Le deploiement refuse tout le reste.
log "Vérification : ce qui va être servi est-il dans main ?"
git fetch origin --quiet
TETE_LOCALE="$(git rev-parse HEAD)"
TETE_MAIN="$(git rev-parse origin/main)"

if [ "$TETE_LOCALE" != "$TETE_MAIN" ]; then
    AVANCE="$(git rev-list --count origin/main..HEAD)"
    RETARD="$(git rev-list --count HEAD..origin/main)"

    echo
    echo "✗ Déploiement refusé : cet arbre n'est pas origin/main."
    echo "    ici et pas dans main : $AVANCE commit(s)"
    echo "    dans main et pas ici : $RETARD commit(s)"
    echo
    echo "  Servir autre chose que main rendrait la question « est-ce livré ? » sans réponse :"
    echo "  ce qui est en ligne ne serait lisible dans aucune branche."
    echo
    [ "$AVANCE" != "0" ] && echo "  → du travail intégré ici n'est pas poussé :   git push origin main"
    [ "$RETARD" != "0" ] && echo "  → main a du travail que tu n'as pas :          git merge --no-edit origin/main"
    exit 1
fi

log "Construction / démarrage des conteneurs"
# ── GENERATION DES CLES DE CHIFFREMENT ET DE SCELLEMENT ─────────────────────────────────────────
#
# ⚠ UNE CLE PAR INSTALLATION (decision de Maxime, 31/08). Elles vivaient dans `app/.env`, VERSIONNE :
# toute installation qui suivait la procedure heritait des cles du depot, et un chiffrement au repos
# ne protege alors de rien contre quiconque a acces au depot.
#
# ⚠ GENEREES UNE FOIS, JAMAIS REGENEREES. Les changer rend indechiffrable ce qui a ete chiffre
# avant, et inverifiables les signatures deja posees -- exactement comme regenerer les cles JWT
# invaliderait tous les jetons emis. On n'ecrit donc QUE ce qui manque.
#
# 32 octets base64 : ces valeurs passent par `base64_decode()` et doivent faire exactement 32 octets
# une fois decodees. `openssl rand -hex 32` produirait une cle que le code refuse (RG-DMS-25).
log "Cles de chiffrement (generation au premier deploiement)"
for cle in DMS_ENCRYPTION_KEY MFA_ENCRYPTION_KEY SEPA_IBAN_KEY OCR_API_KEY_ENCRYPTION_KEY SOCIAL_TOKEN_ENCRYPTION_KEY NF525_SEAL_KEY NF525_COMPTA_SEAL_KEY NF525_FACTURATION_SEAL_KEY; do
    if ! grep -q "^${cle}=." infra/.env.preprod 2>/dev/null; then
        echo "  + $cle (absente, generee)"
        printf '%s=%s\n' "$cle" "$(openssl rand -base64 32)" >> infra/.env.preprod
    fi
done

"${COMPOSE[@]}" build
"${COMPOSE[@]}" up -d

# ⚠ CE QUI SUIT RETIRE PHPUNIT DU `vendor/` DE CET ARBRE, ET TUE TOUTE SUITE QUI EN DEPEND.
#
# `composer install --no-dev` supprime les paquets de developpement. Une suite qui tourne meurt
# alors en plein milieu, avec un message qui accuse l'operateur de ne pas avoir reinstalle -- alors
# qu'il l'avait fait. Constate le 31/08 : deux deploiements a quarante secondes d'ecart, une suite
# complete perdue sans qu'un seul test soit execute.
#
# ── SEULES LES SUITES DU MEME ARBRE SONT CONCERNEES ────────────────────────────────────────────
#
# Ma premiere version avertissait pour toutes. C'etait faux, et allaccess-8e l'a mesure : `git
# worktree` partage le `.git`, PAS le `vendor/`. Chaque worktree a le sien (deux inodes distincts,
# verifie). Il y a dix worktrees ici : avertir pour les dix apprend a tout le monde a sauter la
# ligne -- ce que j'ai moi-meme fait une heure apres l'avoir ecrite.
#
# On lit donc le MONTAGE des conteneurs de test, pas un fichier : il dit quel arbre chaque suite
# utilise, et il ne peut pas devenir perime -- alors qu'un marqueur survit a un processus tue.
#
# IL AVERTIT, IL NE BLOQUE PAS. Bloquer transformerait une gene en panne : la suite complete dure
# des heures et personne ne pourrait livrer pendant ce temps.
CONCERNEES=""
for conteneur in $(docker ps --filter 'name=-run' --format '{{.Names}}' 2>/dev/null); do
    monte="$(docker inspect -f '{{range .Mounts}}{{if eq .Destination "/repo"}}{{.Source}}{{end}}{{end}}' "$conteneur" 2>/dev/null)"
    if [ "$monte" = "$REPO_ROOT" ]; then
        CONCERNEES="$CONCERNEES $conteneur"
    fi
done

if [ -n "$CONCERNEES" ]; then
    echo
    echo "  ⚠  Une suite de tests tourne SUR CET ARBRE ($REPO_ROOT) :"
    for conteneur in $CONCERNEES; do
        echo "       $conteneur"
    done
    echo
    echo "     Ce déploiement va retirer phpunit et la faire mourir en plein milieu."
    echo "     Elle rendra un message qui accuse l'opérateur, pas ce déploiement."
    echo "     Préviens, ou attends — puis « ./infra/reinstaller-dev.sh » et relance-la."
    echo
    echo "     (Les suites montées sur un worktree ne sont PAS concernées : chaque worktree"
    echo "      a son propre vendor/. Elles ne sont pas listées ici.)"
    echo
fi

# ⚠ ON RETIENT, PARCE QUE L'AVERTISSEMENT CI-DESSUS AURA DEFILE.
#
# Il est imprime a une centaine de lignes de la fin et formule au futur. Le deploiement en imprime
# des centaines d'autres apres lui : au moment ou l'operateur lit la derniere, il ne sait plus qu'il
# a tue quelque chose. On garde donc la liste pour la redire A LA FIN, au passe, quand c'est un fait.
SUITE_TUEE="$CONCERNEES"

log "Dépendances Composer (sans les paquets de dev)"
"${COMPOSE[@]}" exec -T php composer install --no-dev --optimize-autoloader --no-interaction

# Les clés JWT ne sont pas versionnées (et ne doivent pas l'être) : elles
# n'arrivent donc jamais par git sur un serveur neuf. Sans elles, toute
# connexion echoue en 500 (JWTEncodeFailureException). Génération au premier
# déploiement uniquement — les regénérer invaliderait tous les jetons émis.
if ! "${COMPOSE[@]}" exec -T php test -f config/jwt/private.pem; then
    log "Génération des clés JWT (premier déploiement)"
    "${COMPOSE[@]}" exec -T php php bin/console lexik:jwt:generate-keypair --no-interaction
    # Les workers FPM tournent en www-data et doivent pouvoir lire les clés.
    "${COMPOSE[@]}" exec -T php chown -R www-data:www-data config/jwt
    "${COMPOSE[@]}" exec -T php chmod 640 config/jwt/private.pem config/jwt/public.pem
fi

log "Migrations de base"
"${COMPOSE[@]}" exec -T php php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

# LA BASE DE CONNAISSANCE GENERIQUE, POSEE A CHAQUE DEPLOIEMENT.
#
# Les dix-huit articles de `app/docs/aide/**` sont tous en portee GLOBALE : ils ne sont donc pas la
# documentation d'un client, ils sont celle du produit, et tout etablissement les voit. Sans cette
# ligne ils ne quittaient jamais le depot — la commande existait, l'ecran d'assistance existait, et
# un exploitant qui ouvrait « Base de connaissances » trouvait une liste vide.
#
# `--strict` volontairement ABSENT : un article de doc mal forme ne doit pas faire echouer un
# deploiement. Le resume imprime dit ce qui est passe et ce qui ne l'est pas ; le reste de la mise
# en ligne continue.
log "Base de connaissance (doc vivante -> articles d'aide)"
"${COMPOSE[@]}" exec -T php php bin/console support:importer-aide --no-interaction

log "Préchauffage du cache Symfony"
"${COMPOSE[@]}" exec -T php php bin/console cache:clear --env=prod --no-debug
"${COMPOSE[@]}" exec -T php php bin/console cache:warmup --env=prod --no-debug

# Composer et cache:warmup tournent en root dans le conteneur, alors que les
# workers FPM tournent en www-data : sans ce chown, l'app ne peut plus écrire ses
# logs ni son cache et renvoie des 500.
log "Droits sur var/"
"${COMPOSE[@]}" exec -T php chown -R www-data:www-data /app/var

# ⚠ ET ON VERIFIE QU'IL A FAIT CE QU'IL ANNONCE. Un chown qui echoue en silence laisse exactement
# l'etat qui a mis toute l'API a 500 le 01/09 — pendant que le deploiement, lui, reste vert. Un
# geste qui repare sans temoin est une esperance, pas une garantie.
./infra/verifier-droits-var.sh

# opcache tourne avec validate_timestamps=0 (cf. docker/php/conf.d/zz-opcache.ini) :
# sans redémarrage du master FPM, le code servi resterait celui d'avant le déploiement.
# ── LE MARQUEUR QUE PHP CHARGERA ────────────────────────────────────────────────────────────────
#
# ⚠ ECRIT AVANT LE REDEMARRAGE, et c'est tout l'interet : FPM le chargera au demarrage suivant. Si
# quelqu'un modifie du code sans redemarrer, la constante restera celle d'avant -- exactement comme
# le code servi. L'instrument herite du defaut qu'il mesure.
#
# `version.json` ne dit que la moitie frontale du produit : il voyage avec le `rsync`. Celui-ci dit
# la moitie serveur, et il est le seul a pouvoir la dire.
log "Marqueur de version pour PHP"

# ⚠ ECRIT DANS LE CONTENEUR, PAS SUR L'HOTE.
#
#     - ../app:/app          l'arbre est partage
#     - app_var:/app/var     SAUF var/, volume nomme qui MASQUE celui de l'hote
#
# Un `printf > app/var/...` depuis l'hote ecrit dans un repertoire que le conteneur ne voit pas.
# Constate le 31/08 : fichier present sur l'hote, `is_file()` faux dans le conteneur.
#
# Les valeurs passent par l'environnement : une chaine imbriquee dans un `sh -c` dans un `exec`
# ajoute un niveau de guillemets a chaque etage, et le shell finit par evaluer ce qu'on voulait
# ecrire.
"${COMPOSE[@]}" exec -T \
    -e MARQUEUR_COMMIT="$(git rev-parse --short HEAD)" \
    -e MARQUEUR_ECRIT="$(date -Is)" \
    php sh -c 'mkdir -p /app/var && printf "<?php\n\nreturn [\"commit\" => \"%s\", \"ecrit\" => \"%s\"];\n" "$MARQUEUR_COMMIT" "$MARQUEUR_ECRIT" > /app/var/build-version.php' 

log "Redémarrage de PHP-FPM (opcache)"
"${COMPOSE[@]}" restart php

# Le frontend est construit dans un conteneur jetable : pas de Node.js à
# installer ni à maintenir sur l'hôte, et la version du builder est figée.
# -u évite que node_modules/ et dist/ appartiennent à root.
log "Construction du frontend (React / Vite)"
docker run --rm \
    -v "$REPO_ROOT/frontend":/app -w /app \
    -u "$(id -u):$(id -g)" \
    -e HOME=/tmp -e npm_config_cache=/tmp/.npm \
    node:20-alpine sh -c 'npm ci --no-audit --no-fund && npm run build'

log "Publication du frontend"
# ── LE SERVICE WORKER PREND LE COMMIT POUR NOM DE CACHE ─────────────────────────────────────────
#
# Sans cela, `VERSION` reste constant d'une construction a l'autre, et DEUX gardes tombent ensemble :
# la purge des anciens caches ne trouve jamais d'autre nom a supprimer, et `install` ne se rejoue
# jamais -- donc la coquille en cache continue de nommer des assets que le `rsync --delete` ci-dessous
# vient de faire disparaitre. Hors ligne, l'utilisateur obtient une page blanche.
#
# ⚠ ET LA SUBSTITUTION EST VERIFIEE, PAS SUPPOSEE. Une substitution sautee rendrait la constante et
# le defaut sans que rien ne le dise : c'est l'absence qui ne crie pas.
log "Service worker : nom de cache au commit"
sed -i "s/fluvia-__COMMIT__/fluvia-$(git rev-parse --short HEAD)/" frontend/dist/sw.js

if grep -q '__COMMIT__' frontend/dist/sw.js; then
    echo "✗ Le jeton de version du service worker n'a pas été substitué." >&2
    echo "  Le cache garderait un nom constant : purge inerte, et coquille périmée hors ligne." >&2
    exit 1
fi

if ! grep -q "fluvia-$(git rev-parse --short HEAD)" frontend/dist/sw.js; then
    echo "✗ Le service worker ne porte pas le commit courant après substitution." >&2
    exit 1
fi

rsync -a --delete frontend/dist/ "$WEB_ROOT/"

# Le tableau de bord d'avancement, que Maxime consulte. Il est publie APRES le rsync ci-dessus, et
# c'est la raison d'etre de ces trois lignes : `--delete` efface tout ce qui n'est pas le front.
#
# Il avait ete publie une fois, puis efface par le deploiement suivant. Personne ne l'a vu partir --
# une page qui disparait ne previent pas, contrairement a une page qui casse. Neuf jours plus tard,
# l'URL que Maxime « gardait precieusement » ne servait plus rien.
if [ -f avancement-dev.html ]; then
    log "Publication du tableau de bord"
    cp avancement-dev.html "$WEB_ROOT/avancement-dev.html"
fi

# ── CE QUE LA PREPROD SERT, LISIBLE EN UNE REQUETE ──────────────────────────────────────────────
#
# Entre une fusion et le deploiement suivant, la preprod montre l'etat precedent -- la suite de
# tests dure cinquante minutes. Quiconque la lit pendant cette fenetre juge du travail deja livre
# sur un build qui ne le contient pas, et n'a aucun moyen de s'en apercevoir.
#
# Le 30/08 cela a coute une enquete complete a une session pour conclure qu'un bouton etait absent
# du BUILD et non du code. Avec ce fichier, la question se pose en une requete :
#
#     curl -s https://smartaccess.hector-conseil.com/version.json
#
# ⚠ PUBLIE APRES LE RSYNC, comme le tableau de bord : `--delete` efface tout ce qui n'est pas le
# front, et un marqueur efface serait pire qu'absent -- il aurait existe une fois.
log "Publication du marqueur de version"
COMMIT_DEPLOYE="$(git rev-parse --short HEAD)"
printf '{"commit":"%s","branche":"%s","construit":"%s","sujet":"%s"}\n' \
    "$COMMIT_DEPLOYE" \
    "$(git rev-parse --abbrev-ref HEAD)" \
    "$(date -Is)" \
    "$(git log -1 --format=%s | tr -d '"' | cut -c1-120)" \
    > "$WEB_ROOT/version.json"

# ── LA BOUCLE : LE DEPLOIEMENT RELIT SA PROPRE URL PUBLIQUE ─────────────────────────────────────
#
# Un champ d'identite dans le marqueur ne prouverait rien : le marqueur est du CONTENU, et le contenu
# voyage avec le rsync. Deux machines servant le meme `dist` porteraient le meme champ -- c'est-a-dire
# precisement le cas qu'on veut detecter.
#
# Ce qui etablit l'identite, c'est le TRANSPORT. On relit donc l'URL publique et l'on exige d'y
# retrouver le commit qu'on vient de construire. « Est-ce la bonne machine ? » est indecidable depuis
# l'exterieur ; « mon deploiement a-t-il atteint l'URL que je pretends deployer ? » a une reponse, et
# c'est maintenant qu'on la connait de source sure. Un cache interpose tombe dans le meme filet.
#
# ⚠ ET LE MARQUEUR GARDE LA TRACE DE CETTE VERIFICATION. Une ligne de controle qui disparait ne crie
# pas -- c'est arrive a ce script meme, un `git reset --hard` l'a emportee et le deploiement suivant
# est passe en silence. `boucle` rend la verification NECESSAIRE : `bin/version-servie.py` refuse de
# conclure quand le champ manque. Sauter la boucle produit un marqueur que le verificateur rejette.
log "Boucle : l'URL publique rend-elle ce qu'on vient de construire ?"
URL_PUBLIQUE="${URL_PUBLIQUE:-https://smartaccess.hector-conseil.com}"
# ⚠ `|| true` EST INDISPENSABLE, ET IL A ETE APPRIS EN CASSANT CETTE GARDE. Sous `set -euo
# pipefail`, un `curl` qui echoue interrompt le script AVANT l'affectation -- donc avant les dix
# lignes d'explication ci-dessous. Eprouve : le script sortait avec le code 22 de curl, sans un mot.
# Une garde contre les echecs muets qui echoue muette ne vaut rien.
#
# L'echec de la lecture est une INFORMATION que le bloc suivant sait interpreter, pas une raison de
# s'arreter avant de l'avoir dite.
SERVI="$(curl -sf -H 'Accept: application/json' "$URL_PUBLIQUE/version.json" 2>/dev/null \
    | sed -n 's/.*"commit":"\([^"]*\)".*/\1/p' || true)"

# ── LA SECONDE BOUCLE : CE QUE PHP A CHARGE ────────────────────────────────────────────────────
#
# La boucle ci-dessus interroge `version.json`, un fichier statique servi par nginx. Elle prouve que
# le FRONTAL est arrive. Elle ne dit rien du serveur : le 31/08, un correctif de cloisonnement etait
# sur le disque et hors d'opcache pendant treize minutes, sans que rien ne le signale.
#
# ⚠ Cette lecture-ci traverse PHP. Elle ne peut donc pas repondre juste si PHP sert du code d'avant.
# ⚠ FPM N'ECOUTE PAS ENCORE QUAND `restart` REND LA MAIN.
#
# La premiere version de ce bloc interrogeait aussitot, lisait vide, et annoncait un echec a CHAQUE
# deploiement -- alors que le point d'entree rendait le bon commit quelques secondes plus tard.
#
# Un controle qui crie pour rien apprend a tout le monde a le sauter, et use la confiance des
# autres controles avec lui. On reessaie donc, BORNE : l'echec apres N tentatives reste un vrai
# echec, on ne remplace pas un faux positif par une patience infinie.
TENTATIVES_CHARGE=10
CHARGE=""
for _ in $(seq 1 "$TENTATIVES_CHARGE"); do
    CHARGE="$(curl -sf -H 'Accept: application/json' "$URL_PUBLIQUE/api/plateforme/version-chargee" 2>/dev/null \
        | sed -n 's/.*"commit":"\([^"]*\)".*/\1/p' || true)"
    [ -n "$CHARGE" ] && break
    sleep 2
done

# ⚠ UN SUCCES MUET EST INDISCERNABLE D'UN CONTROLE SAUTE.
#
# Les deux blocs ci-dessous ne parlaient QUE pour echouer. Leur silence voulait dire « passe » — mais
# il ressemble trait pour trait a un bloc qu'une modification aurait rendu inatteignable. L'en-tete de
# ce fichier dit deja la moitie de la regle : « une garde contre les echecs muets qui echoue muette
# ne vaut rien ». L'autre moitie est ici.
#
# On nomme donc les deux commits compares, meme quand ils concordent : c'est ce qui permet de lire
# un journal de deploiement et de savoir que la verification a EU LIEU.
if [ "$CHARGE" = "$COMMIT_DEPLOYE" ] && [ "$SERVI" = "$COMMIT_DEPLOYE" ]; then
    echo "  ✓ les deux boucles ont bouclé sur $COMMIT_DEPLOYE"
    echo "      servi par $URL_PUBLIQUE : $SERVI"
    echo "      chargé par PHP          : $CHARGE"
fi

if [ "$CHARGE" != "$COMMIT_DEPLOYE" ]; then
    echo
    echo "✗ PHP ne sert pas le code qu'on vient de déployer."
    echo "    déployé ici          : $COMMIT_DEPLOYE"
    echo "    chargé par PHP       : ${CHARGE:-<rien ou illisible>}"
    echo
    echo '  opcache.validate_timestamps=0 : FPM ne relit jamais les fichiers. Il sert le code tel'
    echo "  qu'il était à son dernier démarrage."
    echo
    echo "  ⚠ Lire le fichier dans le conteneur ne prouve rien : le volume est monté, donc le"
    echo "    fichier est frais, et opcache sert quand même une image figée."
    echo
    echo "      docker compose -p billetterie-preprod restart php"
    echo
fi

if [ "$SERVI" != "$COMMIT_DEPLOYE" ]; then
    echo
    echo "✗ La boucle n'a pas bouclé."
    echo "    construit ici : $COMMIT_DEPLOYE"
    echo "    servi par $URL_PUBLIQUE : ${SERVI:-<rien ou illisible>}"
    echo
    echo "  Le déploiement a réussi localement mais n'atteint pas l'URL annoncée."
    echo "  Trois causes possibles, dans cet ordre de fréquence :"
    echo "    · un cache ou un proxy devant ;"
    echo "    · WEB_ROOT ne correspond pas à ce que ce domaine sert ;"
    echo "    · ce n'est pas la machine qui sert ce domaine."
    echo
    echo "  ⚠ Le marqueur reste SANS le champ « boucle » : bin/version-servie.py refusera de"
    echo "    conclure à partir de lui, plutôt que de rendre un commit qu'on ne peut pas garantir."
    exit 1
fi

# La boucle a bouclé : on le grave dans le marqueur, et le vérificateur l'exigera.
printf '{"commit":"%s","branche":"%s","construit":"%s","boucle":"%s","sujet":"%s"}\n' \
    "$COMMIT_DEPLOYE" \
    "$(git rev-parse --abbrev-ref HEAD)" \
    "$(date -Is)" \
    "$URL_PUBLIQUE" \
    "$(git log -1 --format=%s | tr -d '"' | cut -c1-120)" \
    > "$WEB_ROOT/version.json"

# ── LE COURRIEL PART-IL VRAIMENT ? ──────────────────────────────────────────────────────────────
#
# `MAILER_DSN=null://null` avale tout en silence : les six expediteurs du depot s'executent, ne
# levent rien, et aucun message ne part. Un correctif de notification se lira alors comme un
# correctif qui ne marche pas, alors que c'est le transport qui parle.
#
# On ne configure rien ici -- le choix du prestataire appartient a l'exploitant, et un envoi reel
# depuis une preproduction ecrirait a de vraies personnes. On le DIT, c'est tout.
DSN="$("${COMPOSE[@]}" exec -T php php bin/console debug:dotenv MAILER_DSN 2>/dev/null | grep -oE 'null://null|smtp://[^ ]*|sendmail://[^ ]*' | head -1)"
if [ "$DSN" = "null://null" ] || [ -z "$DSN" ]; then
    printf '\n\033[1;33m⚠ AUCUN COURRIEL NE PARTIRA DE CETTE INSTANCE.\033[0m\n'
    echo "  MAILER_DSN vaut « null://null » : le transport nul accepte tout et n'envoie rien."
    echo "  Concerne : mot de passe oublie, invitation d'utilisateur, liste d'attente,"
    echo "  confirmation de commande, relance de panier, rapport planifie."
    echo "  Une invitation qui ne part pas laisse le compte « invite » indefiniment."
fi

# ── ET LE LIEN DANS CE COURRIEL, IL POINTE OU ? ─────────────────────────────────────────────────
#
# `ReinitialisationMailer` et `InvitationMailer` construisent leurs liens depuis `FRONT_BASE_URL`,
# et non depuis l'`Host` de la requete -- ce qui les met a l'abri d'un `Host` choisi par l'appelant,
# mais les rend entierement dependants de cette variable.
#
# Mesure du 04/09 : elle n'etait declaree NULLE PART en preproduction. Symfony retombait sur
# `app/.env`, soit `http://localhost:5173` -- la machine d'un developpeur.
#
# ⚠ LES DEUX DEFAUTS S'ANNULAIENT, ET C'EST LE PIEGE. Le transport nul avale tout : aucun lien mort
# n'etait clique, donc rien ne signalait. Reparer le transport SEUL -- le geste que le bloc
# ci-dessus recommande -- aurait fait partir des liens morts des la premiere invitation.
FRONT_URL="$("${COMPOSE[@]}" exec -T php php bin/console debug:dotenv FRONT_BASE_URL 2>/dev/null | grep -oE 'https?://[^ ]+' | head -1)"
case "${FRONT_URL:-}" in
    *localhost*|*127.0.0.1*|'')
        printf '\n\033[1;33m⚠ LES LIENS ENVOYÉS PAR COURRIEL POINTENT VERS UNE ADRESSE LOCALE.\033[0m\n'
        echo "  FRONT_BASE_URL vaut « ${FRONT_URL:-<non définie>} » : c'est la machine d un développeur,"
        echo "  pas cette instance. Concerne : mot de passe oublié, invitation d utilisateur."
        if [ "$DSN" = "null://null" ] || [ -z "$DSN" ]; then
            echo "  ⚠ Aujourd hui c est sans effet — le transport nul avale tout. MAIS RÉPARER LE"
            echo "  TRANSPORT SEUL FERAIT PARTIR DES LIENS MORTS dès la première invitation."
            echo "  Les deux se réparent ensemble : FRONT_BASE_URL dans app/.env.local, puis MAILER_DSN."
        else
            echo "  ⚠ ET LE TRANSPORT FONCTIONNE : chaque lien déjà envoyé est mort à l arrivée."
            echo "  À corriger avant tout autre chose — un compte invité ne peut pas s activer."
        fi
        ;;
esac

# ── LES TACHES PLANIFIEES TOURNENT-ELLES ? ──────────────────────────────────────────────────────
#
# ⚠ CE BLOC A DECRIT UN DEFAUT QUI N'EXISTE PLUS, ET LA PROSE A SURVECU AU CORRECTIF.
# Il affirmait « ni crontab, ni timer systemd, ni conteneur worker » et « vingt-et-une taches, aucune
# ne s'execute ». C'etait vrai quand il a ete ecrit. Depuis, `infra/ordonnanceur.sh` tourne dans
# `billetterie-preprod-scheduler-1` et lance huit taches chaque minute, par liste blanche.
#
# Le CODE ci-dessous, lui, n'a jamais menti : il cherche un conteneur nomme `scheduler`, le trouve,
# et l'avertissement ne sort pas. Seul le commentaire mentait -- et un commentaire qui decrit un
# defaut corrige fait re-diagnostiquer ce qui va bien. Je m'y suis laisse prendre deux fois.
#
# CE QUI RESTE VRAI, et qui justifie de garder le controle : `sepa:preavis:annoncer` est le SEUL
# emetteur de preavis de prelevement, et `GenerationRemiseHandler` exclut de la remise toute echeance
# non couverte par un preavis delivre. Si l'ordonnanceur s'arretait, aucun prelevement ne pourrait
# plus partir -- en silence, et sans que rien d'autre ne le dise.
#
# On ne demarre rien ici. On le DIT, c'est tout.
TACHES_LANCEES=0
command -v crontab >/dev/null 2>&1 && crontab -l 2>/dev/null | grep -q 'scheduled-tasks' && TACHES_LANCEES=1
systemctl list-timers --all 2>/dev/null | grep -q 'fluvia\|billetterie' && TACHES_LANCEES=1
docker ps --format '{{.Names}}' 2>/dev/null | grep -qiE 'worker|scheduler' && TACHES_LANCEES=1

if [ "$TACHES_LANCEES" = "0" ]; then
    printf '\n\033[1;33m⚠ AUCUNE TÂCHE PLANIFIÉE NE S EXÉCUTE SUR CETTE MACHINE.\033[0m\n'
    echo "  Ni cron, ni timer systemd, ni conteneur worker ne lance l ordonnanceur."
    echo "  Conséquence mesurée : sepa:preavis:annoncer est le seul émetteur de préavis de"
    echo "  prélèvement, et une échéance sans préavis délivré est EXCLUE de la remise."
    echo "  Aucun prélèvement ne peut donc partir — le système refuse de débiter sans prévenir."
    echo "  ⚠ Démarrer l ordonnanceur ne suffira pas : sans transport de courriel, le préavis"
    echo "  sort en « journalisé » et l échéance reste exclue. Le transport d abord."
fi

log "État de la stack"
"${COMPOSE[@]}" ps

# ⚠ AU PASSE, ET EN DERNIER. Ce n'est plus une prevision : la suite est morte, et la personne qui
# la relancera lira « phpunit est absent » — un message qui accuse l'operateur d'avoir oublie de
# reinstaller, alors que c'est CE deploiement qui l'a retire.
if [ -n "${SUITE_TUEE:-}" ]; then
    echo
    echo "  ⚠  CE DÉPLOIEMENT VIENT DE TUER UNE SUITE DE TESTS :"
    for conteneur in $SUITE_TUEE; do
        echo "       $conteneur"
    done
    echo
    echo "     Elle rendra des erreurs de « template introuvable », ou « phpunit est absent »."
    echo "     Ce n'est pas une régression du code : c'est ce déploiement."
    echo "     Préviens la session concernée, puis : ./infra/reinstaller-dev.sh"
    echo
fi

log "Déploiement terminé - $REPO_ROOT"
