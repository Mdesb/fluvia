# Plan technique — Verticale Musée (`Musée` / lot post-MVP, hors ordre L0→L7 — cahier « L16 »)

- **Spec source :** specs/musee/spec-musee.md
- **Stack :** Symfony 7 · API Platform · Doctrine/MariaDB
- **Couvre :** US-MUSEE-01 à 11 · RG-MUS-01 à 06 · CA-1 à CA-11 (⚠ US non encore validées/numérotées
  officiellement dans le backlog — spec §1, hérité tel quel, cf. Risque n°9)

> **Réutilisation socle L0 (à ne pas dupliquer)** — hiérarchie `App\Organisation\Entity\{Groupe,Region,
> Etablissement,Espace}` (RG-SOCLE-01) ; `PermissionVoter` (attribut `PERM`, sujet `"module.action"`,
> RG-SOCLE-04) ; `ContexteEtablissement` (RG-SOCLE-05) ; `Utilisateur` (RG-SOCLE-06) ;
> `App\Audit\Doctrine\AuditWriteSubscriber` append-only (RG-SOCLE-07) — **étendu** avec les entités
> sensibles Musée (T13).
>
> **Réutilisation M1 Offre (code réel, `app/src/Offre/Entity/*`)** — `Produit` (facettes, cycle de
> publication `RG-M1-09`, TVA `tauxTva`/onglet compta déjà présent), `Formule` (droit d'accès, RG-M1-03).
> **Aucun champ M1 dupliqué** : `Exposition`/`Audioguide`/`PassAnnuel` sont des entités **satellites**
> `App\Musee\Entity\*` en relation `OneToOne` vers `Produit`/`Formule` — même patron que
> `Formule`/`CarteMultiEntrees` déjà satellites de `Produit`, ou que `Poss` satellite d'`EspaceAcces` en
> L6 (`specs/L6-piscine/plan-piscine.md` §0/§1.1, lu en référence).
>
> **Réutilisation L3 Accès (code réel, `app/src/Acces/Entity/*`)** — `EspaceAcces` (seuil/mode/pré-alerte
> FMI), `JaugeFmi` (présence temps réel), `Support` (carte QR). Le sous-quota de salle **spécialise** le
> mécanisme FMI générique déjà livré (RG-ACC-04) exactement comme la POSS piscine (L6) : **aucun fichier
> `App\Acces\*` n'est modifié** par ce lot.
>
> **Réutilisation module Réservation (plan livré, code non encore construit)** —
> `specs/reservation/plan-reservation.md` définit désormais des entités réelles
> `App\Reservation\Entity\{Ressource,Creneau,Reservation,ParticipantReservation,ListeAttente,
> RegleAnnulation,FacturationNoShow}`. **Décision structurante n°1 (§0)** : ce plan **réconcilie**
> directement dessus les objets provisoires `CréneauExpo`/`ListeAttenteVisite` de `spec-musee.md` §5 —
> **il n'y a plus de spécialisation provisoire musée** de ces concepts. En contrepartie, **`App\Musee`
> dépend de la construction préalable du module `App\Reservation`** (non codé à ce jour) — cf. Risque n°1.
>
> **Réutilisation M2 Vente** (`App\Vente\Entity\Vente`), **M4/CRM** (`App\Crm\Entity\Beneficiaire`, code
> réel) et **M6 Compta** (TVA culturelle déjà portée par `Produit.tauxTva`, pas de mécanisme dupliqué).

---

## 0. Décisions structurantes (résumé)

1. **Réconciliation avec le module Réservation** (résout le GAP §2.3/§8 point 1 de `spec-musee.md`) —
   `CréneauExpo` → `App\Reservation\Entity\Creneau` (sur une `Ressource` de type `codeType='exposition'`
   représentant la « porte d'entrée » de l'exposition) ; `ListeAttenteVisite` → `App\Reservation\Entity\
   ListeAttente` générique. Le musée **n'ajoute aucune table de créneau/liste d'attente** ; il **crée des
   `Ressource`/`Creneau` typés** et **consomme** l'API générique de réservation.
2. **Granularité de décompte = 1 `Reservation` (module Réservation) = 1 visiteur/billet.** La jauge d'un
   créneau se lit `Creneau.capacite` vs `count(Reservation.statut=confirmee)` (patron déjà posé par
   `plan-reservation.md` §0 point 7). Pour rester cohérent à ce grain, un panier de N billets, un dossier
   groupe de 30 personnes ou une allocation OTA créent chacun **N `Reservation` individuelles** contre le
   même `Creneau`, plutôt qu'une réservation « groupée » à quantité — condition nécessaire pour que
   jauge (RG-MUS-01), contingent de gratuités (RG-MUS-03) et quota OTA (RG-MUS-04) se décomptent au
   **même grain** que la vente individuelle. Documenté comme convention par entité concernée (§1.4/1.5).
3. **Sous-quota de salle = réutilisation intégrale `EspaceAcces`/`JaugeFmi` L3**, sans aucune duplication
   de `seuil`/`mode`/`préAlerte` (même patron que `Poss` en Piscine). **Contrairement à la Piscine, aucun
   listener ne force le mode** : `EspaceAcces.modeSeuil` reste `blocage` **ou** `alerte`, au choix de
   l'établissement à la configuration de la salle — répond à l'arbitrage demandé « paramétrable » (voir
   Risque n°2, point réglementaire ERP non tranché par les sources).
4. **Connecteur OTA = port applicatif stub** (`ConnecteurOtaInterface` + `StubConnecteurOtaAdapter`, même
   patron que `App\Acces\Port\ProjectionDroitInterface`/`StubProjectionDroit`) — **aucune intégration
   technique par plateforme** (Tiqets, Weezevent…), conformément aux arbitrages de cadrage.
5. **Priorité 1ᵉʳ confirmé & récupération no-show OTA = délégués au moteur générique du module
   Réservation** (`RegleAnnulation.margePostCreneauMinutes`, transition `Reservation.statut →
   no_show_facture`, qui libère mécaniquement la jauge du `Creneau`). `ReservationOTA` n'ajoute **qu'une
   lecture sémantique OTA** (conflit/récupération) par-dessus la `Reservation` réelle — **aucun pipeline
   no-show parallèle** n'est créé côté Musée.
6. **`Guide` = `Utilisateur` socle affecté établissement** (hypothèse reprise de `spec-musee.md` §3, par
   analogie avec l'Encadrant MNS/BNSSA de Piscine et l'instructeur de Sport) — à confirmer si un profil
   RH distinct émerge du module Réservation (Risque n°6).
7. **`ParametreMuseeEtablissement`** centralise les valeurs non chiffrées par les sources (seuils de
   pastilles disponibilité, taux de remise bascule audioguide par défaut, délai d'option dossier
   groupe/scolaire) — même patron que `ParametrePiscineEtablissement` (L6), pour éviter toute valeur
   codée en dur (constitution §4 point 4).

---

## 1. Entités & schéma

Namespace : **`App\Musee\Entity\*`** (+ `App\Musee\Enum\*`, `App\Musee\Port\*`, `App\Musee\Service\*`).
`id` = UUID (`Symfony\Component\Uid\Uuid`, type Doctrine `uuid`). `declare(strict_types=1)` partout. Noms
métier en français. Toute entité racine porte un `ManyToOne` vers `Etablissement` (socle, RG-SOCLE-01),
cloisonnée par une extension Doctrine `PerimetreMuseeExtension` (même patron que
`PerimetreAccesExtension`/`PerimetreSportExtension`, code réel lu).

### 1.1 Paramètres établissement (transverse — décision n°7)

| Entité | Champ | Type Doctrine | Null | Contrainte | Notes |
|---|---|---|---|---|---|
| **ParametreMuseeEtablissement** (`musee_parametre_etablissement`) | id | uuid | non | PK | 1-1 par établissement |
| | etablissement | `OneToOne` → `Etablissement` | non | unique | — |
| | seuilPastilleTendu | smallint (0-100) | non, défaut **20** ⚠ non chiffré | `Assert\Range(0,100)` | % places restantes sous lequel un créneau passe `tendu` (§4.1) |
| | tauxRemiseAudioguideDefaut | decimal(5,2) | non, défaut **20.00** ⚠ non chiffré | `Assert\Range(0,100)` | valeur de départ, §4.4 |
| | delaiOptionDossierGroupeJours | smallint | non, défaut **15** ⚠ non chiffré | `Assert\Positive` | date d'option groupe/scolaire, §4.6 |
| | modeSousQuotaSalleDefaut | `string(8)` enum `ModeSeuil` (L3, réutilisé) | non, défaut `alerte` | — | valeur proposée à la création d'une `Salle`, **non imposée** (décision n°3) |

### 1.2 Salles, sous-quota & délestage (US-MUSEE-02, décision actée « Expo à forte affluence »)

| Entité | Champ | Type Doctrine | Null | Contrainte | Notes |
|---|---|---|---|---|---|
| **Salle** (`musee_salle`) | id, nom | uuid, string(120) | non | requis | — |
| | espace | `ManyToOne` → `Espace` (socle) | non | index | RG-SOCLE-01 |
| | espaceAcces | `OneToOne` → `App\Acces\Entity\EspaceAcces` (L3, **réutilisé, pas de copie**) | oui | unique si renseigné | présent **seulement** si la salle a un point de contrôle mobile dédié (condition d'un `SousQuotaSalle`) |
| | exposition | `ManyToOne` → `Exposition` | oui | — | salle rattachée ou non à une expo précise |
| | etablissement | `ManyToOne` → `Etablissement` | non | dénormalisé | — |
| **SousQuotaSalle** (`musee_sous_quota_salle`) | id | uuid | non | PK | décision actée §4.2 |
| | salle | `OneToOne` → `Salle` | non | unique — 1 `Salle` ↔ 0..1 `SousQuotaSalle` | exige `salle.espaceAcces` renseigné |
| | actif | bool | non, défaut true | — | désactivable sans supprimer la config |
| > Pas de colonne `seuil`/`mode`/`occupationCourante` dupliquée — lues via `salle.espaceAcces.{seuilFmi,modeSeuil,preAlertePct}` et `JaugeFmi.valeurCourante` (L3), exactement comme `Poss` en Piscine (`plan-piscine.md` §1.1). |
| **PolitiqueDelestage** (`musee_politique_delestage`) | id | uuid | non | PK | ⚠ HYPOTHÈSE, mécanique non détaillée par les sources (§4.2) |
| | sousQuotaSalle | `OneToOne` → `SousQuotaSalle` | non | unique | — |
| | mode | `string(24)` enum `ModeDelestage` {file_attente_sur_place, redirection_parcours, alerte_seule} | non, défaut `alerte_seule` | — | paramétrable par salle |
| | salleRedirection | `ManyToOne` → `Salle` | oui | requis si `mode=redirection_parcours` | — |
| | messageAgent | string(255) | oui | optionnel | consigne affichée au contrôle mobile |

> **Non-enforcement au point de contrôle** — comme `Poss.reservationsProtegees` en Piscine (§2.3 du plan
> L6), `PolitiqueDelestage` est une **information de supervision et une consigne** appliquée par l'agent
> mobile via le tableau de bord (`SalleEtatLive`, §2), **pas** une modification de
> `ValidationPassageHandler` (L3, inchangé). Si `salle.espaceAcces.modeSeuil = blocage`, le refus
> physique au scan est **déjà** couvert nativement par L3 (RG-ACC-04, comme la POSS piscine) ; si
> `modeSeuil = alerte`, l'entrée passe et l'agent applique la politique via consigne affichée.

### 1.3 Exposition & Audioguide (satellites Produit M1, US-MUSEE-09/11, RG-MUS-06)

| Entité | Champ | Type Doctrine | Null | Contrainte | Notes |
|---|---|---|---|---|---|
| **Exposition** (`musee_exposition`) | id | uuid | non | PK | RG-M1-01/02, RG-MUS-06 |
| | produit | `OneToOne` → `App\Offre\Entity\Produit` (**réutilisé, non dupliqué** : libellé i18n, TVA `tauxTva`, cycle de publication `RG-M1-09`, canaux) | non | unique | — |
| | dateDebut, dateFin | `date_immutable` | non | `dateDebut < dateFin` | RG-MUS-06 |
| | aJauge | bool | non, défaut false | — | pilote l'obligation de créneau (RG-MUS-01) |
| | jaugeGlobale | int > 0 | oui | requis si `aJauge` | informatif (la jauge réelle vendable est portée par les `Creneau` de `ressourceEntree`) |
| | ressourceEntree | `ManyToOne` → `App\Reservation\Entity\Ressource` | oui | requis si `aJauge` | `Ressource(codeType='exposition', partageable=false)` — porte les `Creneau`/tranches (RG-MUS-01, décision n°1) |
| | etablissement | `ManyToOne` → `Etablissement` | non | dénormalisé | — |
| **Audioguide** (`musee_audioguide`) | id | uuid | non | PK | US-MUSEE-11 |
| | produit | `OneToOne` → `Produit` (**réutilisé** : produit boutique standard, tarif, TVA) | non | unique | — |
| | langues | `json` (`list<string>`, codes ISO) | non | `≥ 1` élément | ⚠ HYPOTHÈSE catalogue non chiffré (§4.11), paramétrable établissement |
| | etablissement | `ManyToOne` → `Etablissement` | non | dénormalisé | — |

> **Garde fermeture hors période (CA-9, RG-MUS-06)** — `FenetreExpositionGuard` (service, appelé par le
> `CreerCreneauProcessor` du module Réservation via un événement/validateur enregistré côté Musée sur
> `Ressource.codeType='exposition'`) refuse la création/l'exposition à la vente d'un `Creneau` dont
> `debut::date` est hors `[Exposition.dateDebut, dateFin]`. **Aucune modification de
> `App\Reservation\*`** : le contrôle est un validateur additif porté par le type de ressource, dans
> l'esprit de `TypeRessourceValide` (module Réservation, décision structurante n°1 de son propre plan).

### 1.4 Guides & visites guidées (US-MUSEE-03/04, RG-MUS-02, décision actée « Guide indisponible »)

| Entité | Champ | Type Doctrine | Null | Contrainte | Notes |
|---|---|---|---|---|---|
| **Guide** (`musee_guide`) | id | uuid | non | PK | US-MUSEE-03 ; décision n°6 |
| | utilisateur | `ManyToOne` → `App\Securite\Entity\Utilisateur` (socle) | non | index | ⚠ HYPOTHÈSE, voir §0 décision n°6 |
| | etablissement | `ManyToOne` → `Etablissement` | non | dénormalisé | — |
| **QualificationLangueGuide** (`musee_qualification_langue_guide`) | id | uuid | non | PK | US-MUSEE-03/04 |
| | guide | `ManyToOne` → `Guide` | non | **unique `(guide, langue)`** | — |
| | langue | `string(8)` (code ISO) | non | requis | filtre agenda (§4.3) |
| **VisiteGuidee** (`musee_visite_guidee`) | id | uuid | non | PK | RG-MUS-02 |
| | theme | string(120) | non | requis | cahier §2 |
| | langue | `string(8)` | non | requis | doit matcher une `QualificationLangueGuide` du guide affecté |
| | guide | `ManyToOne` → `Guide` | oui | requis pour passer `statut=confirmee` | RG-MUS-02 |
| | creneauVisite | `OneToOne` → `App\Reservation\Entity\Creneau` | non | unique | `Creneau` d'une `Ressource(codeType='visite_guidee', capacitePropre=capacité)` **dédiée à cette visite** — capacité **indépendante** de la jauge d'entrée (RG-MUS-02) ; les réservations de participants sont des `Reservation` (module Réservation) standards contre ce `Creneau`, ce qui **réutilise gratuitement** compteur de places, annulation et **liste d'attente générique** (§4.4) |
| | creneauEntree | `ManyToOne` → `App\Reservation\Entity\Creneau` | oui | requis pour `statut=confirmee` si l'exposition `aJauge` | tranche d'entrée de l'exposition sur laquelle la visite est adossée (§4.3, « rattache la visite à un créneau d'entrée ») — décompte **séparé** de `creneauVisite` (deux quotas coexistent) |
| | pointRDV | string(255) | non | requis | cahier §2 |
| | statut | `string(10)` enum `StatutVisiteGuidee` {planifiee, confirmee, annulee} | non, défaut `planifiee` | — | — |
| | etablissement | `ManyToOne` → `Etablissement` | non | dénormalisé | — |
| **BasculeAudioguide** (`musee_bascule_audioguide`) | id | uuid | non | PK | US-MUSEE-04/11 |
| | visiteGuideeRefInitiale | `ManyToOne` → `VisiteGuidee` | oui | optionnel (contexte de la demande initiale) | — |
| | audioguide | `ManyToOne` → `Audioguide` | non | requis | produit de repli |
| | beneficiaire | `ManyToOne` → `App\Crm\Entity\Beneficiaire` | non | requis | client concerné |
| | tauxRemise | decimal(5,2) | non | `0-100`, défaut = `ParametreMuseeEtablissement.tauxRemiseAudioguideDefaut` | ⚠ HYPOTHÈSE non chiffré (§4.4) |
| | creeLe | `datetime_immutable` | non | — | — |
| | etablissement | `ManyToOne` → `Etablissement` | non | dénormalisé | — |

> **Garde exclusivité guide** — `GuideDisponibiliteGuard::verifier(VisiteGuidee)` (service, appelé à la
> création/`PATCH` d'une `VisiteGuidee`) : refuse (422) si le `guide` affecté a déjà une autre
> `VisiteGuidee.statut ∈ {planifiee, confirmee}` dont le `creneauVisite` **chevauche** temporellement
> celui de la nouvelle visite — garde applicative, même patron que `AffectationLigneGuard` (Piscine).
> **Confirmation** — `ConfirmerVisiteGuideeHandler::confirmer(VisiteGuidee)` : exige `guide !== null`
> **et** une `QualificationLangueGuide(guide, langue=visite.langue)` valide, sinon refus explicite « aucun
> guide qualifié dans cette langue » (CA-4, déclenche la proposition liste d'attente/bascule côté API,
> §2) ; sinon `statut = confirmee`.
> **Liste d'attente / bascule (CA-4)** — si aucun guide qualifié disponible : le client choisit (a)
> `POST /reservation/creneaux/{creneauVisite.id}/liste-attente` (endpoint générique du module Réservation,
> **réutilisé tel quel**, RG-MUS-04.4/décision actée), ou (b) `POST /musee/bascules-audioguide` qui crée
> une `BasculeAudioguide`. **Aucune des deux n'est un objet Musée redéfinissant la file d'attente.**

### 1.5 Gratuités & groupes/scolaires (US-MUSEE-05/06, RG-MUS-03, décision actée « Gratuités scolaires »)

| Entité | Champ | Type Doctrine | Null | Contrainte | Notes |
|---|---|---|---|---|---|
| **ContingentGratuite** (`musee_contingent_gratuite`) | id | uuid | non | PK | décision actée §4.5 |
| | perimetre | `string(11)` enum `PerimetreContingent` {exposition, creneau} | non | requis | paramétrable par établissement |
| | exposition | `ManyToOne` → `Exposition` | oui | requis si `perimetre=exposition` | — |
| | creneau | `ManyToOne` → `App\Reservation\Entity\Creneau` | oui | requis si `perimetre=creneau` | — |
| | quotaGratuitesDedie | int ≥ 0 | non | requis | — |
| | quotaConsomme | int ≥ 0 | non, défaut 0 | `quotaConsomme ≤ quotaGratuitesDedie` — décrément par `UPDATE` conditionnel atomique (pattern `JaugeFmi`) | RG-MUS-03 |
| | etablissement | `ManyToOne` → `Etablissement` | non | dénormalisé | — |
| **DossierGroupeScolaire** (`musee_dossier_groupe_scolaire`) | id | uuid | non | PK | US-MUSEE-06, RG-MUS-03 |
| | etablissementScolaire | string(255) | non | requis | nom de la structure demandeuse (≠ `Etablissement` socle) |
| | effectif, accompagnateurs | int > 0, int ≥ 0 | non | requis | — |
| | creneauEntree | `ManyToOne` → `App\Reservation\Entity\Creneau` | non | requis | — |
| | dateOption | `date_immutable` | oui | défaut = aujourd'hui + `ParametreMuseeEtablissement.delaiOptionDossierGroupeJours` | réservation provisoire, ⚠ durée non chiffrée (§4.6) |
| | statutPaiement | `string(18)` enum `StatutPaiementDossier` {en_option, bon_commande_emis, mandat_emis, paye} | non, défaut `en_option` | ⚠ HYPOTHÈSE, §4.6 | paiement différé |
| | venteRattachee | `ManyToOne` → `App\Vente\Entity\Vente` | oui | requis si `statutPaiement=paye` | encaissement effectif M2 |
| | guidesAffectes | `ManyToMany` → `Guide` | — | table `musee_dossier_guide` | coordination (cahier §2) |
| | etablissement | `ManyToOne` → `Etablissement` | non | dénormalisé | — |
| **Gratuite** (`musee_gratuite`) | id | uuid | non | PK | RG-MUS-03 |
| | dossier | `ManyToOne` → `DossierGroupeScolaire` | non | index | — |
| | motif | `string(16)` enum `MotifGratuite` {eleve, accompagnateur} | non | requis | — |
| | contingent | `ManyToOne` → `ContingentGratuite` | non | requis | — |
| | reservationRattachee | `OneToOne` → `App\Reservation\Entity\Reservation` | non | unique | `Reservation(creneau=dossier.creneauEntree, modeDecompte='gratuit')` créée à l'octroi — **1 gratuité = 1 `Reservation`** (décision n°2), décompte simultané de la jauge du créneau **et** du contingent |

> **Décompte du dossier (décision n°2)** — À la confirmation d'un `DossierGroupeScolaire`, le service
> `ConfirmerDossierGroupeHandler` crée **`effectif` `Reservation`** contre `creneauEntree` : `effectif −
> Σ(gratuités accordées)` en `modeDecompte='vente_unite'` (facturées différé — aucun `Vente` créé tant
> que `statutPaiement ≠ paye`) et une `Reservation` + `Gratuite` par gratuité accordée, via
> `AccorderGratuiteHandler` (refuse si `contingent.quotaConsomme = quotaGratuitesDedie`, **même si** la
> jauge du créneau reste disponible — refus explicite retenu, CA-5, §4.5). Ce grain garantit que jauge et
> contingent restent cohérents avec la vente individuelle, sans sur-modéliser un mécanisme de « réservation
> groupée à quantité » absent du module Réservation.

### 1.6 Distribution OTA (US-MUSEE-07/08, RG-MUS-04, décision actée « Sur-vente OTA »)

| Entité | Champ | Type Doctrine | Null | Contrainte | Notes |
|---|---|---|---|---|---|
| **PartenaireOTA** (`musee_partenaire_ota`) | id, nom | uuid, string(120) | non | requis | RG-MUS-04 |
| | tarifNet | decimal(10,2) | non | ≥ 0 | cahier §2 |
| | commission | decimal(5,2) | non | ≥ 0 (%) | — |
| | codeConnecteur | string(60) | oui | optionnel | identifiant technique du port stub (§0 décision n°4) |
| | etablissement | `ManyToOne` → `Etablissement` | non | dénormalisé | — |
| **AllocationQuotaOTA** (`musee_allocation_quota_ota`) | id | uuid | non | PK | RG-MUS-04 |
| | partenaire | `ManyToOne` → `PartenaireOTA` | non | **unique `(partenaire, creneau)`** | — |
| | creneau | `ManyToOne` → `App\Reservation\Entity\Creneau` | non | — | **sous-ensemble** du quota du créneau (§4.7), pas un stock séparé |
| | quotaAlloue | int ≥ 0 | non | requis | — |
| | quotaConsomme | int ≥ 0 | non, défaut 0 | `quotaConsomme ≤ quotaAlloue`, décrément atomique | anti sur-vente (RG-MUS-04) |
| **ReservationOTA** (`musee_reservation_ota`) | id | uuid | non | PK | US-MUSEE-08 |
| | allocation | `ManyToOne` → `AllocationQuotaOTA` | non | index | — |
| | reservationRattachee | `OneToOne` → `App\Reservation\Entity\Reservation` | non | unique | `Reservation(creneau=allocation.creneau, modeDecompte='vente_unite')` — **1 vente OTA = 1 `Reservation`** (décision n°2), décrémente le **même inventaire réel** que la vente directe (RG-MUS-04) |
| | horodatageConfirmation | `datetime_immutable` | non | dénormalisé de `reservationRattachee.dateCreation` | clé de priorité 1ᵉʳ confirmé (§4.8), lecture rapide sans jointure |
| | statutOta | `string(20)` enum `StatutReservationOTA` {confirmee, refusee_conflit, recuperee_no_show} | non, défaut `confirmee` | sémantique **OTA** additive — ne duplique pas `Reservation.statut` (générique : confirmee/…/no_show_facture) | §4.8 |
| **Reversement** (`musee_reversement`) | id | uuid | non | PK | RG-MUS-04 |
| | partenaire | `ManyToOne` → `PartenaireOTA` | non | index | — |
| | periodeDebut, periodeFin | `date_immutable` | non | requis | — |
| | montant | decimal(10,2) | non | ≥ 0 | tarif net + commission, calculé sur les `ReservationOTA.statutOta=confirmee` de la période |
| | statut | `string(10)` enum `StatutReversement` {a_verser, verse} | non, défaut `a_verser` | — |
| | etablissement | `ManyToOne` → `Etablissement` | non | dénormalisé | — |

> **Sur-vente & no-show (décision n°5)** — Aucun pipeline no-show musée : `AllocationQuotaOTA` est
> alimentée par une `RegleAnnulation` (module Réservation) configurée sur la `Ressource(codeType=
> 'exposition')`, `margePostCreneauMinutes` pilotant la bascule auto en no-show. Un
> `EventListener\RecupererQuotaOtaNoShowListener` (`App\Musee`, écoute la transition
> `Reservation.statut → no_show_facture` du module Réservation, **sans modifier** ce module) : si la
> `Reservation` correspond à une `ReservationOTA`, décrémente `AllocationQuotaOTA.quotaConsomme` et passe
> `statutOta = recuperee_no_show`, remettant la place à disposition (vente directe ou nouvelle allocation
> OTA), conformément à CA-8. La **priorité au 1ᵉʳ confirmé** en cas de conflit est un service
> `PrioriteOtaResolver::arbitrer(candidats: ReservationOTA[])` qui trie par
> `horodatageConfirmation` croissant et marque les suivants `statutOta = refusee_conflit` (⚠ pas de
> rebascule automatique sur un autre créneau, non tranché par la décision actée — §4.8 spec).

### 1.7 Pass annuel (US-MUSEE-10, RG-MUS-05)

| Entité | Champ | Type Doctrine | Null | Contrainte | Notes |
|---|---|---|---|---|---|
| **PassAnnuel** (`musee_pass_annuel`) | id | uuid | non | PK | RG-MUS-05 |
| | formule | `OneToOne` → `App\Offre\Entity\Formule` (**réutilisée** : droitAcces illimité, renouvellement, RG-M1-03) | non | unique | — |
| | adherent | `ManyToOne` → `App\Crm\Entity\Beneficiaire` | non | requis, nominatif | RG-M4-02 |
| | echeance | `date_immutable` | non | requis | RG-MUS-05 |
| | avantages | `json` (`list<string>` ⊂ {coupe_file, tarif_preferentiel}) | non, défaut `[]` | — | cahier §2 |
| | support | `OneToOne` → `App\Acces\Entity\Support` (L3, **réutilisé**, `type` QR/wallet) | non | unique | carte de membre nominative |
| | etablissement | `ManyToOne` → `Etablissement` | non | dénormalisé | — |

> **CA-10** — L'accès à la collection permanente (expositions `aJauge=false`) ne requiert **aucune**
> action Musée supplémentaire : c'est le droit d'accès illimité de la `Formule` réutilisée qui s'applique
> (projection L3 existante, hors périmètre de ce lot). Pour toute exposition `aJauge=true`, le porteur
> **doit** réserver un `Creneau` comme tout client (RG-MUS-01, décision n°1) : la `Reservation` créée a
> `modeDecompte='gratuit'` (tarif nul pour le porteur d'un pass valide), résolu par un service de
> tarification `TarificationPassAnnuelHandler` appelé côté M2/Réservation à l'encaissement — **aucune
> exception au parcours de créneau**, cohérence stricte avec RG-MUS-05 (« reste soumis au choix d'un
> créneau »).

### 1.8 Port OTA (décision n°4)

`App\Musee\Port\ConnecteurOtaInterface` — `notifierAllocation(AllocationQuotaOTA): void`,
`notifierReversement(Reversement): void`, `notifierNoShowRecupere(ReservationOTA): void`. Implémenté par
`App\Musee\Adapter\StubConnecteurOtaAdapter` (journalise, ne contacte aucune plateforme réelle) — même
patron que `App\Acces\Port\ProjectionDroitInterface`/`StubProjectionDroit` (code réel lu).

**Enums (`App\Musee\Enum\*`)** : `ModeDelestage`, `StatutVisiteGuidee`, `PerimetreContingent`,
`MotifGratuite`, `StatutPaiementDossier`, `StatutReservationOTA`, `StatutReversement`.

---

## 2. API (API Platform)

Toutes ressources : `#[ApiResource]`, `security:` via `is_granted('PERM', 'musee.<action>')` (module
`musee`) ou permissions réutilisées `offre.*`/`vente.*`/`acces.*`/`reservation.*`/`crm.*` quand l'action
relève de ces modules (spec §3, Acteurs & droits). Cadrage établissement : `PerimetreMuseeExtension`
(RG-SOCLE-05).

| Ressource | Opérations | `security:` | Groupes | Filtres/Notes |
|---|---|---|---|---|
| `ParametreMuseeEtablissement` | GET item ; PATCH | `musee.lire` / `musee.gerer` | `param:read/write` | 1-1 établissement |
| `Salle` | GET coll/item ; POST ; PATCH | `musee.lire` / `musee.configurer` | `salle:read/write` | — |
| `SousQuotaSalle` | GET coll/item ; POST ; PATCH | `musee.lire` / `musee.configurer` | `sousquota:read/write` | — |
| `PolitiqueDelestage` | GET item ; POST ; PATCH | `musee.lire` / `musee.configurer` | `delestage:read/write` | — |
| `SalleEtatLive` | `GET /musee/salles/{id}/etat` (non-Doctrine, custom provider) | `musee.superviser_salle` / `acces.superviser` | `salle_live:read` | compose `JaugeFmi` (L3) + `PolitiqueDelestage` — dashboard agent mobile (CA-2) |
| `Exposition` | GET coll/item ; POST ; PATCH | `musee.lire` / `musee.configurer` **et** `offre.creer`/`offre.modifier` (création du `Produit` sous-jacent) | `expo:read/write` | filtre `aJauge`, `dateDebut`/`dateFin` (range) |
| `Audioguide` | GET coll/item ; POST ; PATCH | `musee.lire` / `musee.configurer` | `audioguide:read/write` | — |
| `Guide` | GET coll/item ; POST | `musee.lire` / `musee.gerer` | `guide:read/write` | — |
| `QualificationLangueGuide` | GET coll ; POST ; DELETE | `musee.lire` / `musee.gerer` | `qualif:read/write` | référentiel qualifications (Administrateur) |
| `VisiteGuidee` | GET coll/item ; POST ; PATCH | `musee.lire` / `musee.gerer_visite` | `visite:read/write` | filtre `langue`, `guide`, `creneauVisite.debut` (range) — POST/PATCH déclenchent `GuideDisponibiliteGuard` |
| | `POST /musee/visites-guidees/{id}/confirmer` | `musee.gerer_visite` | — | custom — `ConfirmerVisiteGuideeHandler` (CA-3/CA-4) |
| `BasculeAudioguide` | GET coll ; `POST /musee/bascules-audioguide` | lire → `musee.lire` ; créer → `musee.gerer_visite` ou `reservation.reserver_soi` (client) | `bascule:read/write` | CA-4/CA-11 |
| `ContingentGratuite` | GET coll/item ; POST ; PATCH | `musee.lire` / `musee.configurer` | `contingent:read/write` | — |
| `DossierGroupeScolaire` | GET coll/item ; POST ; PATCH | `musee.lire` / `musee.gerer_dossier_groupe` | `dossier:read/write` | filtre `statutPaiement`, `creneauEntree` |
| | `POST /musee/dossiers-groupe/{id}/confirmer` | `musee.gerer_dossier_groupe` | — | custom — `ConfirmerDossierGroupeHandler` (génère les `Reservation`/`Gratuite`, CA-5/CA-6) |
| `Gratuite` | GET coll | `musee.lire` | `gratuite:read` | lecture seule, dérivée de la confirmation dossier |
| `PartenaireOTA` | GET coll/item ; POST ; PATCH | `musee.lire` / `musee.gerer_ota` | `partenaire:read/write` | — |
| `AllocationQuotaOTA` | GET coll/item ; POST ; PATCH | `musee.lire` / `musee.gerer_ota` | `allocation:read/write` | — |
| `ReservationOTA` | GET coll/item ; `POST /musee/reservations-ota` (créée par le port stub à réception d'une vente OTA simulée) | `musee.lire` / `musee.gerer_ota` | `resa_ota:read` | CA-7/CA-8 |
| `Reversement` | GET coll/item ; `POST /musee/reversements/{id}/marquer-verse` | `musee.lire` / `musee.gerer_ota` | `reversement:read/write` | CA-7 |
| `PassAnnuel` | GET coll/item ; POST ; PATCH | `musee.lire` / `musee.gerer_pass` | `pass:read/write` | POST déclenche création `Formule`+`Support` (réutilisés) |

- **Groupes de sérialisation** : pattern read/write par ressource, comme L3/L6. `DossierGroupeScolaire`
  n'expose `venteRattachee` qu'en lecture (champ système, renseigné par l'encaissement M2).
- **Custom vs CRUD** : confirmation de visite, confirmation de dossier groupe, bascule audioguide et
  marquage de reversement sont des **opérations métier** (State Processors → handlers testables), pas du
  CRUD Doctrine brut — même logique que L3/L6.
- **Endpoints génériques réutilisés sans ressource Musée dédiée** : sélection/achat de créneau
  (`POST /reservation/reservations`), liste d'attente (`POST /reservation/creneaux/{id}/liste-attente`),
  annulation (`POST /reservation/reservations/{id}/annuler`) — module Réservation, non redéfinis.

---

## 3. Sécurité & droits

Permissions `musee.*` (couple `module × action`, RG-SOCLE-02/03/04, portées établissement/espace) —
dérivées du tableau Acteurs & droits de `spec-musee.md` §3 (⚠ HYPOTHÈSE de nommage non littérale dans les
sources, à figer avec M8, même réserve que `piscine.*`/`sport.*`) :

| Permission | Rôle typique | Usage |
|---|---|---|
| `musee.lire` | Tous rôles Musée + lecture seule | lecture des ressources `musee.*` de l'établissement |
| `musee.configurer` | Gestionnaire d'offre culturelle | salles, sous-quotas, politique de délestage, expositions (avec `offre.*`), audioguides, contingents de gratuités |
| `musee.superviser_salle` | Agent de contrôle mobile | `SalleEtatLive`, application de la politique de délestage |
| `musee.gerer_visite` | Coordinateur de visites guidées | planification/confirmation visite, affectation guide, bascule audioguide côté staff |
| `musee.gerer_dossier_groupe` | Agent d'accueil / coordinateur | dossier groupe/scolaire, gratuités, paiement différé |
| `musee.gerer_pass` | Agent d'accueil / caisse | adhésion/renouvellement pass annuel |
| `musee.gerer_ota` | Gestionnaire de distribution OTA | partenaires, allocations, consultation reversements |
| `musee.gerer` | Administrateur | surensemble (référentiel qualifications guides, forçage) |

**Permissions réutilisées (non redéfinies)** — `offre.creer`/`offre.modifier`/`offre.publier` (Produit
sous-jacent Exposition/Audioguide/Formule) ; `vente.creer`/`vente.encaisser` (M2) ;
`acces.controler`/`acces.superviser` (L3, scan salle/dashboard) ; `reservation.reserver`/
`reserver_soi`/`annuler`/`annuler_soi`/`lire`/`lire_soi`/`gerer_creneau`/`gerer_ressource`/
`parametrer_annulation` (module Réservation) ; `crm.lire` (fiche adhérent pass annuel) ;
`securite.gerer` (délégation, socle).

**Voters** : aucun voter nouveau — réutilise `PermissionVoter` du socle. Le voter
`ReservationSoiVoter` (module Réservation) couvre déjà le cas « client voit ses propres réservations »
pour les billets/visites/gratuités Musée, sans duplication.

---

## 4. Migrations

- **Migration structurelle** `VersionMusee_structure` : tables `musee_parametre_etablissement`,
  `musee_salle`, `musee_sous_quota_salle`, `musee_politique_delestage`, `musee_exposition`,
  `musee_audioguide`, `musee_guide`, `musee_qualification_langue_guide`, `musee_visite_guidee`,
  `musee_bascule_audioguide`, `musee_contingent_gratuite`, `musee_dossier_groupe_scolaire`,
  `musee_dossier_guide` (jointure), `musee_gratuite`, `musee_partenaire_ota`,
  `musee_allocation_quota_ota`, `musee_reservation_ota`, `musee_reversement`, `musee_pass_annuel`.
  - **Index/contraintes** : `OneToOne` unique sur `ParametreMuseeEtablissement.etablissement`,
    `Salle.espaceAcces`, `SousQuotaSalle.salle`, `PolitiqueDelestage.sousQuotaSalle`,
    `Exposition.produit`, `Audioguide.produit`, `VisiteGuidee.creneauVisite`,
    `Gratuite.reservationRattachee`, `ReservationOTA.reservationRattachee`, `PassAnnuel.formule`,
    `PassAnnuel.support` ; unique `(guide, langue)` sur `QualificationLangueGuide` ; unique
    `(partenaire, creneau)` sur `AllocationQuotaOTA` ; checks `dateDebut < dateFin` (Exposition),
    `quotaConsomme ≤ quotaGratuitesDedie`/`quotaAlloue`, `effectif > 0`.
  - FKs sortantes vers modules existants : `off_produit` (Exposition, Audioguide), `off_formule`
    (PassAnnuel), `acces_espace_acces`/`acces_support` (Salle, PassAnnuel), `crm_beneficiaire`
    (BasculeAudioguide, PassAnnuel), `vente_vente` (DossierGroupeScolaire), `organisation_etablissement`/
    `organisation_espace`, `securite_utilisateur` (Guide).
  - FKs sortantes vers `App\Reservation\*` (`reservation_ressource`, `reservation_creneau`,
    `reservation_reservation`) — **suppose les migrations du module Réservation jouées avant**
    (dépendance d'ordre, cf. Risque n°1 — ce module n'est pas encore construit).
- **Migration de données** `VersionMusee_permissions` : insère `Permission(module='musee', action ∈
  {lire, configurer, superviser_salle, gerer_visite, gerer_dossier_groupe, gerer_pass, gerer_ota,
  gerer})`.
- **Modification de fichier partagé (hors migration DB)** — ajout des classes Musée sensibles (`Salle`,
  `SousQuotaSalle`, `DossierGroupeScolaire`, `Gratuite`, `PartenaireOTA`, `AllocationQuotaOTA`,
  `PassAnnuel`) à `App\Audit\Doctrine\AuditWriteSubscriber::CLASSES_SURVEILLEES`
  (`app/src/Audit/Doctrine/AuditWriteSubscriber.php`), même patron additif que L1-L6.
- Rejouables, versionnées Doctrine ; jamais de `schema:update --force`.

---

## 5. Tests (PHPUnit + ApiTestCase)

| Test | Type | Couvre |
|---|---|---|
| Timed-entry : exposition `aJauge=true` sans créneau sélectionné → achat refusé ; pastilles libre/tendu/complet cohérentes avec `quotaRestant` ; créneau à quota nul non sélectionnable | API (intégration `App\Reservation`) | CA-1, RG-MUS-01 |
| Fenêtre exposition : `Creneau` hors `[dateDebut,dateFin]` → refus création (`FenetreExpositionGuard`) ; expo hors période n'expose aucun créneau vendable sur aucun canal | API + Unit | CA-9, RG-MUS-06 |
| Sous-quota salle blocage : `Salle.espaceAcces.modeSeuil=blocage` → entrée refusée au seuil (réutilise `POST /acces/passages`, aucun code Musée dans le chemin) ; sortie libère une place | API (intégration L3) | CA-2 (mode blocage), RG-ACC-04 |
| Sous-quota salle alerte + délestage : `modeSeuil=alerte` → entrée acceptée au-delà du seuil, `SalleEtatLive` signale la saturation, `PolitiqueDelestage.mode` exposé à l'agent | API | CA-2 (mode alerte) |
| Visite guidée confirmée : guide qualifié dans la langue disponible → `confirmer` accepte dans la limite de `creneauVisite.capacite`, jauge d'entrée de l'expo inchangée par la réservation de visite | API | CA-3, RG-MUS-02 |
| Guide non qualifié : `confirmer` refuse (« aucun guide qualifié ») ; `GuideDisponibiliteGuard` refuse un guide déjà affecté sur un créneau chevauchant | API + Unit | RG-MUS-02, garde exclusivité |
| Bascule/liste d'attente (CA-4) : guide indisponible → inscription liste d'attente générique (réutilise `POST /reservation/creneaux/{id}/liste-attente`) **et** création `BasculeAudioguide` avec `tauxRemise` par défaut appliqué | API | CA-4, US-MUSEE-04 |
| Gratuité scolaire : octroi décrémente **simultanément** jauge du créneau (`Reservation.modeDecompte=gratuit`) et `ContingentGratuite.quotaConsomme` ; contingent épuisé → refus explicite même si jauge disponible | API + Unit (`AccorderGratuiteHandler`) | CA-5, RG-MUS-03 |
| Dossier groupe/scolaire : confirmation génère `effectif` `Reservation` (payantes + gratuites au bon grain) ; `statutPaiement` suit un cycle indépendant de la confirmation de créneau ; `dateOption` par défaut = paramètre établissement | API | CA-6, RG-MUS-03 |
| Allocation OTA : vente OTA décrémente le **même inventaire réel** que la vente directe (`Creneau.capaciteRestante` partagée) et incrémente `AllocationQuotaOTA.quotaConsomme` ; `Reversement` calculé sur les `ReservationOTA` confirmées de la période | API | CA-7, RG-MUS-04 |
| Sur-vente OTA : deux `ReservationOTA` concurrentes sur la même place → priorité à l'horodatage le plus ancien (`PrioriteOtaResolver`), la seconde `statutOta=refusee_conflit` | Unit (`PrioriteOtaResolver`) | CA-8 (priorité) |
| No-show OTA : `Reservation` liée à une `ReservationOTA` bascule en `no_show_facture` (moteur générique Réservation) → `RecupererQuotaOtaNoShowListener` décrémente `quotaConsomme`, `statutOta=recuperee_no_show`, place remise à disposition | API (intégration `App\Reservation`) + Unit | CA-8 (récupération) |
| Pass annuel : accès collection permanente sans nouveau paiement ; accès expo à jauge → créneau obligatoire, `Reservation.modeDecompte=gratuit` (tarif nul), décrément du quota du créneau identique à un billet payant | API | CA-10, RG-MUS-05 |
| Audioguide : ajout à un billet/visite, choix d'une langue du catalogue paramétré ; bascule (CA-4) applique la remise automatiquement sur le tarif visite initial | API | CA-11 |
| Cloisonnement établissement : agent sans affectation → 403/absent sur toutes les ressources `musee.*` | API | RG-SOCLE-05 |
| Architecture : aucun fichier `App\Acces\*`/`App\Offre\*`/`App\Reservation\*` modifié par ce lot (contrôle statique) | Unit (architecture) | non-régression socle/modules réutilisés |

---

## 6. Tâches (voir tasks-musee.md)

T1 enums/paramètres → T2 salles/sous-quota/délestage (L3) → T3 exposition/audioguide (satellites M1) + garde fenêtre → T4 guides/qualifications → T5 visite guidée + gardes + confirmation → T6 bascule audioguide → T7 contingent gratuités + dossier groupe/scolaire + gratuités → T8 partenaires/allocations/reversements OTA + port stub → T9 sur-vente/no-show OTA (listener) → T10 pass annuel → T11 API Platform (ressources, sécurité, sérialisation) → T12 permissions + extension audit → T13 migrations → T14 tests (ordonnées, cf. fichier tâches).

---

## 7. Risques / à valider

1. **⚠ Dépendance de construction — module Réservation non codé (priorité haute).** Ce plan **suppose**
   `App\Reservation\Entity\{Ressource,Creneau,Reservation,ListeAttente,RegleAnnulation,
   FacturationNoShow}` livrés en code réel (`plan-reservation.md`) **avant** ce lot ; à date, seul le plan
   existe (`app/src/Reservation/` vide). Toute FK vers ce module, la garde `FenetreExpositionGuard`, le
   listener `RecupererQuotaOtaNoShowListener` et l'usage de `RegleAnnulation` sont **bloqués** tant que
   le module Réservation n'est pas construit — **ordre de construction à respecter** (Réservation avant
   Musée).
2. **⚠ Mode blocage/alerte du sous-quota de salle + réglementation ERP** — décision n°3 laisse le choix
   `EspaceAcces.modeSeuil` **au paramétrage** de l'établissement (pas de garde forçant `blocage` comme la
   POSS piscine). Si le sous-quota répond à une contrainte réglementaire de sécurité (et non de simple
   confort), le mode `blocage` **devrait** être imposé — point réglementaire **non tranché par les
   sources**, à valider avec l'exploitant/la commission de sécurité avant mise en service (repris tel
   quel de `spec-musee.md` §7 dernier point).
3. **⚠ Mécanique du délestage non détaillée** — `PolitiqueDelestage.mode` reste **déclaratif** (consigne
   affichée à l'agent mobile), sans automatisme physique (pas de tourniquet par salle) — cohérent avec
   l'absence de matériel de contrôle intérieur, mais à confirmer avec l'exploitant (spec §4.2/§7).
4. **⚠ Conduite à l'épuisement du contingent de gratuités** — refus explicite retenu par défaut (aucune
   bascule automatique vers une entrée payante réduite), non tranché par les sources (spec §4.5/§7).
5. **⚠ Connecteur OTA technique hors périmètre** — `ConnecteurOtaInterface`/`StubConnecteurOtaAdapter` ne
   modélisent que le comportement observable (inventaire partagé, priorité, no-show) ; l'intégration
   réelle par plateforme (Tiqets, Weezevent, FNAC-Spectacles…) relève d'un futur lot M3, non cadrée ici.
6. **⚠ Statut socle du Guide** — hypothèse retenue : `Utilisateur` socle avec affectation établissement
   (§0 décision n°6), par analogie Encadrant MNS/BNSSA (Piscine) ; à confirmer si le module Réservation
   introduit un profil RH distinct.
7. **⚠ Paiement différé dossier groupe/scolaire** — aucun mécanisme générique M2/M6 de bon de commande /
   mandat administratif à ce jour ; `DossierGroupeScolaire.statutPaiement` reste un statut **déclaratif**
   propre au Musée, `venteRattachee` renseigné seulement à l'encaissement effectif M2 — à harmoniser avec
   le futur lot « Groupes & scolaires » (plan V1, L14 : Chorus Pro, gratuités/ratios).
8. **⚠ Valeurs non chiffrées** — `ParametreMuseeEtablissement.{seuilPastilleTendu,
   tauxRemiseAudioguideDefaut, delaiOptionDossierGroupeJours}` portent des valeurs de départ arbitraires
   (20 % / 20 % / 15 jours) à ajuster avec l'exploitant.
9. **US-MUSEE-01 à 11 non encore validées/numérotées officiellement** dans le backlog (spec, préambule)
   — ajustement mineur de nommage possible sans impact structurel attendu sur le modèle de données.
10. **⚠ Rebascule du second client OTA en conflit de sur-vente** — non détaillée au-delà du principe de
    priorité (statut `refusee_conflit` sans proposition automatique d'un autre créneau), conforme à
    `spec-musee.md` §4.8/§7 (« hors périmètre applicatif musée », remboursement à la charge du partenaire).
11. **⚠ Noms des permissions `musee.*`** — dérivés du tableau Acteurs & droits de la spec, à figer avec
    M8 (même réserve que `piscine.*`, `sport.*`, `acces.*`).
12. **Risque hérité du module Réservation (n°2 de son plan)** — la projection d'un droit d'accès L3 pour
    une `Reservation` (donc pour tout billet/visite Musée) reste un **no-op documenté**
    (`ProjectionAccesReservation.droitAccesRef` nullable) tant que `App\Acces\Port\
    ProjectionDroitInterface` n'est pas étendu d'un cas `Reservation` — le contrôle d'entrée physique au
    tourniquet d'un créneau Musée **dépend de la résolution de ce risque en amont**, hors périmètre de ce
    lot.
