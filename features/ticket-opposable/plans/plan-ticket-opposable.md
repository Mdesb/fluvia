# Plan — ticket-opposable

**Statut :** en attente de CP-2 (porte automatique) — Q-C4 tranchée le 07/10 (justificatif d'avoir complet dans ce lot) ; **points bloquants P-1 à P-5 ci-dessous**, chacun sur l'étape qu'il nomme
**Date :** 2026-10-07 (révision du même jour : Q-C4 tranchée, règle du duplicata révisée)
**Spec :** [`features/ticket-opposable/specs/spec-ticket-opposable.md`](../specs/spec-ticket-opposable.md) — validée au CP-1 le 07/10, Q-C4 comprise ; G-17 révisé et G-19 à G-21 y sont reportés (commit `849f20aa`). Les objectifs du §0.2 en sont le résumé ; en cas d'écart, la spec fait foi.
**Inventaire :** [`features/ticket-opposable/refs/inventaire-pr50-pr59.md`](../refs/inventaire-pr50-pr59.md)
**Branche :** `feature/ticket-opposable` (worktree `/home/debian/wt/ticket-opposable`, tête `d877534a`, à jour de `origin/main` `6348322d`)
**Code de départ :** `origin/feat/caisse-materiel` (`c3dabb8d`, `11bba65f`, `40ccbc7f`, `04268a4e`, `b414b5d8`, `3178c9a2`) et `origin/feat/caisse-ticket-route` (`237a1780`)

> Toute référence `fichier:ligne` de ce plan a été relue le 07/10 sur le worktree, sauf mention **UNVERIFIED**. Les points non vérifiés sont au §9 ; chacun bloque l'étape qu'il nomme (CLAUDE.md, « une UNVERIFIED bloque »).

---

## Points bloquants — à trancher par Maxime (aucun n'est tranché ici)

| N° | Question | Ce que dit le code / la règle | Options | Bloque |
|---|---|---|---|---|
| **P-1** | **Quelle « mention de certification » imprimer sur le justificatif d'avoir d'un logiciel non certifié ?** | La décision du 07/10 la demande (référentiel LNE rév. 1.8) ; G-12 interdit « logiciel certifié NF525 » (« nous ne le sommes pas, un test l'interdit », test repris de `3178c9a2`). | **A** — aucune mention tant qu'aucune certification n'est obtenue (le test d'interdiction couvre aussi le justificatif) · **B** — une mention d'auto-attestation, si et seulement si ce régime est applicable (texte exact fourni par Maxime) · **C** — une identification neutre du logiciel (nom, version) sans le mot « certifié » | É41, **pour la seule mention** (le reste du document avance) |
| **P-2** | **Envoi par e-mail** : planifié **selon D94** (É43) — pas une question, un rappel de ce qui reste à mesurer | Un expéditeur existe (patron `ConfirmationCommandeMailer`) ; `app/.env:61` `MAILER_DSN=null://null` ; D82 : prestataire à désigner, clé posée par Maxime ; D94 : un canal non raccordé refuse en 503, n'annonce jamais un succès ; `ExpediteurCourriel::estBranche()` (l.48-59) le dit déjà, publié par `/me` (`MeController.php:91`) | — | rien dans le code ; l'utilité réelle attend D82 (voir §8, mesures) |
| **P-3** | **Une vente facturée peut-elle être *annulée* en caisse ?** (« et d'annuler ? » de la décision) | `annuler()` produit un avoir total comme `rembourser()` (`ContrePassationHandler.php:39-56`) ; même fondement (CGI 272-1, RG-FACT-05) | **A** — refus aussi (même prédicat, recommandé : même règle fiscale) · **B** — refus du remboursement seulement | É30 (une ligne : `annuler()` appelle-t-il la garde) |
| **P-4** | **Où s'enregistre le remboursement d'une vente facturée ?** | La caisse refusera (G-20) et renverra vers l'avoir `AVF`. Or l'avoir d'une facture **justificative** est une « correction documentaire pure » qui ne génère **aucune écriture** (RG-FACT-03, `AvoirFactureHandler.php:30-31`) et il est **total seulement** (l.33). Résultat sans décision : ni avoir NF525 de caisse, ni extourne de l'écriture de vente, ni trace de l'argent rendu ; et pas de remboursement partiel possible | **A** — Facturation émet l'`AVF` et déclenche la contre-passation de caisse liée (lignes, scellement, extourne), le client reçoit l'`AVF` et non le justificatif de caisse (recommandé) · **B** — l'`AVF` d'une justificative génère lui-même l'extourne (change RG-FACT-03) · **C** — refus seul pour l'instant, le trou est consigné | aucune étape planifiée ; É30 livre le refus décidé, **le trou reste ouvert jusqu'à P-4** |
| **P-5** | **Recrédit du porte-monnaie lors d'un remboursement partiel** (argent, constaté en lisant) | `rembourser()` recrédite **toute** la part PMV de la vente à chaque remboursement, même partiel — « ⚠ HYPOTHÈSE » écrite au code (`ContrePassationHandler.php:68-72`, `recrediterPmv()` l.79-94). Deux remboursements partiels d'une vente payée 50 € en PMV recréditent 100 € | **A** — recrédit PMV = le plus petit de (montant de l'avoir, part PMV non encore recréditée) — recommandé, une étape de ~40 lignes s'ajoute au lot 4 · **B** — laisser en l'état, consigné | rien tant que non tranché ; si A, étape ajoutée au lot 4 |

---

## 0. En bref

### 0.1 Les lots, dans l'ordre de livraison (l'argent d'abord)

| Lot | PR | Ce que le client et le caissier y gagnent | Objectifs |
|---|---|---|---|
| **1 — La clé du règlement** | 1 | un rejeu ne débite plus deux fois | G-1, G-4 |
| **2 — Les tentatives (serveur)** | 2 | double clic, deux onglets, terminal muet : un seul débit | G-3, G-5, G-6 |
| **3 — Déclaration et écrans de règlement** | 3 | les écrans envoient une clé, ne disent plus « réessayez », font déclarer un terminal muet | G-2, G-6 |
| **4 — No-show et plafond des remboursements** | 4 | un no-show ne débite qu'une fois ; on ne rembourse jamais plus que ce qui reste | G-1, G-5, G-19 |
| **5 — Le ticket en un seul endroit** | 5 | invisible : portage de #50, preuve que rien ne change | G-11 |
| **6 — La TVA gravée** | 6 | chaque ligne retient le taux de la comptabilité ; produit sans TVA refusé avant paiement | G-7, G-9 |
| **7 — Ticket = comptes** | 7 | un seul calcul ; tout ce qui s'imprime entre dans l'empreinte NF525 | G-8, G-10, G-12, G-13, G-17 |
| **8 — Les avoirs justes** | 8a, 8b, 8c | un remboursement devient des lignes négatives avec leur taux, figées ; extourne à la mesure de l'avoir ; vente facturée renvoyée vers l'`AVF` | G-19, G-20 |
| **9 — Le ticket PDF compté** | 9a, 9b, 9c | original puis duplicatas numérotés, tracés dans un journal scellé, identiques à l'original | G-12 à G-17 |
| **10 — Le justificatif d'avoir** | 10a, 10b | un papier ou un e-mail propre à l'avoir, à la demande, compté | G-21 |

Lots 2 et 3 mis en service **ensemble** ; lot 9 (9a + 9b + 9c) mis en service **ensemble** (D-12).

### 0.2 Objectifs de référence (révision du 07/10, à reporter dans la spec)

Inchangés : G-1 à G-16, G-18. Révisés ou neufs :

- **G-17 (révisé)** — Un duplicata **reproduit exactement l'original scellé**, avec la seule mention « DUPLICATA n° k — édité le JJ/MM/AAAA HH:MM » (exigence 9 du référentiel LNE), et **aucune** mention d'avoir ni de correction : l'avoir vit dans ses propres données et son propre justificatif. Une vente annulée ou remboursée reste réimprimable (le duplicata est l'original tel quel). Jamais de code d'accès sur le PDF.
  *Critère proposé* : vente remboursée puis réimprimée → PDF identique à l'original, mention d'édition exceptée ; aucun « ANNULÉE », « REMBOURSÉE », « CORRIGÉ ».
- **G-19 — Enregistrement juste d'un remboursement.** Un avoir de caisse est fait de **lignes négatives avec leur taux**. Un montant est réparti puis **figé à la saisie** : au taux de chaque ligne quand il vise des lignes ; **au prorata des bases par taux** quand c'est un geste global ; l'annulation = toutes les lignes restantes en négatif. Rien n'est recalculé ensuite. L'avoir est scellé avec ses lignes et sa ventilation. L'écriture d'extourne est à la mesure de l'avoir (partielle pour un remboursement partiel). On ne rembourse jamais plus que ce qui reste remboursable.
  *Critères proposés* : remboursement d'une ligne à 10 % et d'une à 20 % → deux lignes d'avoir aux bons taux ; geste global de 10 % → réparti au prorata, somme exacte au centime ; ligne de vente modifiée après coup → avoir inchangé ; deux remboursements partiels → deux extournes partielles dont la somme ne dépasse pas l'écriture d'origine ; annulation sans remboursement préalable → extourne identique à celle d'aujourd'hui.
- **G-20 — Vente facturée.** La caisse refuse de rembourser une vente qui a donné lieu à une facture et renvoie vers l'avoir de facture `AVF` (RG-FACT-05, CGI 272-1 et 289 I-5). Annulation : selon P-3. Enregistrement du remboursement d'une vente facturée : selon P-4.
  *Critère proposé* : vente avec facture justificative → remboursement refusé, message nommant la facture et la voie `AVF`, rien d'écrit.
- **G-21 — Justificatif d'avoir.** Série distincte ; référence de la vente d'origine (numéro et date) ; lignes remboursées ; HT, TVA, TTC par taux ; identité du vendeur ; mention selon P-1. Remis **à la demande** ou **par e-mail** — pas d'impression systématique (loi AGEC, D541-370 à 372). Toute émission est comptée au journal des éditions (type `credit_note`) ; une réimpression porte « DUPLICATA n° k — édité le … ». L'e-mail respecte D94.
  *Critères proposés* : justificatif = payload scellé de l'avoir ; deux émissions → original puis DUPLICATA n° 2 ; e-mail sans expéditeur branché → 503, rien compté ; e-mail branché (adaptateur d'essai) → message avec le PDF, édition comptée.

---

## 1. Décisions

### D-1 — Portage commit par commit

Règle : `git cherry-pick -x` (l'origine reste lisible) ; **les écarts à la spec s'ajoutent en commits séparés** (spec, §Contraintes « Portage »). Exception : **une migration s'écrit une fois, dans sa forme finale**. Les commits de fusion (`00addd98`, `4db0008b`, `60a6830b`) ne se reprennent pas.

| Commit | Verdict | Étape | Pourquoi |
|---|---|---|---|
| `c3dabb8d` clé d'idempotence | **repris puis corrigé** | É1, É2 | Repris : propriété, garde, docblocks, tests. Migration `Version20260908083000` **non reprise** : réécrite après `Version20261006105004`, unicité **globale** (G-1, C-6). Corrigé ensuite : recherche globale, refus avant débit d'une clé d'une autre vente, contenu différent refusé (G-4), rejeu avant le 409 « vente validée » |
| `11bba65f` filet `DocumentTicketTest` | **tel quel** | É12 | `TicketProcessor` inchangé sur `main` depuis la base (inventaire) |
| `40ccbc7f` `DocumentTicket` | **tel quel** | É13 | G-11 : le filet reste vert sans retouche |
| `04268a4e` taux gravé | **ne se reprend pas** (le mécanisme seul est gardé, réécrit) | É14, É15 | Source `Produit::$tauxTva` contraire à Q-B1 ; valeur seule insuffisante (G-7) ; nom de migration déjà pris par #47 (F-4) |
| `b414b5d8` `SaleVatBreakdown` | **repris puis corrigé** | É19, É20 | Repris : classe, `withoutRate`/`complete`, clés anglaises, test. Corrigé : ligne par ligne puis somme par taux (Q-B2), formule exacte des écritures (D-7) |
| `3178c9a2` `TicketPdfRenderer` | **repris puis corrigé** | É35, É36 | Il manque le vendeur, le prix unitaire, la remise, les règlements, la mention d'édition et le fuseau ; la hauteur estimée tronque ; et il doit désormais reproduire le payload scellé (G-17 révisé) |
| `237a1780` route PDF (#59) | **repris puis corrigé** | É37, É38 | `GET` non compté, sans contrôle de statut ni du type d'utilisateur (C-1, G-13, G-14, G-15) |

### D-2 — Migrations : six, à la main, une par étape de schéma

Dernière migration de `main` au 07/10 : `Version20261006105004` (VERIFIED). Les migrations du lot (G-18) :

| | Table | Objet | Étape |
|---|---|---|---|
| M-a | `vente_paiement` | clé d'idempotence, unique sur toute la table | É1 |
| M-b | `sale_payment_attempt` | tentatives (G-3, G-6) | É3 |
| M-c | `vente_ligne` | taux gravé et ses attributs (G-7) | É14 |
| M-e | `sale_credit_note_line` | lignes d'avoir figées (G-19) | É24 |
| M-f | `compta_ecriture_comptable` | lien de l'extourne vers son avoir (G-19) | É28 |
| M-d | `nf525_document_edition` | journal des éditions (Q-C1, G-14, G-21) | É33 |

- **Nom** : `VersionAAAAMMJJHHMMSS` horodaté **au moment de l'écrire**, strictement après la dernière de `main` à cet instant (relire `ls app/migrations | sort | tail -1`, `origin/main` à jour) ; jamais un nom choisi parmi les existants (garde-fou n°50). `Version20260908094500` (#47) n'est pas touchée. Les lettres M-a…M-f ne disent pas l'ordre des horodatages : c'est l'ordre des étapes qui le donne.
- **Écriture** : SQL demandé à Doctrine d'abord (`bin/console doctrine:schema:update --dump-sql`, filtré sur la table du lot), recopié à la main ; jamais `doctrine:migrations:diff`. Uuid en **`BINARY(16)`**.
- **Colonnes neuves nullables ou à défaut** : `infra/deploy-preprod.sh` migre (l.177) avant de redémarrer FPM (l.265). Aucune donnée fiscale fabriquée (D66-ter).
- **Tables neuves en anglais** (D5 ; le garde-fou n°2 lit les `name:` de `#[ORM\Table]`/`#[ORM\Column]`, `bin/garde-fou-nommage-anglais.php:67-68` ; `etablissement`, `vente`, `taux`, `paiement` sont au lexique). Colonnes ajoutées à des tables existantes : langue de leur entité.
- **Preuve d'une migration** (`bin/verifier-derive-schema.sh` **mesuré cassé** le 07/10 : limite mémoire 128 Mo) — patron de #270 : restaurer une **copie** de la dernière sauvegarde de préprod dans une base **jetable** (jamais `billetterie_preprod` ; patron de `infra/verifier-restauration.sh`) ; `doctrine:migrations:status` y liste la migration comme **à exécuter** ; y exécuter le SQL de `up()`, puis `down()`, puis `up()` ; puis `SELECT` sur les colonnes neuves et `SHOW INDEX FROM <table>` pour les contraintes. Sorties consignées dans `features/ticket-opposable/impl/impl-all.md`. La preuve se fait par `SELECT`, pas par le statut.

### D-3 — La clé d'idempotence du règlement

- `Paiement::$cleIdempotence` (`?Uuid`, `BINARY(16) NULL`), **unique sur toute la table** (`uniq_paiement_cle_idempotence`). Nom déjà exclu du garde-fou n°14 (`EXCLUSIONS_NOMMEES`).
- **Le rejeu se juge avant tout**, y compris avant le 409 « vente validée » (`PaiementHandler.php:50-52`) : même vente → règlement existant, « déjà enregistré », sans TPE ni PMV ; autre vente → refus avant tout débit ; même clé, moyen ou montant différent → refus (G-4). L'`id` fourni vaut clé.
- **Clé facultative sur `POST /ventes/{id}/paiements`** : 32 fichiers de test et 75 appels postent sans clé (mesuré). Les deux écrans (lot 3) et le no-show (É10) en envoient une ; **la sérialisation par vente (D-4) s'applique aussi aux appels sans clé** (le serveur leur en donne une). Ce que l'appel sans clé ne gagne pas : le rejeu idempotent.
- **Synchronisation hors-ligne inchangée** (Q-A2) : `SynchroOperationsProcessor::composer()` (l.152-156) appelle toujours `encaisser()` sans clé ; un `id` déjà pris par une autre vente est refusé avant le débit au lieu de l'être au `flush()` — dans les deux cas, quarantaine (l.99-109). `HorsLigneTest` le garde.

### D-4 — La tentative : une seule « en cours » par vente, écrite avant l'effet

- `App\Vente\Entity\PaymentAttempt`, table `sale_payment_attempt` (préfixe des fichiers anglais du module : `sale_card_rejection`, `sale_settlement_correction`).
- **Une tentative par clé** (`uniq_payment_attempt_key`) : « jamais deux sollicitations du TPE ou du PMV pour une même clé » (G-3). Un refus est une issue définitive ; un nouvel essai est une nouvelle intention, donc une nouvelle clé.
- **Un créneau par vente** : `open_sale_id` nullable et unique, égal à la vente tant que la tentative est `pending` ou `unresolved`. La seconde reçoit « en cours » (G-3). Contrainte et statut, **pas un verrou tenu pendant l'appel au terminal** (C-9). Plusieurs `NULL` admis par un index unique en MariaDB 11.4 : **UNVERIFIED** (F-6).
- **Statuts** (`PaymentAttemptStatus`) : `pending` → `accepted` | `refused` | `failed` | `unresolved` → `declared_accepted` | `declared_not_processed`. Transitions par `UPDATE … WHERE id = UNHEX(:id) AND status = :attendu` en DBAL (utilisable même si l'`EntityManager` est fermé — **UNVERIFIED**, F-3).
- **Tentative périmée** : `pending` depuis plus de `STALE_AFTER_SECONDS = 120` (deux fois le `proxy_read_timeout 60s`, `billetterie-preprod.conf:142`). Vers le terminal → `unresolved` ; sans terminal → close selon la vérité en base (un `Paiement` porte la clé → `accepted`, sinon `failed`). Délai réel d'un TPE et `request_terminate_timeout` de FPM : **UNVERIFIED** (le seuil est du côté prudent).
- **Validation refusée tant qu'une tentative est ouverte** (dérivé de G-6 : un « accepté » déclaré doit pouvoir s'écrire). Sans effet sur la synchro et le no-show.

### D-5 — Argent et écriture : une seule unité

- **Chemin écran** (`PaiementProcessor` → `SettlementCoordinator`) : 1. rejeu ; 2. tentative `pending` validée en base ; 3. `refresh` de la vente ; 4a. **sans terminal** : une `Connection::transactional()` — débit PMV (l'`UPDATE` de `PorteMonnaieVirtuelAdapter.php:63-66` rejoint la transaction), `Paiement`, clôture de la tentative ; 4b. **avec terminal** (`MoyenPaiement::$exigeReference`) : appel **hors transaction**, puis transaction courte ; 5. **événements après le commit** (D7-bis). `Connection::transactional()` et non `wrapInTransaction()` : choix motivé de `ValiderVenteService.php:35-46`.
- **Refus de carte (PAY-3)** : `CardRejectionRecorder::record(…, SettlementEvents)` persiste sans vider et collecte l'événement ; `consigner()` (flush + publish, l.59-95) reste pour la synchro. Collecteur sur le patron de `ValiderVenteService.php:233-240`.
- `PaiementHandler::encaisser(Vente, array, ?SettlementEvents $events = null)` ; `null` = comportement d'aujourd'hui.
- **No-show** : transaction, `lock(PESSIMISTIC_WRITE)` sur la `FacturationNoShow` puis `refresh` (patron `ConfirmGroupBookingProcessor.php:95-112`), statut revérifié, clé = identifiant de la facturation ; `ValiderVenteService` gagne une variante qui **rend** ses événements (sa publication actuelle, l.237-239, précède le commit d'une transaction englobante). `transactional()` imbriquée : **UNVERIFIED** (F-2).

### D-6 — Le taux : correspondance comptable, par un port, gravé par l'écouteur

- Port `App\Vente\Port\SaleFiscalContextInterface` + adaptateur `App\Compta\Adapter\SaleFiscalContextAdapter` (le module Vente parle aux autres par des ports, `config/services.yaml:132`). `vatRateFor(Uuid $productId, Etablissement $site): VatRateResolution` : catégorie de l'axe comptable (comme `ProjectionVenteDoctrineAdapter.php:116-117`) → profil qui **couvre** le site (`ProfilExploitant::couvre()`, l.445 ; même règle que `ResolveurComptesFacturation::profilPour()`, l.48-60) → `MappingComptable` → **`estValide()`** (l.131-135) → `TauxTva`. `Produit::$tauxTva` n'est jamais lu.
- **Gravé** par `LineLabelStamper` (`prePersist`, D61) : valeur (`decimal(5,2)`), catégorie EN 16931 (`VatCategory`, 4), libellé (80), identifiant du `TauxTva` (référence libre). Formes de `TauxTva.php:52-80`.
- **L'écouteur grave, il ne refuse pas** ; **le créateur de la ligne refuse** par le même port (G-9) : `AjoutLigneHandler` (hors rejeu hors-ligne), `VenteReservationHandler::creerVente()`, `ConfirmerCommandeHandler::creerOuRecupererVente()`, `SouscriptionAbonnementEnLigneHandler::souscrire()`.
- Caisse : le panier est local ; les lignes serveur naissent au premier « Régler » (`Caisse.jsx:626`, `api.ajouterLigne` l.656), avant tout règlement — c'est là que le refus apparaît.

### D-7 — Un seul calcul de TVA (D63-bis)

- `App\Vente\Service\SaleVatBreakdown` (repris de `b414b5d8`) : `lineVat(int $grossCents, string $rate): int`, **statique et pur**, mot pour mot l'expression des écritures (`RegimeBase.php:48-50`, flottants) ; `of(Vente)` somme par taux ; `RegimeBase` appelle `lineVat()`. Base HT = TTC − TVA ligne à ligne.
- Le **même** `lineVat()` sert aux lignes d'avoir (D-17).
- Une ligne sans taux n'est jamais à 0 % (`withoutRate`, `complete: false`) ; une vraie ligne à 0 % est ventilée à 0 %.
- **Oracle indépendant (D67) en entiers** : `bcmath` absent de l'image (`docker/php/Dockerfile:10`). Un écart avec la formule des écritures est **consigné et remonté**, jamais corrigé en silence.

### D-8 — Les écritures de vente lisent le taux gravé

`LigneVenteProjectionDto` gagne le taux gravé ; `RegimeBase::genererEcritureVente()` (l.31-88) prend la valeur gravée et le `TauxTva` gravé quand ils existent ; sinon, lecture actuelle (D66-ter). Compte de produit lu au jour de la génération ; `MappingComptableGuard` inchangé ; facture justificative non touchée. *Risque résiduel :* `TauxTva::$taux` modifiable par `PATCH`.

### D-9 — L'empreinte porte tout ce qui s'imprime

- `ValiderVenteService::payload()` (l.394-418) porte désormais **chaque champ que le ticket imprime** : par ligne, les champs du document (`DocumentTicket::lignes()` de `40ccbc7f` : libellé gravé, tarif, quantité, prix unitaire, impact des options, remise et son type, montant, options, promotions) et le taux gravé ; les règlements avec leur rendu ; `vatBreakdown` ; `seller` (raison sociale, adresse, SIRET, TVA intracommunautaire de `ProfilExploitant`, l.78-148 ; nom du site `Etablissement::getNom()`, l.238). *Raison : G-17 révisé — le duplicata reproduit exactement l'original **scellé** ; il faut donc que tout ce qui s'imprime soit scellé.* Les clés neuves sont en anglais (D5). La vérification recalcule sur le payload **stocké** (`HashChainSignataire.php:85-87`) : les anciennes opérations restent vérifiables.
- **Aucune colonne neuve sur `Vente`** : l'instantané vit dans le payload, protégé par la chaîne. `CHAMPS_VENTE_FIGES` n'a rien à recevoir.
- Vendeur : `SaleFiscalContextInterface::sellerFor(Etablissement): ?SellerIdentity`. Aucun profil → `seller: null`, le document écrit « VENDEUR NON RENSEIGNÉ » ; la validation n'est pas refusée (l'argent est encaissé).

### D-10 — `DocumentTicket` : construit depuis l'empreinte

- Un ticket n'existe que si une **opération scellée de type `vente`** vise la vente (G-13). Le statut ne suffit pas : `Annulee` sert aussi à une vente de réservation **jamais réglée** (`AnnulationVenteReservationHandler::nettoyer()`, l.92-100). Recherche en SQL `UNHEX` (`cibleId` est une référence libre).
- **Vente scellée par ce lot ou après** : le document est **construit depuis le payload scellé** (original comme duplicata, G-17 révisé) ; les colonnes de l'entité servent à la **confrontation** — tout écart donne `anomaly`, imprimé (G-10).
- **Vente antérieure** (payload sans `vatBreakdown`) : document construit depuis les colonnes, confronté à ce que l'ancien payload porte (produit, quantité, montant, totaux), mention « TVA non ventilée — vente antérieure au JJ/MM/AAAA » (date d'exécution de M-c, lue en lecture seule dans la table de suivi des migrations ; colonnes **UNVERIFIED**, F-5 ; à défaut, sans date).
- Date : `Vente::$date` dans `Etablissement::getFuseauHoraire()` (l.310), jamais l'horodatage du scellement.
- **Aucune mention d'événement postérieur** (avoir, correction) : G-17 révisé. (L'ancienne étape « mentions sous le document » est supprimée.)

### D-11 — Le journal des éditions : propre stockage, propre chaîne, deux types de document

- `App\Vente\Nf525\Entity\DocumentEdition`, table `nf525_document_edition` ; pas `nf525_operation_scellee` (C-7).
- `document_type` (`EditedDocumentType` : **`sale_ticket` et `credit_note`**, ce second cas servant au justificatif d'avoir, G-21) + `document_id` ; unicité (`document_type`, `document_id`, `edition_number`).
- Chaîne **par point de vente**, **même algorithme** : `calculerEmpreinte`, `signer`, `canonicalize` de `HashChainSignataire` (l.110-150) extraites dans `App\Vente\Nf525\ChainFingerprint`, utilisée par les deux.
- Payload d'une édition : type et identifiant du document, numéro du document, numéro d'édition, instant (UTC), auteur, **canal** (`print` | `email`), **empreinte de l'opération scellée** du document (vente ou avoir). Pour un e-mail : **empreinte (SHA-256) de l'adresse**, jamais l'adresse — un journal append-only ne peut pas effacer une donnée personnelle.
- **Append-only** (`InalterabiliteListener::estAppendOnly()`, l.100-114), **cloisonnée** (`PerimetreVenteExtension::CHEMINS`, l.38-76) ; non exposée en API.
- Deux émissions simultanées : la seconde prend `UniqueConstraintViolationException` et recommence (trois essais).

### D-12 — Émettre, c'est produire le document ; la mention vient du compte

- **Émission** = `POST` du PDF (ou envoi d'e-mail, G-21). Elle compte une édition, pose `imprime` (pour une vente) et produit le document, **dans une transaction** : rendu ou envoi en échec → rien compté.
- **Consultation** = `POST /ventes/{id}/ticket` (JSON) : ne compte rien. `imprime` garde ses points de pose actuels (`ValiderVenteService.php:201-203`, `TicketProcessor.php:67-72`) et son seul rôle (annulation → billets invalidés, `ContrePassationHandler.php:44-50`). Le seuil ne déclenche plus d'**édition** réputée faite.
- Mention : original = édition n° 1 ; ensuite **« DUPLICATA n° k — édité le JJ/MM/AAAA HH:MM »** (G-17 révisé), k = numéro d'édition. Le reste du document est **identique** à l'original.
- **Ventes scellées avant la mise en service du journal** : première édition comptée = n° 2 (un papier a pu sortir par `window.print()` ; un second « original » est l'erreur la plus grave). Repère : exécution de M-d (F-5). D'où : **lot 9 mis en service d'un bloc**.
- Vente gratuite : l'écran ne propose pas d'imprimer (`TicketPrintingPolicy::estGratuite()`) ; la route n'ajoute pas de refus.

### D-13 — Les routes des documents

- Contrôleurs Symfony (patron `TelechargerFacturXController`, et `237a1780`) : la réponse est un fichier.
- **`POST /api/ventes/{id}/ticket-pdf`** et non `/ticket.pdf` : `POST /ventes/{id}/ticket` existe (`Vente.php:149-156`) et ses routes acceptent normalement un suffixe `{._format}` — **UNVERIFIED** (F-7), évité par le chemin ; `router:match` le prouve à É37.
- **`POST /api/avoirs/{id}/credit-note-pdf`** et **`POST /api/avoirs/{id}/credit-note-email`** : `Avoir` n'expose que `Get`/`GetCollection` (`Avoir.php:26-33`), aucune collision de méthode.
- Sous `/api` (nginx, `billetterie-preprod.conf:135`).
- **Ordre des contrôles** : pas un `Utilisateur` → 404 ; droit absent → 403 (`vente.lire` pour le PDF ; `vente.rembourser` pour l'e-mail, qui écrit à une adresse saisie — un relais de courriel n'est pas un droit de lecture) ; document inconnu, malformé, hors établissement actif → 404 ; document non scellé → 409 ; puis D-12. En-têtes : `application/pdf`, `nosniff`, `no-store`, `inline`.
- Vrai jeton partenaire ou de terminal : le pare-feu `api` (`security.yaml`, `^/`, `jwt: ~`) répondra très probablement 401 avant le contrôleur ; le 404 est prouvé avec un utilisateur injecté, l'appel réel est mesuré et ne doit rien rendre. **UNVERIFIED** (F-9).

### D-14 — Le rendu PDF

Dompdf v3.1.6 + `PoliceDeclaree::dans()` (l.61). **Aucune troncature** : mesure puis rendu (une seule page, sinon agrandir) — API **UNVERIFIED** (F-1) ; repli : estimation par caractères (chasse fixe) majorée. `html()` public (preuve des mentions). Jamais « certifié NF525 » (test de `3178c9a2`, étendu au justificatif selon P-1), jamais de code d'accès. Un seul gabarit : en-tête (vendeur, site), corps (vente ou avoir), mention d'édition, pied.

### D-15 — Les écrans

- `frontend/src/api/paymentIntent.js` (module pur) : intention = (vente, moyen, montant) ; clé `crypto.randomUUID()` (**UNVERIFIED** hors contexte sécurisé, F-16), gardée dans `sessionStorage` jusqu'à une issue définitive.
- `api.payer` (`client.js:656-657`) envoie `cleIdempotence` ; son coupe-circuit ne dit plus « Réessayez » (`client.js:251`) mais « Résultat inconnu — vérification en cours » et rejoue avec la même clé.
- 409 lus par code machine (`payment_in_progress`, `payment_outcome_unknown`), en `JsonResponse` (le message d'une exception ne traverse pas toujours la production, `TelechargerFacturXController.php:91-104`).
- PDF par `fetch` authentifié **par en-têtes** puis `Blob` (patron `FactureRendu.jsx:141-171`, contrôle du `Content-Type` comme `PrelevementsSepa.jsx:519-528`, `cache: 'no-store'`) ; jamais de jeton dans l'URL. Ouverture : onglet ouvert au clic puis dirigé vers le `Blob`, repli en téléchargement (**UNVERIFIED**, F-16).
- Bouton « Envoyer par e-mail » affiché selon `envoiCourrielBranche` de `/me` (patron `App.jsx:560`, prop `envoiCourriel`) ; éteint, il dit pourquoi.
- `node --test` (Node v20.20.2 sur l'hôte) pour le module, branché dans `bin/garde-fous.sh` (patron l.501-505). Aucune dépendance ajoutée.

### D-16 — Ce qui ne bouge pas

Synchronisation hors-ligne ; facture justificative et `AvoirFactureHandler` (en attente de P-4) ; ESC/POS ; TPE réel (`TpeMock`, `services.yaml:138`) ; renvoi du **ticket** par e-mail/SMS (seul le justificatif d'avoir s'envoie, G-21) ; invalidation des billets (#93) ; `Etablissement::$langue` ; **`COORDINATION/DECISIONS.md`** (aucune modification prévue).

### D-17 — Les lignes d'avoir, figées à la saisie (G-19)

- Entité `App\Vente\Entity\CreditNoteLine`, table `sale_credit_note_line`, **append-only** : `credit_note_id` (FK `vente_avoir`), `sale_line_id` (FK `vente_ligne`, nullable : une part de geste global ne vise pas une ligne), `kind` (`line` | `global_share`), `quantity` (nullable), `label` (libellé gravé de la ligne d'origine, ou « Geste commercial »), taux copié **de la ligne d'origine** ou du groupe (`rate`, `rate_category`, `rate_label`, `rate_ref`, mêmes formes que M-c), et montants **figés, négatifs** : `gross_amount`, `net_amount`, `vat_amount` (`NUMERIC(10,2)`). `Avoir::$montant` reste le total positif (lu par la clôture, `CloturerSessionProcessor.php:96-102`, et par les écritures) ; un test garde `montant = −Σ gross_amount`.
- **Répartition** (`App\Vente\Service\CreditNoteAllocator`, pur, sans base) :
  - **visant des lignes** : chaque ligne visée reçoit son montant (quantité × prix effectif, ou montant saisi), à **son** taux ; TVA = `SaleVatBreakdown::lineVat()` ;
  - **geste global** : réparti **au prorata des bases par taux** de la vente, lu comme « chaque base réduite dans la même proportion » ; la part TTC de chaque taux est alors proportionnelle au TTC de ce taux ; arrondi au centime par **plus fort reste**, somme exacte ; une ligne d'avoir par taux ;
  - **annulation** : toutes les lignes **restantes** en négatif (après d'éventuels remboursements partiels).
- **Plafonds** : par ligne, jamais plus que ce qui reste remboursable sur la ligne ; au total, jamais plus que ce qui reste sur la vente.
- **Vente antérieure au taux gravé** (lignes sans taux) : les lignes d'avoir n'en portent pas non plus (D66-ter, même règle que le ticket) ; le geste global est réparti au prorata du TTC par ligne. Le justificatif le dit, et l'extourne suit l'écriture d'origine (D-19).
- Rien n'est recalculé ensuite : lignes et montants ne sont relus que de la table.

### D-18 — L'avoir est scellé avec ses lignes, sa ventilation et son vendeur

`ContrePassationHandler::creerAvoir()` (l.96-131) : le payload de l'opération `TypeOperationScellee::Avoir` (l.114-127) gagne les lignes (D-17), `vatBreakdown` (somme par taux des lignes, négative), `seller` (D-9, figé à la création de l'avoir), et la référence de la vente d'origine (numéro, date). Les anciennes opérations restent vérifiables (payload stocké). Signature de `rembourser()` étendue : `rembourser(Vente, CreditNoteRequest, string $motif, Utilisateur)` où la demande dit « lignes visées » ou « montant global » ou « total » ; les trois appelants actuels gardent leur sens : `RembourserVenteProcessor` (lignes ou montant), `TraiterDemandeRemboursementHandler` (Boutique, montant global, l.35), `AnnulationVenteReservationHandler::rembourser()` (total, l.85-88).

### D-19 — L'extourne à la mesure de l'avoir

- **Une extourne par avoir**, plus une par vente : aujourd'hui `venteOriginesDejaExtournees()` dédoublonne **par vente** (`ProjectionVenteDoctrineAdapter.php:213-228`, utilisée l.94-96) — un second remboursement partiel n'est **jamais** comptabilisé. M-f ajoute `compta_ecriture_comptable.avoir_origine` (référence libre) ; un avoir est extourné si une écriture porte son identifiant **ou** (règle héritée, pour les extournes passées) s'il est le seul avoir de sa vente et qu'une extourne de la vente existe sans `avoir_origine`. *Sans la règle héritée, le premier passage après déploiement réextournerait tous les avoirs déjà comptabilisés.* Aucune donnée réécrite (D66-ter).
- **Extourne = miroir proportionnel de l'écriture d'origine** (`RegimeBase::genererEcritureExtourne()`, l.198-216, qui inverse aujourd'hui **tout**) : par taux, la TVA vient des lignes figées de l'avoir ; la base HT de l'avoir se répartit sur les lignes de produit (ou de `487` sous PCA) de ce taux dans l'écriture d'origine, au prorata de leurs montants ; le total de l'avoir se répartit sur les lignes d'encaissement d'origine au prorata ; plus fort reste. Avoir sans taux (vente antérieure) : facteur global sur toute l'écriture d'origine. **Annulation sans remboursement préalable → facteur 1 → extourne identique à celle d'aujourd'hui** (les tests actuels restent verts sans modification).
- `AvoirProjectionDto` porte les montants figés par taux.

### D-20 — Vente facturée (G-20)

Port `App\Vente\Port\SaleInvoiceLookupInterface::invoiceNumberFor(Uuid $saleId): ?string` + adaptateur `App\Facturation\Adapter\SaleInvoiceLookupAdapter` (lecture de `Facture::$venteOrigine`, unicité `uniq_facture_vente_origine`, `Facture.php:64`). `ContrePassationHandler::rembourser()` refuse en 409 : « Cette vente a été facturée (facture n° X) : le remboursement passe par un avoir de facture (`AVF`) depuis Facturation. » `annuler()` : selon **P-3**. Appelants Boutique et Réservation : le refus remonte tel quel, message compris (le geste est le même). Le devenir du remboursement d'une vente facturée : **P-4**.

### D-21 — Le justificatif d'avoir (G-21)

- `App\Vente\Service\CreditNoteDocument` : **construit depuis le payload scellé de l'avoir** (D-18) — n° d'avoir (série `AV-`, distincte des tickets et des `AVF`), vente d'origine (n°, date dans le fuseau), lignes, HT/TVA/TTC par taux, vendeur, motif, mention selon P-1 ; confronté aux lignes en base (anomalie imprimée). Avoir antérieur (payload sans lignes) : montant seul, « avoir antérieur à la ventilation ».
- **Émission comptée** (`credit_note`) : PDF à la demande ; e-mail **selon D94** — `ExpediteurCourriel::estBranche()` faux → **503**, rien compté, rien envoyé ; vrai → envoi (Symfony Mailer, patron `ConfirmationCommandeMailer`), édition comptée **après** l'acceptation par le transport, payload : canal `email`, empreinte de l'adresse. « Transmis au transport » n'est pas « délivré » : la preuve de délivrance attend le prestataire (D82), et le journal ne prétend pas plus.
- **Pas d'impression systématique** (AGEC) : après un remboursement, l'écran **propose** « Imprimer », « Envoyer par e-mail » ou rien.

---

## 2. Étapes

**Règles communes** :
- Avant chaque commit : `./bin/garde-fous.sh` vert ; `git checkout -- app/config/reference.php` ; tests ciblés verts sur `ticket07` ; revue consignée en zone sensible ; commit en français, conventionnel.
- **Budget du hook** : ≤ 400 lignes de code par commit hors tests et migrations (`kit-sdd.json`, `budget_diff`, avertissement à 150) ; tailles visées ≤ 300.
- Pile : `./infra/test-stack.sh up ticket07` ; `./infra/test-stack.sh run ticket07 <chemins relatifs à app/>` ; `up` de nouveau avant de changer de module ; `./infra/test-stack.sh down ticket07` en fin de lot.
- Revue : **[R]** `relecteur` adversarial, **[S]** `security-reviewer` adversarial.
- Journal : une ligne par étape dans `features/ticket-opposable/impl/impl-all.md`.
- Taille : lignes de **code** / **tests** / **migration**, estimées (±30 %).

### Lot 1 — La clé du règlement (PR 1)

#### É1 — Porter la clé d'idempotence, avec sa migration renumérotée (M-a)
*Couvre G-1, G-18.* · Reprise de `c3dabb8d` sans sa migration ; seule correction au portage : contrainte globale.
- `git cherry-pick -n c3dabb8d` (le message du commit cite `c3dabb8d` à la main), retrait de `app/migrations/Version20260908083000.php`, puis :
  - `app/src/Vente/Entity/Paiement.php` : `#[ORM\UniqueConstraint(name: 'uniq_paiement_cle_idempotence', columns: ['cle_idempotence'])]` ; docblocks alignés sur la portée globale ;
  - M-a : `ALTER TABLE vente_paiement ADD cle_idempotence BINARY(16) DEFAULT NULL` ; `CREATE UNIQUE INDEX uniq_paiement_cle_idempotence ON vente_paiement (cle_idempotence)` ; `down()` inverse ;
  - `PaiementHandler.php`, `Vente.php` (description), `tests/Vente/Api/IdempotenceReglementTest.php` : tels que dans `c3dabb8d`.
- **Fait quand** : `run ticket07 tests/Vente/Api/IdempotenceReglementTest.php tests/Vente/Api/PaiementTest.php tests/Vente/Api/HorsLigneTest.php` vert ; preuve M-a (D-2).
- Revue : [R][S]. · Taille : ~110 / 158 / ~50.

#### É2 — Corriger la garde de rejeu
*Couvre G-1, G-4.* · Correction de `c3dabb8d`.
- `PaiementHandler::encaisser()` : rejeu **avant** le 409 (l.50-52), recherche **en base** (clé puis `id`), autre vente → refus avant tout effet, contenu différent → refus, `dejaEnregistre: true` dans la réponse ; `PaiementHandler::requestedAmountCents(Vente, array): int` (règle des l.80-83, réutilisée à É4).
- **Fait quand** : cas ajoutés à `IdempotenceReglementTest` — rejeu après validation → règlement d'origine ; clé d'une autre vente → refus, solde PMV et nombre de `Paiement` inchangés ; même clé, montant différent → refus ; deux règlements identiques **avec deux clés** restent deux ; `HorsLigneTest` vert.
- Revue : [R][S]. · Taille : ~80 / ~160 / 0.

### Lot 2 — Les tentatives, côté serveur (PR 2 ; mise en service avec le lot 3)

#### É3 — La table des tentatives (M-b) et son entité
*Couvre G-3, G-6, G-18.*
- `app/src/Vente/Entity/PaymentAttempt.php`, `app/src/Vente/Enum/PaymentAttemptStatus.php` ; non exposée.
- M-b, `sale_payment_attempt` : `id`, `sale_id` (FK `vente_vente`), `open_sale_id` (FK, nullable, **unique**), `idempotency_key` (**unique**), `payment_method_code` (32), `requested_amount` (nullable), `amount`, `uses_terminal`, `status` (24), `terminal_status` (12, nullable), `failure_reason` (255, nullable), `payment_id` (FK `vente_paiement`, nullable), `started_at`, `closed_at`, `declared_by_id` (FK `sec_utilisateur`, nullable), `declared_at`, `card_reference` (64, nullable).
- Recherches par clé en SQL `UNHEX` (garde-fou n°14).
- **Fait quand** : garde-fous verts ; deux tentatives ouvertes sur la même vente → `UniqueConstraintViolationException`, deux fermées → admises ; preuve M-b avec `SHOW INDEX` et deux `NULL` admis.
- Revue : [R]. · Taille : ~170 / ~60 / ~70.

#### É4 — Le coordinateur, pour les moyens sans terminal
*Couvre G-1, G-3, G-5.*
- `app/src/Vente/Service/SettlementCoordinator.php`, `SettlementEvents.php` ; `PaiementProcessor` délègue ; `encaisser()` reçoit `?SettlementEvents`.
- D-4/D-5 (1, 2, 3, 4a, 5) ; appel sans clé → clé serveur ; conflit → 409 `payment_in_progress` ; refus de validation → `failed`, rejouable à l'identique ; tentative sans terminal périmée → close selon la base.
- **Fait quand** : `tests/Vente/Api/PaymentAttemptTest.php` — tentative ouverte d'avance → « en cours », aucun règlement ; PMV : écriture forcée en échec après le débit (écouteur armable `App\Tests\Vente\Support\FailingWriteListener`, `when@test`, `config/services.yaml:429`) → **solde intact**, aucun `Paiement`, tentative `failed` ; espèces périmée → close ; `PaiementTest`, `IdempotenceReglementTest`, `HorsLigneTest`, `RefusCarteTest` verts.
- Revue : [R][S]. · Taille : ~260 / ~260 / 0.

#### É5 — Le terminal : tentative avant l'appel, et vraie concurrence
*Couvre G-3, G-5, G-6.*
- 4b de D-5 ; `Timeout` → `unresolved` ; périmée → `unresolved` ; rejeu `unresolved` → 409 `payment_outcome_unknown` sans terminal ; accepté puis écriture perdue → `unresolved`. `CardRejectionRecorder::record()`. Docblock « ce que la clé ne ferme pas » réécrit.
- Tests : `App\Tests\Vente\Support\BlockingTerminal` (décore `TpeMock`, barrière fichier, `when@test`), `tests/Vente/Support/settle-in-other-process.php` (patrons `tests/Reservation/ConcurrentSlotWriter.php`, `tests/PublicApi/call-v1-me.php`).
- **Fait quand** : `tests/Vente/Api/ConcurrentSettlementTest.php` — deux appels **réellement concurrents** à 45 € : le second reçoit « en cours », un seul débit ; timeout puis rejeu avec `X-Tpe-Simule: refuse` → « résultat inconnu » (le terminal n'a pas été sollicité) ; tentative `pending` de plus de 120 s → idem ; refus de carte → trace et événement **après** le commit, aucun événement sur une transaction annulée ; `RefusCarteTest` vert.
- Revue : [R][S]. · Taille : ~220 / ~360 / 0.

### Lot 3 — Déclaration et écrans de règlement (PR 3 ; mise en service avec le lot 2)

#### É6 — La déclaration du caissier, et la validation qui l'attend
*Couvre G-6.*
- `Vente.php` : `POST /ventes/{id}/declarer-reglement` (`read: true`, `input: false`, `vente.encaisser`, `LecteurCorps`) ; `app/src/Vente/State/DeclareSettlementProcessor.php` → `SettlementCoordinator::declare()`. « accepte » : `referenceCarte` obligatoire (≤ 64) → `Paiement` (clé de la tentative), tentative `declared_accepted`, qui et quand, **sans terminal** ; « non_passe » → `declared_not_processed`, créneau libéré. `ValiderVenteService::valider()` refuse tant qu'une tentative est ouverte. Garde-fou n°29 : `@sans-suppression: trace d'une déclaration d'encaissement (NF525)` s'il la demande.
- **Fait quand** : `tests/Vente/Api/SettlementDeclarationTest.php` — accepté + référence → un règlement tracé ; non passé → nouvel envoi permis ; accepté sans référence → 422 ; validation refusée puis permise ; sans `vente.encaisser` → 403 ; autre établissement → 404.
- Revue : [R][S]. · Taille : ~190 / ~230 / 0.

#### É7 — L'intention de règlement, côté client
*Couvre G-2.*
- `frontend/src/api/paymentIntent.js` ; `client.js` : `payer(…, { cle })`, message de coupe-circuit propre, `declarerReglement()` ; `frontend/scripts/test-payment-intent.mjs` (`node:test`) branché dans `bin/garde-fous.sh`.
- **Fait quand** : `node --test frontend/scripts/test-payment-intent.mjs` vert (même intention → même clé, y compris après relecture du stockage ; issue définitive → clé oubliée ; montant différent → autre clé ; message sans « Réessayez ») ; garde-fous verts.
- Revue : [R]. · Taille : ~130 / ~130 / 0.

#### É8 — La caisse
*Couvre G-2, G-6.*
- `Caisse.jsx` : `encaisserMoyen()` (l.755-822) par l'intention ; « Régler » pendant l'attente → même clé ; « Paiement en cours de vérification » et relance ; **au retour après F5** : vente relue (`api.vente`, `client.js:646`) et réaffichée, clé rejouée ; fenêtre « Qu'affiche le terminal ? ».
- **Fait quand** : garde-fous verts (boutons nommés, clic/clavier, contrastes) ; `npm run build` sans avertissement nouveau ; vérification réelle en préprod consignée (double clic, F5, `X-Tpe-Simule: timeout` puis déclaration → un règlement par intention).
- Revue : [R]. · Taille : ~280 / 0 / 0.

#### É9 — La souscription d'abonnement
*Couvre G-2, G-6.*
- `SouscriptionAbonnement.jsx` (l.290-335) : même intention ; plus de « reprenez-la depuis la caisse » sur une issue inconnue ; fenêtre de déclaration réutilisée.
- **Fait quand** : garde-fous verts ; vérification réelle consignée.
- Revue : [R]. · Taille : ~90 / 0 / 0.

### Lot 4 — No-show et plafond des remboursements (PR 4)

#### É10 — Le no-show : clé de la facturation, une seule unité
*Couvre G-1, G-5.*
- `DebitPmvStrategie.php` (D-5) ; `ValiderVenteService` : variante qui rend ses événements, `valider()` inchangé pour ses appelants.
- **Fait quand** : `tests/Reservation/Api/NoShowDebitUnitTest.php` — validation forcée en échec → aucun débit, facturation « à facturer » ; relance → un débit ; deux applications simultanées → un débit ; `FacturationNoShowStrategiesTest`, `IssueCreditNoShowTest`, `tests/Vente` verts.
- Revue : [R][S]. · Taille : ~150 / ~210 / 0.

#### É11 — On ne rembourse jamais plus que ce qui reste
*Couvre G-19.* · Garde d'argent posée avant la refonte des avoirs (lot 8), qui la reprend.
- `ContrePassationHandler::rembourser()` (l.58-76) : le plafond devient « total − somme des avoirs déjà émis sur la vente » (aujourd'hui : le total, à chaque fois, et `exigerValidee()` accepte `AvoirEmis`, l.133-138) ; `annuler()` après des remboursements partiels : avoir du **reste**, pas du total.
- **Fait quand** : test dans `tests/Vente/Api/ContrePassationTest.php` (ou voisin) — deux remboursements de 30 € sur une vente de 45 € → le second refusé ; annulation après un remboursement de 10 € → avoir de 35 € ; `ContrePassationTest` vert.
- Revue : [R][S]. · Taille : ~40 / ~80 / 0.

*Si P-5 = A* : une étape s'ajoute ici (recrédit PMV plafonné, ~40 / ~80).

### Lot 5 — Le ticket en un seul endroit (PR 5)

#### É12 — Porter le filet de sortie du ticket
*Couvre G-11.* · `11bba65f` tel quel. `tests/Vente/Api/DocumentTicketTest.php`.
- **Fait quand** : vert **avant** toute extraction.
- Revue : [R]. · Taille : 0 / 179 / 0.

#### É13 — Porter `DocumentTicket`
*Couvre G-11.* · `40ccbc7f` tel quel. `app/src/Vente/Service/DocumentTicket.php`, `TicketProcessor.php`.
- **Fait quand** : `DocumentTicketTest` vert **sans avoir été touché** ; `tests/Vente`, `tests/Caisse` verts.
- Revue : [R]. · Taille : ~125 / 0 / 0.

### Lot 6 — La TVA gravée (PR 6)

#### É14 — Les colonnes du taux gravé (M-c)
*Couvre G-7, G-18.*
- `LigneVente.php` : `tauxTva`, `tauxTvaCategorie`, `tauxTvaLibelle`, `tauxTvaRef`, nullables, groupes `vente:read`/`ticket:read` (comme `libelleProduit`, l.107-109).
- M-c : `ALTER TABLE vente_ligne ADD taux_tva NUMERIC(5, 2) DEFAULT NULL, ADD taux_tva_categorie VARCHAR(4) DEFAULT NULL, ADD taux_tva_libelle VARCHAR(80) DEFAULT NULL, ADD taux_tva_ref BINARY(16) DEFAULT NULL` ; `down()` inverse.
- **Fait quand** : `LibelleFigeTest`, `ChampsFigesTest` verts ; preuve M-c.
- Revue : [R]. · Taille : ~70 / ~40 / ~45.

#### É15 — Résoudre et graver le taux
*Couvre G-7, G-9.*
- `SaleFiscalContextInterface`, `VatRateResolution`, `SaleFiscalContextAdapter` ; câblage `config/services.yaml` (l.130-140) ; `LineLabelStamper` grave.
- **Fait quand** : `tests/Vente/Api/StampedVatRateTest.php` — gravé 20,00/`S`/libellé ; correspondance passée à 10 % après la vente → la ligne garde 20 % ; `PATCH` du `TauxTva` → inchangé ; correspondance invalide → non gravé ; `Produit::$tauxTva` ignoré ; tests unitaires de l'adaptateur.
- Revue : [R]. · Taille : ~210 / ~220 / 0.

#### É16 — Le socle fiscal des tests
*Couvre G-9 (prépare le refus).* · Tests seulement.
- Mesure d'abord : bases qui créent des lignes sans correspondance (`grep -rl "OffreFixtures::class" app/tests | xargs grep -L ComptaFixtures` : `VenteApiTestCase`, `CaisseClotureRoleApiTestCase`, `PiscineApiTestCase`, `OffreApiTestCase`, `StockApiTestCase`, `AccesApiTestCase`, `AllYearPriceAuditTest`, `QuotaCoursInclusTest`, `CloisonnementReservationDroitTest`) et tests qui vendent sur le site B (le profil de `ComptaFixtures` ne couvre que A, l.88-94).
- `ComptaFixtures` ajouté aux bases ; site B : fixture de test dans `tests/…/Support`, jamais dans `ComptaFixtures`.
- **Fait quand** : chaque suite touchée **aussi verte qu'avant** (listes d'échecs consignées avant/après) : Vente, Caisse, Piscine, Offre, Stock, Acces, Reservation, Padel, Boutique, Sport, OptionProduit, Autorisation.
- Revue : [R]. · Taille : 0 / ~150 / 0.

#### É17 — Refuser une ligne sans taux : caisse, réservation, no-show
*Couvre G-9.*
- `AjoutLigneHandler::composerLigne()` (l.85-119) sauf rejeu hors-ligne (l.75-77) ; `VenteReservationHandler::creerVente()` (l.42) — donc ses cinq appelants ; `DebitPmvStrategie` traduit le refus avant le débit (comme l.51-62).
- **Fait quand** : `tests/Vente/Api/LineWithoutVatRefusalTest.php` — caisse 422 nommant la catégorie ; réservation : aucune vente ; no-show : solde intact ; synchro : ligne « sans taux » enregistrée ; témoin à 0 % accepté ; suites Vente, Reservation, Padel vertes.
- Revue : [R][S]. · Taille : ~90 / ~200 / 0.

#### É18 — Refuser une ligne sans taux : boutique et abonnement en ligne
*Couvre G-9.*
- `ConfirmerCommandeHandler::creerOuRecupererVente()` (l.144-175 ; appelée par `PayerPanierProcessor.php:79` avant `initierPaiement()` l.84) ; `SouscriptionAbonnementEnLigneHandler::souscrire()` en tête (l.83, avant le mandat l.152).
- **Fait quand** : tests `tests/Boutique/Api/` — aucun paiement initié ; aucun mandat ; suites Boutique et Sport vertes.
- Revue : [R][S]. · Taille : ~60 / ~160 / 0.

**Avant la mise en service du lot 6** : mesurer en préprod (lecture seule) les produits vendables dont la catégorie n'a pas de correspondance valide (spec F-2 : 2 sur 15) ; liste donnée à Maxime avec le geste qui répare.

### Lot 7 — Ticket = comptes (PR 7)

#### É19 — Porter la ventilation
*Couvre G-8, G-9.* · Reprise de `b414b5d8`, une adaptation au portage : la valeur `vat` épinglée dans `DocumentTicketTest` (depuis É16 le jeu de test grave 20 % : un groupe 20,00, 37,50 + 7,50, `complete: true` ; le témoin « incomplète » reste dans `SaleVatBreakdownTest`).
- `SaleVatBreakdown.php`, `DocumentTicket` (clé `vat`), `TicketProcessor`, `tests/Vente/Unit/SaleVatBreakdownTest.php`.
- **Fait quand** : `SaleVatBreakdownTest`, `DocumentTicketTest` verts.
- Revue : [R]. · Taille : ~125 / ~160 / 0.

#### É20 — Un seul calcul : la ligne d'abord, la formule des écritures
*Couvre G-8, G-9.* · Correction de `b414b5d8`.
- `lineVat()` statique (D-7), `of()` somme par taux ; `RegimeBase.php` l.50 appelle `lineVat()`.
- **Fait quand** : `tests/Vente/Unit/LineVatOracleTest.php` — oracle entier (D67), montants de 0,01 € à 10 000 € × taux 0 ; 0,9 ; 1,05 ; 2,1 ; 5,5 ; 8,5 ; 10 ; 13 ; 20 : égalité ou écart remonté ; 1,15 € à 10 % → 0,10 € ; `GenerationEcritureTest`, `PcaTest`, `MappingIncompletTest`, `VentilationEncaissementTest` verts **sans modification**.
- Revue : [R][S]. · Taille : ~70 / ~160 / 0.

#### É21 — Les écritures de vente lisent le taux gravé
*Couvre G-8.*
- `LigneVenteProjectionDto`, `ProjectionVenteDoctrineAdapter` (l.112-135), `RegimeBase::genererEcritureVente()` (D-8).
- **Fait quand** : `tests/Compta/Api/StampedRateLedgerTest.php` — ventes multi-taux : ticket = écritures au centime ; correspondance changée → taux gravé ; vente sans taux gravé → lecture actuelle ; `tests/Compta` vert ; `EmissionFactureJustificativeHandler` non modifié.
- Revue : [R][S]. · Taille : ~80 / ~160 / 0.

#### É22 — Tout ce qui s'imprime entre dans l'empreinte
*Couvre G-10, G-12, G-17.*
- `sellerFor()` + `SellerIdentity` + adaptateur ; `ValiderVenteService::payload()` (D-9 : champs du document par ligne, taux, règlements et rendu, `vatBreakdown`, `seller`).
- **Fait quand** : `tests/Vente/Api/SealedPayloadTest.php` — chaque champ imprimé présent dans le payload ; site sans profil → `seller: null`, validation acceptée ; **chaîne vérifiée verte** sur des ventes scellées avant et après le changement de format (`HashChainTest`, `Nf525ApiTest`, `VerifierChaineCloisonnementTest`) ; `ClotureJournaliereTest` vert.
- Revue : [R][S]. · Taille : ~180 / ~190 / 0.

#### É23 — `DocumentTicket` construit depuis l'empreinte
*Couvre G-10, G-12, G-13, G-17.*
- D-10 ; `App\Vente\Service\MigrationExecutionDate` (lecture seule ; absente → `null`) ; `TicketProcessor` refuse sans opération scellée (409). `DocumentTicketTest` mis à jour **délibérément** (clés `seller`, `anomaly`, date dans le fuseau).
- **Fait quand** : `tests/Vente/Api/TicketAgainstSealTest.php` — vente en cours → 409 ; vente de réservation annulée sans règlement → 409 ; ligne altérée en SQL → document **inchangé** (il vient du payload) et `anomaly` non vide ; 23:30 UTC → jour de Paris ; vente antérieure → mention ; vente remboursée → document identique à celui d'avant le remboursement ; `TicketVenteGratuiteTest`, `VenteDirecteTest` verts.
- Revue : [R][S]. · Taille : ~220 / ~250 / 0.

### Lot 8 — Les avoirs justes

#### PR 8a — Lignes, répartition, scellement

##### É24 — Les lignes d'avoir (M-e)
*Couvre G-19, G-18.*
- `app/src/Vente/Entity/CreditNoteLine.php`, `app/src/Vente/Enum/CreditNoteLineKind.php` ; `Avoir` : collection `lines` (lecture, groupe `avoir:read`) ; `InalterabiliteListener::estAppendOnly()` (l.100-114).
- M-e, `sale_credit_note_line` : `id`, `credit_note_id` (FK `vente_avoir`), `sale_line_id` (FK `vente_ligne`, nullable), `kind` (16), `quantity` (nullable), `label` (255), `rate` (`NUMERIC(5,2)`, nullable), `rate_category` (4, nullable), `rate_label` (80, nullable), `rate_ref` (nullable), `gross_amount`, `net_amount`, `vat_amount` (`NUMERIC(10,2)`), `position` (INT).
- **Fait quand** : garde-fous verts (nommage, références libres, nullable, rattachable : lignes lues **à travers** `Avoir`, déjà dans `PerimetreVenteExtension::CHEMINS` l.42) ; modification ou suppression → `OperationInalterableException` ; preuve M-e.
- Revue : [R][S]. · Taille : ~160 / ~60 / ~60.

##### É25 — Répartir et figer
*Couvre G-19.*
- `app/src/Vente/Service/CreditNoteAllocator.php` (pur) et `app/src/Vente/Dto/CreditNoteRequest.php` : lignes visées / geste global / total-restant (D-17), plafonds, plus fort reste, `SaleVatBreakdown::lineVat()`.
- **Fait quand** : `tests/Vente/Unit/CreditNoteAllocatorTest.php` — une ligne à 10 % et une à 20 % visées → deux lignes aux bons taux ; geste global de 10 % sur une vente 20 %/5,5 % → part de chaque taux = 10 % de son TTC, somme exacte ; balayage montants × taux : Σ parts = montant demandé, au centime ; dépassement d'une ligne ou du total → refus ; lignes sans taux → réparties au TTC, sans taux.
- Revue : [R][S]. · Taille : ~220 / ~300 / 0.

##### É26 — L'avoir écrit ses lignes et les scelle
*Couvre G-19.*
- `ContrePassationHandler` : `rembourser(Vente, CreditNoteRequest, motif, auteur)` et `annuler()` écrivent les lignes de l'allocateur ; `creerAvoir()` scelle lignes, `vatBreakdown`, `seller`, numéro et date de la vente d'origine (D-18) ; le plafond d'É11 passe par l'allocateur.
- **Fait quand** : `tests/Vente/Api/CreditNoteRecordingTest.php` — remboursement partiel → lignes négatives et payload scellé correspondant ; annulation → toutes les lignes restantes ; ligne de vente modifiée en SQL après coup → avoir inchangé ; `Avoir::montant = −Σ gross_amount` ; chaîne vérifiée verte avec des avoirs avant et après le format ; `ContrePassationTest`, `tests/Caisse` (clôture de session) verts.
- Revue : [R][S]. · Taille : ~150 / ~200 / 0.

##### É27 — Les trois appelants
*Couvre G-19.*
- `RembourserVenteProcessor` (l.50-92) : corps `{ motif, lignes?: [{ ligne, quantite?, montant? }], montant?, demandeEscalade? }` (lignes visées, sinon montant global, sinon total) ; montant évalué pour l'autorisation graduée = total de la demande ; réponse : lignes de l'avoir. `TraiterDemandeRemboursementHandler` (Boutique, l.35) : montant global. `AnnulationVenteReservationHandler::rembourser()` (l.85-88) : total.
- **Fait quand** : tests API (caisse par lignes, par montant, total) ; `tests/Boutique` (demandes de remboursement) et `tests/Reservation` (annulation remboursée) verts ; escalade inchangée (`tests/Autorisation`).
- Revue : [R][S]. · Taille : ~100 / ~160 / 0.

#### PR 8b — L'extourne à la mesure de l'avoir

##### É28 — Une extourne par avoir (M-f)
*Couvre G-19, G-18.*
- `app/src/Compta/Entity/EcritureComptable.php` : `avoirOrigine` (`?Uuid`) ; M-f : `ALTER TABLE compta_ecriture_comptable ADD avoir_origine BINARY(16) DEFAULT NULL` ; `ProjectionVenteDoctrineAdapter::avoirsNonComptabilises()` (l.70-110) : règle D-19 (par avoir, plus la règle héritée) ; `GenerateurEcrituresHandler` (l.109-128) pose `avoirOrigine`.
- **Fait quand** : deux remboursements partiels d'une même vente → **deux** extournes ; base contenant une extourne ancienne (sans `avoir_origine`) et un seul avoir → **aucune** réextourne au passage suivant ; `tests/Compta` vert ; preuve M-f.
- Revue : [R][S]. · Taille : ~90 / ~120 / ~40.

##### É29 — Le miroir proportionnel
*Couvre G-19.*
- `AvoirProjectionDto` (montants figés par taux) ; `RegimeBase::genererEcritureExtourne()` (D-19).
- **Fait quand** : `tests/Compta/Api/PartialReversalTest.php` — remboursement partiel multi-taux → TVA de l'extourne = TVA de l'avoir par taux, écriture équilibrée ; vente sous PCA → lignes `487` au prorata ; avoir sans taux → facteur global ; annulation sans remboursement préalable → extourne **identique** à celle d'aujourd'hui (comparaison ligne à ligne) ; tests d'extourne existants verts **sans modification**.
- Revue : [R][S]. · Taille : ~200 / ~220 / 0.

#### PR 8c — Vente facturée, et écran de remboursement

##### É30 — La caisse renvoie une vente facturée vers l'`AVF`
*Couvre G-20.* · ⚠ **P-3** décide si `annuler()` appelle aussi la garde ; **P-4** reste ouvert après cette étape.
- `app/src/Vente/Port/SaleInvoiceLookupInterface.php`, `app/src/Facturation/Adapter/SaleInvoiceLookupAdapter.php`, câblage ; garde dans `ContrePassationHandler::rembourser()` (et `annuler()` selon P-3) **avant** toute écriture.
- **Fait quand** : `tests/Vente/Api/InvoicedSaleRefundTest.php` — vente avec facture justificative → 409 nommant la facture et l'`AVF`, aucun avoir, aucun recrédit PMV ; vente sans facture → remboursement accepté (témoin) ; demande en ligne et annulation de réservation sur vente facturée → refus remonté ; `tests/Facturation` vert.
- Revue : [R][S]. · Taille : ~110 / ~150 / 0.

##### É31 — Rembourser des lignes depuis l'historique
*Couvre G-19.*
- `HistoriqueVentesModal.jsx` : `FormulaireRemboursement` (l.384-480) propose les lignes restantes (quantité ou montant) **ou** un geste global ; affiche la répartition par taux rendue par le serveur ; le refus « vente facturée » s'affiche avec sa voie ; `client.js:649` (`rembourserVente`) inchangé de forme.
- **Fait quand** : garde-fous frontaux verts ; vérification réelle consignée.
- Revue : [R]. · Taille : ~200 / 0 / 0.

### Lot 9 — Le ticket PDF compté (PR 9a, 9b, 9c ; mise en service d'un bloc)

#### PR 9a — Le journal des éditions

##### É32 — Extraire l'empreinte de chaîne
*Couvre G-14.* · Refactor.
- `app/src/Vente/Nf525/ChainFingerprint.php` : reprises à l'identique de `HashChainSignataire.php:110-150`.
- **Fait quand** : `HashChainTest`, `Nf525ApiTest`, `VerifierChaineCloisonnementTest`, `tests/Compta/Api/Nf525ChainTest.php` verts **sans modification** ; `tests/Vente/Unit/ChainFingerprintTest.php` (empreinte d'un payload connu, épinglée sur le code d'avant).
- Revue : [R][S]. · Taille : ~70 / ~60 / 0.

##### É33 — La table du journal (M-d) et son entité
*Couvre G-14, G-21, G-18.*
- `DocumentEdition`, `EditedDocumentType` (`sale_ticket`, `credit_note`) ; `estAppendOnly()` ; `CHEMINS`.
- M-d, `nf525_document_edition` : `id`, `document_type` (24), `document_id`, `sale_id` (FK `vente_vente`), `point_of_sale_id` (FK `caisse_point_de_vente`), `establishment_id` (FK `org_etablissement`), `edition_number`, `chain_sequence` (BIGINT), `fingerprint` (128), `previous_fingerprint` (128, nullable), `signature` (512), `payload` (JSON), `emitted_at`, `emitted_by_id` (FK `sec_utilisateur`, nullable) ; unicités (`document_type`, `document_id`, `edition_number`) et (`point_of_sale_id`, `chain_sequence`). Pour un avoir, `sale_id` = vente d'origine.
- **Fait quand** : garde-fous verts ; inaltérabilité testée ; preuve M-d.
- Revue : [R][S]. · Taille : ~190 / ~80 / ~70.

##### É34 — Compter une édition
*Couvre G-14, G-21.*
- `app/src/Vente/Nf525/DocumentEditionJournal.php` : `record(EditedDocumentType, Uuid $documentId, Vente $sale, ?Utilisateur, string $channel, ?string $recipientHash)`, `editionCount()`, `verifyChain(PointDeVente)` ; repère des ventes antérieures (D-12).
- **Fait quand** : `tests/Vente/Api/DocumentEditionJournalTest.php` — n° 1, 2, 3 ; deux enregistrements simultanés → numéros distincts, chaîne intacte ; maillon altéré → anomalie ; chaîne des ventes inchangée ; numérotations ticket et avoir indépendantes ; vente antérieure au repère → n° 2.
- Revue : [R][S]. · Taille : ~220 / ~260 / 0.

#### PR 9b — Le rendu

##### É35 — Porter le rendu PDF
*Couvre G-12.* · `3178c9a2` tel quel. `TicketPdfRenderer.php`, `tests/Vente/Unit/TicketPdfRendererTest.php`.
- **Fait quand** : `TicketPdfRendererTest` vert.
- Revue : [R]. · Taille : ~253 / ~170 / 0.

##### É36 — Le rendu reproduit l'original scellé
*Couvre G-10, G-12, G-14, G-17.* · Correction de `3178c9a2`.
- Rendu du document D-10 tel quel : vendeur et site, n°, date dans le fuseau, lignes (libellé, quantité, prix unitaire, remise, montant), total, ventilation (ou « incomplète », ou « antérieure »), règlements et rendu, bandeau d'anomalie ; mention d'édition en second paramètre de `render()` (rien pour l'original, « DUPLICATA n° k — édité le … » ensuite) ; hauteur D-14 ; gabarit en blocs (en-tête, corps, mention, pied) pour le second corps du lot 10 ; « VENDEUR NON RENSEIGNÉ » seulement si `seller` est nul. `DocumentTicket::pour(Vente, bool)` garde sa signature (É37 s'applique sans retouche).
- **Fait quand** : `TicketPdfRendererTest` étendu — chaque mention de G-12 ; « certifié » absent ; aucun code de support ; 40 lignes de 120 caractères → **une page**, dernier libellé présent (vérifié sur le PDF) ; original et duplicata **identiques hors mention** (comparaison du HTML) ; anomalie imprimée.
- Revue : [R]. · Taille : ~240 / ~230 / 0.

#### PR 9c — La route et l'écran

##### É37 — Porter la route du PDF
*Couvre G-15.* · `237a1780`, un ajustement au portage : le chemin `ticket-pdf`.
- **Fait quand** : `router:match /api/ventes/<uuid>/ticket-pdf` → `vente_ticket_pdf` ; `TicketPdfRouteTest` vert.
- Revue : [R][S]. · Taille : ~106 / 91 / 0.

##### É38 — La route devient une émission comptée
*Couvre G-13, G-14, G-15, G-17.* · Correction de `237a1780`.
- `POST`, contrôles et en-têtes D-13, émission D-12.
- **Fait quand** : `TicketPdfRouteTest` réécrit — validée par l'écran puis trois émissions → original, DUPLICATA n° 2, n° 3 ; deux simultanées → deux numéros ; `imprime` posé ; annulation ensuite → billets invalidés ; vente remboursée → réimprimable, **sans** mention d'avoir ; vente en cours → 409 ; autre établissement → 404 ; utilisateurs partenaire et terminal injectés → 404 ; vrais jetons → ni 200 ni PDF (code consigné) ; sans `vente.lire` → 403 ; `GET` → 405 ; vente directe → original ; `%PDF`.
- Revue : [R][S]. · Taille : ~130 / ~230 / 0.

##### É39 — La consultation ne compte pas
*Couvre G-14.*
- `TicketProcessor` : `duplicata` = au moins une édition comptée ; `DocumentTicketTest` : `testLePremierAppelExpliciteEstDejaUnDuplicata` et `duplicata: true` remplacés **délibérément** (G-14, C-1).
- **Fait quand** : `DocumentTicketTest`, `TicketVenteGratuiteTest`, `tests/Caisse` verts.
- Revue : [R]. · Taille : ~40 / ~70 / 0.

##### É40 — L'écran imprime le PDF compté
*Couvre G-14, G-15, G-16, G-17.*
- `client.js` : `ticketPdf()` ; `Caisse.jsx` : « Imprimer le ticket » (`TicketVente`, l.2056) et « Réimprimer » (`onDuplicata`, l.1141-1150) → PDF compté ; au-dessus du seuil, l'écran propose ; `premiereEdition` de `construireTicket()` (l.880-924) supprimé ; `HistoriqueVentesModal.jsx` l.235 : « Réimprimer » aussi pour `annulee` et `avoir_emis`. « Imprimer le billet » (l.1958) inchangé.
- **Fait quand** : `window.print` ne reste que pour le billet ; plus d'`api.ticket(…, 'duplicata')` ; garde-fous verts ; **après déploiement**, `POST /api/ventes/{id}/ticket-pdf` réel → `application/pdf` ; aucune URL ne porte de jeton.
- Revue : [R]. · Taille : ~160 / 0 / 0.

### Lot 10 — Le justificatif d'avoir

#### PR 10a — Le document et son PDF

##### É41 — Le document d'avoir
*Couvre G-21.* · ⚠ **P-1** bloque la seule mention de certification.
- `app/src/Vente/Service/CreditNoteDocument.php` (D-21) ; second corps de `TicketPdfRenderer` (lignes d'avoir, ventilation négative, référence de la vente d'origine).
- **Fait quand** : `tests/Vente/Unit/CreditNoteDocumentTest.php` — chaque mention de G-21 ; document = payload scellé ; ligne d'avoir altérée en SQL → anomalie, document inchangé ; avoir antérieur → montant seul et mention ; « certifié » absent (ou mention de P-1, quand tranchée) ; aucun code d'accès.
- Revue : [R][S]. · Taille : ~200 / ~200 / 0.

##### É42 — L'émission comptée du justificatif
*Couvre G-21.*
- `app/src/Vente/Controller/CreditNotePdfController.php` : `POST /api/avoirs/{id}/credit-note-pdf`, contrôles D-13 (`vente.lire`), journal `credit_note`, canal `print`.
- **Fait quand** : `tests/Vente/Api/CreditNotePdfRouteTest.php` — deux émissions → original puis « DUPLICATA n° 2 — édité le … » ; numérotation indépendante de celle du ticket de la vente ; autre établissement → 404 ; sans droit → 403 ; avoir non scellé → 409 ; `%PDF`.
- Revue : [R][S]. · Taille : ~120 / ~180 / 0.

#### PR 10b — L'e-mail et l'écran

##### É43 — Envoyer le justificatif par e-mail, selon D94
*Couvre G-21.*
- `app/src/Vente/Notification/CreditNoteMailer.php` (patron `ConfirmationCommandeMailer`, Twig + `MailerInterface`, pièce jointe PDF) ; `app/src/Vente/Controller/CreditNoteEmailController.php` : `POST /api/avoirs/{id}/credit-note-email`, corps `{ email }` validé, droit `vente.rembourser` ; `ExpediteurCourriel::estBranche()` faux → **503** nommant ce qui n'a pas eu lieu (D94), rien compté ; vrai → envoi, puis édition comptée (canal `email`, empreinte de l'adresse) ; échec du transport → rien compté, erreur dite. Expéditeur : voir §8 (mesure).
- **Fait quand** : test API (environnement de test = `null://null`) → 503, aucune édition ; test **au niveau du service** avec un `ExpediteurCourriel` construit sur un DSN réel factice et le collecteur de messages (patron `App\Tests\Boutique\Support\MailCollector`, `when@test` l.431) → un message, PDF joint, une édition `email` ; adresse invalide → 422 ; autre établissement → 404.
- Revue : [R][S]. · Taille : ~150 / ~180 / 0.

##### É44 — L'écran de l'avoir
*Couvre G-21.*
- Après un remboursement ou une annulation : « Imprimer le justificatif », « Envoyer par e-mail » (éteint et expliqué si `envoiCourrielBranche` est faux), ou rien (AGEC) ; dans l'historique, sur chaque avoir : justificatif et réimpression. PDF par `fetch` + `Blob` (D-15).
- **Fait quand** : garde-fous frontaux verts ; aucune impression ne part sans clic ; vérification réelle après déploiement consignée.
- Revue : [R]. · Taille : ~180 / 0 / 0.

---

## 3. Tests

Commandes (chemins relatifs à `app/`) : `./infra/test-stack.sh up ticket07` · `./infra/test-stack.sh run ticket07 <chemins>` · `./infra/test-stack.sh down ticket07`. La suite PHP ne tourne pas en CI : chaque critère se lance avant de rendre.

| Étape | Tests (neufs ou repris) | Non-régression |
|---|---|---|
| É1-É2 | `tests/Vente/Api/IdempotenceReglementTest.php` (repris, complété) + preuve M-a | `PaiementTest`, `HorsLigneTest`, `RefusCarteTest` |
| É3 | test d'entité `PaymentAttempt` + preuve M-b | — |
| É4 | `tests/Vente/Api/PaymentAttemptTest.php`, `tests/Vente/Support/FailingWriteListener.php` | `tests/Vente`, `tests/Caisse` |
| É5 | `tests/Vente/Api/ConcurrentSettlementTest.php`, `BlockingTerminal.php`, `settle-in-other-process.php` | `RefusCarteTest` |
| É6 | `tests/Vente/Api/SettlementDeclarationTest.php` | `tests/Vente` |
| É7 | `frontend/scripts/test-payment-intent.mjs` | `./bin/garde-fous.sh` |
| É8-É9 | vérification réelle consignée | contrôles frontaux |
| É10 | `tests/Reservation/Api/NoShowDebitUnitTest.php` | `tests/Reservation`, `tests/Vente` |
| É11 | plafond cumulé (fichier de contre-passation) | `ContrePassationTest` |
| É12-É13 | `tests/Vente/Api/DocumentTicketTest.php` (repris) | `tests/Vente`, `tests/Caisse` |
| É14 | preuve M-c | `LibelleFigeTest`, `ChampsFigesTest` |
| É15 | `tests/Vente/Api/StampedVatRateTest.php` | `tests/Vente`, `tests/Compta` |
| É16 | listes d'échecs avant/après | douze suites (É16) |
| É17 | `tests/Vente/Api/LineWithoutVatRefusalTest.php` | Vente, Reservation, Padel |
| É18 | `tests/Boutique/Api/` (panier, souscription) | Boutique, Sport |
| É19 | `tests/Vente/Unit/SaleVatBreakdownTest.php` (repris) | `tests/Vente` |
| É20 | `tests/Vente/Unit/LineVatOracleTest.php` | `tests/Compta` sans modification |
| É21 | `tests/Compta/Api/StampedRateLedgerTest.php` | `tests/Compta` |
| É22 | `tests/Vente/Api/SealedPayloadTest.php` | `HashChainTest`, `Nf525ApiTest`, `ClotureJournaliereTest` |
| É23 | `tests/Vente/Api/TicketAgainstSealTest.php` | `TicketVenteGratuiteTest`, `VenteDirecteTest` |
| É24 | inaltérabilité des lignes + preuve M-e | `ImmuabiliteTest` |
| É25 | `tests/Vente/Unit/CreditNoteAllocatorTest.php` | — |
| É26 | `tests/Vente/Api/CreditNoteRecordingTest.php` | `ContrePassationTest`, `tests/Caisse`, `Nf525ApiTest` |
| É27 | tests API de remboursement | `tests/Boutique`, `tests/Reservation`, `tests/Autorisation` |
| É28 | double extourne, règle héritée + preuve M-f | `tests/Compta` |
| É29 | `tests/Compta/Api/PartialReversalTest.php` | `tests/Compta` sans modification des tests d'extourne |
| É30 | `tests/Vente/Api/InvoicedSaleRefundTest.php` | `tests/Facturation`, `tests/Boutique`, `tests/Reservation` |
| É31 | vérification réelle consignée | contrôles frontaux |
| É32 | `tests/Vente/Unit/ChainFingerprintTest.php` | chaînes Vente et Compta |
| É33 | inaltérabilité + preuve M-d | `ImmuabiliteTest` |
| É34 | `tests/Vente/Api/DocumentEditionJournalTest.php` | `Nf525ApiTest` |
| É35-É36 | `tests/Vente/Unit/TicketPdfRendererTest.php` (repris, étendu) | — |
| É37-É38 | `tests/Vente/Api/TicketPdfRouteTest.php` (repris, réécrit) | `CloisonnementTest` |
| É39 | `DocumentTicketTest` | `tests/Caisse` |
| É40 | appel réel après déploiement | garde-fous |
| É41 | `tests/Vente/Unit/CreditNoteDocumentTest.php` | — |
| É42 | `tests/Vente/Api/CreditNotePdfRouteTest.php` | `CloisonnementTest` |
| É43 | test API (503) + test de service (envoi) | `tests/Boutique` (courriels) |
| É44 | vérification réelle consignée | contrôles frontaux |

En fin de chaque PR : `run ticket07 tests/Vente tests/Caisse` et les suites des modules touchés.

---

## 4. Couverture

| Objectif | Étapes |
|---|---|
| **G-1** — clé, rejeu sans TPE ni PMV, clé d'une autre vente refusée, chaque appelant fournit sa clé | É1, É2, É4, É7, É8, É9, É10 |
| **G-2** — une clé par intention jusqu'à l'issue ; plus de « réessayez » | É7, É8, É9 |
| **G-3** — une tentative en cours par vente | É3, É4, É5 |
| **G-4** — même clé, contenu différent : refus | É2 |
| **G-5** — argent et écriture dans une unité ; événements après commit | É4, É5, É10 |
| **G-6** — tentative avant le terminal ; déclaration | É3, É5, É6, É8, É9 |
| **G-7** — taux gravé depuis la correspondance valide | É14, É15 |
| **G-8** — un seul calcul ; ticket = écritures | É19, É20, É21 |
| **G-9** — jamais 0 % en silence ; refus par le créateur de la ligne | É15, É16, É17, É18, É19, É20 |
| **G-10** — ventilation et vendeur dans l'empreinte ; confrontation | É22, É23, É36 |
| **G-11** — un seul document, extraction prouvée | É12, É13 |
| **G-12** — PDF 80 mm complet, sans troncature, fuseau, ni certification ni code d'accès | É22, É23, É35, É36 |
| **G-13** — pas de ticket d'une vente non validée | É23, É38 |
| **G-14** — journal scellé ; émission comptée ; mention du compte | É32, É33, É34, É36, É38, É39, É40 |
| **G-15** — route sous `/api`, droit, 404, pas de jeton dans l'URL | É37, É38, É40 |
| **G-16** — « Imprimer » et « Réimprimer » par le PDF compté | É40 |
| **G-17 (révisé)** — duplicata = original scellé + mention ; aucune mention d'avoir ; réimprimable | É22, É23, É36, É38, É40 |
| **G-18** — migrations renumérotées, à la main, `BINARY(16)` | É1, É3, É14, É24, É28, É33 |
| **G-19** — avoir en lignes négatives figées, scellé ; extourne à sa mesure ; plafonds | É11, É24, É25, É26, É27, É28, É29, É31 |
| **G-20** — vente facturée renvoyée vers l'`AVF` | É30 (P-3, P-4 ouverts) |
| **G-21** — justificatif d'avoir, compté, à la demande ou par e-mail (D94) | É33, É34, É41, É42, É43, É44 |

Tous les objectifs sont couverts. Aucune étape ne couvre un objectif absent (É16 sert G-9 ; É32 sert G-14). Points ouverts : P-1 (une mention d'É41), P-3 (une ligne d'É30), P-4 (aucune étape tant que non tranché), P-5 (étape optionnelle du lot 4).

---

## 5. Fiches à produire

`docs/references/README.md` lu le 07/10 : **index vide**, aucune fiche à citer. Versions lues dans `app/composer.lock`, `frontend/package-lock.json`, et hors verrous dans `docker/php/Dockerfile:1`, `infra/compose.preprod.yaml:247`, `infra/test-stack.sh:73`. Le `documentaliste` n'ouvre sans invite que symfony.com, www.php.net, getcomposer.org et developer.mozilla.org ; les autres sources officielles demanderont l'accord de la session.

| N° | Bibliothèque | Version | Fiche | Question précise | Étapes |
|---|---|---|---|---|---|
| F-1 | `dompdf/dompdf` | v3.1.6 | `dompdf-3.1.md` | Nombre de pages produites ou hauteur occupée après `render()` (une seule page) ; coupure des mots longs ; `setChroot()`, `setIsRemoteEnabled(false)` en 3.1 | É35, É36, É41 |
| F-2 | `doctrine/dbal` | 4.4.4 | `doctrine-dbal-4.4.md` | `transactional()` imbriquée (points de sauvegarde ? rollback-only) ; `UniqueConstraintViolationException`, `RetryableException` ; `executeStatement()` dans la transaction | É4, É5, É10, É26, É34, É38, É42, É43 |
| F-3 | `doctrine/orm` | 3.6.8 | `doctrine-orm-3.6.md` | `lock(PESSIMISTIC_WRITE)` ; `refresh()` et collections ; EM fermé après un `flush()` en échec ; `#[ORM\UniqueConstraint]` sur colonne nullable ; `OneToMany` en lecture seule (lignes d'avoir) | É4, É5, É6, É10, É24, É26, É34 |
| F-4 | `doctrine/doctrine-bundle` | 3.3.1 | `doctrine-bundle-3.3.md` | Écouteur `prePersist` qui lit d'autres entités ; ordre des écouteurs | É15 |
| F-5 | `doctrine/migrations` | 3.9.7 | `doctrine-migrations-3.9.md` | Table et colonnes de suivi par défaut (`doctrine_migration_versions`, `version`, `executed_at` ?) ; `migrations:status` et `migrations:execute --up/--down` sur une base désignée | É14, É23, É24, É28, É33, É34, preuves |
| F-6 | MariaDB | 11.4 | `mariadb-11.4.md` | `UNIQUE` sur colonne nullable (plusieurs `NULL`) ; deux `INSERT` concurrents sur clé unique ; `innodb_lock_wait_timeout` ; type `JSON` | É3, É4, É5, É33, É34 |
| F-7 | `api-platform/symfony`, `state`, `metadata` | v4.3.17 | `api-platform-4.3.md` | Suffixe `{._format}` d'une opération `Post` personnalisée et capture de `/ticket.pdf` ; processeur qui rend une `Response` ; `read: true` + `input: false` | É6, É37, É38 |
| F-8 | `symfony/routing`, `http-kernel`, `framework-bundle` | v7.4.15, v7.4.16, v7.4.16 | `symfony-routing-7.4.md` | Ordre entre routes d'attributs et routes d'API Platform ; `priority` ; `router:match` avec méthode | É37, É42, É43 |
| F-9 | `symfony/security-bundle`, `security-core`, `security-http`, `lexik/jwt-authentication-bundle` | v7.4.15, v7.4.15, v7.4.14, v3.2.0 | `symfony-security-7.4.md` | Réponse du pare-feu `api` à un jeton qui n'est pas un JWT d'`Utilisateur` ; `getUser()`/`isGranted()` en contrôleur | É38, É42, É43 |
| F-10 | `symfony/http-foundation` | v7.4.16 | `symfony-http-foundation-7.4.md` | `HeaderUtils::makeDisposition()` ; `Cache-Control: no-store` | É38, É42 |
| F-11 | `symfony/uid` | v7.4.9 | `symfony-uid-7.4.md` | Aucune dépréciation 7.4 sur `isValid`, `fromString`, `toBinary` (usage de `PorteMonnaieVirtuelAdapter.php:62`) | É2, É3, É34 |
| F-12 | `phpunit/phpunit` (+ `symfony/browser-kit` v7.4.14) | 13.3.1 | `phpunit-13.3.md` | `#[DataProvider]` (fournisseur statique ?) ; corps binaire et en-têtes avec le client d'`ApiTestCase` | É20, É25, É36, É38, É42 |
| F-13 | PHP | 8.4 | `php-8.4.md` | `round()` sur les demi-valeurs en 8.4 ; `DateTimeImmutable::setTimezone()` ; `proc_open` ; `intdiv` et plus fort reste en entiers | É5, É20, É23, É25 |
| F-14 | `react`, `react-dom` | 18.3.1 | `react-18.3.md` | Effet de montage doublé en mode strict ; nettoyage d'un minuteur | É8, É9 |
| F-15 | Node (hôte) | v20.20.2 | `node-20.md` | `node --test`, `node:assert` : sortie et code de retour exploitables par `bin/garde-fous.sh` | É7 |
| F-16 | API du navigateur (MDN) | — | `web-fetch-blob.md` | `fetch` + `AbortController` ; `blob()` ; `createObjectURL`/`revokeObjectURL` ; `window.open` après `await` et impression d'un PDF `blob:` ; `sessionStorage` ; `crypto.randomUUID()` (contexte sécurisé) | É7, É8, É9, É40, É44 |
| **F-17** | `symfony/mailer` (+ `symfony/messenger`) | v7.4.15 (+ v7.4.15) | `symfony-mailer-7.4.md` | `MailerInterface::send()` est-il **synchrone** quand Messenger est installé et que `SendEmailMessage` n'est pas routé (`config/packages/messenger.yaml` ne route que `AsyncMessage`) ? Exceptions levées par un transport en échec (`TransportExceptionInterface`) ; comportement du transport `null://` ; événement `MessageEvent` pour les tests | É43 |
| **F-18** | `symfony/mime` (+ `symfony/validator`, `egulias/email-validator`) | v7.4.16 (+ v7.4.16, 4.0.4) | `symfony-mime-7.4.md` | `Email::attach()` (contenu binaire, nom, type) ; `Address` ; contrainte `Email` (mode de validation par défaut en 7.4) pour l'adresse saisie | É43 |
| **F-19** | `twig/twig` (+ `symfony/twig-bundle`) | v3.28.0 (+ v7.4.15) | `twig-3.28.md` | Échappement automatique dans un gabarit de courriel HTML ; rendu par `Environment::render()` hors contrôleur (patron `ConfirmationCommandeMailer`) | É43 |

Si une fiche contredit une décision (D-4, D-5, D-13, D-14, D-19, D-21 surtout), l'architecte corrige l'étape avant CP-2.

---

## 6. Lots et tailles

| Ordre | Lot (PR) | Étapes | Code | Tests | Migr. | Dépend de | Mise en service |
|---|---|---|---|---|---|---|---|
| 1 | **1 — Clé du règlement** (PR 1) | É1-É2 | ~190 | ~320 | ~50 | — | seule, sans risque (clé facultative) |
| 2 | **2 — Tentatives** (PR 2) | É3-É5 | ~650 | ~680 | ~70 | 1 | **avec le lot 3** (un « résultat inconnu » bloque la vente jusqu'à une déclaration que l'écran actuel ne sait pas faire) |
| 3 | **3 — Déclaration et écrans** (PR 3) | É6-É9 | ~690 | ~360 | 0 | 2 | avec le lot 2 ; vérification réelle |
| 4 | **4 — No-show et plafond** (PR 4) | É10-É11 (+ P-5) | ~190 | ~290 | 0 | 1 | seule |
| 5 | **5 — Ticket en un seul endroit** (PR 5) | É12-É13 | ~125 | ~180 | 0 | — | seule, invisible |
| 6 | **6 — TVA gravée** (PR 6) | É14-É18 | ~430 | ~770 | ~45 | — | **mesure préalable** des produits rendus invendables |
| 7 | **7 — Ticket = comptes** (PR 7) | É19-É23 | ~675 | ~920 | 0 | 5, 6 | chaîne vérifiée avant et après |
| 8 | **8a — Avoirs : lignes, répartition, scellement** | É24-É27 | ~630 | ~720 | ~60 | 6, 7 | seule |
| 9 | **8b — Extourne à la mesure** | É28-É29 | ~290 | ~340 | ~40 | 8a | seule (avant la génération d'écritures suivante) |
| 10 | **8c — Vente facturée, écran de remboursement** | É30-É31 | ~310 | ~150 | 0 | 8a ; **P-3** | seule ; P-4 reste ouvert |
| 11 | **9a — Journal des éditions** | É32-É34 | ~480 | ~400 | ~70 | 7 | **9a + 9b + 9c d'un bloc** (D-12) |
| 12 | **9b — Rendu** | É35-É36 | ~490 | ~400 | 0 | 9a | idem |
| 13 | **9c — Route et écran** | É37-É40 | ~440 | ~390 | 0 | 9b, 3 | idem ; appel réel après déploiement |
| 14 | **10a — Justificatif : document et PDF** | É41-É42 | ~320 | ~380 | 0 | 8a, 9 ; **P-1** (une mention) | seule |
| 15 | **10b — Justificatif : e-mail et écran** | É43-É44 | ~330 | ~180 | 0 | 10a | seule ; 503 tant que D82 n'est pas fait |
| | **Total** | **44 étapes** | **~6 240** | **~6 480** | **~335** | | |

Aucune PR ne dépasse ~700 lignes de code ; aucun commit ne vise plus de 300. Une PR = brouillon tant qu'elle avance, fusion squash sur CI verte, suite PHP lancée sur `ticket07` avant de rendre (la CI ne la lance pas).

---

## 7. Q-C4 : tranchée le 07/10 — ce qui a changé dans le plan

- **Q-C4 n'est plus en attente** : le justificatif d'avoir est complet dans ce lot (G-21), précédé de l'enregistrement juste (G-19) et de la règle « vente facturée » (G-20). La section « Si Q-C4 = B ou C » est supprimée.
- **Le journal générique (D-11) sert** : `EditedDocumentType::CreditNote` existe dès É33, sans migration de plus.
- **G-17 révisé** : l'ancienne étape « mentions postérieures sous le document » est **supprimée** ; le duplicata reproduit l'original scellé (D-9 étend le payload à tout ce qui s'imprime ; D-10 construit le document depuis le payload).
- **Lots neufs** : 8a, 8b, 8c (G-19, G-20) et 10a, 10b (G-21) ; É11 (plafond des remboursements) rejoint le lot 4.
- **Livraison réordonnée** : le règlement d'abord (lots 1 à 4), puis le reste.

---

## 8. Interprétations retenues, et ce qui reste à mesurer

**Interprétations** (sans question ouverte ; chacune est écrite là où elle agit) :
1. G-9 « à l'ajout au panier » : le refus arrive au premier « Régler », avant tout paiement (D-6).
2. G-15 « jeton partenaire ou terminal → 404 » : prouvé au contrôleur ; un vrai jeton sera probablement arrêté en 401 par le pare-feu (D-13).
3. G-14 et le seuil : `imprime` reste posé à la validation ; aucune édition n'y est comptée (D-12).
4. Ventes antérieures au journal : première édition comptée = « DUPLICATA n° 2 » (D-12).
5. Clé facultative sur l'API : sérialisée, pas rejouable sans clé (D-3).
6. Validation bloquée par une tentative ouverte (D-4, dérivé de G-6).
7. « Au prorata des bases par taux » : chaque base réduite dans la même proportion, ce qui revient à répartir le TTC au prorata du TTC de chaque taux (D-17).
8. Lignes d'avoir **négatives** en base ; `Avoir::$montant` reste positif (D-17).
9. Vente antérieure au taux gravé : avoir sans taux, extourne au facteur global sur l'écriture d'origine (D-17, D-19).
10. E-mail du justificatif : droit `vente.rembourser` (D-13) ; journal : empreinte de l'adresse, jamais l'adresse (D-11).

**Ce qui reste à mesurer pour l'e-mail (P-2), sans lire `.env.preprod` :**
- en préprod, `GET /me` → `envoiCourrielBranche` (`MeController.php:91`) : vrai ou faux aujourd'hui ;
- le prestataire (D82) et la clé, posés par Maxime ; d'ici là, É43 répond 503 en production comme en préprod si `null://` ;
- l'**adresse d'expédition** : le patron l'écrit en dur (`ConfirmationCommandeMailer.php:55`, `no-reply@itcotation.com`) ; pour le justificatif, la même adresse ou celle du prestataire D82 — à fixer avec lui (authentification du domaine) ;
- la **preuve de délivrance** : le journal ne compte que la remise au transport ; la délivrance attend le suivi du prestataire.

**Constats faits en lisant, hors des décisions (signalés, non traités) :**
- `GenerateurNumero::numeroAvoir()` (l.69-78) numérote par `COUNT` global, **tous établissements confondus** : chaque exploitant voit des trous dans sa série, et deux avoirs simultanés peuvent prendre le même numéro (`uniq_avoir_numero` → 500). À arbitrer pour la série « distincte » de G-21.
- `EmissionFactureJustificativeHandler` (l.61) émet une facture pour une vente **annulée ou remboursée** (`estScellee()` couvre ces statuts), au montant d'origine : symétrique de G-20, pour le lot Facturation.
- La description API de `POST /ventes/{id}/annuler` dit « Annule une vente non validée » (`Vente.php:125`) alors que le gestionnaire exige une vente validée (`ContrePassationHandler.php:133-138`) ; « Abandonner » de la caisse (`Caisse.jsx:977`) l'appelle sur une vente en cours : toujours refusé.

---

## 9. UNVERIFIED restants

Chacun bloque l'étape indiquée jusqu'à sa levée.

| N° | Point | Bloque | Se lève par |
|---|---|---|---|
| U-1 | Une route `POST` API Platform capte-t-elle `/ticket.pdf` par `{._format}` ? (évité par `ticket-pdf`) | É37 | F-7, F-8, `router:match` |
| U-2 | Code HTTP réel d'un jeton partenaire ou de terminal sur `/api/…` (401 attendu) | É38, É42 | F-9, mesure |
| U-3 | API Dompdf pour une page unique sans troncature | É36, É41 | F-1 |
| U-4 | `transactional()` imbriquée en DBAL 4.4 | É10 | F-2 |
| U-5 | État de l'`EntityManager` après un `flush()` en échec ; `refresh()` des collections | É4, É5 | F-3 |
| U-6 | Plusieurs `NULL` dans un index unique, `INSERT` concurrents, en MariaDB 11.4 | É3, É4 | F-6 |
| U-7 | Colonnes de la table de suivi des migrations (repères des ventes antérieures) | É23, É34 | F-5 |
| U-8 | `round()` en PHP 8.4 face à l'oracle | É20 | F-13, oracle |
| U-9 | Effet de montage doublé en mode strict | É8, É9 | F-14 |
| U-10 | PDF `blob:` après `await` (bloqueurs), impression ; `crypto.randomUUID()` hors HTTPS | É7, É40, É44 | F-16 |
| U-11 | Délai réel d'un TPE et `request_terminate_timeout` de FPM face au seuil de 120 s | É5 | lecture de la conf servie (lecture seule) |
| U-12 | Effets de `ComptaFixtures` sur les suites existantes ; tests sur le site B | É16 | mesure de l'étape |
| U-13 | Préprod : produits rendus invendables par G-9 | mise en service du lot 6 | requête en lecture seule |
| U-14 | Préprod : chaque vente validée a-t-elle une opération scellée `vente` ? | É23 | requête en lecture seule |
| U-15 | Préprod : chaque site est-il couvert par un profil exploitant ? | É22 | requête en lecture seule |
| U-16 | Garde-fou n°29 face à `POST /ventes/{id}/declarer-reglement` | É6 | `./bin/garde-fous.sh` |
| U-17 | Lignes du patron d'unicité rattrapée de `SubscriptionInvoicer` (fichier vérifié, lignes non relues) | É34 | relecture |
| U-18 | `MailerInterface::send()` synchrone avec Messenger installé et `SendEmailMessage` non routé | É43 | F-17 |
| U-19 | Préprod : `envoiCourrielBranche` de `/me` | utilité d'É43 (pas son code) | appel réel |
| U-20 | Préprod : des ventes portent-elles **plusieurs** avoirs et une extourne sans `avoir_origine` ? (portée réelle de la règle héritée de D-19) | É28 | requête en lecture seule |
| U-21 | Préprod : des ventes remboursées ont-elles **aussi** une facture justificative ? (ce que G-20 aurait refusé) | É30 | requête en lecture seule |
| U-22 | Comptes de produit sous PCA : l'extourne miroir sur `487` est-elle juste quand une part a déjà été reconnue en produit ? (l'extourne totale d'aujourd'hui l'ignore aussi) | É29 | lecture de `traiterPca()` dans `GenerateurEcrituresHandler` à l'étape |
