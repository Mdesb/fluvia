# Spec — Résolveur de vocabulaire des verticales (#100)

> **Statut : brouillon, en attente de CP‑1 (Maxime).** Feature **noyau** (`app/src/Fonctionnalite`, périmètre claude‑A). Rend enfin utile le catalogue de 12 clés déjà déclaré et testé (`specs/verticales/vocabulaire.md`, `VocabulaireManifesteTest`). Audit du 14/09, geste 7.

## Le problème

Un « créneau » est une *réservation de terrain* au padel et un *créneau public* à la piscine : même concept, mots différents. Les 5 manifestes verticale portent déjà leur vocabulaire (`settingsSchema()['vocabulary']`), mais **aucun résolveur ne l'active** : un padeliste lit « Ressource » et « Créneau » à l'écran. Sans i18n dans le dépôt, le catalogue est exact et inerte.

## Périmètre

**Dans le lot :** un service noyau qui résout une clé de vocabulaire → libellé pour l'établissement courant ; une API qui expose le vocabulaire résolu ; une consommation frontend, appliquée **d'abord** à l'écran de réservation.

**Hors lot** (repris de `vocabulaire.md`, à ne pas ré‑ouvrir) : une i18n multi‑langue complète ; les **états** (`réservé`…) ; les **objets propres à un métier** (`Affûtage`, `PartenaireOTA`…) ; les **messages d'erreur** (ils citent une règle, le mot y est accessoire). Le concept reste technique/anglais (D5) ; seul le **mot affiché** est une clé surchargeable.

## La résolution — trois couches

Pour une clé (`vocabulary.slot`…), le libellé est résolu dans cet ordre, la dernière couche présente l'emportant :

1. **Défaut FR** — colonne « Défaut » du catalogue (`Créneau`, `Ressource`…). Toujours présent → jamais de clé nue à l'écran.
2. **Surcharge verticale** — `settingsSchema()['vocabulary']` du manifeste de la verticale (`Séance de glace` pour la patinoire).
3. **Surcharge établissement** — le mot que **l'exploitant a lui‑même changé** (D15 l'exige : `VocabulaireManifesteTest` interdit de figer les mots précisément pour ça). Stocké dans `FonctionnaliteEtablissement` *(emplacement à confirmer, voir Q2)*.

Un tiret dans le catalogue = clé non surchargée → on retombe sur le défaut. Ce n'est pas un trou.

## ⚠ La décision CP‑1 : la composition D15

Un établissement peut **composer plusieurs verticales** (un camping avec une piscine ET un bowling). Pour une même clé — `vocabulary.slot` — deux surcharges existent alors. **Laquelle s'affiche ?** C'est la seule vraie décision de conception ; le reste en découle.

- **A · Verticale principale.** L'établissement désigne une verticale principale ; son vocabulaire gagne partout. *Simple (le résolveur ne dépend que de l'établissement, l'API expose une seule table). Faux quand un écran montre un terrain de padel dans un établissement « à dominante piscine » : il lira « Créneau public ».*
- **B · Contextuel, par ressource.** Le mot est résolu depuis la verticale de **la ressource affichée** : un créneau sur un terrain de padel → « Réservation de terrain » ; sur un bassin → « Créneau public ». *Le plus juste. Exige que chaque ressource porte sa verticale, et que l'écran passe ce contexte au résolveur (`t(clé, {verticale})`).*
- **C · Un seul métier par établissement.** On interdit la composition. *Le plus simple, mais contredit D15 (la composition est un vrai cas — les complexes municipaux mixtes).*

**Recommandation :** **B** (contextuel) est la sémantique correcte et la seule qui tienne la promesse multi‑verticale sur un complexe mixte ; **A** est un premier pas acceptable si on assume l'imprécision sur les établissements composés et qu'on livre B ensuite. **C** est écarté (contredit le cœur du produit).

## L'API

Un point de lecture expose, pour l'établissement courant, le vocabulaire résolu : une table `clé → libellé` (couche A retenue), ou `clé → { par verticale }` (couche B). Servie avec le profil (là où le front charge déjà l'établissement actif) ou sur une ressource dédiée. Jamais recopiée côté front (le doc l'interdit : une liste en dur « aurait divergé »).

## La consommation frontend

Un helper `t(clé[, contexte])` (hook `useVocabulaire`) lit la table servie et rend le libellé. **Première cible : `Reservation.jsx`** (l'écran que l'audit cite — « Ressource », « Créneau »). Les autres écrans suivent, un par un, sans big‑bang.

## Ce qui n'est pas fait dans ce lot

- L'i18n multi‑langue (c'est du vocabulaire métier, pas de la langue).
- La reprise de tous les écrans (on commence par la réservation).
- Un éditeur de vocabulaire pour l'exploitant (la surcharge établissement peut d'abord se poser en base ; l'écran d'édition vient après).

## Questions CP‑1

- **Q1 — Composition D15 : A, B ou C ?** *(la décision structurante ci‑dessus)*
- **Q2 — Surcharge établissement** : la stocke‑t‑on dès ce lot (dans `FonctionnaliteEtablissement.parametres`), ou plus tard (lot 1 = défauts + verticale seulement) ?
- **Q3 — Premier écran** : `Reservation.jsx` confirmé comme unique cible du lot 1 ?

## Comment on le voit tenir

- Test résolveur : une clé sur un établissement patinoire rend « Séance de glace » ; sur un établissement sans surcharge, le défaut FR ; une surcharge établissement l'emporte sur la verticale.
- Test d'affichage : l'écran de réservation d'un établissement padel montre « Terrain » / « Réservation de terrain » là où un établissement piscine montre « Bassin » / « Créneau public ».

---

*Spec du résolveur de vocabulaire (#100), première tranche. En attente de CP‑1 : la règle de composition D15 (Q1) commande le reste de la conception.*
