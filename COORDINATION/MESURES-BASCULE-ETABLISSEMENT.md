# Écrans ouverts par l'adresse — « n'existe pas » avant lecture, et bascule d'établissement

**Mesuré le 15/09/2026, contre la préprod, en lecture seule.** Ce fichier consigne des mesures, pas
un chantier : les correctifs sont fusionnés. Il sert à ne pas refaire ces mesures, et à savoir
lesquelles manquent encore.

## Les deux défauts

Sept écrans lisent un objet par l'identifiant de l'adresse : Sport (`rejet`), Patinoire (`retour`),
Facturation (`reglement`), FacturesFournisseur (`avoir`), PlanningTravail (`creneau`), Padel
(`reserver`), Stock (`corriger`).

1. **« N'existe pas » avant d'avoir lu.** Le drapeau de chargement était levé *dans* l'effet, donc
   après le premier rendu. Ce rendu affirmait « … n'existe pas, ou n'est pas visible depuis cet
   établissement » le temps d'une trame, même pour un objet servi en 200.
   Corrigé par **#172** (Sport) et **#178** (les six autres) : le chargement se déduit d'une lecture
   gardée avec sa clé, et refermer l'écran oublie la lecture.
2. **La bascule d'établissement laisse l'objet de l'ancien sous le nouveau.** La clé de lecture ne
   portait pas l'établissement ; Facturation et FacturesFournisseur ne relisaient même pas.
   Corrigé par **#185** pour ces trois écrans ; Sport, Patinoire, Padel et Stock portaient déjà
   l'établissement dans la clé depuis #172 et #178.

Cinq autres écrans ouverts par l'adresse ont eu le second défaut, corrigé par **#201** : voir la
section qui leur est consacrée. Les tableaux de mesure ci-dessous portent sur les sept premiers.

## Les cinq écrans de #201 — relayé, sauf le code

**#201** (`3c4ffe71`, fusionnée le 15/09) : AchatsStock (`commande`), Groupes (`panier`, et le
groupe), AudioguidesMusee (`audioguide`), PartenairesOta (`partenaire`), TopologieAcces
(`topologie`). RolesSection (`role`) n'a rien à corriger : `/api/roles` rend les mêmes 58 rôles, avec
les mêmes identifiants, depuis trois établissements.

Deux formes de correctif. AchatsStock et la réservation de Groupes gardent une lecture avec la clé
`identifiant|établissement`. AudioguidesMusee, PartenairesOta et TopologieAcces n'ont **pas de lecture
propre** : l'objet se déduit d'une liste, qui garde l'établissement pour lequel elle a été lue.
⚠ Pour identifier le code servi sur ces deux derniers, compter `|${etabActif}` ne sert à rien : sur
main, il vaut 0 pour AudioguidesMusee et PartenairesOta.

**Bascule d'établissement** — mesurée par la session qui a livré #201, relayée depuis sa description,
**non rejouée ici**. Piscine A → Patinoire B puis retour, garde « ← Retour », contre-témoin `01616e52`.

| Écran | Avant, vers B | Après, vers B | Après, retour vers A |
|---|---|---|---|
| AchatsStock | objet de A à 9 ms | spinner 9 ms → « n'existe pas » | spinner → commande |
| Groupes | réservation de A à 19 ms | spinner 12 ms → « n'existe pas » ; nom du groupe jamais affiché sous B | spinner → réservation |
| AudioguidesMusee | objet de A à 10 ms, **puis de nouveau de 586 ms à 4,07 s** | spinner 7 ms → « n'existe pas » | spinner → formulaire |
| PartenairesOta | objet de A à 9 ms | spinner 7 ms → « n'existe pas » → renvoi à la caisse | non mesurable (renvoi) |
| TopologieAcces | objet de A à 9 ms, **puis formulaire de A jusqu'à la fin des 8 s** | spinner 9 ms → « n'existe pas » | spinner → formulaire |

Avant, au retour, « n'existe pas » s'affichait sous A (Groupes à 12 ms, AudioguidesMusee à 7 ms) ;
après : spinner, puis l'objet. PartenairesOta renvoie à la caisse sous Patinoire B, qui n'a pas la
capacité boutique. Des passages ont été écartés (lien navigateur–préprod calé, jeton expiré).

