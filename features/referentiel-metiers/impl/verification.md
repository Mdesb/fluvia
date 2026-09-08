# Ce qui a été vérifié, et par quel chemin

Ce fichier existe parce qu'un « c'est vert » ne dit pas *où*. Il nomme l'artefact mesuré pour chaque
affirmation, et surtout **ce qu'aucune de ces mesures ne traverse**.

## Mesuré, et par quoi

| Affirmation | Chemin de mesure |
|---|---|
| 55 garde-fous verts, code 0 | `./bin/garde-fous.sh` dans le worktree `vitrine`, après fusion de `main` |
| Les garde-fous refusent ce qu'ils prétendent refuser | `./bin/essai-garde-fous.sh` — 20 cas, tous conformes, sur un clone jetable |
| Le garde-fou n° 55 mord dans les deux sens | 4 cassures volontaires : cas sans entrée, entrée sans cas, entrée sans activités, analyseur muet |
| 55 tests / 298 assertions | `./infra/test-stack.sh run vitrine tests/Website` |
| Le rendu des 9 surfaces ne change pas | `FallbackParityTest`, base vide puis base semée, comparaison octet à octet **dans le même processus** |
| La bascule lit vraiment les lignes | un nom semé DIFFÉRENT du repli doit changer le rendu — sinon « identique » voudrait dire « la base est ignorée » |
| Chaque test neuf mesure ce qu'il annonce | vu ROUGE d'abord, par une mutation ciblée, puis vert une fois la mutation retirée |
| Le frontal compile | `npm run build` |
| L'écart client/serveur ne grandit pas | `node frontend/scripts/garde-fou-ecart.mjs` — 524 inatteignables, plafond 524 |

## ⚠ Ce qu'aucune de ces mesures ne traverse

**La CI n'a pas tourné sur ce lot.** Les commits des étapes 1 et 2 portent 3 et 2 contrôles ; tous
ceux d'après, zéro. Tout ce qui précède a donc été mesuré **sur le VPS, par moi**, jamais par un
tiers sur une machine neuve. Ce n'est pas la même garantie : un environnement local porte des
dépendances installées, un cache, et un état de base qu'une machine de CI n'a pas.

**La production n'a pas été touchée.** Aucune ligne n'existe en base sur les environnements servis :
le site sert toujours la liste de repli, et c'est l'état attendu tant que `website:trades:seed` n'a
pas tourné. Un garde-fou vert et une base vide se ressemblent beaucoup — c'est le risque R-6 du plan,
et c'est le total imprimé par la commande qui les sépare.

**L'écran n'a pas été ouvert dans un navigateur.** `npm run build` prouve qu'il compile, pas qu'il
s'affiche ni que ses appels aboutissent. Les opérations d'API sont couvertes par `EditorTradeApiTest`
en HTTP réel ; le pont entre l'écran et ces opérations, lui, n'a été vérifié par personne.

## Un obstacle d'outillage rencontré en route

Une réécriture git **globale** sur le VPS convertit toute URL `git@github.com:` en `https://` :

    url.https://github.com/.insteadOf = git@github.com:

Elle est **nécessaire** — la clé SSH de la machine authentifie (`Hi Mdesb/fluvia!`) mais n'a pas
accès au dépôt, et sans cette ligne toute poussée échoue par « Repository not found ».

⚠ Elle rend aussi tout diagnostic SSH trompeur : une commande qui croit tester SSH teste HTTPS.
Le contournement apparemment évident — `git -c url."https://github.com/".insteadOf= …` — est **pire
que rien** : un préfixe vide correspond à *toutes* les URL, donc il réécrit tout. La seule mesure
honnête est de retirer la ligne, mesurer, et la remettre.
