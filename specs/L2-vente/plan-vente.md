# Plan technique — Vente & Caisse (`M2` / lot `L2`)

- **Spec source :** specs/L2-vente/spec-vente.md
- **Stack :** Symfony 7 · API Platform · Doctrine/MariaDB
- **Dépend de :** socle L0 (specs/L0-socle/plan-socle.md) et M1 offre (specs/L1-offre/plan-offre.md) — **réutilisés, non redéfinis**
- **Couvre :** US-L2-01 à US-L2-12 · RG-M2-01 à RG-M2-08 · CA-1 à CA-16

> **Réutilisation socle L0 (à ne pas dupliquer)** — hiérarchie `App\Organisation\Entity\{Groupe,Region,Etablissement,Espace}` (RG-SOCLE-01) ; `PermissionVoter` (attribut `PERM`, sujet `"module.action"`, RG-SOCLE-04) ; service `ContexteEtablissement` (en-tête `X-Etablissement`, RG-SOCLE-05) ; identité & code opérateur/régisseur (`Utilisateur`, RG-SOCLE-06) ; `App\Audit\Entity\EntreeAudit` append-only (RG-SOCLE-07) que la couche NF525 **renforce** (signature + chaînage propres à l'encaissement), sans le remplacer.
>
> **Réutilisation M1 offre (à ne pas redéfinir)** — `App\Offre\Entity\{Produit, TypeTarif, Saison, GrilleTarifaire, Promotion, CarteMultiEntrees, Stock, Pool}` et le service `App\Offre\Service\ResolveurPrix` (prix = produit × type de tarif × saison + QF, RG-M1-01). M2 **consomme** le prix résolu, les canaux (visibilité `guichet`, RG-M1-07), les promotions (RG-M1-04) et le stock (RG-M1-10) ; il **décrémente** stock et compostages à la vente mais ne modélise aucun de ces objets. Le port `App\Offre\Port\DependanceVenteInterface` (stub en L1) est **implémenté ici** (M2 sait dire si un produit a des ventes actives).
>
> **Frontière M6 (hors périmètre L2)** — `MoyenPaiement` et l'**acte de régie** sont le référentiel de **M6** ; M2 les **référence** via un port `ReferentielReglementInterface` (stub en L2 renvoyant les moyens standard) et **filtre** les moyens autorisés selon l'acte de régie. Les écritures comptables, PCA/TVA, e-reporting et archivage probant restent **M6** ; M2 **alimente** M6.

---

## 1. Entités & schéma

Namespaces : **`App\Caisse\Entity\*`** (poste, session, régie) et **`App\Vente\Entity\*`** (ticket, règlement, avoir, support, chaînage). `id` = UUID (`Symfony\Component\Uid\Uuid`, type Doctrine `uuid`). `declare(strict_types=1)` partout (constitution §3). Noms métier en français (constitution §7). Montants = `decimal(10,2)`. Toute entité racine porte un `ManyToOne` vers `Etablissement` **du socle** (RG-SOCLE-01) et suit le cloisonnement `ContexteEtablissement` (RG-SOCLE-05).

### 1.1 `App\Caisse\Entity\*` — poste de vente & régie

| Entité | Champ | Type Doctrine | Null | Index/Contrainte | Relation |
|---|---|---|---|---|---|
| **PointDeVente** | id | `uuid` | non | PK | — |
| | libelle | `string(120)` | non | — | — |
| | etablissement | — | non | FK `nullable:false` | `ManyToOne` → `Etablissement` (socle) ; peut porter un `Espace` |
| | imprimante | `json` | oui | config périphérique | paramétrage Admin (`caisse.gerer`) |
| | tpe | `json` | oui | liste terminaux {marque∈{ingenico,nayax,pax}, ref} | US-L2-07 |
| | favoris | `json` | oui | UUID de `Produit` (M1) | écran de caisse (US-L2-02) |
| | seuilImpression | `decimal(10,2)` | non | `≥ 0`, **défaut `0.00`** | décision actée seuil ; 0 = impression systématique (⚠ défaut à confirmer) |
| | moyensAutorises | `json` | non | codes ⊂ référentiel M6, filtrés par acte de régie | RG-M2-02 |
| **Caisse** | id | `uuid` | non | PK | — |
| | libelle | `string(120)` | non | — | — |
| | pointDeVente | — | non | FK | `ManyToOne` → `PointDeVente` |
| | etat | `string(16)` enum `EtatCaisse` {ouverte, en_fermeture, securisee} | non | défaut `securisee` | 🟢/🟡/🔒 ; rouverture si `securisee` → code régisseur (CA-2) |
| **SessionCaisse** | id | `uuid` | non | PK | RG-M2-01 |
| | numero | `string(32)` | non | **unique** ; séquence par point de vente | — |
| | pointDeVente | — | non | FK | `ManyToOne` → `PointDeVente` |
| | caisse | — | non | FK | `ManyToOne` → `Caisse` |
| | regisseur | — | non | FK, code validé | `ManyToOne` → `Utilisateur` (socle), RG-M2-01 |
| | operateur | — | non | FK | `ManyToOne` → `Utilisateur` (agent ayant ouvert) |
| | fondDeCaisse | `decimal(10,2)` | non | `≥ 0`, requis à l'ouverture | US-L2-01 |
| | etat | `string(16)` enum `EtatSession` {ouverte, en_fermeture, close} | non | défaut `ouverte` | — |
| | ouvertureLe | `datetime_immutable` | non | — | — |
| | fermetureLe | `datetime_immutable` | oui | `≥ ouvertureLe` (check) | — |
| | etablissement | — | non | FK | `ManyToOne` → `Etablissement` |
| | **Contrainte** | — | — | — | **index unique partiel** « une session active par point de vente » : unique `(pointDeVente_id)` filtré `etat != close` (émulé par index unique sur colonne générée `pdv_actif`, cf. §7 Migrations) — RG-M2-01, CA-1 |
| **MouvementCaisse** | id | `uuid` | non | PK | cahier M2-§4/§8 |
| | session | — | non | FK | `ManyToOne` → `SessionCaisse` |
| | type | `string(16)` enum `TypeMouvement` {entree, sortie, apport, retrait, versement} | non | — | US-L2-10 |
| | montant | `decimal(10,2)` | non | `> 0` | — |
| | motif | `string(255)` | non | requis | — |
| | auteur | — | non | FK | `ManyToOne` → `Utilisateur` |
| | dateHeure | `datetime_immutable` | non | — | — |
| | alerteRegisseur | `boolean` | non | défaut false | true si gros retrait (seuil paramétrable) — cahier M2-§8 |
| **ClotureZ** | id | `uuid` | non | PK | RG-M2-06 |
| | session | — | non | **OneToOne**, unique | `OneToOne` → `SessionCaisse` (fige la session) |
| | comptages | `json` | non | `[{moyen, theorique, compte, ecart}]` | par moyen de paiement — US-L2-10 |
| | totalVentes | `decimal(10,2)` | non | — | totalisé par nature de recette + moyen |
| | totalRemboursements | `decimal(10,2)` | non | — | remboursements/avoirs |
| | versement | `decimal(10,2)` | non | défaut 0 | au comptable |
| | fondReporte | `decimal(10,2)` | non | selon paramétrage | US-L2-10 |
| | ecartTotal | `decimal(10,2)` | non | théorique − compté | US-L2-10 |
| | horodatage | `datetime_immutable` | non | — | irréversible |
| | etatDeRegie | `json`/`text` | non | document archivé, ré-imprimable, exportable | RG-M2-06 |
| | typeCloture | `string(12)` enum `TypeCloture` {Z, mensuelle, annuelle} | non | défaut `Z` | US-L2-11 (⚠ périodiques à cadrer M6) |

> **`MoyenPaiement` n'est PAS une entité M2.** C'est un objet-valeur lu du référentiel M6 via `ReferentielReglementInterface` : `{code, libelle, autoriseRendu:bool, exigeReference:bool, autoriseDiffere:bool}`. En L2, un stub fournit le jeu standard (Espèces `autoriseRendu=true`, CB, Chèque, Virement, Chèques Vacances/Culture/Loisirs, PMV, Avoir, Différé). Le `code` du moyen est **stocké en clair** sur `Paiement` (pas de FK dure vers M6).

### 1.2 `App\Vente\Entity\*` — ticket, règlement, avoir, support

| Entité | Champ | Type Doctrine | Null | Index/Contrainte | Relation |
|---|---|---|---|---|---|
| **Vente** (= Ticket / « Commande ») | id | `uuid` | non | PK | US-L2-01 |
| | numero | `string(32)` | non | **unique** ; séquentiel par session | — |
| | session | — | non | FK | `ManyToOne` → `SessionCaisse` |
| | date | `datetime_immutable` | non | requise | — |
| | client | `uuid` | oui | ref logique **Client (M4)**, pas de FK dure | vente anonyme possible (US-L2-05) |
| | statut | `string(16)` enum `StatutVente` {en_cours, validee, annulee, avoir_emis} | non | défaut `en_cours` | cahier M2-§6 |
| | total | `decimal(10,2)` | non | `≥ 0` | — |
| | totalRemises | `decimal(10,2)` | non | défaut 0 | — |
| | resteAPayer | `decimal(10,2)` | non | `= total − Σ paiements` | RG-M2-03 (calculé, contrôlé à la validation) |
| | origineHorsLigne | `boolean` | non | défaut false | true si créée en mode dégradé (RG-M2-08) |
| | cleIdempotence | `uuid` | non | **unique** | générée côté client, anti-doublon resynchro (§4) |
| | lignes | — | — | cascade persist/remove | `OneToMany` → `LigneVente` |
| | paiements | — | — | cascade persist | `OneToMany` → `Paiement` |
| | supports | — | — | cascade persist | `OneToMany` → `BilletSupport` |
| | **Immuabilité** | — | — | — | après `validee` : aucune modification/suppression de ligne (garde applicative + NF525, RG-M2-07/CA-15) |
| **LigneVente** (LigneCommande) | id | `uuid` | non | PK | — |
| | vente | — | non | FK | `ManyToOne` → `Vente` |
| | produit | `uuid` | non | ref **Produit (M1)**, ref logique | RG-M1-01 |
| | typeTarif | `uuid` | non | ref **TypeTarif (M1)** | — |
| | saison | `uuid` | non | ref **Saison (M1)** résolue au jour | — |
| | quantite | `integer` | non | `≥ 1` | US-L2-03 |
| | prixUnitaire | `decimal(10,2)` | non | issu de `ResolveurPrix` (M1) ; forçable si droit | `vente.forcer_prix` |
| | prixForce | `boolean` | non | défaut false | trace un prix libre (droit requis) |
| | beneficiaire | `uuid` | oui | ref Personne/Client (M4) ; **requis si produit nominatif** | RG-M2-04, CA-5 |
| | remiseLigne | `decimal(10,2)` | oui | remise en € ou % (cf. `remiseType`) | cahier M2-02/03 |
| | remiseType | `string(12)` enum {montant, pourcentage} | oui | — | — |
| | note | `string(255)` | oui | — | — |
| | promotionsAppliquees | `json` | oui | UUID de `Promotion` (M1), auto & visibles | US-L2-03 |
| | montantLigne | `decimal(10,2)` | non | `(prixUnitaire × qté) − remise − promos` | recalcul instantané |
| **Paiement** | id | `uuid` | non | PK | — |
| | vente | — | non | FK | `ManyToOne` → `Vente` |
| | moyenCode | `string(32)` | non | ∈ moyens autorisés du point de vente | RG-M2-02 |
| | montant | `decimal(10,2)` | non | `> 0` | RG-M2-03 |
| | rendu | `decimal(10,2)` | non | défaut 0 ; `> 0` **seulement si** moyen `autoriseRendu` (espèces) | RG-M2-05 / CA-9 |
| | refTPE | `string(64)` | oui | requis si CB/TPE | US-L2-07 |
| | statutTPE | `string(12)` enum `StatutTPE` {accepte, refuse, annule, timeout} | oui | seul `accepte` crée le règlement | US-L2-07 / CA-10 |
| | banque, numeroCheque | `string(64)` | oui | si chèque | cahier M2-04 |
| | differe | `boolean` | non | défaut false | true = justificatif non acquitté (RG-M2-03) |
| | dateHeure | `datetime_immutable` | non | — | journalisation (US-L2-06) |
| **Avoir** | id | `uuid` | non | PK | RG-M2-07, décision actée |
| | numero | `string(32)` | non | **unique** | — |
| | venteOrigine | — | non | FK | `ManyToOne` → `Vente` (traçabilité) |
| | montant | `decimal(10,2)` | non | `> 0` | issu d'annulation/remboursement |
| | motif | `string(255)` | non | requis | RG-M2-07 |
| | auteur | — | non | FK | `ManyToOne` → `Utilisateur` |
| | dateHeure | `datetime_immutable` | non | — | horodaté |
| | supportInvalide | `boolean` | non | défaut false | true si annulation après impression → dévalidation Accès (US-L2-09) |
| **BilletSupport** | id | `uuid` | non | PK | cahier M2-§4 |
| | vente | — | non | FK | `ManyToOne` → `Vente` |
| | ligne | — | oui | FK | `ManyToOne` → `LigneVente` (droit vendu porté) |
| | type | `string(12)` enum `TypeSupport` {billet, carte, qr, bracelet, wallet} | non | — | — |
| | identifiantSupport | `string(128)` | oui | QR/RFID/wallet | appairage module **Accès** (L3) |
| | statutAppairage | `string(12)` enum `StatutAppairage` {en_attente, actif, echec, invalide} | non | défaut `en_attente` | `echec` → remise bloquée & journalisée (CA-12) |
| | nbCompostages | `integer` | oui | hérité du produit M1 (RG-M1-04/13) | décrémenté par Accès (L3) |

### 1.3 `App\Vente\Nf525\Entity\*` — chaînage inaltérable (voir §2)

| Entité | Champ | Type Doctrine | Null | Index/Contrainte | Relation |
|---|---|---|---|---|---|
| **OperationScellee** | id | `uuid` | non | PK | US-L2-11 |
| | pointDeVente | — | non | FK | `ManyToOne` → `PointDeVente` (une chaîne par point de vente) |
| | typeOperation | `string(24)` enum {vente, avoir, cloture_z, cloture_mensuelle, cloture_annuelle} | non | — | — |
| | cibleType, cibleId | `string(64)`, `uuid` | non | polymorphe (Vente/Avoir/ClotureZ) | — |
| | numeroSequence | `bigint` | non | **strictement croissant, sans trou** ; unique `(pointDeVente, numeroSequence)` | rupture détectable (CA-15) |
| | empreinte | `string(128)` | non | hash de `(payload canonique + empreintePrecedente)` | ⚠ procédé cryptographique à confirmer |
| | empreintePrecedente | `string(128)` | oui | null pour la 1ʳᵉ opération (génésis) | maillon N←N-1 |
| | signature | `string(512)` | non | scelle l'opération (algorithme enfichable) | inaltérable |
| | payloadCanonique | `json` | non | données figées de l'opération (montants, moyens…) | rejouable pour recontrôle |
| | horodatage | `datetime_immutable` | non | — | — |
| | **Immuabilité** | — | — | — | **append-only** : aucun PATCH/DELETE (ni API ni ORM ; garde par listener `preUpdate`/`preRemove` → exception) |

---

## 2. NF525 — chaînage inaltérable **enfichable**

Objectif : rendre l'inaltérabilité (US-L2-11 / RG-M2-07) **isolée, testable et remplaçable**, sans figer un procédé cryptographique que seul un référent conformité peut valider (⚠ point ouvert n°1).

- **Port `App\Vente\Nf525\SignataireOperation`** (interface) :
  - `scelle(OperationAScellerDto $op, ?OperationScellee $precedente): OperationScellee` — calcule `empreinte`, reprend `empreintePrecedente`, produit `signature` et `numeroSequence = precedente.numeroSequence + 1`.
  - `verifieChaine(iterable $operations): RapportVerification` — recalcule chaque maillon, détecte trou de séquence / empreinte incohérente / signature invalide → **alerte de contrôle** (CA-15).
- **Implémentation par défaut `App\Vente\Nf525\HashChainSignataire`** :
  - `empreinte = hash('sha256', canonicalize(payload) . '|' . empreintePrecedente)` (chaînage type blockchain léger, maillon N calculé depuis N-1).
  - `signature` = HMAC-SHA256 avec clé de scellement par établissement en L2 (**placeholder** ; le passage à une **signature asymétrique** — clé privée d'établissement, horodatage qualifié — est un simple changement d'implémentation du port).
  - `canonicalize()` : sérialisation déterministe (tri des clés, montants normalisés) pour rendre l'empreinte reproductible et vérifiable.
- **Déclenchement** : un `ScellementHandler` est appelé **dans la même transaction** que la validation d'une `Vente`, l'émission d'un `Avoir` et chaque clôture. Le verrou d'unicité `(pointDeVente, numeroSequence)` sérialise l'attribution de séquence (pas de trou, pas de doublon).
- **Une chaîne par point de vente** (frontière naturelle du poste physique) ; l'ordre hors-ligne est préservé par le rejeu chronologique de la file locale (§4).
- **Immuabilité** : `OperationScellee`, `Vente` validée, `Paiement`, `Avoir`, `ClotureZ` sont protégés en écriture par un listener Doctrine (`preUpdate`/`preRemove` → `OperationInalterableException`) et par l'absence d'opérations `PATCH/DELETE` côté API. Toute correction = **contre-passation** (nouvel `Avoir`/mouvement négatif), jamais suppression.

> ⚠ **À VALIDER PAR UN RÉFÉRENT CONFORMITÉ (ne bloque pas le développement)** : l'algorithme exact de signature (HMAC vs signature asymétrique/certificat), le périmètre de certification (auto-attestation éditeur vs LNE/INFOCERT), la conservation légale (durée, format d'export fiscal probant) et le périmètre/déclenchement des clôtures mensuelle & annuelle restent à arbitrer avec **M6**. Le design **enfichable** garantit que ces choix se traduisent par une nouvelle implémentation de `SignataireOperation` + éventuel export, **sans toucher** au domaine Vente/Caisse.

