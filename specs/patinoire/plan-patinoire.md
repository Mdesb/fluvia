# Plan technique — Verticale Patinoire (`US-PATIN-01 à 10` · module `App\Patinoire`)

- **Spec source :** specs/patinoire/spec-patinoire.md
- **Stack :** Symfony 7 · API Platform · Doctrine/MariaDB
- **Précédents réutilisés (lecture avant conception) :** `App\Piscine\Entity\{Casier,CautionCasier,
  ForcageCasier,RelanceCasier,Poss}` (patron caution + spécialisation `EspaceAcces` + délai de
  forçage), `App\Piscine\Doctrine\PerimetrePiscineExtension` (cloisonnement), `App\Piscine\DataFixtures\
  PiscineFixtures` (seed permissions), `App\Acces\Entity\EspaceAcces` (jauge/FMI générique L3),
  `App\Offre\Entity\{Produit,Stock,Saison}` (stock dédié RG-M1-10, saison M1), `App\Vente\Entity\
  LigneVente` (référence logique `produit`, réf. réelle côté consommateurs comme M5), `App\Crm\Entity\
  Beneficiaire`, `App\Securite\Entity\Permission` (couple `module × action`), `App\Securite\Notification\
  {InvitationMailer,ReinitialisationMailer}` (patron mailer applicatif), et surtout
  **`specs/reservation/plan-reservation.md`** — la glace est une `App\Reservation\Entity\Ressource
  (codeType='glace')` avec ses `Creneau`/`Reservation`/no-show : **non redéfinis ici**. ⚠ Ce module
  `App\Reservation` est **planifié mais pas encore implémenté en code** (`app/src/Reservation` n'existe
  pas au moment de ce plan) — voir Risque n°2.

## 0. Décisions structurantes (résumé)

1. **Mutualisation caution — patron dupliqué, pas de refactor piscine.** `App\Patinoire\Entity\
   CautionLocationPatins` reprend **exactement** la forme de `CautionCasier` (montant, statut,
   moyenEncaissement, `regieMouvementRef` en référence logique non-FK, dateEncaissement/Liberation)
   mais avec **son propre enum** `App\Patinoire\Enum\StatutCaution` (4 valeurs `encaissee/liberee/
   retenue_partielle/retenue_totale`, conforme à `spec-patinoire.md §5`, plus fin que les 3 valeurs de
   `App\Piscine\Enum\StatutCaution`). Aucune modification de `App\Piscine` dans ce lot. La
   **mutualisation technique** (module `caution` générique consommé par Piscine + Patinoire, voire
   futures verticales) reste un **risque/chantier futur** (Risque n°1), comme signalé par
   `spec-patinoire.md` §8 point 3.
2. **Pas d'entité « article » individuelle.** Conformément au modèle de données de la spec (§5, une
   seule entité `ParcPatins` par pointure, pas d'`ArticlePatin`), les compteurs `quantiteSortie`,
   `quantiteEnAffutage`, `quantiteHS` sont des **compteurs cachés maintenus par les handlers** à chaque
   transition (sortie/retour/mise en affûtage/remise en service), **même patron** que
   `Ressource.occupationCourante` (plan réservation §1) et `Bassin.occupationCourante` (piscine) —
   **pas** d'agrégation SQL à la volée. Conséquence assumée : pas de traçabilité d'un article
   physique précis perdu/cassé au-delà du motif saisi (Risque n°7).
3. **Fermeture automatique de la vente hors fenêtre de saison éphémère (RG-PAT-04, gap M1 signalé
   spec §4.9/§7/§8 point 9).** Résolue **sans toucher à `App\Offre`/`App\Vente`** : un listener Doctrine
   propre à Patinoire, `VerificateurFenetreSaisonEphemereListener` (écoute `prePersist` de
   `App\Vente\Entity\LigneVente`), rejette la création d'une ligne de vente pour un produit du
   `catalogueAssocie` d'une `SaisonEphemere` dont la date courante est hors
   `[fenetreVenteDebut, fenetreVenteFin]`. Le cycle de vie `Produit` (RG-M1-09, manuel) n'est **pas**
   modifié — l'extension reste contenue dans `App\Patinoire`.
4. **Fermeture d'accès hors fenêtre : aucune brique nouvelle.** Repose sur la cohérence opérationnelle
   entre la durée de validité du `Produit` M1 (RG-M1-07) et la fenêtre `SaisonEphemere`, plus le
   contrôle générique `DroitAcces.fenêtreValidité` (RG-ACC-01) déjà porté par L3 — configuration, pas
   de code.
