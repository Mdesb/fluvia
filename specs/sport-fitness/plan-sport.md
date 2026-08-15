# Plan technique — Verticale Salle de sport / Fitness (`Sport` / lot post-MVP, hors ordre L0→L7)

- **Spec source :** specs/sport-fitness/spec-sport.md
- **Stack :** Symfony 7 · API Platform · Doctrine/MariaDB
- **Couvre :** US-SPORT-01 à 11 · RG-SPORT-01 à 07 · CA-1 à CA-14

> **Réutilisation socle L0 (à ne pas dupliquer)** — hiérarchie `App\Organisation\Entity\{Groupe,Region,
> Etablissement,Espace}` (RG-SOCLE-01) ; `PermissionVoter` (attribut `PERM`, sujet `"module.action"`,
> `app/src/Securite/Security/PermissionVoter.php`, RG-SOCLE-04) ; `ContexteEtablissement`
> (RG-SOCLE-05) ; `Utilisateur` (RG-SOCLE-06) ; `App\Audit\Doctrine\AuditWriteSubscriber` append-only
> (RG-SOCLE-07) — **étendu** (liste `CLASSES_SURVEILLEES`, fichier socle partagé déjà étendu par
> L1/L2/L3/L4/L6) avec les entités sensibles Sport (T15).
>
> **Réutilisation M1 Offre (à ne pas redéfinir)** — `App\Offre\Entity\Formule` (code réel,
> `app/src/Offre/Entity/Formule.php`) porte déjà `periodicite`, `sepaActif`, `jourPrelevement`,
> `engagement` (`{dureeMin, pause, resiliation}` array). `AbonnementFitness.formule` **référence** cette
> `Formule` (FK réelle, module déjà codé) ; la verticale **lit** ces champs pour initialiser
> l'échéancier et les règles d'engagement par défaut — elle ne les redéfinit pas. ⚠ Note de
> modélisation : `Formule.periodicite` (enum Offre `mensuel|annuel|personnalise`) et
> `AbonnementFitness.periodicite` (enum Sport `mensuel|hebdomadaire`, cadence **SEPA**) sont deux
> notions distinctes (catalogue vs. cadence de collecte) — pas une duplication, un cas `hebdomadaire`
> nécessite `Formule.periodicite = Personnalise` côté catalogue (documenté §7 risque n°9).
>
> **Réutilisation L3 Accès (clé du couplage anti-impayés — à ne pas redéfinir)** —
> `App\Acces\Entity\{DroitAcces,Support,Appairage,EspaceAcces,JaugeFmi}` et
> `App\Acces\Service\ValidationPassageHandler` (code réel lu, `app/src/Acces/`). **Sport n'ajoute et ne
> modifie aucun fichier `App\Acces\*`** : le couplage paiement↔accès est une **spécialisation** du
> mécanisme déjà livré — `DroitAcces.statutProjection` (`StatutProjectionDroit::Valide|Devalide`,
> **déjà lu** par `ValidationPassageHandler` étape 3, `app/src/Acces/Service/ValidationPassageHandler.php:97`)
> est le **point d'écriture unique** utilisé par Sport pour couper/rétablir un accès — **aucune nouvelle
> colonne booléenne `actif` n'est ajoutée à `DroitAcces`** (contrairement à une lecture littérale de
> la spec §5 « StatutAccesFitness.actif dérivé du statut abonnement » : le champ dérivé vit côté Sport,
> sa **projection** vers L3 réutilise l'enum existant). La propagation hors-ligne vers un contrôleur
> déconnecté réutilise **intégralement** le mécanisme générique L3 (le contrôleur tient `DroitAcces` en
> cache local, resynchronisé via `POST /acces/synchro`/`SynchroPassageHandler`, RG-ACC-05) — **zéro
> développement L3 supplémentaire** pour CA-9/CA-10.
>
> **Réutilisation M4/CRM (référencée, code réel)** — `App\Crm\Entity\{Client,Beneficiaire,Famille}`
> (code réel, `app/src/Crm/Entity/`). `AbonnementFitness.adherent` → `Beneficiaire`,
> `AbonnementFitness.payeur` → `Client` (RG-M4-02, bénéficiaire ≠ payeur). Le canal de notification
> (RG-M4-07) réutilise `App\Crm\Service\ConsentementResolver` — Sport ne réinvente pas de moteur de
> consentement.
>
> **Réutilisation M6 Compta (référencée, gap assumé — cf. Risque n°1)** — `App\Compta\Regime\
> RegimeComptableInterface`/`RegimeComptableResolver`/`TypeExploitant::GroupePrive` existent déjà
> (code réel), mais la chaîne de génération d'écriture (`GenererEcrituresProcessor` →
> `GenerateurEcrituresHandler`) est **architecturalement couplée** à `App\Vente\Entity\Vente`
> (nécessite une `SessionCaisse`, `app/src/Vente/Entity/Vente.php:13`) via
> `App\Compta\Port\ProjectionVenteInterface` — **aucun fait générateur « encaissement récurrent hors
> caisse »** n'existe (confirmé, spec §8 point 3). Sport **ne modifie aucun fichier `App\Compta\*`** :
> elle définit son propre port sortant (§2.5) avec un **adaptateur file d'attente** par défaut, en
> attendant l'extension M6 (hors périmètre de ce lot, cf. Risque n°1).

---

## 0. Principe directeur du couplage paiement ↔ accès (résumé exécutif)

```
AbonnementFitness.statut = actif        → DroitAcces.statutProjection = Valide
AbonnementFitness.statut ∈ {impayé-badge-refusé, pause, résilié}
                                         → DroitAcces.statutProjection = Devalide (+ motif Sport tracé)
```

1. **`StatutAccesFitness`** (nouveau, Sport) porte la relation 1:1 `AbonnementFitness` ↔ `DroitAcces`
   (L3, existant) et le **motif d'inactivité** métier (`impaye|pause|resiliation`, absent de L3 qui ne
   connaît qu'un booléen valide/dévalidé — le motif métier reste donc **côté Sport**, consultable par
   l'agent, sans que L3 ait à le comprendre).
2. **`PropagationAccesFitnessHandler::propager(AbonnementFitness)`** est le **seul point d'écriture**
   sur `DroitAcces.statutProjection` depuis Sport (jamais d'accès direct depuis les handlers métier —
   souscription, pause, résiliation, moteur anti-impayés, résolution — qui **appellent tous** ce
   handler en fin de transition d'état). Symétrique à ce que fait déjà M2 (« `devalide` consomme une
   dévalidation M2 », commentaire lu dans `StatutProjectionDroit`) : Sport devient un **second
   producteur légitime** de cette transition, sans modifier `App\Acces\*`.