---

## 3. API (API Platform)

Toutes ressources : `#[ApiResource]`, `security` via le **`PermissionVoter` du socle** → `is_granted('PERM', 'vente.<action>')` ou `'caisse.<action>'`. Lecture cadrée par `ContexteEtablissement` (extension Doctrine du socle, étendue aux entités `App\Vente`/`App\Caisse`). Les mutations qui touchent au domaine (panier, paiement, clôture, avoir) sont des **opérations métier custom** portées par des **State Processors**, pas du CRUD Doctrine brut — pour garder les invariants (reste dû, immuabilité, scellement) dans un handler testable.

| Ressource | Opérations | `security:` | Groupes sérialisation | Type |
|---|---|---|---|---|
| **SessionCaisse** | GET coll/item | `is_granted('PERM','caisse.lire')` ou `vente.lire` | `session:read` | CRUD lecture |
| | `POST /sessions-caisse/ouvrir` | `is_granted('PERM','caisse.ouvrir')` | `session:ouvrir` | **custom** — vérifie unicité session/PDV, fond, code régisseur (CA-1) |
| | `POST /sessions-caisse/{id}/cloturer` | `is_granted('PERM','caisse.cloturer')` | `session:cloture` | **custom** — produit `ClotureZ`, fige la session (CA-14) |
| | `POST /sessions-caisse/{id}/rouvrir` | `is_granted('PERM','caisse.ouvrir')` | — | **custom** — exige code régisseur si caisse `securisee` (CA-2) |
| **MouvementCaisse** | GET coll ; `POST` | `is_granted('PERM','caisse.mouvement')` | `mouvement:read/write` | custom (alerte gros retrait) |
| **ClotureZ** | GET coll/item ; **PDF/ré-impression** `GET /clotures-z/{id}/etat-regie` | `is_granted('PERM','caisse.lire')` | `cloture:read` | lecture seule (irréversible) |
| **Vente** | GET coll/item | `is_granted('PERM','vente.lire')` | `vente:read`, `vente:list` | lecture (filtre par session) |
| | `POST /ventes` (ouvre un panier) | `is_granted('PERM','vente.creer')` | `vente:write` | **custom** — refuse hors session ouverte (CA-1) |
| | `POST /ventes/{id}/lignes` (ajouter) | `is_granted('PERM','vente.creer')` | `ligne:write` | **custom** — prix via `ResolveurPrix` M1, garde bénéficiaire (CA-5) & stock (CA-6), promos auto (CA-4) |
| | `PATCH /ventes/{id}/lignes/{lid}` / `DELETE` | `is_granted('PERM','vente.creer')` | `ligne:write` | **custom** — recalcul instantané ; **interdit** si vente validée |
| | `POST /ventes/{id}/vider` | `is_granted('PERM','vente.creer')` | — | custom (confirmation côté UI, CA-3) |
| | `POST /ventes/{id}/client` | `is_granted('PERM','vente.creer')` | — | custom — rattache/crée client via M4 (CA-7) |
| | `POST /ventes/{id}/paiements` | `is_granted('PERM','vente.encaisser')` | `paiement:write` | **custom** — reste dû, rendu espèces (CA-8/9), TPE (CA-10) |
| | `POST /ventes/{id}/valider` | `is_granted('PERM','vente.encaisser')` | `vente:validation` | **custom** — refuse si reste dû > 0 (sauf différé), décrément stock atomique (§6), **scellement NF525** (§2), impression selon seuil (CA-11), appairage support (CA-12) |
| | `POST /ventes/{id}/annuler` | `is_granted('PERM','vente.annuler')` | `vente:contrepassation` | **custom** — génère `Avoir`, invalide support si après impression (CA-13) |
| | `POST /ventes/{id}/rembourser` | `is_granted('PERM','vente.rembourser')` | `vente:contrepassation` | **custom** — contre-passation, aucun automatique (CA-13) |
| | `POST /ventes/{id}/ticket` (imprimer/renvoyer) | `is_granted('PERM','vente.lire')` | — | custom — e-mail/SMS, duplicata tracé (CA-11) |
| **Avoir** | GET coll/item | `is_granted('PERM','vente.lire')` | `avoir:read` | lecture (créé via annuler/rembourser) |
| **OperationScellee** | GET coll/item ; `POST /nf525/verifier-chaine` | `is_granted('PERM','caisse.lire')` | `nf525:read` | **custom** lecture + `verifieChaine` (CA-15) ; **jamais** POST/PATCH/DELETE d'écriture |
| **File de synchro** | `POST /synchro/operations` (lot idempotent) | `is_granted('PERM','vente.encaisser')` | `sync:write` | **custom** — rejeu chronologique, anti-doublon (§4, CA-16) |

