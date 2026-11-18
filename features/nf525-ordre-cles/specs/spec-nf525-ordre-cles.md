# Spec — nf525-ordre-cles

**Statut :** validée (CP-1, Maxime, 18/11/2026) <!-- brouillon → en revue → validée (CP-1) -->
**Auteur :** Claude Code (session cloud), arbitrages de Maxime du 18/11/2026
**Date :** 2026-11-18
**Issue :** #369 (suite de #361 et de la PR #368)
**Faits :** `features/nf525-ordre-cles/refs/faits-chercheur.md`

## Contexte & problème

Depuis #368, chaque maillon d'une chaîne NF525 porte l'identifiant de la clé qui l'a signé (`seal_key_id`). Un trousseau garde la clé active et des clés retirées, qui servent seulement à vérifier les anciens maillons.

Le problème : **une clé retirée reste valable sans limite** (VERIFIED, `SealKeyring::check`). Si elle a fuité, quelqu'un qui peut écrire en base peut forger des maillons **postérieurs** à son retrait, qui se vérifient sans erreur, avec l'identifiant de cette clé ou sans identifiant (`NULL`, chemin de compatibilité des maillons d'avant #368). Le trousseau ne connaît aucun ordre entre ses clés (VERIFIED).

Ce sont des chaînes fiscales : selon le BOFiP (BOI-TVA-DECLA-30-10-30, cité dans #361), l'intégrité doit se prouver pendant six ans.

## Arbitrages de Maxime (18/11/2026)

1. **L'ancienneté se lit dans la chaîne elle-même** : une clé qu'une chaîne a quittée n'y revient plus jamais. Il n'y a ni rang configuré, ni ordre imposé à la liste `*_RETIRED_KEYS`, ni migration.
2. **Échec fermé** : une chaîne où une clé est revenue en arrière est rompue, et l'application **refuse de sceller** le maillon suivant, comme elle le fait déjà pour une clé inconnue.
3. **Pas de retour à une ancienne clé, même volontaire.** La seule sortie est une rotation vers une clé **neuve**.
4. **La signature du ZIP d'archive est hors périmètre.** Elle fera l'objet d'une Issue séparée.

## Objectifs (Goals)

On appelle **régression de clé** le fait, le long d'une chaîne lue par numéro de séquence croissant :
- (a) qu'un maillon porte un identifiant de clé que la chaîne avait **déjà quitté**, c'est-à-dire qu'un maillon antérieur portait cet identifiant puis qu'un maillon intermédiaire en portait un autre ;
- (b) ou qu'un maillon porte un identifiant `NULL` alors qu'un maillon antérieur portait un identifiant non `NULL`.

- **G-1** — La vérification des chaînes **de vente** (`verifieSegment` / `verifieChaine`, utilisée par `POST /nf525/verifier-chaine` et par la remontée des ventes hors connexion) signale toute régression de clé comme une **anomalie qui rend la chaîne non intacte**. Le libellé est distinct de « signature invalide » et de « clé inconnue », et donne la séquence du maillon fautif ainsi que les identifiants en cause, jamais les clés.
- **G-2** — Même règle et même anomalie pour la vérification de la chaîne **compta** (`ScellementEcritureHandler::verifieChaine`, `GET /compta/ecritures/verifier-chaine`).
- **G-3** — Même règle et même anomalie pour la vérification de la chaîne **facturation** (`ScellementFactureHandler::verifieChaine`, `GET /factures/verifier-chaine`).
- **G-4** — La vérification **des chaînes relues dans l'archive fiscale** (`FiscalArchiveChecker`) applique la même règle à l'intérieur de chaque segment exporté.
- **G-5** — **Refus de sceller (échec fermé)**, sur les trois chaînes (vente, compta, facturation) : l'application refuse de sceller un nouveau maillon si ce maillon constituerait une régression de clé, c'est-à-dire si la **clé active** a déjà été quittée par cette chaîne. Elle refuse aussi si le **dernier maillon** est lui-même en régression : une forge posée en fin de chaîne ne doit pas être prolongée. Le refus suit le mécanisme existant (`SealingRefusedException`, 409, alerte `critical` journalisant les identifiants seulement), avec un message qui dit quoi faire : rotation vers une clé **neuve**, et non retour à l'ancienne.
- **G-6** — Le contrôle de déploiement `app:nf525:check-keys` détecte une régression de clé **sur toute la longueur** de chaque chaîne, et non plus seulement sur le dernier maillon. Il sort en échec, ce qui fait échouer le déploiement par le verdict NF525 déjà en place.
- **G-7** — Les chaînes **légitimes restent intactes** :
  - une chaîne sans aucun identifiant (tout `NULL`) ;
  - une chaîne `NULL…NULL` puis `A…A` ;
  - une chaîne `A…A` puis `B…B` puis `C…C` ;
  - une chaîne à une seule clé.

  Aucune vente, écriture ou facture légitime n'est refusée.
- **G-8** — `infra/README.md`, section « Trousseau NF525 », dit la règle : on ne revient jamais à une clé retirée ; une rotation passe toujours par une clé neuve, l'ancienne active étant ajoutée à `*_RETIRED_KEYS`. Il dit aussi ce que fait l'application si la règle est violée.

## Hors périmètre

- **La signature du ZIP d'archive fiscale.** Elle n'a pas d'identifiant de clé et reste acceptée avec n'importe quelle clé retirée. Une **Issue séparée** est à ouvrir.
- **Un rang configuré ou une date de retrait des clés.** On ne détecte donc pas un maillon signé par une clé retirée que **cette chaîne n'a jamais utilisée**. Limite assumée de l'arbitrage 1.
- **Une dérogation pour revenir à une ancienne clé** (arbitrage 3).
- **Toute migration ou reprise des maillons existants**, dont les `NULL` d'avant #368.
- **La liaison d'un segment d'archive avec la clé du maillon qui le précède.** G-4 ne juge qu'à l'intérieur du segment, et la base vivante reste jugée en entier par G-1 à G-3 et G-6.
- **L'alerte persistée quand un scellement est refusé.** C'est #370, le lot suivant.

## Contraintes & décisions techniques connues

- **Aucune API tierce** : PHP natif et code maison. Aucune fiche `docs/references/` n'est requise.
- **Les trois vérifications sont dupliquées volontairement** (commentaires de `ScellementEcritureHandler.php:13-21`). Le plan peut factoriser la **règle** dans un composant partagé du trousseau sans fusionner les vérificateurs.
- **Coût** : le refus de sceller (G-5) est sur le chemin de chaque vente. Le plan ne doit **pas** revérifier toute la chaîne à chaque scellement. Il lui faut une requête ciblée et indexée, par exemple « la clé active apparaît-elle avant le dernier maillon d'une autre clé ? ».
- **Remontée hors connexion** : une anomalie G-1 refuse l'ancrage (`SynchroOperationsProcessor`, 409). C'est voulu, cohérent avec l'échec fermé.
- **Rotation et conteneurs** : une bascule de clé où deux conteneurs scelleraient la même chaîne avec deux trousseaux différents produirait `A, B, A`, donc un faux positif bloquant. Le plan doit vérifier quels conteneurs scellent (php, worker, scheduler…). Si plusieurs le font, la procédure de rotation du README doit les redémarrer ensemble.
- **Revue** : sujet NF525, donc revue `relecteur` et `security-reviewer` en **mode adversarial**, et `/garde-fous-sdd:revue-sensible` consignée avant commit.

## Points UNVERIFIED (bloquants pour CP-1)

Aucun bloquant. À mesurer pendant le plan, sans incidence sur les objectifs :
- [ ] Présence de maillons `NULL` dans les bases réelles. D'après #361, la préprod garde des maillons d'avant le 08/11 (boutique), et la production a 0 opération. La règle G-7 les admet en tête de chaîne.
- [ ] Liste des conteneurs qui scellent (voir « Rotation et conteneurs »).

## Critères d'acceptation

Chaque critère est un test automatisé de la suite PHP, en plus des tests existants de `SealKeyring` et `SealKeyRotation*`, qui restent verts.

- **G-1** : chaîne de vente `A, A, B` puis un maillon forgé correctement chaîné et signé par `A` → `intacte: false`, anomalie « régression de clé » à la séquence 4. Même chose pour un maillon forgé `NULL` signé par `A` après `B`. **Témoin positif** : chaîne `A, A, B, B` → `intacte: true`.
- **G-2 / G-3** : mêmes témoins sur une chaîne compta et une chaîne facturation.
- **G-4** : une archive dont le CSV contient la séquence `A, B, A` est déclarée non conforme. Une archive normale reste conforme.
- **G-5** :
  - Trousseau actif `A` (`B` retirée), chaîne `A, B` → le scellement suivant est refusé (409, alerte `critical` sans aucune clé dans le journal).
  - Chaîne `A, B` puis un maillon forgé `A` en fin, avec la clé active `C` → refusé.
  - **Témoin positif** : chaîne `A, B` avec la clé active `B` ou `C` → le scellement passe.
- **G-6** : `app:nf525:check-keys` sort en échec sur une chaîne `A, B, A` dont le dernier maillon est valide. Il sort en succès sur `NULL, A, B`.
- **G-7** : les quatre chaînes légitimes listées en G-7 sont intactes, et chacune se prolonge sans refus.
- **G-8** : la section du README contient la règle et la procédure, relues par `relecteur`.
