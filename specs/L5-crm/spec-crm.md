# Spec — CRM noyau (`M4` / lot `L5`)

- **Lot / module :** L5 · M4 CRM noyau
- **Stories couvertes :** US-L5-01 à US-L5-10
- **Règles de gestion :** RG-M4-01 à RG-M4-10
- **Statut :** brouillon

## 1. Objectif
Constituer une **fiche client unique**, enrichie automatiquement à chaque transaction (vente,
passage, réservation), socle des **familles** (payeur ≠ bénéficiaire), du **porte-monnaie virtuel**
prépayé (PMV) utilisable comme moyen de paiement en caisse, et de la conformité **RGPD** (consentement
par canal, droit à l'effacement) — avec **zéro re-saisie** pour les agents (RG-M4-01).

## 2. Périmètre

- **Inclus (couvert par US-L5-01 à 10) :**
  - Recherche & liste des clients, filtres simples (actif/inactif, avec PMV, mineur/majeur) — US-L5-01.
  - Fiche client 360° (physique/morale), enrichie automatiquement par M2 et les autres modules — US-L5-02, RG-M4-01.
  - Gestion de la famille : payeur ≠ bénéficiaire, autorisations par bénéficiaire — US-L5-03, RG-M4-02.
  - Porte-monnaie virtuel (PMV) : recharge, solde, échéance, relevé de mouvements — US-L5-04, RG-M4-03.
  - PMV comme moyen de paiement en caisse M2, solde négatif interdit, réversibilité sur annulation — US-L5-05, RG-M4-03.
  - Recharge d'un PMV expiré, paramétrable par établissement — US-L5-06, RG-M4-04.
  - Traitement du solde résiduel à l'expiration, paramétrable par établissement — US-L5-07.
  - Fusion de doublons (clients et familles) tracée et réversible — US-L5-08, RG-M4-06.
  - Consentements RGPD par canal, droit à l'effacement/anonymisation — US-L5-09, RG-M4-07/08/09.
  - Passage à la majorité : renouvellement du consentement, bascule des autorisations parentales — US-L5-10, RG-M4-10.

- **Exclu (pour l'instant) :**
  - **Segments dynamiques, campagnes email/SMS/push, fidélité & parrainage** (écran M4-04, RG-M4-05) :
    décrits dans le cahier détaillé comme dans le périmètre de M4, mais **aucune `US-L5` ne les couvre**
    dans ce lot. ⚠ HYPOTHÈSE : reportés à un lot ultérieur ; RG-M4-05 est **rappelée** pour cohérence
    documentaire mais **non implémentée** ni testée par ce lot. La seule exigence retenue *dès ce lot*
    est le filtrage par consentement (RG-M4-07), déjà couvert côté export/recherche (US-L5-01) et
    consentements (US-L5-09).
  - L'**acte de vente** (panier, encaissement, TPE, ticket) → **M2** (`spec-vente.md`) ; M4 **fournit**
    le client/bénéficiaire et le PMV comme moyen de paiement, ne redéfinit pas l'encaissement.
  - Les **écritures comptables**, la reconnaissance de revenu et le traitement fiscal du solde PMV
    expiré → **M6** ; M4 **déclenche** l'événement paramétré (conservé/annulé/transformé en produit) et
    l'**expose** pour rapprochement, sans le comptabiliser lui-même.
  - Le **contrôle d'accès physique** (tourniquets, comptage non nominatif bébé/accompagnant) → module
    **Accès** (L3, `spec-acces.md`, RG-ACC-03) ; une personne comptée sans support ne nécessite **pas**
    de fiche Client dans M4 (cf. §7 Cas limites).
  - La **production des titres/cartes physiques** → module billetterie (M2/M3).
  - Le **mandat SEPA** en tant qu'objet métier (RIB, échéancier de prélèvement, échecs de prélèvement)
    est **porté par M1/M6** (facette `sepa` de la Formule d'abonnement, `spec-offre.md` L1 l.93) ; M4
    **référence** le mandat pour l'associer au **payeur** de la famille mais ne le définit pas ici
    (⚠ HYPOTHÈSE, cf. §8 Dépendances — articulation PMV/mandat SEPA à confirmer).

## 3. Acteurs & droits
Les permissions réutilisent le modèle du socle (`RG-SOCLE-02/03/04/05`) : couple `module × action`,
portées par l'**établissement actif** (ou le **groupe** pour un Client partagé — ⚠ HYPOTHÈSE, cf. §5) ;
l'UI **masque** ce qui n'est pas autorisé (`RG-SOCLE-04`). Module de droits : **`crm`**.

| Acteur | Peut | Ne peut pas | Permission (module × action) |
|---|---|---|---|
| **Agent d'accueil / caisse** | Rechercher, consulter, créer une fiche, rattacher un achat, recharger le PMV, gérer la famille au comptoir | Fusionner des doublons, effacer une fiche (RGPD), créer un segment, modifier les paramètres établissement (PMV, conservation) | `crm × lire`, `crm × creer`, `crm × modifier`, `crm × pmv_recharger`, `crm × famille_gerer` |
| **Client final** (espace client M3) | Consulter sa propre fiche, son PMV (solde, échéance, historique), recharger son PMV, gérer ses consentements par canal, demander l'effacement | Consulter/modifier la fiche d'un autre client, fusionner, forcer un solde PMV, consulter l'historique comptable agrégé | `crm × lire_soi`, `crm × pmv_recharger_soi`, `crm × consentement_gerer_soi`, `crm × rgpd_demander` |
| **Gestionnaire marketing** | Construire des segments, créer/suivre des campagnes (hors périmètre lot, cf. §2), consulter les fiches consentantes | Modifier un solde PMV manuellement, fusionner des fiches, accéder à des données non consenties | `crm × lire`, `crm × segment_gerer` |
| **Comptable** | Consulter PMV, soldes, échéances, mouvements ; exporter pour rapprochement M6 | Modifier une fiche, recharger un PMV, gérer segments ou familles | `crm × pmv_lire`, `crm × exporter` |
| **Administrateur** | Fusionner/défusionner des doublons, exécuter les demandes RGPD (effacement/anonymisation), paramétrer les durées de conservation et les règles PMV par établissement | Contourner la traçabilité d'une fusion ou d'un effacement (toujours journalisé, `RG-M4-06`/`RG-SOCLE-07`) | `crm × fusionner`, `crm × rgpd_gerer`, `crm × parametrer` |

## 4. Comportements & règles

- **RG-M4-01** — Toute transaction (vente M2, passage Accès, réservation M5) **enrichit automatiquement**
  la fiche client (historique, agrégats CA/dernière visite), **sans re-saisie manuelle** (US-L5-02).
  - ⚠ HYPOTHÈSE (US-L5-02 cite « RG-M4-14 (règles de complétion/priorité des champs) » — cette référence
    **n'existe pas** dans le cahier détaillé, qui ne définit que `RG-M4-01` à `RG-M4-10`) : on retient la
    règle de priorité suivante — une **valeur déjà saisie manuellement** par un utilisateur sur un champ
    de coordonnées **prévaut** sur une valeur auto-complétée issue d'une transaction ; l'enrichissement
    automatique ne **complète** qu'un champ **vide**, il n'écrase jamais une correction manuelle. À
    confirmer/renommer en `RG-M4-11` lors de la prochaine mise à jour du cahier détaillé.
- **RG-M4-02** — Un achat comporte **un payeur** et **un ou plusieurs bénéficiaires** ; les droits et
  abonnements sont portés par le **bénéficiaire**, jamais par le payeur (US-L5-03 ; cohérent avec
  `RG-M2-04`/cahier M2-§8, décision actée « Bénéficiaire ≠ payeur »). Un client peut cumuler les deux
  rôles (payeur **et** bénéficiaire) au sein d'une même famille.
- **RG-M4-03** — Le **PMV** est un solde prépayé avec **échéance**, utilisable comme moyen de paiement
  en M2 (référentiel `MoyenPaiement` M6, cf. `spec-vente.md` l.145) ; le **solde négatif est interdit**
  (US-L5-04/05). Un débit qui dépasserait le solde disponible est **refusé** ; un paiement partiel PMV +
  complément par un autre moyen reste possible (US-L5-05). L'annulation de la vente correspondante
  **re-crédite** intégralement le PMV.
- **RG-M4-04** — Un **PMV expiré** n'est plus proposé comme moyen de paiement en caisse (US-L5-05). Une
  recharge peut le réactiver selon les règles d'échéance **paramétrées par établissement** : recharge
  autorisée (avec nouvelle échéance calculée) ou interdite (caisse bloque avec motif affiché) — décision
  actée « Recharge d'un PMV expiré » (US-L5-06). Le comportement retenu est journalisé sur le mouvement.
  L'échéance est le dernier jour utilisable, au jour de l'établissement du client : dès le lendemain, le
  débit est refusé, sans attendre la tâche quotidienne qui passe le statut à « expiré » (08/10/2026).
- **RG-M4-05** — Un **segment dynamique** est réévalué en continu ; l'entrée/sortie d'un client est
  automatique selon ses critères. *(Non implémentée dans ce lot, cf. §2 Exclu — rappelée pour cohérence
  avec le cahier détaillé.)*
- **RG-M4-06** — La **fusion de doublons** (clients ou familles) est intégralement **tracée** (qui, quand,
  quoi) et **réversible** ; la défusion restaure les fiches d'origine à l'identique (US-L5-08).
  - **Décision actée — fusion de deux familles** : le **payeur principal choisi** est conservé, les
    **PMV sont cumulés** (somme des soldes ; l'échéance retenue est la **plus tardive** des deux —
    ⚠ HYPOTHÈSE, non explicitement tranchée par le cahier), et les **bénéficiaires sont dédupliqués** ;
    l'opération reste réversible comme toute fusion.
- **RG-M4-07** — RGPD : le **consentement est géré par canal** (email, SMS, courrier) ; une communication
  n'est envoyée que si le consentement du canal est **valide et non expiré** (US-L5-09).
- **RG-M4-08** — Des **durées de conservation** sont appliquées par **catégorie de données** ; à
  échéance, la donnée est **purgée** ou **anonymisée** selon le paramétrage (US-L5-09, en filigrane).
- **RG-M4-09** — Le **droit à l'effacement** s'exerce par suppression, ou par **anonymisation** lorsqu'un
  historique doit être conservé (comptable, statistique) — décision actée : les données nominatives sont
  supprimées, l'historique agrégé est conservé pour la comptabilité et les statistiques (US-L5-09).
- **RG-M4-10** — Les **données de mineurs** font l'objet d'une vigilance renforcée : consentement du
  représentant légal et restriction des usages marketing (US-L5-03, US-L5-10).
  - **Décision actée — passage à la majorité** : à la date de majorité, les consentements portés par le
    représentant légal passent en état **« à renouveler »** ; une relance de renouvellement est déclenchée
    sur les canaux autorisés ; tant que le renouvellement n'est pas obtenu, les **envois non essentiels
    sont suspendus** ; les **autorisations parentales** (récupérer un mineur, etc.) basculent en cohérence
    (US-L5-10).
- **Décision actée — solde résiduel à l'expiration du PMV** (US-L5-07) — paramétrable par établissement :
  **conservé**, **annulé**, ou **passé en produit** (transformation comptable) ; le traitement s'exécute
  automatiquement à la date d'échéance, génère un mouvement daté/motivé/exportable, et un solde annulé
  **reste consultable** dans l'historique (jamais supprimé).

## 5. Objets de données

| Objet | Champ | Type | Contraintes | Notes |
|---|---|---|---|---|
| **Client** | id | UUID | — | RG-SOCLE (identifiant technique) |
| | type | enum {physique, morale} | requis | conditionne les champs d'identité (M4-02) |
| | civilite, nom, prenom | string | requis si physique | — |
| | raisonSociale, siret | string | requis si morale | — |
| | dateNaissance | date | optionnel | déclenche `estMineur` et `RG-M4-10` si < 18 ans |
| | email, telephone, adresse | string | optionnel, ≥ 1 requis pour toute **communication** | pas requis pour l'existence de la fiche (vente anonyme possible côté M2) |
| | statut | enum {actif, inactif, archive, anonymise} | requis | cycle de vie §6 cahier M4 |
| | dateCreation, creePar, dateMaj, majPar | datetime/ref Utilisateur | audit | cf. `RG-SOCLE-07` |
| | scopeRattachement | ref Groupe ou Établissement | ⚠ HYPOTHÈSE : **Groupe** par défaut (fiche partagée entre établissements d'un même groupe pour éviter les doublons multi-sites), établissement de création tracé | à confirmer au plan technique avec `RG-SOCLE-01` |
| **Famille** (Foyer) | id | UUID | — | — |
| | libelle | string | optionnel | ex. nom de famille |
| | payeurPrincipalId | ref Client | requis | utilisé en cas de fusion de familles |
| | membres | liste de `Beneficiaire` | 1..n | — |
| | statut | enum {active, fusionnee, dissoute} | requis | — |
| | dateCreation | datetime | — | — |
| **Beneficiaire** (rattachement Client↔Famille) | id | UUID | — | table de jointure enrichie |
| | familleId, clientId | ref | requis | un même client actif dans **une seule famille active** à la fois (alerte sinon, US-L5-03) |
| | role | enum {payeur, beneficiaire, payeur_et_beneficiaire} | requis | RG-M4-02 |
| | autorisations | set {recharger_pmv, acheter_pour_famille, recuperer_mineur, entree_seule, activite_encadree} | optionnel | portées par bénéficiaire (M4-03) |
| | dateAjout, dateRetrait | datetime | ajout/retrait **tracé et réversible** | US-L5-03 |
| **PorteMonnaieVirtuel (PMV)** | id | UUID | — | 1:1 avec Client |
| | clientId | ref Client | requis, unique | — |
| | solde | decimal(10,2) | **≥ 0** (invariant, RG-M4-03) | jamais négatif |
| | devise | string | EUR | — |
| | dateEcheance | date | requis si `statut = actif` | calculée selon paramètre établissement |
| | statut | enum {actif, expire} | requis | flux §6 cahier M4 |
| **MouvementPmv** | id | UUID | — | append-only, journal de preuve |
| | pmvId | ref PMV | requis | — |
| | type | enum {recharge, debit_vente, remboursement_vente, expiration, ajustement} | requis | — |
| | montant | decimal(10,2) | signé selon type | — |
| | soldeApres | decimal(10,2) | snapshot | pour audit |
| | dateMouvement | datetime | requis | — |
| | canal | enum {caisse, en_ligne, autre} | requis pour recharge | US-L5-04 |
| | refVenteM2 | ref Vente (M2) | optionnel | lien débit/remboursement ↔ vente |
| | utilisateur | ref Utilisateur | requis | RG-SOCLE-07 |
| | motif | string | requis si `ajustement`/`expiration` | — |
| **ParametrePmvEtablissement** | etablissementId | ref | requis | portée établissement (`RG-SOCLE-01`) |
| | rechargeExpireeAutorisee | bool | requis | US-L5-06, décision actée |
| | regleEcheance | règle (durée, jour fixe…) | requis | calcul de `dateEcheance` |
| | traitementSoldeResiduel | enum {conserve, annule, transforme_en_produit} | requis | US-L5-07, décision actée |
| **Consentement** | id | UUID | — | append-only + état courant |
| | clientId | ref Client | requis | — |
| | canal | enum {email, sms, courrier} | requis | RG-M4-07 |
| | etat | enum {accorde, refuse, a_renouveler, expire} | requis | `a_renouveler` = passage à la majorité (US-L5-10) |
| | dateRecueil, dateExpiration | date | — | — |
| | source | string | requis | caisse, tunnel en ligne, formulaire papier… |
| | recueilliParRepresentant | bool | requis si `estMineur` | RG-M4-10 |
| **DemandeRGPD** | id | UUID | — | droit à l'effacement (US-L5-09) |
| | clientId | ref Client | requis | — |
| | type | enum {effacement, anonymisation} | requis | RG-M4-09 |
| | statut | enum {recue, en_cours, realisee, refusee} | requis | — |
| | dateDemande, dateTraitement, traitePar | datetime/ref | requis pour `realisee` | RG-SOCLE-07 |
| **RegleConservation** | categorieDonnee | string | requis | RG-M4-08 |
| | dureeMois | int | requis | — |
| | actionEcheance | enum {purge, anonymisation} | requis | — |
| **JournalFusion** | id | UUID | — | RG-M4-06 |
| | portee | enum {client, famille} | requis | fusion de fiches ou de familles |
| | fichesSources | liste de ref (Client ou Famille) | ≥ 2 | — |
| | ficheSurvivanteId | ref | requis | fiche/famille maître |
| | champsArbitres | map(champ → valeur retenue) | requis | prévisualisation avant validation (US-L5-08) |
| | motif | string | optionnel | consigné (M4-05) |
| | effectuePar, dateFusion | ref/datetime | requis | RG-SOCLE-07 |
| | statut | enum {active, defusionnee} | requis | réversibilité |
| | dateDefusion, defusionnePar | datetime/ref | requis si `defusionnee` | — |
| **MandatSepa** *(référencé, non redéfini)* | — | — | porté par `Formule` (M1, `spec-offre.md`) | M4 associe le mandat au **payeur** de la famille ; cf. §8 Dépendances |

## 6. Critères d'acceptation

- **CA-1 (US-L5-01)** — *Étant donné* un nom, e-mail, téléphone ou n° de carte saisi dans la recherche
  clients, *quand* l'agent lance la recherche, *alors* les résultats (tolérants à la casse et aux accents)
  s'affichent paginés en moins de 2 s pour une recherche par n° de carte.
- **CA-2 (US-L5-01)** — *Étant donné* des filtres par segment (actif/inactif, avec PMV, mineur/majeur),
  *quand* l'agent les combine, *alors* la liste se réduit en conséquence et le compteur de résultats
  s'actualise ; aucune fiche candidate à fusion n'est masquée.
- **CA-3 (US-L5-02, RG-M4-01)** — *Étant donné* un client rattaché à une vente M2, *quand* l'encaissement
  est validé, *alors* son historique d'achats et ses agrégats (CA, dernière visite) se mettent à jour
  **sans re-saisie**, et la modification est horodatée et attribuée à l'utilisateur/au flux d'origine.
- **CA-4 (US-L5-02)** — *Étant donné* une fiche client, *quand* l'agent l'ouvre, *alors* les blocs
  Coordonnées, Historique, PMV, Famille et Consentements RGPD sont visibles sur un **seul écran**.
- **CA-5 (US-L5-03, RG-M4-02)** — *Étant donné* une famille avec un parent payeur et un enfant
  bénéficiaire, *quand* le parent règle un abonnement au comptoir, *alors* l'abonnement apparaît sur la
  fiche de l'**enfant bénéficiaire**, jamais sur celle du payeur.
- **CA-6 (US-L5-03)** — *Étant donné* un client déjà membre d'une famille active, *quand* un agent tente
  de le rattacher à une seconde famille active, *alors* une **alerte** est affichée ; l'ajout/retrait d'un
  membre reste **tracé et réversible**.
- **CA-7 (US-L5-04, RG-M4-03)** — *Étant donné* un client avec un PMV, *quand* il recharge (guichet ou
  en ligne), *alors* le solde est crédité, un `MouvementPmv` de type `recharge` est journalisé (montant,
  date, canal), et la nouvelle date d'échéance calculée selon le paramétrage établissement s'affiche sur
  la fiche et le ticket.
- **CA-8 (US-L5-05, RG-M4-03)** — *Étant donné* un PMV actif au solde de 10 €, *quand* l'agent tente un
  paiement de 15 € en caisse, *alors* le débit total est **refusé** ; un paiement partiel de 10 € PMV +
  5 € par un autre moyen reste possible.
- **CA-9 (US-L5-05)** — *Étant donné* une vente réglée en partie par PMV, *quand* elle est annulée,
  *alors* le PMV est **re-crédité** intégralement via un mouvement `remboursement_vente` traçable.
- **CA-10 (US-L5-05, RG-M4-04)** — *Étant donné* un PMV expiré, *quand* l'agent tente de l'utiliser en
  paiement, *alors* il n'apparaît **pas** dans les moyens de paiement proposés par la caisse M2.
- **CA-11 (US-L5-06, RG-M4-04)** — *Étant donné* un établissement paramétré « recharge PMV expiré
  autorisée », *quand* le client recharge un PMV expiré, *alors* le PMV est **réactivé** avec une nouvelle
  échéance calculée ; *étant donné* un établissement paramétré « interdite », *quand* la recharge est
  tentée, *alors* la caisse la **bloque** avec un motif affiché ; dans les deux cas le comportement retenu
  est journalisé sur le mouvement.
- **CA-12 (US-L5-07)** — *Étant donné* un établissement paramétré sur le traitement du solde résiduel,
  *quand* un PMV atteint sa date d'échéance, *alors* le solde est **conservé**, **annulé** ou **transformé
  en produit** selon le paramétrage, un mouvement `expiration` daté et motivé est généré et exportable, et
  un solde annulé **reste visible** dans l'historique.
- **CA-13 (US-L5-08, RG-M4-06)** — *Étant donné* deux fiches candidates à la fusion, *quand* l'utilisateur
  habilité choisit la fiche maître et arbitre champ par champ, *alors* une **prévisualisation** est
  affichée avant validation ; après validation, historiques d'achats, PMV et consentements sont rattachés
  à la fiche maître.
- **CA-14 (US-L5-08, RG-M4-06)** — *Étant donné* une fusion validée, *quand* l'administrateur la défait,
  *alors* les deux fiches d'origine sont **restaurées à l'identique** (y compris PMV et historiques).
- **CA-15 (US-L5-08, décision « fusion de familles »)** — *Étant donné* deux familles avec chacune un
  payeur actif et un bénéficiaire en commun, *quand* elles sont fusionnées, *alors* le **payeur principal
  choisi** est conservé, les **PMV sont cumulés**, et le bénéficiaire commun est **dédupliqué** (une seule
  occurrence dans la famille résultante).
- **CA-16 (US-L5-09, RG-M4-07)** — *Étant donné* un client sans consentement valide pour le canal SMS,
  *quand* une campagne cible ce canal, *alors* le client est **exclu** automatiquement de l'envoi et de
  tout export marketing.
- **CA-17 (US-L5-09, RG-M4-09)** — *Étant donné* une demande d'effacement d'un client ayant un historique
  d'achats, *quand* l'administrateur la traite, *alors* les données **nominatives** sont supprimées, la
  fiche passe en statut `anonymise`, et l'**historique agrégé** (comptable/statistique) est conservé.
- **CA-18 (US-L5-09)** — *Étant donné* un client, *quand* il révoque son consentement sur un canal,
  *alors* l'état passe à `refuse`, l'action est horodatée avec sa source, et l'historique des
  consentements reste conservé à des fins de preuve.
- **CA-19 (US-L5-10, RG-M4-10, décision « passage à la majorité »)** — *Étant donné* un bénéficiaire
  mineur dont le consentement est porté par un représentant légal, *quand* il atteint sa majorité,
  *alors* le consentement passe en état `a_renouveler`, une relance est déclenchée sur les canaux
  autorisés, et les envois **non essentiels sont suspendus** tant que le renouvellement n'est pas obtenu.
- **CA-20 (US-L5-10)** — *Étant donné* un passage à la majorité, *quand* il se produit, *alors* le
  changement d'état et sa cause (« majorité ») sont **journalisés**, et les autorisations parentales
  (ex. « récupérer un mineur ») associées sont désactivées ou réévaluées en conséquence.

## 7. Cas limites

- **Mineur devenant majeur au sein d'une famille active** — les autorisations parentales (récupérer un
  mineur, entrée seule) portées par le représentant deviennent caduques pour ce bénéficiaire ; le client
  devenu majeur peut, une fois son propre consentement recueilli, devenir **payeur** de sa propre fiche
  (RG-M4-10, US-L5-10).
- **Fusion de deux familles avec deux payeurs actifs** — le choix du **payeur principal** est obligatoire
  et manuel (pas d'arbitrage automatique) avant validation de la fusion (décision actée, US-L5-08).
- **Fusion de deux PMV à échéances différentes** — ⚠ HYPOTHÈSE : l'échéance retenue après cumul des
  soldes est la **plus tardive** des deux échéances d'origine (non explicitement tranché par le cahier ;
  point ouvert « Fusion de familles » du cahier détaillé M4 §8) ; à confirmer en atelier produit avant le
  plan technique.
- **Nourrisson / bébé sans support individualisé** — conformément à `RG-ACC-03` (module Accès, décision
  actée « bébés & accompagnants comptés dans la FMI sans droit »), une personne uniquement comptée en
  passage **non nominatif** ne nécessite **pas** de fiche `Client`/`Beneficiaire` dans M4 : elle n'a ni
  QR, ni droit, ni PMV. ⚠ HYPOTHÈSE : la famille peut néanmoins, à titre informatif et optionnel, déclarer
  un membre sans compte propre (pas d'email, pas de consentement marketing, pas de PMV) — ce cas n'est pas
  traité explicitement par le cahier M4, à confirmer.
- **Client sans aucune coordonnée** — la fiche reste valide pour une vente en caisse (M2 autorise la vente
  anonyme, `spec-vente.md` l.124), mais **aucune** communication (email/SMS/campagne) ne peut lui être
  adressée (cahier M4-02 : « au moins un canal de contact requis pour toute communication »).
- **Mineur sans représentant légal identifiable** (tutelle, situation atypique) — ⚠ HYPOTHÈSE : les envois
  marketing restent **suspendus** tant qu'aucun représentant légal valide n'est rattaché à la fiche
  (extension de RG-M4-10, non détaillée par le cahier).
- **Chaîne de fusions (A+B+C)** — les fusions successives s'enchaînent dans le `JournalFusion` ; défaire la
  fusion la plus récente ne restaure que son état immédiatement antérieur (pas de « retour à zéro »
  global) — ⚠ HYPOTHÈSE de comportement, à valider avec le produit.
- **Recharge d'un PMV dont le solde résiduel a déjà été transformé en produit** — une nouvelle recharge
  ouvre un **nouveau cycle** (nouvelle échéance) ; l'ancien mouvement d'expiration reste consultable,
  jamais supprimé (US-L5-07).
- **Export marketing incluant des mineurs** — les mineurs sans consentement valide du représentant légal
  sont **exclus automatiquement** de tout export/segment marketing (RG-M4-07/10).

## 8. Dépendances

- **Dépend de L0 (socle)** — identité/authentification, droits fins `module × action` portés par
  établissement, multi-entités (`RG-SOCLE-01`), journal d'audit non modifiable (`RG-SOCLE-07`),
  hébergement en France (`spec-socle.md`).
- **Dépend de L1 (M1 Offre)** — le **bénéficiaire** porte les abonnements/cartes qui lui sont propres
  (`RG-M1-*`, `spec-offre.md`) ; la facette `sepa` de la `Formule` d'abonnement (bool + jour de
  prélèvement) requiert un **mandat** associé au **payeur** de la famille — ⚠ point ouvert (cf. ci-dessous
  « Articulation PMV/mandat SEPA »).
- **Dépend de L2 (M2 Vente & Caisse)** — M4 **reçoit** l'enrichissement automatique de chaque vente
  (`RG-M2-04`, cahier M2-§8) ; le **PMV** est exposé comme entrée du référentiel `MoyenPaiement` de M2
  (`spec-vente.md` l.145) ; M2 **rattache/crée** le client et le bénéficiaire au moment de la vente
  (US-L2-05) ; toute annulation de vente déclenche le re-crédit PMV (RG-M4-03).
- **Dépend de M6 (Compta & Régie, non encore spécifié dans ce dépôt)** — le traitement comptable du solde
  PMV résiduel à l'expiration (US-L5-07) et le rapprochement des mouvements PMV par le Comptable
  supposent une interface d'export ; ⚠ HYPOTHÈSE : cette interface (écriture exportable, format) sera
  précisée lors de la spec M6, non encore rédigée à ce jour dans le dépôt.
- **Référencé, non redéfini** — Module **Accès** (L3, `spec-acces.md`) : comptage non nominatif
  (`RG-ACC-03`) des personnes sans support, hors périmètre fiche Client de M4 (cf. §7 Cas limites).

### Points ouverts (à trancher en atelier produit avant le plan technique)
1. **Portée du Client** (Groupe vs Établissement) — ⚠ HYPOTHÈSE retenue : Groupe, à confirmer avec
   `RG-SOCLE-01`.
2. **Échéance PMV après fusion de familles** — ⚠ HYPOTHÈSE retenue : la plus tardive des deux.
3. **Articulation PMV ↔ mandat SEPA** — le mandat SEPA (RIB, prélèvement) est porté par M1/M6 et rattaché
   au **payeur** ; le PMV est un solde prépayé distinct, alimenté par recharge (caisse/en ligne) et **non**
   par prélèvement SEPA automatique dans le périmètre décrit par le cahier M4. ⚠ HYPOTHÈSE : aucune
   US-L5 ne prévoit d'alimentation automatique du PMV par prélèvement SEPA ; si un besoin de ce type existe
   (ex. rechargement automatique mensuel), il devra faire l'objet d'une US et d'une RG dédiées, hors
   périmètre de ce lot.
4. **Bébé/membre de famille sans compte propre** — non traité explicitement par le cahier M4, cf. §7.
