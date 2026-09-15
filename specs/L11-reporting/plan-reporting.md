# Plan technique — Reporting & pilotage multi-niveaux (`M7` / lot `L11`)
> ### ⚠ `AxeAnalytique` A ÉTÉ SUPPRIMÉ LE 15/09/2026 — les mentions ci-dessous sont historiques
>
> Arbitrage de Maxime, point n°2 de `COORDINATION/A-REVOIR.md` (ouvert le 07/09). Six axes en base,
> alimentés par les fixtures et consommés par **rien** : ni écran, ni moteur d'agrégation, ni appel
> de client d'API. Mesuré deux fois, avec témoin positif (`Indicateur` ressort dans 3 fichiers de
> service, `AxeAnalytique` dans aucun).
>
> Aucun écran n'avait été construit pour lui **à dessein** : un formulaire aurait laissé définir des
> axes qui ne changent aucune analyse. C'est ce constat qui a fait ouvrir l'arbitrage plutôt que de
> le construire quand même.
>
> Les sections qui le décrivent sont **conservées telles quelles**, et non effacées : elles portent
> le raisonnement qui a conduit à le concevoir, et l'effacer rendrait la suppression inexplicable.
> Ce qu'il faut lire : **ce référentiel n'existe plus** — entité, enum, table, fixtures et route.


- **Spec source :** specs/L11-reporting/spec-reporting.md
- **Stack :** Symfony 7 · API Platform · Doctrine/MariaDB
- **Couvre :** US-L11-01 à US-L11-09 · RG-M7-01 à 08 · RG-REPORT-09 à 11 · CA-1 à CA-11 de la spec
- **Périmètre :** module `App\Reporting\*`, **lecture seule** sur toutes les données produites par
  M1/M2/M3/M5/M6/Accès/Recouvrement. Aucune écriture dans un module producteur. M7 **hérite** le
  cloisonnement de M8 (`Affectation`, `PermissionVoter`, `CalculateurDroits`) sans le redéfinir
  (RG-M7-01) — étendu, jamais dupliqué.

> **Réutilisation socle/M8 (rappel, non redéfini ici)** — `app/src/Securite/Entity/{Utilisateur,
> Affectation}.php`, `app/src/Securite/Security/PermissionVoter.php`,
> `app/src/Securite/Service/CalculateurDroits.php`, `app/src/Organisation/Entity/{Groupe,Region,
> Etablissement,Espace}.php`. Sources lues (jamais modifiées) : `App\Vente\Entity\Vente`,
> `App\Caisse\Entity\{SessionCaisse,ClotureZ}`, `App\Acces\Entity\{Passage,JaugeFmi,Controleur,
> EspaceAcces}`, `App\Compta\Entity\{ProfilExploitant,RegieRecettes,Rad,Redevance}`,
> `App\Reservation\Entity\{Creneau,Reservation,FacturationNoShow}`,
> `App\Recouvrement\Entity\IncidentImpaye`, `App\Offre\Entity\{Produit,Categorie}`.

---

## 0. Constat d'écart avec le socle actuel (lu avant de concevoir, cf. Risques §9.1)