- **Custom vs CRUD** : seules les **lectures** (GET) et `MouvementCaisse` (POST simple) sont proches du CRUD ; **tout le reste est opération métier** (State Processor dédié) car chaque mutation porte un invariant fort (unicité session, reste dû = 0, immuabilité post-validation, scellement, atomicité stock). Aucune écriture directe PATCH/DELETE sur `Vente`/`Paiement`/`Avoir`/`OperationScellee`/`ClotureZ`.
- **Groupes de sérialisation** : UUID exposé ; `vente:read` expose totaux + lignes + signature NF525 (lecture) ; `paiement:write` masque les champs bancaires en lecture selon rôle ; `nf525:read` expose `numeroSequence`/`empreinte`/`signature` sans jamais les rendre inscriptibles.

---

## 4. Mode dégradé hors-ligne & resynchronisation (RG-M2-08 / US-L2-12 / CA-16)

Conception (le poste de caisse — future UI/borne — embarque une file locale ; l'API expose l'endpoint de remontée).

- **File d'opérations idempotentes** — chaque opération de vente (ouvrir panier, ajouter ligne, payer, valider) est un **message idempotent** identifié par une **clé d'idempotence générée côté client** (`cleIdempotence` = UUID, idéalement **UUIDv7** pour l'ordre chronologique). Une même clé rejouée n'a **aucun effet** (contrainte unique `Vente.cleIdempotence` + `INSERT … ON CONFLICT DO NOTHING` applicatif) → « une seule remontée par session » (RG-M2-08).
- **Identifiants côté client** — les UUID des entités (`Vente`, `LigneVente`, `Paiement`) sont **générés localement** dès le mode dégradé ; le serveur les **accepte tels quels** (pas de renumérotation), ce qui rend la remontée naturellement idempotente et préserve les références croisées.
- **Chaînage NF525 maintenu hors-ligne** — le poste possède sa **chaîne locale par point de vente** (même `SignataireOperation`), donc chaque ticket hors-ligne est déjà scellé et chaîné (US-L2-12). Au retour réseau, `POST /synchro/operations` remonte le **lot ordonné par `numeroSequence`** ; le serveur **vérifie la continuité** (`verifieChaine`) avant d'ancrer les maillons dans la chaîne serveur du même point de vente.
- **Rejeu chronologique** — les opérations sont rejouées dans l'ordre de `numeroSequence` (croissant) ; un trou → rejet du lot avec rapport (alerte de contrôle), pas d'insertion partielle silencieuse.
- **Résolution de conflits** :
  - *Doublon* (clé déjà remontée) → ignoré, compté comme succès idempotent.
  - *Conflit de stock découvert au retour réseau* (produit épuisé entre-temps sur pool partagé M1) → la vente hors-ligne **n'est pas rejetée** (elle est fiscalement scellée) mais génère un **incident de stock** (survente signalée au régisseur, à régulariser) — ⚠ conduite exacte à arbitrer M1/M3 (point ouvert n°6).
  - *Reste dû / paiement incohérent* → l'opération est mise en **quarantaine** (statut de synchro `en_erreur`) sans casser la chaîne, remontée au régisseur.
- **Indicateur d'état** — l'API expose `GET /synchro/etat` (en ligne / dégradé / synchro en cours) consommé par l'UI en permanence (US-L2-12). *Note : la bascule réseau et le stockage local relèvent de l'UI/poste (hors périmètre API strict), noté en risque.*

---

## 5. Sécurité & droits

- **Permissions requises** (modules `vente` et `caisse`) :
  - `vente.lire`, `vente.creer`, `vente.encaisser`, `vente.annuler`, `vente.rembourser`, `vente.forcer_prix`.
  - `caisse.lire`, `caisse.ouvrir`, `caisse.cloturer`, `caisse.mouvement`, `caisse.gerer` (surensemble admin : PDV, caisses, périphériques, seuils, moyens autorisés).
  - Réutilise `offre.lire` (socle/M1) pour lire prix & stock au guichet.
- **Voter** : **aucun voter nouveau** — réutilise `PermissionVoter` du socle (attribut `PERM`, sujet `"vente.action"`/`"caisse.action"`, RG-SOCLE-04). Le `caisse.gerer` couvre les actions `caisse.*` (résolution dans le Voter, non dupliquée). Le mapping Permission/Role est posé par **migration de données** (§7).
- **Cadrage établissement** : `ContexteEtablissement` (en-tête `X-Etablissement`) ; l'extension Doctrine du socle filtre `SessionCaisse`/`Vente`/… sur les établissements affectés (RG-SOCLE-05 ; cas limite « utilisateur sans affectation → aucun accès »).
- **Séparation des devoirs (explicite, spec §3)** : l'agent (`vente.creer/encaisser`) **ne peut pas** ouvrir/clôturer (`caisse.ouvrir/cloturer`) ni rembourser/annuler après impression (`vente.rembourser/annuler`) — ces attributs sont portés par le Régisseur/Responsable.
- ⚠ **HYPOTHÈSE (point ouvert n°2/3)** : les **noms** de permissions dérivent du tableau Acteurs & droits selon le modèle socle mais ne sont pas nommés littéralement dans les sources ; le rattachement `vente.rembourser` au Régisseur *ou* au Responsable est un choix de **paramétrage de rôle** — **arbitrage/découpage fin à figer avec M8**. La séparation « agent ≠ remboursement/annulation après impression » est, elle, explicite.

---

## 6. Décrément de stock atomique — survente concurrente (point ouvert n°7)

Le décrément de stock/compostages M1 (RG-M1-10) intervient **à la validation du paiement** (`POST /ventes/{id}/valider`), dans la **transaction de scellement** :

- **Stock dédié** (`Stock.disponibilite`) et **pool partagé** (`Pool.disponibilite`) sont décrémentés par un **UPDATE conditionnel atomique** :
  `UPDATE offre_pool SET disponibilite = disponibilite - :q WHERE id = :id AND disponibilite >= :q` — 0 ligne affectée ⇒ rupture ⇒ validation refusée (422 « stock épuisé »), aucun ticket scellé.
- Ceci évite le *read-modify-write* concurrent inter-caisses (et avec la vente en ligne M3) **sans verrou long** ; le décrément mutualisé du pool M1 est ainsi sérialisé au niveau ligne SQL.
- Alternative documentée : verrou pessimiste `SELECT … FOR UPDATE` sur la ligne de pool si des règles de réservation multi-lignes s'ajoutent — retenu seulement si l'UPDATE conditionnel ne suffit plus.
- Le **mode hors-ligne** ne peut garantir l'atomicité globale : le décrément local est optimiste et **réconcilié à la resynchro** (§4), d'où l'incident de survente possible signalé, à arbitrer M1/M3.

---

## 7. Migrations

- **Migration structurelle** `VersionM2_vente_caisse` : tables `caisse_point_de_vente`, `caisse_caisse`, `caisse_session`, `caisse_mouvement`, `caisse_cloture_z`, `vente_vente`, `vente_ligne`, `vente_paiement`, `vente_avoir`, `vente_billet_support`, `nf525_operation_scellee`.
  - **Index/contraintes** : unique `Vente.numero`, `Vente.cleIdempotence`, `Avoir.numero`, `SessionCaisse.numero` ; unique `(pointDeVente, numeroSequence)` sur `OperationScellee` ; **unicité session active/PDV** via colonne générée `pdv_actif` (= `pointDeVente_id` si `etat != close`, sinon NULL) + index unique (MariaDB : colonne persistée + index) ; checks `fondDeCaisse ≥ 0`, `seuilImpression ≥ 0`, `Paiement.montant > 0`, `quantite ≥ 1`, `fermetureLe ≥ ouvertureLe`. FK vers `etablissement`/`utilisateur` (socle) — **suppose migrations socle L0 + M1 jouées d'abord** (dépendance d'ordre).
