# Plan technique — Facturation (`App\Facturation`)

- **Spec source :** specs/facturation/spec-facturation.md
- **Stack :** Symfony 7 · API Platform · Doctrine/MariaDB
- **Dépend de :** socle L0 (`specs/L0-socle/plan-socle.md`), M2 Vente & Caisse (`specs/L2-vente/plan-vente.md`,
  `app/src/Vente/`), M6 Compta & Régie (`specs/L4-compta/plan-compta.md`, `app/src/Compta/`), M4 CRM
  (`specs/L5-crm/plan-crm.md`, `app/src/Crm/`) — **réutilisés, non redéfinis**
- **Couvre :** US-FACT-01 à 08 (proposées) · RG-FACT-01 à 10 · CA-1 à CA-10 — **statut brouillon, plusieurs
  points ⚠ À VALIDER PAR EXPERT (§7 de ce plan)**, comme le rappelle la spec source.

> **Réutilisation socle L0** — hiérarchie `App\Organisation\Entity\{Groupe,Region,Etablissement,Espace}`
> (RG-SOCLE-01) ; `PermissionVoter` (attribut `PERM`, sujet `"module.action"`) ; `ContexteEtablissement`
> (RG-SOCLE-05) ; module de droits proposé **`facturation`**.
>
> **Réutilisation M2 Vente & Caisse** — `App\Vente\Entity\{Vente,LigneVente,Paiement}` : **source, lecture
> seule**, jamais modifiées. Le **principe** de chaînage NF525 (`App\Vente\Nf525\*`) est réutilisé comme
> *patron*, pas comme code partagé (voir §0.3) — même choix que M6 (`App\Compta\Nf525\ScellementEcritureHandler`,
> volontairement dupliqué pour ne pas coupler les domaines, cf. code existant).
>
> **Réutilisation M6 Compta & Régie** — `RegimeComptableResolver`, `CompteLookupService`,
> `App\Compta\Entity\{EcritureComptable,LigneEcriture,ProfilExploitant,PeriodeComptable,TauxTva,
> CompteComptable,MappingComptable,Journal,FactureB2G}`, `LettrageHandler`, `App\Compta\Nf525\
> ScellementEcritureHandler`, port `ChorusProInterface`/`ChorusProStubAdapter`. **Zéro modification de code**
> dans `App\Compta\*` (§0.2) : le module Facturation **consomme** ces classes déjà publiques/autowirables,
> exactement comme M6 lit déjà M2/M1 en direct (`ProjectionVenteDoctrineAdapter`, précédent établi dans ce
> dépôt). Une seule **donnée** ajoutée dans le plan de comptes existant (journal `FAC`, migration de données,
> §4).
>
> **Réutilisation M4 CRM** — lecture directe de `App\Crm\Entity\Client` (même précédent que M6→M2) pour
> peupler l'instantané `DestinataireFacturation` ; le CRM n'est jamais modifié.

---

## 0. Décisions d'architecture (le cœur du module)

### 0.1 La règle de comptabilisation conditionnelle (RG-FACT-03)

**Un seul point de décision**, dans `App\Facturation\Service\EmissionFactureRouter` (façade fine, pas de
logique dupliquée) qui **route** vers l'un des deux seuls chemins d'émission possibles, jamais un `if`
dispersé ailleurs :

| Entrée | Chemin | Effet comptable |
|---|---|---|
| `POST /factures/depuis-vente` (une `Vente` M2 scellée/payée) | `EmissionFactureJustificativeHandler` | **Aucun appel** au moteur d'écritures M6. `ecritureGeneree = null`. Statut → `acquittee` immédiatement. |
| `POST /factures/{id}/emettre` (brouillon composé sans vente) | `EmettreFactureDirecteHandler` | **Un seul appel** au moteur M6 (`RegimeComptableResolver` + entités `EcritureComptable`/`LigneEcriture` réutilisées telles quelles). Statut → `en_attente_paiement`. |

Aucun autre point du code ne décide « faut-il comptabiliser ? » : le **chemin d'appel** (justificative vs
directe) est la seule branche, déterminée par la présence ou non d'une `Vente` d'origine — jamais un
paramètre libre au moment de l'émission (conforme RG-FACT-03 : « jamais un choix manuel »).

### 0.2 Pourquoi zéro modification de `App\Compta\*`

Le moteur `RegimeComptableInterface::genererEcritureVente()` débite le **compte d'encaissement immédiat**
(511 en régie, 411 en DSP/groupe dans le code existant) — sémantique **incompatible** avec une facture à
terme (aucun encaissement immédiat, la créance doit être portée par un compte client 411 quel que soit le
régime). Plutôt que d'étendre `RegimeComptableInterface` (un nouveau point de contrat sur un moteur déjà
scellé fonctionnellement par ses tests), le handler de facture directe **compose lui-même** l'écriture avec
les briques **déjà publiques** de M6 :