`app/src/Securite/Entity/Affectation.php` ne porte un rôle **qu'au niveau Établissement** — écart
`RG-M8-02` déjà signalé par `specs/L7-backoffice/spec-backoffice.md` (§7, « le socle actuel n'affecte
les rôles qu'au niveau Établissement, jamais Groupe/Région/Espace »). M7 ne peut donc pas résoudre un
« rôle rattaché à une Région/à un Groupe » par une simple lecture d'`Affectation.etablissement`. Ce
plan **n'ajoute aucune Affectation de niveau Région/Groupe** (hors périmètre M7, appartient à M8) et
résout le périmètre régional/groupe par **dérivation** : un utilisateur « voit » une Région/un Groupe
en Reporting si **l'ensemble des établissements** de cette entité est couvert par ses `Affectation`
portant `reporting.lire`/`planifier`/`configurer` (§2.2). C'est une lecture pure de données M8
existantes, conforme à RG-M7-01 (« jamais redéfini »).

---

## 1. Entités & schéma

Namespace `App\Reporting\Entity\*` (+ `App\Reporting\Enum\*`, `App\Reporting\ValueObject\*`). `id` =
UUID (`Symfony\Component\Uid\Uuid`). `declare(strict_types=1)` partout.

### 1.1 Trait de rattachement polymorphe `App\Reporting\Entity\Trait\RattachementNiveauTrait`

Réutilisé par `Mesure`, `TableauDeBord`, `ObjectifIndicateur`, `DestinataireRapport`, `Export` : pas
d'interface `Entité` commune à `Groupe`/`Region`/`Etablissement` dans `App\Organisation` (vérifié),
donc rattachement par **triplet niveau + 3 FK nullables** (une seule renseignée, cohérente avec
`niveau` — contrôlé en Processor, pas en contrainte SQL `CHECK` pour rester portable Doctrine) :

| Champ | Type Doctrine | Null | Notes |
|---|---|---|---|
| `niveau` | `string(12)` enum `NiveauEntite` {`etablissement`,`region`,`groupe`} | non | — |
| `etablissement` | `ManyToOne Etablissement` | oui | renseigné ssi `niveau=etablissement` |
| `region` | `ManyToOne Region` | oui | renseigné ssi `niveau ∈ {etablissement,region}` — **dénormalisé** aussi sur les lignes établissement (ancêtre direct) pour filtrer une région sans jointure |
| `groupe` | `ManyToOne Groupe` | oui | renseigné sur **toutes** les lignes (ancêtre racine dénormalisé) |

### 1.2 `AxeAnalytique` (référentiel léger)

| Champ | Type | Null | Contrainte | Notes |
|---|---|---|---|---|
| `id` | uuid | non | PK | — |
| `code` | `string(30)` | non | unique | `site`,`activite`,`produit`,`categorie`,`periode`,`canal` |
| `libelle` | `string(80)` | non | — | affiché Explorateur |
| `type` | `string(20)` enum `TypeAxeAnalytique` (mêmes valeurs que `code`) | non | — | RG-M7-05 ; `categorie`/`canal` = extension §4.3 spec, marqués `estExtension=true` |
| `granularites` | `json` (liste) | oui | requis si `code=periode` | `jour`,`semaine`,`mois`,`annee` |
| `estExtension` | `boolean` | non | défaut `false` | trace `categorie`/`canal` comme extensions à valider (§4.3 spec) |
| `actif` | `boolean` | non | défaut `true` | jamais supprimé (cas limite spec §7), seulement désactivé |

### 1.3 `Indicateur` (référentiel)

| Champ | Type | Null | Contrainte | Notes |
|---|---|---|---|---|
| `id` | uuid | non | PK | — |
| `code` | `string(40)` | non | unique | `CA`, `FREQUENTATION_CUMULEE`, `FMI_MAX`, `FMI_MAX_SOMME_SITES`, `FMI_MAX_SITE_CRITIQUE`, `TAUX_REMPLISSAGE`, `NO_SHOW`, `IMPAYES`, `FOND_CAISSE` |
| `libelle` | `string(120)` | non | — | **libellé explicite obligatoire** pour toute variante FMI agrégée (RG-M7-04, §2.4) |
| `unite` | `string(20)` enum `UniteIndicateur` {`euro`,`nombre`,`pourcentage`,`ratio`} | non | — | — |
| `modeCalcul` | `string(12)` enum `ModeCalculIndicateur` {`somme`,`max`,`moyenne`,`ratio`} | non | — | pilote l'agrégation ascendante (RG-M7-03) |
| `nature` | `string(12)` enum `NatureIndicateur` {`instantane`,`cumule`} | non | — | distingue FMI (instantané) de CA/fréquentation (cumulé) — RG-M7-04 |
| `sourceModule` | `string(16)` enum `SourceModuleIndicateur` {`vente`,`acces`,`compta`,`reservation`,`recouvrement`} | non | — | dispatch vers la projection idoine (§2.1), jamais recalculé divergemment (RG-M7-02) |
| `seuilCompletudeMinutes` | `integer` | oui | défaut `60` | RG-REPORT-11 : ancienneté max tolérée avant marquage `partiel` (§2.6) |
| `actif` | `boolean` | non | défaut `true` | jamais supprimé si référencé par `TableauDeBord`/`RapportPlanifie` (cas limite spec §7) — seule `Patch(actif=false)` exposée |

### 1.4 `Mesure` (table de faits — cœur de la pré-agrégation)

| Champ | Type | Null | Contrainte | Notes |
|---|---|---|---|---|
| `id` | `uuid` (v7, tri temporel comme `Passage`) | non | PK | — |
| *(trait §1.1)* `niveau`/`etablissement`/`region`/`groupe` | — | — | — | niveau de la ligne + ancêtres dénormalisés |
| `indicateur` | `ManyToOne Indicateur` | non | index | — |
| `activite` | `string(60)` | oui | index | code verticale (`piscine`,`patinoire`,`sport`,`padel`,`musee`) — **référence logique** (pas de référentiel « Activité » unifié dans le socle actuel, cf. Risques §9.3) |
| `produit` | `uuid` | oui | index | réf. logique `Offre\Entity\Produit` — **pas de FK dure** (même patron que `Vente.client`, découplage inter-module) |
| `categorie` | `uuid` | oui | index | réf. logique `Offre\Entity\Categorie`, idem |
| `canal` | `string(20)` | oui | index | valeurs `App\Offre\Enum\Canal` (`guichet`,`en_ligne`,`borne`) **ou** valeurs Reporting propres (`dsp`,`regie`) non couvertes par M1 — champ texte libre validé par une liste blanche, pas un `ManyToOne` (cf. Risques §9.4) |
| `periodeDebut` / `periodeFin` | `date_immutable` | non | — | bornes obligatoires (RG-M7-...) |
| `granularite` | `string(8)` enum `GranulariteMesure` {`jour`,`semaine`,`mois`,`annee`} | non | — | — |
| `valeur` | `decimal(14,2)` | non | — | résultat du calcul |
| `regimeExploitant` | `string(14)` enum `RegimeExploitantMesure` {`regie`,`dsp`,`groupe_prive`,`mixte`} | oui | requis si agrégé | RG-REPORT-09 |
| `comparabiliteRegime` | `boolean` | non | défaut `false` | vrai si agrège des régimes différents (RG-REPORT-09) |
| `statutCompletude` | `string(8)` enum `StatutCompletude` {`complet`,`partiel`} | non | défaut `complet` | RG-M7-08, RG-REPORT-11 |
| `sitesManquants` | `json` (liste uuid) | oui | requis si `partiel` | traçabilité (§2.6) |
| `fuseauReference` | `string(40)` | non | défaut `Europe/Paris` | fuseau du site (établissement) ou fuseau de référence configuré (région/groupe) — horodatage **en base toujours UTC** (`genereLe`), conversion à l'affichage uniquement (RG-REPORT-10) |
| `devise` | `string(3)` | non | défaut `EUR` | RG-REPORT-10, conversion multi-devise hors MVP |
| `cleAgregation` | `string(64)` | non | **unique** | hash déterministe du tuple de dimensions (§2.1), sert de clé d'upsert idempotent à `reporting:agreger` |
| `genereLe` | `datetime_immutable` | non | index | horodatage de calcul (UTC) |

Table `report_mesure`. Index composites `(indicateur_id, niveau, etablissement_id, periode_debut,
periode_fin, granularite)`, `(indicateur_id, niveau, region_id, ...)`,
`(indicateur_id, niveau, groupe_id, ...)` pour les 3 lectures dashboard. **Aucune opération
d'écriture API** (Get/GetCollection uniquement) : `Mesure` n'est produite que par
`reporting:agreger` (§2.1), jamais via l'API (même patron « append-only, pas d'écriture exposée » que
`Passage`, mais plus strict : zéro écriture API du tout).

### 1.5 `ObjectifIndicateur` *(ajouté par ce plan, hors §5 de la spec — cf. Risques §9.2)*

Nécessaire pour rendre **testable** CA-3 (« écarts vs objectifs ») : la spec §5 ne modélise pas de
porteur de valeur cible.

| Champ | Type | Null | Contrainte | Notes |
|---|---|---|---|---|
| `id` | uuid | non | PK | — |
| *(trait §1.1)* | — | — | — | périmètre de l'objectif |
| `indicateur` | `ManyToOne Indicateur` | non | — | — |
| `periodeDebut` / `periodeFin` | `date_immutable` | non | — | — |
| `granularite` | enum `GranulariteMesure` | non | — | — |
| `valeurCible` | `decimal(14,2)` | non | — | — |

Table `report_objectif_indicateur`. CRUD `reporting.configurer` uniquement (Administrateur).

### 1.6 `TableauDeBord`

| Champ | Type | Null | Contrainte | Notes |
|---|---|---|---|---|
| `id` | uuid | non | PK | — |
| `nom` | `string(160)` | non | — | — |
| *(trait §1.1)* | — | — | — | détermine le périmètre effectif (§4.1 spec) — `niveau` = niveau de dashboard (établissement/région/groupe) |
| `indicateurs` | `ManyToMany Indicateur` | — | **≥ 1** (Assert au Processor) | composition affichée |
| `miseEnPage` | `json` | oui | — | disposition des widgets, opaque côté back |
| `actif` | `boolean` | non | défaut `true` | jamais supprimé si référencé par un `RapportPlanifie` actif |