5. **Surbooking glace = visibilité seule, aucun verrou.** `ConflitGlaceProvider` (State Provider en
   lecture seule, **pas d'entité stockée**) interroge `App\Reservation\Entity\Creneau` pour détecter les
   chevauchements sur les `Ressource(codeType='glace')` de l'établissement et les expose au
   gestionnaire (`patinoire.arbitrer_surbooking`) ; **aucun blocage** à l'écriture côté réservation
   (RG-PAT-03 exception actée). Résolution 100 % manuelle, hors périmètre applicatif.
6. **Dépendance à `App\Reservation` non encore codé** (Risque n°2) — `ConflitGlaceProvider` et toute
   articulation future accès/glace restent des **points d'intégration différés**, séquencés après (ou
   en parallèle strict de) l'implémentation du module `App\Reservation`.
7. **Pointure voisine : ±1 puis ±2, paramétrable par établissement** (hypothèse spec §4.5), implémentée
   par `ProposeurPointureVoisineHandler`, appelée par `SortirPatinsProcessor` en cas de rupture.
8. **Canal de notification liste d'attente = e-mail uniquement dans ce lot** (`ListeAttentePointureMailer`,
   patron `App\Securite\Notification\*Mailer`). SMS/app hors périmètre (Risque n°8, hypothèse spec §4.5).

## 1. Entités & schéma