- `RegimeComptableResolver::pour($profil)` puis `$regime->compteTvaCollectee($profil)` (déjà public, déjà
  identique quel que soit le régime dans le code actuel : `4457*`) ;
- `CompteLookupService::journal($profil, 'FAC')` — nouveau **code** de journal, pas une nouvelle méthode :
  `CompteLookupService::journal()` accepte déjà un code arbitraire. Le journal `FAC` (« Journal des
  factures directes ») est créé par une **migration de données** (§4), pas par du code, pour ne pas
  conflater les ventes comptoir (`VTE`) et les factures à terme dans le même journal ;
- `CompteLookupService::compteParPrefixe($profil, '411')` — le compte 411 « Redevables » est **déjà seedé**
  pour le profil de démonstration (`ComptaFixtures`, y compris en régie M57) ; réutilisé tel quel comme
  compte client, quel que soit le régime (411 est le compte de tiers standard aussi bien en M57/M4 qu'en
  PCG) ;
- `MappingComptable` (entité M6, table existante) — relue directement (même précédent que
  `ProjectionVenteDoctrineAdapter`) pour résoudre le compte produit d'une ligne via une `categorieComptable`
  optionnelle (ajout **applicatif** sur `LigneFacture`, §1.2) ; à défaut, un compte produit par défaut
  paramétrable côté Facturation (`ParametreFacturationEtablissement.compteProduitDefaut`).
- `App\Compta\Entity\EcritureComptable`/`LigneEcriture` — **instanciées et persistées directement** par
  `EmettreFactureDirecteHandler` (mêmes classes, même table), scellées par
  `App\Compta\Nf525\ScellementEcritureHandler::sceller()` **réutilisé tel quel** (chaîne `(profil, journal)`
  existante, désormais alimentée aussi par le journal `FAC`).

**Conséquence** : `App\Compta\*` n'est touché par **aucun fichier PHP** de ce plan. Seule une migration de
données (INSERT de lignes `Journal`) est ajoutée, réversible. C'est le choix « idéalement rien » demandé.