3. **RG-SPORT-01 (accès reste actif tant que la représentation n'a pas échoué)** est implémenté en ne
   déclenchant `propager()` vers `Devalide` **qu'aux points de la machine à états où le paramètre
   `PolitiqueAntiImpayes.momentRefusBadge` l'exige** (§2.3) — la simple création d'un
   `IncidentPrelevement` (statut `représentation`) **ne propage rien** par défaut.
4. **Hors-ligne (RG-SPORT-04, CA-9/CA-10)** — **aucun développement L3 requis** : `DroitAcces` est déjà
   la projection locale que `ValidationPassageHandler` lit en < 1 s, synchronisée par le mécanisme
   générique de contrôleur (`app/src/Acces/State/SynchroProcessor.php`). Le **délai de propagation**
   d'une réactivation vers un contrôleur resté hors-ligne dépend uniquement de la fréquence de synchro
   déjà paramétrée en L3 (point ouvert générique, non résolu ici — Risque n°6).
5. **`StatutAccesFitness.droitAcces` est nullable et résolu à l'appairage**, pas à la souscription :
   l'`AbonnementFitness` peut être créé (US-SPORT-01, souscription en agence ou en ligne) avant que le
   support physique/dématérialisé de l'adhérent soit appairé par L3 (`App\Acces\Entity\Appairage`,
   flux standard M1/M2/L3 déjà livré). Tant que `droitAcces` est `null`, `PropagationAccesFitnessHandler`
   ne fait rien (rien à propager) ; le rattachement se fait via un endpoint dédié `POST
   /sport/abonnements/{id}/rattacher-droit-acces` qui enregistre la référence puis rejoue immédiatement
   la propagation de l'état courant (CA-1 : « le droit d'accès correspondant est actif dès la
   souscription » suppose ce rattachement fait **au même moment** que l'appairage du support en agence,
   flux synchrone décrit en tâche T4).

---

## 1. Entités & schéma

Namespace : **`App\Sport\Entity\*`**. `id` = UUID (`Symfony\Component\Uid\Uuid`, type Doctrine `uuid`).
`declare(strict_types=1)` partout. Noms métier en français. Toute entité racine porte un `ManyToOne`
vers `Etablissement` (socle, RG-SOCLE-01), cloisonnée par `App\Sport\Doctrine\PerimetreSportExtension`
(même pattern Doctrine que L1/L2/L3/L4/L6).

### 1.1 Enums (`App\Sport\Enum\*`)

| Enum | Valeurs | Notes |
|---|---|---|
| `PeriodiciteAbonnementFitness` | `mensuel`, `hebdomadaire` | cadence SEPA (≠ `Formule.periodicite`, cf. en-tête) |
| `StatutAbonnementFitness` | `actif`, `pause`, `impaye`, `resilie` | RG-SPORT-04/05/06/07 |
| `StatutEcheanceSepa` | `a_venir`, `prelevee`, `rejetee`, `gelee` | `gelee` = pause (RG-SPORT-05) |
| `StatutRemiseSepa` | `brouillon`, `generee`, `transmise`, `traitee` | cycle de vie du lot pain.008 |
| `StatutIncidentPrelevement` | `representation`, `recouvrement`, `resolu` | RG-SPORT-01/02 |
| `ResultatRepresentationSepa` | `en_attente`, `reussie`, `echouee` | RG-SPORT-02 |
| `CanalResolutionImpaye` | `app_1_clic`, `virement`, `caisse`, `autre` | RG-SPORT-03 |
| `MomentRefusBadge` | `apres_1er_echec`, `apres_representation_echouee`, `apres_n_representations_echouees` | décision actée, défaut = `apres_representation_echouee` |
| `MotifInactiviteAccesFitness` | `impaye`, `pause`, `resiliation` | affiché au refus (`spec-acces.md` §4.3) |
| `StatutPauseAbonnement` | `active`, `terminee`, `refusee` | `refusee` si impayé en cours |
| `StatutResiliation` | `en_preavis`, `effective`, `refusee` | RG-SPORT-06/07 |
| `StatutMandatSepaFitness` | `actif`, `revoque` | révoqué à la date d'effet de résiliation |
| `StatutRejetPrelevement` | `nouveau`, `traite` | retour brut banque, avant création de l'incident |
| `StatutEvenementSOS` | `ouverte`, `traitee` | US-SPORT-09 |

### 1.2 Cœur abonnement & échéancier (US-SPORT-01, RG-M1-03/12)

| Entité | Champ | Type Doctrine | Null | Contrainte | Notes |
|---|---|---|---|---|---|
| **AbonnementFitness** | id | `uuid` | non | PK | — |
| | adherent | — | non | FK | `ManyToOne` → `App\Crm\Entity\Beneficiaire` (existant) |
| | payeur | — | non | FK | `ManyToOne` → `App\Crm\Entity\Client` (existant) — porte le mandat |
| | formule | — | non | FK | `ManyToOne` → `App\Offre\Entity\Formule` (existant, RG-M1-03) |
| | periodicite | `string(12)` enum `PeriodiciteAbonnementFitness` | non | requis | cadence SEPA |
| | statut | `string(12)` enum `StatutAbonnementFitness` | non | défaut `actif` | pilote §0 |
| | dateSouscription | `date_immutable` | non | — | — |
| | dateDebutEngagement, dateFinEngagement | `date_immutable`, `date_immutable` | non/non | `fin ≥ debut` (validateur) | reportée par les pauses |
| | preavisResiliationJours | `smallint` | non | `≥ 0`, copié de `formule.engagement['resiliation']` à défaut | RG-SPORT-06 |
| | mandatSepa | — | non | **OneToOne unique** | `OneToOne` → `MandatSepaFitness` |
| | droitAcces (via `StatutAccesFitness`) | — | — | — | cf. §1.6, pas de FK directe ici |
| | etablissement | — | non | FK | `ManyToOne` → `Etablissement` (socle) |
| **EcheanceSepa** | id, abonnement | `uuid`, FK | non | requis | `ManyToOne` → `AbonnementFitness` |
| | dateProgrammee | `date_immutable` | non | — | — |
| | montantCentimes | `integer` | non | `> 0` | montants en centimes (convention M2/M6) |
| | statut | `string(10)` enum `StatutEcheanceSepa` | non | défaut `a_venir` | — |
| | remise | — | oui | FK | `ManyToOne` → `RemiseSepa`, renseignée à l'inclusion dans un lot |
| | dateExecutionReelle | `datetime_immutable` | oui | — | renseignée au retour banque |

