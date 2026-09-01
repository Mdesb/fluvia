# Spec — Rapprochement aux flux métier (`App\Finance\Treasury`, extension de FIN-4)

- **Lot / module :** extension du module **existant** `finance` / feature `bank_reconciliation`
  (`App\Finance\Treasury`, déjà codé — cf. `specs/finance/plan-treasury.md`). Ne crée aucun nouveau
  module ; étend `BankReconciliationHandler`, `BankReconciliationSuggestionCalculator`,
  `ConfirmReconciliationProcessor`, `ReconciliationSuggestionProvider` existants.
- **Stories couvertes :** **US-TRE-11 à US-TRE-16** (nouvelles, suite de US-TRE-01 à 10 de
  `spec-treasury.md`) — ⚠ **HORS BACKLOG**, comme le reste de la suite Treasury (aucune source
  `backlog.html`/`cahier-detaille.html` ne couvre la trésorerie) : à faire valider/chiffrer avant
  développement, au même titre que `spec-treasury.md` §6.
- **Règles de gestion :** **RG-TRE-14 à RG-TRE-24** (nouvelles, la numérotation `RG-TRE-01` à `RG-TRE-13`
  étant déjà réservée par `spec-treasury.md`/`plan-treasury.md`). Règles **réutilisées, non
  redéfinies** : `RG-TRE-01/02/03/04/09` (comptes bancaires, import, suggestion, confirmation par
  écriture, détection d'écart — `spec-treasury.md`), `RG-M6-13/14` (compte auxiliaire par
  `counterpartyType`/`counterpartyId`, lettrage groupé — `spec-comptabilite-generale.md`),
  `RG-SOCLE-01/05` (rattachement établissement, cloisonnement).
- **Statut :** proposée.

## 1. Objectif

Permettre à un exploitant **non-comptable** de confirmer le rapprochement d'une ligne de relevé
bancaire directement contre l'**objet métier qu'il reconnaît** — sa remise SEPA, son versement de
régie, son règlement PayFiP — plutôt que contre une ligne d'écriture comptable 512 qu'il ne sait pas
lire, l'écriture comptable et son lettrage étant produits (ou retrouvés s'ils existent déjà) et posés
**automatiquement derrière**, sans jamais exposer le vocabulaire comptable à l'écran de rapprochement
et sans que l'absence de compte 512 explicitement configuré reste un blocage muet dans le cas standard.

## 2. Périmètre

### Inclus
- Extension de `GET /finance/treasury/statement-lines/{id}/suggestions` : la liste retournée mélange,
  pour une même ligne de relevé, les candidats **ligne d'écriture** déjà existants (RG-TRE-03) et de
  **nouveaux** candidats **flux métier**, chacun typé (US-TRE-11, RG-TRE-14/15).
- Extension de `POST /finance/treasury/statement-lines/{id}/reconcile` : le corps accepte, en
  alternative à `ledgerLineIds`, `{ businessFlow: { type, id } }` (US-TRE-12).
- **Trois types de flux couverts** : `regie_versement` (`App\Compta\Entity\BordereauVersement`),
  `sepa_remise` (`App\Sepa\Entity\RemiseSepa`), `payfip_settlement`
  (`App\Compta\Entity\BordereauPayFiP`, **best-effort v1**, §7) (US-TRE-13/14/15).
- **Cas « l'écriture existe déjà »** (`regie_versement`) — retrouve la ligne 512 déjà scellée via
  `BordereauVersement.ecritureGeneree` et la lettre, exactement comme un rapprochement par écriture
  (RG-TRE-17).
- **Cas « l'écriture n'existe pas encore »** (`sepa_remise`, `payfip_settlement`) — génère une écriture
  512 ↔ contrepartie équilibrée à la confirmation, via `DirectLedgerEntryBuilder` déjà existant, puis la
  lettre dans le même geste transactionnel (RG-TRE-18).