**« N'existe pas » à l'ouverture** — mesuré par la même session sur main `eccd2c3e`, contre-témoin =
main dont seul #201 est retiré (`git apply -R`), deux ouvertures par écran, garde « ← Retour », liste
stable depuis 1 s. **Relayé.** Résultat : **0 flash sur main comme sur le contre-témoin, donc cette
mesure ne prouve rien sur ces cinq écrans** — l'ancien code ne pouvait pas y clignoter. Les causes
ont été **vérifiées ici dans le code d'avant #201** (`3c4ffe71^`) :

- **AchatsStock** : `chargement` démarre à `true` (`AchatsStock.jsx:38`) et son spinner est rendu
  avant la branche `commande` (l. 141) — le premier rendu est couvert, comme Padel.
- **Groupes** : `lectureReservation === null` était déjà traité comme un chargement (l. 264, 354).
- **AudioguidesMusee, PartenairesOta** : aucun appel `api.…(params.…)`, l'objet est cherché dans une
  liste. **TopologieAcces** : l'effet qui ouvre le formulaire dépend des listes (l. 466–483) ; les
  seuls appels avec un identifiant sont des écritures.

Autrement dit, #201 corrige des fuites à la bascule sur ces écrans, pas un flash à l'ouverture qui
n'y existait pas.

## Méthode

- Worktree détaché, Vite branché sur l'API de préprod, tunnel SSH, jeton `lexik:jwt:generate-token`.
- Ouverture par changement de hash, sans rechargement. Bascule par le `select` de l'en-tête (setter
  natif de `value`, puis un événement `change`).
- Échantillonnage par `MutationObserver` (chaque commit du DOM) et toutes les 50 ms.
- On attend que l'objet soit **affiché** avant de basculer ; sinon le passage est écarté.
- **Garde « ← Retour »** (écrans marqués ✚ ci-dessous) : un état ne compte « écran » que si le bouton
  de retour de l'écran est rendu, et spinner, objet et bandeau sont cherchés dans son conteneur.
- **Contre-témoin** : le même instrument, sans rechargement, sur le code d'avant le correctif. Un
  zéro sans contre-témoin qui fuit ne prouve rien.
- **Zéro écriture** : `fetch` intercepté, toute requête autre que GET/HEAD rendait 418 sans partir.
  Aucune n'a été tentée.

## Résultats — bascule d'établissement, mesurée sur main avec contre-témoin

« Fuite » = échantillon montrant, sous l'établissement d'arrivée, l'objet lu depuis celui de départ.