### 1.3 Moteur anti-impayés (US-SPORT-05/06, RG-SPORT-01/02) `critique`

| Entité | Champ | Type | Null | Contrainte | Notes |
|---|---|---|---|---|---|
| **PolitiqueAntiImpayes** | id, etablissement | `uuid`, FK | non | **OneToOne unique** | 1 par établissement |
| | nbRepresentationsMax | `smallint` | non | `≥ 0`, défaut 1 | RG-SPORT-01 |
| | calendrierRepresentationJours | `json` (`list<int>`) | non | requis si `nbRepresentationsMax > 0` | délais en jours après rejet |
| | momentRefusBadge | `string(32)` enum `MomentRefusBadge` | non | défaut `apres_representation_echouee` | ⚠ défaut à confirmer (spec §4.5) |
| | nReprAvantBadge | `smallint` | oui | requis si `momentRefusBadge = apres_n_representations_echouees` | — |
| | delaiAvantSuspensionContratJours | `smallint` | oui | ⚠ HYPOTHÈSE, pas de défaut chiffré | second seuil, spec §4.5 |
| **IncidentPrelevement** | id, abonnement | `uuid`, FK | non | requis | `ManyToOne` → `AbonnementFitness` |
| | echeanceOrigine | — | non | FK | `ManyToOne` → `EcheanceSepa` |
| | rejetOrigine | — | oui | FK | `ManyToOne` → `RejetPrelevement` (retour brut source) |
| | montantCentimes | `integer` | non | `> 0` | — |
| | dateRejet | `date_immutable` | non | — | — |
| | motifBancaire | `string(4)` | non | requis | code retour SEPA (ex. `AM04`), dénormalisé de `rejetOrigine` |
| | statut | `string(14)` enum `StatutIncidentPrelevement` | non | défaut `representation` | cycle §0 |
| | canalResolution | `string(10)` enum `CanalResolutionImpaye` | oui | requis si `statut = resolu` | RG-SPORT-03 |
| | dateResolution | `datetime_immutable` | oui | — | — |
| **RepresentationSepa** | id, incident | `uuid`, FK | non | requis | `ManyToOne` → `IncidentPrelevement` |
| | dateProgrammee | `date_immutable` | non | — | calendrier paramétrable |
| | dateExecution | `datetime_immutable` | oui | — | renseignée à l'exécution |
| | resultat | `string(10)` enum `ResultatRepresentationSepa` | non | défaut `en_attente` | échec → recouvrement |

### 1.4 Pause, résiliation, réengagement (US-SPORT-02/03/04)

| Entité | Champ | Type | Null | Contrainte | Notes |
|---|---|---|---|---|---|
| **PauseAbonnement** | id, abonnement | `uuid`, FK | non | requis | `ManyToOne` → `AbonnementFitness` |
| | dateDebut, dateFin | `date_immutable`, `date_immutable` | non/non | `fin ≥ debut` | — |
| | motif | `string(255)` | oui | — | — |
| | statut | `string(10)` enum `StatutPauseAbonnement` | non | défaut `active` | `refusee` si `abonnement.statut = impaye` |
| **Resiliation** | id, abonnement | `uuid`, FK | non | requis | `ManyToOne` → `AbonnementFitness` |
| | dateDemande | `date_immutable` | non | — | — |
| | motif | `string(255)` | non | requis | texte libre |
| | motifLegitime | `boolean` | non | défaut `false` | conditionne dérogation engagement |
| | justificatifChemin | `string(255)` | oui | optionnel | pièce jointe (stockage fichiers socle) |
| | valideParUtilisateur | — | oui | FK | `ManyToOne` → `Utilisateur`, requis si `motifLegitime = true` |
| | preavisAppliqueJours | `smallint` | non | copié de l'abonnement à la demande | — |
| | dateEffet | `date_immutable` | non | `= dateDemande + preavisAppliqueJours` | RG-SPORT-06 |
| | statut | `string(12)` enum `StatutResiliation` | non | défaut `en_preavis` | `refusee` = engagement non honoré |
| **Reengagement** | id, ancienAbonnement | `uuid`, FK | non | requis | `ManyToOne` → `AbonnementFitness` (résilié) |
| | nouvelAbonnement | — | non | **OneToOne unique** | `ManyToOne`/`OneToOne` → `AbonnementFitness` |
| | nouveauMandat | — | non | FK | `ManyToOne` → `MandatSepaFitness` (**toujours nouveau**, décision actée) |
| | dateReengagement | `date_immutable` | non | — | — |

### 1.5 Mandats & collecte SEPA (US-SPORT-10)

| Entité | Champ | Type | Null | Contrainte | Notes |
|---|---|---|---|---|---|
| **MandatSepaFitness** | id | `uuid` | non | PK | cahier §4 |
| | rum | `string(35)` | non | **unique** | Référence Unique de Mandat |
| | ibanToken | `string(128)` | non | requis | **jamais l'IBAN en clair** — cf. §2.4 |
| | iban4Derniers | `string(4)` | non | requis | affichage seul (`FR76 •••• 1234`) |
| | titulaire | `string(180)` | non | requis | — |
| | dateSignature | `date_immutable` | non | — | — |
| | statut | `string(10)` enum `StatutMandatSepaFitness` | non | défaut `actif` | révoqué à la résiliation |
| | abonnementRattache | — | non | **OneToOne unique** | `OneToOne` → `AbonnementFitness` |
| | payeur | — | non | FK | `ManyToOne` → `App\Crm\Entity\Client` (dénormalisé, cohérence audit) |
| **RemiseSepa** | id, etablissement | `uuid`, FK | non | requis | lot de prélèvements (pain.008) |
| | referenceRemise | `string(35)` | non | **unique** | référence technique du lot |
| | dateGeneration | `datetime_immutable` | non | — | — |
| | dateExecutionPrevue | `date_immutable` | non | — | — |
| | statut | `string(10)` enum `StatutRemiseSepa` | non | défaut `brouillon` | — |
| | nbEcheances | `integer` | non | dérivé | — |
| | montantTotalCentimes | `integer` | non | dérivé | — |
| **RejetPrelevement** | id | `uuid` | non | PK | retour brut normalisé banque |
| | remise | — | oui | FK | `ManyToOne` → `RemiseSepa` |
| | echeance | — | non | FK | `ManyToOne` → `EcheanceSepa` |
| | codeRetour | `string(4)` | non | requis | ex. `AM04`, `MD01`, `MS03` |
| | libelleRetour | `string(255)` | oui | — | — |
| | dateReception | `datetime_immutable` | non | — | — |
| | montantCentimes | `integer` | non | `> 0` | — |
| | statut | `string(10)` enum `StatutRejetPrelevement` | non | défaut `nouveau` | `traite` dès l'`IncidentPrelevement` créé |

