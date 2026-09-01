#!/usr/bin/env bash
#
# Remet les dépendances de DÉVELOPPEMENT sur la préproduction, et redémarre PHP-FPM.
#
# ── ⚠ IL SALIT UN FICHIER SUIVI, ET `git add -A` LE COMMITERAIT ────────────────────────────────
#
# `composer install` avec les dependances de dev regenere `app/config/reference.php` en y ajoutant
# une section `when@inspection` qui n'existe QUE dans cet environnement. Le fichier est suivi : un
# `git add -A` apres ce script l'emporte dans le commit, et le prochain deploiement en `--no-dev` la
# retire — le fichier oscille d'un commit a l'autre sans que personne ne l'ait voulu.
#
# Constate le 02/09 : rattrape avant le commit, par hasard, en relisant `git status`.
#
#     git restore app/config/reference.php
#
# ── POURQUOI CE SCRIPT EXISTE ───────────────────────────────────────────────────────────────────
#
# `deploy-preprod.sh` installe en `--no-dev`, ce qui retire PHPUnit : les autres sessions ne peuvent
# plus lancer la suite. On réinstalle donc avec les dépendances de dev juste après.
#
# **Ce geste, fait à la main, a cassé la préproduction pendant des heures.**
#
#     opcache.validate_timestamps = Off    FPM ne relit JAMAIS un fichier modifié
#     conteneur démarré à                  17:55:41
#     vendor/composer/autoload_static.php  17:56:19   ← réécrit APRÈS
#
# `composer install` réécrit l'autoloader. Sans redémarrage, FPM continue de servir la version
# compilée d'AVANT — celle qui ne connaît pas les classes fraîchement installées. La CLI, elle, lit
# le fichier courant : `class_exists()` répondait `true` en ligne de commande et « not found » en
# FPM, **et les deux disaient la vérité**.
#
# Symptôme constaté : `POST /mot-de-passe/oublie` en 500,
# « Class Symfony\Component\HttpClient\UriTemplateHttpClient not found », alors que le fichier était
# bien là.
#
# ⚠ LE DIAGNOSTIC A D'ABORD ÉTÉ FAUX, et c'est pour cela que ce commentaire est long. Le même jour,
# `symfony/http-client` avait été trouvé en `require-dev` alors que huit classes de production
# l'importent — un vrai défaut, latent, corrigé depuis. Les deux faits étaient vrais ; le lien entre
# eux ne l'était pas. La preuve : redémarrer PHP-FPM **sans changer une ligne de code** fait passer
# la route de 500 à 202.
#
#   > Deux faits vrais côte à côte ne font pas une cause.
#
# ── LA RÈGLE ────────────────────────────────────────────────────────────────────────────────────
#
# Toute écriture dans `vendor/` sur ce serveur doit être suivie d'un redémarrage de PHP-FPM. Ce
# script le fait ; ne réinstalle pas à la main.
set -euo pipefail

cd "$(dirname "$0")/.."
COMPOSE=(docker compose -f infra/compose.preprod.yaml --env-file infra/.env.preprod)

echo "==> Dépendances de développement (PHPUnit pour les autres sessions)"
"${COMPOSE[@]}" exec -T php composer install --no-interaction

# ⚠ COMPOSER TOURNE EN ROOT, ET CE SCRIPT NE RENDAIT PAS LES DROITS.
#
# `composer install` declenche les `auto-scripts` de Flex, dont `cache:clear` — en root. Les
# fichiers reecrits appartiennent alors a root, et PHP-FPM, qui tourne en www-data, ne peut plus les
# REECRIRE. Il peut encore les lire : l'API repond, jusqu'au premier defaut de cache. Puis 500.
#
# C'est le meme defaut que celui qui a mis la preproduction a terre le 01/09, dans le script cense
# suivre celui qui l'a cause. `deploy-preprod.sh` faisait ce chown depuis toujours ; celui-ci, non.
echo "==> Droits sur var/ — composer a tourne en root"
docker compose -f infra/compose.preprod.yaml --env-file infra/.env.preprod     exec -T php chown -R www-data:www-data /app/var
./infra/verifier-droits-var.sh

echo "==> Redémarrage de PHP-FPM — SANS LUI, FPM sert l'autoloader d'avant (opcache)"
"${COMPOSE[@]}" restart php

echo "==> Vérification : une route qui construit un client HTTP"
sleep 6
code=$(curl -s -o /dev/null -w '%{http_code}' -X POST \
    https://smartaccess.hector-conseil.com/mot-de-passe/oublie \
    -H 'Content-Type: application/json' \
    -d '{"email":"admin@itcotation.com"}')
echo "    POST /mot-de-passe/oublie -> $code"

if [ "$code" -ge 500 ]; then
    echo "    ✗ FPM sert encore du code périmé, ou autre chose est cassé." >&2
    exit 1
fi
echo "    ✓ La préproduction répond."
