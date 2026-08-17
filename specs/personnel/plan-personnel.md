# Plan technique — Personnel & planning d'équipe (`App\Personnel`, module `personnel`)

- **Spec source :** specs/personnel/spec-personnel.md (US-PERSO-01 à 11 ⚠ hors backlog, RG-PERSO-01 à 10)
- **Stack :** Symfony 7 · API Platform · Doctrine/MariaDB
- **Précédents réutilisés (lecture avant conception) :** `App\Acces` (`Support`, `Appairage`, `DroitAcces`,
  `AppairageHandler`, `BlocageSupportHandler`, `ValidationPassageHandler`, `ResolveurMarges`,
  `PerimetreAccesExtension`), `App\Vente\Service\GenerateurCodeSupport`, `App\Securite`
  (`Utilisateur`, `PermissionVoter`, `ContexteEtablissement`, `Affectation`), `App\Organisation`
  (`Etablissement`, `Espace`), `App\Piscine` (`QualificationEncadrant`/`AffectationEncadrant`/
  `ValiderCreneauBassinHandler` — à réconcilier §7, **non modifiés dans ce lot**), `App\Reservation`
  (patron de plan pour la structure du document et la réconciliation différée §7).

## 0. Décisions structurantes (résumé — à valider avant implémentation)

Ce module est **transverse** et touche par nature un module déjà livré (`App\Acces`). Les points
suivants **résolvent** les questions de coordination posées par la spec (§8, points ouverts n°7/8) de
façon **additive** (aucune ligne de `ValidationPassageHandler`/`ResolveurMarges`/`AppairageHandler`/
`BlocageSupportHandler` n'est modifiée) :

1. **`App\Acces\Enum\TypeDroitAcces` gagne un seul cas additif `Personnel = 'personnel'`.** C'est
   l'**unique** modification de fichier dans `App\Acces` requise par ce plan (une ligne). Impact
   nul sur le moteur : `ValidationPassageHandler` ne teste `sourceType` qu'une fois, pour le
   décompte crédit `CarteQuota` (§4.3 étape 7 du plan L3) — un droit `Personnel` suit exactement le
   chemin d'un `Billet` (pas de décompte). **Aucune migration de schéma** : la colonne
   `acces_droit_acces.source_type` est déjà `VARCHAR(24)`, largement suffisante pour `personnel` (9
   caractères). **À faire valider par le propriétaire de `App\Acces` avant merge** (changement isolé,
   revue courte).
2. **1 `BadgeStaff` actif par couple (Employé, Établissement)**, et **non** « 1 par Employé » comme
   suggéré par la formulation littérale de la spec (§5) — **divergence assumée et documentée** : le
   modèle `App\Acces` existant (`Support.etablissement` et `DroitAcces.etablissement`, tous deux
   `ManyToOne` **non nullables, mono-établissement**) ne permet pas nativement un unique
   Support/DroitAcces couvrant plusieurs établissements sans réécrire ces entités socle L3. Un employé
   multi-site (RG-PERSO-09) reçoit donc **un badge physique par site** où il doit accéder
   physiquement — chaque badge reste géré indépendamment (émission/révocation/portée), ce qui est
   même **préférable en sécurité/audit** (révocation fine par site). **À confirmer produit** (Risque
   n°2).
3. **Mode `shifts_uniquement` réutilise `DroitAcces.fenetreDebut`/`fenetreFin`** (fenêtre **unique**,
   déjà supportée par `ResolveurMarges` sans aucune modification) — recalculée par un handler
   `RecalculFenetreBadgeHandler` déclenché (a) en écoute sur création/confirmation/annulation
   d'`AffectationTravail` et validation d'`Absence`, et (b) par une commande planifiée
   (`personnel:recalculer-fenetres-badges`, cron ~5 min) : la fenêtre est fixée au **prochain/au
   `CreneauTravail` confirmé en cours** de l'employé sur cet établissement (± `margeAvantApres`) ; en
   dehors de tout shift, la fenêtre est mise dans le passé (`fenetreDebut=fenetreFin=` instant déjà
   écoulé) pour que `ResolveurMarges` refuse tout passage, sans code additionnel. **Limite assumée** :
   une seule fenêtre à la fois (pas un calendrier multi-créneaux natif dans `DroitAcces`) — suffisant
   pour CA-8/CA-9 mais imprécis si deux shifts très rapprochés existent (Risque n°3).
4. **Mode `permanent` = `fenetreDebut`/`fenetreFin` = `null`** — `ResolveurMarges` traite déjà nativement
   « pas de fenêtre = aucune contrainte de marge » (§4.2 du plan L3) : zéro code à ajouter.
5. **`PorteeAccesEmploye.espacesAutorises[]` est déclarative dans ce lot, non appliquée par le moteur.**
   `ValidationPassageHandler` ne restreint **aucun** droit (client ou staff) à un sous-ensemble
   d'`EspaceAcces` au sein d'un établissement — seule la fédération `SousReseau` gère une portée
   multi-espaces, pour un tout autre usage (inter-établissement). Cette limite préexiste et n'est **pas
   une régression introduite par ce module**. `espacesAutorises` sert la **restitution** (fiche badge,
   roster, export RH) et pourra **guider** une émission de badge par étab (décision n°2), mais un
   employé dont le badge est actif peut aujourd'hui franchir **tout équipement de l'établissement
   concerné**, comme n'importe quel droit générique. Une vraie restriction fine par espace nécessite une
   évolution future, additive, de `ValidationPassageHandler` (Risque n°1, **à cadrer avec le
   propriétaire L3**, hors périmètre de ce lot).
6. **Support physique** : `TypeSupport` (Acces) réutilisé tel quel — `Rfid` par défaut (badge physique),
   `Qr`/`Wallet` si le site choisit un badge dématérialisé. **Identifiant** généré par
   `App\Vente\Service\GenerateurCodeSupport::genererPourType()` **réutilisé sans modification**, avec le
   cas `App\Vente\Enum\TypeSupport::Carte` (préfixe `CAR`) — **zéro modification de `App\Vente`**.
7. **Révocation ET suspension réutilisent `App\Acces\Service\BlocageSupportHandler` tel quel** (`bloquer()`
   / `annuler()`), pour les **quatre** déclencheurs (fin de contrat, suspension, perte/vol, révocation
   manuelle) — seul le `motif` texte diffère. Ceci **résout nativement** le point ouvert n°10 de la
   spec (« suspension réversible sans ré-appairage ») : `annuler()` repasse le **même** `Support` à
   `actif` sans créer de nouvel `Appairage`. `BadgeStaff.statut` (3 états `actif/suspendu/revoque`,
   propres à la RH) est **denormalisé** côté Personnel car `Support.statut` (Acces) n'a que 2 états
   (`actif`/`bloque`) — les 2 causes RH (`suspendu`, `revoque`) mappent toutes deux sur `Support.statut
   = bloque` côté L3, distinguées uniquement par `BadgeStaff.motifRevocation` côté Personnel.
8. **`DeclarationIncidentBadge` = vue API (Provider), pas de table propre** : elle **lit**
   `App\Acces\Entity\DeclarationPerteVol` filtrée sur le(s) `Support` d'un `BadgeStaff`, exactement
   comme le roster hebdomadaire (§4.5 spec) est une vue agrégeant `CreneauTravail`/`AffectationTravail`
   sans table dédiée. Réutilise `BlocageSupportHandler::bloquer()`/`annuler()` pour l'écriture — **zéro
   duplication** de `RG-ACC-07`.
9. **Réconciliation `App\Piscine` (`QualificationEncadrant`/`AffectationEncadrant`) et rôle Coach padel** :
   **mapping documenté §7, non exécuté dans ce lot** — aucun fichier `App\Piscine`/`App\Padel` modifié
   (même patron que `specs/reservation/plan-reservation.md` §7 pour sa propre réconciliation Piscine).
10. **`Qualification.statut` est calculé à la volée** (méthode `estValideA(\DateTimeImmutable): bool`,
    même patron que `Piscine\QualificationEncadrant::estValideA()`), **non persisté** — élimine tout
    risque de désynchronisation, pas de cron de recalcul nécessaire.

## 1. Entités & schéma

| Entité (`App\Personnel\Entity\*`) | Champ | Type Doctrine | Null | Index/Contrainte | Relation |
|---|---|---|---|---|---|
| **Employe** (`personnel_employe`) | id | uuid | non | PK | RG-PERSO-01 |
| | utilisateur | ManyToOne `App\Securite\Entity\Utilisateur` | oui | index | lien logiciel optionnel (§3 spec) |
| | nom, prenom | string(100) | non | — | identité propre |
| | matricule | string(40) | oui | unique si renseigné | id interne RH |
| | poste | string(80) | non | — | ex. « MNS », « coach padel » |
| | typeContrat | string(16) enum `TypeContrat` {cdi, cdd, vacataire, saisonnier, stagiaire, prestataire} | non | — | liste ouverte ⚠ hypothèse spec §4.1 |
| | dateEntree | date_immutable | non | — | — |
| | dateSortie | date_immutable | oui | — | déclenche révocation badge(s) §4.8 |
| | statut | string(10) enum `StatutEmploye` {actif, suspendu, sorti} | non | défaut `actif` | RG-PERSO-01 |
| **RattachementEmploye** (`personnel_rattachement`) | id | uuid | non | PK | RG-PERSO-09 |
| | employe | ManyToOne `Employe` | non | index | — |
| | etablissement | ManyToOne `Etablissement` (socle) | non | index | multi-site |
| | posteLocal | string(80) | oui | — | surcharge locale du poste |
| | debut | date_immutable | non | — | — |
| | fin | date_immutable | oui | — | période d'effet |
| **Qualification** (`personnel_qualification`) | id | uuid | non | PK | RG-PERSO-02 |
| | employe | ManyToOne `Employe` | non | index | — |
| | type | string(10) enum `TypeQualification` {MNS, BNSSA, BEESAN, BAFA, BPJEPS, autre} | non | — | liste ouverte ⚠ hypothèse |
| | libelle | string(120) | oui | requis si `type=autre` (validateur) | — |
| | dateObtention | date_immutable | oui | — | historique |
| | dateValidite | date_immutable | non | — | expiration/recyclage |
| *(pas de colonne `statut` — décision n°10, calculé par `estValideA()`)* |
| **CreneauTravail** (`personnel_creneau_travail`) | id | uuid | non | PK | RG-PERSO-03 |
| | etablissement | ManyToOne `Etablissement` | non | index | — |
| | espace | ManyToOne `Espace` (socle) | oui | index | zone concernée |
| | creneauReservationRef | uuid | oui | ref logique `Reservation\Entity\Creneau`, pas de FK dure | §4.9 spec |
| | libellePoste | string(120) | non | — | — |
| | debut, fin | datetime_immutable | non | `debut < fin` (validateur `TopologieTravailCoherente`) | — |
| | qualificationRequise | string(10) enum `TypeQualification`? | oui | — | RG-PERSO-04/08 |
| | effectifRequis | smallint | non | défaut 1, `≥ 1` | — |
| | statut | string(10) enum `StatutCreneauTravail` {planifie, confirme, realise, annule} | non | défaut `planifie` | — |
| | motifRecurrence | string(16) enum `MotifRecurrenceTravail` {hebdomadaire}? | oui | — | RG-PERSO-03 (généralisation RG-M5-07) |
| | finRecurrence | date_immutable | oui | requis si `motifRecurrence` renseigné | — |
| **AffectationTravail** (`personnel_affectation_travail`) | id | uuid | non | PK | RG-PERSO-04 |
| | creneauTravail | ManyToOne `CreneauTravail` | non | index, unique(creneauTravail, employe) | — |
| | employe | ManyToOne `Employe` | non | index | pas de chevauchement (RG-PERSO-04) |
| | qualificationUtilisee | ManyToOne `Qualification` | oui | requis si créneau qualifiant (validateur) | traçabilité couverture |
| | statut | string(20) enum `StatutAffectationTravail` {planifiee, confirmee, realisee, absente_remplacee, annulee} | non | défaut `planifiee` | — |
| **Absence** (`personnel_absence`) | id | uuid | non | PK | RG-PERSO-05 |
| | employe | ManyToOne `Employe` | non | index | — |
| | debut, fin | datetime_immutable | non | `debut < fin` | — |
| | type | string(10) enum `TypeAbsence` {conge, maladie, formation, autre} | non | — | pas de calcul de solde (RG-PERSO-10) |
| | statut | string(10) enum `StatutAbsence` {declaree, validee, refusee} | non | défaut `declaree` | — |
| | valideePar | ManyToOne `Utilisateur` | oui | requis si `validee`/`refusee` | — |
| | motif | string(255) | oui | — | — |
| **BadgeStaff** (`personnel_badge_staff`) | id | uuid | non | PK | RG-PERSO-06, décision n°2 |
| | employe | ManyToOne `Employe` | non | index | — |
| | etablissement | ManyToOne `Etablissement` | non | index | **champ ajouté vs spec §5**, décision n°2 |
| | support | ManyToOne `App\Acces\Entity\Support` | non | index (FK réelle, Personnel dépend d'Acces) | 1 badge = 1 Support |
| | droitAcces | ManyToOne `App\Acces\Entity\DroitAcces` | non | — | `sourceType = Personnel` |
| | statut | string(10) enum `StatutBadgeStaff` {actif, suspendu, revoque} | non | défaut `actif` | denormalisé, décision n°7 |
| | dateEmission | datetime_immutable | non | — | — |
| | dateRevocation | datetime_immutable | oui | requis si `suspendu`/`revoque` | RG-PERSO-08 |
| | motifRevocation | string(255) | oui | requis si `suspendu`/`revoque` | fin contrat / suspension / perte-vol / manuel |
| | cleActive | string(72) | oui | `employe_id`+`etablissement_id` si `statut=actif`, sinon `NULL` ; **unique** | garantit 1 badge actif par (employé, étab) — patron `Appairage.supportActif` |
| **PorteeAccesEmploye** (`personnel_portee_acces`) | id | uuid | non | PK | RG-PERSO-06/07 |
| | badgeStaff | OneToOne `BadgeStaff` | non | unique | — |
| | espacesAutorises | ManyToMany `App\Acces\Entity\EspaceAcces` | non | `≥ 1` (validateur) | déclaratif, décision n°5 |
| | modeHoraire | string(18) enum `ModeHoraireBadge` {shifts_uniquement, permanent} | non | — | §4.7 |
| | margeAvantApres | smallint (minutes) | oui | requis si `shifts_uniquement` | tolérance shift |
| *(`DeclarationIncidentBadge` : vue API, pas de table — décision n°8, lit `App\Acces\Entity\DeclarationPerteVol`)* |

> id = UUID (`symfony/uid`). Rattachement multi-entités : **Établissement** obligatoire sur toute
> entité racine sauf `Employe`/`Qualification`/`Absence` (multi-site via `RattachementEmploye`,
> cloisonnement par jointure — §4). **Espace** optionnel sur `CreneauTravail`. Namespace
> `App\Personnel\Entity\*` / `App\Personnel\Enum\*`, `declare(strict_types=1)` partout, noms métier en
> français (constitution §7).

**Enums (`App\Personnel\Enum\*`)** : `TypeContrat`, `StatutEmploye`, `TypeQualification`,
`StatutCreneauTravail`, `MotifRecurrenceTravail`, `StatutAffectationTravail`, `TypeAbsence`,
`StatutAbsence`, `StatutBadgeStaff`, `ModeHoraireBadge`.

**Validateurs (`App\Personnel\Validator\*`)**, patron `App\Acces\Validator\TopologieCoherente` :
- `TopologieTravailCoherente` (sur `CreneauTravail`) — refuse créneau sans établissement,
  `fin ≤ debut`, `effectifRequis ≤ 0` (RG-PERSO-03).
- `QualificationLibelleCoherent` (sur `Qualification`) — `libelle` requis si `type = autre`.

## 2. Extension coordonnée `App\Acces` (point de coordination — décision n°1)

| Fichier | Changement | Impact |
|---|---|---|
| `app/src/Acces/Enum/TypeDroitAcces.php` | + `case Personnel = 'personnel';` | additif, aucune valeur existante touchée |
| `app/src/Acces/Service/ValidationPassageHandler.php` | **aucun** | le seul `match`/`if` sur `sourceType` teste `=== TypeDroitAcces::CarteQuota` (étape 7, décompte crédit) ; un droit `Personnel` (comme `Billet`) ne décompte rien — chemin déjà générique |
| `app/src/Acces/Service/ResolveurMarges.php` | **aucun** | agnostique de `sourceType`, ne lit que `fenetreDebut/Fin` + marges |
| `app/src/Acces/Service/AppairageHandler.php` | **aucun** | agnostique de `sourceType` |
| `app/src/Acces/Service/BlocageSupportHandler.php` | **aucun** | agnostique de `sourceType` |
| `app/src/Acces/Doctrine/PerimetreAccesExtension.php` | **aucun** | `DroitAcces`/`Support`/`Appairage` déjà cloisonnés par `{root}.etablissement`, valable pour un droit `Personnel` |
| `app/src/Vente/Service/GenerateurCodeSupport.php` | **aucun** | réutilisé tel quel avec `App\Vente\Enum\TypeSupport::Carte` (décision n°6) |
| Migration Doctrine | **aucune** | `acces_droit_acces.source_type` déjà `VARCHAR(24)` |

Construction du `DroitAcces` d'un badge staff : **hors** `ProjectionDroitInterface`/`StubProjectionDroit`
(ces derniers sont spécifiques à la projection d'une vente M1/M2, non pertinents ici) — un nouveau
service `App\Personnel\Service\EmissionBadgeStaffHandler` **construit directement** un `DroitAcces`
(`sourceType = Personnel`, `billetSupportRef = null`, `produitRef = null`, `creditRestant = null`,
`etablissement = <étab du badge>`, `fenetreDebut/Fin` selon décision n°3/4, `margeAvanceDefaut =
margeRetardDefaut = margeAvantApres` si `shifts_uniquement`) puis appelle
`App\Acces\Service\AppairageHandler::appairer()` (réutilisé tel quel, `mode = ModeAppairage::Caisse`).

## 3. API (API Platform)

| Ressource | Opérations | `security:` | Groupes sérialisation | Filtres |
|---|---|---|---|---|
| `Employe` | GetCollection/Get (`personnel.lire` **ou** `personnel.lire_soi` + `EmployeSoiVoter`) ; Post/Patch (`personnel.gerer_employe`) ; `Post /personnel/employes/{id}/suspendre` \| `/reactiver` (`EmployeStatutProcessor`, `personnel.gerer_employe`, déclenche suspension/réactivation badge(s) §4.8) | — | `employe:read/write` | `statut`, `poste`, `matricule` |
| `RattachementEmploye` | GetCollection/Get (`personnel.lire`) ; Post/Patch/Delete (`personnel.gerer_employe`) | — | `rattachement:read/write` | `employe`, `etablissement` |
| `Qualification` | GetCollection/Get (`personnel.lire`) ; Post/Patch (`personnel.gerer_qualification`) | — | `qualification:read/write` | `employe`, `type`, `dateValidite` (range, pour lister les qualifs proches d'expirer) |
| `CreneauTravail` | GetCollection/Get (`personnel.lire`) ; `Post /personnel/creneaux-travail` (`CreerCreneauTravailProcessor`, expansion récurrence hebdo) ; `Patch /personnel/creneaux-travail/{id}` (occurrence) ; `Post /personnel/creneaux-travail/{id}/annuler` — tout en écriture : `personnel.gerer_planning` | — | `creneau_travail:read/write` | `etablissement`, `espace`, `debut` (range), `statut` |
| `AffectationTravail` | GetCollection/Get (`personnel.lire`) ; `Post /personnel/affectations` (`AffecterEmployeProcessor` — conflit RG-PERSO-04 + qualification requise) ; `Post /personnel/affectations/{id}/annuler` — écriture : `personnel.gerer_planning` | — | `affectation_travail:read/write` | `creneauTravail`, `employe`, `statut` |
| `Absence` | GetCollection/Get (`personnel.lire` / `personnel.lire_soi`) ; `Post /personnel/absences` (`personnel.gerer_planning` **ou** `personnel.declarer_absence_soi` + voter, statut force `declaree`) ; `Post /personnel/absences/{id}/valider` \| `/refuser` (`ValiderAbsenceProcessor`, `personnel.valider_absence`, déclenche alerte de couverture CA-7) | — | `absence:read/write` | `employe`, `type`, `statut`, `debut` (range) |
| `BadgeStaff` | GetCollection/Get (`personnel.lire` / `personnel.lire_soi`) ; `Post /personnel/employes/{id}/badges` (`EmissionBadgeStaffProcessor`, `personnel.gerer_badge`) ; `Post /personnel/badges/{id}/revoquer` \| `/suspendre` \| `/reactiver` (`RevocationBadgeProcessor`, `personnel.gerer_badge`) | — | `badge_staff:read` | `employe`, `etablissement`, `statut` |
| `PorteeAccesEmploye` | GetCollection/Get (`personnel.lire`) — créée en side-effect de l'émission du badge, pas de `Post` direct | — | `portee_acces:read` | `badgeStaff` |
| `DeclarationIncidentBadge` *(vue, décision n°8)* | GetCollection/Get (`personnel.lire`, `Provider` lisant `DeclarationPerteVol`) ; `Post /personnel/badges/{id}/declarer-incident` (`DeclarerIncidentBadgeProcessor` → délègue à `BlocageSupportHandler::bloquer()`, `personnel.gerer_badge` **ou** `acces.bloquer_support` — délégation ponctuelle §3 spec) ; `Post /personnel/declarations-incident/{id}/annuler` (idem, délègue à `annuler()`) | — | `declaration_incident:read` | `badgeStaff` |
| `RosterHebdomadaire` *(vue, §4.5 spec — pas d'entité)* | `GET /personnel/roster` (`RosterProvider`, agrège `CreneauTravail`+`AffectationTravail`+`Qualification.estValideA()`) | `personnel.lire` | `roster:read` | `etablissement`, `espace`, `poste`, `employe`, `periode` (semaine) |

- **Voter** `App\Personnel\Security\EmployeSoiVoter` (attribut `EMPLOYE_SOI`) : autorise si
  `Security::getUser()` correspond à `Employe.utilisateur` — même patron que
  `App\Reservation\Security\ReservationSoiVoter`. Un employé sans `Utilisateur` (§3 spec) n'a par
  construction **aucun** accès `*_soi` (cohérent, il n'utilise pas le logiciel).
- **`ContexteEtablissement`** (en-tête `X-Etablissement`) cadre toutes les écritures ; `Employe` étant
  multi-site, sa création n'exige pas d'étab actif mais son `RattachementEmploye` si.

## 4. Sécurité & droits

Permissions `personnel.*` (couple `module × action`, RG-SOCLE-02/03/04, portées établissement) —
dérivées par analogie avec `acces`/`reservation` comme la spec le signale (§3, ⚠ à arbitrer M8) :

| Permission | Rôle typique | Usage |
|---|---|---|
| `personnel.gerer_employe` | Administrateur RH | CRUD `Employe`/`RattachementEmploye`, suspendre/réactiver |
| `personnel.gerer_qualification` | Administrateur RH | CRUD `Qualification` |
| `personnel.gerer_badge` | Administrateur RH | émission/révocation/suspension/incident de `BadgeStaff` |
| `personnel.gerer_planning` | Responsable planning | CRUD `CreneauTravail`/`AffectationTravail` |
| `personnel.valider_absence` | Responsable planning | valider/refuser une `Absence` |
| `personnel.lire` | Gestionnaire, lecture seule | lecture référentiel/roster/badges de l'établissement |
| `personnel.lire_soi` | Employé (soi-même) | lecture de sa propre fiche/planning/badge (`EmployeSoiVoter`) |
| `personnel.declarer_absence_soi` | Employé (soi-même) | déclarer sa propre demande d'absence |

Réutilisées telles quelles (module `acces`) : `acces.appairer`, `acces.bloquer_support` (délégation
ponctuelle « badge perdu/volé » par un agent d'accueil, §3 spec).

**Doctrine** : `App\Personnel\Doctrine\PerimetrePersonnelExtension` (patron `PerimetreAccesExtension`) —

| Entité | Chemin de restriction |
|---|---|
| `RattachementEmploye`, `CreneauTravail`, `BadgeStaff` | `{root}.etablissement` (direct) |
| `AffectationTravail` | via jointure `creneauTravail.etablissement` |
| `Employe`, `Qualification`, `Absence` | via jointure sur `RattachementEmploye` actif de l'employé (même patron que `JaugeFmi`/`ListeRevocation` dans `PerimetreAccesExtension`, double-hop) |
| `PorteeAccesEmploye` | via jointure `badgeStaff.etablissement` |

## 5. Migrations

1. **`VersionPersonnel_schema`** — crée `personnel_employe`, `personnel_rattachement`,
   `personnel_qualification`, `personnel_creneau_travail`, `personnel_affectation_travail`,
   `personnel_absence`, `personnel_badge_staff`, `personnel_portee_acces` + table de jointure
   `personnel_portee_acces_espace` (ManyToMany vers `acces_espace_acces`).
   - FKs sortantes vers modules existants (Personnel dépend de Securite/Organisation/Acces — sens
     conforme constitution) : `sec_utilisateur` (`Employe.utilisateur`, `Absence.valideePar`),
     `organisation_etablissement`/`organisation_espace`, `acces_support`, `acces_droit_acces`,
     `acces_espace_acces`.
   - Index : `(employe_id)` sur `personnel_rattachement`/`qualification`/`absence`/`badge_staff` ;
     `(etablissement_id)` sur `personnel_creneau_travail`/`badge_staff` ; unique
     `(creneau_travail_id, employe_id)` sur `personnel_affectation_travail` ; unique `matricule` (si
     non nul) sur `personnel_employe` ; unique `cle_active` sur `personnel_badge_staff` ; unique
     `badge_staff_id` sur `personnel_portee_acces`.
   - Checks : `effectif_requis ≥ 1`, `fin > debut` (créneaux/absences).
   - **Aucune** table/colonne d'`App\Acces`/`App\Piscine`/`App\Padel` modifiée.
2. **`VersionPersonnel_permissions`** — insère `Permission(module='personnel', action ∈
   {gerer_employe, gerer_qualification, gerer_badge, gerer_planning, valider_absence, lire, lire_soi,
   declarer_absence_soi})`.
3. **(hors migration, code)** — `App\Acces\Enum\TypeDroitAcces::Personnel` (§2) : PR séparée et courte
   sur `App\Acces`, sans migration, revue par le propriétaire L3 avant merge de ce lot.

Toutes réversibles (`down()` symétrique), rejouables, aucune modification hors `App\Personnel` +
l'unique ajout d'enum §2 (constitution §7).

## 6. Tests

| Test | Type | Couvre |
|---|---|---|
| `Api\EmployeTest::testCreationSansCompteUtilisateurUtilisablePlanningEtBadge` | Fonctionnel API | CA-1 |
| `Api\EmployeTest::testPlanningMultiEtablissementAvecPosteLocal` | Fonctionnel API | CA-2, RG-PERSO-09 |
| `Unit\QualificationTest::testStatutExpireApresDateValidite` | Unitaire | CA-3, RG-PERSO-02 |
| `Api\AffectationTravailTest::testEmployeNonProposePourQualificationExpiree` | Fonctionnel API | CA-3 |
| `Api\AffectationTravailTest::testConflitChevauchementMemeEtablissementBloque` | Fonctionnel API | CA-4, RG-PERSO-04 |
| `Api\AffectationTravailTest::testConflitChevauchementEtablissementsDifferentsBloque` | Fonctionnel API | CA-4, §7 cas limite |
| `Api\AffectationTravailTest::testAffectationRefuseeSansQualificationValide` | Fonctionnel API | CA-5 |
| `Api\RosterTest::testCouvertureCompletSousCouvertConflitEtQualifManquante` | Fonctionnel API | CA-6 |
| `Api\AbsenceTest::testAffectationRefuseeSurAbsenceValidee` | Fonctionnel API | CA-7 |
| `Api\AbsenceTest::testAlerteCouvertureSiChevaucheAffectationConfirmee` | Fonctionnel API | CA-7 |
| `Api\BadgeStaffTest::testModeShiftsUniquementValideSeulementPendantCreneau` | Fonctionnel API | CA-8 |
| `Api\BadgeStaffTest::testModePermanentValideEnContinu` | Fonctionnel API | CA-8 |
| `Unit\RecalculFenetreBadgeHandlerTest::testFenetreDeplaceeSurProchainCreneauConfirme` | Unitaire | décision n°3 |
| `Api\BadgeStaffPassageTest::testPassageEmployeValideParMemeMoteurQueClient` | Fonctionnel API (réutilise `SimulateurAccesAdapter`) | CA-9, RG-PERSO-07 |
| `Api\BadgeStaffPassageTest::testPassageRefuseHorsFenetreHoraire` | Fonctionnel API | CA-9 |
| `Api\RevocationBadgeTest::testDateSortieRevoqueBadgeImmediatementEtPropage` | Fonctionnel API | CA-10, RG-PERSO-08 |
| `Api\RevocationBadgeTest::testDeclarationPerteVolBloqueImmediatementEtHorsLigne` | Fonctionnel API | CA-11 |
| `Api\RevocationBadgeTest::testSuspensionReversibleSansReAppairage` | Fonctionnel API | §4.8 spec, point ouvert n°10 (décision n°7) |
| `Api\ValiderCreneauBassinReconciliationTest::testCreneauBloqueSansAffectationQualifieeValide` | Fonctionnel API | CA-12 (bridge documenté §7, sur le futur port) |
| `Unit\ValiderCreneauTravailHandlerTest::testBlocageStrictSurveillanceVsAlerteAccueil` | Unitaire | §4.4, §7 cas limite n°6 |
| `Api\CloisonnementPersonnelTest::testUtilisateurNeVoitQueSesEtablissementsAffectes` | Fonctionnel API | RG-SOCLE-05 |
| `Unit\EmployeSoiVoterTest::testEmployeSansUtilisateurNaAucunAccesSoi` | Unitaire | §3 spec |
| `Unit\TypeDroitAccesPersonnelTest::testDroitPersonnelNeDeclencheAucunDecompteCredit` | Unitaire (`App\Acces`) | §2, non-régression moteur L3 |

## 7. Réconciliation Piscine / Padel (mapping documenté, non exécuté dans ce lot)

| Objet existant | Objet cible `App\Personnel` | Notes |
|---|---|---|
| `App\Piscine\Entity\QualificationEncadrant` (`encadrant: Utilisateur`, `type`, `dateValidite`) | `Qualification` (`employe: Employe`, `type`, `dateValidite`) | migration de données : créer un `Employe` par `Utilisateur` encadrant existant (poste = « MNS »/« BNSSA »), puis une `Qualification` équivalente ; `QualificationEncadrant` reste en place tant que `ValiderCreneauBassinHandler` n'est pas basculé |
| `App\Piscine\Entity\AffectationEncadrant` (`creneauBassin`, `qualification: QualificationEncadrant`) | `AffectationTravail` (`creneauTravail`, `qualificationUtilisee: Qualification`) | `CreneauBassin` ↔ `CreneauTravail` via `creneauReservationRef`/lien à créer (aucun lien direct aujourd'hui) |
| `App\Piscine\Service\ValiderCreneauBassinHandler` | (futur) requête `AffectationTravail`/`Qualification` de ce module | nécessite soit un nouveau port (`QualificationSourceInterface` implémenté par Personnel, injecté en Piscine), soit une bascule de source de données ; **non exécuté ici** |
| Rôle **Coach** (`spec-padel.md` §3/§4.8) | `Employe` (poste = « coach padel ») | **aucune entité Coach n'existe encore dans `App\Padel`** (vérifié dans le dépôt) : quand elle sera implémentée, elle doit référencer `Employe`/`Qualification` directement plutôt que dupliquer un modèle d'encadrant — risque de duplication **évité en amont**, pas de dette à résorber |

Cette bascule Piscine est un **risque/chantier futur** (Risque n°4) : `QualificationEncadrant` /
`AffectationEncadrant` / `ValiderCreneauBassinHandler` restent **inchangés** par ce plan.

## 8. Tâches (voir tasks-personnel.md)

T1 enums + entités socle (`Employe`, `RattachementEmploye`, `Qualification` + `estValideA()`) → T2
`CreneauTravail` + validateur topologie + récurrence hebdo → T3 `AffectationTravail` +
`AffecterEmployeProcessor` (conflit chevauchement multi-établissement + garde qualification requise) →
T4 `Absence` + `ValiderAbsenceProcessor` (blocage affectation + alerte couverture) → T5 **coordination
`App\Acces`** : ajout `TypeDroitAcces::Personnel` (PR courte, revue L3) → T6 `EmissionBadgeStaffHandler`
(construit `DroitAcces` + réutilise `AppairageHandler`) + `PorteeAccesEmploye` → T7
`RecalculFenetreBadgeHandler` (event listener + commande cron) → T8 `RevocationBadgeHandler` (réutilise
`BlocageSupportHandler`, 4 déclencheurs) + commande d'échéance `personnel:traiter-echeances-sortie` → T9
`DeclarationIncidentBadge` (vue + processors délégués) → T10 `RosterProvider` (vue agrégée) → T11 API +
sérialisation + droits (`personnel.*`) + `EmployeSoiVoter` → T12
`PerimetrePersonnelExtension` (cloisonnement, y compris double-hop) → T13 migrations + permissions → T14
tests (dont non-régression `App\Acces` T5).

## 9. Risques / à valider

1. **`PorteeAccesEmploye.espacesAutorises` non appliquée par le moteur** (décision n°5) — vraie
   restriction par espace = évolution future de `ValidationPassageHandler`, **à cadrer avec le
   propriétaire L3**, hors périmètre de ce lot. Impact sécurité potentiel si un site compte sur cette
   portée pour restreindre physiquement l'accès staff (ex. local technique).
2. **1 badge par (employé, établissement) plutôt que 1 par employé** (décision n°2) — divergence assumée
   vs la lettre de la spec §5, imposée par le modèle mono-établissement de `Support`/`DroitAcces` (Acces
   L3) — **à confirmer produit**.
3. **Fenêtre `shifts_uniquement` = une seule plage à la fois** (décision n°3), recalculée par cron
   ~5 min + événements — imprécision possible entre deux shifts rapprochés (< marge) ou pendant la
   fenêtre de latence du cron ; **à valider produit** (fréquence de recalcul, granularité acceptable).
4. **Réconciliation Piscine (`QualificationEncadrant`/`AffectationEncadrant` → `Qualification`/
   `AffectationTravail`)** — mapping documenté §7 mais **non exécuté** ; migration de données et
   double-écriture temporaire à prévoir dans un lot dédié, cohérent avec le point ouvert n°8 de la spec.
5. **Comptage du badge staff dans la jauge FMI/POSS** — aucune action requise (le moteur incrémente déjà
   toute entrée quel que soit `sourceType`), mais l'hypothèse « oui, compté » (§4.7 spec, ⚠ non tranchée
   par les sources) a un **impact sécurité ERP potentiel** sur le calcul du seuil — à confirmer avec un
   expert ERP/sécurité avant mise en production.
6. **Listes ouvertes non chiffrées** (`TypeContrat`, `TypeQualification`) — retenues comme `enum` PHP
   fermé dans ce plan par cohérence technique (Doctrine `enumType`), alors que la spec les qualifie
   d'« ouvertes et paramétrables » (§4.1/4.2) — **à trancher produit** : soit ajout de cas au fil de
   l'eau (migration mineure à chaque nouveau type, comme aujourd'hui), soit passage à un référentiel
   paramétrable en base (patron `ReferentielTypeRessource` de `App\Reservation`) dans un lot futur.
7. **Alerte de recyclage à échéance proche** (§4.2/§7 spec, point ouvert n°4) — **non implémentée** dans
   ce lot (aucun canal de notification cadré) ; le filtre `dateValidite` (range) sur l'API `Qualification`
   permet une requête manuelle en attendant.
8. **RGPD — données RH light** — `Employe` porte des données personnelles minimales (identité, poste,
   contrat, dates) : **aucune** donnée sensible (pas de NIR/SSN, pas d'IBAN, pas d'adresse/téléphone
   dans ce lot). **Durée de conservation** après `dateSortie` **non tranchée par les sources** — à
   confirmer avec le DPO/juridique (proposition par défaut : anonymisation `nom`/`prenom`/`matricule`
   après N années réglementaires, hors périmètre technique de ce lot — pas de commande de purge livrée
   ici, seulement le champ `dateSortie` qui permettrait de la piloter plus tard). Traçabilité des
   révocations/suspensions déjà couverte par `App\Audit` (RG-SOCLE-07), réutilisé sans modification.
9. **Blocage strict vs alerte non bloquante en sous-effectif** (§4.4/§7 spec, point ouvert n°6) — retenu
   par type de poste (bloquant seulement si `qualificationRequise` non couverte, RG-PISC-02) ; à
   confirmer si d'autres postes réglementés (hors piscine) doivent aussi bloquer.
10. **Numérotation `US-PERSO` hors backlog** (préambule spec) — ce plan peut nécessiter un réajustement
    mineur de nommage une fois la validation produit faite ; aucun impact structurel attendu sur le
    modèle de données ci-dessus.
11. **Suspension employé (`Employe.statut=suspendu`) vs suspension badge** — ce plan suspend
    **automatiquement** le(s) `BadgeStaff` actif(s) quand `EmployeStatutProcessor` bascule l'employé en
    `suspendu`, et les réactive à la levée — comportement **non détaillé explicitement** par la spec
    (qui ne lie la suspension qu'au badge, §4.8), **à confirmer** que la réciproque (suspension employé
    ⇒ suspension badge automatique) est bien le comportement attendu et pas seulement une action
    manuelle distincte de l'Administrateur RH.