> **Pourquoi `RejetPrelevement` distinct d'`IncidentPrelevement`** — `RejetPrelevement` est
> l'**enregistrement technique brut** du retour bancaire normalisé (1 par retour reçu, y compris un
> retour concernant une **représentation**, pas seulement le rejet initial) ; `IncidentPrelevement` est
> l'**objet métier du dossier impayé** (1 par échéance en défaut, cycle représentation→recouvrement→
> résolution). Un `RejetPrelevement` sur une représentation **met à jour** l'`IncidentPrelevement`
> existant (`RepresentationSepa.resultat = echouee`), il n'en crée pas un second.

### 1.6 Couplage accès (§0, US-SPORT-08, RG-SPORT-04)

| Entité | Champ | Type | Null | Contrainte | Notes |
|---|---|---|---|---|---|
| **StatutAccesFitness** | id, abonnement | `uuid`, FK | non | **OneToOne unique** | `OneToOne` → `AbonnementFitness` |
| | droitAcces | — | oui | FK unique si renseigné | `ManyToOne`/`OneToOne` → `App\Acces\Entity\DroitAcces` (**existant, réutilisé**) — nullable jusqu'à l'appairage (§0 point 5) |
| | actif | `boolean` | non | dérivé, défaut `true` | miroir Sport de `DroitAcces.statutProjection` |
| | motifInactivite | `string(12)` enum `MotifInactiviteAccesFitness` | oui | requis si `actif = false` | **non porté par L3** — motif métier Sport |
| | dateDernierePropagation | `datetime_immutable` | oui | — | traçabilité §0 point 4 |

### 1.7 Accès nocturne autonome (US-SPORT-09)

| Entité | Champ | Type | Null | Contrainte | Notes |
|---|---|---|---|---|---|
| **ConfigAccesNocturne** | id, espaceAcces | `uuid`, FK | non | **OneToOne unique** | `OneToOne` → `App\Acces\Entity\EspaceAcces` (existant, réutilisé — **aucune duplication** de `seuilFmi`) |
| | plageDebut, plageFin | `time_immutable`(ou `string(5)` `HH:MM`), idem | non/non | requis | définit « nuit » pour cet espace |
| | videoActive | `boolean` | non | défaut `true` | décision actée |
| | boutonSosActif | `boolean` | non | défaut `true` | décision actée |
| | detectionPresenceIsoleeActive | `boolean` | non | défaut `true` | décision actée |
| | limiteOccupationNocturne | `integer` | oui | `≥ 0`, `null` = hérite `EspaceAcces.seuilFmi` | peut différer du seuil FMI diurne |
| **EvenementSOS** | id, espaceAcces | `uuid`, FK | non | requis | `ManyToOne` → `EspaceAcces` |
| | horodatage | `datetime_immutable` | non | — | — |
| | declenchePar | — | oui | FK | `ManyToOne` → `App\Acces\Entity\Support` (optionnel, anonyme possible) |
| | statut | `string(10)` enum `StatutEvenementSOS` | non | défaut `ouverte` | — |
| | traitePar, dateTraitement | FK, `datetime_immutable` | oui/oui | — | clôture tracée |
| **AlertePresenceIsolee** | id, espaceAcces | `uuid`, FK | non | requis | `ManyToOne` → `EspaceAcces` |
| | horodatage | `datetime_immutable` | non | — | dérivé d'un provider lisant `JaugeFmi` (lecture seule, comme `PossEtatLiveProvider` en L6) |
| | nbPersonnesDetectees | `smallint` | non | `= 1` (condition de déclenchement) | ⚠ HYPOTHÈSE comportement aval (spec §4.8) |

### 1.8 File d'attente comptable transitoire (§2.5 — Risque n°1)

| Entité | Champ | Type | Null | Contrainte | Notes |
|---|---|---|---|---|---|
| **MouvementComptableSepa** | id, abonnement | `uuid`, FK | non | requis | append-only, jamais modifié |
| | type | `string(12)` enum `TypeMouvementComptableSepa` {`encaissement`,`impaye`} | non | requis | — |
| | montantCentimes | `integer` | non | `> 0` | — |
| | dateFaitGenerateur | `date_immutable` | non | — | date de l'encaissement/du rejet |
| | origine | `string(20)` | non | ex. `prelevement`, `representation`, `resolution_1_clic` | — |
| | statutTransmission | `string(12)` enum {`en_attente`,`transmis`} | non | défaut `en_attente` | consommé par une future extension M6 |
| | transmisLe | `datetime_immutable` | oui | — | — |

---

## 2. Ports & adaptateurs (extensions/intégrations)

Tous les ports Sport suivent le pattern déjà établi par `App\Compta\Port\PayFipInterface` +
`App\Compta\Adapter\PayFipStubAdapter` (code réel lu) : interface fine, adaptateur stub par défaut
enregistré dans `services.yaml`, remplaçable sans toucher le domaine.

### 2.1 Collecte bancaire SEPA — `App\Sport\Sepa\Port\CollecteurSepaInterface`

```php
interface CollecteurSepaInterface
{
    /** Génère le fichier de remise (pain.008) pour un lot d'échéances ; renvoie référence + contenu/URI. */
    public function genererRemise(RemiseSepa $remise, array $echeances): ResultatGenerationRemise;

    /** Transmet une remise déjà générée au partenaire bancaire (SFTP/EBICS/API — non spécifié ici). */
    public function transmettre(RemiseSepa $remise): void;

    /** @return list<RetourSepaDto> Relève les retours normalisés (rejets/représentations) depuis une date. */
    public function relerverRetours(\DateTimeImmutable $depuis): array;

    /** Soumet une représentation ponctuelle (hors remise groupée). */
    public function soumettreRepresentation(RepresentationSepa $representation): void;
}
```

- **Adaptateur par défaut** `App\Sport\Sepa\Adapter\CollecteurSepaStubAdapter` — génère une référence
  déterministe (même technique que `PayFipStubAdapter`), ne transmet rien à une vraie banque,
  `relerverRetours()` renvoie `[]` par défaut (aucun retour réel). **Aucune remise bancaire réelle
  n'est effectuée par ce lot** — cf. Risque n°2.
- Un test dédié (`CollecteurSepaStubAdapterTest`) simule un retour de rejet en **injectant** un
  `RetourSepaDto` via une méthode de test (`CollecteurSepaStubAdapter::injecterRetourDeTest()`), pour
  permettre de tester le moteur anti-impayés bout en bout sans banque réelle.