- **Migration de données** `VersionM2_permissions` : insère `Permission(module='vente', action ∈ {lire,creer,encaisser,annuler,rembourser,forcer_prix})` et `Permission(module='caisse', action ∈ {lire,ouvrir,cloturer,mouvement,gerer})`.
- **Migration de données** `VersionM2_moyens_paiement_defaut` : jeu **par défaut** de moyens de paiement (Espèces `autoriseRendu=true`, CB, Chèque, Virement, Chèques Vacances/Culture/Loisirs, PMV, Avoir, Différé) — donnée de **secours L2** en attendant le référentiel M6 (via `ReferentielReglementInterface`).
- Rejouables, versionnées Doctrine ; jamais de `schema:update --force` (constitution §7).

---

## 8. Tests (PHPUnit + ApiTestCase)

| Test | Type | Couvre |
|---|---|---|
| Vente hors session refusée ; ouverture mémorise PDV+fond+régisseur+code ; **une seule** session active/PDV | API | CA-1, RG-M2-01 |
| Réouverture caisse `securisee` exige le code régisseur | API | CA-2 |
| Écran caisse : recherche libellé/code/code-barres, panier + total temps réel, ligne modifiable/supprimable, « vider » confirmé | API + Unit | CA-3, US-L2-02 |
| Ajout ligne : prix = grille M1 (tarif×saison), promos auto visibles, recalcul immédiat, qté ≥ 1 | API + Unit (via `ResolveurPrix`) | CA-4 |
| Bénéficiaire requis : ajout refusé sans bénéficiaire sur produit nominatif | API | CA-5, RG-M2-04 |
| Stock 0 → grisé/non ajoutable (« stock épuisé ») ; produit non géré jamais bloqué | API | CA-6 |
| Rattachement client (nom/e-mail/n° compte) + création rapide ; vente anonyme possible | API | CA-7 |
| Paiement scindé : reste dû décroît, validation **seulement** à reste = 0 (sauf différé), chaque moyen journalisé | API | CA-8, RG-M2-03 |
| Rendu de monnaie : calculé sur espèces si > dû ; **aucun** rendu sur CB/chèque/chèque-vacances | Unit + API | CA-9, RG-M2-05 |
| TPE : montant envoyé auto ; `accepte` crée règlement + réfTPE ; `refuse/timeout` n'ajoute rien, reste dû inchangé | API (adaptateur TPE mocké) | CA-10, US-L2-07 |
| Seuil d'impression : > seuil → impression auto ; ≤ seuil → à la demande + renvoi e-mail/SMS proposé | API | CA-11 |
| Appairage support : actif à la validation ; échec → remise bloquée + journalisée | API | CA-12, RG-M2-04 |
| Annulation/remboursement : habilité seulement, horodaté/motivé/opérateur/vente origine, **contre-passation** (aucune suppression), annulation→avoir, après impression→support invalidé ; non habilité refusé (403) | API | CA-13, RG-M2-07 |
| Clôture Z : totalise ventes/moyens/remboursements, écart théorique vs compté, état de régie archivé/ré-imprimable, fige la session, refus si paiements incohérents, fond reporté/repris | API | CA-14, RG-M2-06 |
| NF525 : chaque opération signée + chaînée ; modif/suppression impossible ; **rupture de chaîne → alerte** (`verifieChaine`) | Unit (`HashChainSignataire`) + API | CA-15, US-L2-11 |
| Hors-ligne : ventes locales chaînées/inaltérables ; resynchro sans doublon (clé idempotence rejouée = no-op) ni perte, une seule remontée/session ; indicateur d'état | Unit + API | CA-16, RG-M2-08 |
| Décrément stock atomique : deux validations concurrentes sur pool à 1 → une réussit, l'autre 422 | API (concurrence) | RG-M1-10 (survente), §6 |
| Cloisonnement : utilisateur sans affectation sur l'étab du PDV → 403/absent | API | RG-SOCLE-05 (socle réutilisé) |
| Immuabilité ORM : `preUpdate`/`preRemove` sur entités scellées → exception | Unit | US-L2-11 |