| Écran | Objet · départ → arrivée | Code mesuré | Résultat | Contre-témoin (code d'avant) |
|---|---|---|---|---|
| Sport | échéance `d3fab3df` · Piscine A (200) → Patinoire B (404) | `aa054514` | spinner 12 ms → « n'existe pas » 469 ms · **0/85** | `58ce9869` : état « à venir » de A sous B à 7 ms, spinner à 11 ms |
| Patinoire | location `913f3e90` · A (200) → B (404) | `aa054514` | spinner 10 ms → « n'existe pas » 351 ms · **0/85** | `a25dac9e` : formulaire de A sous B à 11 ms, spinner à 15 ms |
| Facturation ✚ | facture `315f9eb5` · A (200) → B (404) | `8e973f8b` (#185) | spinner 14 ms → « n'existe pas » 244 ms · **0/85** · retour A : spinner → formulaire | `980bb409` : formulaire de A sous B **84/84 pendant 4 s**, aucun spinner, aucune relecture |
| FacturesFournisseur ✚ | facture fournisseur `575e9438` (annulée) · A (200) → B (404) | `8e973f8b` | spinner 8 ms → « n'existe pas » 360 ms · **0/85** · retour A : spinner → objet | `980bb409` : objet de A sous B **84/84**, aucun spinner |
| PlanningTravail ✚ | créneau **fabriqué** côté client · A (200) → B (404) | `8e973f8b` | spinner 7 ms → « n'existe pas » 309 ms · **0/85** · retour A : spinner → créneau | `980bb409` : créneau de A sous B jusqu'à 319 ms (8 éch.) ; au retour vers A, « n'existe pas » sous A pendant 300 ms |
| Padel ✚ | terrain `75bb243a` · A (200) → B (404) | `37df88de` | spinner 10 ms → « n'existe pas » 2,5 s · **0/105** · retour A : spinner → terrain | `37df88de` sans le bloc #178 : terrain de A sous B à 9 ms (2 éch.), spinner à 11 ms |
| Stock ✚ | article `006332d6` · GI-ONE (200) → B (404) | `37df88de` | spinner 6 ms → « n'existe pas » 7,2 s · **0/163** | `37df88de` sans le bloc #178 : article de GI-ONE sous B à 9 ms (2 éch.), spinner à 11 ms |

Avec la garde « ← Retour » : 0 échantillon hors écran et 0 spinner hors du conteneur de l'écran, dans
les deux versions et les deux sens.

## Résultats — « n'existe pas » à l'ouverture, mesuré sur main avec contre-témoin ✚

Main `ea690b3b`. Contre-témoin : le même main, dont seul le bloc de lecture est ramené à l'état
d'avant le correctif — bloc de #172 pour Sport, de #178 pour Patinoire, Padel et Stock, de #185 puis
#178 pour Facturation, FacturesFournisseur et PlanningTravail. Chaque bloc a été retrouvé une seule
fois, la clé `|${etabActif}` passe de 2 à 0, et l'ancien drapeau `setChargement…` revient (0 → 3).

Ouverture depuis la liste **déjà chargée** (aucun spinner depuis 1 s), par changement de hash, jusqu'à
l'objet affiché. « Flash » = échantillon « n'existe pas » dans l'écran avant l'objet.

| Écran | Sur main | Contre-témoin |
|---|---|---|
| Sport | spinner 6 ms → objet 296 ms · **0 flash** | « n'existe pas » @3 → spinner @5 → objet 287 ms · **1** |
| Patinoire | spinner 3 ms → formulaire 235 ms · **0** | @2 → spinner @3 → 258 ms · **1** |
| Facturation | spinner 3 ms → formulaire 513 ms · **0** | @2 → spinner @3 → 311 ms · **1** |
| FacturesFournisseur | spinner 3 ms → objet 179 ms · **0** | @2 → spinner @3 → 205 ms · **1** |
| PlanningTravail (créneau fabriqué) | spinner 2 ms → créneau 322 ms · **0** | @1 → spinner @2 → 317 ms · **1** |
| Stock (depuis GI-ONE) | spinner 17 ms → article 298 ms · **0** | @14 → spinner @18 → 242 ms · **1** |
| Padel | spinner 4 ms → terrain 573 ms · **0** | spinner @4 → terrain 541 ms · **0** |

⚠ **Padel : le contre-témoin ne clignote pas**, donc le zéro de main n'y est pas prouvé par cette
mesure. Sur ce chemin, un chargement de la page couvre le premier rendu même dans l'ancien code (même
constat que la session qui a livré #178). Le défaut existe dans le code ; ce parcours ne l'atteint pas.

Garde « ← Retour » : 0 échantillon hors écran après l'ouverture, 0 spinner hors du conteneur. Aucun
échec réseau, aucune requête hors GET.

### Mesures antérieures, gardées pour l'historique

| Écran | Qui a mesuré | Sur quel code | Avant | Après |
|---|---|---|---|---|
| Sport | cette session | code de la PR #174, fermée comme doublon de #172 — **pas main** | « n'existe pas » à 33 ms (200), à 26 ms (404, avant tout 404) | 0/83 pour « à venir » et « annulée » ; un vrai 404 → spinner puis « n'existe pas » |
| Patinoire | cette session | code de la PR #177, identique à #178 à un nom de variable près — **pas main** | « n'existe pas » à 35, 7, 6 et 8 ms (en cours, rendue, 404, réouverture) | spinner puis état réel dans les quatre cas ; 500 simulé → « n'a pas pu être lue » |
| Sport, et les six de #178 | l'autre session (worktree `rejet-refus-acces`) | worktrees de #172 et #178 | AUTRE → N_EXISTE_PAS → SPINNER (Padel : pas de flash, `TerrainsSection` couvrait le premier rendu) | jamais N_EXISTE_PAS avant la fin de la lecture, pour 200, réouverture, 404 et 500 simulé |

Ces mesures antérieures sont **remplacées par la mesure sur main ci-dessus**. Les deux premières
lignes portaient sur le code de PR fermées, pas sur main. La dernière est relayée, pas mesurée ici.

## Le sens retour — un « n'existe pas » périmé sous l'établissement de départ

L'ancien code a une seconde fuite, au retour : si l'écran affiche déjà le « n'existe pas » reçu de
l'établissement d'arrivée, rebasculer vers le départ le montre une trame sous le départ.

⚠ **Cette fuite ne peut apparaître que si l'aller a déjà reçu son 404 au moment du retour.** Sinon
il n'y a rien de périmé à montrer, et son absence ne prouve rien.

| Écran | Code d'avant | Sur main |
|---|---|---|
| PlanningTravail | **fuite** : « n'existe pas » sous Piscine A de 7 à 312 ms (l'aller avait son 404 à 319 ms) | aucune ; aller à 309 ms, retour : spinner → créneau |
| Facturation, FacturesFournisseur | pas de fuite possible : l'ancien code ne relisait pas, l'aller n'a jamais affiché « n'existe pas » | aucune ; allers à 244 et 360 ms, retours : spinner → objet |
| Padel, Stock | **non concluant ici** : avec un tunnel à 16–18 s, l'aller est resté sur le spinner jusqu'au retour (121 et 161 éch.) | aucune ; allers à 2,5 s et 7,2 s. Retour Stock resté 8 s sur le spinner (objet non réaffiché dans la fenêtre) |
| Sport, Patinoire | sens retour non échantillonné ici | sens retour non échantillonné ici |

**Relayé, non rejoué ici** : l'autre session, avec un 404 arrivé avant le retour (3,7 à 4,7 s dans
une fenêtre de 8 à 10 s), mesure sur le code d'avant une trame de « n'existe pas » sous le départ
pour Sport (@9 → spinner @10), Patinoire (@6 → 7), Padel (@7 → 9) et Stock (@6 → 8), et aucune sur
main.

