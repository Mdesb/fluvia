# Faits vérifiés — chantier RH du module Personnel

**Phase :** Spec (SDD) — matière première pour `specs/spec-personnel-rh.md`
**Établi le :** 2026-09-08, par l'agent `chercheur`, en lecture seule sur `feature/personnel-rh`
**Discipline :** chaque fait porte sa source `chemin:ligne`. Toute conclusion d'absence est adossée à
un témoin positif (la même commande trouve un objet comparable qui existe).

---

## FIL 1 — Gestion documentaire (module `Dms`)

### 1.1 Un Document ne peut PAS être rattaché à une entité arbitraire — VERIFIED

`app/src/Dms/Entity/Document.php:103-172` — liste complète des colonnes : `id`, `establishment`
(ManyToOne `Etablissement`, `nullable: false`, l. 113), `category` (l. 117), `title` (l. 121),
`tags` (json `list<string>`, l. 126), `sourceModule` (string(60) nullable, l. 130), `currentVersion`
(l. 135), `status`, `retentionPolicy`, `retainUntil`, `createdBy`, `createdAt`, `updatedAt`,
`deletedAt`.

**Aucun champ de sujet** : pas de `subjectType`/`subjectId`, pas de relation polymorphe, pas de
discriminant. `sourceModule` est une chaîne libre **en lecture seule** (`document:read` uniquement,
l. 129) : elle nomme le module d'origine, jamais l'objet métier. `tags` est un tableau JSON libre,
exposé en écriture, sans contrainte serveur.

**Témoin positif du patron réellement employé** : `app/src/Offre/Entity/ProductPhoto.php:86-92` —
l'entité métier porte un ManyToOne vers son parent (`produit`) **plus** `private ?Uuid $documentRef`
documenté « Référence libre vers `App\Dms\Entity\Document` (D2) ». C'est le seul consommateur du DMS
hors module : la recherche `Dms\Entity\Document|DocumentStore|Dms\Enum\DocumentCategory` hors
`app/src/Dms/` rend exactement 5 lignes, toutes dans `App\Offre`.

### 1.2 Catégories — VERIFIED

`app/src/Dms/Enum/DocumentCategory.php:11-21` : 8 cas fixes, dont `Contract = 'contract'` et
`HrDocument = 'hr_document'`. Docblock : « catalogue fixe v1 … pas un référentiel configurable par
établissement (arbitrage D18 pt.7) ». Ajouter une catégorie RH = modifier l'enum, pas une donnée.

### 1.3 Téléversement — VERIFIED

- Route `POST /documents`, `inputFormats: ['multipart' => ['multipart/form-data']]`, `input: false`,
  `read: false`, `security: dms.write` — `Document.php:56-66`.
- Corps lu à la main : `app/src/Dms/Processor/UploadDocumentProcessor.php`. Champs acceptés :
  `category` (obligatoire, 422 sinon), `title` (obligatoire), `tags` (JSON, repli CSV),
  `sourceModule` (string libre), `file`.
- Taille max : `DMS_MAX_UPLOAD_BYTES` (l. 36), contrôlé l. 77-78 → 422 `dms.error.file_too_large`.
  `app/.env:197` = `104857600` (100 Mio) ; identique dans `app/.env.test:8`.
- MIME : `$file->getMimeType()` (finfo sur le contenu), jamais le type annoncé par le client —
  `UploadDocumentProcessor.php:100` (audit 06/09, constat 6).
- Établissement : **dérivé serveur** de `ContexteEtablissement::etablissementActif()` (l. 47), 422 si
  irrésolu. Jamais un champ client. Immuable ensuite (RG-DMS-04, `DocumentIntegrityListener`).
- Voie PHP interne : `App\Dms\DocumentStore::store()` avec `StoreDocumentRequest` qui porte
  `establishment` **explicitement** — le module appelant fournit son périmètre.
  ⚠ Le docblock de `DocumentStore` dit que `readContent()` **ne revérifie aucune permission `dms.*`** :
  le module appelant est responsable de son propre contrôle d'accès.