---

## 9. Tâches (voir tasks-vente.md)

T1 enums → T2 entités Caisse → T3 entités Vente + support → T4 NF525 (port + hash-chain + immuabilité) → T5 ports M6/M4/Accès → T6 résolveur panier (prix M1, bénéficiaire, stock, promos) → T7 paiement/TPE/rendu → T8 validation+scellement+stock atomique → T9 clôture Z & mouvements → T10 avoir/annulation/remboursement → T11 hors-ligne/resynchro → T12 API+sérialisation+droits → T13 migrations → T14 tests. (ordonnées, cf. fichier tasks.)

---

## 10. Risques / à valider

1. **⚠ NF525 (priorité haute)** — algorithme de signature (HMAC vs asymétrique/certificat), **périmètre de certification** (auto-attestation vs LNE/INFOCERT), conservation légale (durée, export fiscal probant), déclenchement/périmètre des **clôtures mensuelle & annuelle**. Design **enfichable** (`SignataireOperation`) pour ne pas bloquer ; **arbitrage M6 + référent conformité avant mise en production** (§2, §4.8 spec).
2. **⚠ Noms des permissions** `vente.*`/`caisse.*` dérivés du modèle socle, non littéraux dans les sources → **figer avec M8** (§5).
3. **⚠ Rôles Régisseur vs Responsable** (remboursement) → rattaché à `vente.rembourser` attribuable par paramétrage, **arbitrage M8** (§5).
4. **⚠ Défaut du seuil d'impression = 0 €** (impression systématique tant que non paramétré) — **à confirmer** métier (§1.1).
5. **⚠ Échec d'appairage support** — conduite à tenir (ré-essai / support de secours / ticket seul) **à préciser avec module Accès (L3)** (CA-12).
6. **⚠ Idempotence & conflits de resynchro** — clé d'idempotence par ticket retenue ; **conflit de stock découvert au retour réseau** (survente pool M1) signalé comme incident, conduite exacte **à arbitrer M1/M3** (§4).
7. **⚠ Décrément stock partagé (pool M1)** — UPDATE conditionnel atomique retenu (§6) ; à valider sous charge inter-caisses + M3.
8. **⚠ Click & Pay** (écran M2-02) — rattaché à **M3**, hors périmètre L2 (aucune US-L2).
9. **Périmètre API vs poste** — bascule réseau, stockage local hors-ligne et impression physique relèvent de l'UI/poste ; l'API expose file de synchro, état et endpoints ticket. Frontière à confirmer à l'intégration.
10. **`MoyenPaiement`/acte de régie** — stub L2 (`ReferentielReglementInterface`) ; câblage réel au **référentiel M6** à l'intégration L4.