Table `report_tableau_de_bord` + `report_tableau_de_bord_indicateur` (join).

### 1.7 `RapportPlanifie`

| Champ | Type | Null | Contrainte | Notes |
|---|---|---|---|---|
| `id` | uuid | non | PK | — |
| `nom` | `string(160)` | non | — | — |
| `tableauDeBord` | `ManyToOne TableauDeBord` | non | — | RG-M7-06 : référence un modèle existant, n'invente pas d'indicateur |
| `format` | `string(4)` enum `FormatExport` {`pdf`,`xlsx`,`csv`} | non | — | §4.6 spec |
| `periodicite` | `string(12)` enum `PeriodiciteRapport` {`quotidienne`,`hebdomadaire`,`mensuelle`} | non | — | — |
| `heureEnvoi` | `string(5)` (`HH:MM`) | non | — | — |
| `etat` | `string(9)` enum `EtatRapportPlanifie` {`actif`,`suspendu`} | non | défaut `actif` | RG-M7-06 |
| `createur` | `ManyToOne Utilisateur` | non | — | pour le contrôle « destinataires ⊆ périmètre créateur » (§2.3) |
| `dernierEnvoi` / `prochainEnvoi` | `datetime_immutable` | oui | — | traçabilité du cycle (calculé par `reporting:executer-rapports`) |
| `destinataires` | `OneToMany DestinataireRapport` | — | ≥ 1 | cascade persist/remove |

Table `report_rapport_planifie`.

### 1.8 `DestinataireRapport` (enfant de `RapportPlanifie`)

| Champ | Type | Null | Notes |
|---|---|---|---|
| `id` | uuid | non | PK |
| `rapportPlanifie` | `ManyToOne RapportPlanifie` | non | — |
| `email` | `string(180)` | non | pas nécessairement un `Utilisateur` du système (RG-M7-07 : « liste e-mail ») |
| *(trait §1.1)* | — | — | **périmètre propre à ce destinataire** (RG-M7-07) — validé ⊆ périmètre du `createur` à l'écriture (§2.3) |

Table `report_destinataire_rapport`.

### 1.9 `Export`

| Champ | Type | Null | Notes |
|---|---|---|---|
| `id` | uuid | non | PK |
| `rapportPlanifie` | `ManyToOne RapportPlanifie` | oui | nul si export manuel (§3 spec, ⚠ HYPOTHÈSE reconduite) |
| `destinataireEmail` | `string(180)` | oui | renseigné pour un export issu d'un `RapportPlanifie` (une ligne `Export` par destinataire, périmètre individualisé RG-M7-07) ; nul pour un export manuel (périmètre = appelant) |
| `format` | enum `FormatExport` | non | — |
| *(trait §1.1)* | — | — | périmètre effectif de **cet** export |
| `axesAppliques` | `json` | oui | filtres Explorateur appliqués (activité/produit/catégorie/canal/période) |
| `statut` | `string(8)` enum `StatutExport` {`genere`,`envoye`,`echec`} | non | — |
| `cheminStockage` | `string(255)` | oui | référence opaque retournée par `StockageExportInterface` (§2.7) |
| `messageErreur` | `string(255)` | oui | si `echec` |
| `genereLe` | `datetime_immutable` | non | — |
| `envoyeLe` | `datetime_immutable` | oui | — |
| `demandePar` | `ManyToOne Utilisateur` | oui | nul pour un export planifié automatique |

Table `report_export`. `security:` `Get`/`GetCollection` = `reporting.lire` + filtre périmètre ;
téléchargement via contrôleur dédié (§3).

### 1.10 Enums (`App\Reporting\Enum\*`)

`NiveauEntite`, `TypeAxeAnalytique`, `UniteIndicateur`, `ModeCalculIndicateur`, `NatureIndicateur`,
`SourceModuleIndicateur`, `GranulariteMesure`, `RegimeExploitantMesure`, `StatutCompletude`,
`FormatExport`, `PeriodiciteRapport`, `EtatRapportPlanifie`, `StatutExport`.

### 1.11 `App\Reporting\ValueObject\Periode`

`debut: \DateTimeImmutable`, `fin: \DateTimeImmutable`, `granularite: GranulariteMesure` — VO
immutable partagé par les projections, l'agrégateur et les Providers de dashboard (une seule
définition des bornes jour/semaine/mois/année dans tout le module, RG-M7-02).

---

## 2. Décisions structurantes

### 2.1 Services de projection en lecture (RG-M7-02, `App\Reporting\Projection\*`)

Une interface par module producteur + un adaptateur Doctrine par défaut (aliasing dans
`services.yaml`) — **aucune** requête directe d'un Provider/de la commande d'agrégation vers une
entité d'un autre module hors de ces interfaces :

| Interface | Méthode(s) clé | Adaptateur lit |
|---|---|---|
| `ProjectionVenteInterface` | `caEncaisse(Uuid $etablissementId, Periode $p): string` | `App\Vente\Entity\Vente` (`statut ∈ {Validee}` — cf. `estScellee()`), `SUM(total)` |
| `ProjectionAccesInterface` | `frequentationCumulee(Uuid $etablissementId, Periode $p): int` ; `jaugesFmi(Uuid $etablissementId): list<{espace,valeurCourante,seuil,mode}>` (lecture directe, pour M7-01 temps réel) ; `etablissementHorsLigne(Uuid $etablissementId): bool` (§2.6) | `App\Acces\Entity\Passage` (`COUNT` où `resultat=Autorise ∧ sens=Entree`), `App\Acces\Entity\JaugeFmi`/`EspaceAcces`, `App\Acces\Entity\Controleur.etat` |
| `ProjectionComptaInterface` | `fondDeCaisseTheorique(Uuid $etablissementId): string` ; `regimeExploitant(Uuid $etablissementId): TypeExploitant` ; `syntheseRegimeIsolee(Uuid $etablissementId): array` (RAD/redevance, §2.5) | `App\Caisse\Entity\SessionCaisse` (ouvertes) + `Vente\Paiement` espèces cumulés, `App\Compta\Entity\ProfilExploitant::couvre()`, `Rad`, `Redevance` |
| `ProjectionReservationInterface` | `tauxRemplissage(...)`, `noShow(...)` | `Creneau`, `Reservation`, `FacturationNoShow` |
| `ProjectionRecouvrementInterface` | `impayes(Uuid $etablissementId, Periode $p): array{montant, nombre}` | `IncidentImpaye` |