### 1.4 Cloisonnement — VERIFIED

- Lecture : `app/src/Dms/Doctrine/DmsScopeExtension.php:30-34` (`CHAINES`) couvre `Document`
  (direct), `DocumentVersion` (via `document`), `DocumentPublicLink` (via `document`).
  `RetentionPolicy` est volontairement hors cloisonnement (catalogue global).
- L'axe est **l'établissement ACTIF** (en-tête `X-Etablissement`), pas le périmètre du lecteur —
  bascule du 28/08. Sans établissement actif : `andWhere('1 = 0')`.
- Écritures et opérations custom : `app/src/Dms/Security/DmsScopeGuard.php` → `verify()` lève
  **404, jamais 403** (RG-DMS-03, indiscernabilité).

### 1.5 Versionnage exploitable pour « contrat + avenants » ? — VERIFIED, avec réserve dirimante

- `DocumentVersion` est append-only : `versionNumber`, `previousVersion` (chaînage), `fileHash`,
  `sizeBytes`, `mimeType`, `originalFilename`, `uploadedBy`, `uploadedAt`, `purgedAt`. Aucun
  `Post`/`Patch`/`Delete` exposé.
- `app/src/Dms/Service/ReplaceVersionHandler.php` : verrou pessimiste sur la ligne `Document`,
  `versionNumber = courant + 1`, `previousVersion` = la précédente, puis bascule de
  `Document.currentVersion`. Contrainte `UNIQUE(document, versionNumber)` en filet.
- ⚠ **`POST /documents/{id}/replace-version` n'accepte que le fichier.** Aucune métadonnée par
  version : pas de date d'effet, pas de libellé, pas de motif, pas de signataire. Une version n'a que
  son numéro et son horodatage de dépôt. **Un avenant modélisé comme une version serait indiscernable
  d'une simple correction de scan.**

### 1.6 Rétention — VERIFIED

Catalogue fixe (`RetentionPolicy`, aucun CRUD exposé). Deux politiques semées :
`fr_accounting_10y` (120 mois, `accounting_piece`) et **`fr_hr_5y` (60 mois, `hr_document`)** —
`app/src/Dms/DataFixtures/DmsFixtures.php:71-72`, `app/migrations/Version20260822090000.php:136-139`.
`UploadDocumentHandler` applique automatiquement la politique par défaut de la catégorie et pose
`retainUntil = aujourd'hui + N mois`. La suppression logique est refusée (409) tant que la rétention
est active.
⚠ Commentaire des fixtures : « valeurs légales proposées **par analogie**, à confirmer par un expert
compta/RH avant mise en production ».

### 1.7 Opérations API et ce que le front appelle déjà — VERIFIED

Exposé : `GetCollection`/`Get` (`dms.read`), `POST /documents` (`dms.write`),
`PATCH /documents/{id}` (`dms.write` — titre/catégorie/tags seulement),
`POST /documents/{id}/replace-version` (`dms.write`), `POST /documents/{id}/retention`
(`dms.manage_retention`), `DELETE` (`dms.delete`), `POST /documents/{documentId}/public-links` +
`POST /public-links/{id}/revoke` (`dms.manage_public_link`), et la route hors API Platform
`GET /dms/documents/{id}/download` (`app/src/Dms/Controller/DocumentDownloadController.php:54`,
garde `dms.read` l. 57, `?version=<uuid>` accepté, 410 si purgée).

Front : `frontend/src/api/client.js:833-856` expose 9 appels. Écran `frontend/src/pages/Documents.jsx`
(763 lignes), branché `frontend/src/App.jsx:31` et `:535`, avec le libellé « Document RH » pour
`hr_document` (`Documents.jsx:32`).
⚠ L'upload front n'envoie **que** `file`, `title`, `category` — `Documents.jsx:148-153`. Ni `tags`,
ni `sourceModule`.

---

## FIL 2 — Comptes utilisateurs

### 2.1 Création aujourd'hui — VERIFIED