| Entité (`App\Patinoire\Entity\*`) | Champ | Type Doctrine | Null | Index/Contrainte | Relation |
|---|---|---|---|---|---|
| **ParcPatins** (`patin_parc_patins`) | id | uuid | non | PK | — |
| | etablissement | ManyToOne `Etablissement` | non | index | RG-SOCLE-01 |
| | pointure | smallint (28-48) | non | `Assert\Range` ; unique(etablissement, pointure) | RG-PAT-01 |
| | produitLocation | ManyToOne `App\Offre\Entity\Produit` | non | FK réelle | stock dédié RG-M1-10 |
| | quantiteTotale | smallint ≥0 | non | `Assert\PositiveOrZero` | cahier §4 |
| | quantiteSortie | smallint ≥0 | non, défaut 0 | compteur caché maintenu par handlers | décision n°2 |
| | quantiteEnAffutage | smallint ≥0 | non, défaut 0 | idem | RG-PAT-06 |
| | quantiteHS | smallint ≥0 | non, défaut 0 | idem | RG-PAT-06 |
| | actif | bool | non, défaut true | — | — |
| *(méthode `quantiteDisponible(): int`, non persistée = total − sortie − affûtage − HS, RG-PAT-06)* | | | | | |
| **LocationPatins** (`patin_location`) | id | uuid | non | PK | RG-PAT-01/05 |
| | parcPatins | ManyToOne `ParcPatins` | non | index | pointure louée |
| | ligneVente | ManyToOne `App\Vente\Entity\LigneVente` | non | unique | produit « location de patins » |
| | beneficiaire | ManyToOne `App\Crm\Entity\Beneficiaire` | non | index | RG-M2-04 |
| | dateSortie | datetime_immutable | non | — | — |
| | dateRetour | datetime_immutable | oui | vide tant qu'en cours | — |
| | etatRetour | string(10) enum `EtatRetourPatins` | oui | requis à la clôture | bon/casse/non_rendu |
| | statut | string(12) enum `StatutLocationPatins` | non, défaut `en_cours` | en_cours/retournee/non_rendue | — |
| | etablissement | ManyToOne `Etablissement` | non | dénormalisé (copie `parcPatins.etablissement`) | cloisonnement |
| **CautionLocationPatins** (`patin_caution_location`) | id | uuid | non | PK | décision n°1 |
| | location | ManyToOne `LocationPatins` | non | unique (1:1) | — |
| | montant | decimal(6,2) ≥0 | non | paramétrable | ⚠ non chiffré (Risque n°5) |
| | statut | string(16) enum `StatutCaution` | non, défaut `encaissee` | encaissee/liberee/retenue_partielle/retenue_totale | — |
| | moyenEncaissement | string(30) | oui | — | ⚠ non précisé (Risque n°6) |
| | regieMouvementRef | uuid | oui | réf. logique (M2/M6), non-FK | même patron `CautionCasier` |
| | dateEncaissement | datetime_immutable | oui | — | — |
| | dateLiberation | datetime_immutable | oui | — | — |
| **GrilleRetenue** (`patin_grille_retenue`) | id | uuid | non | PK | décision actée « retenue » |
| | etablissement | ManyToOne `Etablissement` | non | index | paramétrable par établissement |
| | motif | string(24) enum `MotifRetenue` | non | casse/non_rendu/perte/restitution_partielle | — |
| | mode | string(20) enum `ModeRetenue` | non | forfait/valeur_remplacement | cahier §7, non tranché → les deux existent |
| | montantOuTaux | decimal(6,2) ≥0 | non | forfait (montant) ou % | — |
| | parcPatins | ManyToOne `ParcPatins` | oui | granularité optionnelle par pointure | — |
| | actif | bool | non, défaut true | — | — |
| **RetenueCaution** (`patin_retenue_caution`) | id | uuid | non | PK | trace décision actée |
| | location | ManyToOne `LocationPatins` | non | unique (1:1) | — |
| | grilleAppliquee | ManyToOne `GrilleRetenue` | oui | nullable si forcée hors grille | — |
| | montantRetenu | decimal(6,2) ≥0 | non | proposé, modifiable avant validation | — |
| | mouvementRegieRef | uuid | oui | réf. logique mouvement régie (M2/M6) | trace comptable |
| | agent | ManyToOne `App\Securite\Entity\Utilisateur` | non | — | journalisé RG-SOCLE-07 |
| | motif | string(255) | non | — | — |
| | horodatage | datetime_immutable | non | — | — |
| | forcee | bool | non, défaut false | hors barème, requiert `patinoire.forcer_retenue` | Administrateur §3 |
| **ListeAttentePointure** (`patin_liste_attente_pointure`) | id | uuid | non | PK | décision actée « rupture » |
| | parcPatins | ManyToOne `ParcPatins` | non | index | pointure demandée |
| | beneficiaire | ManyToOne `Beneficiaire` | non | — | — |
| | rang | smallint ≥1 | non | unique(parcPatins, rang) | FIFO |
| | dateDemande | datetime_immutable | non | — | — |
| | statut | string(12) enum `StatutListeAttentePointure` | non, défaut `en_attente` | en_attente/proposee/honoree/expiree | — |
| | pointureVoisineProposee | smallint | oui | ⚠ algorithme ±1/±2 (Risque n°9) | — |
| | dateExpirationProposition | datetime_immutable | oui | paramétrable, ⚠ non chiffré (Risque n°9) | — |
| | etablissement | ManyToOne `Etablissement` | non | dénormalisé | cloisonnement |
| **Affutage** (`patin_affutage`) | id | uuid | non | PK | RG-PAT-06, décision « affûtage » |
| | type | string(18) enum `TypeAffutage` | non | prestation_client/maintenance_parc | §4.6 |
| | ligneVente | ManyToOne `App\Vente\Entity\LigneVente` | oui | requis si `prestation_client` | produit dédié tarif/TVA propres |
| | parcPatins | ManyToOne `ParcPatins` | oui | requis si `maintenance_parc` | §4.1 |
| | technicien | ManyToOne `Utilisateur` | non | — | §3 |
| | dateEntreeAtelier | datetime_immutable | non | — | — |
| | dateSortieAtelier | datetime_immutable | oui | — | — |
| | statut | string(12) enum `StatutAffutage` | non, défaut `en_attente` | en_attente/en_cours/termine | remise en service = « bon » |
| | etablissement | ManyToOne `Etablissement` | non | index | — |
| **ZonePatinoire** (`patin_zone`) *(spécialisation `EspaceAcces`, patron `Poss`)* | id | uuid | non | PK | RG-PAT-02 |
| | espaceAcces | ManyToOne `App\Acces\Entity\EspaceAcces` | non | unique(espace_acces_id) | délègue seuil/mode/jauge à L3 |
| | typeZone | string(10) enum `TypeZonePatinoire` | non | glace/gradins | routage du billet (§4.7, aucun mécanisme nouveau) |
| *(getter `getEtablissement()` délégué à `espaceAcces`, patron `CautionCasier`)* | | | | | |
| **SaisonEphemere** (`patin_saison_ephemere`) | id | uuid | non | PK | RG-PAT-04 |
| | etablissement | ManyToOne `Etablissement` | non | index | — |
| | libelle | string(120) | non | — | — |
| | dateOuverture, dateFermeture | date_immutable, date_immutable | non | ouverture ≤ fermeture | montage/démontage |
| | fenetreVenteDebut, fenetreVenteFin | date_immutable, date_immutable | non | debut ≤ fin | peut différer de la fenêtre d'exploitation |
| | saisonM1 | ManyToOne `App\Offre\Entity\Saison` | non | FK réelle | réutilise M1 |
| | catalogueAssocie | ManyToMany `App\Offre\Entity\Produit` | ≥1 (applicatif) | table jointe `patin_saison_ephemere_produit` | — |
| | bascule | string(12) enum `BasculeSaisonEphemere` | non, défaut `automatique` | manuelle/automatique | ⚠ défaut à arbitrer (Risque n°3) |
| | actif | bool | non, défaut true | — | — |

