# Plan technique — Réservation & no-show générique (`M5` · module `App\Reservation`)

- **Spec source :** specs/reservation/spec-reservation.md (US-RES-01 à 14, RG-M5-01 à 12)
- **Stack :** Symfony 7 · API Platform · Doctrine/MariaDB
- **Précédents réutilisés (lecture avant conception) :** `App\Vente` (Vente/Avoir/ContrePassationHandler,
  `PorteMonnaieVirtuelInterface`), `App\Sepa` (`MandatSepa`, `EcheanceSepaSource`,
  `CompositeEcheanceSepaSource`, pattern `App\Sport\Sepa\SportEcheanceSepaSource`), `App\Recouvrement`
  (`RedevablePort`, `PropagationAccesHandler`), `App\Acces` (`DroitAcces`, `ProjectionDroitInterface`),
  `App\Crm` (`Beneficiaire.client`, `Client`), `App\Offre` (`Produit`, `Formule`, `ServiceInclus` — déjà
  préparé côté M1 avec `ServiceInclus.activiteRef: Uuid` en **référence logique** « vers une Activité
  (M5) », confirmant que ce module en est le propriétaire fonctionnel), `App\Piscine` (`Bassin`,
  `LigneEau`, `CreneauBassin` — modèle de réconciliation §7).

## 0. Décisions structurantes (résumé)