- Entité `app/src/Securite/Entity/Utilisateur.php`, table `sec_utilisateur`. Opérations
  `GetCollection`/`Get`/`Post`/`Patch` toutes gardées `securite.gerer` (l. 38-46), plus
  `/utilisateurs/{id}/{suspendre,reactiver,reinviter}` et 4 routes MFA.
- `app/src/Securite/State/UtilisateurProcessor.php` : si `motDePasseClair` est fourni, il est vérifié
  (`PasswordPolicy`), haché, compte **actif** immédiatement ; sinon jeton d'invitation haché (72 h),
  `statut = invite`, `InvitationMailer` part.
- Écran `frontend/src/pages/Parametres.jsx:1532-1544` : `api.creerUtilisateur({email, nom,
  motDePasseClair?})` puis, **si** un rôle et un établissement sont choisis,
  `api.creerAffectation({utilisateur, role, etablissement})`. L'affectation est facultative.
- `AccountKind` (`app/src/Securite/Enum/AccountKind.php`) `operator`/`customer`, posée par le chemin
  de création, **jamais** par un PATCH. Exposée en lecture seule.
- Droits : couple `module × action` en base (`Permission`), agrégé par
  `CalculateurDroits::codesEffectifs($utilisateur, $etablissementId)` à partir des `Affectation`
  (utilisateur × rôle × établissement) ; `PermissionVoter` sert `is_granted('PERM', 'x.y')`.

### 2.2 Aucun code ne crée un Utilisateur depuis un Employe, ni l'inverse — VERIFIED

`setUtilisateur|getUtilisateur` dans `app/src/Personnel` rend 4 lignes : `Employe.php:120,125` (les
accesseurs), `EmployeSoiVoter.php:37` (lecture), `PersonnelFixtures.php:324` (qui pose une
`Affectation`, pas un lien Employé→Utilisateur). Aucun processor, aucun handler, aucune commande.

Front : `frontend/src/pages/Personnel.jsx:541-548` — `EmployeModal` envoie `{nom, prenom, poste,
typeContrat, matricule?, dateEntree}`. **Le champ `utilisateur` n'est jamais renseigné par un écran**,
alors qu'il est bien dans le groupe `employe:write` (`Employe.php:70-72`).

*Témoin positif* : `findOneBy(['utilisateur' => …])` trouve 9 sites ailleurs (Boutique ×5, Support ×2,
Finance ×1, Personnel ×1) — la commande n'est pas muette.

⚠ **Aucun `new Employe(` dans `app/src`** (0 occurrence). *Témoin positif* : la même chaîne trouve
5 fichiers sous `app/tests`. Conséquence : **aucune fixture ne crée d'employé** — la base de
démo/préprod n'en contient aucun issu du semis.

### 2.3 Un employé sans compte : ce que ça lui interdit — VERIFIED

`app/src/Personnel/Security/EmployeSoiVoter.php:37-40` : si `Employe.utilisateur === null`,
`EMPLOYE_SOI` rend **false**, sans exception.

- `GET /api/employes/{id}` en propre, `GET` d'une `Qualification`/`Absence`/`AffectationTravail`/
  `BadgeStaff` en propre : refusés.
- `personnel.lire_soi` est inopérant : `PerimetrePersonnelExtension::restreindreParEmployeSoi`
  (l. 235) cherche l'`Employe` par `utilisateur` et pose `1 = 0` s'il n'y en a pas.
- **Notes de frais** : `app/src/Finance/ExpenseReport/Entity/ExpenseReport.php:55,70,77,84` exigent
  `is_granted('EMPLOYE_SOI', object.getEmployee())` pour soumettre/modifier/retirer. Un employé sans
  compte ne peut pas avoir de note de frais utilisable.
- En revanche il **peut** avoir une fiche, des rattachements, des qualifications, un planning et un
  badge staff : `BadgeStaff` et `AffectationTravail` ne dépendent pas de `utilisateur`.

Conforme à `specs/personnel/spec-personnel.md:95-99` (« ⚠ HYPOTHÈSE — Un Employé n'a pas
obligatoirement de compte Utilisateur ») et au docblock de `Employe.php` (« agent d'entretien… fiche
autonome, sans connexion possible »).

