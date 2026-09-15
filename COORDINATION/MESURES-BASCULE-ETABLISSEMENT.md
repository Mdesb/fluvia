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

## Résultats — « n'existe pas » à l'ouverture

| Écran | Qui a mesuré | Sur quel code | Avant | Après |
|---|---|---|---|---|
| Sport | cette session | code de la PR #174, fermée comme doublon de #172 — **pas main** | « n'existe pas » à 33 ms (200), à 26 ms (404, avant tout 404) | 0/83 pour « à venir » et « annulée » ; un vrai 404 → spinner puis « n'existe pas » |
| Patinoire | cette session | code de la PR #177, identique à #178 à un nom de variable près — **pas main** | « n'existe pas » à 35, 7, 6 et 8 ms (en cours, rendue, 404, réouverture) | spinner puis état réel dans les quatre cas ; 500 simulé → « n'a pas pu être lue » |
| Sport, et les six de #178 | l'autre session (worktree `rejet-refus-acces`) | worktrees de #172 et #178 | AUTRE → N_EXISTE_PAS → SPINNER (Padel : pas de flash, `TerrainsSection` couvrait le premier rendu) | jamais N_EXISTE_PAS avant la fin de la lecture, pour 200, réouverture, 404 et 500 simulé |

⚠ **Le flash à l'ouverture n'a pas été rejoué sur main avec contre-témoin.** La dernière ligne est
relayée, pas mesurée ici.

## Ce qui n'est pas mesuré

- Le flash à l'ouverture, sur main, pour les sept écrans.
- La garde « ← Retour » pour Sport et Patinoire (bascule mesurée avec la première version de
  l'instrument).
- PlanningTravail sur un vrai créneau : aucun établissement de préprod n'en a.
- La réouverture **après** une action réellement enregistrée (un rejet, un retour, un règlement) :
  ce serait une écriture. On sait seulement que la réouverture relit.
- Un seul objet par écran, et deux établissements par bascule.
- Retours vers l'établissement de départ non conclus dans la fenêtre : Stock sur main (8 s), Padel sur
  le contre-témoin (6 s). Aucun « n'existe pas » périmé sous le départ dans ces deux cas.

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
