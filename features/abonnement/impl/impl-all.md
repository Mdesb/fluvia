# Suivi d'implémentation — abonnement (transverse)

**État :** review <!-- discovery → spec → plan → build → review → done -->
**Branche :** feature/abonnement-socle-lot0
**Spec :** features/abonnement/specs/spec-abonnement-transverse.md
**Plan :** features/abonnement/plans/plan-abonnement-socle-lot0.md

**Portée de cette itération : le LOT 0 seulement** (le socle, §6 de la spec). Les lots 1 à 3 —
bascule, cycle de vie et avoirs, onglet Exploitation — feront chacun leur propre plan.

## Checkpoints

- [x] **CP-1** — spec validée par l'humain — Maxime, 07/09 (commit `6df661d8`, « nom: abonnement »)
- [x] **CP-2** — porte automatique (CLAUDE.md : « CP-1 est le seul checkpoint de jugement »). Les deux
      questions du §5 du plan ont été confirmées par Maxime le 10/09 : nom `Membership`, `capability(): null`.
- [ ] **CP-3** — revue avant merge — *PR ouverte en brouillon, en attente du feu vert humain*

## Étapes (reprises du plan)

- [x] Étape 1 — Le manifeste du module (couvre G-1, G-2)
- [x] Étape 2 — Les deux énumérations (couvre G-3)
- [x] Étape 3 — L'entité (couvre G-3)
- [x] Étape 4 — Le cloisonnement (couvre G-4)
- [x] Étape 5 — La migration (couvre G-5)
- [x] Étape 6 — Le test du socle (couvre G-6)
- [x] Étape 7 — La suite complète, non modifiée (couvre G-6)

## Journal de Session

**10/09 — ouverture du lot 0, et l'aveu qui va avec.** Trois PR (74, 75, 76) avaient été écrites sans
passer par `/aiguiller`. Vérification après coup contre le test d'éligibilité de l'aiguilleur : la 74
relève du SDD complet sur deux conditions (zone paiements, capacité utilisateur nouvelle). Elle est
suspendue et commentée. La 76 relève du lot 2 et s'installe dans l'écran que le lot 1 doit vider ;
gardée sur proposition motivée (elle arrête un prélèvement indu en cours), décision laissée à Maxime.
La 75 est hors du périmètre de cette spec.

**10/09 — la spec se trompe sur un point, et c'est décisif.** §2 tiret 1 : « avec une capability
ajoutée à `CapaciteCode` (sinon le module est présent mais inaccessible — RG-PLAT) ». Mesuré dans
`app/src/Platform/Module/ModuleManifest.php:9-24` : `capability()` rendant `null` désigne au contraire
un **service transverse**, cas prévu et exempté du garde-fou n°41. Le cas « présent mais inaccessible »
est celui d'un code **inventé, absent du catalogue**. Le plan propose donc `null` (décision D-2), en le
signalant comme une contradiction assumée avec le CP-1, à trancher en CP-2.

**10/09 — le nom validé en CP-1 ne peut pas être porté par du code.** `abonnement` est au lexique
français que le garde-fou n°2 refuse dans tout fichier ajouté (`bin/nommage.lexique-francais.txt:68`),
et `App\Subscription` est déjà pris par la facturation SaaS de l'éditeur. Le plan propose `Membership`
(décision D-1). Le CP-1 portait sur le mot **produit**, qui reste « abonnement » à l'écran ; D5 sépare
le technique de la présentation.

**10/09 — contrainte d'outillage qui force une entorse à D5.** Les extensions de périmètre écrivent
`IDENTITY(%s.etablissement)` en dur et le garde-fou n°28 refuse un autre nom : la propriété de
cloisonnement reste donc `etablissement`, en français, comme dans `App\Group` qui est pourtant un
module à nommage anglais. Décision D-3.

**10/09 — ligne de base des garde-fous établie AVANT toute écriture** sur la branche vierge : 50
verts, code de sortie 0. Tout rouge ultérieur est imputable à ce lot, pas hérité.

**10/09 — défaut repéré au passage, hors périmètre.** L'écran Abonnements traduit des statuts que le
serveur n'émet jamais (`suspendu`, `en_pause`, `expire`) et laisse en code brut ceux qu'il émet
(`pause`, `impaye`, `echu`). Signalé pour une session dédiée, non traité ici.

**10/09 — deux mesures ont corrigé le plan pendant l'écriture, et c'est le principal apport de la
phase Construire.** (1) Le plan annonçait une clé étrangère vers `sport_mandat_sepa_fitness`, nom lu
dans la migration de création de `sport_abonnement_fitness`. Doctrine dit `sepa_mandat` : le mandat a
déménagé depuis. Une clé étrangère vers une table disparue échoue **en cours de déploiement**, comme
le 07/09 où un errno 150 a bloqué toutes les mises en production. (2) Le plan supposait un cas
`Personnalise` dans `PeriodiciteAbonnementFitness` ; il appartient en fait à `PeriodiciteFormule`, et
l'énumération Sport le REFUSE déjà explicitement. Le commentaire a été réécrit sur la mesure.

**10/09 — la migration est prouvée équivalente au mapping, pas supposée telle.** La base de test est
montée PAR MAPPING, donc `migrations:migrate` ne peut pas y rejouer l'historique. Vérification faite
autrement : exécution de la seule migration du lot (`migrations:execute --up`), puis
`doctrine:schema:update --dump-sql` — `membership` a disparu de l'écart, seule subsiste une dérive
héritée sur `finance_treasury_cash_alert`. `--down` a ensuite été exécutée et la table a bien disparu.

**10/09 — D-5 vérifiée par la mesure, pas par l'intention.** L'écart client/serveur est resté à **504
opérations inatteignables** avant et après le lot, plafond 521 : le socle n'a consommé aucun des
dix-sept crans de marge, ce qui est exactement ce que « aucune exposition d'API » devait produire.

## Journal de Rétropropagation

**10/09 — FAIT.** `spec-abonnement-transverse.md` corrigée sur trois points, avec l'accord de Maxime :
§2 (la justification « sinon le module est inaccessible » était fausse, et le nom technique est
`Membership`), §6 (le lot 0 ne pose plus de capability et n'expose rien), §8 question 1 (close).
La spec dit désormais ce que le code fait ; sans quoi le prochain lecteur referait le même
raisonnement à partir de la même erreur.

**À faire au moment du CP-3, si CP-2 confirme D-2 :** corriger le §2 tiret 1 de
`spec-abonnement-transverse.md`, dont la justification (« sinon le module est présent mais
inaccessible ») est inexacte. La spec doit dire ce que le code fait, sinon le prochain lecteur
refera le même raisonnement.