⚠ **Risque documenté (§7, point 1)** — le compte 411 est résolu par préfixe générique, sans passer par le
`RegimeComptableInterface` (qui reste propriétaire du choix du compte d'encaissement *immédiat*). C'est
cohérent avec le point ⚠ déjà ouvert par la spec elle-même (§4.3, articulation titre de recette en régie) :
si un expert-comptable tranche qu'un compte client différent doit être utilisé par régime, l'extension sera
alors : ajouter `compteClient(ProfilExploitant): CompteComptable` à `RegimeComptableInterface` (1 méthode +
3 implémentations, changement additif isolé, décrit mais **non fait** dans ce lot).

### 0.3 NF525 — chaîne propre à Facturation, pas de table partagée

Même raisonnement que M6 (`ScellementEcritureHandler`, commentaire du code : *« implémentation propre à M6
[...] M2 n'est pas modifié »*) : Facturation duplique le **principe** (empreinte chaînée sha256 + HMAC,
champs embarqués `numeroSequence/empreinte/empreintePrecedente/signature`), **pas le code**, dans
`App\Facturation\Nf525\ScellementFactureHandler`. La chaîne est scopée par **`profilExploitant` seul**
(facture et avoir **mélangés dans une même chaîne chronologique**, `nature` figurant dans le payload
canonique) — même précédent que M2 où `OperationScellee` mélange déjà Vente et Avoir dans la chaîne d'un
même point de vente.

Le **numéro métier légal** (`FA-…`/`AVF-…`, RG-FACT-01) est un mécanisme **distinct** de cette chaîne
d'intégrité : compteur atomique par `(profilExploitant, exercice, préfixe)` porté par l'entité
`SerieNumerotation` (§1.4), incrémenté sous verrou pessimiste. Deux garanties indépendantes, comme pour
`Vente.numero` (lisible) vs `OperationScellee.numeroSequence` (intégrité) en M2.

### 0.4 Idempotence

- **Facture justificative** : contrainte d'unicité base `uniq_facture_vente_origine` sur
  `facture.vente_origine_id` (NULL multiples autorisés par MariaDB) **+** vérification applicative
  (`findOneBy(['venteOrigine' => $vente])`) avant toute création — la seconde demande renvoie l'objet
  existant, aucun nouveau numéro (CA-2).
- **Facture directe** : la transition `brouillon → émise` est gardée par le statut (`ConflictHttpException`
  si `statut !== Brouillon`) et par `FactureInalterableListener` (aucune réécriture du contenu financier
  après scellement) — une seule écriture possible par facture (CA-4).
- **Toute l'émission** (numérotation + écriture + scellement Facture) s'exécute dans **une seule
  transaction** (`EntityManagerInterface::wrapInTransaction()`) pour fermer la fenêtre « numéro consommé
  sans écriture » en cas d'erreur en cours de route.

---

## 1. Entités & schéma

Namespace : **`App\Facturation\Entity\*`** (+ `App\Facturation\Enum\*`, `App\Facturation\Nf525\*`,
`App\Facturation\Service\*`, `App\Facturation\Doctrine\*`, `App\Facturation\State\*`). `id` = UUID
(`Symfony\Component\Uid\Uuid`, type Doctrine `uuid`). `declare(strict_types=1)` partout. Montants en
`decimal(10,2)` (chaîne), **même convention que M2/spec** (pas de centimes ici — la conversion
decimal→centimes n'a lieu qu'à la frontière avec M6, dans `EmettreFactureDirecteHandler`, même principe que
`ProjectionVenteDoctrineAdapter`).

### 1.1 Facture

| Champ | Type Doctrine | Null | Index/Contrainte | Notes |
|---|---|---|---|---|
| id | `uuid` | non | PK | — |
| numero | `string(32)` | oui | **unique** (NULL multiples OK) | rempli seulement à l'émission (§0.3) |
| nature | `string(8)` enum `NatureFacture` {facture, avoir} | non | — | RG-FACT-05 |
| origine | `string(16)` enum `OrigineFacture` {ticket_encaisse, vente_a_terme} | non | immuable après création (garde listener) | RG-FACT-03 |
| venteOrigine | `ManyToOne → App\Vente\Entity\Vente` | oui | **unique** (`uniq_facture_vente_origine`) | requis si `origine=ticket_encaisse` ; §0.4 |
| factureCorrigee | `ManyToOne → self` | oui | — | requis si `nature=avoir` |
| profilExploitant | `ManyToOne → App\Compta\Entity\ProfilExploitant` | non | — | porte la séquence NF525 et le SIREN |
| periode | `ManyToOne → App\Compta\Entity\PeriodeComptable` | oui | — | rempli à l'émission (exercice couvrant `dateEmission`) |
| etablissement | `ManyToOne → App\Organisation\Entity\Etablissement` | non | index | cloisonnement RG-SOCLE-05 |
| destinataire | `OneToOne → DestinataireFacturation` (cascade persist, orphanRemoval) | non | — | instantané figé, propre à cette facture (jamais partagé, §1.3) |
| statut | `string(24)` enum `StatutFacture` {brouillon, emise, acquittee, en_attente_paiement, partiellement_reglee, payee, echue} | non | défaut `brouillon` | RG-FACT-04 |
| dateEmission | `datetime_immutable` | oui | requis dès émise | — |
| dateEcheance | `date_immutable` | oui | requis si directe émise | — |
| conditionsReglement | `text` | oui | requis dès émise | échéance, pénalités, indemnité forfaitaire |
| totalHT, totalTVA, totalTTC | `decimal(10,2)` | non | défaut `0.00` | Σ lignes |
| ventilationTva | `json` `{taux,baseHT,montantTva}[]` | oui | calculé à l'émission | RG-M6-05 réutilisée (aucun taux moyen) |
| mentionAcquittee | `boolean` | non | défaut `false` | RG-FACT-02 |
| acquitteeLe | `datetime_immutable` | oui | requis si `mentionAcquittee` | — |
| acquitteeMoyen | `string(120)` | oui | requis si `mentionAcquittee` | concat. des moyens du ticket M2 pour justificative |
| acquitteeReference | `string(64)` | oui | requis si `mentionAcquittee` | n° de ticket M2 (justificative) |
| ecritureGeneree | `ManyToOne → App\Compta\Entity\EcritureComptable` | oui | **null si justificative** | RG-FACT-03, cœur |
| ligneEcritureClient | `ManyToOne → App\Compta\Entity\LigneEcriture` | oui | — | référence directe de la ligne « client » 411 à lettrer (évite une re-dérivation fragile, addition technique) |
| factureB2G | `ManyToOne → App\Compta\Entity\FactureB2G` | oui | — | RG-FACT-07 |
| canal | `string(12)` enum `CanalFacture` {pdf, chorus_pro} | oui | — | — |
| numeroSequence | `bigint` | non | défaut `0` | chaîne NF525 (§0.3) |
| empreinte | `string(128)` | non | défaut `''` | — |
| empreintePrecedente | `string(128)` | oui | — | — |
| signature | `string(512)` | non | défaut `''` | — |
| creeLe | `datetime_immutable` | non | — | RG-SOCLE-07 |
| creePar | `ManyToOne → App\Securite\Entity\Utilisateur` | non | — | — |

Table `facturation_facture`. Contraintes : `UNIQUE(numero)`, `UNIQUE(vente_origine_id)`,
`INDEX(profil_exploitant_id, nature, numero_sequence)` (vérification de chaîne, §5 tests).

### 1.2 LigneFacture

| Champ | Type | Null | Notes |
|---|---|---|---|
| id | `uuid` | non | PK |
| facture | `ManyToOne → Facture` (inversedBy `lignes`, cascade persist, orphanRemoval) | non | — |
| designation | `string(255)` | non | libellé produit/prestation |
| ligneVenteOrigine | `uuid` (réf. logique, pas de FK dure) | oui | requis si `facture.origine=ticket_encaisse` — pointe `App\Vente\Entity\LigneVente` |
| categorieComptable | `uuid` (réf. logique M1) | oui | **ajout technique de ce plan** (hors table indicative de la spec) — permet de résoudre le compte produit via `MappingComptable` (M6) réutilisé à l'identique ; absent pour une ligne composée librement (facture directe) → repli `compteProduitDefaut` |
| quantite | `integer` | non | défaut `1`, `Assert\Positive` |
| prixUnitaireHT | `decimal(10,2)` | non | — |
| tauxTva | `ManyToOne → App\Compta\Entity\TauxTva` | non | RG-M6-05, aucune ligne sans taux |
| montantHT, montantTva, montantTTC | `decimal(10,2)` | non | `montantTTC = HT + TVA` |

Table `facturation_ligne`.

### 1.3 DestinataireFacturation

| Champ | Type | Null | Notes |
|---|---|---|---|
| id | `uuid` | non | PK |
| type | `string(16)` enum `TypeDestinataire` {particulier, personne_morale} | non | conditionne les champs suivants |
| nom, prenom | `string(120)` | oui | requis si `particulier` |
| raisonSociale | `string(180)` | oui | requis si `personne_morale` |
| siret | `string(14)` | oui | requis si `personne_morale` |
| tvaIntracommunautaire | `string(20)` | oui | toujours optionnel (§4.4 spec, cas limite) |
| adresse | `json` `{rue,complement,cp,ville,pays}` | non | — |
| clientRef | `uuid` (réf. logique M4) | oui | optionnel, navigation vers `App\Crm\Entity\Client` |
| estOrganismePublic | `boolean` | non | défaut `false` — conditionne l'éligibilité Chorus Pro |

Table `facturation_destinataire`. **Une ligne par Facture** (jamais partagée/réutilisée entre deux factures,
y compris pour un même client — c'est l'instantané figé RG-FACT-08 : une modification ultérieure du
`Client` M4 ne doit *jamais* pouvoir se répercuter, donc pas de possibilité même accidentelle de partage).

### 1.4 SerieNumerotation

| Champ | Type | Null | Notes |
|---|---|---|---|
| id | `uuid` | non | PK |
| profilExploitant | `ManyToOne → ProfilExploitant` | non | — |
| periode | `ManyToOne → PeriodeComptable` | non | exercice (§0.3 spec, périmètre ⚠ ouvert documenté §7) |
| prefixe | `string(4)` enum `PrefixeSerie` {FA, AVF} | non | — |
| dernierNumero | `integer` | non | défaut `0`, append-only, incrémenté sous verrou pessimiste |

Table `facturation_serie_numerotation`, `UNIQUE(profil_exploitant_id, periode_id, prefixe)`.

### 1.5 ReglementFacture

*(addition technique — nécessaire pour supporter le règlement **partiel**, RG-FACT-06, alors que
`App\Compta\Entity\LettrageEcriture` (M6) ne porte pas de montant : c'est un marqueur « ligne soldée »,
tout-ou-rien. Facturation trace donc ses règlements dans son propre domaine et n'appelle
`LettrageHandler::lettrer()` **qu'une seule fois**, quand le cumul atteint le total — aucun second mécanisme
de lettrage n'est créé, la réconciliation finale de la ligne d'écriture reste portée par M6.)*

| Champ | Type | Null | Notes |
|---|---|---|---|
| id | `uuid` | non | PK |
| facture | `ManyToOne → Facture` (inversedBy `reglements`) | non | — |
| montant | `decimal(10,2)` | non | — |
| moyen | `string(32)` | non | code moyen (référentiel M6 réutilisé en clair, même convention que `Paiement::moyenCode`) |
| reference | `string(64)` | oui | — |
| dateReglement | `datetime_immutable` | non | — |
| auteur | `ManyToOne → Utilisateur` | non | — |

Table `facturation_reglement`.

### 1.6 ParametreFacturationEtablissement

| Champ | Type | Null | Notes |
|---|---|---|---|
| id | `uuid` | non | PK |
| profilExploitant | `ManyToOne → ProfilExploitant` | non | **unique** (1 paramétrage / profil) |
| mentionsLegalesEmetteur | `json` `{denomination,adresse,siret,tvaIntra}` | non | RG-FACT-02 |
| conditionsReglementDefaut | `text` | non | échéance/pénalités/indemnité par défaut |
| delaiPaiementDefautJours | `integer` | non | défaut `30` |
| tauxPenaliteRetard | `decimal(5,2)` | oui | ⚠ EXPERT #4 |
| indemniteForfaitaireRecouvrement | `decimal(10,2)` | non | défaut `40.00`, ⚠ EXPERT #4 |
| mentionTvaSpecifique | `string(255)` | oui | franchise en base, §4.2 hypothèse |
| chorusProActif | `boolean` | non | défaut `false` |
| compteProduitDefaut | `ManyToOne → App\Compta\Entity\CompteComptable` | oui | repli si `LigneFacture.categorieComptable` absent/non mappé |

Table `facturation_parametre`, `UNIQUE(profil_exploitant_id)`.

---

## 2. API (API Platform)

Toutes les opérations d'écriture sont des `Post` fins (mêmes conventions que M2/M6 : `read`/`input` à
`false` pour les actions métier, `processor` dédié). Groupes de sérialisation : `facture:read`,
`facture:write`, `ligne:read`, `destinataire:read`, `destinataire:write`, `reglement:read`, `parametre:read`,
`parametre:write`.