---

## FIL 3 — Cloisonnement du module Personnel

### 3.1 Sur quoi repose le périmètre — VERIFIED

**`Employe` ne porte aucun `Etablissement`** (lecture intégrale de `Employe.php` : aucun import ni
propriété). Le périmètre d'un employé est **entièrement dérivé de ses `RattachementEmploye`**.

`app/src/Personnel/Doctrine/PerimetrePersonnelExtension.php` :

- `CHEMINS_DIRECTS` (l. 50-56) : `RattachementEmploye`, `CreneauTravail`, `BadgeStaff` →
  `{root}.etablissement` ; `AffectationTravail` → via `creneauTravail` ; `PorteeAccesEmploye` → via
  `badgeStaff`.
- `CIBLES_VIA_EMPLOYE` (l. 59) : `Employe`, `Qualification`, `Absence` — **multi-site**, visibles si
  (a) l'employé n'a **aucun** rattachement, **ou** (b) il en a un sur l'établissement **actif**
  (l. 161-195).
- Cas (a) pour `Employe` : réservé aux détenteurs de `personnel.gerer_employe` sur l'établissement
  actif (`orphelinReserveAuxGestionnaires`, l. 199-211), sinon `1 = 0`. Motif écrit : la visibilité
  inconditionnelle d'un orphelin « exposait des fiches RH à des utilisateurs totalement étrangers ».
- `CIBLES_LIRE_SOI` (l. 62) : `Qualification`, `CreneauTravail`, `AffectationTravail` — si
  l'utilisateur a `lire_soi` **sans** `lire`, filtrage par son propre `Employe`.
- L'axe est **l'établissement actif** partout ; `1 = 0` si aucun actif.

Défense en profondeur pour les écritures qui résolvent un `Employe` par UUID brut :
`app/src/Personnel/Security/PerimetreEmployeVerificateur.php` — visible si **aucun rattachement**,
sinon il faut une `Affectation` de l'agent sur l'un des établissements de rattachement.
⚠ Ce vérificateur raisonne sur **l'ensemble des affectations** de l'agent, pas sur l'établissement
actif — divergence d'axe avec l'extension.

Pour les écritures qui ciblent un établissement du corps :
`app/src/Personnel/Security/EtablissementCibleVerificateur.php` — autorise si cible == actif, **ou**
si l'agent détient la permission demandée directement sur la cible. Appelé par
`EmissionBadgeStaffProcessor.php:69`, `AffecterEmployeProcessor.php:70`,
`CreerCreneauTravailProcessor.php:60`.

### 3.2 RattachementEmploye vs PorteeAccesEmploye — VERIFIED (deux objets sans rapport)

|  | `RattachementEmploye` | `PorteeAccesEmploye` |
|---|---|---|
| Table | `personnel_rattachement` | `personnel_portee_acces` |
| Attache | `employe` + **`etablissement`** (tous deux `nullable: false`) | `badgeStaff` (OneToOne unique) |
| Porte | `posteLocal`, `debut`, `fin` (+ `estActifA()`) | `espacesAutorises` (ManyToMany `EspaceAcces`), `modeHoraire`, `margeAvantApres` |
| Sens | **RH / organisationnel** : ouvre l'éligibilité au planning et au badge sur un site | **physique** : quelles portes le badge ouvre, et quand |
| API | `GetCollection`/`Get` (`personnel.lire`), `Post`/`Patch`/`Delete` (`personnel.gerer_employe`) | **lecture seule** ; créée en side-effect de `EmissionBadgeStaffHandler` |

`specs/personnel/spec-personnel.md:105-113` (RG-PERSO-09) : le rattachement RH et l'`Affectation`
socle « ne se confondent pas » — le premier ouvre l'éligibilité planning/badge sur le site, la
seconde ouvre l'accès **au logiciel**.

### 3.3 Ce qu'un écran de rattachement doit respecter — VERIFIED