`Indicateur.sourceModule` pilote le **dispatch** dans `AgregateurMesuresService` (un `match` explicite
code→projection, pas de mapping dynamique magique — testable, lisible). Aucun indicateur n'est
recalculé différemment selon l'écran consommateur (dashboards/Explorateur/rapports lisent tous
`Mesure`, jamais les entités sources directement, **sauf** M7-01 pour `caJour`/`entreesJour`/`fmi
courante`/`fondDeCaisse` qui restent en lecture directe via ces mêmes interfaces de projection pour
le temps réel, §2.4).

`cleAgregation` = `hash('sha256', implode('|', [indicateur.code, niveau, etablissementId??region
Id??groupeId, activite, produit, categorie, canal, periodeDebut, periodeFin, granularite]))` —
upsert idempotent (recalculer une période déjà agrégée **met à jour** la ligne, ne duplique jamais).

### 2.2 `PerimetreReportingResolver` (`App\Reporting\Security\PerimetreReportingResolver`)

Service (pas un Voter — dépend d'un calcul multi-entités, pas d'un simple couple module×action) :

```
etablissementsAutorises(Utilisateur $u, string $action = 'lire'): list<Uuid>
```
Union de `Affectation.etablissement` où le `Role` porte `reporting.$action` (ou `reporting.*`) —
**sans filtrer par établissement actif** (`X-Etablissement`), à la différence de
`PermissionVoter`/`ContexteEtablissement` : un dashboard région/groupe a par nature besoin du
périmètre **complet** de l'utilisateur, pas d'un seul établissement courant.

```
regionsCouvertes(list<Uuid> $etabs): list<Uuid>   // région dont TOUS les établissements ⊆ $etabs
groupesCouverts(list<Uuid> $etabs): list<Uuid>    // groupe dont TOUTES les régions sont couvertes
perimetreEffectif(Utilisateur $u, string $action = 'lire'): PerimetreReporting  // VO récapitulatif
```

`PerimetreReporting` (VO) expose `estAutoriseEtablissement(Uuid)`, `estAutoriseRegion(Uuid)`,
`estAutoriseGroupe(Uuid)` — utilisé par les Providers de dashboard (403 si demandé hors périmètre,
CA-1) **et** par `App\Reporting\Doctrine\PerimetreReportingExtension` (même patron que
`PerimetreEtablissementExtension`/`PerimetreVenteExtension`, §4) pour `GetCollection`/`Get` de
`Mesure`, `TableauDeBord`, `Export`, `RapportPlanifie`.

- **Cas limite « rôles sur établissements non contigus »** (spec §7) : si l'union ne couvre aucune
  région/groupe entière, `regionsCouvertes()`/`groupesCouverts()` renvoient `[]` — seuls M7-01 (par
  établissement) et l'Explorateur (filtré à `etablissementsAutorises()`) restent accessibles ; aucun
  dashboard région/groupe n'est exposé pour cet utilisateur (comportement dérivé automatiquement,
  pas de branche spéciale à coder).

### 2.3 Cloisonnement par destinataire d'un `RapportPlanifie` (RG-M7-07, US-L11-06)

`RapportPlanifieProcessor` (Post/Patch) valide, pour **chaque** `DestinataireRapport` soumis, que son
périmètre (niveau + entité) est **inclus** dans `PerimetreReportingResolver::perimetreEffectif($createur,
'planifier')` de l'utilisateur qui crée/modifie le rapport (422 sinon) — empêche un directeur de site
de planifier un rapport « groupe » en configurant un destinataire hors de son propre périmètre. Un
administrateur (`reporting.configurer`, périmètre M8 large) peut composer des rapports multi-niveaux
(site + région, cas explicite de RG-M7-07) tant que chaque destinataire reste dans **son** périmètre à
lui.

À la génération (`reporting:executer-rapports`, §2.8), **chaque** `DestinataireRapport` produit un
`Export` **individualisé**, filtré strictement à son propre `niveau`/entité — jamais le périmètre du
rapport « au sens large » (CA-8).

### 2.4 Dashboards par niveau (US-L11-01/02/03) — fraîcheur différenciée (cas limite spec §7)

- **M7-01 (établissement, temps réel, CA-2)** — `App\Reporting\State\DashboardEtablissementProvider`
  lit **en direct** (pas de passage par `Mesure`) via les interfaces de projection §2.1 : `caJour`
  (`ProjectionVenteInterface::caEncaisse` bornes = jour courant), `entreesJour`
  (`ProjectionAccesInterface::frequentationCumulee`), `jaugesFmi` (liste par `EspaceAcces`, état
  `alerte` si `valeurCourante ≥ seuil`), `fondDeCaisse`
  (`ProjectionComptaInterface::fondDeCaisseTheorique`). Aucune mise en cache : chaque appel HTTP est
  une lecture fraîche des entités sources (charge bornée : un établissement, quelques requêtes
  indexées) — satisfait « rafraîchissement sans rechargement manuel » côté front (poll court).
- **M7-02/M7-03 (région/groupe, comparatif/consolidé, CA-3/CA-4)** — `DashboardRegionProvider`/
  `DashboardGroupeProvider` lisent **exclusivement** `Mesure` (jamais les entités sources) : coût
  borné indépendamment du nombre de sites, alimenté par `reporting:agreger` exécutée en tâche de fond
  à fréquence paramétrable (§2.9). Un **délai de fraîcheur** (`genereLe` de la `Mesure` la plus
  ancienne du jeu retourné) est renvoyé dans la réponse pour affichage explicite (« actualisé il y a
  X min ») — répond au cas limite spec §7 « pas garanti temps réel strict au-delà du site ».