### 2.2 Tokenisation IBAN — `App\Sport\Sepa\Port\TokenisationIbanInterface`

```php
interface TokenisationIbanInterface
{
    /** Tokenise un IBAN en clair reçu en entrée d'un processor ; l'IBAN clair n'est jamais persisté. */
    public function tokeniser(string $ibanClair): TokenIban; // {token: string, quatreDerniers: string}
}
```

- **Adaptateur par défaut** `App\Sport\Sepa\Adapter\TokenisationIbanHmacAdapter` — HMAC-SHA256 avec clé
  d'application (`%env(SPORT_IBAN_HMAC_KEY)%`), tronqué. ⚠ **Non un vrai coffre-fort de paiement** —
  documenté Risque n°3 (à remplacer par un vrai PSP/HSM avant mise en production réelle).

### 2.3 Encaissement immédiat CB (résolution 1 clic) — `App\Sport\Paiement\Port\EncaissementImmediatInterface`

```php
interface EncaissementImmediatInterface
{
    public function initierPaiement(Uuid $incidentId, int $montantCentimes): InitiationPaiementResultat;
    public function confirmerPaiement(string $referenceTransaction): ResultatConfirmationPaiement;
}
```

- **Adaptateur par défaut** `App\Sport\Paiement\Adapter\EncaissementImmediatStubAdapter` — même patron
  que `PayFipStubAdapter` (référence déterministe, URL factice, confirmation simulée synchrone en test).
  ⚠ PSP CB réel non nommé par les sources (spec §4.6) — Risque n°4.

### 2.4 Projection comptable (encaissements/impayés) — `App\Sport\Compta\Port\ProjectionEcritureSepaInterface`

```php
interface ProjectionEcritureSepaInterface
{
    public function enregistrerEncaissement(Uuid $etablissementId, Uuid $abonnementId, int $montantCentimes, \DateTimeImmutable $date, string $origine): void;
    public function enregistrerImpaye(Uuid $etablissementId, Uuid $abonnementId, int $montantCentimes, \DateTimeImmutable $date): void;
}
```

- **Adaptateur par défaut** `App\Sport\Compta\Adapter\MouvementComptableSepaQueueAdapter` — persiste un
  `MouvementComptableSepa` (§1.8), **sans écrire dans `App\Compta\Entity\EcritureComptable`** (aucun
  fichier `App\Compta\*` modifié par ce lot — cf. Risque n°1, principal point d'articulation à trancher
  avec M6 avant mise en production comptable réelle). Cet adaptateur **journalise déjà correctement**
  la traçabilité exigée par RG-SPORT (§4.5) — c'est le **raccordement GL** qui reste à faire, pas la
  capture de l'événement.

### 2.5 Résumé — pourquoi ce découpage ne duplique pas le socle

- Le SEPA (remise/retours) n'existe **nulle part ailleurs** dans le dépôt → port neuf légitime (spec §8
  point 3, gap confirmé).
- L'IBAN/tokenisation n'existe **nulle part ailleurs** → port neuf légitime, avec garde-fou explicite
  « jamais d'IBAN en clair en base ni en API » (§4).
- L'encaissement CB immédiat est **distinct** de PayFiP (M6, régie publique — non pertinent pour un club
  privé, spec §4.6) → port neuf légitime, même patron que PayFiP.
- La comptabilisation **réutilise** `RegimeComptableInterface`/`TypeExploitant::GroupePrive` **dès que**
  M6 sera étendu ; en attendant, Sport ne réimplémente **pas** la génération d'écriture NF525
  (chaînage/scellement) — elle **journalise en file d'attente**, ce qui est la définition même de « ne
  pas dupliquer une capacité du socle » (constitution §4) appliquée à une capacité **qui n'existe pas
  encore**.

---

## 3. API (API Platform)

Toutes ressources : `#[ApiResource]`, `security` via `is_granted('PERM', 'sport.<action>')` (module
`sport`) ou permissions réutilisées `acces.*`/`crm.*`/`compta.*` quand pertinent. Cadrage établissement
via `ContexteEtablissement` (RG-SOCLE-05).

| Ressource | Opérations | `security:` | Groupes | Notes |
|---|---|---|---|---|
| **AbonnementFitness** | GET coll/item | `sport.lire` / (`sport.lire_soi` + `object.estLieA(user)`) | `abonnement:read` | même patron `_soi` que `Client` (M4) |
| | `POST /sport/abonnements/souscrire` | `sport.gerer_abonnement` | `abonnement:write` | custom — `SouscrireAbonnementProcessor` → `SouscriptionAbonnementHandler` (CA-1) |
| | `POST /sport/abonnements/{id}/rattacher-droit-acces` | `sport.gerer_abonnement` ou `acces.appairer` | — | custom — cf. §0 point 5 |
| **PauseAbonnement** | GET coll | `sport.lire` | `pause:read` | — |
| | `POST /sport/abonnements/{id}/pauses` | `sport.gerer_abonnement` ou (`sport.pause_demander_soi` + lien adhérent) | `pause:write` | custom — `DemanderPauseHandler` (CA-2), refuse si `abonnement.statut = impaye` |
| **Resiliation** | GET coll/item | `sport.lire` | `resiliation:read` | — |
| | `POST /sport/abonnements/{id}/resiliations` | `sport.gerer_abonnement` ou (`sport.resilier_demander_soi` + lien) | `resiliation:write` | custom — `DemanderResiliationHandler` (CA-3), bloque en engagement sans motif légitime |
| | `POST /sport/resiliations/{id}/valider-motif-legitime` | `sport.gerer_abonnement` | — | custom — validation manuelle obligatoire (§4.3 spec) |
| **Reengagement** | `POST /sport/abonnements/{id}/reengager` | `sport.gerer_abonnement` | `reengagement:write` | custom — `ReengagementHandler` (CA-4), exige signature d'un **nouveau** mandat avant activation |
| **EcheanceSepa** | GET coll | `sport.lire` ou `compta.lire` | `echeance:read` | échéancier consultable club + comptable |
| **PolitiqueAntiImpayes** | GET item ; PATCH | `sport.lire` / `sport.parametrer` | `politique:read/write` | 1-1 établissement |
| **RemiseSepa** | GET coll/item | `compta.lire` ou `sport.piloter_impayes` | `remise:read` | — |
| | `POST /sport/remises/generer` | `sport.parametrer` (déclenchement manuel) ou système (cron) | — | custom — `GenererRemiseSepaHandler` → `CollecteurSepaInterface::genererRemise/transmettre` |
| **IncidentPrelevement** | GET coll/item | `sport.piloter_impayes` ou (`sport.lire_soi` + lien) | `incident:read` | alimente le tableau de bord (CA-13) |
| | `POST /sport/impayes/{id}/resoudre` | `sport.piloter_impayes` ou (`sport.resoudre_impaye_soi` + lien) | — | custom — `ResolutionImpayeHandler` (CA-8), 1 clic CB |
| | `POST /sport/impayes/{id}/forcer-reouverture` | `sport.forcer_acces` | — | custom — motif requis, tracé (RG-SOCLE-07) |
| **RepresentationSepa** | GET coll | `sport.piloter_impayes` | `representation:read` | lecture seule, pilotée système |
| **RejetPrelevement** | GET coll | `sport.piloter_impayes` ou `compta.lire` | `rejet:read` | retours bruts, lecture seule |
| **TableauBordImpayes** | `GET /sport/tableau-bord-impayes` | `sport.piloter_impayes` | `dashboard:read` | custom, non-Doctrine — provider composant `IncidentPrelevement` (CA-13), même patron que `PossEtatLiveProvider` |
| **MandatSepaFitness** | GET coll/item | `sport.lire` ou `sport.gerer_abonnement` | `mandat:read` | **`ibanToken` jamais exposé** ; seul `iban4Derniers` l'est (§4) |
| | (création implicite via souscription/réengagement, pas d'endpoint `POST` public direct) | — | — | l'IBAN en clair transite uniquement en entrée du processor de souscription (`denormalizationContext` transitoire, non mappé Doctrine) |
| **ConfigAccesNocturne** | GET coll/item ; POST ; PATCH | `sport.lire` / `sport.configurer_nocturne` | `nocturne:read/write` | — |
| **EvenementSOS** | GET coll/item | `sport.superviser_nocturne` | `sos:read` | — |
| | `POST /sport/espaces/{id}/sos` | ⚠ auth minimale device (cf. Risque n°7) | `sos:write` | déclenchement physique |
| | `POST /sport/sos/{id}/traiter` | `sport.superviser_nocturne` | — | clôture tracée |
| **AlertePresenceIsolee** | GET coll | `sport.superviser_nocturne` | `alerte:read` | dérivée, lecture seule |

- **Groupes de sérialisation** : pattern read/write par ressource comme L3/L6. `MandatSepaFitness` :
  `mandat:read` **exclut explicitement** `ibanToken` (jamais dans `normalizationContext`) —
  garde-fou de nommage à l'image de `compterPassagesAutorises` (jamais `jaugeFmi`, cf.
  `ProjectionPassageInterface`).
