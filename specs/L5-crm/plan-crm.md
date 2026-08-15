# Plan technique — CRM noyau (`M4` / lot `L5`)

- **Spec source :** specs/L5-crm/spec-crm.md
- **Stack :** Symfony 7 · API Platform · Doctrine/MariaDB
- **Dépend de :** socle L0 (specs/L0-socle/plan-socle.md), M1 offre (specs/L1-offre/plan-offre.md), M2 vente
  (specs/L2-vente/plan-vente.md) — **réutilisés, non redéfinis**
- **Couvre :** US-L5-01 à US-L5-10 · RG-M4-01 à RG-M4-10 · CA-1 à CA-20

> **Réutilisation socle L0 (à ne pas dupliquer)** — hiérarchie `App\Organisation\Entity\{Groupe,Region,Etablissement,Espace}`
> (RG-SOCLE-01) ; `PermissionVoter` (attribut `PERM`, sujet `"module.action"`, RG-SOCLE-04) ; service
> `ContexteEtablissement` (en-tête `X-Etablissement`, RG-SOCLE-05) ; `Utilisateur`/`Affectation` (RG-SOCLE-03/06) ;
> `App\Audit\Entity\EntreeAudit` append-only (RG-SOCLE-07), **réutilisée** pour journaliser le passage à la
> majorité (CA-20) au lieu d'une entité dédiée.
>
> **Réutilisation M1 offre** — `Formule.sepaActif/jourPrelevement` porte la facette SEPA de l'abonnement ;
> M4 **référence** le mandat associé au payeur mais ne le redéfinit pas (point ouvert §10.3).
>
> **Réutilisation M2 vente** — `App\Vente\Entity\Vente.client` (ref logique UUID, pas de FK dure) est déjà le
> point d'ancrage de l'historique d'achat (RG-M2-04, US-L2-05) ; M4 ne duplique pas cette table, il **résout**
> l'historique via un port exposé par M2 (§2.3). Le port `App\Vente\Port\ClientM4Interface` (M2 → M4, recherche/
> création rapide) est déjà défini côté M2 en L2 avec un **stub** (`ClientM4Stub`) ; **ce plan livre
> l'implémentation réelle** (`App\Crm\Adapter\ClientM4Adapter`) et le câblage `services.yaml` qui remplace le stub.

---

## 1. Entités & schéma

Namespace : `App\Crm\Entity\*`. `id` = UUID (`Symfony\Component\Uid\Uuid`, type Doctrine `uuid`).
`declare(strict_types=1)` partout (constitution §3). Noms métier en français (constitution §7).

**Décision structurante — isolation des données personnelles.** Seule l'entité `Client` porte des champs
nominatifs (nom, email, téléphone, adresse…). Toutes les autres entités CRM (`PorteMonnaieVirtuel`,
`MouvementPmv`, `Consentement`, `DemandeRGPD`, `JournalFusion`, `Beneficiaire`) ne référencent le client que
par relation/UUID, sans dupliquer de PII. Conséquence directe : l'anonymisation RGPD (RG-M4-09) se limite à
purger les colonnes de `Client`, sans cascade sur les autres tables (§3).

**Décision structurante — portée du `Client` = Groupe** (arbitrage point ouvert §10.1). Un client est visible
et modifiable depuis n'importe quel établissement du même groupe (évite les doublons multi-sites, cohérent
avec la portée fréquente d'un abonné multi-équipements). Les **interactions** (mouvement PMV, création de
fiche) restent rattachées à l'établissement où elles ont eu lieu, pour la traçabilité et le paramétrage
(`ParametrePmvEtablissement` reste, lui, à la maille Établissement — RG-SOCLE-01).

### 1.1 Client & Famille