- **Auto-résolution du compte 512** du compte bancaire quand il n'est pas configuré explicitement
  (RG-TRE-16) — supprime le blocage aujourd'hui systématique dans le cas standard (établissement
  onboardé via `StructureOnboarding`/`AccountingChartSeeder`, qui seed déjà un compte `512000` par
  profil exploitant).
- Cloisonnement établissement strict, un contrôle dédié par type de flux (US-TRE-16, RG-TRE-20/21/22).
- Idempotence/irréversibilité (RG-TRE-19), traçabilité étendue de `treasury.reconciliation_completed`
  (RG-TRE-24).

### Exclu (pour l'instant)
- **La clôture de caisse (`App\Caisse\Entity\ClotureZ`) comme flux directement rapprochable** — elle ne
  génère **aucune** écriture comptable dans le dépôt actuel (vérifié : aucun fichier `App\Compta`
  ne référence `ClotureZ`/`SessionCaisse`) ; c'est le **versement de régie** qu'elle précède
  (`BordereauVersement`, généré séparément, sans lien de clé étrangère vers la `ClotureZ` d'origine)
  qui porte l'écriture 512 réellement rapprochable. §4.6 détaille cette clarification — pas un
  écart mineur, une **correction du périmètre annoncé par la mission** (§0 « Points ouverts »).
- **Le détail par mandat/client d'une remise SEPA rapprochée** — la contrepartie générée pour
  `sepa_remise` est **collective** (`counterpartyType`/`counterpartyId` pointant la `RemiseSepa`
  elle-même, cohérent RG-M6-13), pas ventilée par `LigneRemiseSepa`/mandat/`Facture` cliente — ventiler
  exigerait de traverser `LigneRemiseSepa → MandatSepa → Facture → ReglementFacture`, aucun de ces
  chemins n'étant aujourd'hui outillé pour la comptabilisation (`ReglementFacture` ne porte lui-même
  aucune référence à une écriture, vérifié).
- **Le regroupement de plusieurs `BordereauPayFiP` en un seul règlement bancaire agrégé** (ex. un
  virement quotidien de la DGFiP couvrant plusieurs transactions) — v1 traite chaque `BordereauPayFiP`
  comme un candidat **1:1** (§7, limite connue, non levée par ce lot).
- **Un nouveau moteur d'écritures ou de lettrage** — réutilise `DirectLedgerEntryBuilder`,
  `ScellementEcritureHandler`, `LettrageHandler::lettrer()`/`lettrerGroupe()` **tels quels**, aucune
  modification d'`App\Compta`.
- **Toute modification d'`App\Sepa`, d'`App\Caisse`, de `App\Compta\Entity\BordereauVersement/
  BordereauPayFiP`** — lecture seule sur ces trois modules ; le seul écrit nouveau est, comme pour le
  rapprochement par écriture existant, une écriture (nouvelle, cette fois) + un lettrage dans
  `App\Compta`, via les services publics déjà exposés.
- **Le rapprochement automatique sans confirmation humaine** — cohérent avec RG-TRE-03 : la suggestion
  reste **proposée**, jamais appliquée seule.

## 3. Acteurs & droits

Réutilise strictement les permissions déjà déclarées par `App\Finance\FinanceModule` (aucune nouvelle
permission) :

| Acteur | Peut | Permission |
|---|---|---|
| **Trésorier / Régisseur / agent non-comptable** | Consulter les suggestions (écriture **et** flux métier), confirmer un rapprochement en désignant un flux métier reconnu, ignorer une ligne | `finance.read`, `finance.treasury_reconcile` |
| **Comptable** | Idem, + confirmer un rapprochement par ligne d'écriture directe (chemin existant, inchangé) | `finance.read`, `finance.treasury_reconcile` |
| **Administrateur établissement** | Créer/éditer un `BankAccount` (déclenche l'auto-résolution du 512, RG-TRE-16) | `finance.treasury_manage_account` |
| **Système** | Résoudre les candidats flux métier (lecture), générer l'écriture manquante à la confirmation | *(acteur technique porté par l'utilisateur confirmant, pas de permission séparée — l'écriture est générée **au nom de** l'utilisateur qui confirme, comme le lettrage existant)* |

## 4. Comportements & règles

### 4.1 Une seule liste de suggestions, deux natures (US-TRE-11, RG-TRE-14)

- **RG-TRE-14** — `GET /finance/treasury/statement-lines/{id}/suggestions` renvoie **une seule liste**
  mêlant les deux natures de candidats, chacun discriminé par un champ `kind` :
  `{ kind: 'ledger_line', ...ReconciliationCandidate }` (inchangé, RG-TRE-03) ou
  `{ kind: 'business_flow', flowType, flowId, label, amountCents, referenceDate, textScore,
  alreadyPosted }`. **Décision d'architecture de ce lot** (le point que la mission demande de trancher) :
  une liste unique plutôt que deux endpoints séparés, parce que l'exploitant doit voir « les choses
  auxquelles cette ligne de relevé peut correspondre » comme **une seule question**, jamais deux écrans
  distincts pour un même geste (Règle d'or, `constitution.md` §2 — la complexité du moteur ne doit
  jamais transparaître). Les deux listes sont triées ensemble par `textScore` décroissant (même
  logique de tri que RG-TRE-03, appliquée uniformément).
  - ⚠ **Changement de contrat sur un endpoint déjà livré** — le code actuel de `ReconciliationSuggestionProvider`
    renvoie un tableau plat de `ReconciliationCandidate` (sans `kind`). Ce lot **change la forme du
    tableau** (chaque élément gagne `kind`) sans changer sa nature (toujours un tableau) — impact
    console d'admin/BO déjà branché sur cette route à vérifier avant merge (§7).
- **RG-TRE-15** — Le calcul d'un candidat flux métier suit la **même règle de correspondance** que
  RG-TRE-03 : montant total du flux **exactement égal**, au centime, au montant absolu de la ligne de
  relevé (tolérance nulle) ; date de référence du flux dans la fenêtre `TreasurySettings.matchingWindowDays`
  (réutilisée, pas de second réglage). Un flux déjà rapproché (RG-TRE-23) n'est **plus** candidat.
  Montant/date par type (§4.3/§4.4/§4.5).

### 4.2 Confirmation par flux métier — un seul endpoint, deux façons de désigner la cible (US-TRE-12)

- Le corps de `POST /finance/treasury/statement-lines/{id}/reconcile` accepte, **en alternative
  mutuellement exclusive** à `{ ledgerLineIds: [uuid] }` (inchangé) : `{ businessFlow: { type:
  'regie_versement'|'sepa_remise'|'payfip_settlement', id: uuid } }`. Les deux formes aboutissent au
  **même** `BankReconciliationHandler`, étendu d'une méthode `confirmerFluxMetier()` qui :
  1. résout l'objet métier (`BordereauVersement`/`RemiseSepa`/`BordereauPayFiP`) par son `id`, 404 s'il
     n'existe pas ;
  2. vérifie le cloisonnement établissement (§7, un contrôle dédié par type) ;
  3. vérifie que le montant total du flux correspond exactement à `|BankStatementLine.amount|`
     (défense en profondeur — même règle que la suggestion, revérifiée à la confirmation, jamais fait
     confiance à ce que le client a affiché) — 422 sinon ;
  4. **retrouve** (RG-TRE-17) ou **génère** (RG-TRE-18) la `LigneEcriture` 512 correspondante ;
  5. délègue à la mécanique de lettrage **existante et inchangée** (`lettrer()` mono-ligne +
     `reconciliationCode`, §0.6 du plan) ;
  6. copie `businessFlowType`/`businessFlowId` sur la `BankStatementLine` (nouveaux champs, §5) et émet
     `treasury.reconciliation_completed` avec le payload étendu (RG-TRE-24).

### 4.3 Versement de régie — l'écriture existe déjà (US-TRE-13, RG-TRE-17)

- **RG-TRE-17** — Pour `regie_versement` (`App\Compta\Entity\BordereauVersement`, champs
  `montantCentimes`, `dateVersement`, `ecritureGeneree`) : l'écriture 512 est **toujours déjà scellée**
  au moment où une ligne de relevé peut apparaître (`RegieHandler::enregistrerVersement()`, existant,
  génère et scelle l'écriture **au moment même** où le régisseur déclare le versement — vérifié dans le
  code, la banque ne fait que confirmer après coup un fait déjà comptabilisé). La confirmation retrouve
  la `LigneEcriture` du compte 512 au sein de `bordereau.getEcritureGeneree()` et applique **exactement**
  le chemin RG-TRE-17 = le chemin RG-TRE-04/§0.6 déjà codé (`lettrer()` + `reconciliationCode`) : aucune
  nouvelle écriture, seule la **désignation** de la cible par l'utilisateur change (un bordereau qu'il
  reconnaît, pas un identifiant de ligne d'écriture qu'il ne connaît pas).
  - Montant candidat = `BordereauVersement.montantCentimes`. Date candidate = `dateVersement`.
  - Cloisonnement : `bordereau.getRegie().getProfilExploitant().couvre(bankAccount.getEstablishment())`
    (réutilise `ProfilExploitant::couvre()`, même contrôle que `BankAccountProcessor`/
    `ResolveurComptesFacturation::profilPour()`).