1. **`etablissement` est exposé en écriture** (`rattachement:write`, `RattachementEmploye.php:57-61`).
   C'est la classe de défaut D41. Le rattrapage est le décorateur global
   `app/src/Platform/Security/EstablishmentScopeWriteGuard.php:49` qui confronte `getEtablissement()`
   au périmètre atteignable et lève **404** sinon. `RattachementEmploye` est couvert (getter français)
   et figure dans `bin/etablissement-ecrivable.ligne-de-base.json:117-120` (`"voie": "groupe"`) —
   dette gelée et connue, protégée par le décorateur.
2. **`employe` est aussi exposé en écriture** et `RattachementEmploye` n'a **aucun processor**. Le
   décorateur ne regarde que `getEtablissement()`. **Rien ne vérifie que l'`Employe` désigné est dans
   le périmètre de l'appelant** — `PerimetreEmployeVerificateur` n'est pas appelé ici (ses 3 seuls
   appelants sont les processors d'absence/affectation/badge).
3. **Le trou par construction** : `PerimetreEmployeVerificateur::estDansLePerimetre` rend `true` pour
   un employé **sans aucun rattachement**. Un écran qui pose le premier rattachement d'un employé
   orphelin travaille donc dans la seule zone où le recoupement est inopérant — c'est précisément
   l'état qu'un écran de rattachement manipule.
4. ⚠ **Piège de nommage D5** : `PerimetrePersonnelExtension` filtre sur `{root}.etablissement`, écrit
   en dur en français (l. 51-55). Toute entité RH **neuve** nommée en anglais (`establishment`)
   sortirait du filtre. Le garde-fou n°28 (`bin/garde-fou-champ-cloisonnement.php`) existe pour ça :
   « Deux règles du dépôt se contredisent sur ce nom précis, et la sécurité doit gagner ».
5. ⚠ **Angle mort du décorateur global** : `EstablishmentScopeWriteGuard.php:68` teste
   `method_exists($data, 'getEtablissement')` — **le nom français uniquement**. Aucune occurrence de
   `getEstablishment` dans `app/src/Platform` (*témoin positif* : `getEtablissement` y rend 5 lignes).
   Une entité RH neuve écrite en anglais (comme `Dms\Document` ou `Finance\ExpenseReport`)
   **échapperait silencieusement** au garde D41 et devrait appeler
   `EstablishmentScopeAsserter::assertReachable()` à la main.

---

## FIL 4 — Permissions du module

### 4.1 Les 8 permissions `personnel.*` réellement déclarées — VERIFIED

Source d'autorité : `app/migrations/Version20260817150200.php:20-30` (INSERT IGNORE dans
`sec_permission`), en miroir dans `app/src/Personnel/DataFixtures/PersonnelFixtures.php:93`.

| Permission | Ce qu'elle garde |
|---|---|
| `personnel.lire` | Toutes les lectures du module (`Employe:36,37`, `RattachementEmploye:32,33`, `PorteeAccesEmploye:33,34`, `Qualification:33,34`, `Absence:36,37`, `CreneauTravail:40,41`, `AffectationTravail:34,35`, `BadgeStaff:38,39`, `RosterHebdomadaire:24`, `DeclarationIncidentBadge:26,31`). Aussi consommée par `Project`/`ProjectTask` en lecture. |
| `personnel.gerer_employe` | `Employe` Post/Patch (`:38,39`), `/suspendre` (`:44`), `/reactiver` (`:52`) ; `RattachementEmploye` Post/Patch/Delete (`:34,35,36`) ; visibilité des employés orphelins (`PerimetrePersonnelExtension.php:206`). |
| `personnel.gerer_qualification` | `Qualification` Post/Patch (`Qualification.php:35,36`). |
| `personnel.gerer_planning` | `CreneauTravail` création/Patch/annulation (`:46,49,54`) ; `AffectationTravail` (`:40,47`) ; `Absence` déclarée pour autrui (`Absence.php:42`, `DeclarerAbsenceProcessor.php:55`) ; recoupement de cible (`AffecterEmployeProcessor.php:70`, `CreerCreneauTravailProcessor.php:60`). |
| `personnel.valider_absence` | `Absence` `/valider` et `/refuser` (`:49,57`). |
| `personnel.gerer_badge` | `BadgeStaff` émission/suspension/réactivation/révocation/incident (`:44,51,59,67,75`) ; `DeclarationIncidentBadge` annulation (`:38`). |
| `personnel.lire_soi` | Accès « soi » sur `Qualification`, `CreneauTravail`, `AffectationTravail`, `Absence`, `BadgeStaff` + filtrage `PerimetrePersonnelExtension:216-221`. |
| `personnel.declarer_absence_soi` | `Absence` Post « soi » (`:42`). |