| Ressource / Route | Opération | `security:` | Processor/Provider | Couvre |
|---|---|---|---|---|
| `GET /factures` | GetCollection | `facturation.lire` | — (cloisonné par `PerimetreFacturationExtension`) | consultation interne |
| `GET /factures/{id}` | Get | `facturation.lire or (facturation.lire_soi and object.estLieA(user))` | — | CA-10 |
| `GET /mes-factures` | GetCollection | `facturation.lire_soi` | `MesFacturesProvider` | CA-10, espace client M3 |
| `GET /factures/{id}/rendu` | Get | `facturation.lire or (facturation.lire_soi and ...)` | `FactureRenduProvider` | CA-8 |
| `POST /factures` | Post | `facturation.emettre_directe` | `CreerFactureDirecteProcessor` | crée un **brouillon** (destinataire + lignes composées) |
| `PATCH /factures/{id}` | Patch | `facturation.emettre_directe` | `ModifierFactureDirecteProcessor` | modif libre **tant que brouillon** (gardé par listener) |
| `POST /factures/depuis-vente` | Post (`/factures/depuis-vente`) | `facturation.emettre_justificative` | `EmettreFactureJustificativeProcessor` | CA-1, CA-2 |
| `POST /factures/{id}/emettre` | Post | `facturation.emettre_directe` | `EmettreFactureDirecteProcessor` | CA-3, CA-4 |
| `POST /factures/{id}/reglements` | Post | `facturation.lettrer` | `EnregistrerReglementProcessor` | CA-5 |
| `POST /factures/{id}/avoir` | Post | `facturation.avoir` | `GenererAvoirFactureProcessor` | CA-6 |
| `POST /factures/{id}/chorus` | Post | `facturation.deposer_chorus` | `DeposerChorusProFactureProcessor` | CA-9 |
| `GET /factures/verifier-chaine` | GetCollection | `facturation.lire` | `VerifierChaineFactureProvider` | CA-7 (intégrité) |
| `GET /parametres-facturation`, `GET /parametres-facturation/{id}` | GetCollection/Get | `facturation.lire` | — | — |
| `POST/PATCH /parametres-facturation` | Post/Patch | `facturation.gerer` | — | US-FACT-08 |
| `GET /series-numerotation` | GetCollection | `facturation.lire` | — | lecture seule (traçabilité), écrite uniquement par `GenerateurNumeroFacture` |