- **Custom vs CRUD** : toutes les transitions d'état (souscrire, pauser, résilier, réengager, résoudre,
  forcer, générer une remise) sont des **opérations métier** (State Processors → handlers testables),
  pas du CRUD Doctrine brut — même logique que L3/L6.

---

## 4. Sécurité & droits

- **Permissions requises (module `sport`)** — dérivées du tableau Acteurs & droits de la spec (⚠
  HYPOTHÈSE, non littérales dans les sources, à figer avec M8 comme `piscine.*`/`acces.*`) :
  `sport.gerer_abonnement`, `sport.piloter_impayes`, `sport.forcer_acces`, `sport.lire`,
  `sport.parametrer`, `sport.configurer_nocturne`, `sport.superviser_nocturne`, `sport.lire_soi`,
  `sport.resoudre_impaye_soi`, `sport.pause_demander_soi`, `sport.resilier_demander_soi`.
- **Permissions réutilisées** (non redéfinies) — `acces.appairer`/`acces.lire`/`acces.superviser`
  (rattachement droit d'accès, consultation FMI nocturne), `crm.lire` (fiche adhérent/payeur),
  `compta.lire` (échéancier/rejets côté comptable, §3 spec).
- **Voter** — **aucun voter nouveau** : réutilise `PermissionVoter` du socle
  (`app/src/Securite/Security/PermissionVoter.php`), exactement comme L3/L6. Les contrôles `_soi`
  réutilisent le patron `object.estLieA(user)` de `App\Crm\Entity\Client` (méthode équivalente
  `AbonnementFitness::estLieA(mixed $user)` comparant `adherent.client` ou `payeur` à
  `Utilisateur::getClientLie()`).
- **IBAN — garde de sécurité applicative** — `MandatSepaFitness` n'expose **jamais** `ibanToken` en
  sérialisation (aucun groupe ne le porte) ; seul `iban4Derniers` est lisible. Le champ IBAN en clair
  n'est **jamais mappé Doctrine** : il transite uniquement comme propriété transitoire d'un DTO d'entrée
  consommé synchrones par `TokenisationIbanInterface::tokeniser()` avant persistance — testé par
  `MandatSepaFitness*Test::ibanJamaisPersisteEnClair()` (recherche de sous-chaîne IBAN brute en base,
  test négatif).
- **Cadrage établissement** — `ContexteEtablissement`, extension Doctrine `PerimetreSportExtension`
  (même mécanique que L1/L3/L4/L6).

---

## 5. Migrations

- **Migration structurelle** `VersionSport_schema` : tables `sport_abonnement_fitness`,
  `sport_echeance_sepa`, `sport_politique_anti_impayes`, `sport_incident_prelevement`,
  `sport_representation_sepa`, `sport_pause_abonnement`, `sport_resiliation`, `sport_reengagement`,
  `sport_mandat_sepa_fitness`, `sport_remise_sepa`, `sport_rejet_prelevement`,
  `sport_statut_acces_fitness`, `sport_config_acces_nocturne`, `sport_evenement_sos`,
  `sport_alerte_presence_isolee`, `sport_mouvement_comptable_sepa`.
  - **Index/contraintes** : `OneToOne` unique `AbonnementFitness.mandatSepa`,
    `MandatSepaFitness.abonnementRattache`, `StatutAccesFitness.abonnement`,
    `PolitiqueAntiImpayes.etablissement`, `ConfigAccesNocturne.espaceAcces`,
    `Reengagement.nouvelAbonnement` ; **unique** `MandatSepaFitness.rum`, `RemiseSepa.referenceRemise` ;
    checks `montantCentimes > 0` (toutes les tables concernées), `nbRepresentationsMax ≥ 0`,
    `preavisResiliationJours ≥ 0`. FK vers `etablissement` (socle) et vers
    `App\Acces\Entity\{DroitAcces,EspaceAcces,Support}` (L3), `App\Crm\Entity\{Client,Beneficiaire}`
    (M4), `App\Offre\Entity\Formule` (M1) — **suppose migrations socle L0 + M1 + L3(+L5 si codée) jouées
    d'abord**, même dépendance d'ordre que L6.
- **Migration de données** `VersionSport_permissions` : insère `Permission(module='sport', action ∈
  {gerer_abonnement, piloter_impayes, forcer_acces, lire, parametrer, configurer_nocturne,
  superviser_nocturne, lire_soi, resoudre_impaye_soi, pause_demander_soi, resilier_demander_soi})`.
- **Modification de fichier partagé (hors migration DB)** — ajout des classes Sport sensibles
  (`AbonnementFitness`, `Resiliation`, `MandatSepaFitness`, `PolitiqueAntiImpayes`, `EvenementSOS`) à
  `App\Audit\Doctrine\AuditWriteSubscriber::CLASSES_SURVEILLEES`
  (`app/src/Audit/Doctrine/AuditWriteSubscriber.php`), extension additive comme L1/L2/L3/L4/L6.
- Rejouables, versionnées Doctrine ; jamais de `schema:update --force`.

---

## 6. Tests (PHPUnit + ApiTestCase)

| Test | Type | Couvre |
|---|---|---|
| Souscription : abonnement `actif` créé, mandat signé (IBAN jamais en clair en base), échéancier mensuel généré jusqu'à fin d'engagement, droit d'accès actif dès rattachement | API + Unit (`SouscriptionAbonnementHandler`) | CA-1, US-SPORT-01 |
| Pause : échéances gelées pendant la pause (aucun prélèvement émis), fin d'engagement reportée de la durée exacte ; pause refusée si `abonnement.statut = impaye` | API + Unit (`DemanderPauseHandler`) | CA-2, RG-SPORT-05 |
| Résiliation en engagement sans motif légitime → bloquée ; avec motif légitime validé → acceptée, `dateEffet = demande + préavis`, mandat révoqué **à cette date seulement** (pas avant) | API + Unit (`DemanderResiliationHandler`) | CA-3, RG-SPORT-06/07 |
| Réengagement d'un résilié → nouvel abonnement, nouvel engagement, nouvel échéancier, **nouveau mandat obligatoire** même si un ancien mandat non révoqué existait | API + Unit (`ReengagementHandler`) | CA-4, décision actée |
| Rejet SEPA détecté → `IncidentPrelevement` créé (`representation`) + `RepresentationSepa` programmée selon calendrier ; accès reste actif tant que non échouée (politique par défaut) | API (stub `CollecteurSepaStubAdapter::injecterRetourDeTest`) + Unit (`MoteurAntiImpayesHandler`) | CA-5, RG-SPORT-01 |
| Représentation en échec → incident `recouvrement`, `StatutAccesFitness.actif=false` (`DroitAcces.statutProjection=Devalide`), notification envoyée | API + Unit | CA-6, RG-SPORT-02 |
| `momentRefusBadge = apres_1er_echec` → badge refusé dès le 1ᵉʳ rejet, en parallèle de la 1ʳᵉ représentation programmée | Unit (`MoteurAntiImpayesHandler`, matrice des 3 valeurs de `momentRefusBadge`) | CA-7, décision actée |
| Résolution 1 clic (stub CB) → incident `resolu` (`canalResolution=app_1_clic`), abonnement `actif`, `DroitAcces.statutProjection=Valide` restauré **sans intervention agent** | API + Unit (`ResolutionImpayeHandler`) | CA-8, RG-SPORT-03 |
| Hors-ligne : `DroitAcces` en cache local `Valide`/`Devalide` consulté par `ValidationPassageHandler` sans appel réseau (réutilise directement la suite de tests L3 existante, un scénario Sport de plus) | API (intégration L3↔Sport) | CA-9, RG-SPORT-04 |
| Restauration hors-ligne : régularisation confirmée pendant coupure contrôleur → statut propagé à la synchro suivante, re-badge accepté sans procédure manuelle | API (réutilise `POST /acces/synchro`) | CA-10, décision actée |
| Accès nocturne : plage active → vidéo réputée active (flag lu), bouton SOS actif (`POST /sport/espaces/{id}/sos` crée `EvenementSOS`), présence isolée détectée (`nbPersonnesDetectees=1`), occupation > limite → entrée bloquée (réutilise `ValidationPassageHandler`/`ModeSeuil::Blocage`) | API + Unit | CA-11, US-SPORT-09 |
| Mandat révoqué à la date d'effet de la résiliation, visible sur l'écran de gestion des mandats | API | CA-12, US-SPORT-10 |
| Tableau de bord impayés : file par statut, nombre de badges refusés en cours, taux de résolution `app_1_clic` | API (`TableauBordImpayesProvider`) | CA-13, US-SPORT-11 |
| Quota « cours inclus » : réinitialisation hebdomadaire sans report (réutilise `ServiceInclus`/`PeriodeQuota` M1, test d'intégration, pas de code Sport) | API (intégration M1↔Sport) | CA-14, RG-M1-12 |
| IBAN jamais exposé : recherche de correspondance IBAN en clair sur toutes les réponses API `MandatSepaFitness` (négatif) et sur la table `sport_mandat_sepa_fitness` (colonne `iban_token` non réversible trivialement) | Unit/Sécurité | Contrainte transverse §4 |
| Cloisonnement établissement : agent sans affectation → 403/absent sur toutes les ressources `sport.*` | API | RG-SOCLE-05 (réutilisé) |
| Architecture : aucun fichier `App\Acces\*`/`App\Compta\*`/`App\Offre\*`/`App\Crm\*` modifié par Sport (contrôle statique diff) | Unit (architecture) | non-régression socle |

---

## 7. Tâches (voir tasks-sport.md)

T1 enums Sport → T2 `PolitiqueAntiImpayes` → T3 `MandatSepaFitness` + ports tokenisation IBAN → T4
`AbonnementFitness` + `SouscriptionAbonnementHandler`/`GenerateurEcheancierHandler` + `EcheanceSepa` →
T5 `StatutAccesFitness` + `PropagationAccesFitnessHandler` (couplage L3) → T6 `PauseAbonnement` +
`DemanderPauseHandler` → T7 `Resiliation` + `DemanderResiliationHandler` → T8 `Reengagement` +
`ReengagementHandler` → T9 `RemiseSepa`/`RejetPrelevement` + port `CollecteurSepaInterface` + stub → T10
`IncidentPrelevement`/`RepresentationSepa` + `MoteurAntiImpayesHandler` → T11 port
`EncaissementImmediatInterface` + stub + `ResolutionImpayeHandler` → T12 `MouvementComptableSepa` + port
`ProjectionEcritureSepaInterface` + adaptateur file d'attente → T13 `ConfigAccesNocturne` +
`EvenementSOS` + `AlertePresenceIsolee` + handlers nocturnes → T14 API Platform (ressources restantes +
`TableauBordImpayesProvider`) → T15 sécurité (permissions `sport.*` + extension
`AuditWriteSubscriber`) → T16 migrations → T17 tests. (ordonnées, cf. fichier tasks.)

---

## 8. Risques / à valider

1. **⚠ AUCUNE ÉCRITURE COMPTABLE RÉELLE GÉNÉRÉE PAR CE LOT (priorité haute, gap confirmé spec §8 point
   3)** — la chaîne M6 (`GenererEcrituresProcessor`/`GenerateurEcrituresHandler`) est couplée à
   `App\Vente\Entity\Vente` (nécessite une `SessionCaisse`), inadaptée à un encaissement récurrent
   headless (prélèvement nocturne planifié). Ce lot livre `MouvementComptableSepa` comme **file
   d'attente append-only tracée** (§1.8, §2.4) — **aucune écriture NF525 scellée n'est produite pour les
   encaissements/impayés SEPA**. Deux voies pour lever ce risque (hors périmètre de ce lot, à spécifier
   avec M6) : (a) étendre `RegimeComptableInterface` d'une méthode
   `genererEcritureAbonnementRecurrent()` consommant `ProjectionEcritureSepaInterface` (symétrique à
   `ProjectionPassageInterface` L3→M6, mais dans le sens Sport→M6) ; (b) modéliser chaque encaissement
   confirmé comme une `Vente` M2 headless (nécessiterait que M2 supporte une vente sans `SessionCaisse`,
   non confirmé). **À trancher avant toute mise en production facturant réellement des adhérents.**
2. **⚠ AUCUNE REMISE BANCAIRE RÉELLE (SEPA Direct Debit)** — `CollecteurSepaInterface` n'a qu'un
   adaptateur stub ; aucune connexion EBICS/SFTP/API bancaire n'est implémentée. C'est un **point
   d'extension documenté**, pas un défaut de ce lot (même statut que PayFiP en M6 avant intégration
   réelle) — mais **bloquant pour toute collecte SEPA réelle**.
3. **⚠ Tokenisation IBAN par HMAC applicatif, pas un vrai coffre-fort de paiement (PCI-DSS)** —
   suffisant pour ne jamais exposer l'IBAN en clair via l'API (garde testée §4), **insuffisant** comme
   protection cryptographique de niveau production réelle (recommandation : PSP/HSM dédié avant collecte
   réelle de fonds).
4. **⚠ PSP CB non nommé** pour la résolution 1 clic (spec §4.6) — adaptateur stub par défaut,
   équivalent PayFiP mais pour carte bancaire classique privée.
5. **⚠ Réconciliation du mandat SEPA avec M1/M4 (spec §8 point 2, principal risque de duplication de
   modèle)** — `spec-offre.md` (facette `sepa` de `Formule`) et `spec-crm.md` (mandat rattaché au
   payeur) évoquent un « mandat SEPA » **sans l'objet définir**. `MandatSepaFitness` est ici la
   **première définition opérationnelle concrète** du dépôt. **Avant qu'une autre verticale (padel,
   musée…) ait besoin d'un mandat SEPA**, une factorisation en objet socle unique (probablement porté
   par M4 ou une extension M6) sera nécessaire — non faite ici pour ne pas anticiper une spec non
   écrite (même posture que L6 §8 point 8 sur la caution transverse).