Rôles semés (`PersonnelFixtures.php:104-124`) : *Personnel Administrateur RH* (`gerer_employe`,
`gerer_qualification`, `gerer_badge`, `lire`, + `acces.ingestion`), *Responsable Planning*
(`gerer_planning`, `valider_absence`, `lire`), *Lecture seule* (`lire`), *Agent Accueil*
(`acces.bloquer_support` seul), *Employé (soi)* (`lire_soi`, `declarer_absence_soi`).

### 4.2 Permission pour les documents RH ? — NON, VERIFIED

Aucune permission `personnel.*` ne concerne un document. Les seules permissions documentaires sont
`dms.{read,write,delete,manage_retention,manage_public_link}`
(`app/migrations/Version20260822090000.php:142`).
⚠ **Le rôle *Personnel Administrateur RH* n'en détient aucune** : seul le rôle socle *Administrateur
groupe* reçoit `read/write/delete/manage_retention` (`DmsFixtures.php:51-57`). En l'état, l'import
d'un contrat par le RH est impossible.

### 4.3 Permission pour le recrutement ? — NON, VERIFIED

Aucune action de ce champ lexical parmi les 8. *Témoin positif* : la même liste contient bien 8
actions distinctes, dont trois `gerer_*`.

### 4.4 ⚠ DÉFAUT — `personnel.gerer` n'existe pas et garde 6 opérations

Exigée par `app/src/Project/Entity/Project.php:63,68`,
`app/src/Project/Entity/ProjectTask.php:45,49,52` et `app/src/Calendar/State/CalendarEventProcessor.php:39`.
**Créée nulle part** : ni dans `Version20260817150200.php`, ni dans `PersonnelFixtures.php:93`, ni dans
aucune migration touchant `sec_permission` pour `module='personnel'`. Ces opérations ne passent que
par leur alternative `organisation.gerer`. Défaut réel, indépendant de ce chantier.

---

## FIL 5 — Ce qui n'existe pas encore

*Témoin positif de la commande* (mêmes options, même périmètre) : `Qualification` → 47 fichiers,
`Absence` → 164, `BadgeStaff` → 25, `CreneauTravail` → 19, `RattachementEmploye` → 5.