Filtres : `ApiFilter(SearchFilter::class, properties: ['statut' => 'exact', 'nature' => 'exact', 'origine' =>
'exact', 'numero' => 'exact', 'venteOrigine' => 'exact'])` sur `Facture`.

`Facture::estLieA(mixed $user): bool` — même patron que `Crm\Entity\Client::estLieA()` : compare
`$user->getClientLie()` à `$this->destinataire->getClientRef()`.

---

## 3. Sécurité & droits

Module de droits **`facturation`** (proposé par la spec, à faire figer par M8) :

| Permission | Portée |
|---|---|
| `facturation.lire` | consultation interne (agent/comptable/admin) |
| `facturation.lire_soi` | client final, ses propres factures uniquement |
| `facturation.emettre_justificative` | agent de caisse / comptable |
| `facturation.emettre_directe` | comptable (création brouillon, modification brouillon, émission) |
| `facturation.avoir` | comptable |
| `facturation.lettrer` | comptable |
| `facturation.deposer_chorus` | comptable |
| `facturation.gerer` | admin (paramétrage, surensemble) |

Cloisonnement : `App\Facturation\Doctrine\PerimetreFacturationExtension` (implémente
`QueryCollectionExtensionInterface`/`QueryItemExtensionInterface`, même patron que
`App\Vente\Doctrine\PerimetreVenteExtension`), restreint `Facture` par `{root}.etablissement` via
`Affectation` de l'utilisateur courant (RG-SOCLE-05). Pas de voter dédié : le contrôle « soi-même » passe
par `object.estLieA(user)` (expression `security:` déclarative, même patron que `Client`/`FicheClient360`).

