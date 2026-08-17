# Spec — Facturation (`App\Facturation`)

- **Lot / module :** module transverse **hors backlog actuel** — s'appuie sur **M2** (Vente & Caisse,
  `spec-vente.md`), **M6** (Compta & Régie, `spec-compta.md`) et **M4** (CRM, `spec-crm.md`).
- **Stories couvertes :** **aucune `US-Lx` existante ne couvre ce module.** Le cahier détaillé et le
  backlog évoquent la facture comme **document** (M3-04 « Chaque billet payé est immédiatement
  disponible en QR et en facture », `cahier-detaille.html` l.537 ; M6-07 « Factures B2G émises via
  Chorus Pro », RG-M6-07/08/09) mais **aucune US ne spécifie l'émission, le cycle de vie ni la
  comptabilisation d'une Facture**. Le code existant le confirme explicitement :
  `App\Reservation\Facturation\FactureAEncaisserStrategie` (squelette) porte la note *« aucun objet
  "Facture" côté M6 n'existe dans ce dépôt »*. Cette spec introduit donc, à titre de **proposition**,
  les stories **US-FACT-01 à US-FACT-08** et les règles **RG-FACT-01 à RG-FACT-10**, à faire valider/
  formaliser au backlog avant développement — même statut que le RAD/redevances DSP et la
  consolidation groupe déjà signalés hors-US dans `spec-compta.md` (§4.8/§4.11).
- **Règles de gestion :** RG-FACT-01 à RG-FACT-10 (proposées par cette spec, aucune n'existe au
  cahier sous ce préfixe).
- **Statut :** brouillon — **plusieurs points ⚠ À VALIDER PAR EXPERT** (comptable public / fiscaliste,
  cf. §9) et **⚠ HYPOTHÈSE** (produit) avant figement, dans la continuité de `spec-compta.md`.

## 1. Objectif
Permettre d'émettre une **vraie facture opposable**, dans **deux circonstances distinctes** dont la
distinction comptable est **le cœur de ce module** :
1. un client demande **en plus** un document facture pour une vente **déjà encaissée** en caisse
   (déjà comptabilisée par M2/M6) → document **justificatif**, **acquitté**, qui **ne recompte jamais
   le CA** ;
2. un client (B2B, groupe, collectivité) est **facturé sans passage caisse** (vente à terme) → la
   facture **crée** la créance, l'écriture comptable et l'échéance de règlement.
Le tout avec les **mentions légales obligatoires**, une **numérotation chronologique inaltérable**, un
**avoir** comme seule voie de correction, et une **ouverture** vers Chorus Pro (B2G, réutilisé) et la
facture électronique B2B (signalée, non implémentée).