| Objet cherché | Résultat |
|---|---|
| Contrat de travail comme **objet** | **0** — `ContratTravail`, `EmploymentContract` : 0 fichier. Seul existe l'enum `TypeContrat` (`app/src/Personnel/Enum/TypeContrat.php`, 6 cas : cdi, cdd, vacataire, saisonnier, stagiaire, prestataire), champ scalaire sur `Employe.php:95`. **Pas de date de fin de CDD, pas de période d'essai, pas de temps de travail, pas de rémunération.** |
| Avenant | **0** — `Avenant`, `ContractAmendment` |
| Convention collective | **0** — `CollectiveAgreement`, `convention collective`, `Idcc` |
| DPAE | **0** — `Dpae`, `declaration prealable` |
| Visite médicale | **0** — `visite medicale`, `MedicalCheckup`, `medecine du travail` |
| Mutuelle / prévoyance | **0** — les 3 hits de `mutuelle` sont « mutuellement exclusives »/« reconnaissance mutuelle ». `Prevoyance` : 0. |
| Registre du personnel | **0** — `registre du personnel`, `StaffRegister` |
| Candidature / offre / entretien | **0** — `JobApplication`, `JobPosting`, `Applicant`, `Interview`, `recruitment` : 0. Les 3 hits de `recrutement` sont une métaphore produit (« lacune de recrutement » d'audioguides). `Onboarding` (8 fichiers) est l'onboarding d'un **établissement**, pas d'un salarié. |
| Pointage horaire réel | **0** — `pointage`, `TimeClock`, `Timesheet`, `feuille de temps`. `CreneauTravail` ne porte que du **prévisionnel** (l. 92-111) ; `AffectationTravail` ne porte que `creneauTravail`, `employe`, `qualification`, `statut` (l. 63-80). **Aucun champ d'heure réelle d'entrée/sortie.** |
| Solde de congés payés | **0**, et **explicitement exclu** : `TypeAbsence.php` docblock « pas de calcul de solde, SIRH externe » ; `specs/personnel/spec-personnel.md:175-177` (RG-PERSO-10). `Absence` porte `employe`, `debut`, `fin`, `type`, `statut`, `motif`, rien d'autre. |
| Bulletin de paie | **0** — `Payslip`, `BulletinPaie` |
| **Note de frais** | **EXISTE, et liée à `Employe`** — `app/src/Finance/ExpenseReport/Entity/ExpenseReport.php:118-122` (`employee_id`, `nullable: false`). Porte aussi `establishment` (l. 106-110) et `businessProfile`. `CreateExpenseReportProcessor.php:29-30,99-101` valide que l'`establishment` correspond à un `RattachementEmploye` **actif ce jour** — **seul consommateur métier du rattachement hors du module Personnel**. Permissions `finance.expense_report_*`, pas `personnel.*`. |

---

## Arbitrages de Maxime qui contraignent ce chantier

- **D18 (2026-08-22, `COORDINATION/DECISIONS.md:337`) — GED.** `dms.manage_public_link` est un rôle
  dédié, jamais héritée par un rôle d'administration générique (« la seule capacité de toute la
  plateforme qui fabrique un accès non authentifié »). Stockage fichier local validé **à condition**
  que la sauvegarde des fichiers et celle de la base soient cohérentes — « condition de mise en
  production du module ». Chiffrement au repos, clé d'environnement, sans valeur par défaut. Liens
  publics : 7 jours par défaut, plafond 30. Catalogue de catégories **fixe** (pt. 7).
- **D41 (`:1075`) — Le cloisonnement filtre les lectures, personne ne garde les écritures.**
  35 entités concernées, dont `Personnel RattachementEmploye` (`:1093`). Remède imposé : **un
  décorateur global, pas 35 processors** (`:1112`), plus un garde-fou refusant qu'une entité **neuve**
  expose un `Etablissement` en groupe d'écriture (`:1119`). Contraint directement toute entité RH à
  créer.
- **D19 (`:369`) — Ce qui dépend d'un tiers est consigné, jamais attendu.** Pour une intégration
  SIRH/DPAE : le premier livrable est le port + adaptateur factice, jamais l'intégration.
- **D88 (`:2861`) — Les zones d'un badge de personnel viennent de la FONCTION, pas du badge.**
  Contrepartie énoncée : « il faut que les rôles existent déjà et soient justes ».
  ⚠⚠ **CE POINT A ÉTÉ ÉCRIT FAUX ICI, ET CORRIGÉ LE 2026-09-08.** Le texte d'origine affirmait que
  « `EmissionBadgeStaffHandler` et `RecalculFenetreBadgeHandler` ne posent **aucune** zone
  (0 occurrence de `addAuthorisedSpace`) ». **C'était un zéro faux** : la méthode réelle est
  `addEspaceAutorise` (`EmissionBadgeStaffHandler.php:110` l'appelle, `PorteeAccesEmploye.php:94` la
  déclare) ; `addAuthorisedSpace` appartient à une **autre classe**, `App\Acces\Entity\DroitAcces:365`.
  La recherche portait sur la chaîne rendue par une source **voisine**, pas sur celle du sujet.
  **Ce qui est vrai :** le handler pose les zones, et **refuse** d'émettre sans zone
  (`EmissionBadgeStaffHandler.php:75-77`, RG-PERSO-06/07).
  **D88 reste ouvert, pour une autre raison :** les zones viennent du **corps de la requête**, pas de
  la fonction de l'employé. La contrepartie n'est donc pas remplie.
