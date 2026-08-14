# Spec — Vente & Caisse (`M2` / lot `L2`)

- **Lot / module :** L2 · M2 Vente & Caisse
- **Stories couvertes :** US-L2-01 à US-L2-12
- **Règles de gestion :** RG-M2-01 à RG-M2-08
- **Statut :** brouillon

## 1. Objectif
Permettre à un agent de caisse d'**encaisser une vente au guichet en quelques gestes**, dans une **session de caisse conforme** (régie, NF525) — du fond de caisse jusqu'à la clôture Z — avec panier en direct, paiement scindé multi-moyens, édition du billet/ticket et remise du support d'accès, sans jamais rompre la piste d'audit ni interrompre l'accueil du public.

## 2. Périmètre
- **Inclus :**
  - Session de caisse : ouverture (point de vente, fond, régisseur), état, clôture Z, mouvements d'espèces, versement, état de régie — US-L2-01/10, RG-M2-01/06.
  - Écran de caisse tactile : rayons, favoris, recherche, panier temps réel — US-L2-02.
  - Ajout au panier : quantité, tarif, bénéficiaire, promotions applicables — US-L2-03, RG-M2-04 (bénéficiaire requis).
  - Blocage d'un produit sans stock — US-L2-04, RG-M2-04.
  - Rattachement d'un client à la vente (optionnel) — US-L2-05.
  - Paiement scindé multi-moyens jusqu'à reste dû = 0, rendu de monnaie espèces — US-L2-06, RG-M2-02/03/05.
  - Intégration TPE (Ingenico / Nayax / PAX) — US-L2-07, RG-M2-03.
  - Ticket paramétrable (seuil d'impression), renvoi e-mail/SMS, appairage support d'accès — US-L2-08, RG-M2-04.
  - Remboursement / avoir / annulation tracés, par contre-passation — US-L2-09, RG-M2-07.
  - Conformité NF525 : inaltérabilité, signature et chaînage des opérations, clôtures périodiques — US-L2-11.
  - Mode dégradé hors-ligne + resynchronisation sans doublon — US-L2-12, RG-M2-08.
- **Exclu (pour l'instant), que M2 *référence* seulement :**
  - Définition des produits, tarifs, grilles, saisons, promotions, stocks → **M1** (lot L1), réutilisé (`spec-offre.md`, RG-M1-01/07…). M2 **consomme** le prix résolu (produit × type de tarif × saison + QF), les canaux, les promotions et le stock ; ne les redéfinit pas.
  - Écritures comptables, reconnaissance de recette (PCA), TVA appliquée, e-reporting fiscal, référentiel des moyens de paiement et **acte de régie** → **M6** ; M2 **alimente** M6 et **filtre** les moyens de paiement selon l'acte de régie fourni par M6 (RG-M2-02).
  - Validation physique au tourniquet / compostage réel, dévalidation d'un support → module **Accès** (lot L3) ; M2 émet le support et déclenche l'appairage, l'Accès le consomme.
  - Réservation / consommation de séances et de quotas → **M5**.
  - Le fichier client (CRM), la fiche famille, les mandats SEPA → **M4** ; M2 recherche/rattache/crée un client via ce module.
  - L'UI (front) elle-même ; l'authentification, les rôles/permissions et le journal d'audit → **socle L0** (`spec-socle.md`), réutilisés et non redéfinis ici.
  - Vente en ligne / Click & Pay (tunnel e-commerce) → **M3** ; M2 partage la Commande mais l'encaissement à distance sort du guichet L2. ⚠ HYPOTHÈSE : l'action « Click & Pay » (lien à distance) apparaît sur l'écran de caisse M2-02 mais aucune US-L2 ne la couvre ; rattachée à M3, hors périmètre L2.

## 3. Acteurs & droits
Les permissions réutilisent le modèle du socle (`RG-SOCLE-02/03/04/05`) : couple `module × action`, portées par l'**établissement actif** ; l'UI **masque** ce qui n'est pas autorisé (`RG-SOCLE-04`). Deux modules de droits sont impliqués : **`vente`** (composer/encaisser une vente) et **`caisse`** (piloter la session de régie et les opérations sensibles).

| Acteur | Peut | Ne peut pas | Permission (module × action) |
|---|---|---|---|
| **Agent de caisse** | Composer un panier, encaisser, rattacher/créer un client, imprimer/renvoyer un ticket | Ouvrir/clôturer une caisse, rembourser, annuler après impression, forcer un prix | `vente × creer`, `vente × encaisser`, `vente × lire`, `offre × lire` (socle, pour lire prix/stock) |
| **Régisseur** | Ouvrir / sécuriser / clôturer la caisse (Z), saisir le fond, mouvements d'espèces, versement | Modifier les moyens de paiement autorisés (config M6) | `caisse × ouvrir`, `caisse × cloturer`, `caisse × mouvement` |
| **Responsable** | Rembourser, émettre un avoir, annuler une vente (y c. après impression), forcer un prix (si autorisé) | — | `vente × rembourser`, `vente × annuler`, `vente × forcer_prix` |
| **Administrateur** | Configurer points de vente, caisses, périphériques (TPE, imprimante), seuils d'impression, moyens de paiement autorisés | — | `caisse × gerer` (surensemble), `securite × gerer` (socle, délégation) |
| **Lecture seule** | Consulter les ventes/tickets d'une session | Toute action d'écriture | `vente × lire` |

- ⚠ HYPOTHÈSE : les noms de permissions (`vente × encaisser`, `caisse × ouvrir`, `vente × rembourser`, `vente × forcer_prix`…) déclinent le tableau « Acteurs & droits » du cahier M2-§2 selon le modèle `module × action` du socle, mais ne sont pas nommés littéralement dans les sources ; découpage fin à arbitrer avec M8. La séparation « Agent ne peut pas rembourser/annuler après impression / Responsable le peut » est, elle, explicite (cahier M2-§2, RG-M2-07).
- ⚠ HYPOTHÈSE : le cahier distingue **Régisseur** (session/espèces) et **Responsable** (rembours./avoir/annul.) ; le backlog n'emploie que « Agent de caisse » et « Régisseur » et confie le remboursement au Régisseur (US-L2-09). La spec conserve les deux rôles fonctionnels et rattache le remboursement à une permission dédiée (`vente × rembourser`) attribuable indifféremment au Régisseur ou au Responsable selon le paramétrage des rôles (socle) ; **arbitrage M8 requis**.

## 4. Comportements & règles
Chaque comportement trace une **RG-M2** (source : `cahier-detaille.html`, panel `p-m2`) et/ou une **US-L2** (source : `backlog.html`, panel `p-l2`).

### 4.1 Session de caisse — ouverture & clôture
- **RG-M2-01** — **Aucune vente sans session ouverte** : la session exige un **point de vente**, un **fond de caisse** saisi et un **régisseur identifié** (utilisateur + code validé). Toute tentative de vente hors session ouverte est **bloquée** par un message explicite (US-L2-01).
- **Unicité de session** — Une **seule session active par point de vente** à un instant donné (US-L2-01).
- **États de caisse** — 🟢 Ouverte → 🟡 En cours de fermeture → 🔒 Sécurisée (après Z). Une caisse **sécurisée** exige le **code régisseur** pour être rouverte (cahier M2-01).
- **RG-M2-06** — La **clôture Z** arrête la session : elle **totalise** les ventes par nature de recette et par moyen de paiement, les remboursements/avoirs, calcule l'**écart** (théorique vs compté), produit l'**état de régie** (exportable, archivé, ré-imprimable) et **fige** définitivement les ventes de la session. La clôture est **irréversible** : plus aucune vente n'est possible sur une session close (US-L2-10). La clôture est **refusée** si des paiements sont incohérents (cahier M2-§7).
- **Mouvements d'espèces & versement** — Entrées/sorties d'espèces motivées (dont retrait/apport), et **versement** au comptable, sont enregistrés sur la session ; un gros retrait déclenche un mouvement dédié + **alerte au régisseur** (cahier M2-§8). Permission `caisse × mouvement`.
- **Report du fond** — À la clôture, le fond de caisse est **reporté ou repris** selon paramétrage (US-L2-10).
- ⚠ HYPOTHÈSE : le cahier liste les clôtures Z ; US-L2-11 ajoute des **clôtures périodiques mensuelle et annuelle** et un journal inaltérable à l'archivage. Leur déclenchement (manuel/automatique) et leur périmètre (par caisse / par point de vente / par établissement) ne sont pas précisés — **à cadrer avec M6**.

### 4.2 Panier & composition de la vente
- **Écran de caisse** — Navigation par **rayons** et **favoris** paramétrables du point de vente ; **recherche** produit par libellé, code ou code-barres à résultats instantanés ; le **panier** affiche lignes (produit, tarif, qté, bénéficiaire, remise, note), total en **temps réel**, et permet modifier/supprimer une ligne. **Vider le panier** demande confirmation (US-L2-02).
- **Ajout au panier** — Étape quantité / nb de personnes, choix du **type de tarif**, du **bénéficiaire** et des **promotions & options** (promo groupe %, promotion libre en %/€). La quantité est modifiable ligne par ligne, **minimum 1** (US-L2-03, cahier M2-03).
- **Prix issu de la grille (M1)** — Le prix appliqué provient de la **grille tarifaire M1** selon le type de tarif choisi et la **saison du jour** (+ tranche de quotient familial le cas échéant) ; **aucune saisie de prix libre sans droit** (`vente × forcer_prix`). Réutilise RG-M1-01 (cahier M2-02).
- **Promotions** — Les promotions **éligibles s'appliquent automatiquement** et sont **visibles sur la ligne** ; le total recalcule immédiatement, remises comprises (US-L2-03).
- **Recalcul instantané** — Ajouter un produit met à jour le total **instantanément** ; retirer/annuler une ligne recalcule (cahier M2-§7).

### 4.3 Bénéficiaire & client
- **RG-M2-04 (volet bénéficiaire)** — Un produit à **bénéficiaire requis** (ex. abonnement nominatif) **impose** de saisir le/les bénéficiaire(s) avant d'ajouter la ligne ; le bénéficiaire est porté par la **ligne** (US-L2-03, cahier M2-02).
- **Rattachement client** — Recherche client par nom, e-mail ou n° de compte, avec **création rapide** si absent (via M4). Le client rattaché apparaît sur l'**en-tête de la vente** et sur le **ticket**. Le rattachement est **optionnel** : vente comptoir **anonyme** possible (US-L2-05).
- **Bénéficiaire ≠ payeur** — Billet **nominatif au bénéficiaire**, paiement rattaché au **payeur** (lien M4) (cahier M2-§8).

### 4.4 Stock
- **RG-M2-04 (volet stock)** — Un produit **géré en stock à quantité 0** est **grisé et non ajoutable** ; une tentative d'ajout affiche « **stock épuisé** ». Les produits **non gérés en stock** ne sont **jamais bloqués** (US-L2-04). Réutilise la notion de stock M1 (RG-M1-10) ; M2 en déclenche le décrément à la vente.
- ⚠ HYPOTHÈSE : l'instant exact du décrément d'un **stock partagé (pool M1, RG-M1-10)** et la gestion des ruptures simultanées entre caisses / avec la vente en ligne (M3) ne sont pas spécifiés en L2 ; risque de survente concurrente **à arbitrer avec M1/M3** (déjà signalé en L1, cas limite « pool »).

### 4.5 Paiement scindé, TPE, rendu de monnaie
- **RG-M2-02** — Les **moyens de paiement disponibles** dépendent de l'**acte de régie** (profil exploitant M6) : Espèces, CB (TPE), Chèque, Virement, Chèques Vacances/Culture/Loisirs, PMV, Avoir, Paiement différé… **filtrés** par la régie (cahier M2-04).
- **RG-M2-03** — Le **paiement peut être scindé** sur plusieurs moyens : chaque ligne de règlement s'additionne, un **reste à payer** est recalculé après chaque ajout (montant par défaut = reste dû). La commande **n'est validée que si encaissé = dû (reste = 0)**, **sauf paiement différé autorisé** (justificatif non acquitté). La validation est **impossible** tant que reste dû > 0 (US-L2-06, cahier M2-04/§7).
- **RG-M2-05** — Le **rendu de monnaie** n'est calculé et autorisé **que sur les espèces** ; aucun rendu sur CB, chèque ou chèques vacances (US-L2-06, cahier M2-04).
- **TPE (US-L2-07)** — Le montant est **envoyé automatiquement** au terminal (Ingenico / Nayax / PAX selon le point de vente) ; le résultat (**accepté / refusé / annulé / timeout**) revient dans la caisse et met à jour le règlement. Un **refus ou timeout n'ajoute aucun règlement** et laisse le reste dû **inchangé**. Le **n° de transaction TPE** est conservé sur la ligne de règlement.
- **Journalisation** — Chaque moyen de paiement et son montant sont **journalisés** sur la vente (US-L2-06).

### 4.6 Ticket, seuil d'impression & appairage support
- **Seuil d'impression (décision actée)** — L'impression du ticket se déclenche **automatiquement au-dessus d'un montant paramétrable** ; **en dessous**, elle reste possible **à la demande**, sinon proposition de renvoi **e-mail/SMS** (US-L2-08, cahier M2-§8). ⚠ HYPOTHÈSE : valeur par défaut du seuil = **0 €** (cahier M2-§8, question ouverte « défaut 0 € »), c.-à-d. impression systématique tant que non paramétré ; défaut à **confirmer**.
- **Renvoi** — Le ticket peut être **renvoyé par e-mail et/ou SMS** au client rattaché (US-L2-08), et **dupliqué** ; renvoi et duplicata sont **tracés** (cahier M2-§7).
- **RG-M2-04 (volet support)** — L'émission d'un billet/abonnement **génère son support** (carte / QR / bracelet / wallet) et **peut ouvrir la popup d'appairage RFID**. À la validation, le support est **appairé aux droits vendus** et devient **actif** ; un **échec d'appairage bloque la remise** du support et est **journalisé** (US-L2-08, cahier M2-04). L'appairage physique et la consommation relèvent du module **Accès** (L3).

### 4.7 Remboursement, avoir & annulation
- **RG-M2-07** — **Remboursement / avoir / annulation** exigent un **droit spécifique** (contrôle du profil) et sont **tracés** : horodatage, **motif**, opérateur, rattachement à la **vente d'origine** (US-L2-09).
- **Aucune suppression (contre-passation)** — **Aucune ligne d'origine n'est supprimée** : les corrections se font **uniquement par contre-passation** (mouvements ajoutés en négatif). Cohérent NF525/US-L2-11.
- **Remboursement en ligne = aucun automatique (décision actée)** — Tout remboursement passe par une **demande / formulaire** ; il n'existe **aucun remboursement automatique** déclenché par la caisse.
- **Annulation → avoir (décision actée)** — Une **annulation** génère un **avoir** (et non un rendu d'espèces automatique). Une **annulation après impression** (autorisée avec droit) génère un **avoir** **et invalide le billet/support** émis côté **Accès** (le billet composté est dévalidé) (US-L2-09, cahier M2-§8).

### 4.8 Conformité NF525
- **Inaltérabilité & chaînage (US-L2-11)** — Chaque **ticket / opération** validée porte une **signature** et est **chaîné au précédent**, de sorte qu'une **rupture soit détectable**. **Aucune modification ni suppression** d'une opération validée n'est possible ; toute correction passe par **contre-passation** (RG-M2-07).
- **Clôtures & journal** — Clôtures périodiques (**Z**, mensuelle, annuelle) et **journal inaltérable** disponibles à l'archivage ; un **test de rupture de chaîne** remonte une **alerte de contrôle** (US-L2-11). S'appuie sur le journal d'audit append-only du socle (`RG-SOCLE-07`) qu'il **renforce** (signature + chaînage propres à l'encaissement).
- ⚠ HYPOTHÈSE — **À CONFIRMER (NF525)** : les sources posent l'exigence (inaltérabilité, chaînage, signature, clôtures, journal) sans fixer le **procédé cryptographique** (algorithme de signature, forme du chaînage/hash du ticket N à partir de N-1), le **périmètre de certification** (éditeur auto-attestation vs certificat LNE/INFOCERT), ni la **conservation légale** (durée, format d'export fiscal, archivage probant). À **arbitrer avec M6** et un référent conformité **avant implémentation**.

### 4.9 Mode dégradé hors-ligne
- **RG-M2-08** — La vente fonctionne en **mode dégradé hors-ligne** : en perte de réseau la caisse **bascule** et **poursuit les ventes localement** ; au retour du réseau les tickets se **synchronisent** (RG-M2-08).
- **Conformité maintenue hors-ligne (US-L2-12)** — Les opérations hors-ligne restent **chaînées et inaltérables** (NF525 maintenu localement).
- **Synchronisation sans doublon (US-L2-12)** — Au retour réseau, la synchro **remonte les ventes sans doublon ni perte** ; le cahier précise « **une seule remontée par session** » (RG-M2-08). Un **indicateur d'état** (en ligne / dégradé / synchro en cours) est **visible en permanence** (US-L2-12).
- ⚠ HYPOTHÈSE : la mécanique d'idempotence de la remontée (clé d'idempotence par ticket, réconciliation du chaînage entre poste local et serveur, gestion des conflits de stock découverts à la resynchro) n'est pas détaillée dans les sources ; **à préciser au plan technique**.

## 5. Objets de données
Les types PHP sont indicatifs (spec = comportement observable). Tout objet est rattaché à un **Établissement** via le socle (`RG-SOCLE-01`) ; identifiants = **UUID** (constitution §3). Les objets M1 (Produit, TypeTarif, Saison, GrilleTarifaire, Promotion, Stock) sont **référencés, non redéfinis** (`spec-offre.md`).

| Objet | Champ | Type | Contraintes | Notes |
|---|---|---|---|---|
| **SessionCaisse** | id | uuid | PK | RG-M2-01 |
| | pointDeVente | ref PointDeVente | requis | conditionne imprimante & TPE |
| | caisse | ref Caisse | requis | 1 seule session active / point de vente |
| | régisseur | ref Utilisateur (socle) | requis, code validé | RG-M2-01 |
| | opérateur | ref Utilisateur (socle) | requis | agent ayant ouvert |
| | fondDeCaisse | decimal ≥ 0 | requis à l'ouverture | US-L2-01 |
| | état | enum {ouverte, en_fermeture, sécurisée} | défaut = ouverte | cahier M2-01 |
| | ouvertureLe, fermetureLe | datetime | fermeture ≥ ouverture | — |
| **PointDeVente** | id, libellé | uuid, string | requis | rattaché à Établissement/Espace (socle) |
| | imprimante, tpe[], favoris[] | config | — | paramétrage Admin (`caisse × gerer`) |
| | seuilImpression | decimal ≥ 0 | défaut ⚠ 0 € (à confirmer) | décision actée seuil |
| | moyensAutorisés | set MoyenPaiement | filtré par acte de régie (M6) | RG-M2-02 |
| **Caisse** | id, libellé, état | uuid, string, enum | états 🟢/🟡/🔒 | rouverture si sécurisée → code régisseur |
| **Vente / Ticket** (Commande) | id, numéro | uuid, string | numéro séquentiel par session | US-L2-01 ; « Commande » au cahier |
| | date | datetime | requise | — |
| | client | ref Client (M4)? | optionnel | vente anonyme possible (US-L2-05) |
| | statut | enum {en_cours, validée/payée, livrée/facturée, annulée, avoir} | défaut = en_cours | cahier M2-§6 |
| | total, totalRemises, resteÀPayer | decimal | resteÀPayer = total − Σ paiements | RG-M2-03 |
| | signatureNF525, hashPrécédent | string | chaînage inaltérable | US-L2-11 |
| | origineHorsLigne | bool | true si créée en mode dégradé | RG-M2-08 |
| **LigneVente** (LigneCommande) | id | uuid | PK | — |
| | produit | ref Produit (M1) | requis | RG-M1-01 |
| | typeTarif, saison | ref (M1) | requis | prix résolu par grille M1 |
| | quantité | int ≥ 1 | min 1 | US-L2-03 |
| | prixUnitaire | decimal | issu de la grille ; forçable si droit | `vente × forcer_prix` |
| | bénéficiaire | ref Client/Personne? | requis si produit nominatif | RG-M2-04, US-L2-03 |
| | remiseLigne, note | decimal/%, texte | optionnels | cahier M2-02/03 |
| | promotionsAppliquées | ref Promotion[] (M1) | auto, visibles | US-L2-03 |
| **Paiement** | id | uuid | PK | — |
| | moyen | ref MoyenPaiement | requis, ∈ moyens autorisés | RG-M2-02 |
| | montant | decimal > 0 | — | RG-M2-03 |
| | rendu | decimal ≥ 0 | > 0 seulement si moyen = espèces | RG-M2-05 |
| | réfTPE | string? | requis si CB/TPE | US-L2-07 |
| | banque, numéroChèque | string? | si chèque | cahier M2-04 |
| | statutTPE | enum {accepté, refusé, annulé, timeout}? | refus/timeout → non enregistré | US-L2-07 |
| | différé | bool | true = justificatif non acquitté | RG-M2-03 |
| **MoyenPaiement** | code, libellé | ref M6 | référentiel **M6**, non redéfini | Espèces, CB, Chèque, Virement, Chèques Vacances/Culture/Loisirs, PMV, Avoir, Différé |
| | autoriseRendu | bool | true pour espèces uniquement | RG-M2-05 |
| **Avoir** | id, numéro | uuid, string | PK | RG-M2-07, décision actée |
| | venteOrigine | ref Vente | requis | traçabilité |
| | montant | decimal > 0 | — | issu d'annulation/remboursement |
| | motif, auteur, dateHeure | texte, ref Utilisateur, datetime | requis | RG-M2-07 |
| | supportInvalidé | bool | true si annulation après impression | dévalidation Accès (US-L2-09) |
| **Billet / Support** | id, type | uuid, enum {billet, carte, QR, bracelet, wallet} | — | cahier M2-§4 |
| | identifiantSupport | string (QR/RFID/wallet) | — | appairage module Accès (L3) |
| | nbCompostages, sousRéseau | int, ref | hérités du produit M1 | RG-M1-04/13 |
| | statutAppairage | enum {en_attente, actif, échec, invalidé} | échec → remise bloquée | RG-M2-04, US-L2-08 |
| **MouvementCaisse** | id, type | uuid, enum {entrée, sortie} | — | cahier M2-§4 |
| | montant, motif | decimal, texte | requis | US-L2-10 |
| | session | ref SessionCaisse | requis | `caisse × mouvement` |
| **ClotureZ** | id | uuid | PK | RG-M2-06 |
| | session | ref SessionCaisse | 1-1, fige la session | irréversible |
| | comptages[] | (moyen, théorique, compté, écart) | par moyen de paiement | US-L2-10 |
| | totalVentes, totalRemboursements | decimal | — | US-L2-10 |
| | versement, fondReporté | decimal | selon paramétrage | US-L2-10 |
| | horodatage | datetime | requis | — |
| | étatDeRégie | document archivé | ré-imprimable, exportable | RG-M2-06 |
| **Chaînage NF525** | id, venteRef | uuid, ref Vente | append-only | US-L2-11 |
| | numéroSéquence | int | strictement croissant, sans trou | rupture détectable |
| | empreinte (hash) | string | calculée à partir de l'opération + empreinte précédente | ⚠ procédé à confirmer |
| | signature | string | scelle l'opération | inaltérable, non modifiable |
| | typeClôture | enum {Z, mensuelle, annuelle}? | pour clôtures périodiques | US-L2-11 |

## 6. Critères d'acceptation
- **CA-1 (US-L2-01, RG-M2-01)** — *Étant donné* un point de vente sans session ouverte, *quand* l'agent tente de vendre, *alors* l'action est bloquée par un message ; *quand* il ouvre une session en saisissant point de vente, fond de caisse, régisseur et code valide, *alors* la session mémorise ces informations + date/heure, et une **seule** session active existe par point de vente.
- **CA-2 (US-L2-01)** — *Étant donné* une caisse **sécurisée** (post-Z), *quand* on tente de la rouvrir, *alors* le **code régisseur** est exigé.
- **CA-3 (US-L2-02)** — *Étant donné* l'écran de caisse, *quand* l'agent navigue par rayons/favoris ou recherche par libellé/code/code-barres, *alors* les résultats sont instantanés, le panier affiche lignes + total en temps réel, une ligne est modifiable/supprimable, et **vider le panier** demande confirmation.
- **CA-4 (US-L2-03)** — *Étant donné* un produit, *quand* l'agent l'ajoute avec quantité (≥ 1), tarif et éventuel bénéficiaire, *alors* le prix provient de la **grille M1** (tarif × saison du jour), les **promotions éligibles s'appliquent automatiquement** et sont visibles sur la ligne, et le total recalcule immédiatement remises comprises.
- **CA-5 (US-L2-03, RG-M2-04)** — *Étant donné* un produit à **bénéficiaire requis**, *quand* l'agent tente de l'ajouter sans bénéficiaire, *alors* l'ajout est refusé tant que le bénéficiaire n'est pas saisi.
- **CA-6 (US-L2-04, RG-M2-04)** — *Étant donné* un produit **géré en stock à 0**, *quand* il s'affiche, *alors* il est grisé et non ajoutable ; une tentative d'ajout affiche « stock épuisé » ; un produit **non géré en stock** n'est jamais bloqué.
- **CA-7 (US-L2-05)** — *Étant donné* une vente, *quand* l'agent recherche un client (nom/e-mail/n° compte) et le rattache (ou le crée rapidement), *alors* le client apparaît en en-tête et sur le ticket ; *quand* aucun client n'est rattaché, *alors* la vente comptoir anonyme reste possible.
- **CA-8 (US-L2-06, RG-M2-03)** — *Étant donné* un panier dû, *quand* l'agent ajoute plusieurs règlements (espèces, CB, chèque, chèque-vacances…), *alors* le **reste à payer** décroît, la vente n'est **validable qu'à reste = 0** (sauf paiement différé autorisé), et chaque moyen + montant est journalisé.
- **CA-9 (US-L2-06, RG-M2-05)** — *Étant donné* un paiement en **espèces** supérieur au dû, *alors* un **rendu de monnaie** est calculé ; *quand* le moyen est CB/chèque/chèque-vacances, *alors* **aucun rendu** n'est proposé.
- **CA-10 (US-L2-07)** — *Étant donné* un règlement CB, *quand* l'agent le déclenche, *alors* le montant part **automatiquement** au TPE (Ingenico/Nayax/PAX) ; un résultat **accepté** crée la ligne avec le n° de transaction ; un **refus/timeout** n'ajoute **aucun** règlement et laisse le reste dû inchangé.
- **CA-11 (US-L2-08, décision actée seuil)** — *Étant donné* un seuil d'impression paramétré, *quand* le total **dépasse** le seuil, *alors* le ticket **s'imprime automatiquement** ; **en dessous**, l'impression reste possible à la demande et le renvoi **e-mail/SMS** est proposé au client rattaché.
- **CA-12 (US-L2-08, RG-M2-04)** — *Étant donné* la vente d'un billet/abonnement, *quand* elle est validée, *alors* le **support** est appairé aux droits et devient actif ; un **échec d'appairage** bloque la remise du support et est journalisé.
- **CA-13 (US-L2-09, RG-M2-07, décisions actées)** — *Étant donné* une vente validée, *quand* un opérateur **habilité** l'annule/rembourse/émet un avoir, *alors* l'opération est horodatée, motivée, rattachée à l'opérateur et à la vente d'origine, **aucune ligne d'origine n'est supprimée** (contre-passation), l'annulation génère un **avoir** ; *quand* l'annulation intervient **après impression**, *alors* le billet/support est **invalidé** côté Accès. Un opérateur **non habilité** est refusé.
- **CA-14 (US-L2-10, RG-M2-06)** — *Étant donné* une session, *quand* le régisseur lance la **clôture Z**, *alors* le Z totalise ventes / moyens de paiement / remboursements-avoirs, calcule l'**écart** théorique vs compté, produit un **état de régie** archivé et ré-imprimable, **fige** la session (plus aucune vente), le fond étant reporté/repris selon paramétrage ; la clôture est **refusée** si des paiements sont incohérents.
- **CA-15 (US-L2-11, NF525)** — *Étant donné* des opérations validées, *quand* on les consulte, *alors* chacune porte une **signature** et un **chaînage** au précédent ; *quand* on tente de modifier/supprimer une opération validée, *alors* c'est **impossible** (contre-passation uniquement) ; *quand* la **chaîne est rompue**, *alors* une **alerte de contrôle** remonte.
- **CA-16 (US-L2-12, RG-M2-08)** — *Étant donné* une perte de réseau, *quand* l'agent continue de vendre, *alors* la caisse passe en **mode dégradé**, les ventes locales restent **chaînées/inaltérables**, un **indicateur d'état** est visible en permanence ; *quand* le réseau revient, *alors* la synchro remonte les ventes **sans doublon ni perte** (une seule remontée par session).

## 7. Cas limites
- **Vente hors session** — Bloquée tant qu'aucune session n'est ouverte (RG-M2-01, CA-1).
- **Deuxième session sur le même point de vente** — Refusée : une seule session active à la fois (US-L2-01).
- **Réouverture d'une caisse sécurisée** — Exige le code régisseur (cahier M2-01).
- **Fond insuffisant / gros retrait d'espèces** — Mouvement de caisse dédié + **alerte au régisseur** (cahier M2-§8).
- **Bénéficiaire ≠ payeur** — Billet nominatif au bénéficiaire, paiement au payeur (lien M4) (cahier M2-§8).
- **Refus / timeout TPE** — Aucun règlement ajouté, reste dû inchangé, nouvelle tentative possible (US-L2-07).
- **Paiement différé autorisé** — Validation possible avec reste dû > 0 et **justificatif non acquitté** (RG-M2-03).
- **Tentative de rendu sur non-espèces** — Interdite ; rendu seulement sur espèces (RG-M2-05).
- **Seuil d'impression à 0 €** — Impression systématique tant que non paramétré ⚠ (défaut à confirmer, cahier M2-§8).
- **Échec d'appairage support** — Support non remis, opération journalisée ; ⚠ HYPOTHÈSE : conduite à tenir (ré-essai, remise d'un support de secours, ticket seul) non spécifiée → à préciser avec module Accès.
- **Annulation après impression** — Avoir + billet invalidé côté Accès ; nécessite le droit (RG-M2-07, cahier M2-§8).
- **Aucune suppression d'opération** — Toute correction passe par contre-passation (NF525, US-L2-09/11).
- **Clôture avec paiements incohérents** — Refusée (cahier M2-§7).
- **Rupture de chaîne NF525 détectée** — Alerte de contrôle remontée ; ⚠ HYPOTHÈSE : procédure de remédiation (blocage de la caisse ? journal d'incident ?) non spécifiée.
- **Resynchronisation hors-ligne** — Sans doublon ni perte, une seule remontée par session ; ⚠ conflit de stock découvert au retour réseau (produit épuisé entre-temps) non tranché (RG-M2-08, lien M1/M3).
- **Stock partagé (pool M1)** — Décrément mutualisé, risque de survente concurrente inter-caisses/en-ligne à arbitrer (RG-M1-10).
- **Utilisateur sans affectation sur l'établissement du point de vente** — Aucun accès (socle, `RG-SOCLE-05`).

## 8. Dépendances
- **Dépend de : socle L0** (`specs/L0-socle/spec-socle.md`) — hiérarchie Groupe/Région/Établissement/Espace (`RG-SOCLE-01`) à laquelle se rattachent points de vente, caisses et ventes ; permissions `module × action` réutilisées sur les modules **`vente`** et **`caisse`** (`RG-SOCLE-02/03/04`) ; cadrage par établissement actif (`RG-SOCLE-05`) ; identité & code opérateur/régisseur (`RG-SOCLE-06`) ; **journal d'audit append-only** (`RG-SOCLE-07`) que la couche NF525 renforce (signature + chaînage).
- **Dépend de : M1 · Offre & Tarification** (L1, `specs/L1-offre/spec-offre.md`) — consomme le **prix résolu** (produit × type de tarif × saison + QF, RG-M1-01), les **canaux** (visibilité guichet, RG-M1-07), les **promotions** (RG-M1-04), le **stock** (RG-M1-10) et les paramètres de **support/compostage** (RG-M1-04/13). M2 **décrémente** stocks/compostages à la vente ; ne redéfinit aucun de ces objets.
- **Interagit avec (hors périmètre L2) :**
  - **M6 · Compta & Régie** — fournit le **référentiel des moyens de paiement** et l'**acte de régie** qui les filtre (RG-M2-02) ; reçoit les recettes ; porte la **PCA/TVA** et la **conformité NF525/fiscale** (procédé de signature, e-reporting, archivage probant) — **arbitrages NF525 à mener avec M6**.
  - **M4 · CRM** — recherche/création/rattachement du **client** et du **bénéficiaire** (US-L2-05), lien payeur/bénéficiaire, mandats SEPA.
  - **Module Accès** (L3) — **appairage** physique du support, **activation** des droits, **dévalidation** du billet lors d'une annulation après impression (US-L2-08/09).
  - **M5 · Planning & Réservation** — décompte réel des séances/quotas des droits vendus.
  - **M3 · Boutique & App client** — **Commande partagée** ; encaissement à distance (Click & Pay) hors périmètre guichet L2.
  - **Connecteurs TPE** (Ingenico / Nayax / PAX) et périphériques (imprimante ticket, lecteur RFID) — pilotés selon le point de vente (US-L2-07, RG-M2-04).

---

## Points ouverts / hypothèses (récapitulatif)
1. **⚠ NF525 — À CONFIRMER (priorité haute)** : procédé de signature et forme du **chaînage/hash** des opérations, **périmètre de certification** (auto-attestation éditeur vs certificat LNE/INFOCERT), **conservation légale** (durée, format d'export fiscal, archivage probant), déclenchement et périmètre des **clôtures mensuelle/annuelle** et du journal inaltérable. À arbitrer avec **M6** + référent conformité avant implémentation (§4.8, §4.1).
2. **⚠ HYPOTHÈSE — Noms des permissions** `vente × encaisser / rembourser / annuler / forcer_prix`, `caisse × ouvrir / cloturer / mouvement / gerer` : dérivés du tableau Acteurs & droits selon le modèle socle, à figer avec **M8** (§3).
3. **⚠ HYPOTHÈSE — Rôles Régisseur vs Responsable** : le cahier confie le remboursement au Responsable, le backlog au Régisseur ; la spec le rattache à `vente × rembourser` attribuable par paramétrage. **Arbitrage M8** (§3).
4. **⚠ HYPOTHÈSE — Valeur par défaut du seuil d'impression** (0 € = impression systématique ?) à confirmer (§4.6, décision actée « seuil paramétrable »).
5. **⚠ HYPOTHÈSE — Conduite en cas d'échec d'appairage support** (ré-essai / support de secours / ticket seul) à préciser avec le **module Accès** (§4.6, cas limites).
6. **⚠ HYPOTHÈSE — Idempotence & conflits de la resynchronisation hors-ligne** : clé d'idempotence par ticket, réconciliation du chaînage local↔serveur, gestion d'un **conflit de stock** découvert au retour réseau (lien M1/M3) non détaillés (§4.9, cas limites).
7. **⚠ HYPOTHÈSE — Décrément du stock partagé (pool M1)** et survente concurrente inter-caisses / avec la vente en ligne (M3) : instant du décrément et verrouillage non spécifiés (§4.4, hérité d'un point ouvert L1).
8. **⚠ HYPOTHÈSE — Click & Pay** (encaissement à distance figurant sur l'écran M2-02) : rattaché à **M3**, non couvert par une US-L2, exclu du périmètre L2 (§2).