6. **⚠ Délai maximal de propagation d'une restauration d'accès vers un contrôleur hors-ligne** — hérité
   tel quel du point ouvert générique L3 (`spec-acces.md` §7) ; non chiffré, dépend de la fréquence de
   synchro déjà paramétrée par établissement. Sport n'ajoute aucune garantie supplémentaire.
7. **⚠ Authentification du déclenchement `POST /sport/espaces/{id}/sos`** — un bouton SOS physique 24/7
   sans personnel ne peut pas raisonnablement exiger un login humain classique ; un **jeton
   d'appareil/espace** (device token scoping) est nécessaire mais **non spécifié par ce plan** (relève
   d'un mécanisme d'authentification machine générique, potentiellement transverse avec
   l'authentification des contrôleurs L3 — à cadrer avec la sécurité applicative avant implémentation).
8. **⚠ Modalités de sécurité nocturne (conservation vidéo, destinataire de l'alerte SOS, comportement
   exact de la détection de présence isolée)** — non détaillées par les sources (spec §4.8), reprises
   telles quelles ici comme point à cadrer avec un référent conformité/sécurité avant mise en service ;
   ce plan modélise uniquement les **hooks de données** (`ConfigAccesNocturne`, `EvenementSOS`,
   `AlertePresenceIsolee`), pas l'intégration matérielle vidéo elle-même (point d'extension documenté,
   hors périmètre logiciel).