- **D90 (`:2891`)** — les badges de personnel restent sous « ancien régime » (ils ouvrent), asymétrie
  assumée et écrite.

---

## ⚠ Contradiction majeure avec l'existant

**La demande porte, mot pour mot, sur ce que la spec du module exclut explicitement.**

`specs/personnel/spec-personnel.md:72`, section « Exclu (pour l'instant) » : « **Recrutement,
entretiens, dossiers administratifs complets (contrats signés, bulletins de salaire, DPAE) — hors
périmètre logiciel de billetterie/accès, relève d'un SIRH.** »

Et `:59-63` : « Paie, solde de congés légal, arrêts maladie CPAM, éléments variables de paie —
**délégués à un SIRH externe** (décision actée du cahier, panel `p-m5`) ». Repris en RG-PERSO-10
(`:175-177`) et dans le tableau des acteurs (`:85`, colonne « Ne peut pas » : « Modifier la paie
(hors périmètre, SIRH externe) »).

Ce n'est pas une omission : c'est une exclusion écrite, adossée à une décision du cahier des charges.
**Aucune décision `Dxx` ne la lève.** La lever est un arbitrage à acter avant de spécifier.

---

## Risques et contradictions — récapitulatif

1. **`personnel.gerer` n'existe pas** et garde 6 opérations (FIL 4.4). Défaut réel, indépendant.
2. **`EstablishmentScopeWriteGuard` est aveugle à l'anglais** (`:68`, `method_exists(…,
   'getEtablissement')`). Toute entité RH neuve conforme à D5 échappe silencieusement au remède D41.
   Idem `PerimetrePersonnelExtension:51-55`.
3. **`Employe` n'a pas d'établissement** — toute entité RH nouvelle rattachée à un `Employe` n'aura
   aucun ancrage de cloisonnement direct et devra passer par la gymnastique
   `EXISTS(RattachementEmploye)`, cas orphelin compris. Le garde-fou n°35 exigera qu'elle soit nommée
   dans une extension.
4. **`replace-version` ne porte aucune métadonnée** — « contrat + avenants » en versions DMS perdrait
   la date d'effet et le motif de chaque avenant, **sans qu'aucun test ne tombe**.
5. **`dms.read` est un droit plat** — un contrat de travail déposé en DMS est lisible par quiconque a
   `dms.read` sur l'établissement, via `/api/documents` **et** via
   `GET /dms/documents/{id}/download`. Aucun filtrage par sujet n'existe.
6. **Le rôle RH n'a aucune permission DMS** (`PersonnelFixtures.php:104-107`).
7. **`RattachementEmploye` accepte `employe` et `etablissement` du corps sans processor** —
   l'établissement est rattrapé par le décorateur global ; **l'employé ne l'est par rien**. Et le seul
   recoupement existant rend `true` pour un employé sans rattachement, c'est-à-dire le cas exact d'un
   premier rattachement.
8. **Divergence d'axe** — `PerimetrePersonnelExtension` filtre sur l'établissement **actif** ;
   `PerimetreEmployeVerificateur` raisonne sur **toutes** les affectations de l'agent. Un employé peut
   être écrivable sans être lisible dans le même contexte.
9. **Aucun `Employe` en base de démo** — tout écran RH construit maintenant sera démontré sur une
   liste vide tant qu'aucune fixture ne sème d'employés.
10. **Valeurs de rétention non validées** — `fr_hr_5y` (60 mois) s'applique automatiquement à tout
    `hr_document` et bloque la suppression (409). Un contrat de travail relève en droit français de
    durées plus longues selon la pièce.
11. **Condition de mise en production D18 pt. 2 non traitée** — cohérence sauvegarde fichiers/base,
    déclarée condition de mise en production du module DMS. Stocker des contrats de travail y ajoute
    un enjeu de conservation légale.
