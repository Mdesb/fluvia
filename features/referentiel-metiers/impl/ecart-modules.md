# Écart entre les modules déduits et les préréglages

**Date :** 2026-09-07 · **Étape 4** du plan `referentiel-metiers` · **Critère d'acceptation G-3**

> **G-3 ne demande pas zéro écart.** « Un écart n'est pas forcément un défaut du calcul : il peut
> révéler que le préréglage a dérivé. Le critère est que l'écart soit **connu et justifié**, pas
> qu'il soit nul. » Ce document est cette justification, ligne par ligne.

Mesure : `website:trades:modules-diff`, sur les cinq métiers matérialisés.
**5 métiers comparés, 21 écarts.**

| métier | déduit, absent du préréglage | préréglé, non déduit |
|---|---|---|
| piscine | `location_materiel`, `no_show` | `porte_monnaie`, `poss`, `recouvrement`, `sepa` |
| sport | — | `acces_nocturne`, `no_show` |
| padel | `casiers`, `location_materiel` | `boutique_en_ligne`, `controle_acces`, `porte_monnaie` |
| patinoire | — | `encadrants`, `reservation` |
| musee | `casiers`, `location_materiel`, `porte_monnaie`, `recouvrement`, `sepa` | `boutique_en_ligne` |

---

## Les 21 écarts se rangent en trois causes, pas une

### Cause A — Le classement fonctionne comme prévu (2 écarts, **rien à corriger**)

`poss` (piscine) et `acces_nocturne` (sport) apparaissent comme « préréglés, non déduits ». **C'est
le résultat attendu, pas un trou.** Les deux sont classés `OwnRule` : `composition.md` les nomme
explicitement parmi « ce qui n'entre dans aucune brique — une règle réellement nouvelle », le seul
cas où D15 autorise un module de code à subsister.

Une déduction qui les proposerait aurait un défaut : elle suggérerait à un exploitant un module
qu'aucune de ses activités ne justifie.

**Verdict : conforme. Ces deux écarts doivent rester.**

### Cause B — `composition.md` décrit le caractéristique, pas l'exhaustif (12 écarts)

Douze écarts sont des capacités préréglées que la déduction ne propose pas, **parce que l'activité
qui les servirait n'est pas déclarée** dans `composition.md` :

| métier | capacité | l'activité qui la servirait | le métier la pratique-t-il ? |
|---|---|---|---|
| piscine | `sepa`, `recouvrement`, `porte_monnaie` | `membership` | **oui** — une piscine vend des abonnements |
| sport | `no_show` | `resource_booking` | **oui** — les cours collectifs se réservent |
| padel | `controle_acces` | `entry` | **oui** — l'accès au club est contrôlé |
| padel | `boutique_en_ligne` | `product_sale` | **oui** — le padel vend à distance |
| padel | `porte_monnaie` | `membership` | **oui** |
| patinoire | `reservation` | `resource_booking` | **oui** — créneaux publics et scolaires |
| patinoire | `encadrants` | `coaching` | **oui** — cours de patinage |
| musee | `boutique_en_ligne` | `product_sale` | **oui** — billetterie en ligne |

⚠ **La déduction n'est pas fausse : la description est incomplète.** `composition.md` liste ce qui
CARACTÉRISE chaque verticale — ce par quoi on la reconnaît — pas tout ce qu'elle pratique. C'est un
résumé, et il a été écrit comme tel : son propre titre annonce un « inventaire », et son verdict
porte sur la question « les neuf briques tiennent-elles ? », pas sur « chaque verticale est-elle
décrite exhaustivement ? ».

**Verdict : ce n'est pas le calcul qu'il faut corriger, ce sont les activités déclarées.** Elles
sont des **données** dans le référentiel : les compléter ne demande aucun déploiement, et c'est
exactement ce que le chantier rend possible. À faire avec l'arbitrage de Maxime, métier par métier,
au moment où l'étape 3 du tunnel s'en servira.

⚠ **Et cela ne se corrige pas en douce.** Ajouter `membership` à la piscine change les modules
suggérés à un futur client. C'est une décision commerciale, pas un ajustement technique.

### Cause C — `equipment_rental` est trop grossier, et le remède est déjà spécifié (7 écarts)

Sept écarts viennent tous du même endroit : la déduction émet **`location_materiel` ET `casiers`**
pour toute activité `equipment_rental`, alors que le métier n'en veut qu'un.

| métier | ce que le métier loue | déduit à tort |
|---|---|---|
| piscine | des **casiers** | `location_materiel` |
| padel | du matériel, **par son propre module** | `casiers` et `location_materiel` |
| musee | des **audioguides** | `casiers`, `location_materiel` |

⚠ **`paquet.md` porte déjà le remède, et je ne l'ai pas utilisé.** Sa forme n'est pas
`- type: equipment_rental` seul, mais :

```yaml
  - type: equipment_rental
    subject: locker
```

Le `subject` — casier, patins, audioguide — dit **laquelle** des capacités de location s'applique.
Ma table le perd, donc elle propose les deux.

⚠ **Et le padel est un cas à part, déjà mesuré** : sa location n'est gardée par **aucune** capacité.
Elle vit dans le module padel, sous la permission `padel.lire`. Aucun `subject` ne la ferait
apparaître, et c'est correct — c'est une fonctionnalité de verticale, pas un module vendable.

**Verdict : défaut réel de la déduction, remède connu.** Le modèle porte déjà `TradeActivity`, une
table fille : ajouter une colonne `subject` y coûte une migration additive. **Hors périmètre de ce
lot** — la spec ne le demande pas, et l'ajouter maintenant élargirait une étape qui doit rester
inerte. À ouvrir en Issue.

---

## Ce que cette mesure a réellement démontré

**Elle a trouvé un défaut de ma propre table**, ce qu'un critère « zéro écart » aurait masqué : si
j'avais dû faire concorder les chiffres, j'aurais ajusté la table métier par métier jusqu'à ce que
la somme tombe juste — et le `subject` manquant serait resté invisible, pour ressortir au premier
métier créé en base.

**Elle confirme que trois sources décrivent la même chose et ne parlent pas de la même chose** :
`PresetVerticale` liste des capacités, `composition.md` liste des activités, l'écran « Ouvrir une
structure » affiche une phrase. Comparer les deux premières, c'est comparer une conséquence à une
cause. Rien dans le dépôt ne les confrontait avant cette commande.

**Elle ne referme rien, et c'est voulu.** La commande rend toujours 0. Faire échouer une fusion sur
un écart aurait poussé à effacer le signal au lieu de le lire.

## Ce qui reste à trancher, et par qui

| # | À décider | Par |
|---|---|---|
| 1 | Compléter les activités déclarées des cinq métiers (cause B) — décision commerciale : elle change ce qu'on suggère à un futur client | Maxime |
| 2 | Ajouter `subject` à `TradeActivity` (cause C) — Issue, hors de ce lot | session vitrine |
| 3 | Le `location_materiel` du padel : sa location vit dans la verticale, pas dans un module vendable. Le préréglage a-t-il raison de ne pas l'activer ? | Maxime |