## Ce qui n'est pas mesuré

- Le zéro de Padel à l'ouverture : son contre-témoin ne clignote pas sur ce parcours (voir plus haut).
- À l'ouverture sur main, un identifiant inconnu (vrai 404) et une lecture en 500 : seul un objet
  existant a été ouvert.
- La garde « ← Retour » pour Sport et Patinoire (bascule mesurée avec la première version de
  l'instrument), et leur sens retour.
- La fuite au retour sur le code d'avant pour Padel et Stock, par cette session (voir ci-dessus).
- PlanningTravail sur un vrai créneau : aucun établissement de préprod n'en a.
- La réouverture **après** une action réellement enregistrée (un rejet, un retour, un règlement) :
  ce serait une écriture. On sait seulement que la réouverture relit.
- Un seul objet par écran, et deux établissements par bascule.

## Pièges d'instrument rencontrés

- **Classer sur la phrase complète.** Le bandeau d'échec contient lui aussi « elle n'existe pas » : un
  motif court lit un 500 comme un 404.
- **La liste encore affichée se lit comme l'objet** après un changement de hash : d'où la garde
  « ← Retour ».
- **Vite retire les commentaires du module servi.** Pour savoir quel code tourne, compter
  `|${etabActif}` dans le module servi, pas un commentaire.
- **Poser un hash identique ne déclenche rien** : ni `hashchange`, ni relecture. Un passage qui
  commence par le même hash que le précédent part d'un état périmé.
- **`net::ERR_NETWORK_CHANGED`** côté poste : une requête vers l'article a échoué pendant un passage
  Stock, et l'écran a fini sur « n'a pas pu être lu » alors que la même lecture rend 404 par `curl`.
  La coïncidence est constatée ; le lien entre cette requête-là et cette lecture-là ne l'est pas.
  Compter les échecs réseau pendant le passage, et l'écarter s'il y en a.
- **Tunnel lent** : jusqu'à 18 s par fichier par le tunnel, 6 ms en direct sur le VPS. Les durées
  sont gonflées ; les fuites dépendent de l'ordre des états, pas du délai.
- **Panneau du navigateur masqué** : `requestAnimationFrame` ne tourne pas. Le `MutationObserver`
  voit quand même chaque commit.
- **Contre-témoin par fichier entier** : faux dès qu'un commit ultérieur a touché le fichier (#182 sur
  `Stock.jsx`). Retirer seulement le bloc du correctif, et vérifier que la copie ne diffère de main
  que de ce bloc (28 lignes par fichier ici ; identique à `git apply -R` pour Padel).
- **L'outil JavaScript du navigateur abandonne à 45 s** : lancer la mesure en tâche de fond dans la
  page et relire le résultat par appels courts.