⚠ HYPOTHÈSE reprise de la spec (§3) : restriction de l'agent de caisse à ses propres ventes encaissées —
**non implémentée dans ce lot** (le cloisonnement s'arrête à l'établissement, comme RG-SOCLE-05) ; à
trancher avec le métier avant activation d'une restriction plus fine (nécessiterait un voter dédié comparant
`Vente.session.utilisateur` — non fait ici, signalé).

---

## 4. Migrations

**Migration 1 — schéma** (réversible) :
- `CREATE TABLE facturation_destinataire (...)`
- `CREATE TABLE facturation_facture (...)` + FK vers `vente_vente`, `compta_profil_exploitant`,
  `compta_periode_comptable`, `org_etablissement`, `facturation_destinataire`, `self` (factureCorrigee),
  `compta_ecriture_comptable`, `compta_ligne_ecriture`, `compta_facture_b2g`, `sec_utilisateur`.
  `UNIQUE(numero)`, `UNIQUE(vente_origine_id)`, `INDEX(profil_exploitant_id, nature, numero_sequence)`.
- `CREATE TABLE facturation_ligne (...)` + FK `facturation_facture`, `compta_taux_tva`.
- `CREATE TABLE facturation_reglement (...)` + FK `facturation_facture`, `sec_utilisateur`.
- `CREATE TABLE facturation_serie_numerotation (...)` + FK `compta_profil_exploitant`,
  `compta_periode_comptable`. `UNIQUE(profil_exploitant_id, periode_id, prefixe)`.
- `CREATE TABLE facturation_parametre (...)` + FK `compta_profil_exploitant`, `compta_compte_comptable`.
  `UNIQUE(profil_exploitant_id)`.
- Seed permissions (`INSERT IGNORE INTO sec_permission`, idempotent, même patron que
  `Version20260817192240`) : `facturation.lire`, `facturation.lire_soi`,
  `facturation.emettre_justificative`, `facturation.emettre_directe`, `facturation.avoir`,
  `facturation.lettrer`, `facturation.deposer_chorus`, `facturation.gerer`.
- `down()` : suppression symétrique des 8 permissions + `DROP TABLE` dans l'ordre inverse des FK.

**Migration 2 — donnée M6 additive** (réversible, **aucune table/colonne M6 modifiée**) :
- Pour chaque `compta_profil_exploitant` existant, `INSERT IGNORE INTO compta_journal (id, code, libelle,
  profil_exploitant_id) VALUES (?, 'FAC', 'Journal des factures directes', ?)`.
- `down()` : `DELETE FROM compta_journal WHERE code = 'FAC'`.
- Note : un profil créé **après** cette migration devra voir son journal `FAC` créé par le même mécanisme
  que ses autres journaux (fixture/onboarding profil, hors périmètre technique strict de ce plan — signalé
  §7).

---

## 5. Tests

