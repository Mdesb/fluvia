# Plan technique — Expense reports / Notes de frais (`App\Finance\ExpenseReport`, lot `FIN-3`)

- **Spec source :** specs/finance/spec-expense-reports.md (+ specs/finance/spec-finance-suite.md §3/§5/§6)
- **Stack :** Symfony 7 · API Platform · Doctrine/MariaDB
- **Contrat de plateforme :** COORDINATION/CONTRACT/manifeste-module.md (module `finance`, partagé avec
  FIN-2), COORDINATION/CONTRACT/catalogue-evenements.md (3 événements `expense_report.*` déjà
  catalogués), COORDINATION/CONTRACT/noyau-commun.md (invariants #1/#2/#3/#7), COORDINATION/DECISIONS.md
  **D5/D6/D7/D8** (impératifs de la mission, traités explicitement §0.2/§0.7/§0.9/§0.10)
- **Dépend de (déjà livré, réutilisé tel quel, aucune duplication) :** `App\Autorisation`
  (`ServiceAutorisation`, `RequeteAutorisation`, `Decision`, `OperationSensible`, `LimiteAutorisation`,
  `DemandeEscalade`, `GestionnaireEscalade`) — **le moteur de validation graduée de cette brique**,
  `App\Personnel` (`Employe`, `RattachementEmploye`, `EmployeSoiVoter`), `App\Ocr`
  (`DocumentExtractor`, `DocumentKind::ExpenseReceipt` — déjà prévu par l'enum), `App\Compta` L4 +
  extension FIN-1 (`DirectLedgerEntryBuilder`, `DirectLedgerEntryLine`, `ExpenseAccountMapping`/
  `ExpenseAccountMappingGuard`, `LettrageHandler::lettrerGroupe()`, `PeriodeComptableResolver`,
  `CompteLookupService`, `ScellementEcritureHandler`), `App\Stock\Security\
  PerimetreEtablissementVerificateur` (réutilisé hors de son module d'origine, même précédent que
  FIN-2), `App\Securite` (`ContexteEtablissement`, `CalculateurDroits`, `Utilisateur`),
  `App\Platform\Event` (`EventBus`/`DomainEvent`/`EventTenant`/`EventSubject`/`EventName`),
  `App\Platform\Module` (`ModuleManifest` — **étend** `App\Finance\FinanceModule`, ne crée pas de second
  manifeste)
- **Couvre :** US-EXP-01 à US-EXP-09 · RG-EXP-01 à RG-EXP-11 · CA-1 à CA-8

> **Note de méthode.** Ce lot dépend de deux briques en cours d'implémentation en parallèle,
> **non modifiées par ce plan** : FIN-1 (`App\Compta`, extension déjà livrée selon
> `plan-comptabilite-generale.md`) et FIN-2 (`App\Finance\SupplierInvoice`, en cours). Au moment de la
> rédaction de ce plan, **aucun fichier n'existe encore sous `app/src/Finance`** — ce plan est donc écrit
> en amont du code des deux lots, et documente explicitement où son propre code devra **s'insérer** dans
> ce qui sera livré par FIN-2 (notamment `App\Finance\FinanceModule`, à étendre, jamais dupliquer — §0.10).

---

## 0. Décisions d'architecture

### 0.1 Namespace et rattachement du module

`App\Finance\ExpenseReport\{Entity,Enum,Dto,Service,State,Doctrine,Command}` (brique), même racine de
module que FIN-2 : `App\Finance\FinanceModule` (manifeste **partagé**, étendu §0.10 — pas de second
manifeste). Aucune entité de ce lot n'est créée dans `App\Compta`/`App\Personnel`/`App\Autorisation` : ils
sont **référencés**, jamais étendus par ce lot (FIN-1 a déjà fait l'extension Compta nécessaire).

### 0.2 Cloisonnement (D3/D8) — établissement direct + restriction « soi » combinée

`ExpenseReport` porte un **`establishment` direct** (comme `SupplierInvoice`, §0.2 de
`plan-supplier-invoices.md`) — l'ancre de périmètre et la source du tenant d'événement (D6, §0.7).

1. **Création** (`POST /finance/expense-reports`, `read: false`) — `CreateExpenseReportProcessor`
   vérifie, dans l'ordre, échec fermé à chaque étape :
   - `employee` référencé dans le corps → **doit être l'employé du salarié courant**
     (`is_granted('EMPLOYE_SOI', $employee)`, réutilise **strictement** le voter existant
     `App\Personnel\Security\EmployeSoiVoter` plutôt que de dupliquer la comparaison
     `Employe.utilisateur === utilisateur courant` — RG-EXP §4.3 point ouvert « un salarié sans compte
     `Utilisateur` ne peut pas soumettre lui-même » découle directement de ce contrôle : un `Employe`
     sans `utilisateur` échoue toujours `EMPLOYE_SOI`) — 403 sinon.
   - `establishment` référencé dans le corps → doit correspondre à un `RattachementEmploye` de **cet**
     `employee`, actif à la date du jour (`RattachementEmploye::estActifA()`, réutilisé tel quel) — 422
     sinon (§7 spec, « choix explicite de l'établissement de rattachement » pour un salarié multi-site).
   - `businessProfile` référencé → doit **couvrir** `establishment` (`ProfilExploitant::couvre()`,
     réutilisé, patron FIN-1/FIN-2) — 422 sinon.
   - **Défense en profondeur (D8)** : `App\Stock\Security\PerimetreEtablissementVerificateur::verifier()`
     (réutilisé hors de son module d'origine, même précédent explicitement posé par FIN-2 §0.2 point 2)
     sur `establishment` — l'appelant doit disposer d'au moins une `Affectation` sur cet établissement (ou
     il est son établissement actif). **Comportement réel du service** (vérifié dans le code, pas
     supposé) : il lève `AccessDeniedHttpException` (**403**, pas 404 — correction par rapport à la
     description « échec fermé 404 » du plan FIN-2, §7 point 10).
   - ⚠ **Edge case fonctionnel signalé (§7 point 13)** : ce contrôle est en pratique **redondant** avec
     les deux précédents pour un salarié légitime soumettant sur son propre établissement de
     rattachement (il doit déjà détenir `finance.expense_report_submit` — donc une `Affectation` —
     quelque part ; encore faut-il que ce soit précisément sur *cet* établissement). Conservé par
     cohérence avec le patron FIN-1/FIN-2, pas retiré.
2. **Lecture** (`GetCollection`/`Get`, tout `read: true`) — nouvelle extension Doctrine
   `App\Finance\ExpenseReport\Doctrine\PerimetreFinanceExtension implements
   QueryCollectionExtensionInterface, QueryItemExtensionInterface` (copie stricte du **patron** déjà posé
   par FIN-2 pour `SupplierInvoice` — **pas** la même classe, un nouvel exemplaire dans le namespace
   `ExpenseReport`, §7 point 4). Chaînes : `ExpenseReport => []`, `ExpenseLine => ['expenseReport']`,
   `Reimbursement => ['expenseReport']`. Double mode, patron `PerimetrePersonnelExtension::
   doitFiltrerParEmployeSoi()/restreindreParEmployeSoi()` (`App\Personnel`, réutilisé **par analogie**,
   pas par appel direct — modules différents) :
   - Titulaire d'au moins une permission « large » (`finance.read`, `finance.expense_report_post_to_ledger`,
     `finance.manage`) → visibilité **établissement** standard (jointure `Affectation`, comme
     `PerimetreFinanceExtension` de FIN-2).
   - Titulaire de **seulement** `finance.expense_report_read_own` → filtré strictement sur
     `IDENTITY(root.employee) = <Employe dont utilisateur = courant>` (aucun résultat si le courant n'a
     pas de fiche `Employe` — CA-7).
3. **Écriture au-delà de la création** (transitions `/submit`, `/reopen`, `/finalize-escalade`, lignes) —
   `security:` combine la permission ET `is_granted('EMPLOYE_SOI', object.getEmployee())` (réutilise
   directement le voter existant dans l'expression de sécurité API Platform elle-même — aucune classe de
   voter propre à ce lot n'est nécessaire, §3).

### 0.3 Le point d'intégration exact avec `App\Autorisation` (RG-EXP-04)

**Étape 1 — soumission (`SubmitExpenseReportHandler`, dans la transaction de `POST …/submit`) :**

```php
$decision = $this->serviceAutorisation->evaluer(new RequeteAutorisation(
    operationCode: 'finance.expense_report_approve',
    utilisateur: $report->getEmployee()->getUtilisateur(),   // jamais null ici : garanti par EMPLOYE_SOI à la création
    montant: $report->getTotalAmount(),
    cibleType: 'ExpenseReport',
    cibleId: $report->getId(),
    cibleEtablissementId: $report->getEstablishment()->getId(),
));
```

Branche sur `$decision->resultat` **sans jamais lancer `EscaladeRequiseException`** (divergence
délibérée par rapport au seul autre consommateur existant, M2 — §0.3.1) :
- `Autorise` → `status = Approved`, `approvedAt = now`, tentative synchrone de
  `ExpenseReportLedgerPoster::poster()` (§0.5).
- `EscaladeRequise` → `status` **reste** `Submitted`, `escalationRequest = $decision->demandeEscalade`.
  La réponse HTTP de `/submit` reste un succès (200/201) : la note est bien soumise, elle attend un
  superviseur — ce n'est **pas** un échec de la requête (RG-EXP-04).
- `Refuse` → `status = Rejected`, `rejectionReason = $decision->motif`.

Dans les trois cas, `expense_report.submitted` est émis **avant** l'appel à `ServiceAutorisation`
(RG-EXP-01.1 : « la transition fige les lignes, émet `expense_report.submitted`, **et** déclenche
l'évaluation ») ; `expense_report.approved` n'est émis que dans la branche `Autorise`.

#### 0.3.1 Pourquoi ce lot ne lance pas `EscaladeRequiseException`

Le seul consommateur existant de `ServiceAutorisation` (M2, `vente.annuler` — `plan-autorisation.md`
§6.3) traite `ESCALADE_REQUISE` comme un **échec** de la tentative en cours : il lance
`EscaladeRequiseException`, traduite en 403 par `EscaladeRequiseExceptionListener`, et **le même appelant**
doit **rejouer la même requête** en y ajoutant le jeton (`demandeEscalade` dans le corps) une fois
l'escalade approuvée. Ce patron ne convient pas à RG-EXP-04, qui décrit explicitement une note qui
**reste `submitted`** (pas rejetée) en attendant le superviseur — `ServiceAutorisation::evaluer()`
**retourne** une `Decision` (elle ne lance rien elle-même ; `EscaladeRequiseException` est un choix du
Processor **appelant**, pas du service). Ce lot exploite légitimement le même contrat public par une
branche différente, sans toucher à `App\Autorisation`.

#### 0.3.2 Étape 2 — finalisation après escalade (le vrai point délicat, §7 point 1/2)

`ServiceAutorisation::evaluerRejeu()` (branche `jetonRejeu !== null` de `evaluer()`) est conçue pour un
**rejeu côté client** (l'appelant d'origine represente sa requête avec le jeton). Rien dans le code ne
l'empêche techniquement d'être appelée **hors contexte HTTP** : la branche rejeu ne relit ni
`Security::isGranted()` ni `ContexteEtablissement::idActif()` (vérifié dans le code, `ServiceAutorisation::
evaluerRejeu()`, lignes 105-133) — elle compare seulement `DemandeEscalade` (statut, cible, montant,
auteur) et marque `dateRejeu`. C'est ce point technique précis qui rend possible le design retenu :

- **Service partagé** `App\Finance\ExpenseReport\Service\EscaladeExpenseReportResolver::resoudre(ExpenseReport $report): void` :
  1. Si `report.escalationRequest` est `null` ou `report.status !== Submitted` → no-op.
  2. Selon `escalationRequest.getStatut()` :
     - `Approuvee` → appelle `ServiceAutorisation::evaluer(new RequeteAutorisation(operationCode:
       'finance.expense_report_approve', utilisateur: $report->getEmployee()->getUtilisateur(), montant:
       $report->getTotalAmount(), cibleType: 'ExpenseReport', cibleId: $report->getId(),
       cibleEtablissementId: $report->getEstablishment()->getId(), jetonRejeu:
       $escalationRequest->getJeton()))` → attend `Decision::Autorise` (le rejeu consomme le jeton,
       `dateRejeu` posé, usage unique garanti **par le moteur existant**, §0 n°6 de `plan-autorisation.md`)
       → `status = Approved`, `approvedAt = now`, tente `ExpenseReportLedgerPoster::poster()`, émet
       `expense_report.approved` (`actor = null`, « réaction en chaîne », cohérent avec la doc de
       `EventActor`).
     - `Rejetee` → **pas d'appel à `ServiceAutorisation`** (rien à rejouer, la décision d'`App\Autorisation`
       est déjà finale) : `status = Rejected`, `rejectionReason = escalationRequest.getMotifRejet()`.
     - `Expiree` *(⚠ hypothèse, non couverte littéralement par la spec, §7 point 9)* → traité comme un
       rejet : `rejectionReason = "Escalade expirée sans décision du superviseur."`.
     - `EnAttente` → no-op (toujours en attente).
- **Deux déclencheurs, même service, aucune duplication de logique** :
  1. **Commande planifiée** `finance:expense-reports:resoudre-escalades` (même patron exact que
     `App\Autorisation\Command\ExpirerEscaladesCommand` — « à planifier via cron externe ») : parcourt
     `ExpenseReport` où `status = Submitted AND escalationRequest IS NOT NULL`, appelle le resolver pour
     chacune, `flush()` unique en fin de commande. **C'est le mécanisme qui garantit** le comportement
     décrit par RG-EXP-04 (« une fois approuvée par un superviseur, la note passe à `approved` ») même si
     personne ne rappelle explicitement l'API après l'approbation — sans cette commande, une note
     approuvée par escalade resterait `submitted` indéfiniment tant que personne ne la « réveille ».
  2. **Endpoint optionnel** `POST /finance/expense-reports/{id}/finalize-escalade` (§2) — confort UX
     (déclenchement immédiat plutôt que d'attendre le prochain passage du cron), strictement le même
     service, aucune logique dupliquée.

⚠ **Ce design est une décision d'intégration de ce plan, non un mécanisme déjà documenté par
`spec-autorisation.md`/`plan-autorisation.md`** (le seul usage décrit du rejeu est le retour client HTTP,
§0.3.1) — **premier consommateur non-HTTP de `evaluerRejeu()`**, à confirmer explicitement avec le
propriétaire du module `App\Autorisation` avant merge (§7 point 1, risque le plus important de ce plan).

### 0.4 Permission binaire défensive `finance.expense_report_approve` — à ne pas oublier de créer

**Piège identifié en préparant ce plan, à corriger explicitement dans le manifeste (§0.10) :**
`ServiceAutorisation::evaluer()` commence par `$this->security->isGranted('PERM',
$requete->operationCode)` — ici `'finance.expense_report_approve'` — **avant** toute résolution de
`LimiteAutorisation`. C'est le même patron que M2 (`vente.annuler` est à la fois le code
`OperationSensible` **et** une permission binaire authentique que tout caissier détient). `spec-finance-
suite.md` §5 affirme (à juste titre pour la surface API) qu'« aucune permission dédiée
`finance.expense_report_approve` » ne gate un endpoint — **mais cette lecture ne doit pas conduire à
omettre de déclarer/seeder la `Permission` elle-même** : sans elle, `isGranted()` renvoie
systématiquement `false` pour **tout** salarié (même sous plafond), et `ServiceAutorisation::evaluer()`
retourne `Refuse` à l'étape 1, **avant même de consulter une `LimiteAutorisation`** — RG-EXP-04 ne
fonctionnerait jamais, y compris son cas le plus simple (CA-2, note sous plafond).

**Décision de ce plan** : `App\Finance\FinanceModule::permissions()` déclare **quatre** entrées pour
cette brique (pas trois) : les trois permissions « API » (`finance.expense_report_submit`,
`finance.expense_report_read_own`, `finance.expense_report_post_to_ledger`) **+**
`finance.expense_report_approve` (jamais référencée par un `security:` d'opération API — uniquement
consommée par `ServiceAutorisation::evaluer()` en interne). Recommandation opérationnelle explicite
(hors code de ce lot, point de configuration) : accorder `finance.expense_report_approve` à **tout rôle**
qui détient déjà `finance.expense_report_submit` (ex. rôle « Employé »), sans quoi la fonctionnalité est
inerte. À documenter dans les fixtures de test de ce lot (§5/§6) et signalé à l'intégrateur (§7 point 1).

### 0.5 Déversement comptable — découplé de l'approbation métier (RG-EXP-06, CA-5)

Contrairement à FIN-2 (où un mapping de charge incomplet **bloque** `approve()`, la facture restant
`draft`), RG-EXP-06 dissocie explicitement l'**approbation métier** (décidée par `App\Autorisation`,
déjà actée dès `Decision::Autorise`/le rejeu approuvé) du **déversement comptable** (peut échouer,
rejouable, sans annuler l'approbation). Le modèle de données §5 spec ne liste **aucun** statut
intermédiaire supplémentaire (`{draft, submitted, approved, rejected, reimbursed}`, 5 valeurs
seulement) : ce plan retient donc `ledgerEntry === null` sur une note `approved` comme **seul et
unique** marqueur technique de « en attente de déversement » — pas de 6ᵉ valeur d'enum. ⚠ à confirmer
que cette lecture (statut inchangé, indicateur = absence de `ledgerEntry`) correspond bien à l'intention
du « statut intermédiaire » évoqué par la spec (§7 point 5, formulation ambiguë).

`App\Finance\ExpenseReport\Service\ExpenseReportLedgerPoster::poster(ExpenseReport $report): list<string>`
(retourne la liste des anomalies, **vide = succès** — même contrat de dégradation propre que
`ExpenseAccountMappingGuard::anomalies()`, jamais d'exception) :
1. Précondition (appelant) : `status === Approved && ledgerEntry === null` — sinon 409 côté Processor
   appelant, pas dans ce service.
2. Pour chaque `ExpenseLine` : `ExpenseAccountMappingGuard::resoudre($businessProfile,
   $line->getExpenseNatureCode())`. Un seul mapping introuvable/inactif → **anomalies non vides,
   aucune écriture construite** (tout ou rien, pas de dépôt partiel) → retour anticipé.
3. Calcul HT/TVA **par ligne**, jamais de taux « par défaut » emprunté au mapping (même principe que
   FIN-2 §0.5, RG-M6-05 « pas de taux moyen ») :
   - `ExpenseLine.vatRate` renseigné → `amountExclTax = amountInclTax / (1 + taux)`, `vatAmount =
     amountInclTax − amountExclTax` (calcul serveur, jamais fourni par le client) : débit du compte de
     charge résolu pour `amountExclTax`, débit du compte de TVA déductible (résolu par
     `CompteLookupService::compteParPrefixe($profil, '4456')`) pour `vatAmount`, agrégés par compte de
     charge distinct et par taux de TVA distinct (comme FIN-2).
   - `ExpenseLine.vatRate` absent → **aucune TVA déduite pour cette ligne** : la totalité du montant TTC
     débite le compte de charge (pas de recours implicite au `deductibleVatRate` du mapping, qui ne sert
     qu'à qualifier une nature de charge par défaut, jamais à construire l'écriture — ⚠ hypothèse
     conservatrice, à valider avec l'expert-comptable, §7 point 6).
4. Ligne crédit unique : `CompteLookupService::compteParPrefixe($profil, '421')`, montant =
   `report.totalAmount`, `counterpartyType = 'personnel_employe'`, `counterpartyId = employee.id`,
   `counterpartyLabel = "{prenom} {nom}"` (RG-EXP-06, RG-M6-13).
5. `journal = CompteLookupService::journal($profil, 'NDF')` *(nouveau code, non seedé —
   même situation non bloquante que `OD`/`ACH`/`BNQ` déjà signalée par FIN-1/FIN-2, §7 point 7)*,
   `periode = PeriodeComptableResolver::resoudreOuCreer($profil, $date)` (flux opérationnel courant,
   comme FIN-2 §0.7 point 3, pas un usage humain ponctuel).
6. `DirectLedgerEntryBuilder::construire(...)` → `report.ledgerEntry = $ecriture`. Aucun `flush()` ici
   (patron constant du dépôt) : le Processor appelant (`SubmitExpenseReportProcessor`,
   `EscaladeExpenseReportResolver`, ou `PostToLedgerExpenseReportProcessor` pour le rejouage manuel)
   porte la transaction/le `flush()`.

**Rejouable** (§7 cas limite spec) : `POST /finance/expense-reports/{id}/post-to-ledger`
(`finance.expense_report_post_to_ledger`) rappelle le même service tant que `ledgerEntry === null` et
`status === Approved` — aucune nouvelle approbation redemandée, cohérent RG-EXP-06.

### 0.6 Remboursement — lettrage groupé à deux lignes, un seul coup (RG-EXP-05, CA-4)

Contrairement à FIN-2 (règlements partiels possibles, lettrage différé jusqu'à solde nul), RG-EXP-05
retient explicitement **un remboursement en une fois** (§7 spec, hypothèse actée « non retenu v1 » pour
le partiel). Design volontairement plus simple :

- `Reimbursement` (entité, une par `ExpenseReport` — **contrainte `UNIQUE` en base** sur
  `expenseReport`, §1) : `amount` **doit égaler exactement** `report.totalAmount` (422 sinon — pas de
  comparaison « ≤ solde » comme FIN-2, il n'y a qu'un solde possible : zéro ou tout).
- Précondition : `status === Approved && ledgerEntry !== null` (**la note doit déjà être déversée en
  comptabilité** — un remboursement ne peut pas se lettrer contre une ligne 421 qui n'existe pas encore ;
  409 sinon « note pas encore déversée en comptabilité » — restriction **non énoncée littéralement** par
  la spec mais nécessaire mécaniquement, §7 point 8).
- `ReimburseExpenseReportHandler` : construit une **nouvelle** `EcritureComptable` via
  `DirectLedgerEntryBuilder` (débit 421 / crédit 512, journal `BNQ` — **réutilise le code déjà introduit
  par FIN-2**, pas un nouveau code de journal), `counterparty*` identiques à la ligne 421 d'origine, puis
  `LettrageHandler::lettrerGroupe([ligne421Report, ligne421Reimbursement], auteur)` — exactement 2
  lignes, montants strictement égaux par construction (invariant `lettrerGroupe()` trivialement respecté,
  contrairement à FIN-2 qui devait gérer un lettrage différé multi-lignes).
- `Reimbursement.ledgerEntry`/`reconciliationCode` renseignés, `report.status = Reimbursed`,
  `reimbursedAt = now`, émission `expense_report.reimbursed`.

### 0.7 Événements — tenant dérivé de `ExpenseReport.establishment` (D6), jamais du contexte HTTP

| Événement | Émis par | `EventTenant` | `EventSubject` | `EventActor` | Payload |
|---|---|---|---|---|---|
| `expense_report.submitted` | `SubmitExpenseReportHandler` (avant l'appel à `ServiceAutorisation`) | `new EventTenant($report->getEstablishment()->getId())` | `ExpenseReport`/id | l'utilisateur courant (le salarié) | `employeeId`, `amountCents` |
| `expense_report.approved` | `SubmitExpenseReportHandler` (branche `Autorise`) **ou** `EscaladeExpenseReportResolver` (branche `Approuvee`) | idem | idem | l'utilisateur courant, **ou `null`** si émis par la commande planifiée (§0.3.2, « réaction en chaîne ») | `amountCents`, `approverId` (email/id du superviseur si escalade, `null` si auto-approuvé sous plafond) |
| `expense_report.reimbursed` | `ReimburseExpenseReportHandler` | idem | idem | l'utilisateur courant (le Comptable) | `amountCents`, `date` (`Y-m-d`) |

**Jamais** `ContexteEtablissement::idActif()` comme source du tenant (même règle que FIN-2 §0.6) : la
commande planifiée n'a d'ailleurs **aucun** contexte HTTP, ce qui rend ce choix non seulement conforme à
D6 mais **techniquement obligatoire** ici — il n'existe pas d'en-tête `X-Etablissement` à lire depuis une
commande CLI.

⚠ **Point de vigilance fonctionnel additionnel (§7 point 14)** : `App\Autorisation\Service\
ServiceAutorisation::perimetreRespecte()` (branche `PerimetreAutorisation::PropreEtablissement`, réglage
par défaut d'une `LimiteAutorisation`, §0 de `LimiteAutorisation`) compare `cibleEtablissementId` à
`ContexteEtablissement::idActif()` — c'est-à-dire l'en-tête `X-Etablissement` **de la requête HTTP
`/submit` elle-même**, pas celui de la note. Un salarié multi-établissement dont l'en-tête actif (choisi
côté front) diffère de `report.establishment` au moment de l'appel `/submit` verrait sa demande
**refusée pour cause de périmètre** (`RG-AUTZ-05`) même si son droit binaire et son affectation sont
corrects — le front doit donc **aligner `X-Etablissement` sur `report.establishment`** avant d'appeler
`/submit`. Ce n'est pas un bug du présent design (le comportement de `ServiceAutorisation` est repris
tel quel, RG-EXP-04 l'exige), mais un point d'intégration front à documenter.

### 0.8 OCR — endpoint dédié, `DocumentKind::ExpenseReceipt`

Même patron que FIN-2 §0.3 : `App\Ocr\DocumentExtractor` est consommé exclusivement en PHP.
`POST /finance/expense-reports/extract` (corps `{ content: base64, mimeType }`, `read: false`, `input:
false`, `output: false`) → `ExtractExpenseReceiptProcessor` appelle `DocumentExtractor::extract($dto,
DocumentKind::ExpenseReceipt)` (valeur déjà présente dans `App\Ocr\Enum\DocumentKind`, aucune extension
de l'enum nécessaire) et renvoie les champs extraits + l'IRI de l'`ExtractionAttempt`. Le client
pré-remplit une `ExpenseLine` en brouillon puis l'enregistre normalement, en passant en option
`ocrExtraction` — aucune validation automatique (RG-EXP-03, même garde-fou que FIN-2 CA-2). Mode
dégradé toujours disponible (OCR en échec ou non configuré → saisie manuelle intégralement fonctionnelle).

### 0.9 Re-soumission après refus (RG-EXP-07, CA-6)

`POST /finance/expense-reports/{id}/reopen` : `status` doit être `Rejected` (409 sinon) → `status =
Draft`, `rejectionReason = null`, `escalationRequest = null` (la référence à l'ancienne `DemandeEscalade`
est **abandonnée**, pas réutilisée — cohérent avec `spec-autorisation.md` §4.6 « une nouvelle demande
devra être créée »). Les lignes restent éditables de nouveau (`ExpenseLineProcessor` les autorise à
nouveau puisque `status === Draft`). Un nouvel appel à `/submit` déclenche un **cycle complet neuf**
(nouvelle évaluation `ServiceAutorisation`, nouvelle `DemandeEscalade` le cas échéant) — CA-6 est ainsi
satisfait par construction, sans logique dédiée « ne pas hériter de l'ancienne approbation » (il n'y a
tout simplement plus aucune trace de l'ancien cycle après `reopen()`).

### 0.10 Extension du manifeste `App\Finance\FinanceModule` — partagé avec FIN-2, pas de second manifeste

Ce lot **n'implémente pas** `ModuleManifest` une seconde fois. Il **ajoute** à la classe unique
`App\Finance\FinanceModule` (que FIN-2 introduit en premier, `dependencies(): []` transitoire déjà
décidée par `plan-supplier-invoices.md` §7 point 7 — ce plan **reprend la même décision transitoire**,
mêmes modules `personnel`/`autorisation` ne portant pas encore de `ModuleManifest`) :

- `permissions()` **+=** `finance.expense_report_submit`, `finance.expense_report_read_own`,
  `finance.expense_report_post_to_ledger`, `finance.expense_report_approve` (§0.4 — la 4ᵉ est
  **essentielle au fonctionnement**, pas une simple déclaration cosmétique).
- `eventsEmitted()` **+=** `expense_report.submitted`, `expense_report.approved`,
  `expense_report.reimbursed` (déjà catalogués tels quels, `catalogue-evenements.md`).
- `features()` **+=** `expense_reports`, `ocr_expense_reports` (déjà nommées par
  `spec-finance-suite.md` §3.1).
- `eventsConsumed()` inchangé pour cette brique (aucun événement externe consommé par FIN-3 — la
  synchronisation avec `App\Autorisation` passe par appel PHP direct, jamais par le bus, §0.3).

⚠ **Point de coordination critique avec FIN-2, en cours en parallèle (§7 point 3)** : les deux lots
modifient le **même fichier** `App\Finance\FinanceModule`. Quel que soit l'ordre de merge, le second lot
doit **étendre** les tableaux déjà posés par le premier (concaténation simple de listes de chaînes,
conflit textuel de faible ampleur mais réel) — jamais réécrire la classe. Aucun fichier sous
`app/src/Finance` n'existe au moment de la rédaction de ce plan (vérifié) : les deux lots partent du même
état vide, la coordination est donc uniquement une question d'ordre de merge, pas de résolution de
conflit complexe.

---

## 1. Entités & schéma

| Entité (`App\Finance\ExpenseReport\Entity\*`) | Champ | Type Doctrine | Null | Index/Contrainte | Relation |
|---|---|---|---|---|---|
| **`ExpenseReport`** | id | uuid | non | PK | — |
| | establishment | uuid (FK) | non | index (`establishment`, `status`) | `Etablissement` (Organisation, socle) — ancre D6/D8 |
| | businessProfile | uuid (FK) | non | — | `ProfilExploitant` (Compta) — doit couvrir `establishment` (422 sinon, §0.2) |
| | employee | uuid (FK) | non | index | `Employe` (Personnel) — doit être l'employé du salarié courant (`EMPLOYE_SOI`, §0.2) |
| | status | `string(16)` enum `ExpenseReportStatus` | non | défaut `draft` | `draft`\|`submitted`\|`approved`\|`rejected`\|`reimbursed` |
| | totalAmount | `decimal(12,2)` | non | défaut `0.00` | Σ lignes, **recalculé serveur**, jamais fourni par le client |
| | escalationRequest | uuid (FK) | **oui** | — | `DemandeEscalade` (Autorisation) — posé si `Decision::EscaladeRequise` (§0.3) |
| | rejectionReason | `text` | **oui** | requis si `status = rejected` (validé applicativement) | RG-EXP-07 |
| | ledgerEntry | uuid (FK) | **oui** | — | `EcritureComptable` (Compta) — `null` = « approuvée, en attente de déversement » (§0.5) |
| | submittedAt / approvedAt / reimbursedAt | `datetime_immutable` | **oui** | — | horodatage du cycle de vie |
| | createdAt | `datetime_immutable` | non | — | — |
| | createdBy | uuid (FK) | **oui** | — | `Utilisateur` (Securite) |
| **`ExpenseLine`** | id | uuid | non | PK | — |
| | expenseReport | uuid (FK) | non | index | `ExpenseReport`, `inversedBy: lines` |
| | expenseNatureCode | `string(64)` | non | — | résolu par `ExpenseAccountMappingGuard` au déversement (§0.5), **jamais** bloquant à la soumission (RG-EXP-02) |
| | expenseDate | `date` | non | — | — |
| | amountInclTax | `decimal(12,2)` | non | `> 0` | saisi (manuel ou OCR) |
| | vatRate | uuid (FK) | **oui** | — | `TauxTva` (Compta) — présent seulement si TVA récupérable identifiable (§0.5) |
| | amountExclTax | `decimal(12,2)` | non | calculé serveur, `= amountInclTax` si `vatRate` absent | — |
| | vatAmount | `decimal(12,2)` | non | calculé serveur, `0.00` si `vatRate` absent | — |
| | receiptFileName / receiptMimeType / receiptSize / receiptUrl | `string(255)` / `string(100)` / `int` / `string(500)` | **oui** | — | métadonnées justificatif (même patron que `SupplierInvoice.attachment*`, FIN-2) — **nullable en base**, contrôle applicatif « requis » **au moment de `/submit`** seulement (RG-EXP-02.1, CA-1) |
| | ocrExtraction | uuid (FK) | **oui** | — | `ExtractionAttempt` (Ocr) — traçabilité (§0.8) |
| | description | `string(255)` | **oui** | — | — |
| **`Reimbursement`** | id | uuid | non | PK | — |
| | expenseReport | uuid (FK) | non | **unique** | `ExpenseReport` — un seul remboursement possible v1 (§0.6, RG-EXP-05) |
| | date | `date` | non | — | — |
| | amount | `decimal(12,2)` | non | `= report.totalAmount` exactement (validé applicativement, §0.6) | — |
| | method | uuid (FK) | non | — | `MoyenPaiement` (Compta) |
| | reference | `string(64)` | **oui** | — | n° virement/chèque |
| | ledgerEntry | uuid (FK) | non | — | `EcritureComptable` propre au remboursement (§0.6) |
| | reconciliationCode | `string(36)` | **oui** | — | copié depuis `LettrageEcriture.reconciliationCode` |
| | createdAt | `datetime_immutable` | non | — | — |
| | createdBy | uuid (FK) | **oui** | — | `Utilisateur` |

> id = UUID (`symfony/uid`). Rattachement multi-entités : `establishment` **direct** sur `ExpenseReport`
> (ancre de cloisonnement, D6/D8) ; `ExpenseLine`/`Reimbursement` héritent du périmètre via leur parent
> (chaînes de jointure §0.2). Aucune nouvelle stratégie de cloisonnement : ce lot réutilise le patron
> déjà posé par FIN-2 pour la même famille de module.

**Enums** (`App\Finance\ExpenseReport\Enum`, valeurs anglaises D5) : `ExpenseReportStatus` (`Draft =
'draft'`, `Submitted = 'submitted'`, `Approved = 'approved'`, `Rejected = 'rejected'`, `Reimbursed =
'reimbursed'`) — **cinq valeurs, aucune sixième pour « en attente de déversement »** (§0.5).

---

## 2. API (API Platform)

| Ressource / route | Opération | `security:` | Processor/Provider | Groupes sérialisation |
|---|---|---|---|---|
| `ExpenseReport` | `GetCollection`, `Get` | `finance.read` or `finance.expense_report_read_own` or `finance.expense_report_post_to_ledger` or `finance.manage` | — (filtré par `PerimetreFinanceExtension`, §0.2 point 2) | `expense_report:read` |
| `ExpenseReport` | `POST /finance/expense-reports` | `finance.expense_report_submit` | `CreateExpenseReportProcessor` (D8 explicite, §0.2 point 1) | in: `expense_report:write`, out: `expense_report:read` |
| `ExpenseReport` | `PATCH /finance/expense-reports/{id}` | `finance.expense_report_submit` and `is_granted('EMPLOYE_SOI', object.getEmployee())` | même Processor (rejette 409 si `status != draft`) | idem |
| `ExpenseReport` | `POST /finance/expense-reports/extract` | `finance.expense_report_submit` | `ExtractExpenseReceiptProcessor` (§0.8), `read:false`, `input:false`, `output:false` | — (JSON brut) |
| `ExpenseReport` | `POST /finance/expense-reports/{id}/submit` | `finance.expense_report_submit` and `is_granted('EMPLOYE_SOI', object.getEmployee())` | `SubmitExpenseReportProcessor` → `SubmitExpenseReportHandler` (§0.3), `read:true`, `input:false` | out: `expense_report:read` |
| `ExpenseReport` | `POST /finance/expense-reports/{id}/reopen` | `finance.expense_report_submit` and `is_granted('EMPLOYE_SOI', object.getEmployee())` | `ReopenExpenseReportProcessor` (§0.9, `status == rejected` sinon 409) | idem |
| `ExpenseReport` | `POST /finance/expense-reports/{id}/finalize-escalade` | `(finance.expense_report_submit and is_granted('EMPLOYE_SOI', object.getEmployee())) or finance.expense_report_post_to_ledger` | `FinalizeEscaladeExpenseReportProcessor` → `EscaladeExpenseReportResolver` (§0.3.2, confort UX — la commande planifiée reste la garantie), `read:true`, `input:false` | idem |
| `ExpenseReport` | `POST /finance/expense-reports/{id}/post-to-ledger` | `finance.expense_report_post_to_ledger` | `PostToLedgerExpenseReportProcessor` → `ExpenseReportLedgerPoster` (§0.5, rejouable), `read:true`, `input:false` | idem |
| `ExpenseLine` | `GetCollection`, `Get`, `Post`, `Patch`, `Delete` | Lecture : même expression que `ExpenseReport` · Écriture : `finance.expense_report_submit` | `ExpenseLineProcessor` (D8 : `expenseReport` référencé doit appartenir au salarié courant **et** `status == draft`, sinon 409 — RG-EXP-01.1 ; recalcule `totalAmount` du parent) | `expense_line:read` / `:write` |
| `Reimbursement` | `GetCollection`, `Get` | même expression que `ExpenseReport` | — (filtré) | `reimbursement:read` |
| `Reimbursement` | `POST /finance/expense-reports/{id}/reimbursements` | `finance.expense_report_post_to_ledger` | `ReimburseExpenseReportProcessor` → `ReimburseExpenseReportHandler` (§0.6) | in: `reimbursement:write`, out: `reimbursement:read` |

**Filtres** (`ApiFilter(SearchFilter::class, ...)`) : `ExpenseReport` → `status` exact, `employee` exact,
`businessProfile` exact ; `ExpenseLine` → `expenseReport` exact ; `Reimbursement` → `expenseReport`
exact.

**Non exposé par ce lot** : `Delete` sur `ExpenseReport`/`Reimbursement` (aucune suppression — le cycle
de vie passe par `reopen`/refus, jamais par suppression, cohérent RG-SOCLE-07) ; les lignes sont créées
**séparément** de la note (même patron que FIN-2 : `lines` de `ExpenseReport` exposé en lecture seule,
`Groups(['expense_report:read'])` uniquement, jamais en écriture nested) ; aucune permission
`finance.expense_report_approve` ne gate une opération API (§0.4 — usage interne exclusif à
`ServiceAutorisation`).

> **Intégration `api_platform.yaml` — non modifiée par ce lot**, même signalement que FIN-2 (§7 point
> 11) : `mapping.paths` doit inclure `'%kernel.project_dir%/src/Finance/ExpenseReport/Entity'` — à la
> charge de l'intégrateur, en même temps que l'ajout déjà signalé par FIN-2 pour `SupplierInvoice`.

---

## 3. Sécurité & droits

- **Permissions consommées/déclarées par ce lot** (extension de `App\Finance\FinanceModule::
  permissions()`, §0.10) : `finance.expense_report_submit`, `finance.expense_report_read_own`,
  `finance.expense_report_post_to_ledger`, `finance.expense_report_approve` (**défensive uniquement**,
  §0.4 — critique, à ne pas omettre du seed de rôles). Réutilisées, non redéclarées :
  `finance.read`, `finance.manage` (existants dès FIN-2), `autorisation.approuver`/`autorisation.gerer`
  (`App\Autorisation`, pour les endpoints génériques `POST /demandes-escalade/{id}/approuver|rejeter`,
  **inchangés**, ce lot ne les modifie pas).
- **Voters** : **aucun voter propre à ce lot** — réutilise directement
  `App\Personnel\Security\EmployeSoiVoter` (attribut `EMPLOYE_SOI`) dans les expressions `security:`
  d'API Platform, via `object.getEmployee()` (§0.2 point 3). Le filtrage de périmètre large/étroit
  (établissement vs « soi ») est porté par `PerimetreFinanceExtension` (lecture) et les processors dédiés
  (écriture par corps brut) — même séparation de responsabilités que FIN-1/FIN-2.
- **Cloisonnement — gardes explicites, tous échec fermé** :
  1. `CreateExpenseReportProcessor` : `employee` → `EMPLOYE_SOI` (403) ; `establishment` → couvert par un
     `RattachementEmploye` actif de cet `employee` (422) ; `businessProfile` → `couvre($establishment)`
     (422) ; `PerimetreEtablissementVerificateur::verifier($establishment)` (403, §0.2 point 1).
  2. `ExpenseLineProcessor` : `expenseReport` référencé → appartient au salarié courant (`EMPLOYE_SOI`
     sur `expenseReport.getEmployee()`) **et** `status == draft` (409 sinon, RG-EXP-01.1).
  3. `SubmitExpenseReportProcessor`/`ReopenExpenseReportProcessor`/`FinalizeEscaladeExpenseReportProcessor` :
     `EMPLOYE_SOI` (ou permission large pour le finalize, §2) — objet déjà résolu via
     `PerimetreFinanceExtension`, donc déjà dans le périmètre établissement avant même l'évaluation de
     `security:`.
  4. `ReimburseExpenseReportProcessor`/`PostToLedgerExpenseReportProcessor` : `finance.
     expense_report_post_to_ledger` — objet déjà résolu via l'extension (mode « large », un comptable
     n'a jamais besoin d'être l'employé lui-même).
- **Aucun secret manipulé par ce lot** — le justificatif (`receiptUrl`) est une référence externe déjà
  hébergée (§1), jamais transmise en base64 en base ; l'OCR chiffre déjà ses propres clés fournisseur
  (hors périmètre de ce lot, `App\Ocr\Service\ChiffreurApiKeyOcr`).

---

## 4. Migrations

Trois migrations additives, timestamps **après** celles proposées par `plan-supplier-invoices.md`
(`Version20260820090000`…`090300`) — aucune de ces migrations FIN-2 n'existe encore comme fichier réel au
moment de la rédaction de ce plan (vérifié, `app/migrations/` s'arrête à `Version20260819140100`, FIN-1),
mais l'horodatage est choisi **strictement postérieur** par construction pour ne jamais entrer en
conflit d'ordre, quel que soit l'ordre effectif d'implémentation :

- **`Version20260820100000`** — `CREATE TABLE finance_expense_report` (`id BINARY(16) PK`,
  `establishment_id BINARY(16) NOT NULL FK → org_etablissement`, `business_profile_id BINARY(16) NOT
  NULL FK → compta_profil_exploitant`, `employee_id BINARY(16) NOT NULL FK → personnel_employe`,
  `status VARCHAR(16) NOT NULL DEFAULT 'draft'`, `total_amount DECIMAL(12,2) NOT NULL DEFAULT '0.00'`,
  `escalation_request_id BINARY(16) NULL FK → atz_demande_escalade`, `rejection_reason LONGTEXT NULL`,
  `ledger_entry_id BINARY(16) NULL FK → compta_ecriture_comptable`, `submitted_at DATETIME NULL`,
  `approved_at DATETIME NULL`, `reimbursed_at DATETIME NULL`, `created_at DATETIME NOT NULL`,
  `created_by_id BINARY(16) NULL FK → sec_utilisateur`) + `INDEX
  idx_expense_report_establishment_status (establishment_id, status)` + index sur `employee_id`.
- **`Version20260820100100`** — `CREATE TABLE finance_expense_line` (`id BINARY(16) PK`,
  `expense_report_id BINARY(16) NOT NULL FK → finance_expense_report`, `expense_nature_code
  VARCHAR(64) NOT NULL`, `expense_date DATE NOT NULL`, `amount_incl_tax DECIMAL(12,2) NOT NULL`,
  `vat_rate_id BINARY(16) NULL FK → compta_taux_tva`, `amount_excl_tax DECIMAL(12,2) NOT NULL`,
  `vat_amount DECIMAL(12,2) NOT NULL`, `receipt_file_name VARCHAR(255) NULL`, `receipt_mime_type
  VARCHAR(100) NULL`, `receipt_size INT NULL`, `receipt_url VARCHAR(500) NULL`, `ocr_extraction_id
  BINARY(16) NULL FK → ocr_extraction_attempt`, `description VARCHAR(255) NULL`) + index sur
  `expense_report_id`.
- **`Version20260820100200`** — `CREATE TABLE finance_expense_reimbursement` (`id BINARY(16) PK`,
  `expense_report_id BINARY(16) NOT NULL FK → finance_expense_report`, `date DATE NOT NULL`, `amount
  DECIMAL(12,2) NOT NULL`, `method_id BINARY(16) NOT NULL FK → compta_moyen_paiement`, `reference
  VARCHAR(64) NULL`, `ledger_entry_id BINARY(16) NOT NULL FK → compta_ecriture_comptable`,
  `reconciliation_code VARCHAR(36) NULL`, `created_at DATETIME NOT NULL`, `created_by_id BINARY(16)
  NULL FK → sec_utilisateur`) + `UNIQUE INDEX uniq_expense_reimbursement_report (expense_report_id)`
  (§0.6, un seul remboursement v1).

**Down** : les trois migrations sont réversibles (`DROP TABLE`, ordre inverse pour respecter les FK),
rejouables (constitution §7). Aucune donnée existante affectée (tables entièrement nouvelles).

---

## 5. Tests

| Test | Type | Couvre |
|---|---|---|
| `ExpenseReportApiTest::testSalarieSansUtilisateurNePeutPasSoumettre` | Fonctionnel API | §4.3 point ouvert — `Employe.utilisateur = null` → `EMPLOYE_SOI` refuse (403) la création |
| `ExpenseReportApiTest::testLigneSansJustificatifBloqueLaSoumission` | Fonctionnel API | **CA-1** : ligne sans `receiptUrl` → `POST …/submit` refusé, message identifiant la ligne fautive, `status` reste `draft` |
| **`CloisonnementExpenseReportTest::testEtablissementHorsDesRattachementsDeLEmployeRefuse422`** | Fonctionnel API | §0.2 point 1 — `establishment` du corps ne correspond à aucun `RattachementEmploye` de l'employé → 422, aucune note créée |
| `CloisonnementExpenseReportTest::testEmployeAutreUtilisateurRefuse403` | Fonctionnel API | §0.2 point 1 — un salarié tente de créer une note pour l'`Employe` d'un collègue → 403 |
| **`CloisonnementExpenseReportTest::testSalarieNeVoitQueSesPropresNotes`** | Fonctionnel API | **CA-7** : titulaire de `finance.expense_report_read_own` seul → collection filtrée strictement sur son `Employe` ; un autre salarié authentique (même établissement) n'apparaît jamais |
| `CloisonnementExpenseReportTest::testComptableVoitToutesLesNotesDeLEtablissement` | Fonctionnel API | §0.2 point 2 — titulaire de `finance.expense_report_post_to_ledger` (sans `read_own`) voit l'ensemble établissement |
| **`ServiceAutorisationIntegrationTest::testPermissionApprouveManquanteRefuseMemeSousPlafond`** | Fonctionnel/Unit | **§0.4, régression critique** — un rôle sans `finance.expense_report_approve` (mais avec `finance.expense_report_submit`) voit sa soumission `Refuse` dès l'étape 1 de `ServiceAutorisation`, **même pour un montant très inférieur à tout plafond** — preuve explicite de la nécessité du seed §0.4 |
| `SubmitExpenseReportHandlerTest::testSousPlafondApprouveEtDeversementDeclenche` | Fonctionnel API | **CA-2** : note 40 €, plafond 100 € → `status = approved` directement, `ledgerEntry` non nul (mapping complet) |
| **`SubmitExpenseReportHandlerTest::testAuDelaDuPlafondEscaladeCreeeNoteResteSubmitted`** | Fonctionnel API | **CA-3 (partie 1)** : note 250 €, plafond 100 € escalade permise → réponse HTTP **succès** (pas 403 — divergence assumée du patron M2, §0.3.1), `status` reste `submitted`, `escalationRequest` renseigné, `DemandeEscalade.statut = en_attente` |
| `SubmitExpenseReportHandlerTest::testAucuneEscaladePossibleRejetteImmediatement` | Fonctionnel API | RG-EXP-04, branche `Refuse` — `status = rejected`, motif explicite |
| **`EscaladeExpenseReportResolverTest::testApprobationSuperviseurFinaliseApresRejeuTokenUsageUnique`** | Fonctionnel/Unit | **CA-3 (partie 2)** — §0.3.2 : après `POST /demandes-escalade/{id}/approuver`, appel du resolver (via commande **ou** endpoint) → `status = approved`, `ledgerEntry` posé, `expense_report.approved` émis (`actor = null` si via commande) ; **un second appel** au resolver ne relève **pas** d'exception mais ne fait rien de plus (jeton déjà consommé, `dateRejeu` déjà posé, idempotence du point de vue de l'appelant) |
| `EscaladeExpenseReportResolverTest::testRejetSuperviseurRejetteLaNoteAvecMotif` | Fonctionnel/Unit | **CA-3 (partie 3)** — rejet superviseur → `status = rejected`, `rejectionReason` = motif du rejet, **aucun appel** à `ServiceAutorisation` (§0.3.2) |
| `EscaladeExpenseReportResolverTest::testAutoApprobationSuperviseurImpossible` | Fonctionnel API | §7 spec, réutilise RG-AUTZ-13 **à l'identique** (`GestionnaireEscalade::garderTraitable()`, non modifié par ce lot) — le superviseur = auteur de la note ne peut pas l'approuver, 403 dès `POST /demandes-escalade/{id}/approuver` |
| `FinaliserEscaladesExpenseReportCommandTest::testCommandeTraiteLesNotesEnAttenteSansContexteHttp` | Unit/Fonctionnel (CLI) | §0.3.2 — la commande fonctionne **sans requête HTTP active** (`ContexteEtablissement::idActif()` retourne `null` dans ce contexte, sans faire échouer le rejeu, §0.3.2 vérifié empiriquement) |
| **`ExpenseReportLedgerPosterTest::testMappingChargeIncompletBloqueDeversementSansAnnulerLApprobation`** | Fonctionnel API | **CA-5** : note déjà `approved` (mapping absent) → `POST …/submit` réussit son approbation métier, `ledgerEntry` reste `null`, **statut reste `approved`** (pas de retour à `submitted`/`rejected`) |
| `ExpenseReportLedgerPosterTest::testDeversementRejouableApresCorrectionDuMapping` | Fonctionnel API | §7 cas limite spec — mapping complété a posteriori → `POST …/post-to-ledger` réussit, `ledgerEntry` posé, **aucune nouvelle évaluation `App\Autorisation`** redemandée |
| `ExpenseReportLedgerPosterTest::testLigneSansTauxTvaIntegralementDebiteeSansDeduction` | Unit | §0.5 point 3 — pas de taux « emprunté » au mapping |
| `ExpenseReportLedgerPosterTest::testLigneAvecTauxTvaVentileHtEtTva` | Unit | §0.5 point 3 |
| `ReimburseExpenseReportHandlerTest::testRemboursementTotalPasseAReimbursedEtLettre` | Fonctionnel API | **CA-4** : note 300 €, remboursement 300 € → `status = reimbursed`, 2 `LettrageEcriture` créées, même `reconciliationCode` |
| `ReimburseExpenseReportHandlerTest::testMontantDifferentDuTotalRejete422` | Fonctionnel API | §0.6 — pas de remboursement partiel v1 |
| `ReimburseExpenseReportHandlerTest::testRemboursementAvantDeversementRefuse409` | Fonctionnel API | §0.6 — `ledgerEntry === null` (mapping pas encore corrigé) → remboursement impossible |
| `ReimburseExpenseReportHandlerTest::testDeuxiemeRemboursementRefuseParContrainteUnique` | Fonctionnel API | §1 — contrainte `UNIQUE(expense_report_id)` |
| `ExpenseReportReopenTest::testRejeteCorrigeEtReSoumisRepartSurUnCycleNeuf` | Fonctionnel API | **CA-6** : `reopen()` efface `escalationRequest`/`rejectionReason` ; une nouvelle soumission au-delà du plafond crée une **nouvelle** `DemandeEscalade` distincte de la précédente |
| `ExpenseReportOcrTest::testExtractionPreRemplitSansValiderAutomatiquement` | Fonctionnel API | RG-EXP-03 — `POST …/extract` ne crée ni ne modifie aucune `ExpenseLine` |
| `ExpenseReportOcrTest::testOcrNonConfigureModeDegradeSaisieManuelleFonctionne` | Fonctionnel API | Mode dégradé — `DocumentExtractor` renvoie `failed`, saisie manuelle inchangée |
| **`ExpenseReportEventTest::testSubmittedEmisAvantEvaluationAutorisation`** | Unit | **CA-8** — ordre d'émission exact (§0.3, RG-EXP-01.1) |
| **`EventTenantExpenseReportTest::testTenantDeriveDeLEtablissementNoteJamaisDuContexte`** | Fonctionnel/Unit | **D6** — assertion explicite demandée par la mission, même patron que FIN-2 §0.6 |
| `FinanceModuleExtensionTest::testPermissionsEtEvenementsExpenseReportPresents` | Unit | §0.10 — les 4 permissions et 3 événements de cette brique figurent dans `FinanceModule::permissions()`/`eventsEmitted()`, **sans supprimer** ceux déjà ajoutés par FIN-2 (test de non-régression sur le fichier partagé) |

---

## 6. Tâches (voir tasks-expense-reports.md)

- **T1** — Enum `ExpenseReportStatus` + entités `ExpenseReport`/`ExpenseLine` (sans API Platform ni
  processor) + migrations `Version20260820100000`/`…100100` + tests unitaires d'entité (calculs HT/TVA
  par ligne, totaux).
- **T2** — `App\Finance\ExpenseReport\Doctrine\PerimetreFinanceExtension` (double mode « soi »/large,
  §0.2 point 2) + `#[ApiResource]` lecture seule (`GetCollection`/`Get`) + tests de cloisonnement en
  lecture (CA-7).
- **T3** — `CreateExpenseReportProcessor` (D8 explicite §0.2 point 1, réutilise `EMPLOYE_SOI`,
  `RattachementEmploye::estActifA()`, `ProfilExploitant::couvre()`, `PerimetreEtablissementVerificateur`)
  + `ExpenseLineProcessor` (création/édition/suppression, garde `status == draft`, recalcul HT/TVA/total
  parent) + tests (dépend de T1/T2, de FIN-1/`App\Personnel` déjà livrés).
- **T4** — `ExtractExpenseReceiptProcessor` (§0.8, `DocumentKind::ExpenseReceipt`) + tests OCR (dégradé)
  — dépend de T3, `App\Ocr` (déjà livré).
- **T5** — Seed/fixtures de test : `Permission finance.expense_report_approve` + `OperationSensible`
  (`code = finance.expense_report_approve`) + `LimiteAutorisation` de test + `RequeteAutorisation`
  intégration — `SubmitExpenseReportHandler` (§0.3, branches `Autorise`/`EscaladeRequise`/`Refuse`, **sans**
  `EscaladeRequiseException`) + `SubmitExpenseReportProcessor` + tests (CA-1, CA-2, CA-3 partie 1, test
  critique §0.4) — dépend de T3, `App\Autorisation` (déjà livré).
- **T6** — `ExpenseReportLedgerPoster` (§0.5, `ExpenseAccountMappingGuard`, `CompteLookupService`,
  `DirectLedgerEntryBuilder`, journal `NDF`) branché dans la branche `Autorise` de T5 +
  `PostToLedgerExpenseReportProcessor` (rejeu) + tests (CA-5) — dépend de T5, FIN-1.
- **T7** — `EscaladeExpenseReportResolver` (§0.3.2) + commande `finance:expense-reports:resoudre-
  escalades` + `FinalizeEscaladeExpenseReportProcessor` (endpoint) + tests (CA-3 parties 2/3, test CLI
  sans contexte HTTP) — dépend de T5, T6.
- **T8** — Entité `Reimbursement` + migration `…100200` + `ReimburseExpenseReportHandler` (§0.6,
  `LettrageHandler::lettrerGroupe()`, journal `BNQ`) + `ReimburseExpenseReportProcessor` + tests (CA-4) —
  dépend de T6.
- **T9** — `ReopenExpenseReportProcessor` (§0.9) + tests (CA-6) — dépend de T5.
- **T10** — Émission des 3 événements (§0.7) dans T5/T7/T8 (branché rétroactivement, jalon de revue
  distinct) + tests d'événement (CA-8, D6) — dépend de T5, T7, T8.
- **T11** — Extension de `App\Finance\FinanceModule` (§0.10 : +4 permissions, +3 événements, +2
  features) + `FinanceModuleExtensionTest` — **coordination explicite avec FIN-2** (§7 point 3) : à
  merger en dernier, après vérification de l'état du fichier partagé au moment du merge réel.
- **T12** — Revue de cohérence (constitution §8) : `GET /health`, rejeu complet
  `App\Tests\Compta\*`/`App\Tests\Personnel\*`/`App\Tests\Autorisation\*`/`App\Tests\Ocr\*` existants
  (non-régression — ce lot ne modifie aucun fichier de ces modules) et, si déjà livré à ce stade,
  `App\Tests\Finance\SupplierInvoice\*` (FIN-2, non-régression croisée sur le fichier partagé
  `FinanceModule`) ; vérification qu'aucun libellé utilisateur n'est en dur (i18n — clés
  `finance.expense_report.*`) ; intégration `mapping.paths` **signalée mais non faite** (§2, à la charge
  de l'intégrateur).

---

## 7. Risques / à valider

1. **[CRITIQUE] Premier usage non-HTTP de `ServiceAutorisation::evaluerRejeu()`** (§0.3.2) — le seul
   patron documenté (`plan-autorisation.md`) est un **rejeu client** (l'appelant HTTP d'origine
   re-soumet sa requête avec le jeton). Ce plan invoque la même méthode depuis une **commande CLI**
   (`finance:expense-reports:resoudre-escalades`) et un endpoint dédié, sans jamais passer par
   `EscaladeRequiseException`. Techniquement sûr (vérifié dans le code : la branche rejeu ne dépend ni de
   `Security::isGranted()` ni de `ContexteEtablissement`), mais c'est une **extension d'usage non
   anticipée par la spec `App\Autorisation`** — à confirmer explicitement avec le propriétaire de ce
   module avant merge. Si ce design est refusé, l'alternative (moins satisfaisante côté UX : la note
   reste `submitted` indéfiniment tant que le salarié ne rappelle pas lui-même `/submit` avec le jeton,
   à la manière de M2) devra être documentée comme comportement de repli.
2. **Divergence assumée par rapport au patron M2 (`EscaladeRequiseException`)** (§0.3.1) — `/submit`
   renvoie un **succès HTTP** même en cas d'escalade requise (la note reste `submitted`, ce n'est pas un
   échec de la requête), contrairement à M2 qui traite ce cas comme un 403. Choix **délibéré et
   spec-conforme** (RG-EXP-04 littéral), mais à signaler pour qu'un futur agent ne « corrige » pas ce
   comportement en le jugeant incohérent avec M2 sans relire ce §.
3. **Fichier partagé `App\Finance\FinanceModule` modifié par FIN-2 et FIN-3 en parallèle** (§0.10) —
   coordination de merge nécessaire (concaténation de listes, faible risque technique mais réel).
   Recommandation : le second lot mergé relit l'état du fichier avant d'étendre les tableaux, jamais un
   merge automatique aveugle.
4. **`PerimetreFinanceExtension` dupliquée par brique** (`SupplierInvoice` chez FIN-2, `ExpenseReport`
   ici) — même risque déjà signalé par FIN-2 (§7 point 1 de son plan, appliqué ici à
   `PerimetreEtablissementVerificateur`) : deux classes quasi identiques. Recommandation : promotion
   future vers un namespace partagé `App\Finance\Doctrine\PerimetreFinanceExtension` paramétrable par
   entité, dans un lot de nettoyage dédié — hors périmètre de ce plan (éviter le risque de régression
   croisée sur un lot déjà livré).
5. **Absence de 6ᵉ statut pour « approuvée, en attente de déversement »** (§0.5) — ce plan retient
   `ledgerEntry === null` sur une note `approved` comme seul marqueur technique, la spec ne listant que
   5 valeurs de statut. Si le métier souhaite un statut explicitement visible côté UI (plutôt qu'un champ
   technique interprété), un changement mineur (ajout d'une valeur d'enum) resterait localisé à ce lot
   sans impact structurel.
6. **Pas de taux de TVA « par défaut » emprunté au mapping quand `ExpenseLine.vatRate` est absent**
   (§0.5 point 3) — choix conservateur (aucune déduction plutôt qu'un taux deviné), cohérent avec
   RG-M6-05 déjà appliqué par FIN-2, mais **non tranché littéralement** par `spec-expense-reports.md` —
   à valider avec un expert-comptable, en particulier pour les catégories à taux fixe bien identifié
   (ex. forfait repas) où le `deductibleVatRate` du mapping pourrait légitimement servir de valeur par
   défaut suggérée (jamais appliquée automatiquement) à l'écran de saisie.
7. **Journal `NDF` non seedé** dans `ComptaFixtures` — même situation non bloquante déjà signalée pour
   `OD` (FIN-1) et `ACH`/`BNQ` (FIN-2) : aucun blocage technique, mais à ajouter aux fixtures de ce lot
   et à documenter comme configuration initiale recommandée.
8. **Contrainte « note déjà déversée » avant tout remboursement** (§0.6) — nécessaire mécaniquement
   (le lettrage exige une ligne 421 déjà scellée) mais **non énoncée littéralement** par
   `spec-expense-reports.md`, qui décrit seulement « une note `approved` reçoit un remboursement » sans
   mentionner explicitement le déversement comme préalable. À confirmer que cette lecture (déversement
   avant remboursement) correspond à l'intention métier — l'alternative (remboursement déclaratif sans
   lien comptable immédiat, lettré plus tard) romprait le patron FIN-1/FIN-2 déjà éprouvé.
9. **`StatutEscalade::Expiree` traité comme un rejet pour `ExpenseReport`** (§0.3.2) — hypothèse de ce
   plan, non couverte littéralement par la spec (qui ne mentionne que « approuvée »/« rejetée »). À
   confirmer avec le métier ; alternative possible : notifier le salarié pour qu'il relance une nouvelle
   demande plutôt que de rejeter automatiquement.
10. **`PerimetreEtablissementVerificateur` lève `AccessDeniedHttpException` (403), pas 404** — comportement
    réel du service (vérifié dans le code), à ne pas confondre avec la description « échec fermé 404 »
    employée par endroits dans `plan-supplier-invoices.md` pour d'autres gardes de ce même lot FIN-2 (qui,
    elles, utilisent effectivement 404 côté FIN-2 pour des `find()` bruts distincts). Ce plan documente le
    code réel de la dépendance partagée pour éviter un test écrit sur une hypothèse fausse.
11. **`api_platform.yaml` `mapping.paths`** — ce lot ne le modifie pas, même signalement que FIN-2 (§7
    point 5 de son plan) : à la charge de l'intégrateur, pour les deux lots simultanément.
12. **`FinanceModule::dependencies()` laissé à `[]`** — même décision transitoire que FIN-2 (§7 point 7
    de son plan), pour la même raison (`personnel`/`autorisation`/`compta` n'implémentent pas encore
    `ModuleManifest`) ; à revoir conjointement avec FIN-2 dès que ces modules legacy sont rétrofités
    (tâche de coordination C5).
13. **Garde `PerimetreEtablissementVerificateur` potentiellement redondante** pour un salarié soumettant
    sur son propre établissement de rattachement (§0.2 point 1) — conservée par cohérence avec le
    patron FIN-1/FIN-2, pas un risque de sécurité (défense en profondeur), simplement une note de
    lisibilité pour un futur lecteur qui se demanderait pourquoi trois contrôles successifs sur le même
    établissement.
14. **`X-Etablissement` de la requête `/submit` doit correspondre à `report.establishment`** (§0.7) —
    sinon un salarié multi-établissement pourrait se voir refuser une soumission légitime pour cause de
    périmètre (`RG-AUTZ-05`), **avant même** toute considération de plafond. Point d'intégration front à
    documenter explicitement dans le contrat d'API (non couvert par les tests fonctionnels backend seuls,
    car un client de test bien écrit alignera naturellement l'en-tête — un test dédié
    `SubmitExpenseReportHandlerTest::testEnTeteEtablissementDifferentDeLaNoteRefusePerimetre` est
    recommandé pour rendre ce piège visible plutôt que silencieusement absent de la suite).
15. **⚠ HORS BACKLOG, remboursement partiel non retenu v1, dissociation approbation/déversement,
    rattachement à un établissement unique** — hypothèses déjà actées et non retranchées par la spec
    elle-même (§7/§9 de `spec-expense-reports.md`) ; ce plan les reprend sans les re-trancher, cohérent
    avec la consigne « ne pas re-trancher » de la constitution.