| Entité (`App\Crm\Entity\*`) | Champ | Type Doctrine | Null | Index/Contrainte | Relation |
|---|---|---|---|---|---|
| **Client** | id | `uuid` | non | PK | — |
| | groupe | — | non | FK `nullable:false` | `ManyToOne` → `Groupe` (socle) — portée de rattachement (§10.1) |
| | etablissementCreation | — | non | FK `nullable:false` | `ManyToOne` → `Etablissement` (socle) — traçabilité de création, pas cloisonnement |
| | type | `string(12)` enum `TypeClient` {physique, morale} | non | — | RG-M4/M4-02 |
| | civilite | `string(8)` | oui | — | — |
| | nom, prenom | `string(120)` | oui | requis (Assert conditionnelle) si `type=physique` | RG-M4-01 (saisie manuelle prévaut) |
| | raisonSociale | `string(180)` | oui | requis si `type=morale` | — |
| | siret | `string(14)` | oui | requis si `type=morale` | — |
| | dateNaissance | `date_immutable` | oui | — | déclenche `estMineur()`/RG-M4-10 |
| | email | `string(180)` | oui | — | ≥1 canal requis pour toute communication (cas limite §7 spec) |
| | telephone | `string(32)` | oui | — | — |
| | adresse | `json` {rue,complement,cp,ville,pays} | oui | — | — |
| | champManuel | `json` set de noms de champs | oui | RG-M4-01 (⚠ HYPOTHÈSE renommée `RG-M4-11`) | marque les champs saisis à la main, jamais écrasés par l'auto-enrichissement |
| | statut | `string(12)` enum `StatutClient` {actif, inactif, archive, anonymise, fusionne} | non | défaut `actif` | `fusionne` = redirigé vers `fusionneDans` |
| | fusionneDans | — | oui | self-ref | `ManyToOne` → `Client` — alias de fusion (chaîne A→B→C, §4) |
| | dateDerniereVisite | `datetime_immutable` | oui | mis à jour par l'enrichissement auto | CA-3 |
| | caCumule | `decimal(10,2)` | oui | agrégat dénormalisé, recalculable | CA-3 (agrégat CA) |
| | dateCreation, creePar, dateMaj, majPar | `datetime_immutable`/FK `Utilisateur` | non | audit applicatif | complète `EntreeAudit` (RG-SOCLE-07) |
| **Famille** | id | `uuid` | non | PK | RG-M4-02 |
| | groupe | — | non | FK `nullable:false` | `ManyToOne` → `Groupe` (même portée que Client) |
| | libelle | `string(120)` | oui | — | — |
| | payeurPrincipal | — | non | FK | `ManyToOne` → `Client` |
| | statut | `string(12)` enum `StatutFamille` {active, fusionnee, dissoute} | non | défaut `active` | — |
| | dateCreation | `datetime_immutable` | non | — | — |
| **Beneficiaire** (jointure Client↔Famille enrichie) | id | `uuid` | non | PK | table pivot, US-L5-03 |
| | famille | — | non | FK | `ManyToOne` → `Famille` |
| | client | — | non | FK | `ManyToOne` → `Client` |
| | role | `string(24)` enum `RoleBeneficiaire` {payeur, beneficiaire, payeur_et_beneficiaire} | non | — | RG-M4-02 |
| | autorisations | `json` set ⊂ {recharger_pmv, acheter_pour_famille, recuperer_mineur, entree_seule, activite_encadree} | oui | — | portées par le bénéficiaire (M4-03) |
| | dateAjout | `datetime_immutable` | non | — | — |
| | dateRetrait | `datetime_immutable` | oui | ajout/retrait **tracé et réversible** (jamais de suppression physique) | US-L5-03, réutilisé pour la fusion (§4) |
| | **Contrainte applicative** | — | — | — | un `client` actif (`dateRetrait IS NULL`) dans **une seule** `Famille` `active` à la fois → alerte sinon (CA-6), pas de contrainte SQL dure (le cas limite reste possible en écriture concurrente, à arbitrer si volumétrie l'exige) |

### 1.2 Porte-monnaie virtuel

| Entité | Champ | Type Doctrine | Null | Index/Contrainte | Relation |
|---|---|---|---|---|---|
| **PorteMonnaieVirtuel** | id | `uuid` | non | PK | 1:1 avec `Client` — créé **à la demande** (1ʳᵉ recharge), pas systématiquement à la création du client |
| | client | — | non | **OneToOne, unique** | `ManyToOne`/`OneToOne` → `Client` |
| | solde | `decimal(10,2)` | non | **check `≥ 0`** (RG-M4-03), défaut `0.00` | jamais négatif |
| | devise | `string(3)` | non | défaut `EUR` | — |
| | dateEcheance | `date_immutable` | oui | requis si `statut=actif` | calculée selon `ParametrePmvEtablissement.regleEcheance` |
| | statut | `string(12)` enum `StatutPmv` {actif, expire} | non | défaut `expire` (jusqu'à 1ʳᵉ recharge) | flux §2 |
| **MouvementPmv** | id | `uuid` | non | PK | **append-only** (garde ORM, §3) |
| | pmv | — | non | FK | `ManyToOne` → `PorteMonnaieVirtuel` |
| | type | `string(24)` enum `TypeMouvementPmv` {recharge, debit_vente, remboursement_vente, expiration, ajustement} | non | — | — |
| | montant | `decimal(10,2)` | non | signé selon `type` | — |
| | soldeApres | `decimal(10,2)` | non | snapshot | audit |
| | dateMouvement | `datetime_immutable` | non | — | — |
| | canal | `string(12)` enum `CanalMouvementPmv` {caisse, en_ligne, autre} | oui | requis si `type=recharge` (Assert applicative) | US-L5-04 |
| | etablissement | — | non | FK `nullable:false` | `ManyToOne` → `Etablissement` (socle) — site de l'interaction |
| | refVenteM2 | `uuid` | oui | ref logique `Vente` (M2), pas de FK dure | lien débit/remboursement ↔ vente |
| | utilisateur | — | non | FK | `ManyToOne` → `Utilisateur` (socle), RG-SOCLE-07 |
| | motif | `string(255)` | oui | requis si `type ∈ {ajustement,expiration}` | — |
| **ParametrePmvEtablissement** | id | `uuid` | non | PK, **unique par `etablissement`** | portée établissement (RG-SOCLE-01), volontairement **pas** au niveau Groupe (règles de site) |
| | etablissement | — | non | FK `nullable:false, unique` | `ManyToOne` → `Etablissement` |
| | rechargeExpireeAutorisee | `boolean` | non | défaut `false` | US-L5-06 |
| | regleEcheance | `json` {mode: duree_jours\|jour_fixe, valeur} | non | — | calcul `dateEcheance` |
| | traitementSoldeResiduel | `string(24)` enum `TraitementSoldeResiduel` {conserve, annule, transforme_en_produit} | non | défaut `conserve` | US-L5-07 |

### 1.3 RGPD & fusion

| Entité | Champ | Type Doctrine | Null | Index/Contrainte | Relation |
|---|---|---|---|---|---|
| **Consentement** | id | `uuid` | non | PK | **append-only** (chaque changement = nouvelle ligne, §3) |
| | client | — | non | FK | `ManyToOne` → `Client` |
| | canal | `string(12)` enum `CanalConsentement` {email, sms, courrier} | non | RG-M4-07 | — |
| | etat | `string(12)` enum `EtatConsentement` {accorde, refuse, a_renouveler, expire} | non | — | `a_renouveler` = passage majorité (US-L5-10) |
| | dateRecueil | `datetime_immutable` | non | — | — |
| | dateExpiration | `date_immutable` | oui | — | — |
| | source | `string(64)` | non | caisse, tunnel en ligne, formulaire papier… | — |
| | recueilliParRepresentant | `boolean` | non | défaut `false`, requis vrai si `estMineur` au recueil | RG-M4-10 |
| **DemandeRGPD** | id | `uuid` | non | PK | droit à l'effacement (US-L5-09) |
| | client | — | non | FK | `ManyToOne` → `Client` |
| | type | `string(16)` enum `TypeDemandeRgpd` {effacement, anonymisation} | non | RG-M4-09 | — |
| | statut | `string(12)` enum `StatutDemandeRgpd` {recue, en_cours, realisee, refusee} | non | — | verrouillée en écriture une fois `realisee` (garde ORM) |
| | dateDemande | `datetime_immutable` | non | — | — |
| | dateTraitement | `datetime_immutable` | oui | requis si `realisee`/`refusee` | — |
| | traitePar | — | oui | FK, requis si traitée | `ManyToOne` → `Utilisateur` |
| **RegleConservation** | id | `uuid` | non | PK | RG-M4-08 |
| | groupe | — | non | FK `nullable:false` | `ManyToOne` → `Groupe` — même portée que Client (⚠ HYPOTHÈSE, non spécifiée par le cahier) |
| | categorieDonnee | `string(64)` | non | ex. `identite`, `historique_achat`, `consentement_marketing` | — |
| | dureeMois | `integer` | non | `> 0` | — |
| | actionEcheance | `string(16)` enum `ActionConservation` {purge, anonymisation} | non | — | — |
| **JournalFusion** | id | `uuid` | non | PK | RG-M4-06, **append-only sauf transition défusion** |
| | portee | `string(12)` enum `PorteeFusion` {client, famille} | non | — | — |
| | fichesSources | `json` liste d'UUID | non | `≥ 2` | Client ou Famille selon `portee` |
| | ficheSurvivante | `uuid` | non | — | fiche/famille maître |
| | champsArbitres | `json` map(champ→valeur retenue) | non | prévisualisation avant validation (US-L5-08) | — |
| | snapshotAvant | `json` | non | état complet des fiches sources avant fusion (PII, PMV, appartenances) | nécessaire pour restaurer « à l'identique » (CA-14) |
| | motif | `string(255)` | oui | — | — |
| | effectuePar | — | non | FK | `ManyToOne` → `Utilisateur` |
| | dateFusion | `datetime_immutable` | non | — | RG-SOCLE-07 |
| | statut | `string(16)` enum `StatutJournalFusion` {active, defusionnee} | non | défaut `active` | seul champ mutable (avec les 2 suivants) |
| | dateDefusion | `datetime_immutable` | oui | requis si `defusionnee` | — |
| | defusionnePar | — | oui | FK, requis si `defusionnee` | `ManyToOne` → `Utilisateur` |

> **Rattachement multi-entités récapitulatif** : `Client`/`Famille`/`RegleConservation` = **Groupe**
> (§10.1) ; `ParametrePmvEtablissement` = **Établissement** (paramètre de site) ; `MouvementPmv` porte un
> `Etablissement` d'interaction en plus de son rattachement logique au client (Groupe) — double lecture :
> cloisonnement par Groupe, traçabilité par Établissement. `PorteMonnaieVirtuel`, `Beneficiaire`,
> `Consentement`, `DemandeRGPD`, `JournalFusion` héritent de la portée Groupe de leur `Client`/`Famille`.

---

## 2. Articulation PMV ↔ paiement M2

**Constat code (repéré dans `app/src/Vente/Port/`)** : M2 définit déjà 3 ports vers des modules externes
(`ReferentielReglementInterface` → M6, `ClientM4Interface` → M4, `AppairageAccesInterface` → module Accès),
mais **aucun port PMV n'existe encore** — `PaiementHandler` traite `pmv` comme un simple code de moyen de
paiement (`ReferentielReglementStub` le déclare, `RG-M2-02`) sans jamais vérifier ni débiter de solde.
Ce plan **ajoute le 4ᵉ port manquant**, suivant exactement le même patron que les trois existants (interface
+ objet-valeur dans `App\Vente\Port`, stub L2 déjà en place à remplacer, implémentation réelle fournie par
L5, câblage dans `services.yaml`).

### 2.1 Nouveau port `App\Vente\Port\PorteMonnaieVirtuelInterface`

```
interface PorteMonnaieVirtuelInterface {
    public function solde(Uuid $clientId): ?SoldePmv;              // {solde, statut, dateEcheance} — CA-10
    public function debiter(Uuid $clientId, string $montant, Uuid $venteId): ResultatDebitPmv; // atomique
    public function crediter(Uuid $clientId, string $montant, Uuid $venteId, string $motif): void; // recharge/remboursement
}
```

- `SoldePmv` (objet-valeur, `App\Vente\Port\SoldePmv`) : miroir minimal de `PorteMonnaieVirtuel`, sans exposer
  l'entité Doctrine de M4 à M2 (respect de la frontière modules).
- `ResultatDebitPmv` : `{reussi: bool, motifRefus: ?string}` — insuffisance de solde ou PMV expiré → `reussi=false`,
  jamais d'exception métier générique (le `PaiementHandler` traduit en 422).
- Le **stub L2** (`PorteMonnaieVirtuelStub`, à ajouter à côté des stubs existants) renvoie systématiquement un
  solde nul / refus, pour ne pas bloquer les tests M2 déjà écrits qui n'exercent pas le moyen `pmv` en détail.
- **Implémentation réelle** : `App\Crm\Adapter\PorteMonnaieVirtuelAdapter implements PorteMonnaieVirtuelInterface`
  (module L5), câblée dans `config/services.yaml` : `App\Vente\Port\PorteMonnaieVirtuelInterface: '@App\Crm\Adapter\PorteMonnaieVirtuelAdapter'`
  — remplace le stub, symétrique au remplacement de `ClientM4Stub` (§0, réutilisation M2).

### 2.2 Débit atomique (RG-M4-03, CA-8, réutilise le patron `DecrementStockHandler` de M2/M1)

`PorteMonnaieVirtuelAdapter::debiter()` exécute un **UPDATE conditionnel** sur `crm_porte_monnaie_virtuel`,
même principe que le décrément de stock M1 (`UPDATE ... SET disponibilite = disponibilite - :q WHERE
disponibilite >= :q`) :

```sql
UPDATE crm_porte_monnaie_virtuel
SET solde = solde - :m
WHERE client_id = UNHEX(:id) AND statut = 'actif' AND solde >= :m
```

0 ligne affectée ⇒ refus (solde insuffisant **ou** PMV expiré/inexistant) ⇒ `ResultatDebitPmv{reussi:false}`
⇒ `PaiementHandler` renvoie 422 sans créer de `Paiement` (cohérent avec CA-8 : un paiement partiel PMV +
complément reste possible, c'est `PaiementHandler` qui gère le montant scindé, le port ne fait que débiter
le montant demandé). Un `MouvementPmv(type=debit_vente, refVenteM2=venteId)` est journalisé **dans la même
transaction** que l'UPDATE (append-only, cf. §3).

### 2.3 Points d'intégration côté M2 (hors périmètre strict de ce dépôt de specs, décrits pour l'implémentation)

Ces modifications touchent `app/src/Vente/*` (déjà livré en L2) et doivent être réalisées **lors de
l'implémentation de L5**, pas dans ce lot de spécification :

- `PaiementHandler::encaisser()` — quand `code === 'pmv'`, appeler `PorteMonnaieVirtuelInterface::debiter()`
  avant de créer le `Paiement` ; refus → `UnprocessableEntityHttpException` (comme les autres gardes du
  handler). Le **solde disponible** doit aussi filtrer la liste des moyens proposés en caisse : `MoyenPaiement`
  gagne un champ `necessiteSolde: bool` (vrai pour `pmv`) exploité par une future UI, et l'appel à
  `PorteMonnaieVirtuelInterface::solde()` permet à `ReferentielReglementInterface`-consommateur de **masquer**
  le PMV expiré (CA-10) — orchestré côté State Processor de vente (`PaiementProcessor`), pas dans le
  référentiel générique.
- `ContrePassationHandler::annuler()`/`rembourser()` — pour la part payée en PMV d'une vente annulée/remboursée
  (recherche des `Paiement.moyenCode === 'pmv'` de la vente), appeler `PorteMonnaieVirtuelInterface::crediter()`
  avec `motif = 'remboursement_vente'` (RG-M4-03, CA-9) → génère un `MouvementPmv(type=remboursement_vente)`.
- **Recherche « n° de carte »** (CA-1, fiche 360 §2.4) : nécessite symétriquement un petit ajout côté M2 —
  port `App\Vente\Port\RechercheSupportInterface::clientPourSupport(string $identifiant): ?Uuid` (interroge
  `BilletSupport`/`LigneVente`), consommé par le `Crm\State\RechercheClientProvider` de ce plan. Même patron
  que les 3 ports existants ; **à ajouter en même temps** que `PorteMonnaieVirtuelInterface`.

### 2.4 Fiche client 360° — agrégation cross-modules

`App\Crm\State\FicheClient360Provider` (State Provider API Platform, `GET /clients/{id}/fiche-360`) agrège :
Client + Famille/Beneficiaire (local) + PMV/mouvements (local) + Consentements (local) + **historique
d'achats** via un nouveau port en lecture `App\Vente\Port\HistoriqueVenteInterface::pourClients(array $uuids):
iterable<ResumeVente>` (M2 → M4, symétrique à `ClientM4Interface`), appelé avec **la chaîne complète des
identifiants fusionnés** (`Client.fusionneDans` remonté + `JournalFusion.fichesSources`, §4) pour ne perdre
aucun historique après fusion. CA-4 (tous les blocs sur un seul écran) est satisfait par cette agrégation
unique côté API ; l'UI n'a qu'un seul appel à faire.

---

## 3. RGPD

### 3.1 Consentement par canal (RG-M4-07, CA-16, CA-18)

- **Append-only** : chaque action (accord initial, révocation, renouvellement) **insère** une nouvelle ligne
  `Consentement` ; aucune ligne existante n'est modifiée (garde ORM `preUpdate`, §3.4). L'**état courant**
  d'un canal = la ligne la plus récente (`dateRecueil` max) pour `(client, canal)` — résolu par un
  `ConsentementResolver` (requête, pas de colonne dénormalisée pour éviter l'incohérence).
- Une communication n'est déclenchée (côté module marketing, hors périmètre) que si l'état courant du canal
  est `accorde` et `dateExpiration` non dépassée. CA-16 : un client sans consentement `accorde` sur `sms` est
  automatiquement exclu (le module consommateur interroge `ConsentementResolver`, filtre appliqué en amont
  de tout export — pas de filtre applicatif dans le module marketing lui-même, hors périmètre L5).
- **Mineurs (RG-M4-10)** : `recueilliParRepresentant=true` obligatoire si `Client.estMineur()` au moment du
  recueil (validation applicative, pas de contrainte SQL portant sur une donnée calculée).

### 3.2 Passage à la majorité (RG-M4-10, décision actée, CA-19/CA-20)

- Commande planifiée `App\Crm\Command\VerifierMajoriteCommand` (`crm:consentement:verifier-majorite`,
  exécution quotidienne — ⚠ ordonnanceur/cron non défini par le socle, cf. Risques) : sélectionne les
  `Client` dont `dateNaissance` correspond à un 18ᵉ anniversaire **atteint depuis la dernière exécution**,
  et dont au moins un `Consentement.etat=accorde` porte `recueilliParRepresentant=true`.
- Pour chaque client concerné, `RenouvellementMajoriteHandler` :
  1. **Insère** une nouvelle ligne `Consentement(etat=a_renouveler)` par canal concerné (append-only, §3.1) —
     CA-19.
  2. Émet un événement Symfony Messenger `App\Crm\Event\PassageMajoriteEvent` (canaux concernés, client) pour
     déclencher la relance de renouvellement — **envoi effectif hors périmètre L5** (module notifications non
     spécifié dans ce dépôt).
  3. Suspend les envois non essentiels : matérialisé par l'état `a_renouveler` lui-même (tout consommateur
     de `ConsentementResolver` doit traiter `a_renouveler` comme « non exploitable », cf. §3.1).
  4. **Journalise** la transition et sa cause dans `App\Audit\Entity\EntreeAudit` (socle, réutilisé —
     `action=passage_majorite`, `cible=Client`) — CA-20, pas de nouvelle entité pour ce seul besoin.
  5. Réévalue les autorisations `Beneficiaire` du client désormais majeur : retire `entree_seule` de son
     **propre** enregistrement (n'a plus besoin d'une autorisation d'entrée seule dérogatoire) ; les
     autorisations `recuperer_mineur` portées par **d'autres** membres de la famille référençant
     implicitement ce mineur (le modèle `autorisations` n'a pas de cible fine, cf. cas limite) sont
     **signalées pour revue manuelle** (flag dans l'événement Messenger) plutôt que révoquées
     automatiquement — ⚠ HYPOTHÈSE, cf. Risques (modèle d'autorisations trop grossier pour un retrait
     automatique sûr).

### 3.3 Droit à l'effacement / anonymisation (RG-M4-08/09, CA-17)

`App\Crm\Service\EffacementRgpdHandler::traiter(DemandeRGPD $demande, Utilisateur $administrateur)` :

1. Garde : `demande.statut` doit être `recue`/`en_cours` (une demande `realisee` est immuable, §3.4).
2. Selon `demande.type` :
   - `effacement` **ou** `anonymisation` (le cahier retient l'anonymisation dès qu'un historique doit être
     conservé — décision actée RG-M4-09) : purge des champs nominatifs de `Client` uniquement (`nom`,
     `prenom`, `raisonSociale`, `siret`, `email`, `telephone`, `adresse`, `civilite`, `dateNaissance` →
     remplacés par des valeurs neutres/`null`), `Client.statut = anonymise`.
   - **Aucune cascade** vers `PorteMonnaieVirtuel`, `MouvementPmv`, `Consentement`, `JournalFusion` : ces
     tables ne portent pas de PII (décision structurante §1) — leurs lignes (montants, dates, canaux) sont
     l'**historique agrégé** conservé pour la comptabilité/statistiques (CA-17), sans lien avec l'identité
     purgée.
   - **Aucun impact sur M2** : `Vente.client`/`LigneVente.beneficiaire` restent des UUID sans FK dure
     (rappel §0) — les ventes scellées NF525 ne référencent l'identité que par UUID logique, jamais par PII ;
     l'intégrité comptable (RG-M2-07) n'est donc jamais rompue par une anonymisation côté M4 (**pas de
     modification requise côté M2**, ce point était le principal risque d'articulation RGPD × NF525 et il est
     résolu par construction de M2, cf. spec-vente.md l.78).
3. `demande.statut = realisee`, `dateTraitement`, `traitePar` renseignés → verrouillage ORM (§3.4).

### 3.4 Conservation (RG-M4-08) & garde d'inaltérabilité

- `App\Crm\Command\AppliquerConservationCommand` (`crm:rgpd:appliquer-conservation`, planifiée) : pour chaque
  `RegleConservation`, sélectionne les données de la `categorieDonnee` dont l'ancienneté dépasse `dureeMois`
  et applique `actionEcheance` (`purge` ou `anonymisation`, réutilise `EffacementRgpdHandler` pour la seconde).
- `App\Crm\Ecriture\InalterabiliteCrmListener` (`preUpdate`/`preRemove`, même patron que
  `App\Vente\Nf525\InalterabiliteListener`) interdit : modification/suppression de `Consentement` (append-only),
  de `MouvementPmv` (append-only), de `DemandeRGPD` à l'état `realisee` (verrouillée), et de `JournalFusion`
  hors des champs `statut`/`dateDefusion`/`defusionnePar` (transition de défusion, §4).

---

## 4. Fusion de fiches et de familles

`App\Crm\Service\FusionHandler` (RG-M4-06, US-L5-08, CA-13/14/15) :

### 4.1 Fusion de clients

1. **Prévisualisation** (`GET /crm/fusions/previsualiser?sources[]=...&maitre=...`) : pas d'écriture, renvoie
   les champs divergents entre fiches sources et une proposition d'arbitrage (`champsArbitres` prérempli).
2. **Validation** (`POST /crm/fusions`) : transaction unique —
   - Capture `snapshotAvant` (état complet des fiches sources : champs Client, solde/échéance PMV, lignes
     `Beneficiaire` actives) dans le `JournalFusion`, **avant** toute mutation — condition de la
     restauration « à l'identique » (CA-14).
   - Applique `champsArbitres` sur la fiche maître.
   - Chaque fiche source : `statut = fusionne`, `fusionneDans = maitre`.
   - **Cumul PMV** (si les deux ont un PMV) : `MouvementPmv(type=ajustement, montant=-soldeSource,
     motif="fusion vers <maitre>")` sur le PMV source (ramené à 0, jamais supprimé) et
     `MouvementPmv(type=ajustement, montant=+soldeSource, motif="fusion depuis <source>")` sur le PMV maître —
     toujours **append-only**, réversible par mouvements inverses à la défusion.
   - Historique d'achats : **aucune réécriture** de `Vente.client` côté M2 (pas de FK dure à corriger) ; la
     résolution passe par la chaîne `fusionneDans` côté fiche 360 (§2.4).
3. **Dédoublonnage** implicite : la fiche maître concentre tout ; les fiches sources restent consultables
   (statut `fusionne`) mais sorties des résultats de recherche standard (US-L5-01 filtre `statut≠fusionne`
   par défaut, sauf recherche explicite « candidates à fusion », CA-2).

### 4.2 Fusion de familles (décision actée, CA-15)

- **Choix manuel obligatoire** du payeur principal (cas limite « deux payeurs actifs » — jamais d'arbitrage
  automatique).
- PMV cumulés comme en 4.1 (au niveau des `Client` bénéficiaires communs).
- **Échéance retenue = la plus tardive des deux** (arbitrage point ouvert §10.2), calculée simplement par
  `max(dateEcheance)` sur les PMV fusionnés.
- **Déduplication des bénéficiaires** : pour un `Client` présent dans les deux familles, l'entrée
  `Beneficiaire` de la famille non-survivante est retirée (`dateRetrait` renseigné, jamais supprimée
  physiquement — réutilise le même mécanisme que le retrait ordinaire US-L5-03) plutôt qu'une nouvelle
  entrée n'est créée dans la famille survivante si elle existe déjà.

### 4.3 Défusion (RG-M4-06, CA-14)

`FusionHandler::defusionner(JournalFusion $journal, Utilisateur $administrateur)` :

- Garde : `journal.statut === active` uniquement — seule **la fusion la plus récente non défaite** peut être
  défaite (chaîne A+B+C : défaire la dernière ne restaure que l'état immédiatement antérieur, cas limite
  documenté, ⚠ HYPOTHÈSE).
- Restaure les fiches sources depuis `snapshotAvant` (champs Client, `statut=actif`, `fusionneDans=null`).
- Génère les mouvements PMV inverses des ajustements de fusion (restitue les soldes/échéances d'origine).
- Réactive les `Beneficiaire` retirés lors d'une fusion de famille (`dateRetrait=null`).
- `journal.statut = defusionnee`, `dateDefusion`, `defusionnePar`.

---

## 5. API (API Platform)

Toutes ressources : `#[ApiResource]`, `security` via le `PermissionVoter` du socle → `is_granted('PERM',
'crm.<action>')`. Lecture cadrée par une extension Doctrine dédiée `App\Crm\Doctrine\PerimetreCrmExtension`
(§6, cloisonnement **Groupe**, pas Établissement — spécificité de ce module). Les mutations avec invariant
fort (PMV, fusion, RGPD) sont des **State Processors** custom, pas du CRUD Doctrine brut (même choix que M2).

| Ressource | Opérations | `security:` | Groupes sérialisation | Filtres |
|---|---|---|---|---|
| **Client** | GET coll/item | `is_granted('PERM','crm.lire')` ou `crm.lire_soi` (soi-même, espace client M3) | `client:read`, `client:list` | `SearchFilter` (nom, prénom, email, téléphone partial), filtres `actif/inactif`, `avecPmv`, `mineur/majeur` (CA-1/CA-2) |
| | POST | `is_granted('PERM','crm.creer')` | `client:write` | — |
| | PATCH | `is_granted('PERM','crm.modifier')` ou `crm.modifier_soi` | `client:write` (respecte `champManuel`, §1.1) | — |
| | `GET /clients/{id}/fiche-360` | `is_granted('PERM','crm.lire')` ou soi | `fiche360:read` | **custom Provider** (§2.4), CA-4 |
| | `POST /clients/{id}/rattacher-support` | `is_granted('PERM','crm.creer')` | — | recherche par n° de carte (§2.3), CA-1 |
| **Famille** | GET coll/item, POST, PATCH | `crm.lire` / `crm.famille_gerer` | `famille:read/write` | `SearchFilter(libelle, payeurPrincipal)` |
| **Beneficiaire** | GET coll ; `POST /familles/{id}/beneficiaires` ; `POST /beneficiaires/{id}/retirer` | `crm.famille_gerer` | `beneficiaire:read/write` | **custom** — alerte si client déjà dans une famille active (CA-6) |
| **PorteMonnaieVirtuel** | `GET /clients/{id}/pmv` | `crm.pmv_lire` ou `crm.lire_soi`/`pmv_lire_soi` | `pmv:read` | — |
| | `POST /clients/{id}/pmv/recharger` | `crm.pmv_recharger` ou `crm.pmv_recharger_soi` | `pmv:mouvement` | **custom** — calcule échéance, gère PMV expiré selon `ParametrePmvEtablissement` (CA-7, CA-11) |
| | `GET /clients/{id}/pmv/mouvements` | `crm.pmv_lire` ou soi | `mouvement:read` | pagination, tri date |
| **MouvementPmv** *(interne, débit/crédit)* | — | — | — | jamais exposé en écriture directe API : uniquement via le port §2 (M2) ou `recharger` |
| **ParametrePmvEtablissement** | GET, PATCH | `crm.parametrer` | `parametre:read/write` | par établissement actif |
| **Consentement** | GET (historique+état courant) ; `POST /clients/{id}/consentements` (nouvelle ligne) | `crm.lire`/`crm.consentement_gerer_soi` | `consentement:read/write` | filtre `canal`, `etat` |
| **DemandeRGPD** | GET coll/item ; POST (`crm.rgpd_demander` ou soi) ; `POST /demandes-rgpd/{id}/traiter` (`crm.rgpd_gerer`) | selon opération | `rgpd:read/write` | — |
| **RegleConservation** | GET, POST, PATCH | `crm.parametrer` | `conservation:read/write` | — |
| **JournalFusion** | GET coll/item ; `POST /crm/fusions/previsualiser` ; `POST /crm/fusions` ; `POST /crm/fusions/{id}/defusionner` | `crm.fusionner` | `fusion:read` | append-only sauf défusion |

- **Groupes de sérialisation — données perso protégées** : `client:list` (recherche/liste) n'expose **pas**
  `adresse`/`dateNaissance` en clair (seulement nom/prénom/statut/indicateurs) ; `client:read` complet réservé
  à `crm.lire`/`crm.lire_soi` sur la fiche elle-même. `pmv:read`/`consentement:read` ne sont jamais inclus
  dans un export marketing sans passer par le filtre `ConsentementResolver` (§3.1). `rgpd:write` exclut tout
  champ administratif (`traitePar`) en écriture cliente (rempli serveur uniquement).

---

## 6. Sécurité & droits

- **Permissions requises (module `crm`)** : `crm.lire`, `crm.lire_soi`, `crm.creer`, `crm.modifier`,
  `crm.modifier_soi`, `crm.pmv_lire`, `crm.pmv_lire_soi`, `crm.pmv_recharger`, `crm.pmv_recharger_soi`,
  `crm.famille_gerer`, `crm.consentement_gerer_soi`, `crm.rgpd_demander`, `crm.rgpd_gerer`, `crm.fusionner`,
  `crm.parametrer`, `crm.exporter`, `crm.segment_gerer` *(réservée pour un lot ultérieur, non exploitée en
  L5, cf. §10)*.
- **Voter** : **aucun voter nouveau** — réutilise `PermissionVoter` du socle (RG-SOCLE-04). Les suffixes
  `_soi` (`lire_soi`, `pmv_recharger_soi`, `consentement_gerer_soi`) sont des **permissions distinctes**
  (pas une résolution spéciale dans le Voter) : leur `security:` combine `is_granted('PERM','crm.pmv_lire')
  or (is_granted('PERM','crm.pmv_lire_soi') and object.getClient() == user.getClientLie())` — nécessite un
  moyen de relier un `Utilisateur` (socle, back-office) à son `Client` (CRM, espace public M3) ; ⚠ HYPOTHÈSE
  **new** champ `Utilisateur.clientLie: ?Uuid` (ou table de correspondance dédiée) — **à confirmer avec M8**
  (l'espace client M3 n'est pas encore spécifié dans ce dépôt), cf. Risques.
- **Cadrage Groupe (spécifique à ce module)** — `App\Crm\Doctrine\PerimetreCrmExtension` (nouvelle extension,
  distincte de `PerimetreVenteExtension`/`PerimetreProduitExtension` qui filtrent par Établissement) : filtre
  `Client`/`Famille`/`RegleConservation` sur `EXISTS (affectation WHERE affectation.utilisateur = :user AND
  affectation.etablissement.region.groupe = client.groupe)` — un utilisateur voit un client dès qu'il a **une
  seule** affectation dans le groupe du client, quel que soit l'établissement précis (cohérent avec la portée
  Groupe du Client, §1). Les entités dépendantes (`PorteMonnaieVirtuel`, `Beneficiaire`, `Consentement`,
  `DemandeRGPD`, `JournalFusion`) sont filtrées par jointure vers `Client`/`Famille`. `ParametrePmvEtablissement`
  suit, lui, le patron `PerimetreVenteExtension` standard (Établissement direct).
- **Séparation des devoirs (spec §3)** : agent (`crm.lire/creer/modifier/pmv_recharger/famille_gerer`) ne peut
  ni fusionner (`crm.fusionner`) ni traiter une demande RGPD (`crm.rgpd_gerer`) ni paramétrer
  (`crm.parametrer`) — réservés à l'Administrateur. Le Comptable a un accès **lecture seule** PMV
  (`crm.pmv_lire`, `crm.exporter`), jamais d'écriture.

---

## 7. Migrations

- **Migration structurelle** `VersionM4_crm` : tables `crm_client`, `crm_famille`, `crm_beneficiaire`,
  `crm_porte_monnaie_virtuel`, `crm_mouvement_pmv`, `crm_parametre_pmv_etablissement`, `crm_consentement`,
  `crm_demande_rgpd`, `crm_regle_conservation`, `crm_journal_fusion`.
  - **Index/contraintes** : unique `crm_porte_monnaie_virtuel.client_id` ; unique
    `crm_parametre_pmv_etablissement.etablissement_id` ; check `solde ≥ 0` ; index `(client_id, canal,
    date_recueil)` sur `crm_consentement` (résolution état courant) ; index `(pmv_id, date_mouvement)` sur
    `crm_mouvement_pmv` ; FK vers `org_groupe`/`org_etablissement`/`sec_utilisateur` (socle) — **suppose les
    migrations socle L0 + M1 + M2 jouées d'abord**.
- **Migration de données** `VersionM4_permissions` : insère `Permission(module='crm', action ∈ {lire,
  lire_soi, creer, modifier, modifier_soi, pmv_lire, pmv_lire_soi, pmv_recharger, pmv_recharger_soi,
  famille_gerer, consentement_gerer_soi, rgpd_demander, rgpd_gerer, fusionner, parametrer, exporter,
  segment_gerer})`.
- **Migration de données** `VersionM4_parametres_defaut` : un `ParametrePmvEtablissement` par établissement
  existant (`rechargeExpireeAutorisee=false`, `regleEcheance={mode:duree_jours,valeur:365}`,
  `traitementSoldeResiduel=conserve`) — valeurs de repli explicites, à ajuster par établissement.
- Rejouables, versionnées Doctrine ; jamais de `schema:update --force` (constitution §7).

---

## 8. Tests (PHPUnit + ApiTestCase)

| Test | Type | Couvre |
|---|---|---|
| Recherche client (nom/email/tél/n° carte) tolérante casse/accents, < 2 s sur n° de carte, pagination | API | CA-1 |
| Filtres cumulés (actif/inactif, avecPmv, mineur/majeur) + compteur ; aucune fiche « fusionnée » masquée si recherche explicite | API | CA-2 |
| Enrichissement auto (vente M2 validée → historique + agrégats CA/dernière visite, horodaté, sans re-saisie) | API (via port `HistoriqueVenteInterface`) | CA-3, RG-M4-01 |
| Champ saisi manuellement jamais écrasé par l'auto-enrichissement (`champManuel`) | Unit | RG-M4-01 (⚠ RG-M4-11) |
| Fiche 360 : un seul appel agrège coordonnées/historique/PMV/famille/consentements | API | CA-4 |
| Abonnement réglé par le payeur apparaît sur la fiche du bénéficiaire, jamais du payeur | API (croisé M1/M2/M4) | CA-5, RG-M4-02 |
| Rattachement à une 2ᵉ famille active → alerte ; ajout/retrait tracé et réversible | API | CA-6, US-L5-03 |
| Recharge PMV : solde crédité, `MouvementPmv(recharge)` journalisé, nouvelle échéance calculée | API | CA-7, RG-M4-03 |
| Débit 15 € sur solde 10 € refusé ; paiement partiel 10 € PMV + 5 € autre moyen possible | API (`PaiementHandler` + port PMV) | CA-8, RG-M4-03 |
| Annulation vente payée en PMV → re-crédit intégral, `MouvementPmv(remboursement_vente)` traçable | API | CA-9, RG-M4-03 |
| PMV expiré absent des moyens de paiement proposés en caisse | API | CA-10, RG-M4-04 |
| Recharge PMV expiré : réactivation si paramètre `true` (nouvelle échéance), blocage motivé si `false`, journalisé dans les deux cas | API | CA-11, RG-M4-04, US-L5-06 |
| Expiration PMV : solde conservé/annulé/transformé en produit selon paramètre, `MouvementPmv(expiration)` daté/motivé/exportable, solde annulé reste visible | API + Unit (commande planifiée) | CA-12, US-L5-07 |
| Fusion clients : prévisualisation avant validation, historiques/PMV/consentements rattachés à la fiche maître | API | CA-13, RG-M4-06 |
| Défusion : restauration à l'identique (snapshot), y compris PMV et historiques | API | CA-14, RG-M4-06 |
| Fusion familles : payeur principal conservé, PMV cumulés, bénéficiaire commun dédupliqué | API | CA-15, RG-M4-06 |
| Client sans consentement `accorde` sur `sms` exclu d'une campagne/export ciblant ce canal | Unit (`ConsentementResolver`) | CA-16, RG-M4-07 |
| Demande d'effacement : PII supprimées, `statut=anonymise`, historique agrégé conservé, aucune régression M2 (vente scellée intacte) | API | CA-17, RG-M4-09 |
| Révocation consentement : `etat=refuse`, horodaté+source, historique conservé (append-only) | API | CA-18, RG-M4-07 |
| Passage majorité : consentement → `a_renouveler`, relance déclenchée, envois non essentiels suspendus | Unit (`RenouvellementMajoriteHandler`) + API | CA-19, RG-M4-10 |
| Passage majorité : changement journalisé (`EntreeAudit`) avec cause, autorisations parentales réévaluées/désactivées | Unit + API | CA-20, RG-M4-10 |
| Débit PMV atomique concurrent : deux débits simultanés sur solde tout juste suffisant → un seul réussit | API (concurrence) | RG-M4-03, §2.2 |
| Cloisonnement Groupe : utilisateur affecté à un seul établissement du groupe voit le client ; hors groupe → 403/absent | API | RG-SOCLE-05 (spécificité Groupe, §6) |
| Garde d'inaltérabilité : `MouvementPmv`/`Consentement`/`DemandeRGPD` réalisée/`JournalFusion` hors transition → exception ORM | Unit | §3.4 |

---

## 9. Tâches (voir tasks-crm.md)

T1 enums & schéma Client/Famille/Beneficiaire → T2 PMV/MouvementPmv/ParametrePmvEtablissement → T3 port
`PorteMonnaieVirtuelInterface` (côté Vente) + adaptateur (§2) → T4 intégration `PaiementHandler`/
`ContrePassationHandler` (M2) → T5 Consentement/RGPD (§3) → T6 fusion/défusion (§4) → T7 fiche 360 + port
`HistoriqueVenteInterface`/`RechercheSupportInterface` (M2) → T8 API + sérialisation + droits (§5/§6) →
T9 cloisonnement Groupe (`PerimetreCrmExtension`) → T10 migrations + permissions → T11 tests. (ordonnées,
cf. fichier tasks.)

---

## 10. Risques / à valider

1. **Anomalie de numérotation `RG-M4-14`** (citée par US-L5-02, inexistante dans le cahier détaillé qui ne
   définit que `RG-M4-01` à `RG-M4-10`) — la règle de priorité « saisie manuelle prévaut sur
   auto-enrichissement » est retenue et **provisoirement documentée comme `RG-M4-11`** ; à corriger dans le
   cahier détaillé source (source de vérité) plutôt que dans ce plan.
2. **Segments dynamiques, campagnes, fidélité/parrainage — hors périmètre L5.** Décrits dans le cahier
   détaillé (écran M4-04, RG-M4-05) mais **aucune US-L5 ne les couvre**. Ce plan porte uniquement la
   permission `crm.segment_gerer` (réservation de nom) et le filtre par consentement (`ConsentementResolver`,
   §3.1) déjà exigé par RG-M4-07 ; aucune entité `Segment`/`Campagne` n'est créée dans ce lot. Reporté à un
   lot ultérieur.
3. **Portée du Client = Groupe** (point ouvert §1) — arbitrage retenu par cohérence multi-sites, à
   **confirmer en atelier produit** ; si infirmé (portée Établissement), impact limité à
   `PerimetreCrmExtension` (remplacer le join Groupe par le join Établissement direct, patron déjà connu
   `PerimetreVenteExtension`) et au champ `Client.groupe` → `Client.etablissement`.
4. **Échéance PMV après fusion = la plus tardive** (point ouvert §2) — non tranché explicitement par le
   cahier détaillé, retenu par défaut métier raisonnable ; à confirmer.
5. **Articulation PMV ↔ mandat SEPA** (point ouvert §3) — le mandat SEPA reste porté par M1/M6, rattaché au
   payeur ; **aucune alimentation automatique du PMV par prélèvement** n'est prévue dans ce lot (aucune US-L5
   ne le demande) ; si un rechargement automatique récurrent est souhaité, il nécessitera une US/RG dédiée
   hors périmètre L5.
6. **Membre de famille sans compte propre** (bébé/accompagnant, point ouvert §4, cas limite spec §7) — non
   modélisé dans ce plan (pas de `Beneficiaire` sans `Client`) ; à trancher si le besoin se confirme
   (probablement un champ optionnel « membre déclaratif » sur `Famille`, hors périmètre CA testé).
7. **Nouveaux ports M2 requis mais non encore codés** (§2.1, §2.3) — `PorteMonnaieVirtuelInterface`,
   `RechercheSupportInterface`, `HistoriqueVenteInterface` doivent être ajoutés côté `App\Vente\Port` lors de
   l'implémentation de L5, alors même que M2 (L2) est déjà livré ; c'est un **retour en arrière contrôlé**
   sur un module clos, à coordonner (revue de non-régression M2 complète, cf. §8 tests réutilisés).
8. **Lien `Utilisateur` (socle) ↔ `Client` (CRM) pour les permissions `_soi`** (§6) — nécessaire à l'espace
   client M3 (non spécifié dans ce dépôt) ; le mécanisme exact (champ direct vs table de correspondance) est
   une **hypothèse à arbitrer avec M8/M3**.
9. **Modèle d'autorisations `Beneficiaire` trop grossier pour un retrait automatique fiable à la majorité**
   (§3.2 point 5) — `autorisations` est un simple set sans cible ; le retrait de `recuperer_mineur` détenu
   par un tiers n'est **pas automatisé**, seulement signalé pour revue manuelle. À enrichir (cible nominative
   de l'autorisation) si l'automatisation complète est requise.
10. **Ordonnanceur/cron non défini par le socle** (§3.2, §3.4) — les commandes planifiées
    (`crm:consentement:verifier-majorite`, `crm:rgpd:appliquer-conservation`) supposent une infrastructure de
    tâches planifiées (cron système, Symfony Scheduler…) **non tranchée par L0** ; à préciser à
    l'intégration.
11. **RGPD × NF525 — risque a priori absent** (§3.3) : confirmé par construction (M2 ne stocke aucune PII,
    seulement des UUID logiques) — **pas d'action requise côté M2**, mais à **vérifier par un test croisé**
    explicite lors de l'implémentation (cf. §8, test CA-17) plutôt que supposé.