| Test | Type | Couvre |
|---|---|---|
| `FactureJustificativeApiTest::testCa1FactureSurTicketDejaEncaisseNeRecomptabilisePas` | Fonctionnel API | CA-1 : `ecritureGeneree=null`, statut `acquittee`, aucune `EcritureComptable` créée, CA de la période inchangé |
| `FactureJustificativeApiTest::testCa2DuplicataMemeVenteNeCreePasNouvelleFacture` | Fonctionnel API | CA-2, RG-FACT-09 : deuxième appel `POST /factures/depuis-vente` renvoie le même `id`/`numero` |
| `FactureJustificativeApiTest::testUniciteVenteOrigineEnBase` | Intégration (contrainte DB) | idempotence — tentative d'INSERT direct concurrente rejetée par `uniq_facture_vente_origine` |
| `FactureDirecteApiTest::testCa3EmissionGenereEcritureEquilibreeEtCreance` | Fonctionnel API | CA-3 : écriture équilibrée (débit 411 / crédit produit+TVA), scellée, statut `en_attente_paiement` |
| `FactureDirecteApiTest::testCa4ReEmissionRejetee` | Fonctionnel API | CA-4 : `POST /factures/{id}/emettre` sur une facture déjà émise → 409, pas de 2ᵉ écriture |
| `FactureDirecteApiTest::testCompteProduitIndeterminableRejeteExplicitement` | Fonctionnel API | ligne sans `categorieComptable` ni `compteProduitDefaut` → 422 clair (pas de crash) |
| `ReglementFactureApiTest::testCa5ReglementTotalLettreEtPasse` | Fonctionnel API | CA-5 : règlement total → `LettrageEcriture` créée sur la ligne 411, statut `payee` |
| `ReglementFactureApiTest::testCa5ReglementPartielMetAJourSolde` | Fonctionnel API | CA-5 : règlement partiel → statut `partiellement_reglee`, solde recalculé, **aucun** lettrage M6 encore posé |
| `AvoirFactureApiTest::testCa6AvoirSurFactureDirecteExtourneEcriture` | Fonctionnel API | CA-6 branche 1 : écriture d'extourne symétrique générée, `pieceExtourneDe` renseigné |
| `AvoirFactureApiTest::testCa6AvoirSurFactureJustificativeNeGenereAucuneEcriture` | Fonctionnel API | CA-6 branche 2 : `ecritureGeneree=null` sur l'avoir, aucune écriture créée |
| `NumerotationFactureTest::testCa7SequenceSansTrouNiDoublonFaEtAvf` | Unitaire/Intégration | CA-7 : 20 émissions concurrentes simulées (verrou pessimiste) → séquence continue par préfixe, brouillon jamais numéroté |
| `ScellementFactureHandlerTest::testChaineDetecteAlteration` | Unitaire | intégrité NF525 propre à Facturation (empreinte/signature, trou de séquence détecté) |
| `FactureRenduApiTest::testCa8RenduPortesLesMentionsLegales` | Fonctionnel API | CA-8 : numéro, émetteur, destinataire, lignes, TVA ventilée, totaux, conditions, mention acquittée |
| `ChorusProFactureApiTest::testCa9DepotB2gTraceStatutEnvoi` | Fonctionnel API | CA-9 : `FactureB2G` créée/liée, `statutEnvoi` tracé, rejeu sans nouveau numéro en cas d'échec |
| `EspaceClientFactureApiTest::testCa10ClientVoitSesFacturesUniquement` | Fonctionnel API | CA-10 : `GET /mes-factures` filtré par `clientRef`, 403/404 sur la facture d'un autre client |
| `CloisonnementFacturationTest::testUtilisateurHorsEtablissementNAccedePas` | Fonctionnel API | RG-SOCLE-05, `PerimetreFacturationExtension` |
| `FactureInalterableListenerTest::testFactureScelleeRejetteModificationDuContenu` | Unitaire | inaltérabilité post-scellement (contenu bloqué, statut/lettrage autorisés) |

---

## 6. Tâches (voir `tasks-facturation.md`)

1. **T1** — Enums (`NatureFacture`, `OrigineFacture`, `StatutFacture`, `TypeDestinataire`, `CanalFacture`,
   `PrefixeSerie`) + entités §1 + migration 1 (schéma + permissions).
2. **T2** — `App\Facturation\Nf525\ScellementFactureHandler` + `FactureInalterableListener` + tests
   d'intégrité (indépendants du reste, testables tôt).
3. **T3** — `GenerateurNumeroFacture` (verrou pessimiste sur `SerieNumerotation`) + tests de séquence
   concurrente (CA-7).