9. **⚠ `AbonnementFitness.periodicite` (Sport, mensuel/hebdomadaire) vs `Formule.periodicite`
   (Offre, mensuel/annuel/personnalise)** — cas `hebdomadaire` nécessite `Formule.periodicite =
   Personnalise` côté catalogue (cf. en-tête) ; à documenter dans le guide de paramétrage catalogue pour
   éviter une incohérence de saisie côté back-office M1.
10. **⚠ Valeur par défaut de `momentRefusBadge` et de `delaiAvantSuspensionContrat`** — retenues comme
    dans la spec (`apres_representation_echouee` ; pas de valeur chiffrée pour le second seuil), à
    confirmer au paramétrage produit avant premier établissement fitness mis en service (spec §4.5,
    points ouverts n°4/8).
11. **⚠ Souscription en ligne autonome vs. exclusivement en agence** (spec §4.1, hypothèse retenue :
    possible en ligne, compte + mandat obligatoires) — conditionne si `SouscrireAbonnementProcessor`
    doit supporter un flux **sans agent** (`sport.lire_soi`/self-service) dès ce lot ou seulement le
    flux agence (`sport.gerer_abonnement`) ; ce plan couvre les deux (permissions alternatives déjà
    posées §3/§4), mais l'ergonomie du flux self-service (app membre) reste hors périmètre logiciel de
    ce plan (M3, comportement observable seulement).
12. **⚠ Dépendance de code L5/CRM** — si `App\Crm\Entity\{Client,Beneficiaire}` évolue de schéma
    (module déjà codé à ce jour, contrairement à l'hypothèse initiale de la spec §0 qui l'anticipait
    non codé), aucun risque de FK cassée : ce plan **référence directement** le code réel lu
    (`app/src/Crm/Entity/`), pas une référence logique — mise à jour par rapport à la spec §5/§8 qui
    anticipait encore une référence faible.