> id = UUID (`symfony/uid`). Rattachement multi-entités : **Établissement** obligatoire sur toute
> entité racine (dénormalisé sur les entités enfants, patron `PerimetrePiscineExtension`) ; **Espace**
> porté indirectement via `ZonePatinoire.espaceAcces.espaceSocle`. Toutes les classes en
> `declare(strict_types=1)`, namespaces `App\Patinoire\Entity\*` / `App\Patinoire\Enum\*`.

**Enums (`App\Patinoire\Enum\*`)** : `EtatRetourPatins`, `StatutLocationPatins`, `StatutCaution`
(propre patinoire, décision n°1), `MotifRetenue`, `ModeRetenue`, `StatutListeAttentePointure`,
`TypeAffutage`, `StatutAffutage`, `TypeZonePatinoire`, `BasculeSaisonEphemere`.

**Services/handlers (`App\Patinoire\Service\*`, `App\Patinoire\Doctrine\*`, `App\Patinoire\Notification\*`)** :
- `ProposeurPointureVoisineHandler` — décision n°7.
- `ResolveurGrilleRetenueHandler` — priorité pointure > établissement pour choisir la `GrilleRetenue`
  applicable à un motif donné.
- `PromotionListeAttenteHandler` — déclenché à chaque hausse de `quantiteDisponible` (retour bon,
  remise en service après affûtage) ; promeut le rang 1 `en_attente` → `proposee`.
- `ListeAttentePointureMailer` — décision n°8.
- `VerificateurFenetreSaisonEphemereListener` — décision n°3.
- `PerimetrePatinoireExtension` (Doctrine, `QueryCollectionExtensionInterface`/`QueryItemExtensionInterface`)
  — cloisonnement établissement, patron `PerimetrePiscineExtension`.

## 2. API (API Platform)