1. **`codeType` de Ressource = string libre**, validée par une contrainte `TypeRessourceValide` contre
   un **référentiel extensible** (`ReferentielTypeRessource`, liste de codes connus + `actif=true`
   toléré pour tout code non listé émis par une verticale — pas d'`enum` figé, constitution §4.4).
2. **Priorité des `RegleAnnulation`** en cas de portées multiples applicables : **activité > ressource >
   type_ressource > établissement** (la plus spécifique l'emporte), résolue par
   `ResolveurRegleAnnulation`. ⚠ à confirmer produit (spec §4.7, point ouvert n°4).
3. **`margePostCreneauMinutes`** (bascule auto no-show) : paramètre par `RegleAnnulation`, **défaut =
   0**. Si aucune `RegleAnnulation` active ne couvre le créneau, le comportement historique s'applique
   (place/quota perdu, **aucune** `FacturationNoShow` créée — spec §7 cas limite).
4. **Facturation no-show = stratégie enfichable** (`StrategieFacturationNoShow`), résolue par
   `RegleAnnulation.modeFacturation` via `ResolveurStrategieFacturation` (tag DI
   `reservation.strategie_facturation_no_show`). **Réellement branchées dans ce lot :**
   `vente_differee_agent` (crée une `Vente` M2 quand un agent traite le dossier depuis une session
   caisse ouverte) et `debit_pmv` (débit automatique via `PorteMonnaieVirtuelInterface`, cf. §2.4 pour
   le mécanisme de **session système** qui débloque une `Vente` sans agent présent). **Squelette
   documenté, non branché à un encaissement réel :** `prelevement_differe` (implémente
   `App\Sepa\Port\EcheanceSepaSource`, comme `SportEcheanceSepaSource`) et `facture_a_encaisser` (statut
   déclaratif, en attente d'un objet « Facture » côté M6, absent de ce dépôt).
5. **Réconciliation Piscine (`Bassin`/`LigneEau`/`CreneauBassin`)** : **non traitée dans ce lot** — ce
   plan livre le socle générique et un mapping documenté (§7), la bascule effective de la verticale
   Piscine est un risque/tâche future (Risque n°3).
6. **Lien accès (`ProjectionAccesReservation`)** : entité locale prête, mais la projection réelle vers
   `App\Acces\Entity\DroitAcces` nécessite une extension du port `ProjectionDroitInterface` côté L3
   (nouveau `TypeDroitAcces::Reservation`), **hors périmètre de ce lot** (Risque n°2) — implémentée en
   no-op documenté (log) en attendant.
7. **« Quota » (mentionné en cadrage) n'est pas une entité dédiée** : la jauge d'un créneau =
   `Creneau.capacite` vs décompte des `Reservation.statut=confirmee` ; la jauge de la ressource mère
   (RG-M5-08) = `Ressource.occupationCourante` (même patron que `Piscine\Bassin.occupationCourante`) ;
   le quota inclus d'une formule (RG-M1-12) est décompté via `App\Offre\Service\SimulateurQuota`,
   consommé par un nouveau port `FormuleBeneficiaireInterface` (frontière M1/M4, stub par défaut — voir
   Risque n°5).

## 1. Entités & schéma

| Entité (`App\Reservation\Entity\*`) | Champ | Type Doctrine | Null | Index/Contrainte | Relation |
|---|---|---|---|---|---|
| **Ressource** (`reservation_ressource`) | id | uuid | non | PK | — |
| | etablissement | ManyToOne `Etablissement` | non | index | RG-SOCLE-01 |
| | espace | ManyToOne `Espace` | oui | index | rattachement fin optionnel |
| | codeType | string(40) | non | validé `TypeRessourceValide` | terrain/glace/ligne_eau/salle/court/table/personnel… (RG-M5-03/05) |
| | libelle | string(120) | non | — | — |
| | capacitePropre | smallint ≥1 | non | `Assert\Positive` | RG-M5-01 |
| | partageable | bool | non, défaut false | — | RG-M5-08 |
| | ressourceMere | ManyToOne `Ressource` (self) | oui | requis si sous-ressource | ex. ligne d'eau ↔ bassin |
| | competenceRequise | string(80) | oui | — | RG-M5-05, attribut générique |
| | occupationCourante | int | non, défaut 0 | `Assert\PositiveOrZero` | jauge mère RG-M5-08, maintenue si `partageable` |
| | ouvreAcces | bool | non, défaut false | — | active `ProjectionAccesReservation` (RG-M5-12) |
| | actif | bool | non, défaut true | — | — |
| **DisponibiliteRessource** (`reservation_disponibilite`) | id | uuid | non | PK | — |
| | ressource | ManyToOne `Ressource` | non | index | borne création de Créneau |
| | jourSemaine | smallint (1-7) | non | `Assert\Range(1,7)` | — |
| | heureDebut, heureFin | time | non | début<fin | — |
| **IndisponibiliteRessource** (`reservation_indisponibilite`) | id | uuid | non | PK | — |
| | ressource | ManyToOne `Ressource` | non | index | congé/maintenance |
| | debut, fin | datetime_immutable | non | début<fin | — |
| | motif | string(255) | oui | — | — |
| **Activite** (`reservation_activite`) *(propriété fonctionnelle M5, référencée par `ServiceInclus.activiteRef` M1)* | id | uuid | non | PK | — |
| | etablissement | ManyToOne `Etablissement` | non | index | — |
| | libelle | string(120) | non | — | cahier M5-02 |
| | typeActivite | string(60) | non | — | — |
| | dureeMinutes | smallint ≥1 | non | hérité par défaut sur le Créneau | — |
| | niveauRequis | string(60) | oui | filtre la réservation | — |
| | competenceExigee | string(80) | oui | miroir `Ressource.competenceRequise` | RG-M5-05 |
| | produitTarifReference | ManyToOne `App\Offre\Entity\Produit` | oui | FK réelle (M5 dépend de M1) | tarif vente à l'unité §4.3 |
| | actif | bool | non, défaut true | — | — |
| **Recurrence** (`reservation_recurrence`) | id | uuid | non | PK | RG-M5-07 |
| | etablissement | ManyToOne `Etablissement` | non | index | — |
| | motif | string(16) enum `MotifRecurrence` | non | hebdomadaire/quotidien/mensuel | — |
| | finRecurrence | date_immutable | non | — | — |
| | joursSemaine | json (`list<int>`) | oui | requis si hebdo | — |
| | regleConflit | string(20) enum `RegleConflitRecurrence` | non, défaut `report_auto` | — | RG-M5-11 |
| **Creneau** (`reservation_creneau`) | id | uuid | non | PK | RG-M5-01/03 |
| | ressource | ManyToOne `Ressource` | non | index (ressource, debut, fin) | pas de chevauchement RG-M5-03 |
| | activite | ManyToOne `Activite` | oui | — | hérite durée/tarif |
| | debut, fin | datetime_immutable | non | `Assert\Expression` début<fin | — |
| | capacite | smallint ≥1 | non | peut différer de `Ressource.capacitePropre` | — |
| | statut | string(10) enum `StatutCreneau` | non, défaut `planifie` | planifie/complet/termine/annule | — |
| | publicReserve | string(80) | oui | masque en ligne | RG-M5-08 |
| | recurrence | ManyToOne `Recurrence` | oui | — | — |
| | occurrenceModifiee | bool | non, défaut false | — | exception série, RG-M5-07 |
| | enAttenteArbitrage | bool | non, défaut false | — | conflit récurrence sans ressource équivalente, RG-M5-11 |
| | etablissement | ManyToOne `Etablissement` | non | dénormalisé (copie `ressource.etablissement`, immuable) | perf cloisonnement |
| **Reservation** (`reservation_reservation`) | id | uuid | non | PK | RG-M5-01/02/09 |
| | creneau | ManyToOne `Creneau` | non | index | — |
| | organisateur | ManyToOne `App\Crm\Entity\Beneficiaire` | non | index | RG-M4-02, payeur potentiellement distinct |
| | statut | string(24) enum `StatutReservation` | non, défaut `confirmee` | cahier §6 | — |
| | dateCreation | datetime_immutable | non | — | — |
| | modeDecompte | string(14) enum `ModeDecompteReservation` | non | quota_formule/vente_unite/gratuit | RG-M5-02 |
| | serviceInclusRef | ManyToOne `App\Offre\Entity\ServiceInclus` | oui | requis si `quota_formule` | RG-M1-12 |
| | venteRattachee | ManyToOne `App\Vente\Entity\Vente` | oui | requis si `vente_unite` | RG-M5-02 |
| | montantDu | decimal(10,2) ≥0 | non, défaut 0.00 | dérivé du tarif de référence | — |
| | dateLimiteAnnulation | datetime_immutable | oui | = début créneau − délai franc | RG-M5-04/09 |
| | presenceConfirmee | bool | non, défaut false | — | §4.8 |
| | dateConfirmationPresence | datetime_immutable | oui | requis si `presenceConfirmee` | — |
| | sourcePresence | string(18) enum `SourcePresence` | oui | emargement_manuel/passage_acces | — |
| | etablissement | ManyToOne `Etablissement` | non | dénormalisé (copie `creneau.etablissement`) | cloisonnement |
| **ParticipantReservation** (`reservation_participant`) | id | uuid | non | PK | RG-M5-10 |
| | reservation | ManyToOne `Reservation` | non | unique(reservation, personne) | — |
| | personne | ManyToOne `Beneficiaire` | non | — | — |
| | estOrganisateur | bool | non, défaut false | 1 seul par réservation (contrainte applicative) | — |
| | partMontant | decimal(10,2) ≥0 | non | Σ parts = `montantDu` | — |
| | statutPaiement | string(20) enum `StatutPaiementParticipant` | non, défaut `en_attente` | paye/en_attente/impute_organisateur | RG-M5-10 |
| **ListeAttente** (`reservation_liste_attente`) | id | uuid | non | PK | RG-M5-06 |
| | creneau | ManyToOne `Creneau` | non | unique(creneau, rang) | — |
| | beneficiaire | ManyToOne `Beneficiaire` | non | — | — |
| | rang | smallint ≥1 | non | — | premier arrivé premier servi |
| | dateInscription | datetime_immutable | non | — | — |
| | statut | string(12) enum `StatutListeAttente` | non, défaut `en_attente` | en_attente/promue/expiree/annulee | — |
| | promueEn | ManyToOne `Reservation` | oui | requis si `promue` | RG-M5-06 |
| | dateExpirationPromotion | datetime_immutable | oui | délai confirmation, défaut 15 min | §7 point 11 |
| **RegleAnnulation** (`reservation_regle_annulation`) | id | uuid | non | PK | RG-M5-09 |
| | etablissement | ManyToOne `Etablissement` | non | racine toujours renseignée | — |
| | portee | string(14) enum `PorteeRegleAnnulation` | non | établissement/type_ressource/ressource/activité | résolution plus spécifique |
| | cibleTypeRessource | string(40) | oui | requis si portée=type_ressource | — |
| | cibleRessource | ManyToOne `Ressource` | oui | requis si portée=ressource | — |
| | cibleActivite | ManyToOne `Activite` | oui | requis si portée=activité | — |
| | delaiFrancMinutes | int ≥0 | non | ex. 1440 (24h) | — |
| | modeMontant | string(11) enum `ModeMontantAnnulation` | non | fixe/pourcentage | — |
| | valeurMontant | decimal(10,2) ≥0 | non | montant ou % | — |
| | exonerations | json | oui | liste {motif, condition} | — |
| | modeFacturation | string(20) enum `ModeFacturationNoShow` | non | vente_differee_agent/debit_pmv/prelevement_differe/facture_a_encaisser | ⚠ point ouvert §8 spec |
| | margePostCreneauMinutes | int ≥0 | non, défaut 0 | bascule auto no-show | §4.8 |
| | actif | bool | non, défaut true | — | — |
| **Emargement** (`reservation_emargement`) | id | uuid | non | PK | cahier M5-05 |
| | reservation | ManyToOne `Reservation` | non | unique (1 émargement courant par réservation) | — |
| | statut | string(8) enum `StatutEmargement` | non | present/absent | horodaté, modifiable jusqu'à clôture |
| | horodatage | datetime_immutable | non | — | — |
| | operateur | ManyToOne `App\Securite\Entity\Utilisateur` | oui | — | émargement manuel |
| | compteRendu | text | oui | — | — |
| **FacturationNoShow** (`reservation_facturation_no_show`) | id | uuid | non | PK | RG-M5-09 |
| | reservation | ManyToOne `Reservation` | non | unique (1:1) | — |
| | regleAppliquee | ManyToOne `RegleAnnulation` | non | — | — |
| | montant | decimal(10,2) ≥0 | non | — | — |
| | statut | string(12) enum `StatutFacturationNoShow` | non, défaut `a_facturer` | a_facturer/facturee/exoneree/contestee | — |
| | venteRattachee | ManyToOne `App\Vente\Entity\Vente` | oui | requis si `facturee` via vente | articulation §8 |
| | referenceEcheanceSepa | string(64) | oui | requis si `modeFacturation=prelevement_differe` | réf. opaque pour `EcheanceSepaSource` |
| | exonerePar | ManyToOne `Utilisateur` | oui | requis si `exoneree` | `reservation.exonerer`, tracé |
| | motifExoneration | string(255) | oui | requis si `exoneree` | — |
| **ProjectionAccesReservation** (`reservation_projection_acces`) | id | uuid | non | PK | RG-M5-12 |
| | reservation | ManyToOne `Reservation` | non | unique (1:1) | — |
| | droitAccesRef | uuid | oui | référence logique `DroitAcces` (L3), nullable tant que port non étendu (Risque n°2) | — |
| | fenetreDebut, fenetreFin | datetime_immutable | non | = fenêtre créneau ± tolérance | RG-ACC-01 |
| | margeAvanceMinutes, margeRetardMinutes | int | oui | tolérance paramétrable | — |
| | etablissement | ManyToOne `Etablissement` | non | — | cloisonnement |

> id = UUID (`symfony/uid`). Rattachement multi-entités : **Établissement** (obligatoire sur toute
> entité racine), **Espace** (optionnel sur `Ressource`). Toutes les tables portent
> `strict_types=1` et suivent les conventions `App\Reservation\Entity\*` / `App\Reservation\Enum\*`.

**Enums (`App\Reservation\Enum\*`)** : `StatutCreneau`, `MotifRecurrence`, `RegleConflitRecurrence`,
`StatutReservation`, `ModeDecompteReservation`, `SourcePresence`, `StatutPaiementParticipant`,
`StatutListeAttente`, `PorteeRegleAnnulation`, `ModeMontantAnnulation`, `ModeFacturationNoShow`,
`StatutFacturationNoShow`, `StatutEmargement`.

## 2. API (API Platform)

| Ressource | Opérations | security: | Groupes sérialisation | Filtres |
|---|---|---|---|---|
| `Ressource` | GetCollection/Get ; Post/Patch | lire → `reservation.lire` ; écriture → `reservation.gerer_ressource` | `ressource:read`/`write` | `codeType`, `etablissement`, `espace`, `actif` (SearchFilter) |
| `DisponibiliteRessource` / `IndisponibiliteRessource` | GetCollection/Get ; Post/Patch/Delete | idem `Ressource` | `dispo:read/write` | `ressource` |
| `Activite` | GetCollection/Get ; Post/Patch | lire → `reservation.lire` ; écriture → `reservation.gerer_ressource` | `activite:read/write` | `typeActivite`, `actif` |
| `Recurrence` | GetCollection/Get (lecture seule directe, création via `Creneau`) | `reservation.lire` | `recurrence:read` | — |
| `Creneau` | GetCollection/Get ; `Post /reservation/creneaux` (`CreerCreneauProcessor`, expansion récurrence) ; `Patch /reservation/creneaux/{id}` (occurrence unique, `ModifierOccurrenceProcessor`) ; `Post /reservation/creneaux/{id}/annuler` (`AnnulerCreneauProcessor`) ; `Post /reservation/creneaux/{id}/arbitrer` (`ArbitrerConflitRecurrenceProcessor`) | lire → `reservation.lire` ; créer/modifier/annuler → `reservation.gerer_creneau` ; arbitrer → `reservation.arbitrer_recurrence` | `creneau:read/write` | `ressource`, `activite`, `statut`, `debut` (range) |
| `Reservation` | GetCollection/Get ; `Post /reservation/reservations` (`ReserverProcessor`) ; `Post /reservation/reservations/{id}/annuler` (`AnnulerReservationProcessor`) | lire → `reservation.lire` ou (`reservation.lire_soi` + `ReservationSoiVoter`) ; réserver → `reservation.reserver` ou `reservation.reserver_soi` ; annuler → `reservation.annuler` ou (`reservation.annuler_soi` + voter) | `reservation:read/write` | `creneau`, `organisateur`, `statut` |
| `ParticipantReservation` | `Post /reservation/reservations/{id}/participants` (`AjouterParticipantProcessor`) ; `Post /reservation/reservations/{id}/participants/{pid}/payer` (`PayerPartProcessor`, déclenche vente à l'unité M2) | `reservation.reserver` ou `reservation.reserver_soi` (soi = organisateur uniquement) | `participant:read/write` | — |
| `ListeAttente` | GetCollection/Get ; `Post /reservation/creneaux/{id}/liste-attente` (`InscrireListeAttenteProcessor`) | lire → `reservation.lire`/`reservation.lire_soi` ; inscrire → `reservation.reserver`/`reservation.reserver_soi` | `liste_attente:read` | `creneau`, `statut` |
| `RegleAnnulation` | GetCollection/Get ; Post/Patch | lire → `reservation.lire` ; écriture → `reservation.parametrer_annulation` | `regle_annulation:read/write` | `portee`, `actif` |
| `Emargement` | GetCollection/Get ; `Post /reservation/reservations/{id}/emarger` (`EmargerProcessor`, upsert) | lire → `reservation.lire` ; émarger → `reservation.emarger` | `emargement:read/write` | `reservation` |
| `FacturationNoShow` | GetCollection/Get ; `Post /reservation/facturations-no-show/{id}/exonerer` (`ExonererProcessor`) ; `Post /reservation/facturations-no-show/{id}/emettre-vente` (`EmettreVenteNoShowProcessor`) | lire → `reservation.lire`/`compta.lire` ; exonérer → `reservation.exonerer` ; émettre → `reservation.facturer` | `facturation_no_show:read` | `statut`, `reservation` |
| `ProjectionAccesReservation` | GetCollection/Get (créée en side-effect, pas de Post direct) | `reservation.lire` | `projection_acces:read` | `reservation` |

## 3. Sécurité & droits

Permissions `reservation.*` (couple `module × action`, RG-SOCLE-02/03/04, portées établissement/espace) :

| Permission | Rôle typique | Usage |
|---|---|---|
| `reservation.lire` | Gestionnaire, opérateur, agent | lecture toutes réservations de l'établissement |
| `reservation.lire_soi` | Client/organisateur | lecture de ses propres réservations (`ReservationSoiVoter`) |
| `reservation.gerer_ressource` | Gestionnaire de planning | CRUD `Ressource`/`Activite`/disponibilités |
| `reservation.gerer_creneau` | Gestionnaire de planning | CRUD `Creneau`, récurrence, annulation créneau |
| `reservation.parametrer_annulation` | Gestionnaire de planning | CRUD `RegleAnnulation` |
| `reservation.reserver` | Agent d'accueil | réserver/inscrire liste d'attente pour un bénéficiaire |
| `reservation.reserver_soi` | Client/organisateur | réserver pour soi/ses participants |
| `reservation.annuler` | Agent d'accueil | annuler dans/hors délai franc |
| `reservation.annuler_soi` | Client/organisateur | annuler sa propre réservation (refusé hors délai, CA-8) |
| `reservation.emarger` | Opérateur de ressource | émargement présent/absent |
| `reservation.exonerer` | Responsable/Admin | exonération tracée d'un no-show |
| `reservation.forcer` | Responsable/Admin | réouverture forcée d'un créneau |
| `reservation.arbitrer_recurrence` | Responsable/Admin | validation manuelle d'un conflit de récurrence |
| `reservation.facturer` | Agent d'accueil | émission différée d'une vente no-show (nouveau, dérivé de la capacité « déclencher la vente à l'unité vers M2 » du tableau acteurs §3 de la spec — nommage technique ajouté par ce plan) |

**Voters** :
- `ReservationSoiVoter` (attribut `RESERVATION_SOI`) : autorise si `Security::getUser()` correspond au
  `Beneficiaire.client` de `Reservation.organisateur` **ou** d'un `ParticipantReservation`, même patron
  que `App\Recouvrement\Security\RedevableSoiVoter`.

**Doctrine** : `App\Reservation\Doctrine\PerimetreReservationExtension` (cloisonnement établissement,
patron `PerimetreVenteExtension`) sur toutes les entités racines listées §1.

## 4. Migrations

Une migration Doctrine (`VersionYYYYMMDDHHMMSS_reservation.php`) crée :
- Tables : `reservation_ressource`, `reservation_disponibilite`, `reservation_indisponibilite`,
  `reservation_activite`, `reservation_recurrence`, `reservation_creneau`, `reservation_reservation`,
  `reservation_participant`, `reservation_liste_attente`, `reservation_regle_annulation`,
  `reservation_emargement`, `reservation_facturation_no_show`, `reservation_projection_acces`.
- FKs sortantes vers modules existants (dépendance dans le bon sens, M5 dépend de M1/M2/M4) :
  `off_produit` (Activite), `off_service_inclus` (Reservation), `vente_vente` (Reservation,
  FacturationNoShow), `crm_beneficiaire` (Reservation.organisateur, ParticipantReservation.personne,
  ListeAttente.beneficiaire), `organisation_etablissement`/`organisation_espace`,
  `securite_utilisateur` (Emargement.operateur, FacturationNoShow.exonerePar).
- Index : `(ressource_id, debut, fin)` sur `reservation_creneau` (recherche de chevauchement),
  unique `(creneau_id, rang)` sur `reservation_liste_attente`, unique `(reservation_id, personne_id)`
  sur `reservation_participant`, unique `reservation_id` sur `reservation_facturation_no_show` et
  `reservation_projection_acces`.
- Aucune modification de tables hors `App\Reservation` (constitution : ce lot ne touche que
  `specs/reservation/` en spec, et `App\Reservation\*` en implémentation future — pas de migration sur
  `App\Acces`/`App\Piscine`/`App\Offre` dans ce lot).

## 5. Tests

| Test | Type | Couvre |
|---|---|---|
| `Api\RessourceCreneauTest::testCreationRessourceTypeParametrable` | Fonctionnel API | CA-1 |
| `Api\RessourceCreneauTest::testChevauchementBloque` | Fonctionnel API | CA-2, RG-M5-03 |
| `Api\ReservationQuotaVenteTest::testReservationDecompteQuota` | Fonctionnel API | CA-3 (quota) |
| `Api\ReservationQuotaVenteTest::testReservationDeclencheVenteUnite` | Fonctionnel API | CA-3 (vente unité) |
| `Api\ReservationQuotaVenteTest::testCreneauCompletProposeListeAttente` | Fonctionnel API | CA-4 |
| `Api\ListeAttenteTest::testPromotionAutomatiqueAuDesistement` | Fonctionnel API | CA-5, RG-M5-06 |
| `Api\ListeAttenteTest::testExpirationPromotionSansConfirmation` | Fonctionnel API | §7 point 11 |
| `Api\RecurrenceTest::testModificationUneSeuleOccurrence` | Fonctionnel API | CA-6, RG-M5-07 |
| `Api\RecurrenceTest::testReportAutoSurRessourceEquivalente` | Fonctionnel API | CA-7 (report réussi) |
| `Api\RecurrenceTest::testBasculeValidationManuelleSiAucuneAlternative` | Fonctionnel API | CA-7 (bascule manuelle) |
| `Api\AnnulationNoShowTest::testAnnulationGratuiteDansDelaiFranc` | Fonctionnel API | CA-8 (gratuite) |
| `Api\AnnulationNoShowTest::testAnnulationRefuseeHorsDelaiFranc` | Fonctionnel API | CA-8 (refus + annulée tardive) |
| `Api\AnnulationNoShowTest::testAnnulationExactementAuDelaiFrancEncoreGratuite` | Fonctionnel API | §7 cas limite (borne inclusive) |
| `Api\AnnulationNoShowTest::testBasculeAutoNoShowApresMarge` | Fonctionnel API | CA-9 |
| `Api\AnnulationNoShowTest::testPassageAccesValideConfirmePresence` | Fonctionnel API | CA-10 |
| `Api\FacturationNoShowStrategiesTest::testVenteDiffereeAgentEmiseParAgent` | Fonctionnel API | CA-11 (mode `vente_differee_agent`) |
| `Api\FacturationNoShowStrategiesTest::testDebitPmvAutomatiqueSansAgent` | Fonctionnel API | CA-11 (mode `debit_pmv`) |
| `Api\FacturationNoShowStrategiesTest::testPrelevementDiffereGenereEcheanceSepa` | Fonctionnel API | mode `prelevement_differe` (squelette, vérifie `EcheanceSepaSource`) |
| `Api\FacturationNoShowStrategiesTest::testExonerationTraceeSansEncaissement` | Fonctionnel API | CA-12 |
| `Api\FacturationNoShowStrategiesTest::testAucuneRegleActiveAucuneFacturation` | Fonctionnel API | §7 cas limite (défaut sans règle) |
| `Api\PaiementPartageTest::testChaquePartPayeeIndividuellement` | Fonctionnel API | CA-13 (paiement complet) |
| `Api\PaiementPartageTest::testDefautPaiementImputeOrganisateur` | Fonctionnel API | CA-13 (défection) |
| `Api\RessourcePartageableTest::testJaugeRessourceMereCoherente` | Fonctionnel API | CA-14, RG-M5-08 |
| `Api\ProjectionAccesTest::testProjectionCreeeSurConfirmation` | Fonctionnel API | CA-15 (fenêtre + no-op documenté droitAccesRef) |
| `Api\CloisonnementTest::testUtilisateurNeVoitQueSonEtablissement` | Fonctionnel API | RG-SOCLE-05 |
| `Unit\ResolveurRegleAnnulationTest::testPrioriteActiviteRessourceTypeEtablissement` | Unitaire | décision structurante n°2 |
| `Unit\QuotaFormuleResolverTest::testQuotaRestantSemaineCalendaireSansReport` | Unitaire | RG-M1-12, `SimulateurQuota` |
| `Unit\RecurrenceExpansionHandlerTest::testGenerationOccurrencesHebdo` | Unitaire | RG-M5-07 |
| `Unit\JaugeRessourceMereHandlerTest::testDecrementSurAnnulationSousRessource` | Unitaire | RG-M5-08 |

## 6. Tâches (voir tasks-reservation.md)
- T1 … T21 (ordonnées, voir fichier tâches) — entités socle → chevauchement/récurrence → réservation
  quota/vente → liste d'attente → annulation/no-show → stratégies de facturation → jauge partageable →
  projection accès → cloisonnement & tests finaux.

## 7. Réconciliation Piscine (mapping documenté, non exécuté dans ce lot)

| Objet Piscine (`App\Piscine`) | Objet cible `App\Reservation` | Notes |
|---|---|---|
| `Bassin` | `Ressource(partageable=true, codeType='bassin')` | `Bassin.capacite` → `Ressource.capacitePropre` ; `Bassin.occupationCourante` → `Ressource.occupationCourante` (même sémantique déjà présente côté Piscine, confirme le patron retenu §1 note 7) |
| `LigneEau` | `Ressource(ressourceMere=<Bassin ressource>, codeType='ligne_eau')` | `LigneEau.numero` → `Ressource.libelle` |
| `CreneauBassin` | `Creneau(ressource=<Bassin ou LigneEau ressource>)` | `encadrantRequis` → `Activite.competenceExigee` ou `Ressource.competenceRequise` selon modélisation retenue ; `statut` (brouillon/valide) à faire correspondre à `Creneau.statut` (planifie/annule) — écart de vocabulaire à trancher |
| `CreneauPublic` | `Creneau.publicReserve` | — |

Cette bascule est un **risque/chantier futur** (Risque n°3), non exécutée ici : les entités Piscine
restent en place, ce module ne les modifie pas.

## 8. Risques / à valider

1. **Facturation à distance sans agent (point ouvert majeur, spec §8)** — `FacturationNoShow.statut =
   à_facturer` sans garantie d'encaissement pour `prelevement_differe`/`facture_a_encaisser` (squelettes
   documentés). Pour `debit_pmv` (réellement branché), l'absence d'agent/session caisse au moment de la
   bascule auto no-show est résolue par un mécanisme de **« session système »** (une `SessionCaisse`
   technique permanente par établissement, condition nécessaire car `Vente.session` est **non
   nullable** dans `App\Vente\Entity\Vente`) — ce choix a des implications **NF525/comptables** (vente
   sans opérateur humain identifié) qui **doivent être validées par un expert compta/NF525** avant mise
   en production (constitution §4 point 5).
2. **Port `ProjectionDroitInterface` (L3) spécialisé billet M2** (`billetSupportRef`,
   `TypeDroitAcces::{Billet,Abonnement,CarteQuota}`) — projeter un droit d'accès depuis une
   `Reservation` nécessite une extension côté `App\Acces` (nouveau cas `Reservation`), **hors périmètre
   de ce lot**. `ProjectionAccesReservation.droitAccesRef` reste nullable, la projection réelle est un
   no-op documenté en attendant.
3. **Réconciliation Piscine** (`Bassin`/`LigneEau`/`CreneauBassin` → `Ressource`/`Creneau`) — mapping
   documenté §7 mais **non exécuté** ; migration de données et double-écriture temporaire à prévoir
   dans un lot dédié.
4. **Priorité des `RegleAnnulation` de portées différentes** — retenue « la plus spécifique l'emporte »
   (activité > ressource > type_ressource > établissement), **à confirmer produit** (spec §4.7 point
   ouvert n°4).
5. **Absence de modélisation « bénéficiaire détient une formule active »** côté M1/M4 — le port
   `FormuleBeneficiaireInterface` est stubé (renvoie toujours `null`) tant qu'un lot M1/M4 ne modélise
   pas explicitement ce rattachement ; conséquence : toute réservation basculera par défaut en vente à
   l'unité (`modeDecompte=vente_unite`) tant que ce port n'est pas câblé à l'intégration.
6. **Valeurs par défaut non chiffrées par les sources** — `margePostCreneauMinutes` (défaut 0) et délai
   de confirmation de promotion liste d'attente (défaut 15 min, analogie panier M3) — **à valider
   produit** (spec §7 points 5 et 11).
7. **Non-cumul solidarité organisateur (RG-M5-10) / facturation no-show propre (RG-M5-09)** — implémenté
   par hypothèse de simplicité en **non-cumul** (spec §7 point 8), à confirmer avec le métier.
8. **US-RES-01 à 14 ne sont pas encore validées/numérotées officiellement** dans le backlog (spec,
   préambule) — ce plan peut nécessiter un ajustement mineur de nommage une fois la validation produit
   faite, sans impact structurel attendu sur le modèle de données.
9. **Naming `Reservation.statut = no_show_facture`** — utilisé même quand aucune `RegleAnnulation`
   active ne facture réellement (cas limite §7) : le libellé du statut réservation ne reflète pas
   toujours un encaissement effectif ; la granularité réelle est portée par `FacturationNoShow.statut`
   (ou son absence). Point de vigilance UX à signaler (constitution §2, règle d'or de simplicité).

## 9. Divergences d'implémentation (constaté à l'exécution, DoD constitution §8.5)

1. **`Activite.tarifReferenceMontant`** (decimal, en plus de `produitTarifReference: ref Produit`) —
   la résolution complète du prix via la grille M1 (`ResolveurPrix` + `TypeTarif` + `Saison`, §4.3) est
   hors périmètre de ce lot socle : ce montant simple sert de base à la vente à l'unité et au calcul
   du montant d'un no-show. `produitTarifReference` reste porté (référence FK réelle) pour un câblage
   futur au moteur de prix M1 complet.
2. **`VenteReservationHandler`** construit directement `Vente`/`LigneVente` (prix forcé) plutôt que de
   passer par `AjoutLigneHandler`/`ResolveurPrix` (M2) : la résolution de grille tarifaire complète
   n'était pas nécessaire pour ce socle (le montant vient de `RegleAnnulation.montantCalcule()` ou de
   `Activite.tarifReferenceMontant`). La `Vente` produite reste une entité M2 réelle, traçable, scellée
   (NF525) dans le cas `debit_pmv`.
3. **Routes API des sous-ressources** — `POST /reservation/creneaux/{id}/liste-attente` (crée une
   `ListeAttente`) et `POST /reservation/reservations/{id}/emarger` (crée un `Emargement`) sont
   déclarées comme opérations sur l'entité **parente** (`Creneau`, `Reservation`) plutôt que sur
   l'entité créée, `{id}` correspondant alors à l'identifiant propre de la ressource parente : API
   Platform (version utilisée) ne résout pas nativement une variable d'URI secondaire
   (`{creneauId}`, `{reservationId}`) sans `Link` explicite déclaré côté ressource cible, ce qui
   provoquait une erreur « Invalid uri variables. ». Documenté dans le code (commentaires) sur les
   deux entités concernées. De même, `POST /reservation/participants/{id}/payer` (paiement partagé)
   a été simplifié pour ne porter qu'un seul identifiant (celui du participant), sans
   `{reservationId}` redondant.
4. **`SessionSystemeResolver`** (Risque n°1 du plan) est bien implémenté tel qu'anticipé : une
   `SessionCaisse`/`PointDeVente`/`Caisse`/`Utilisateur` techniques permanentes par établissement,
   créées à la demande, portent le débit PMV automatique sans agent présent. Confirmé fonctionnel par
   les tests (`FacturationNoShowStrategiesTest::testCa11DebitPmvAutomatiqueSansAgent`) — la réserve
   NF525/comptable du plan (vente sans opérateur humain identifié) reste d'actualité et **doit être
   validée avec un expert compta avant mise en production**.
5. **Comportement Doctrine DBAL constaté (non modifié, hors périmètre)** — le type `datetime_immutable`
   du DBAL (config par défaut de ce dépôt) sérialise une date avec offset (ex. `+02:00`) sans la
   convertir en UTC ; à la relecture (PHP `date_default_timezone_get() === 'UTC'` dans ce
   conteneur), l'heure murale est réinterprétée telle quelle en UTC — un décalage de l'ordre de
   l'offset d'origine peut apparaître entre une valeur fraîchement construite en PHP et la même valeur
   après un aller-retour base de données. Comportement déjà présent et accepté ailleurs dans le dépôt
   (ex. fixtures/tests Piscine utilisant aussi `+02:00`) ; non corrigé ici (hors périmètre, module
   partagé DBAL). Les tests de ce lot utilisent des horodatages `+00:00` pour éviter toute ambiguïté
   lorsqu'une comparaison mélange une valeur fraîche et une valeur relue depuis la base (notamment
   `BasculerNoShowCommand` appelé directement en test, hors cycle requête/réponse complet).