### 4.4 Remise SEPA — l'écriture n'existe pas encore (US-TRE-14, RG-TRE-18)

- Pour `sepa_remise` (`App\Sepa\Entity\RemiseSepa`, champs `ctrlSumCentimes`, `dateCollecte`,
  `etablissement`, `statut`) : **aucune écriture comptable n'est jamais générée nulle part dans le dépôt
  actuel** pour une `RemiseSepa` ni pour les `ReglementFacture` qu'elle collecte (vérifié : ni
  `App\Sepa`, ni `App\Facturation` ne référencent `EcritureComptable`/`LigneEcriture` — cohérent avec
  le constat déjà posé par `plan-treasury.md` §0.8, ces deux modules sont des sources **read-only**
  pour Treasury). Seules les `RemiseSepa` au statut `Transmise` (collecte effectivement envoyée à la
  banque) sont candidates. Montant candidat = `ctrlSumCentimes` (déjà en centimes). Date candidate =
  `dateCollecte`. Cloisonnement : `remiseSepa.getEtablissement()->equals(bankAccount.getEstablishment())`
  (FK directe, pas d'indirection par profil).
- **RG-TRE-18** — La confirmation **génère** une écriture équilibrée via `DirectLedgerEntryBuilder`
  (réutilisé tel quel) :
  - Débit `bankAccount.getLedgerAccount()` (512), `ctrlSumCentimes`.
  - Crédit un compte résolu par préfixe `411` (`CompteLookupService::compteParPrefixe($profil,
    '411')`, même service que `ResolveurComptesFacturation`), `counterpartyType = 'sepa_remise'`,
    `counterpartyId = remiseSepa.getId()`, `counterpartyLabel` = un libellé lisible (« Remise SEPA du
    {dateCollecte} ») — patron **collectif** déjà retenu par RG-M6-13 pour fournisseurs/employés, ici
    appliqué à une remise SEPA (⚠ HYPOTHÈSE, §7 — l'exactitude comptable de créditer le 411 collectif
    plutôt qu'un compte d'attente dédié n'a **pas** été validée avec un expert-comptable).
  - Journal `OD` (opérations diverses — déjà seedé par `AccountingChartSeeder` pour toute saisie hors
    régime standard). Période = celle couvrant **la date d'opération de la ligne de relevé**
    (`BankStatementLine.operationDate`), pas `dateCollecte` — c'est la date bancaire réelle qui doit
    ouvrir/fermer l'exercice comptable, cohérent `RG-CLOTURE-10`.
  - L'écriture est scellée (`ScellementEcritureHandler::sceller()`) puis lettrée dans le **même**
    passage transactionnel que RG-TRE-17 — jamais deux étapes exposées côté API/UI.

### 4.5 Règlement PayFiP — l'écriture n'existe pas encore, best-effort v1 (US-TRE-15, RG-TRE-18 bis)

- Pour `payfip_settlement` (`App\Compta\Entity\BordereauPayFiP`) : la mission nomme ce flux
  « bordereau PayFiP », mais l'entité réelle **n'a pas de champ montant ni d'agrégat** — c'est une
  transaction **unitaire** (`venteOrigine` : `Uuid` **brut, pas une relation Doctrine** — vérifié —,
  `referenceTransaction`, `statutRetour`, `dateHeure`). Le montant candidat est résolu en chargeant
  `App\Vente\Entity\Vente` par `venteOrigine` (recherche manuelle par id, aucune jointure possible) et
  en lisant `Vente.total` ; l'établissement candidat est `Vente.etablissement` (même vente). Seules les
  transactions `statutRetour = Ok` **et** `venteRapprochee = true` sont candidates (règlement
  effectivement confirmé côté PayFiP).
  - Génération d'écriture (RG-TRE-18, même mécanique, décliné) : Débit 512, Crédit préfixe `511`
    (« Valeurs à l'encaissement », déjà seedé par `AccountingChartSeeder` avec le commentaire explicite
    « encaissement en régie directe ») — ⚠ HYPOTHÈSE forte (§7) : ce choix suppose que la vente d'origine
    a été comptabilisée par M2 avec une contrepartie en attente sur ce même préfixe 511 en payant par
    PayFiP, hypothèse **non vérifiée** dans ce lot (`TraiterRetourPayFipHandler` documente lui-même
    explicitement ne **jamais** toucher M2/`Vente` — « M2 non modifié »). Si cette hypothèse est fausse,
    l'écriture générée ici **doublonnerait** une contrepartie déjà soldée ailleurs. **À confirmer avec
    le propriétaire de M2/`App\Vente` avant tout développement** — signalé comme risque le plus incertain
    de cette spec (§7 point 1).
  - `counterpartyType = 'boutique_payfip'`, `counterpartyId = bordereau.getId()`.

### 4.6 Clôture de caisse — redirection, pas un flux rapprochable (§2 Exclu, US-TRE-16)

- `App\Caisse\Entity\ClotureZ` porte un champ `versement` (montant théorique à déposer) mais **ne
  génère aucune `EcritureComptable`** et **ne référence aucun `BordereauVersement`** (aucune relation
  entre les deux entités dans le schéma actuel — vérifié). Le geste bancaire réel (le dépôt en banque)
  reste porté, comme aujourd'hui, par `RegieRecettes`/`BordereauVersement` (§4.3), déclenché séparément
  par le régisseur via `POST /compta/regies/{id}/versements`. **Ce lot ne propose pas** de lier
  `ClotureZ` à `BankStatementLine` : le faire correctement demanderait soit une nouvelle relation
  `BordereauVersement → ClotureZ` (hors périmètre, modifierait `App\Caisse`/`App\Compta`), soit une
  correspondance déclarative fragile (même montant, pas de garantie d'unicité). **US-TRE-16** couvre
  uniquement la **clarté du message d'API** : si un flux `regie_versement` n'est pas trouvé pour un
  montant qui correspond à une `ClotureZ.versement` récente, l'absence de candidat reste silencieuse
  (comportement standard « aucun candidat », pas une erreur) — pas de fausse promesse d'un flux
  « clôture de caisse » qui n'existe pas comme tel dans le modèle actuel.

## 5. Objets de données

| Objet | Champ | Type | Contraintes | Notes |
|---|---|---|---|---|
| **`BankStatementLine`** *(existant, étendu)* | businessFlowType | `string(20)`? | nullable, requis si rapprochée via flux métier | nouveau, RG-TRE-14/17/18 |
| | businessFlowId | uuid? | nullable, requis si `businessFlowType` non nul | nouveau — copie l'id de `BordereauVersement`/`RemiseSepa`/`BordereauPayFiP` |
| **`BankAccount`** *(existant, comportement étendu)* | ledgerAccount | ref CompteComptable? | **auto-résolu si absent à l'écriture** (RG-TRE-16) | aucun nouveau champ, changement de comportement du processor uniquement |
| *(non persisté)* `BusinessFlowCandidate` (`App\Finance\Treasury\Dto`) | flowType | enum `regie_versement`\|`sepa_remise`\|`payfip_settlement` | — | RG-TRE-14 |
| | flowId | uuid | — | id de l'objet métier source |
| | label | string | — | libellé lisible construit côté serveur (jamais de jargon comptable) |
| | amountCents | int | — | §4.3/4.4/4.5 |
| | referenceDate | date | — | §4.3/4.4/4.5 |
| | textScore | float | — | même calcul que `ReconciliationCandidate` (RG-TRE-03) |
| | alreadyPosted | bool | — | `true` pour `regie_versement` (RG-TRE-17), `false` pour `sepa_remise`/`payfip_settlement` (RG-TRE-18) |
| **`LigneEcriture`** *(existant, aucune modification de schéma)* | counterpartyType | `'sepa_remise'`\|`'boutique_payfip'` (nouvelles valeurs) | — | RG-TRE-18, mêmes colonnes que RG-M6-13, aucune migration |
| | counterpartyId | uuid | — | `RemiseSepa.id`/`BordereauPayFiP.id` |

Aucune migration de schéma nouvelle table : deux colonnes nullable ajoutées à
`finance_treasury_bank_statement_line` (`business_flow_type VARCHAR(20) NULL`,
`business_flow_id BINARY(16) NULL`) — une migration additive, réversible.

## 6. API

| Route | Changement | Détail |
|---|---|---|
| `GET /finance/treasury/statement-lines/{id}/suggestions` | **Réponse étendue** | tableau `list<{kind: 'ledger_line'\|'business_flow', ...}>` au lieu de `list<ReconciliationCandidate>` (RG-TRE-14) |
| `POST /finance/treasury/statement-lines/{id}/reconcile` | **Corps étendu** | accepte `{ businessFlow: { type, id } }` en alternative à `{ ledgerLineIds }` — mutuellement exclusif, 422 si les deux ou aucun ne sont fournis et qu'il n'y a pas de `suggestedLedgerLine` par défaut |
| — | *(inchangé)* | `security: "is_granted('PERM', 'finance.treasury_reconcile')"` (réutilisée, aucune nouvelle permission) |

## 7. Sécurité / cloisonnement

- **RG-TRE-20** (`regie_versement`) — `bordereau.getRegie().getProfilExploitant().couvre(bankAccount.
  getEstablishment())` doit être vrai ; sinon 404 (identifiant client non protégé par une extension
  Doctrine — même défense en profondeur que `ConfirmReconciliationProcessor::§0.2 point 3` du plan
  existant, jamais un simple 403 générique).
- **RG-TRE-21** (`sepa_remise`) — `remiseSepa.getEtablissement()->equals(bankAccount.getEstablishment())`
  doit être vrai ; 404 sinon.
- **RG-TRE-22** (`payfip_settlement`) — la `Vente` résolue via `venteOrigine` (recherche manuelle, pas
  de jointure) doit avoir `Vente.etablissement->equals(bankAccount.getEstablishment())` ; 404 sinon —
  **jamais** l'en-tête `X-Etablissement` seul (D3/D8), toujours l'établissement **réellement porté**
  par l'objet chargé, comme partout ailleurs dans ce lot et dans FIN-2/FIN-3/FIN-4 existants.
- **RG-TRE-23** — Un flux métier déjà associé à une `LigneEcriture` lettrée par ce mécanisme
  (recherche par `counterpartyType`/`counterpartyId` = ce flux, sur le compte 512 de l'établissement)
  n'est **plus** un candidat (RG-TRE-15) et une nouvelle tentative de confirmation dessus **réutilise**
  la ligne déjà générée plutôt que d'en créer une seconde (RG-TRE-19) — jamais deux écritures pour un
  même flux, même en cas de rejeu réseau côté client.
- **RG-TRE-24** — `treasury.reconciliation_completed` (événement existant, §0.10 du plan) porte
  désormais, en plus du payload déjà défini, `businessFlowType`/`businessFlowId` (`null` quand la
  confirmation vient d'un `ledgerLineIds` direct) — tenant toujours dérivé de
  `BankAccount.establishment` (D6), jamais du contexte HTTP, inchangé.
- **Permission unique** — `finance.treasury_reconcile` couvre les deux chemins de confirmation (par
  ligne d'écriture ou par flux métier) : c'est le **même geste métier** (rapprocher une ligne de
  relevé) vu sous deux désignations différentes de la cible, pas deux opérations distinctes — cohérent
  avec la Règle d'or (ne pas multiplier les permissions pour une même action perçue par l'utilisateur).
- **Audit** — le lettrage généré porte `auteur` = l'utilisateur qui confirme (`LettrageEcriture.auteur`,
  inchangé) ; l'écriture générée (cas RG-TRE-18) est scellée (NF525, chaînage) comme toute écriture du
  dépôt, aucune dérogation.

## 8. Risques / à valider

1. **[CRITIQUE] Compte de contrepartie 511 pour `payfip_settlement` non vérifié avec le propriétaire de
   M2/`App\Vente`** (§4.5) — `TraiterRetourPayFipHandler` documente explicitement ne jamais modifier
   `App\Vente`, ce qui laisse ouverte la question de savoir quelle contrepartie M2 utilise déjà (le cas
   échéant) pour un paiement en ligne en attente de règlement. Générer une écriture Débit 512/Crédit 511
   sans certitude sur ce que M2 a déjà posé risque de **doubler** une contrepartie. À trancher avant tout
   développement — le point le plus incertain de cette spec.
2. **Compte de contrepartie 411 pour `sepa_remise`** (§4.4) — approximation « collectif » cohérente avec
   RG-M6-13 mais non validée avec un expert-comptable ; risque mineur comparé au point 1 (le 411 est un
   compte de tiers légitime pour un encaissement client, contrairement au 511 qui suppose une écriture
   M2 préexistante non confirmée).
3. **Changement de forme de la réponse `GET .../suggestions`** (§4.1, RG-TRE-14) — endpoint **déjà
   implémenté et potentiellement déjà consommé** par un client existant (BO/front) ; à coordonner avant
   merge, pas un endpoint neuf sans consommateur.
4. **`payfip_settlement` en v1 est strictement 1:1** (§2 Exclu, §4.5) — si le règlement bancaire réel de
   PayFiP/DGFiP est **agrégé** (plusieurs transactions en un seul virement), aucun candidat n'apparaîtra
   pour la ligne de relevé correspondante ; à confirmer avec le métier si un mode de règlement agrégé
   existe réellement pour PayFiP, auquel cas une v2 introduirait une vraie entité de règlement groupé.
5. **`ClotureZ` non rapprochable directement** (§4.6) — décision documentée, pas un oubli, mais change
   l'attente initiale de la mission qui listait « clôture de caisse » comme un flux à part entière ; à
   confirmer que rediriger vers `BordereauVersement` est acceptable pour l'exploitant final (l'écran
   pourrait avoir besoin d'expliciter ce lien, hors périmètre API de cette spec).
6. **Auto-résolution du 512 (RG-TRE-16) dépend de `AccountingChartSeeder`/`StructureOnboarding`** —
   un établissement créé hors de ce chemin standard (migration de données historiques, jeu d'essai
   ancien) peut ne pas avoir de compte `512xxx` actif ; dans ce cas le blocage **subsiste**, seulement
   déplacé (l'exploitant voit une liste de candidats vide et un message actionnable plutôt qu'une
   configuration manuelle bloquante — amélioration, pas une résolution à 100 %).
7. **Aucun test d'intégration réel avec un export bancaire PayFiP/SEPA de production** — les montants et
   dates candidats sont dérivés du modèle applicatif interne (`ctrlSumCentimes`, `Vente.total`), jamais
   confrontés à un relevé réel ; risque d'écart si la banque prélève des frais ou regroupe différemment.

## 9. Tests

| Test | Type | Couvre |
|---|---|---|
| `BusinessFlowSuggestionTest::testListeUniqueMelangeLigneEtFluxMetierTrieeParScore` | Fonctionnel API | RG-TRE-14 |
| `BusinessFlowSuggestionTest::testAucunCandidatFluxDejaRapproche` | Fonctionnel API | RG-TRE-15/23 |
| `RegieVersementReconciliationTest::testConfirmationRetrouveEcritureExistanteEtLettreSansEnCreerUneSeconde` | Fonctionnel API | RG-TRE-17 |
| `SepaRemiseReconciliationTest::testConfirmationGenereEcritureEquilibree512Vs411EtLaLettre` | Fonctionnel API | RG-TRE-18 |
| `SepaRemiseReconciliationTest::testRejeuNeGenerePasUneSecondeEcriture` | Fonctionnel API | RG-TRE-19/23 |
| `PayfipSettlementReconciliationTest::testMontantResoluViaVenteOrigineSansJointureOrm` | Unit | §4.5, limitation `venteOrigine` non-relation |
| `PayfipSettlementReconciliationTest::testTransactionNonRapprocheeCoteMetierNestPasCandidate` | Unit | §4.5 |
| `CloisonnementFluxMetierTest::testRegieVersementAutreEtablissementRefuse404` | Fonctionnel API | RG-TRE-20 |
| `CloisonnementFluxMetierTest::testRemiseSepaAutreEtablissementRefuse404` | Fonctionnel API | RG-TRE-21 |
| `CloisonnementFluxMetierTest::testPayfipVenteAutreEtablissementRefuse404` | Fonctionnel API | RG-TRE-22 |
| `BankAccountAutoResolutionTest::testCompte512AutoResoluSiNonFourniALaCreation` | Fonctionnel API | RG-TRE-16 |
| `BankAccountAutoResolutionTest::testAucunCompte512SeedeListeCandidatsVideMessageActionnable` | Fonctionnel API | §7 point 6 |
| `EventBusinessFlowTest::testPayloadPorteBusinessFlowTypeEtIdQuandConfirmationParFlux` | Unit | RG-TRE-24 |
| `EventBusinessFlowTest::testPayloadBusinessFlowNullQuandConfirmationParLigneDirecte` | Unit | RG-TRE-24, non-régression du chemin existant |

---

## Points ouverts / hypothèses (récapitulatif)

1. **⚠ HORS BACKLOG** — comme toute la suite Treasury (en-tête).
2. **[CRITIQUE]** Compte de contrepartie 511 pour `payfip_settlement`, non vérifié avec M2 (§8 point 1).
3. Compte de contrepartie 411 « collectif » pour `sepa_remise`, non validé avec un expert-comptable
   (§8 point 2).
4. Changement de forme de `GET .../suggestions`, endpoint déjà livré — coordination de merge nécessaire
   (§8 point 3).
5. `payfip_settlement` strictement 1:1 en v1, pas de règlement agrégé — à confirmer avec le métier
   (§8 point 4).
6. `ClotureZ` non rapprochable directement, redirection vers `BordereauVersement` — écart avec l'énoncé
   initial de la mission, documenté (§4.6, §8 point 5).
7. Auto-résolution du 512 ne couvre pas les établissements hors chemin d'onboarding standard (§8 point 6).