4. **T4** — `EmissionFactureJustificativeHandler` + `EmettreFactureJustificativeProcessor` + tests CA-1/CA-2
   (chemin le plus simple, aucune dépendance au moteur M6 d'écritures).
5. **T5** — Migration 2 (journal `FAC`) + `EmettreFactureDirecteHandler` (résolution comptes/journal,
   construction écriture, appel `ScellementEcritureHandler` M6) + `CreerFactureDirecteProcessor`/
   `ModifierFactureDirecteProcessor`/`EmettreFactureDirecteProcessor` + tests CA-3/CA-4.
6. **T6** — `ReglementFactureHandler` (+ entité `ReglementFacture`) + `EnregistrerReglementProcessor` + tests
   CA-5 (total et partiel).
7. **T7** — `AvoirFactureHandler` + `GenererAvoirFactureProcessor` + tests CA-6 (deux branches).
8. **T8** — `DepotChorusProHandler` + `DeposerChorusProFactureProcessor` + tests CA-9.
9. **T9** — `FactureRenduProvider` (structure de rendu, pas de moteur PDF) + `MesFacturesProvider` +
   `PerimetreFacturationExtension` + `VerifierChaineFactureProvider` + tests CA-8/CA-10/cloisonnement.
10. **T10** — `ParametreFacturationEtablissement` CRUD (`facturation.gerer`) + fixtures de démonstration
    (`FacturationFixtures`, un profil/établissement de démo, cohérent avec `ComptaFixtures`).

---

## 7. Risques / points à valider

1. **Compte client 411 résolu par préfixe générique, hors `RegimeComptableInterface`** (§0.2) — fonctionne
   avec le plan de comptes seedé actuel (411000 présent y compris en régie M57 de démo), mais **pas encore
   arbitré par un expert-comptable** ; si le régime doit déterminer un compte différent selon le contexte,
   prévoir l'extension additive documentée (`compteClient()`, 1 méthode + 3 implémentations M6).
2. **Articulation titre de recette / facture directe en régie** — hérite intégralement du point ⚠ ouvert de
   `spec-compta.md` §4.6, étendu par `spec-facturation.md` §4.3 ; **non traité** par ce plan (une facture
   directe en régie génère une créance 411, sans émission de titre de recette formalisée).
3. **Périmètre de la séquence de numérotation** (`ProfilExploitant`/SIREN vs établissement physique) —
   retenu par défaut au niveau du profil exploitant (§1.4), conforme au précédent `EcritureComptable`, mais
   ⚠ à valider par un expert-comptable (établissement secondaire à SIRET distinct).
4. **Déclenchement de l'avoir de facture après un avoir M2** (remboursement d'une vente déjà facturée) —
   ce plan expose l'endpoint manuel `POST /factures/{id}/avoir` (le Comptable le déclenche) ; **aucun
   déclenchement automatique** depuis `ContrePassationHandler` (M2) n'est câblé — risque de facture
   justificative « orpheline » signalé mais non résolu (même point ouvert que la spec §7).
5. **Exclusion e-reporting B2C pour une facture B2G déposée** (RG-M6-08/09, CA-9 fin d'énoncé) — ce plan
   **enregistre** les données nécessaires (`Facture.canal=chorus_pro`, `Facture.venteOrigine`) mais
   **n'implémente pas** le filtre côté générateur e-reporting M6 (`GenerateurEReportingHandler`), qui
   resterait à étendre de façon additive dans un lot ultérieur (hors périmètre strict ici, pour respecter
   la contrainte « ne pas modifier M6 au-delà du strict nécessaire »).
6. **Journal `FAC` pour les profils créés après la migration 2** — pas d'automatisme d'onboarding dans ce
   plan ; à intégrer au processus de création d'un `ProfilExploitant` (`ProfilExploitantProcessor`, M6) si
   ce module est confirmé au backlog — modification M6 mineure mais **non faite** ici.
7. **Avoir partiel** (§1, `AvoirFactureHandler`) — simplification documentée : une seule ligne synthétique
   au taux de TVA de la première ligne de la facture d'origine (même niveau de simplification assumé que
   `ContrePassationHandler::rembourser()` côté M2 pour la ventilation PMV) ; à affiner si le besoin de
   ventilation exacte par taux est confirmé.
8. **Facturation d'acompte/solde**, **relance/recouvrement avancés**, **facture électronique B2B (PDP)** —
   explicitement hors périmètre (spec §2/§9), non traités par ce plan.
9. **Restriction de l'agent de caisse à ses seules ventes encaissées** (§3) — non implémentée (cloisonnement
   établissement seul) ; nécessiterait un voter dédié si confirmée par le métier.
10. **Moteur de rendu PDF** — ce plan livre uniquement la **structure de données** prête pour un gabarit
    (`FactureRenduProvider`, JSON normalisé avec toutes les mentions légales, CA-8) ; la génération d'un
    binaire PDF (Dompdf/mPDF/wkhtmltopdf) est **repoussée**, cohérent avec la consigne de ne pas construire
    un moteur PDF complet dans ce lot.
11. **Aucune US-Lx officielle** ne couvre ce module (préambule de la spec) — implémentation à ne déclencher
    qu'après formalisation au backlog, comme le rappelle `spec-facturation.md`.