| Ressource | Opérations | security: | Groupes sérialisation | Filtres |
|---|---|---|---|---|
| `ParcPatins` | GetCollection/Get ; Post/Patch | lire → `patinoire.lire` ; écriture → `patinoire.configurer` | `parc_patins:read/write` | `etablissement`, `pointure`, `actif` |
| `LocationPatins` | GetCollection/Get ; `Post /patinoire/locations` (`SortirPatinsProcessor`) ; `Post /patinoire/locations/{id}/retour` (`RetournerPatinsProcessor`) | lire → `patinoire.lire` ; sortie/retour → `patinoire.gerer_location` | `location:read/write` | `statut`, `beneficiaire`, `parcPatins` |
| `CautionLocationPatins` | GetCollection/Get (créée en side-effect, pas de Post direct) | `patinoire.lire` | `caution_location:read` | `location`, `statut` |
| `GrilleRetenue` | GetCollection/Get ; Post/Patch | lire → `patinoire.lire` ; écriture → `patinoire.configurer` | `grille_retenue:read/write` | `motif`, `parcPatins`, `actif` |
| `RetenueCaution` | GetCollection/Get ; `Post /patinoire/retenues/{id}/valider` (`ValiderRetenueProcessor`) | lire → `patinoire.lire` ; valider (montant défaut) → `patinoire.gerer_location` ; valider (hors barème) → `patinoire.forcer_retenue` | `retenue:read/write` | `location`, `forcee` |
| `ListeAttentePointure` | GetCollection/Get ; `Post /patinoire/liste-attente` (`InscrireListeAttenteProcessor`) ; `Post /patinoire/liste-attente/{id}/annuler` | lire → `patinoire.lire` ; inscrire/annuler → `patinoire.gerer_liste_attente` | `liste_attente:read/write` | `parcPatins`, `statut` |
| `Affutage` | GetCollection/Get ; `Post /patinoire/affutages` (`DemarrerAffutageProcessor`) ; `Post /patinoire/affutages/{id}/terminer` (`TerminerAffutageProcessor`) | lire → `patinoire.lire` ; gérer → `patinoire.gerer_affutage` | `affutage:read/write` | `type`, `statut`, `parcPatins` |
| `ZonePatinoire` | GetCollection/Get ; Post/Patch | lire → `patinoire.lire` (ou `acces.lire`) ; écriture → `patinoire.configurer` | `zone_patinoire:read/write` | `typeZone`, `espaceAcces` |
| `SaisonEphemere` | GetCollection/Get ; Post/Patch | lire → `patinoire.lire` ; écriture → `patinoire.configurer` | `saison_ephemere:read/write` | `actif`, `fenetreVenteDebut` (range) |
| `ConflitGlace` *(Provider seul, pas d'entité)* | GetCollection (`ConflitGlaceProvider`, lecture `App\Reservation\Entity\Creneau`) | `patinoire.arbitrer_surbooking` (ou `reservation.superviser` réutilisée) | `conflit_glace:read` | `etablissement`, `date` (range) |

## 3. Sécurité & droits

Permissions `patinoire.*` (couple `module × action`, RG-SOCLE-02/03/04, portées établissement/espace) —
noms dérivés du tableau Acteurs & droits de la spec (§3, ⚠ à figer avec M8, point ouvert n°10) :

| Permission | Rôle typique | Usage |
|---|---|---|
| `patinoire.lire` | Lecture seule, tous rôles | consultation parc, locations en cours, liste d'attente, glace/gradins |
| `patinoire.configurer` | Gestionnaire d'offre | CRUD `ParcPatins`, `GrilleRetenue`, `ZonePatinoire`, `SaisonEphemere` |
| `patinoire.gerer_location` | Agent de comptoir | sortie/retour de patins ; validation de retenue **au barème par défaut** |
| `patinoire.gerer_liste_attente` | Agent de comptoir | inscription/annulation liste d'attente pointure |
| `patinoire.gerer_affutage` | Technicien/atelier | démarrer/terminer un affûtage (prestation ou maintenance) |
| `patinoire.arbitrer_surbooking` | Gestionnaire glace (planning) | consultation `ConflitGlace`, aucune action bloquante (résolution manuelle hors appli) |
| `patinoire.forcer_retenue` | Administrateur | valider une `RetenueCaution` **hors barème** (`forcee=true`), action journalisée RG-SOCLE-07 |
| `patinoire.gerer` | Administrateur | surensemble de tout ce qui précède |

Réutilisées telles quelles (non redéfinies) : `offre.creer`/`offre.modifier` (M1, produits location/
affûtage), `vente.encaisser` (M2, panier location/affûtage), `acces.lire`/`acces.gerer` (L3,
`EspaceAcces` sous-jacent aux `ZonePatinoire`), `reservation.superviser` (créneaux glace).

**Garde-fou (pas de Voter dédié)** : `ValiderRetenueProcessor` compare le `montantRetenu` soumis au
`montantOuTaux` par défaut de la `GrilleRetenue` résolue ; tout écart exige `patinoire.forcer_retenue`
(403 sinon), et positionne `RetenueCaution.forcee = true` — logique de garde portée par le processeur,
pas par un `Voter` Symfony séparé (plus simple, cohérent avec le patron `PermissionVoter` générique déjà
en place).

**Doctrine** : `App\Patinoire\Doctrine\PerimetrePatinoireExtension` (cloisonnement établissement,
patron `PerimetrePiscineExtension`) sur toutes les entités racines du §1.

## 4. Migrations

Une migration Doctrine (`VersionYYYYMMDDHHMMSS_patinoire.php`) crée :
- Tables : `patin_parc_patins`, `patin_location`, `patin_caution_location`, `patin_grille_retenue`,
  `patin_retenue_caution`, `patin_liste_attente_pointure`, `patin_affutage`, `patin_zone`,
  `patin_saison_ephemere`, table jointe `patin_saison_ephemere_produit`.
- FKs sortantes vers modules existants (dépendance dans le bon sens, Patinoire dépend de M1/M2/M4/L3) :
  `off_produit` (`ParcPatins.produitLocation`, `SaisonEphemere.catalogueAssocie`), `off_saison`
  (`SaisonEphemere.saisonM1`), `vente_ligne` (`LocationPatins.ligneVente`, `Affutage.ligneVente`),
  `crm_beneficiaire` (`LocationPatins.beneficiaire`, `ListeAttentePointure.beneficiaire`),
  `acces_espace_acces` (`ZonePatinoire.espaceAcces`), `organisation_etablissement`,
  `securite_utilisateur` (`RetenueCaution.agent`, `Affutage.technicien`).
- **Aucune FK vers un module `App\Reservation`** : ce module n'est pas encore codé (Risque n°2) ; le
  couplage glace se fait uniquement en lecture via `ConflitGlaceProvider` (pas de contrainte SQL).
- **Aucune modification de tables hors `App\Patinoire`** — en particulier aucune migration sur
  `App\Piscine` (mutualisation caution différée, décision n°1) ni sur `App\Offre`/`App\Vente` (le
  listener de fermeture de vente, décision n°3, n'ajoute aucune colonne, seulement un service tagué).
- Index : unique `(etablissement_id, pointure)` sur `patin_parc_patins`, unique `location_id` sur
  `patin_caution_location` et `patin_retenue_caution`, unique `(parc_patins_id, rang)` sur
  `patin_liste_attente_pointure`, unique `espace_acces_id` sur `patin_zone`.

## 5. Tests

| Test | Type | Couvre |
|---|---|---|
| `Api\ParcPatinsTest::testDisponibiliteDeriveeDesCompteurs` | Fonctionnel API | CA-1 |
| `Api\ParcPatinsTest::testArticleAAffuterOuHSSortDuDisponible` | Fonctionnel API | CA-1, RG-PAT-06 |
| `Api\LocationPatinsTest::testSortieBloqueArticleEtEncaisseCaution` | Fonctionnel API | CA-2 |
| `Api\LocationPatinsTest::testSortieRefuseeSiDisponibiliteNulle` | Fonctionnel API | CA-2, bascule CA-5 |
| `Api\LocationPatinsTest::testRetourBonLibereEtRestitueCaution` | Fonctionnel API | CA-3 |
| `Api\LocationPatinsTest::testRetourCasseProposeRetenue` | Fonctionnel API | CA-3, CA-4 |
| `Api\RetenueCautionTest::testValidationMontantDefautGenereTraceComptable` | Fonctionnel API | CA-4 |
| `Api\RetenueCautionTest::testForcageHorsBaremeReserveAuxAdministrateurs` | Fonctionnel API | CA-4, garde-fou §3 |
| `Api\ListeAttentePointureTest::testPointureVoisineProposeeAvantListeAttente` | Fonctionnel API | CA-5 |
| `Api\ListeAttentePointureTest::testNotificationDesInscritsAuRetourDUneUnite` | Fonctionnel API | CA-5 |
| `Api\AffutageTest::testPrestationClientSansImpactParc` | Fonctionnel API | CA-6 |
| `Api\AffutageTest::testMaintenanceParcSortEtRentreDuDisponible` | Fonctionnel API | CA-7 |
| `Api\ZonePatinoireTest::testDeuxZonesJaugesIndependantes` | Fonctionnel API | CA-8, RG-PAT-02 |
| `Api\ConflitGlaceTest::testChevauchementVisibleSansBlocage` | Fonctionnel API | CA-9, RG-PAT-03 |
| `Api\SaisonEphemereTest::testVenteFermeeAutomatiquementHorsFenetre` | Fonctionnel API | CA-10 |
| `Api\SaisonEphemereTest::testReouvertureAutomatiqueDansLaFenetre` | Fonctionnel API | CA-10 |
| `Api\CloisonnementPatinoireTest::testUtilisateurNeVoitQueSonEtablissement` | Fonctionnel API | RG-SOCLE-05 |
| `Api\CasLimitesTest::testRestitutionPartielleTauxReduit` | Fonctionnel API | §7 cas limite (hypothèse) |
| `Unit\ProposeurPointureVoisineHandlerTest::testPropositionOrdreInferieurPuisSuperieur` | Unitaire | décision n°7 |
| `Unit\ResolveurGrilleRetenueHandlerTest::testPrioritePointureSurEtablissement` | Unitaire | §4.4 |
| `Unit\PromotionListeAttenteHandlerTest::testPromotionRangUnSurLiberationUnite` | Unitaire | RG-PAT-05/décision « rupture » |
| `Unit\VerificateurFenetreSaisonEphemereListenerTest::testRejetHorsFenetreVente` | Unitaire | décision n°3 |

## 6. Tâches (voir tasks-patinoire.md)
- T1 … T22 (ordonnées) — enums/socle → sortie/retour + caution → grille de retenue → pointure voisine +
  liste d'attente → affûtage → zones accès → saison éphémère (listener vente) → conflits glace →
  cloisonnement/migration/fixtures → tests transverses → revue permissions M8.

## 7. Risques / à valider

1. **Mutualisation caution non spécifiée transversalement** (spec §8 point 3) — `CautionCasier`
   (Piscine) et `CautionLocationPatins` (Patinoire) dupliquent aujourd'hui le même patron ; un module
   `caution` générique (M2/M6) consommé par les deux verticales est un **candidat fort de
   refactoring futur**, volontairement **non traité dans ce lot** (consigne explicite : ne pas
   refactorer la piscine ici).
2. **Dépendance à `App\Reservation` non encore implémenté en code** — `specs/reservation/plan-
   reservation.md` existe mais aucune entité `App\Reservation\*` n'est présente dans `app/src` à la
   date de ce plan. `ConflitGlaceProvider` (T17) ne peut être livré fonctionnellement qu'une fois ce
   module codé ; à séquencer explicitement dans le planning de lots.
3. **Gap M1 — bascule automatique de statut produit par date** (RG-PAT-04) — contournée par un listener
   propre à Patinoire (décision n°3) plutôt que par une extension du cycle de vie `Produit` (RG-M1-09,
   manuel) ; solution pragmatique mais **doublon potentiel de logique** si M1 se dote un jour d'un job
   de bascule temporisée générique — à réconcilier alors.
4. **NF525 / trace comptable de la retenue** — `RetenueCaution.mouvementRegieRef` est une **référence
   logique non-FK** (même patron que `CautionCasier.regieMouvementRef`), sans garantie d'écriture
   effective dans un module régie/M6 encore non branché sur ce point précis ; **à valider par un
   expert compta/NF525** avant mise en production (constitution §4 point 5), notamment l'inaltérabilité
   du chaînage sur les retenues forcées.
5. **Montant de caution initial non chiffré** (spec §4.2/§7) — paramétrable par établissement, aucune
   valeur par défaut imposée par le code ; risque produit si aucun paramétrage n'est fait à l'ouverture.
6. **Moyen d'encaissement de la caution non précisé** (empreinte CB, espèces, PMV) — champ libre
   `moyenEncaissement` (string), aucune validation métier stricte tant que le mode retenu n'est pas
   arbitré transversalement (même point ouvert que Piscine).
7. **Pas de traçabilité d'article physique individuel** (décision n°2) — un établissement qui voudrait
   savoir *quelle paire précise* a été cassée (numéro de série) devra passer par le champ `motif`
   texte de `RetenueCaution`, pas par un identifiant d'article structuré ; à revoir si le besoin de
   traçabilité fine se confirme.
8. **Restitution partielle d'une paire** (§7 spec) — traitée par le motif `restitution_partielle` de
   `GrilleRetenue` (taux réduit paramétrable), **hypothèse de travail à confirmer avec l'exploitant**.
9. **Algorithme de pointure voisine et délai de la liste d'attente non chiffrés par les sources**
   (§4.5/§7) — retenus par hypothèse (±1 puis ±2 ; délai de proposition paramétrable, pas de valeur
   par défaut imposée dans le code) ; canal de notification limité à l'e-mail dans ce lot (décision n°8).
10. **Permissions `patinoire.*` non nommées littéralement dans les sources** (spec §3 point ouvert
    n°10) — nommage proposé dans ce plan (§3), à figer avec M8 avant industrialisation, même statut que
    `piscine.*`/`acces.*` déjà signalés dans les plans précédents.
11. **US-PATIN-01 à 10 hors backlog MVP officiel** (spec en-tête, §8 point ouvert n°11) — ce plan peut
    nécessiter un ajustement mineur de nommage/priorité une fois la validation product owner faite,
    sans impact structurel attendu sur le modèle de données proposé ici.