- Écarts vs n-1 : deuxième lecture de `Mesure` sur la période de référence (n-1), calcul dans
  `App\Reporting\Service\CalculComparaisonService` (`ecartValeur`, `ecartPourcentage`, code couleur
  bon/à surveiller/critique — seuils configurables par indicateur, valeur par défaut documentée en
  Risques §9.5). Écart vs objectif : lecture `ObjectifIndicateur` (§1.5) si présent, sinon absent du
  payload (pas d'erreur).
- **Comparaison n-1 avec périmètre changé** (spec §7, arbre mutable) : le calcul de région/groupe
  regroupe les `Mesure(niveau=etablissement)` **actuellement** rattachées à la région (dénormalisation
  `region_id` recalculée à chaque `reporting:agreger`, donc reflète toujours le rattachement
  **courant**) — le comparatif n-1 additionne les valeurs historiques des sites **actuellement**
  membres, avec `statutCompletude=partiel` + note explicite si l'historique n'existe pas sous ce
  rattachement sur toute la période n-1 (détecté par absence de ligne `Mesure` pour ce site sur la
  période de référence).

### 2.5 FMI ≠ fréquentation cumulée — agrégation (RG-M7-04, US-L11-07, point d'attention majeur)

- `FREQUENTATION_CUMULEE` : `nature=cumule`, `modeCalcul=somme` — sommable site → région → groupe
  sans restriction (RG-M7-03).
- `FMI_MAX` : `nature=instantane`, calculé **uniquement au niveau établissement**, par
  **échantillonnage** : à chaque exécution de `reporting:agreger` (fréquence paramétrable, §2.9),
  l'agrégateur lit `ProjectionAccesInterface::jaugesFmi()` (valeur **déjà calculée** par le module
  Accès, jamais recalculée — RG-ACC-06/§2 spec exclusions), prend le **max des espaces** de
  l'établissement, et met à jour `Mesure(FMI_MAX, jour) = GREATEST(valeur_existante,
  nouvel_échantillon)`. **Limite documentée** (Risques §9.6) : un pic entre deux échantillons peut ne
  pas être capturé — acceptable pour du reporting de pilotage (le contrôle de sécurité ERP temps réel
  reste dans Accès, hors périmètre M7 §2 spec).
- **Jamais d'agrégation naïve de `FMI_MAX` au-delà du site.** Deux indicateurs **distincts**, avec
  libellé explicite imposé par construction (`Indicateur.libelle`), calculés par
  `AgregateurMesuresService` à la passe région/groupe à partir des `Mesure(FMI_MAX,
  niveau=etablissement)` de la période :
  - `FMI_MAX_SOMME_SITES` (`modeCalcul=somme`, libellé **« Somme des FMI max des sites »**).
  - `FMI_MAX_SITE_CRITIQUE` (`modeCalcul=max`, libellé **« FMI max — site le plus critique »**, la
    `Mesure` région/groupe porte en plus un `axes`/méta pointant l'établissement concerné — champ
    `activite`/`produit` réutilisés serait trompeur, donc **méta dédiée** : le payload du Provider
    dashboard région/groupe joint l'établissement critique via une requête complémentaire, pas stocké
    sur `Mesure` elle-même pour ne pas complexifier le schéma générique).
  - Aucun écran, export ou libellé ne restitue une valeur nommée simplement « FMI » au-delà du site :
    **CA-6 est garanti structurellement** par l'existence de codes `Indicateur` distincts plutôt que
    par une convention de présentation seule.

### 2.6 Mode dégradé / complétude (RG-M7-08, RG-REPORT-11, US-L11-09)

- Signal de fraîcheur retenu (⚠ heuristique documentée, cf. Risques §9.7 — RG-REPORT-11 ne précise
  pas le mécanisme de détection) : `ProjectionAccesInterface::etablissementHorsLigne()` lit
  `App\Acces\Entity\Controleur.etat` (déjà maintenu par `EtatReseauHandler`, socle Accès) — un
  établissement est considéré en défaut de remontée si **au moins un** de ses contrôleurs est
  `hors_ligne`/`hors_service` au moment de l'agrégation.
- `Indicateur.seuilCompletudeMinutes` (§1.3) : à la passe site, si le signal ci-dessus est actif
  depuis plus longtemps que ce seuil, la `Mesure(niveau=etablissement)` de cet indicateur est marquée
  `statutCompletude=partiel`.
- Passes région/groupe : `statutCompletude=partiel` **dès qu'une** `Mesure` fille l'est ;
  `sitesManquants` = union des établissements en défaut (comptés au minimum, RG-REPORT-11) — la
  consolidation **se poursuit toujours** (jamais de blocage, valeur calculée sur les sites disponibles
  + marquage explicite).
- Restitution : badge « données partielles » porté par tout payload de dashboard/Explorateur/`Export`
  dérivé d'une `Mesure` `partielle` (jamais de marquage silencieux, CA-10).
- **Alerte prolongée** (spec §7, cas limite) — hors périmètre de ce lot MVP (documenté Risques §9.8) :
  aucune notification proactive à l'administrateur au-delà de N jours consécutifs de complétude
  partielle ; le badge reste visible en continu, ce qui couvre le besoin minimal « jamais silencieux ».

### 2.7 Consolidation multi-régime (RG-REPORT-09, US-L11-08)

- `AgregateurMesuresService`, passe région/groupe : pour un indicateur à `sourceModule=vente` (CA
  notamment), collecte `ProjectionComptaInterface::regimeExploitant()` de chaque établissement du
  périmètre agrégé ; si l'ensemble contient **plus d'un** `TypeExploitant` distinct, la `Mesure`
  résultante porte `comparabiliteRegime=true` et `regimeExploitant=mixte` — l'agrégat **est produit**
  normalement (RG-M7-03 standard), jamais bloqué (CA-9).
- **Vues isolées non fusionnées** : `ProjectionComptaInterface::syntheseRegimeIsolee()` alimente une
  section séparée du payload `DashboardRegionVue`/`DashboardGroupeVue` (RAD/redevances DSP lues sur
  `App\Compta\Entity\{Rad,Redevance}`, état de régie publique sur `RegieRecettes`) — **jamais**
  sommée dans `Mesure(CA)` : lecture à la demande, pas de pré-agrégation dédiée (volumétrie faible,
  section secondaire de l'écran).
- **Filtre par régime dans l'Explorateur** (cas limite spec §7) : `ExplorateurProvider` accepte un
  paramètre `regimeExploitant` filtrant les `Mesure(niveau=etablissement)` sous-jacentes avant
  re-agrégation à la volée pour retrouver une comparabilité stricte.

### 2.8 Rapports planifiés & exports (RG-M7-06, US-L11-05)

- `reporting:executer-rapports` (`App\Reporting\Command\ExecuterRapportsCommand`, même patron que
  `securite:delegations:expirer` — commande console + cron externe, §8) : sélectionne les
  `RapportPlanifie` `actif` dont `prochainEnvoi <= now` (calculé à la création/dernier envoi selon
  `periodicite`/`heureEnvoi`) ; pour chacun, pour **chaque** `DestinataireRapport` : construit le
  payload (indicateurs du `TableauDeBord`, filtré au périmètre du destinataire, §2.3), génère un
  `Export` (§2.9), envoie l'e-mail (`App\Reporting\Notification\RapportPlanifieMailer`, pièce jointe,
  même patron `symfony/mailer` que `ReinitialisationMailer`), met à jour `dernierEnvoi`/`prochainEnvoi`.
  Un rapport `suspendu` n'est **jamais** sélectionné (CA-7).
- **Génération** — `App\Reporting\Service\GenerateurExportInterface::generer(payload, format):
  ExportGenere` (bytes + extension). Deux implémentations :
  - `CsvGenerateurExport` — **réellement fonctionnel**, `fputcsv` sur les lignes `Mesure` du
    périmètre/axes demandés (même patron `StreamedResponse`/`fputcsv` que
    `ExportAuditController`), format simple et testable de bout en bout.
  - `PdfGenerateurExportStub` / `XlsxGenerateurExportStub` — **ports documentés, non implémentés en
    MVP** : lèvent `GenerationExportNonSupporteeException` (message explicite « format PDF/XLSX non
    disponible dans cette version, utiliser CSV »), interceptée par la commande qui marque
    `Export.statut=echec` avec `messageErreur` clair plutôt que de planter silencieusement — un
    `RapportPlanifie` configuré en `pdf`/`xlsx` reste **créable** (ne bloque pas la configuration
    fonctionnelle décrite au cahier M7-05) mais échoue proprement à l'exécution tant qu'un vrai
    générateur n'est pas branché (dépendance externe, §8 — Risques §9.9).
- **Stockage** — `App\Reporting\Service\StockageExportInterface::stocker(string $contenu, string
  $extension): string` (retourne une référence opaque) / `recuperer(string $reference): string` ;
  adaptateur `StockageExportLocal` (filesystem, `%kernel.project_dir%/var/reporting/exports/`, nom =
  `Export.id`). Port isolé pour permettre un futur adaptateur S3-compatible sans changer l'appelant.
- **Export manuel** (US-L11-05 note §3 spec, ⚠ HYPOTHÈSE reconduite) — `POST /reporting/exports`
  (`reporting.lire`, `App\Reporting\State\ExportManuelProcessor`) : génère immédiatement (CSV
  garanti, PDF/XLSX soumis à la même limite) un `Export(rapportPlanifie=null, demandePar=$user)`
  borné au périmètre de l'appelant (vérifié via `PerimetreReportingResolver`), retourne l'`id` ; le
  fichier se télécharge via `GET /reporting/exports/{id}/telecharger`
  (`App\Reporting\Controller\ExportTelechargerController`, `reporting.lire` + `demandePar == user`
  **ou** `reporting.configurer`).

### 2.9 Commande d'agrégation & performance (`reporting:agreger`)

`App\Reporting\Command\AgregerMesuresCommand` — options `--depuis`/`--jusqu-a` (rattrapage,
idempotent via `cleAgregation`), défaut = jour courant. Trois passes séquentielles (§2.1/§2.5/§2.7/§2.6) :
site → région → groupe, chacune s'appuyant **uniquement** sur les résultats de la passe précédente
(jamais de re-lecture des entités sources à la passe région/groupe) — coût **linéaire** en nombre
d'établissements, indépendant du volume de `Passage`/`Vente` bruts une fois la passe site faite.
Ordonnancement **cron externe**, même arbitrage que `securite:delegations:expirer` (§8, pas de
nouveau worker permanent) — fréquence recommandée : toutes les 5 minutes pour capter les échantillons
FMI (§2.5) avec une granularité raisonnable, documentée en configuration (`reporting.frequence_agregation_minutes`,
paramètre informatif, la fréquence réelle dépend du cron externe).

### 2.10 Fuseau horaire & devise (RG-REPORT-10)

Toute écriture (`Mesure.genereLe`, `Passage.horodatage`, `Vente.date`, etc.) reste en **UTC en base**
(inchangé, socle). `Mesure.fuseauReference` porte le fuseau à utiliser **à l'affichage uniquement**
(site → fuseau du site ; région/groupe → fuseau de référence configuré, ⚠ HYPOTHÈSE : paramètre
`organisation.fuseau_reference_defaut = Europe/Paris` en l'absence d'un fuseau par `Region`/`Groupe`
dans le socle actuel — aucun champ fuseau sur `Region`/`Groupe` aujourd'hui, cf. Risques §9.10) — la
conversion elle-même est **front-end** (le back expose la valeur UTC + le fuseau à appliquer, jamais
une conversion serveur ambiguë). Multi-devise explicitement **hors MVP** (RG-REPORT-10, `devise` figée
`EUR` en pratique tant qu'un seul pays fiscal est actif).

---

## 3. API (API Platform)

| Ressource / route | Opération | `security:` | Groupes sérialisation | Notes |
|---|---|---|---|---|
| `AxeAnalytique` | `GetCollection`/`Get` | `reporting.lire` | `axe:read` | référentiel |
| `AxeAnalytique` | `Post`/`Patch` | `reporting.configurer` | `axe:write` | pas de `Delete` (désactivation via `actif`) |
| `Indicateur` | `GetCollection`/`Get` | `reporting.lire` | `indicateur:read` | filtre `SearchFilter` sur `code`/`sourceModule`/`actif` |
| `Indicateur` | `Post`/`Patch` | `reporting.configurer` | `indicateur:write` | pas de `Delete` |
| `ObjectifIndicateur` | CRUD complet | `reporting.configurer` (écriture) / `reporting.lire` (lecture, filtré périmètre) | `objectif:read/write` | §1.5 |
| `TableauDeBord` | CRUD complet | `reporting.configurer` (écriture) / `reporting.lire` (lecture) | `tdb:read/write` | filtré `PerimetreReportingExtension` ; `Assert\Count(min:1)` sur `indicateurs` au Processor |
| `Mesure` | `GetCollection`/`Get` uniquement | `reporting.lire` | `mesure:read` | filtré périmètre ; `ApiFilter` `indicateur`,`niveau`,`etablissement`,`region`,`groupe`,`periodeDebut/Fin`,`activite`,`produit`,`categorie`,`canal` |
| — | `GET /reporting/dashboards/etablissement/{id}` (Provider) | `reporting.lire` | — | CA-2, §2.4, 403 si `id` hors périmètre |
| — | `GET /reporting/dashboards/region/{id}` (Provider) | `reporting.lire` | — | CA-3, §2.4, 403 si région non **entièrement** couverte |
| — | `GET /reporting/dashboards/groupe/{id}` (Provider) | `reporting.lire` | — | CA-4, §2.4 |
| — | `GET /reporting/explorateur` (Provider, query params axes/période/indicateur/régime) | `reporting.lire` | — | CA-5, filtré périmètre, §2.7 filtre régime |
| `RapportPlanifie` | CRUD complet | `reporting.planifier` (écriture) / `reporting.lire`+`reporting.planifier` (lecture) | `rapport:read/write` | Processor §2.3, filtré `PerimetreReportingExtension` |
| — | `POST /reporting/exports` (custom, `ExportManuelProcessor`) | `reporting.lire` | `export:read` | §2.8 |
| `Export` | `GetCollection`/`Get` | `reporting.lire` | `export:read` | filtré périmètre + `demandePar == user` pour les exports manuels |
| — | `GET /reporting/exports/{id}/telecharger` (contrôleur) | `reporting.lire` + (`demandePar==user` ou `reporting.configurer`) | — | streaming fichier depuis `StockageExportInterface` |

---

## 4. Sécurité & droits

- **Permissions nouvelles** — module `reporting` (nouveau) : `reporting.lire`, `reporting.planifier`,
  `reporting.configurer`. Aucune matrice de droits propre à M7 au-delà de ce module (RG-M7-01) : le
  **périmètre** (quel établissement/région/groupe) reste entièrement dérivé de `Affectation` (M8),
  jamais stocké/dupliqué dans `App\Reporting`.
- **Aucun nouveau Voter** : `PermissionVoter` (`PERM`, socle) inchangé pour le couple module×action.
  Le périmètre multi-niveaux est porté par le service `PerimetreReportingResolver` (§2.2, pas un
  Voter — dépend d'un calcul ensembliste sur plusieurs entités, pas d'un simple couple), consommé par
  les Providers (contrôle explicite → 403) et par `App\Reporting\Doctrine\PerimetreReportingExtension`
  (même patron `QueryCollectionExtensionInterface`/`QueryItemExtensionInterface` que
  `PerimetreEtablissementExtension`/`PerimetreVenteExtension`, appliqué à `Mesure`, `TableauDeBord`,
  `RapportPlanifie`, `Export`).
- **Invariants métier en Processor** (pas des Voters, dépendent de l'objet écrit) :
  `RapportPlanifieProcessor` (§2.3, destinataires ⊆ périmètre créateur), `TableauDeBordProcessor`
  (`indicateurs` ≥ 1), `ExportManuelProcessor` (périmètre demandé ⊆ périmètre appelant).

---

## 5. Migrations

1. **Migration structurelle** (`VersionYYYYMMDDHHMMSS_l11_schema.php`) : crée `report_axe_analytique`,
   `report_indicateur`, `report_objectif_indicateur`, `report_mesure`, `report_tableau_de_bord`,
   `report_tableau_de_bord_indicateur`, `report_rapport_planifie`, `report_destinataire_rapport`,
   `report_export`, avec FKs vers `org_etablissement`/`org_region`/`org_groupe`/`sec_utilisateur` et
   les index listés §1.4. `report_mesure.cle_agregation` unique.
2. **Migration de données — permissions** : insère `Permission(module='reporting',
   action='lire'|'planifier'|'configurer')`.
3. **Migration de données — référentiel** : insère les `AxeAnalytique` (`site`, `activite`,
   `produit`, `categorie` [`estExtension=true`], `periode`, `canal` [`estExtension=true`]) et les
   `Indicateur` de base (`CA`, `FREQUENTATION_CUMULEE`, `FMI_MAX`, `FMI_MAX_SOMME_SITES`,
   `FMI_MAX_SITE_CRITIQUE`, `TAUX_REMPLISSAGE`, `NO_SHOW`, `IMPAYES`, `FOND_CAISSE`) avec leurs
   `sourceModule`/`modeCalcul`/`nature`/`unite` définis §1.3.
- Rejouables, versionnées Doctrine ; jamais de `schema:update --force` (constitution §7).

---

## 6. Tests (PHPUnit + `ApiTestCase`, `App\Tests\Reporting\*`)

| Test | Type | Couvre |
|---|---|---|
| `reporting:agreger` calcule `Mesure(FREQUENTATION_CUMULEE)` = `COUNT(Passage autorisé/entrée)` du jour, idempotent (relance = update, pas de doublon via `cleAgregation`) | Unit + Command | RG-M7-02, RG-M7-03 |
| Agrégation ascendante : `Mesure(niveau=region)` = somme des `Mesure(niveau=etablissement)` filles pour un indicateur `modeCalcul=somme` | Unit | RG-M7-03 |
| `FMI_MAX` (site) échantillonné = max des jauges de l'établissement à l'instant de l'agrégation ; deux exécutions successives ⇒ `GREATEST` conservé | Unit | RG-M7-04 |
| `FMI_MAX_SOMME_SITES`/`FMI_MAX_SITE_CRITIQUE` (région) : valeurs distinctes, libellés explicites présents dans le payload dashboard région, jamais un champ générique « FMI » agrégé | API + Unit | CA-6, RG-M7-04 |
| Fréquentation cumulée vs FMI sur un même établissement/jour : deux valeurs distinctes, jamais fusionnées dans un même champ/formule | API | CA-6 |
| Dashboard établissement (M7-01) : CA jour, entrées, jauges FMI (état alerte au franchissement de seuil), fond de caisse, lecture directe (pas de `Mesure`) | API | CA-2 |
| Dashboard région (M7-02) : sites côte à côte, classement, écart vs objectif (`ObjectifIndicateur`) et vs n-1, drill-down vers M7-01 | API | CA-3 |
| Dashboard groupe (M7-03) : consolidé toutes régions, drill-down région → site sans changement d'axes | API | CA-4 |
| Explorateur : combinaison d'axes autorisée renvoie une valeur strictement identique à celle du dashboard pour le même indicateur/périmètre | API | CA-5 |
| Cloisonnement — directeur régional Région A : voit l'agrégat de ses sites, accès à un site de Région B refusé (masqué) | API | CA-1 |
| Cloisonnement — utilisateur avec affectations sur établissements non contigus : aucun dashboard région/groupe exposé, Explorateur borné à ses établissements | API | cas limite §7 spec, §2.2 |
| `RapportPlanifie` actif : `reporting:executer-rapports` génère et envoie automatiquement à l'échéance, sans connexion du créateur ; `suspendu` ⇒ aucune génération | Command + Unit (mailer factice) | CA-7 |
| `RapportPlanifie` multi-destinataires (site + région) : chaque `Export` généré est strictement borné au périmètre de son destinataire | Command | CA-8 |
| Création d'un `RapportPlanifie` avec un destinataire hors du périmètre du créateur ⇒ 422 | API | §2.3 |
| Export CSV réellement généré et téléchargeable (contenu exploitable, en-têtes corrects) | API | RG-M7-06, §2.8 |
| Export PDF/XLSX demandé ⇒ `Export.statut=echec` avec message explicite (pas de plantage silencieux) | Unit + API | §2.8, Risques §9.9 |
| Consolidation multi-régime : région régie+DSP ⇒ `comparabiliteRegime=true`, vues RAD/redevance isolées non fusionnées dans l'agrégat CA | API + Unit | CA-9 |
| Complétude : établissement avec contrôleur hors-ligne au-delà du seuil ⇒ `Mesure` régionale `partielle`, `sitesManquants` renseigné, vue non bloquée | API + Unit | CA-10 |
| Fuseau : mesure UTC en base, affichage converti au fuseau du site (établissement) et au fuseau de référence (région/groupe) avec indication explicite | Unit | CA-11 |
| `Indicateur`/`AxeAnalytique` référencé par un `TableauDeBord`/`RapportPlanifie` actif : `Delete` non exposé, seule `Patch(actif=false)` disponible | API | cas limite §7 spec |

---

## 7. Tâches

Voir `specs/L11-reporting/tasks-reporting.md` (T1 → Tn, ordonnées, fichiers/dépendances/tests).

---

## 8. Dépendances

- **Aucune nouvelle dépendance composer obligatoire** : CSV via `fputcsv` (stdlib, même patron que
  `ExportAuditController`), e-mail via `symfony/mailer` (déjà ajouté par L7-backoffice). PDF/XLSX
  volontairement **non implémentés** (§2.8) — si retenus en V2, dépendances candidates à évaluer
  alors : `dompdf/dompdf` ou `mpdf/mpdf` (PDF), `phpoffice/phpspreadsheet` (XLSX).
- **Ordonnancement** `reporting:agreger` et `reporting:executer-rapports` : cron externe au code
  applicatif, même arbitrage que `securite:delegations:expirer` (pas de nouveau worker permanent).
- Dépend de **`specs/L0-socle/spec-socle.md`** (hiérarchie, identité), **`specs/L7-backoffice/
  spec-backoffice.md`** (M8, droits/périmètre hérité), **`specs/L2-vente/spec-vente.md`** (CA),
  **`specs/L3-acces/spec-acces.md`** (fréquentation/FMI), **`specs/L4-compta/spec-compta.md`** (M6,
  régie/régime), **`specs/reservation/spec-reservation.md`** (M5, remplissage/no-show),
  **`specs/sport-fitness/spec-sport.md`**/Recouvrement (impayés), **`specs/L1-offre/spec-offre.md`**
  (M1, produit/catégorie) — toutes lues en projection (§2.1), jamais modifiées.

---

## 9. Risques / à valider

1. **Résolution du périmètre région/groupe par dérivation, pas par affectation native M8** (§0, §2.2)
   — conséquence directe de l'écart `RG-M8-02` déjà signalé par `spec-backoffice.md` §7 (aucune
   `Affectation` de niveau Région/Groupe dans le socle actuel). Ce plan ne comble pas cet écart (hors
   périmètre M7) ; si une évolution M8 introduit un jour l'affectation hiérarchique native, la
   dérivation `PerimetreReportingResolver` pourra être simplifiée sans changer son contrat public.
2. **`ObjectifIndicateur` ajouté par ce plan**, absent du §5 « Objets de données » de la spec —
   nécessaire pour rendre CA-3 (« écart vs objectifs ») testable ; composition/valeurs cibles par
   défaut non fournies par le cahier, à valider côté produit.
3. **Absence de référentiel « Activité » unifié** dans le socle (`App\Organisation` ne porte pas de
   notion de verticale/activité par établissement) : `Mesure.activite` reste un code texte non
   contraint par FK — à revoir si une entité `Activite` structurée émerge d'un futur lot transverse.
4. **Axe `canal` en texte libre** plutôt qu'un `ManyToOne` vers `App\Offre\Enum\Canal` : ce dernier ne
   couvre pas `dsp`/`regie` cités par la spec M7 comme valeurs de canal — éviter de modifier l'enum M1
   pour un besoin propre au reporting ; liste blanche applicative à documenter/valider.
5. **Seuils de code couleur « bon/à surveiller/critique » (écarts n-1/objectif, M7-02)** — non
   chiffrés par le cahier ; valeurs par défaut à fixer dans `CalculComparaisonService`
   (`App\Reporting\Service`), configurables ultérieurement si besoin produit.
6. **FMI max échantillonnée, pas recalculée en continu** (§2.5) — un pic très bref entre deux
   exécutions de `reporting:agreger` peut ne pas être capturé au niveau reporting ; **sans impact
   sécurité** (le contrôle temps réel du seuil ERP reste entièrement dans Accès, M7 ne fait que
   restituer un indicateur de pilotage) — à confirmer explicitement avec la sécurité ERP (repris de
   la spec §7 « ⚠ HYPOTHÈSE, à confirmer »).
7. **Heuristique de complétude fondée sur l'état réseau des contrôleurs Accès** (§2.6) — RG-REPORT-11
   ne précise pas le mécanisme de détection ; un site sans contrôleur Accès (verticale sans contrôle
   d'accès physique) ou dont la remontée d'un *autre* module (M2/M6) est en défaut sans que son
   contrôleur Accès soit hors-ligne ne serait pas détecté par ce seul signal — signal à enrichir avec
   d'autres sources de fraîcheur si le besoin se confirme.
8. **Pas d'alerte administrateur automatique pour une complétude partielle prolongée** (cas limite
   spec §7) — hors MVP, badge persistant seul retenu.
9. **PDF/XLSX non implémentés (ports/stubs uniquement)** — un `RapportPlanifie` configuré dans ces
   formats échoue proprement à l'exécution (`Export.statut=echec`) tant qu'un générateur réel n'est
   pas branché ; **CSV est le seul format garanti fonctionnel du MVP**, conforme à la consigne de ce
   plan mais en écart avec « Excel ou PDF » du cahier M7-05 (déjà signalé ⚠ HYPOTHÈSE par la spec).
10. **Fuseau de référence région/groupe par défaut unique (`Europe/Paris`)**, faute de champ fuseau
    sur `Region`/`Groupe` dans le socle `App\Organisation` actuel — cohérent avec l'hypothèse socle
    « tout hébergé en France » (RG-M8), à revoir si une extension internationale ajoute ce champ.
11. **NF525/comptabilité publique** — M7 ne lit que des agrégats déjà scellés (`Vente`, `ClotureZ`,
    `EcritureComptable` via `Rad`/`Redevance`/`RegieRecettes`), aucune écriture ; pas d'impact
    identifié sur la valeur probante NF525 des modules producteurs. À confirmer par un expert
    comptable/ERP si un doute émergeait sur la restitution multi-régime (§2.7) ou sur l'usage de la
    « FMI max » en dehors du contexte sécurité (§2.5, point déjà porté par la spec).