## 2. Périmètre
- **Inclus :**
  - Émission d'une **Facture justificative** adossée à une `Vente` (M2) déjà validée/scellée et
    intégralement payée : **aucune écriture comptable nouvelle**, statut « acquittée » (US-FACT-01,
    RG-FACT-03/09).
  - Émission d'une **Facture directe** (vente à terme, sans passage caisse) pour un **destinataire
    identifié** (particulier ou personne morale) : création de la créance et de l'écriture comptable
    (produit + TVA + créance), échéance et conditions de règlement (US-FACT-02, RG-FACT-03/04).
  - Gestion du **destinataire de facturation** : identité figée au moment de l'émission (raison
    sociale, SIRET, TVA intracommunautaire, adresse) pour une personne morale, ou identité simple pour
    un particulier (US-FACT-03, RG-FACT-08).
  - **Mentions légales obligatoires** portées par chaque facture (US-FACT-01/02, RG-FACT-02).
  - **Numérotation séquentielle chronologique inaltérable**, chaînage NF525 (US-FACT-01/02, RG-FACT-01).
  - Suivi de l'**échéance** et enregistrement d'un **règlement** (lettrage) sur une facture directe
    (US-FACT-04, RG-FACT-06), réutilisant `LettrageHandler` (M6).
  - **Avoir** (facture d'avoir) comme seule voie de correction d'une facture émise, jamais de
    suppression (US-FACT-05, RG-FACT-05).
  - **Dépôt Chorus Pro** pour les destinataires publics (B2G), en réutilisant `FactureB2G` /
    `ChorusProInterface` / `ChorusProStubAdapter` existants (US-FACT-06, RG-FACT-07).
  - Consultation/téléchargement par le **client** de ses propres factures (espace M3, cf. cahier
    M3-04) (US-FACT-07).
  - Paramétrage par établissement/exploitant des **séries de numérotation** et des mentions légales par
    défaut (émetteur) (US-FACT-08).
- **Exclu (pour l'instant), signalé mais non implémenté :**
  - **Facture électronique B2B** (réforme française de la facturation électronique, plateformes
    PDP/PPF) : ce module **ouvre la voie** (canal `pdf` vs `chorus_pro` factorisable vers un futur canal
    `pdp`) mais **ne l'implémente pas**. ⚠ HYPOTHÈSE — calendrier et choix de PDP hors périmètre, cohérent
    avec le point ouvert déjà signalé côté e-reporting (`spec-compta.md` §4.7).
  - **Relance et recouvrement avancés** (échéanciers de relance automatisés, contentieux, affacturage) :
    seul un **suivi d'échéance simple** (échue/non échue) est couvert (§4.5) ; tout mécanisme de relance
    structuré est **hors périmètre**, au-delà de ce que porte déjà `App\Reservation\Facturation`
    (stratégies no-show, non redéfinies ici — cf. §8).
  - **Affacturage** (cession de créance à un tiers financeur) : non traité.
  - La **vente**, l'**encaissement**, le **panier**, le **TPE**, le **ticket de caisse** eux-mêmes →
    **M2** (`spec-vente.md`), réutilisés ; ce module ne redéfinit pas l'acte de vente, il **s'y adosse**
    (facture justificative) ou **le remplace** par un acte de facturation directe pour les ventes à
    terme sans passage caisse.
  - Le **moteur d'écritures**, le **plan de comptes**, le **régime comptable** (M57/M4/PCG), la **TVA
    multi-taux**, le **lettrage**, la **clôture de période**, le **e-reporting agrégé B2C** et le
    **marquage « ImpayeRegie »** → **M6** (`spec-compta.md`), réutilisés à l'identique, non redéfinis.
  - Le **client/destinataire** (fiche CRM, SIRET, raison sociale) → **M4** (`spec-crm.md`), réutilisé ;
    ce module n'ajoute qu'un **instantané figé** (snapshot) au moment de l'émission (§4.4).
  - Les **stratégies de facturation no-show** (`App\Reservation\Facturation\*`) restent **inchangées** :
    elles pilotent le passage d'une réservation non honorée vers un mode d'encaissement (vente
    différée agent, débit PMV, prélèvement SEPA différé) au niveau de la **réservation**, pas de ce
    module. `FactureAEncaisserStrategie` reste un **squelette déclaratif** ; ce module **fournit
    désormais l'objet `Facture`** qui manquait pour le compléter un jour, mais **le brancher est hors
    périmètre** de cette spec (§8, point ouvert).

## 3. Acteurs & droits
Les permissions réutilisent le modèle du socle (`RG-SOCLE-02/03/04/05`) : couple `module × action`,
portées par l'**établissement actif** ; l'UI **masque** ce qui n'est pas autorisé (`RG-SOCLE-04`).
Module de droits proposé : **`facturation`**.

| Acteur | Peut | Ne peut pas | Permission (module × action) |
|---|---|---|---|
| **Agent de caisse / accueil** | Émettre une **Facture justificative** pour une vente qu'il a encaissée (ou visible sur son périmètre), renvoyer/dupliquer un PDF déjà émis | Émettre une facture directe (à terme), modifier une facture émise, générer un avoir | `facturation.lire`, `facturation.emettre_justificative` |
| **Comptable / Gestionnaire facturation** | Créer/gérer un destinataire, émettre une **Facture directe**, suivre les échéances, enregistrer un règlement (lettrage), générer un **avoir**, déposer une facture B2G sur Chorus Pro, consulter tout le journal de facturation | Modifier une facture déjà scellée, supprimer une facture | `facturation.lire`, `facturation.emettre_directe`, `facturation.avoir`, `facturation.lettrer`, `facturation.deposer_chorus` |
| **Administrateur** | Paramétrer les séries de numérotation, les mentions légales par défaut de l'émetteur, activer/désactiver le canal Chorus Pro | Contourner la numérotation ou le chaînage | `facturation.gerer` (surensemble), `securite.gerer` (socle, délégation) |
| **Client final** (espace client M3) | Consulter/télécharger **ses propres** factures (justificatives et directes le concernant) | Consulter la facture d'un autre client, en demander l'annulation directement | `facturation.lire_soi` |
| **Autorité publique destinataire** (Chorus Pro) | Recevoir la facture B2G via Chorus Pro (destinataire externe) | Intervenir dans l'émission | — (destinataire externe, sans compte applicatif, cf. `spec-compta.md` §3) |

- ⚠ HYPOTHÈSE — Noms des permissions `facturation.*` : proposées par analogie avec `vente.*`/`compta.*`
  (modèle socle `module × action`), non issues d'un tableau « Acteurs & droits » du cahier (ce module
  n'existant pas au cahier) ; à figer avec **M8** comme pour L1/L2/L4.
- ⚠ HYPOTHÈSE — Restriction « Agent de caisse : uniquement les ventes qu'il a encaissées (ou son
  périmètre établissement) » : posée par analogie avec le cloisonnement `RG-SOCLE-05`, à confirmer avec
  le métier (un accueil pourrait avoir besoin d'émettre une facture pour une vente d'un collègue).

## 4. Comportements & règles

### 4.1 Numérotation séquentielle chronologique inaltérable (RG-FACT-01)
- **RG-FACT-01** — Chaque **Facture émise** (justificative ou directe) et chaque **avoir** reçoivent un
  **numéro strictement croissant, sans trou ni doublon**, attribué **au moment de l'émission** (jamais
  en brouillon) — obligation légale de séquence chronologique continue (art. 242 nonies A du CGI /
  L441-9 du Code de commerce, ⚠ **cadre légal général, non issu du cahier**, à faire valider par un
  expert-comptable/fiscaliste comme les autres points NF525/fiscaux de `spec-compta.md`).
  - Deux **séries distinctes** : une pour les factures (`FA-…`), une pour les avoirs (`AVF-…`),
    cohérent avec le précédent déjà posé côté M2 (`Vente` vs `Avoir`, `GenerateurNumero`).
  - Une **Facture restée en brouillon** (jamais émise) **ne consomme aucun numéro**.
  - Le numéro est porté par un **chaînage NF525** (empreinte, empreinte précédente, signature),
    réutilisant le **principe** déjà posé côté caisse (`spec-vente.md` §4.8, `OperationScellee`/
    `HashChainSignataire`) et côté écritures (`spec-compta.md` §4.9, champs embarqués sur
    `EcritureComptable`) ; **aucune facture émise n'est modifiable ou supprimable** — seule une
    correction par **avoir** (§4.6) est possible.
  - ⚠ **OUVERT — périmètre de la séquence (établissement vs exploitant/SIREN)** : cette spec retient
    par défaut une séquence **par `ProfilExploitant`** (l'entité comptable rattachée à un **SIREN**,
    cf. `spec-compta.md` `ProfilExploitant`) **et par exercice** (`PeriodeComptable`), **par cohérence
    avec le précédent déjà posé pour `EcritureComptable`** (séquence unique par `(profilExploitant,
    journal, numeroSequence)`) plutôt qu'une séquence par établissement physique. Un `ProfilExploitant`
    pouvant couvrir plusieurs établissements rattachés (`etablissementsRattaches`), cela revient à une
    séquence **au niveau de l'exploitant/SIREN émetteur**, conforme à la pratique courante où le numéro
    de facture est rattaché au système de facturation de l'entité qui facture. **À valider par un
    expert-comptable** avant figement — un établissement à SIRET distinct (établissement secondaire)
    pourrait légalement justifier une série dédiée déclarée ; l'alternative « une séquence par
    établissement » reste possible sans changement de modèle (le champ porteur de la séquence serait
    alors l'établissement plutôt que le profil).

### 4.2 Mentions légales obligatoires (RG-FACT-02)
- **RG-FACT-02** — Toute facture émise porte au minimum :
  - le **numéro** séquentiel (§4.1) et la **date d'émission** ;
  - l'**identité de l'émetteur** : dénomination, adresse, SIRET, n° de TVA intracommunautaire le cas
    échéant (portés par `ProfilExploitant`/`Etablissement`, socle) ;
  - l'**identité du destinataire** (§4.4) : nom/prénom ou raison sociale, adresse, SIRET et TVA
    intracommunautaire si personne morale ;
  - la **désignation** de chaque ligne (produit/prestation), quantité, prix unitaire HT ;
  - la **TVA ventilée par taux** (réutilise RG-M6-05 : aucun taux moyen, cf. §4.4 `spec-compta.md`) ;
  - les **totaux** HT, TVA (par taux) et TTC ;
  - les **conditions de règlement** : échéance, et pour une facture **directe à terme B2B** — taux des
    pénalités de retard et **indemnité forfaitaire de recouvrement** (⚠ **cadre légal général** : 40 €
    par défaut en droit français B2B, non issu du cahier, à confirmer par un fiscaliste) ;
  - la mention **« Facture acquittée »** avec **date, moyen de règlement et référence** (n° de ticket
    de caisse pour une facture justificative) lorsque le montant est déjà réglé (§4.3).
- ⚠ HYPOTHÈSE — Mention « TVA non applicable, art. 293 B du CGI » (franchise en base) : non pertinente
  pour la majorité des exploitants ciblés (collectivités, DSP, groupes assujettis) mais le champ
  `mentionTvaSpecifique` reste **prévu, optionnel**, au cas où un petit exploitant en franchise
  utiliserait le module — à confirmer avec le métier.

### 4.3 Comptabilisation conditionnelle — la règle cœur (RG-FACT-03)
- **RG-FACT-03** — La comptabilisation d'une facture dépend **exclusivement** de son **origine**,
  jamais d'un choix manuel au moment de l'émission :
  1. **Facture justificative** (`origine = ticket_encaisse`, `venteOrigine` renseignée) — la `Vente`
     (M2) référencée est **déjà validée/scellée et intégralement payée** en session de caisse, donc
     **déjà comptabilisée** par le mécanisme existant (`RG-COMPTA-04` de `spec-compta.md` : toute vente
     validée produit automatiquement une écriture équilibrée). L'émission de la Facture :
     - est marquée **« acquittée »** immédiatement (date = date de la vente, moyen(s) = paiement(s) de
       la vente, référence = numéro du ticket M2) ;
     - **ne déclenche AUCUNE `EcritureComptable`** — `ecritureGeneree` reste `null` ;
     - **ne modifie jamais le CA** de la période : le CA a déjà été comptabilisé **une seule fois**, par
       la caisse/régie, au moment de la vente. La facture est un **document**, pas un **fait
       générateur comptable**.
  2. **Facture directe** (`origine = vente_a_terme`, aucune `venteOrigine`) — il n'y a **jamais eu de
     passage caisse** : l'émission de la Facture **EST** le fait générateur comptable. Elle
     **déclenche** la génération d'une **`EcritureComptable`** équilibrée (débit compte client / crédit
     compte de produit + TVA collectée), en **réutilisant à l'identique** le moteur d'écritures de M6
     (mapping comptable par famille de produit, régime comptable résolu via
     `RegimeComptableResolver`, scellement NF525) — **aucun second moteur d'écritures n'est créé** par
     ce module. Le statut passe à **« en attente de paiement »** avec l'échéance choisie ;
     `ecritureGeneree` référence l'écriture ainsi créée.
  - **Idempotence (jamais deux comptabilisations pour le même fait générateur)** :
    - une `Vente` scellée ne peut être à l'origine que d'**une seule** Facture justificative (contrainte
      d'unicité sur `venteOrigine`) — toute demande supplémentaire produit un **duplicata/renvoi** du
      même document (même numéro), **jamais** une nouvelle émission (§7, cas limite « duplicata ») ;
    - une Facture directe ne peut être **émise qu'une seule fois** : la transition
      `brouillon → émise` scelle la facture (empreinte non vide) et **génère l'écriture une fois pour
      toutes** ; toute tentative de ré-émission est **rejetée** (cohérent avec l'inaltérabilité NF525,
      même principe que `EcritureComptable::estScellee()`) ;
    - un **avoir** ne recrée jamais une comptabilisation positive : il ne fait qu'**extourner** (si la
      facture corrigée avait généré une écriture) ou **ne rien comptabiliser** (si la facture corrigée
      n'en avait jamais généré) — cf. §4.6.
- **Articulation régie** — En régie, la recette d'une vente au comptoir est **déjà constatée** par la
  caisse/régie (`RG-M2-06`/`RG-M6-10`) : la Facture justificative sur ticket **ne recrée donc ni titre
  de recette ni recette supplémentaire**, cohérent avec RG-FACT-03.1. Pour une **Facture directe émise
  en profil régie directe** (vente à terme facturée à une collectivité/un groupe sans passage caisse),
  l'articulation avec l'**émission d'un titre de recette** par l'ordonnateur reste, comme pour les
  écritures de régie en général, **un point ⚠ À VALIDER PAR EXPERT** — même point ouvert que celui déjà
  signalé dans `spec-compta.md` §4.6 (« Articulation titres de recettes / PES V2 »), qui s'étend
  naturellement à toute créance née d'une Facture directe en régie.

### 4.4 Destinataire de facturation — instantané figé (RG-FACT-08)
- **RG-FACT-08** — Au moment de l'émission, l'identité du **destinataire** (particulier ou personne
  morale) est **copiée en instantané figé** sur la Facture (raison sociale, SIRET, TVA intracommunautaire,
  adresse, ou nom/prénom/adresse pour un particulier) — une modification ultérieure de la fiche `Client`
  (M4) **n'altère jamais** une facture déjà émise (obligation légale de stabilité du document, cohérent
  avec l'inaltérabilité NF525).
  - Le destinataire peut être **rattaché** à une fiche `Client` (M4) vivante (`clientRef`, pour
    naviguer/consulter l'historique) **ou** saisi **hors fiche** (facturation ponctuelle B2G sans
    création de fiche CRM complète) — ⚠ HYPOTHÈSE : le cahier M4 ne prévoit pas explicitement de facture
    sans fiche client ; posé pour couvrir le cas d'une collectivité facturée une fois sans vouloir
    peupler le CRM, à confirmer avec le métier.
  - Un **client particulier sans SIRET/TVA** reste valide : ces champs sont **optionnels**, seuls
    requis pour une **personne morale** (cf. §7, cas limite « client sans SIRET »).

### 4.5 Cycle de vie, échéance & lettrage (RG-FACT-04, RG-FACT-06)
- **RG-FACT-04** — Cycle de vie d'une Facture :
  - `Brouillon` (modifiable librement, aucun numéro attribué) →
  - `Émise` (numéro + chaînage attribués, inaltérable) puis, selon l'origine (§4.3) :
    - **justificative** → `Acquittée` directement (aucune attente de paiement) ;
    - **directe** → `En attente de paiement` → `Partiellement réglée` (lettrage partiel) →
      `Payée`/`Lettrée` (solde à 0) **ou** `Échue` (échéance dépassée sans règlement complet — état
      informatif, ne bloque rien automatiquement, cf. §7).
  - Toute correction d'une facture `Émise` (quel que soit son état) passe **exclusivement** par un
    **avoir** (§4.6) — jamais de modification ni de suppression.
- **RG-FACT-06** — Un **règlement** enregistré sur une Facture directe est **rapproché/lettré** avec la
  ligne « client » de l'écriture de créance générée à l'émission, en réutilisant `LettrageHandler` (M6,
  `spec-compta.md` §4.2) — aucun second mécanisme de lettrage n'est créé. Un règlement peut être
  **partiel** (§7, cas limite « facture partielle ») ; le solde restant dû est recalculé à chaque
  lettrage.

### 4.6 Avoir — seule voie de correction (RG-FACT-05)
- **RG-FACT-05** — Un **avoir** (facture d'avoir) est la **seule** voie de correction d'une facture
  émise : il **ne modifie ni ne supprime** aucune ligne de la facture d'origine (cohérent avec le
  principe de contre-passation déjà posé côté M2, `RG-M2-07`, et côté M6, écriture d'extourne). Un
  avoir :
  - référence obligatoirement une **facture d'origine** (`factureCorrigee`) ;
  - reçoit son **propre numéro**, dans la série dédiée `AVF-…` (§4.1) ;
  - **si la facture corrigée avait généré une `EcritureComptable`** (facture directe) → l'avoir
    génère une **écriture d'extourne symétrique** (crédit compte client / débit produit + TVA), en
    réutilisant le mécanisme d'extourne existant de M6 ;
  - **si la facture corrigée n'avait généré aucune écriture** (facture justificative) → l'avoir **ne
    génère lui non plus aucune écriture** : rien à extourner comptablement, seule la correction
    documentaire est tracée (cohérent RG-FACT-03).
  - Un avoir peut être **total** (montant = facture d'origine) ou **partiel** (§7).

### 4.7 Canaux d'émission — PDF, Chorus Pro B2G, ouverture B2B (RG-FACT-07)
- **RG-FACT-07** — Deux canaux couverts par cette spec :
  - **PDF / impression** — génération d'un document PDF portant toutes les mentions légales (§4.2),
    téléchargeable par l'agent et par le **client** dans son espace (cahier M3-04, « Chaque billet payé
    est immédiatement disponible en QR et en facture »).
  - **Chorus Pro (B2G)** — pour un destinataire **personne morale de droit public** (école,
    collectivité), la facture est **déposée** via le port `ChorusProInterface` (réutilisé tel quel,
    stub `ChorusProStubAdapter` non branché sur un flux réel dans ce lot, cf. `spec-compta.md` §4.7) ;
    un enregistrement `FactureB2G` (existant, réutilisé) est créé/lié, portant `numeroEngagement` et
    `serviceExecutant` ; le **statut d'envoi** (préparé/transmis/rejeté) est **tracé**
    (`StatutEnvoi`, réutilisé). Le dépôt Chorus Pro **ne duplique jamais** le flux e-reporting agrégé
    B2C (`RG-M6-08`) : une facture B2G émise par ce module **exclut** la vente correspondante (le cas
    échéant) de l'agrégat e-reporting B2C, comme le fait déjà le marquage « ImpayeRegie » pour les
    recettes de régie (`RG-M6-09`) — même logique anti-double-comptabilisation.
  - **Ouverture B2B (signalée, non implémentée)** — le canal est conçu de façon à pouvoir accueillir
    demain un canal `pdp` (Plateforme de Dématérialisation Partenaire) pour la **facture électronique
    B2B** obligatoire (réforme française) ; **aucune implémentation n'est livrée** par cette spec.
    ⚠ HYPOTHÈSE — calendrier, format (Factur-X/UBL/CII) et choix de PDP hors périmètre, cohérent avec le
    point ouvert déjà signalé côté e-reporting (`spec-compta.md` §4.7, point 6).

## 5. Objets de données
Les types PHP sont indicatifs (spec = comportement observable). Tout objet est rattaché à un
**Établissement**/**ProfilExploitant** via le socle et M6 ; identifiants = **UUID** (constitution §3).
Les objets **Vente**, **LigneVente**, **Paiement**, **Avoir** (M2), **EcritureComptable**,
**LigneEcriture**, **LettrageEcriture**, **ProfilExploitant**, **PeriodeComptable**, **TauxTva**,
**MappingComptable**, **FactureB2G**, **ChorusProInterface** (M6) et **Client** (M4) sont
**référencés, non redéfinis**.

| Objet | Champ | Type | Contraintes | Notes |
|---|---|---|---|---|
| **Facture** | id | uuid | PK | RG-FACT-01 |
| | numero | string? | requis dès `émise`, null en `brouillon` | série `FA-…`, unique, sans trou (§4.1) |
| | nature | enum {facture, avoir} | requis | RG-FACT-05 |
| | origine | enum {ticket_encaisse, vente_a_terme} | requis, immuable après émission | RG-FACT-03 |
| | venteOrigine | ref Vente (M2)? | requis si `origine = ticket_encaisse`, **unique** (1 facture max/vente) | RG-FACT-03/09 |
| | factureCorrigee | ref Facture? | requis si `nature = avoir` | RG-FACT-05 |
| | profilExploitant | ref ProfilExploitant (M6) | requis | porte la séquence (§4.1) et le SIREN émetteur |
| | etablissement | ref Etablissement (socle) | requis | cloisonnement `RG-SOCLE-01/05` |
| | destinataire | ref DestinataireFacturation | requis, instantané figé | RG-FACT-08 |
| | statut | enum {brouillon, emise, acquittee, en_attente_paiement, partiellement_reglee, payee, echue} | défaut = brouillon | RG-FACT-04 |
| | dateEmission | datetime? | requise dès `émise` | — |
| | dateEcheance | date? | requise si `en_attente_paiement` | conditions de règlement |
| | conditionsReglement | texte | requis dès émise | échéance, pénalités, indemnité forfaitaire (§4.2) |
| | totalHT, totalTTC | decimal | requis | Σ lignes |
| | ventilationTva[] | (taux, baseHT, montantTva) | requis, ≥ 1 par taux présent | RG-M6-05 réutilisée |
| | mentionAcquittee | bool | true si réglée | RG-FACT-02 |
| | acquitteeLe, acquitteeMoyen, acquitteeReference | date, string, string | requis si `mentionAcquittee` | n° ticket M2 pour justificative |
| | ecritureGeneree | ref EcritureComptable (M6)? | **null si justificative**, requis si directe émise | RG-FACT-03, cœur |
| | factureB2G | ref FactureB2G (M6)? | requis si déposée Chorus Pro | RG-FACT-07 |
| | canal | enum {pdf, chorus_pro}? | — | RG-FACT-07 |
| | numeroSequence, empreinte, empreintePrecedente, signature | int, string, string?, string | append-only, dès émission | chaînage NF525 (§4.1), même principe que `EcritureComptable` |
| | creeLe, creePar | datetime, ref Utilisateur | requis | RG-SOCLE-07 |
| **LigneFacture** | id, facture | uuid, ref Facture | PK | — |
| | designation | string | requis | libellé produit/prestation |
| | ligneVenteOrigine | ref LigneVente (M2)? | requis si `origine = ticket_encaisse` | traçabilité vers la vente |
| | quantite | int ≥ 1 | requis | — |
| | prixUnitaireHT | decimal | requis | — |
| | tauxTva | ref TauxTva (M6) | requis | RG-M6-05, aucune ligne sans taux |
| | montantHT, montantTva, montantTTC | decimal | requis | montantTTC = HT + TVA |
| **DestinataireFacturation** | id | uuid | PK | instantané figé (§4.4) |
| | type | enum {particulier, personne_morale} | requis | conditionne les champs suivants |
| | nom, prenom | string? | requis si particulier | — |
| | raisonSociale, siret, tvaIntracommunautaire | string? | requis si personne_morale (SIRET), TVA optionnelle | RG-FACT-08 |
| | adresse | texte | requis | — |
| | clientRef | ref Client (M4)? | optionnel | lien vivant vers la fiche, sans devoir exister (§4.4) |
| | estOrganismePublic | bool | défaut = false | conditionne l'éligibilité Chorus Pro (§4.7) |
| **SerieNumerotation** | id, profilExploitant, exercice | uuid, ref ProfilExploitant, ref PeriodeComptable | 1 par (profil, exercice, préfixe) | §4.1, ⚠ périmètre ouvert (établissement vs exploitant) |
| | prefixe | enum {FA, AVF} | requis | facture / avoir |
| | dernierNumero | int ≥ 0 | append-only, incrémenté atomiquement | garantit « sans trou » |
| **ParametreFacturationEtablissement** | etablissement/profilExploitant | ref | requis | Admin (`facturation.gerer`) |
| | mentionsLegalesEmetteur | texte structuré | requis | dénomination, adresse, SIRET, TVA intra émetteur |
| | conditionsReglementDefaut | texte | requis | échéance par défaut, pénalités, indemnité forfaitaire |
| | chorusProActif | bool | défaut = false | conditionne l'apparition du canal (§4.7) |

## 6. Critères d'acceptation

- **CA-1 (US-FACT-01, RG-FACT-03.1 — cœur)** — *Étant donné* une `Vente` (M2) **validée, scellée et
  intégralement payée** au comptoir (déjà comptabilisée par `RG-COMPTA-04`), *quand* un agent ou le
  client émet une facture pour cette vente, *alors* une `Facture` de type `origine = ticket_encaisse`
  est créée, référence la `Vente`, reçoit un numéro, est marquée **« acquittée »** (date/moyen/n° de
  ticket de la vente), **`ecritureGeneree` reste `null`**, et le **CA de la période comptable ne
  varie pas** (aucune nouvelle écriture, cf. `spec-compta.md` RG-COMPTA-04 déjà déclenchée à la vente).
- **CA-2 (US-FACT-01, RG-FACT-03/09)** — *Étant donné* une `Vente` pour laquelle une Facture
  justificative a **déjà été émise**, *quand* une nouvelle demande de facture est faite pour la **même**
  vente, *alors* le système renvoie/duplique le **document existant** (même numéro), **aucune nouvelle
  `Facture` n'est créée**.
- **CA-3 (US-FACT-02, RG-FACT-03.2)** — *Étant donné* un destinataire identifié (personne morale) sans
  vente préalable en caisse, *quand* un Comptable compose les lignes, choisit l'échéance et **émet** la
  facture, *alors* une `Facture` `origine = vente_a_terme` est créée, une **`EcritureComptable`
  équilibrée** (débit client / crédit produit + TVA) est **générée et scellée**, la Facture devient
  **inaltérable** (numéro + chaînage attribués), et son statut passe à **« en attente de paiement »**
  avec l'échéance choisie.
- **CA-4 (US-FACT-02, RG-FACT-03, idempotence)** — *Étant donné* une Facture directe déjà **émise**
  (scellée, écriture générée), *quand* une tentative de **ré-émission** est faite, *alors* elle est
  **rejetée** : jamais une deuxième écriture pour le même fait générateur.
- **CA-5 (US-FACT-04, RG-FACT-06)** — *Étant donné* une Facture directe « en attente de paiement »,
  *quand* un règlement est enregistré pour le **montant total dû**, *alors* il est **lettré** avec la
  ligne « client » de l'écriture de créance, et le statut passe à **« payée »** ; *quand* le règlement
  est **partiel**, *alors* le statut passe à **« partiellement réglée »** et le solde restant dû est
  recalculé.
- **CA-6 (US-FACT-05, RG-FACT-05)** — *Étant donné* une Facture émise (justificative ou directe) à
  corriger, *quand* un utilisateur habilité génère un **avoir**, *alors* un nouvel objet `Facture`
  (`nature = avoir`) est créé, référence la facture d'origine, **aucune ligne d'origine n'est modifiée
  ni supprimée** ; *si* la facture d'origine avait généré une écriture (directe), *alors* une
  **écriture d'extourne symétrique** est générée ; *si* elle n'en avait généré aucune (justificative),
  *alors* **aucune écriture** n'est générée par l'avoir non plus.
- **CA-7 (US-FACT-01/02, RG-FACT-01)** — *Étant donné* une séquence de facturation active pour un
  exploitant/exercice, *quand* plusieurs factures sont émises consécutivement (y compris en cas de
  tentatives concurrentes), *alors* chaque facture émise reçoit un **numéro strictement croissant, sans
  trou ni doublon** ; une facture restée en **brouillon** ne consomme **aucun numéro**.
- **CA-8 (US-FACT-01/02, RG-FACT-02)** — *Étant donné* une facture émise, *quand* on l'affiche/exporte
  en PDF, *alors* elle porte **numéro, date, identité émetteur (SIRET/TVA), identité destinataire,
  désignation des lignes, TVA ventilée par taux, totaux HT/TVA/TTC, conditions de règlement**, et la
  mention **« acquittée »** avec date/moyen/référence si déjà réglée.
- **CA-9 (US-FACT-06, RG-FACT-07)** — *Étant donné* un destinataire **personne morale de droit public**,
  *quand* la facture est déposée sur Chorus Pro, *alors* un enregistrement `FactureB2G` est créé/lié
  (numéro d'engagement, service exécutant), le **statut d'envoi** est tracé (préparé/transmis/rejeté),
  et la vente/recette correspondante (le cas échéant) est **exclue** de l'agrégat e-reporting B2C
  (pas de double comptabilisation, cohérent `RG-M6-08/09`).
- **CA-10 (US-FACT-07)** — *Étant donné* un client connecté à son espace, *quand* il consulte ses
  documents, *alors* il voit et peut **télécharger** toutes ses factures (justificatives et directes)
  le concernant, sans accès aux factures d'un autre client.

## 7. Cas limites
- **Facture partielle** — une Facture directe peut être réglée **partiellement** (§4.5) ; ⚠ HYPOTHÈSE :
  la **facturation elle-même** (émission d'une facture pour une **partie** d'une commande/prestation,
  ex. acompte puis solde) n'est pas détaillée par cette spec au-delà du **règlement** partiel d'une
  facture déjà émise pour le **montant total** ; un mécanisme d'**acompte** (facture d'acompte distincte,
  puis facture de solde) serait à spécifier séparément si le besoin est confirmé.
- **Remboursement d'une vente déjà facturée (justificative)** — *étant donné* une `Vente` déjà réglée
  ayant fait l'objet d'une Facture justificative acquittée, *quand* la vente est ensuite
  **annulée/remboursée** côté M2 (`RG-M2-07`, avoir M2, qui **génère lui l'écriture d'extourne** via le
  mécanisme M6 existant), *alors* ce module de Facturation génère un **avoir de facture** (`nature =
  avoir`) référençant la Facture justificative, **sans générer de nouvelle écriture** (l'extourne a déjà
  eu lieu côté M2/M6) — cohérence documentaire pure. ⚠ HYPOTHÈSE : le **déclenchement automatique** de
  cet avoir de facture au moment de l'avoir M2 (vs génération manuelle a posteriori par le Comptable)
  n'est pas tranché ; à confirmer avec le métier (risque de facture justificative « orpheline » d'une
  vente annulée si l'avoir n'est pas déclenché systématiquement).
- **Multi-taux TVA** — une Facture (justificative ou directe) portant des lignes à taux différents
  **ventile la TVA ligne à ligne** (aucun taux moyen), réutilisant `RG-M6-05` à l'identique (§4.2).
- **Client sans SIRET (particulier)** — les champs SIRET/TVA intra restent **vides**, la facture reste
  valide et complète pour un particulier (§4.4) ; seule une **personne morale** doit renseigner un SIRET.
- **Personne morale sans TVA intracommunautaire** (auto-entrepreneur, hors UE, franchise) — le champ
  reste **optionnel** même pour une personne morale (§4.4).
- **Régie vs DSP/groupe privé** — la génération d'écriture d'une Facture directe (§4.3.2) traverse le
  **même** `RegimeComptableResolver` que M2/M6 : le régime (M57/M4 en régie, PCG en DSP/groupe) et le
  mapping comptable s'appliquent **sans branche spécifique** à ce module. En **régie**, l'articulation
  avec un **titre de recette** reste ⚠ **À VALIDER PAR EXPERT** (§4.3, hérité de `spec-compta.md`).
- **Facture directe émise à cheval sur deux exercices** — l'exercice retenu est celui couvrant la
  **date d'émission** (cf. `PeriodeComptable::couvre`, M6), cohérent avec la logique déjà posée pour les
  écritures.
- **Échec de dépôt Chorus Pro** — le `statutEnvoi` passe à **« rejeté »** ; une nouvelle tentative de
  dépôt est possible **sans** générer un nouveau numéro de facture (seul le dépôt est rejoué, pas
  l'émission) — cohérent avec le rejeu déjà posé pour PayFiP (`spec-compta.md` §4.5).
- **Duplicata / renvoi d'une facture déjà émise** — jamais une nouvelle `Facture` ni un nouveau numéro
  (§CA-2) ; uniquement une nouvelle génération/envoi du **même** document.
- **Destinataire modifié après émission** — sans effet sur les factures déjà émises (instantané figé,
  RG-FACT-08) ; seule une facture **future** portera la nouvelle identité.
- **Utilisateur sans affectation sur l'établissement/l'exploitant de la facture** — aucun accès (hérité
  du socle, `RG-SOCLE-05`).

## 8. Dépendances
- **Dépend de : socle L0** (`specs/L0-socle/spec-socle.md`) — hiérarchie
  Groupe/Région/Établissement/Espace (`RG-SOCLE-01`), permissions `module × action` sur le module
  **`facturation`** (`RG-SOCLE-02/03/04`), cadrage par établissement actif (`RG-SOCLE-05`), journal
  d'audit append-only (`RG-SOCLE-07`) que le chaînage NF525 de la Facture prolonge, comme pour M2/M6.
- **Dépend de : M2 · Vente & Caisse** (L2, `specs/L2-vente/spec-vente.md`, `app/src/Vente/`) — **source**
  de la `Vente` référencée par une Facture justificative (déjà validée/scellée/payée) ; réutilise le
  **numéro de ticket** et les **paiements** pour la mention « acquittée » ; les **avoirs M2**
  (`RG-M2-07`) restent le mécanisme de correction d'une vente elle-même, distinct de l'**avoir de
  facture** de ce module (§7, cas limite « remboursement d'une vente déjà facturée »).
- **Dépend de : M6 · Compta & Régie** (L4, `specs/L4-compta/spec-compta.md`, `app/src/Compta/`) —
  **réutilise à l'identique** : le moteur d'écritures (`EcritureComptable`, `LigneEcriture`,
  `RegimeComptableResolver`, mapping comptable, `TauxTva`), le **lettrage** (`LettrageHandler`), la
  **période comptable** (`PeriodeComptable`), le **scellement NF525** des écritures
  (`ScellementEcritureHandler`, dont s'inspire directement le chaînage de la Facture, §4.1), l'entité
  **`FactureB2G`** et le port **`ChorusProInterface`**/**`ChorusProStubAdapter`** (§4.7), ainsi que le
  marquage **« ImpayeRegie »** et le principe d'exclusion de l'agrégat e-reporting B2C (RG-M6-08/09).
  Ce module **n'ajoute aucun second moteur comptable** : il **appelle** celui de M6.
- **Dépend de : M4 · CRM** (L5, `specs/L5-crm/spec-crm.md`, `app/src/Crm/`) — **réutilise** la fiche
  `Client` (raison sociale, SIRET) comme **source optionnelle** du `DestinataireFacturation` (instantané
  figé, §4.4) ; ne redéfinit pas le CRM.
- **Interagit avec (sans dépendance stricte) :**
  - **`App\Reservation\Facturation\*`** (stratégies no-show) — **non modifiées** par cette spec. Ce
    module fournit désormais l'objet `Facture` que `FactureAEncaisserStrategie` (squelette déclaratif)
    attendait explicitement (commentaire du code : *« aucun objet "Facture" côté M6 n'existe dans ce
    dépôt »*) ; **brancher** cette stratégie sur le présent module (émettre une vraie Facture directe à
    l'issue d'un no-show) est une **suite naturelle mais hors périmètre** de cette spec (à traiter en
    story dédiée, sans casser le comportement actuel des stratégies).
  - **M3 · Boutique & App client** — consomme la Facture justificative pour l'affichage « billet +
    facture » de l'espace client (cahier M3-04) ; ne redéfinit pas le tunnel de commande.
  - **M8 · Admin & Droits** — arbitrage des noms de permissions `facturation.*` (§3).

---

## 9. Points ouverts / hypothèses (récapitulatif)

### ⚠ À VALIDER PAR EXPERT (comptable public / fiscaliste — dans la continuité de `spec-compta.md`)
1. **Périmètre de la séquence de numérotation** (établissement physique vs `ProfilExploitant`/SIREN) —
   cette spec retient par défaut l'exploitant/SIREN, par cohérence avec `EcritureComptable`, mais un
   établissement à SIRET distinct pourrait légalement justifier une série dédiée (§4.1).
2. **Seuil/obligation d'émission d'une facture** — en droit français général, une facture est
   **obligatoire pour tout destinataire professionnel (B2B)**, et **sur demande seulement** pour un
   particulier (B2C) ; **aucun seuil en euros n'est posé dans le cahier** (à ne pas confondre avec le
   **seuil d'impression du ticket** de caisse, `RG-M2-04`/`spec-vente.md` §4.6, qui est un mécanisme
   distinct). ⚠ Ce cadre est **général**, non issu du cahier, à confirmer par un fiscaliste avant
   d'implémenter un éventuel refus/simplification en dessous d'un montant.
3. **Articulation titre de recette / facture directe en régie** — hérite directement du point ouvert
   déjà posé dans `spec-compta.md` §4.6 pour les encaissements de régie ; s'étend à toute créance née
   d'une Facture directe émise en profil régie directe (§4.3).
4. **Indemnité forfaitaire de recouvrement et pénalités de retard** — valeurs par défaut (40 € B2B en
   droit français) à confirmer par un fiscaliste avant paramétrage (§4.2).
5. **Procédé cryptographique et périmètre de certification du chaînage NF525 de la Facture** — même
   point ouvert que celui déjà signalé pour la caisse (`spec-vente.md` §4.8) et les écritures
   (`spec-compta.md` §4.9), étendu ici à l'objet `Facture`.

### ⚠ HYPOTHÈSE (fonctionnel/produit, à trancher avec le métier / M7 / M8)
1. **Aucune US-Lx n'existe pour ce module** : `US-FACT-01` à `08` sont **proposées** par cette spec,
   comme le RAD/redevances DSP et la consolidation groupe l'ont été pour M6 ; à formaliser au backlog
   avant développement (préambule, §1).
2. Noms des permissions `facturation.*` (§3), à figer avec M8, comme pour L1/L2/L4/L5.
3. Restriction de l'Agent de caisse aux ventes qu'il a lui-même encaissées (§3).
4. Facturation **sans fiche `Client`** (destinataire ponctuel non rattaché au CRM) — posée pour couvrir
   la facturation B2G ponctuelle (§4.4).
5. **Déclenchement automatique ou manuel** de l'avoir de facture lors d'un remboursement M2 d'une vente
   déjà facturée (§7, cas limite dédiée) — risque de facture « orpheline » si non automatique.
6. **Facturation d'acompte/solde** (facturation partielle d'une même prestation, distincte du règlement
   partiel déjà couvert) — non spécifiée, à confirmer si le besoin existe (§7).
7. **Brancher `FactureAEncaisserStrategie`** (no-show) sur ce module : opportunité identifiée, **non
   traitée** par cette spec (§8).
8. **Facture électronique B2B (réforme)** : canal signalé, non implémenté, calendrier/PDP à trancher
   (§2, §4.7).
