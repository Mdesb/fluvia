# Plan technique — Autorisations graduées des opérations sensibles (`App\Autorisation`)

- **Spec source :** specs/autorisation/spec-autorisation.md
- **Stack :** Symfony 7 · API Platform · Doctrine/MariaDB
- **Couvre :** US-AUTZ-01 à 09 · RG-AUTZ-01 à 13 · CA-1 à CA-9 (⚠ US/RG créées par la spec, absentes du
  backlog/cahier — cf. réserve en tête de `spec-autorisation.md`, rappelée au §10 Risques)

> **Réutilisation socle (à ne pas dupliquer, aucune modification)** — `App\Securite\Entity\{Permission,
> Role,Affectation,DelegationDroit,Utilisateur}` ; `App\Securite\Security\PermissionVoter` (attribut
> `PERM`) ; `App\Securite\Service\{CalculateurDroits,ContexteEtablissement}` ; `App\Securite\Service\
> VerificateurPlafondDroits` (référence de patron pour la garde de configuration, §5.2, code non touché) ;
> `App\Audit\Entity\EntreeAudit` + `App\Audit\Service\JournalAudit` (append-only, RG-M8-05) ;
> `App\Organisation\Entity\Etablissement`.
>
> **Réutilisation M2 Vente & Caisse (code réel, modification additive minimale uniquement)** —
> `App\Vente\State\{AnnulerVenteProcessor,RembourserVenteProcessor}` (insertion d'un appel avant
> exécution, §6) ; `App\Vente\Service\ContrePassationHandler` **non modifié** ; `App\Caisse\Entity\
> SessionCaisse` (lecture seule, `getOperateur()`/`getRegisseur()`, pour le périmètre `propre_session`) ;
> `App\Caisse\State\RouvrirCaisseProcessor::codeRegisseur` **non modifié** (précédent jugé insuffisant par
> la spec, référencé au catalogue mais non câblé v1).
>
> **Extension additive du socle Audit** — `App\Audit\Doctrine\AuditWriteSubscriber::CLASSES_SURVEILLEES`
> reçoit `LimiteAutorisation` et `OperationSensible` (même patron que tous les modules précédents, T9) ;
> `DemandeEscalade` **n'y est volontairement pas ajoutée** (son cycle de vie est tracé par des appels
> explicites `JournalAudit::enregistrer()` à sémantique métier — `escalade.creee`/`escalade.approuvee`/
> `escalade.rejetee`/`escalade.expiree` — RG-AUTZ-08 littéral ; l'ajouter aussi au listener générique
> produirait un doublon d'entrées pour le même événement).

---

## 0. Décisions structurantes (résumé)

1. **`OperationSensible.code` est la clé primaire (string, pas UUID)** — dérogation mineure et assumée à
   la règle constitution §3 « id = UUID » : le principe visé par cette règle (pas d'auto-incrément
   exposé) reste respecté (`code` est une clé métier stable, jamais un compteur), et RG-AUTZ-01 exige
   explicitement une « clé stable » référencée directement par `LimiteAutorisation.operation` et
   `DemandeEscalade.operation` (FK sur `code`, littéralement demandé par la spec §5). Toutes les autres
   entités du module (`LimiteAutorisation`, `DemandeEscalade`) gardent un `id` UUID classique. Signalé au
   Risque n°9.
2. **Point d'insertion = les deux `Processor` M2, `ContrePassationHandler` non modifié.** `App\Vente\
   Service\ContrePassationHandler` reste inchangé (aucune dépendance vers `App\Autorisation`, aucun
   couplage inverse M2→module transverse au niveau du service métier). `ServiceAutorisation::evaluer()`
   est appelé **dans le Processor**, avant l'appel à `$this->handler->annuler()`/`rembourser()` — si la
   décision n'est pas `AUTORISE`, le handler n'est **jamais invoqué** (§6).
3. **Rétrocompatibilité stricte par construction, pas par test défensif.** Quand aucune
   `LimiteAutorisation` n'est trouvée pour l'opération, `ServiceAutorisation::evaluer()` retourne
   `AUTORISE` **immédiatement**, sans écriture, sans flush, avant tout autre calcul (périmètre, montant).
   Le chemin d'exécution du Processor pour un client qui ne configure rien est donc **rigoureusement
   identique** au code actuel (même nombre de requêtes ajoutées : une seule lecture `LimiteAutorisation`
   en plus, aucune écriture) — CA-6.
4. **Flush ciblé côté service, uniquement pour les décisions bloquantes.** `ServiceAutorisation` persiste
   et **flush lui-même** les effets de bord (`DemandeEscalade` créée, `EntreeAudit` de refus) **seulement**
   quand la décision est `REFUSE` ou `ESCALADE_REQUISE` — dans ces deux cas, le Processor interrompt de
   toute façon l'exécution (retour 403 immédiat) et n'atteindra jamais son propre `$em->flush()` final :
   sans ce flush interne, les effets de bord seraient perdus. Pour `AUTORISE`, **aucun flush** n'est
   déclenché par le service : le comportement transactionnel actuel (un seul `flush()` en fin de
   Processor, portant l'`Avoir`) reste **strictement inchangé**.
5. **`jeton` (rejeu) distinct de `id` (PK interne).** La spec mentionne à la fois un corps `403` avec
   `"demandeEscalade": "<uuid>"` (§4.6) et un champ `jeton` dédié au tableau §5 (« référence à fournir
   lors du rejeu »). Ce plan tranche : le corps `403` renvoie la valeur de `DemandeEscalade.jeton` (pas
   `id`) sous la clé `demandeEscalade` ; `id` reste la clé primaire interne utilisée par les endpoints
   superviseur (`/demandes-escalade/{id}/approuver`). Signalé au Risque n°5.
6. **Champ additif `dateRejeu` (hors tableau §5 spec), pour empêcher le rejeu multiple d'un jeton
   approuvé.** La spec ne précise pas explicitement si un jeton approuvé est à usage unique. Ce plan
   ajoute `DemandeEscalade.dateRejeu` (nullable, renseigné à la première exécution réussie du rejeu) :
   une deuxième tentative avec le même jeton est refusée (409). Sans cela, un remboursement partiel
   approuvé une fois pourrait être rejoué indéfiniment au même montant — signalé au Risque n°6.
7. **`CalculateurDroits` n'est pas modifié** : la résolution des rôles effectifs d'un utilisateur pour
   `LimiteAutorisation` (RG-AUTZ-03/12, y compris délégations actives) est faite par un nouveau service
   `ResolveurLimiteAutorisation` qui interroge directement `Affectation`/`DelegationDroit` (même requêtes
   que `CalculateurDroits`, dupliquées volontairement pour ne pas coupler `App\Securite` à un besoin
   propre à `App\Autorisation` — même logique de non-couplage que `PerimetreVenteExtension` qui ne
   modifie pas `CalculateurDroits` pour son propre cloisonnement).

---

## 1. Entités & schéma

Namespace : `App\Autorisation\Entity\*` (+ `App\Autorisation\Enum\*`, `App\Autorisation\Service\*`,
`App\Autorisation\Doctrine\*`, `App\Autorisation\State\*`, `App\Autorisation\Command\*`).
`declare(strict_types=1)` partout. Noms métier en français.

**Enums (`App\Autorisation\Enum\*`)** :
- `PerimetreAutorisation: string` — `PropreSession = 'propre_session'`, `PropreEtablissement =
  'propre_etablissement'`, `Global = 'global'` (RG-AUTZ-05).
- `StatutEscalade: string` — `EnAttente = 'en_attente'`, `Approuvee = 'approuvee'`, `Rejetee =
  'rejetee'`, `Expiree = 'expiree'` (RG-AUTZ-06/07).
- `ResultatDecision: string` — `Autorise = 'autorise'`, `Refuse = 'refuse'`, `EscaladeRequise =
  'escalade_requise'` (non persistée en tant que colonne — valeur de retour de `Decision`).

| Entité (`App\Autorisation\Entity\*`) | Champ | Type Doctrine | Null | Index/Contrainte | Notes |
|---|---|---|---|---|---|
| **OperationSensible** (`atz_operation_sensible`) | code | `string(80)`, **PK** | non | unique (PK) | RG-AUTZ-01, §0 n°1 |
| | libelle | `string(180)` | non | — | affichage back-office |
| | moduleAction | `string(120)` | non | format `module.action` (`Assert\Regex`) | référence **logique** (pas de FK stricte) vers `Permission.getCode()` — RG-AUTZ-01 : reste paramétrable indépendamment de l'ordre de création des `Permission` |
| | active | `bool` | non, défaut `true` | — | désactivable sans suppression |
| **LimiteAutorisation** (`atz_limite_autorisation`) | id | `uuid`, PK | non | — | RG-AUTZ-02 |
| | operation | `ManyToOne` → `OperationSensible` (FK `code`) | non | index | RG-AUTZ-01 |
| | role | `ManyToOne` → `App\Securite\Entity\Role`, nullable | oui | **exclusif** avec `utilisateur` | RG-AUTZ-02 |
| | utilisateur | `ManyToOne` → `App\Securite\Entity\Utilisateur`, nullable | oui | **exclusif** avec `role` | RG-AUTZ-02 |
| | etablissement | `ManyToOne` → `Etablissement` | non | index | pas d'héritage hiérarchique (même écart assumé que RG-M8-02) |
| | plafondMontant | `decimal(10,2)`, nullable | oui | `null` = illimité | comparaison **inclusive** (`≤`) |
| | perimetre | `string(20)`, `enumType: PerimetreAutorisation` | non | — | RG-AUTZ-05 |
| | escaladeAuDela | `bool` | non, défaut `false` | — | RG-AUTZ-04 |
| | cumulJournalierMax | `decimal(10,2)`, nullable | oui | **réservé, non exploité v1** | RG-AUTZ-11 |
| | dateCreation | `datetime_immutable` | non | — | traçabilité (aussi via `AuditWriteSubscriber`, §0 en-tête) |
| | auteur | `ManyToOne` → `Utilisateur` | non | — | qui a configuré la limite |
| **DemandeEscalade** (`atz_demande_escalade`) | id | `uuid`, PK | non | — | RG-AUTZ-06 |
| | operation | `ManyToOne` → `OperationSensible` | non | index | — |
| | cibleType | `string(60)` | non | — | ex. `Vente` (même patron que `EntreeAudit.cibleType`) |
| | cibleId | `string(36)` | non | index (avec `operation`) | ex. id de la `Vente`, même patron que `EntreeAudit.cibleId`/`MouvementStock.referenceId` |
| | montant | `decimal(10,2)` | non | — | montant de l'opération évaluée |
| | auteur | `ManyToOne` → `Utilisateur` | non | index | le caissier demandeur |
| | etablissement | `ManyToOne` → `Etablissement` | non | index | borne les superviseurs éligibles |
| | statut | `string(12)`, `enumType: StatutEscalade` | non, défaut `EnAttente` | index (avec `etablissement`) | RG-AUTZ-06/07 |
| | dateDemande | `datetime_immutable` | non | — | — |
| | dateExpiration | `datetime_immutable` | non | index (avec `statut`, requête de la commande d'expiration) | calculée à la création (défaut 15 min, §3) |
| | superviseur | `ManyToOne` → `Utilisateur`, nullable | oui | ne peut être `== auteur` (garde applicative, RG-AUTZ-13) | renseigné au traitement |
| | dateTraitement | `datetime_immutable`, nullable | oui | — | — |
| | motifRejet | `string(255)`, nullable | oui | requis si `statut = rejetee` (garde applicative) | — |
| | jeton | `uuid`, **unique** | non | index unique | §0 n°5 — identifiant externe du rejeu, distinct de `id` |
| | dateRejeu | `datetime_immutable`, nullable | oui | — | §0 n°6, additif hors tableau spec §5 |

> **Non persisté** — `App\Autorisation\Service\Decision` (readonly, value object) : `resultat:
> ResultatDecision`, `motif: string`, `limiteAppliquee: ?LimiteAutorisation`, `demandeEscalade:
> ?DemandeEscalade` — valeur de retour de `ServiceAutorisation::evaluer()` (§2).

**Garde applicative (pas de contrainte SQL native)** — « au plus une `LimiteAutorisation` par
`(operation, role|utilisateur, etablissement)` » : MariaDB traite `NULL` comme toujours distinct dans un
index unique, donc une contrainte SQL sur `(operation_code, role_id, utilisateur_id, etablissement_id)`
ne détecterait pas les doublons quand l'une des deux colonnes cible est `NULL`. Le contrôle est fait dans
`LimiteAutorisationProcessor` (requête d'existence avant `persist`, 422 si doublon) — même patron que
`CatalogueFournisseur.principal` (stock, `PrincipalUniqueValidator`). Risque de course concurrente rare
documenté au Risque n°4 (pas de verrou pessimiste, contrairement à `GardeDernierAdministrateur`).

**Cloisonnement multi-entités** : `App\Autorisation\Doctrine\PerimetreAutorisationExtension`
(`QueryCollectionExtensionInterface`/`QueryItemExtensionInterface`, même patron exact que
`PerimetreVenteExtension`) restreint `LimiteAutorisation` et `DemandeEscalade` par jointure `Affectation`
sur `{root}.etablissement` (RG-SOCLE-05). `OperationSensible` n'est **pas** cloisonnée (catalogue global,
pas de champ `etablissement` — conforme au tableau §5 de la spec).

---

## 2. Service de décision centrale — `App\Autorisation\Service\ServiceAutorisation`

### 2.1 Contrat

```php
final readonly class RequeteAutorisation
{
    public function __construct(
        public string $operationCode,          // ex. 'vente.annuler'
        public Utilisateur $utilisateur,        // demandeur courant
        public string $montant,                 // montant de l'opération évaluée (chaîne décimale)
        public string $cibleType,                // ex. 'Vente'
        public Uuid $cibleId,
        public Uuid $cibleEtablissementId,
        public ?Uuid $cibleSessionOperateurId = null,  // SessionCaisse::getOperateur()->getId(), périmètre propre_session
        public ?Uuid $cibleSessionRegisseurId = null,  // SessionCaisse::getRegisseur()->getId()
        public ?Uuid $jetonRejeu = null,         // fourni par le Processor si le corps contient "demandeEscalade"
    ) {}
}

final readonly class Decision
{
    public function __construct(
        public ResultatDecision $resultat,
        public string $motif,
        public ?LimiteAutorisation $limiteAppliquee = null,
        public ?DemandeEscalade $demandeEscalade = null,
    ) {}
}

final class ServiceAutorisation
{
    public function evaluer(RequeteAutorisation $requete): Decision;
}
```

Le module M2 (ou tout futur appelant du catalogue extensible §8) construit la `RequeteAutorisation` à
partir de ses propres entités **sans que `App\Autorisation` ne dépende de `App\Vente`/`App\Caisse`** :
l'appelant transmet des scalaires (`Uuid`, `string`) déjà extraits — aucune entité `Vente`/`SessionCaisse`
n'entre dans `App\Autorisation`.

### 2.2 Algorithme (`evaluer()`, RG-AUTZ-04)

```
SI $requete->jetonRejeu !== null
    → evaluerRejeu($requete)                                    // §2.4, RG-AUTZ-06

1. Droit binaire — is_granted('PERM', $requete->operationCode)  // défensif : déjà garanti par le
                                                                  // `security:` de l'opération API Platform
                                                                  // qui a exécuté le Processor appelant ;
                                                                  // revérifié ici pour un usage isolé/test
   SI absent → REFUSE (motif « droit non accordé »)

2. $operation = OperationSensible[$requete->operationCode] (repository ; absente/inactive → traité
   comme "aucune limite possible", va directement à 3.)
   $limite = ResolveurLimiteAutorisation::resoudre($operation, $requete->utilisateur,
             ContexteEtablissement::idActif())                   // RG-AUTZ-03

   SI $limite === null → AUTORISE (RG-AUTZ-09, §0 n°3 — aucune écriture, retour immédiat)

3. Périmètre (RG-AUTZ-05) :
   SI NON perimetreRespecte($limite, $requete) → REFUSE (motif « hors périmètre »)
                                                    + audit refus (persist + flush, §0 n°4)

4. Montant vs plafond (borne inclusive) :
   SI $limite->plafondMontant === null OU $requete->montant ≤ $limite->plafondMontant → AUTORISE
   SINON SI $limite->escaladeAuDela === false → REFUSE (motif « dépassement, aucune escalade possible »)
                                                   + audit refus (persist + flush)
   SINON → crée DemandeEscalade(en_attente) + audit création (persist + flush)
           → ESCALADE_REQUISE
```

- **`perimetreRespecte()`** — `PropreSession` : `utilisateur.id ∈ {cibleSessionOperateurId,
  cibleSessionRegisseurId}` ; `PropreEtablissement` : `cibleEtablissementId ==
  ContexteEtablissement::idActif()` ; `Global` : toujours vrai (le cloisonnement établissement est déjà
  assuré en amont par la récupération de la cible elle-même, RG-SOCLE-05).
- **Comparaison décimale** — `bccomp($requete->montant, $limite->plafondMontant, 2) <= 0` (jamais de
  comparaison flottante sur des montants).

### 2.3 `ResolveurLimiteAutorisation::resoudre()` — priorité RG-AUTZ-03

1. Cherche une `LimiteAutorisation` `utilisateur = $utilisateur AND operation = $operation AND
   etablissement = $etablissementActif` → si trouvée, **retour immédiat** (prévaut sur tout rôle).
2. Sinon, cherche toutes les `LimiteAutorisation` `role IN (rôles effectifs de l'utilisateur sur
   $etablissementActif)` (Affectation + `DelegationDroit` actives, RG-AUTZ-12 — requête directe,
   dupliquée volontairement de `CalculateurDroits`, §0 n°7) `AND operation = $operation AND
   etablissement = $etablissementActif`.
3. S'il y en a plusieurs, retient **la plus restrictive** : tri par `(plafondMontant ?? +∞) ASC`, puis à
   égalité par `rang(perimetre)` ASC avec `propre_session=0 < propre_etablissement=1 < global=2` — ⚠
   HYPOTHÈSE d'implémentation du critère de priorité, RG-AUTZ-03 ne détaille pas l'ordre exact entre
   « plafond le plus bas » et « périmètre le plus étroit » quand ils divergent entre deux limites (ex.
   limite A : plafond 200 €/périmètre global, limite B : plafond 300 €/périmètre propre_session) — ce
   plan retient le plafond comme critère primaire (impact financier direct), le périmètre en
   départage. Signalé au Risque n°3.
4. Aucune limite trouvée → `null`.

### 2.4 Rejeu d'une demande approuvée (`evaluerRejeu()`, RG-AUTZ-06)

```
$demande = DemandeEscalade[jeton = $requete->jetonRejeu]
SI absente → REFUSE (motif « jeton inconnu »)
SI $demande->statut !== Approuvee → REFUSE (motif « demande non approuvée » — couvre rejetée/expirée/en attente)
SI $demande->dateRejeu !== null → REFUSE 409 (motif « demande déjà utilisée », §0 n°6)
SI $demande->operation->code !== $requete->operationCode
   OU $demande->cibleType !== $requete->cibleType OU $demande->cibleId !== (string) $requete->cibleId
   OU bccomp($demande->montant, $requete->montant, 2) !== 0
   OU $demande->auteur !== $requete->utilisateur
   → REFUSE (motif « la demande approuvée ne correspond pas à cette exécution »)         // §4.6 spec

$demande->dateRejeu = maintenant
audit "escalade.rejouee" (persist, PAS de flush ici — §0 n°4 : c'est une AUTORISE, flush délégué au Processor)
→ AUTORISE (sans re-comparaison au plafond — le superviseur a statué), demandeEscalade = $demande
```

---

## 3. Flux d'escalade superviseur — `App\Autorisation\Service\GestionnaireEscalade`

```php
final class GestionnaireEscalade
{
    public function creer(OperationSensible $operation, RequeteAutorisation $requete, LimiteAutorisation $limite): DemandeEscalade;
    public function approuver(DemandeEscalade $demande, Utilisateur $superviseur): void;   // RG-AUTZ-13
    public function rejeter(DemandeEscalade $demande, Utilisateur $superviseur, ?string $motif): void;
}
```

- **`creer()`** — persiste une `DemandeEscalade(statut: EnAttente, jeton: Uuid::v4(), dateExpiration:
  maintenant + délai)`, journalise `escalade.creee` (`JournalAudit`, `valeurApres` = opération/montant/
  auteur). Délai par défaut **15 minutes**, paramétrable (`autorisation.delai_expiration_escalade`, bundle
  de configuration Symfony — même mécanisme que les paramètres de délai déjà présents ailleurs),
  RG-AUTZ-07.
- **`approuver()`** — garde-fous, tous en 403/409/422 explicites :
  - `$demande->statut !== EnAttente` (ou expirée entre-temps, double garde `estEnAttenteMaintenant()`
    même patron que `DelegationDroit::estActiveMaintenant()`) → refus.
  - `$superviseur === $demande->auteur` → **refus, RG-AUTZ-13** (séparation des tâches, message explicite).
  - Statut → `Approuvee`, `superviseur`, `dateTraitement` = maintenant.
  - Journalise `escalade.approuvee` (`valeurApres = {"statut": "approuvee", "superviseur": "<email>"}`,
    RG-AUTZ-08).
- **`rejeter()`** — mêmes gardes statut/auto-approbation, `motifRejet` **requis** (422 sinon), statut →
  `Rejetee`, journalise `escalade.rejetee`.
- **Aucun `flush()`** dans ce service (convention constante du dépôt, ex. `App\Caution\Service\
  GestionCaution`) : les Processors/`ServiceAutorisation` appelants portent la transaction.

### 3.1 Expiration automatique — `App\Autorisation\Command\ExpirerEscaladesCommand`

`autorisation:escalades:expirer` — même patron exact que `securite:delegations:expirer`
(`ExpirerDelegationsCommand`) : sélectionne les `DemandeEscalade` `statut = en_attente AND
dateExpiration < maintenant`, passe `statut = Expiree`, journalise `escalade.expiree` pour chacune,
`flush()` unique en fin de commande. Idempotente, sans effet de bord si aucune ligne. À planifier via cron
externe (RG-AUTZ-07).

---

## 4. API Platform

Toutes ressources `#[ApiResource]`, `security:` via `is_granted('PERM', 'autorisation.<action>')`.

| Ressource | Opérations | `security:` | Groupes sérialisation | Filtres |
|---|---|---|---|---|
| **OperationSensible** | GetCollection, Get | `autorisation.lire` ou `autorisation.gerer` | `operation_sensible:read` | `ApiFilter(BooleanFilter)` `active` |
| | Post, Patch | `autorisation.gerer` | `operation_sensible:write` | pas de `Delete` (désactivation via `active`) |
| **LimiteAutorisation** | GetCollection, Get | `autorisation.lire` ou `autorisation.gerer` | `limite:read` | `ApiFilter(SearchFilter)` `operation`, `role`, `utilisateur`, `etablissement` (exact) |
| | Post, Patch, Delete | `autorisation.gerer` | `limite:write` | processor : `LimiteAutorisationProcessor` (§4.1) |
| **DemandeEscalade** | GetCollection | `autorisation.approuver` ou `autorisation.lire` | `escalade:read` | `ApiFilter(SearchFilter)` `statut`, `operation`, `etablissement`, `auteur` (exact) — file d'attente superviseur |
| | Get | `autorisation.approuver` ou `autorisation.lire` ou `object.getAuteur() == user` | `escalade:read` | même patron que `DelegationDroit::Get` — le caissier peut suivre sa propre demande |
| | `POST /demandes-escalade/{id}/approuver` | `autorisation.approuver` | — | `ApprouverEscaladeProcessor`, corps `{}` |
| | `POST /demandes-escalade/{id}/rejeter` | `autorisation.approuver` | — | `RejeterEscaladeProcessor`, corps `{"motif"}` |
| | *(pas de `Post` de création directe)* | — | — | une `DemandeEscalade` n'est créée que par `ServiceAutorisation` (§2.2), jamais via l'API publique |

### 4.1 Gardes des processors d'écriture

- **`LimiteAutorisationProcessor`** (Post/Patch/Delete) :
  1. Cible exclusive `role` XOR `utilisateur` (`Assert\Expression` sur l'entité + revérifié processeur,
     422 sinon).
  2. Établissement du corps de requête réellement géré par l'auteur : `CalculateurDroits::autorise(
     CalculateurDroits::codesEffectifs($auteur, $limite->etablissement->getId()), 'autorisation', 'gerer')`
     — **vérifié sur l'établissement soumis dans le corps**, pas seulement sur le contexte actif
     (`X-Etablissement`), pour empêcher un administrateur d'établissement A de configurer une limite sur
     un établissement B où il n'a pas `autorisation.gerer`, même s'il possède ce droit sur A (⚠ HYPOTHÈSE
     §4.2 spec, garde analogue à `VerificateurPlafondDroits`, code non modifié — nouveau petit service
     `App\Autorisation\Service\VerificateurPerimetreLimite` qui réutilise `CalculateurDroits` en lecture
     seule).
  3. Garde applicative « au plus une limite par (opération, cible, établissement) » (§1, 422 si doublon).
- **`ApprouverEscaladeProcessor`/`RejeterEscaladeProcessor`** : revérifient que le superviseur a
  `autorisation.approuver` **sur `$demande->etablissement` précisément** (même logique que ci-dessus, pas
  seulement sur l'établissement actif de l'en-tête) avant de déléguer à `GestionnaireEscalade`.

---

## 5. Sécurité & droits

- **3 nouvelles permissions**, module `autorisation` (RG-AUTZ, §3 spec) : `autorisation.gerer` (configurer
  catalogue + limites), `autorisation.approuver` (traiter les escalades), `autorisation.lire` (consultation
  seule, miroir `securite.gerer`/`securite.lire`).
- **Aucune nouvelle permission requise pour *subir* la gradation** — un caissier continue d'utiliser
  `vente.annuler`/`vente.rembourser` (inchangés).
- **Voters** — aucun voter nouveau : `PermissionVoter` (socle) suffit pour le `security:` des opérations
  API Platform ; les gardes fines (établissement précis, cible exclusive, auto-approbation RG-AUTZ-13,
  jeton déjà utilisé) sont portées par les processors/services applicatifs (§4.1, §3), pas par des voters
  — cohérent avec le patron déjà utilisé pour `VerificateurPlafondDroits`/`GardeDernierAdministrateur`
  (services dédiés, pas de `Voter` custom).
- **Cadrage établissement** — `PerimetreAutorisationExtension` (§1) pour `LimiteAutorisation`/
  `DemandeEscalade` en lecture ; garde explicite par établissement soumis pour toute écriture (§4.1).

---

## 6. Intégration M2 — points d'insertion précis

### 6.1 `AnnulerVenteProcessor::process()`

Insertion **avant** `$this->handler->annuler(...)`, après résolution de `$auteur` :

```php
$decision = $this->serviceAutorisation->evaluer(new RequeteAutorisation(
    operationCode: 'vente.annuler',
    utilisateur: $auteur,
    montant: $data->getTotal(),
    cibleType: 'Vente',
    cibleId: $data->getId(),
    cibleEtablissementId: $data->getEtablissement()?->getId(),
    cibleSessionOperateurId: $data->getSession()?->getOperateur()?->getId(),
    cibleSessionRegisseurId: $data->getSession()?->getRegisseur()?->getId(),
    jetonRejeu: $this->lireJetonRejeu(), // Uuid::fromString($corps['demandeEscalade']) si présent, sinon null
));

match ($decision->resultat) {
    ResultatDecision::Refuse => throw new AccessDeniedException($decision->motif),
    ResultatDecision::EscaladeRequise => throw new EscaladeRequiseException($decision), // §6.3
    ResultatDecision::Autorise => null, // poursuite normale, code actuel inchangé
};

// --- code existant, strictement inchangé à partir d'ici ---
$motif = ...;
$avoir = $this->handler->annuler($data, $motif, $auteur);
$this->em->persist($avoir);
$this->em->flush();
return $this->reponse($avoir);
```

### 6.2 `RembourserVenteProcessor::process()`

Même insertion, avec le montant **effectivement évalué** (partiel ou total) calculé **avant** l'appel au
service — reproduction minimale (une ligne, `number_format`) de la résolution déjà faite dans
`ContrePassationHandler::rembourser()`, **sans modifier ce handler** (§0 n°2) :

```php
$montantEvalue = $montant !== null ? number_format((float) $montant, 2, '.', '') : $data->getTotal();

$decision = $this->serviceAutorisation->evaluer(new RequeteAutorisation(
    operationCode: 'vente.rembourser',
    utilisateur: $auteur,
    montant: $montantEvalue,
    cibleType: 'Vente',
    cibleId: $data->getId(),
    cibleEtablissementId: $data->getEtablissement()?->getId(),
    cibleSessionOperateurId: $data->getSession()?->getOperateur()?->getId(),
    cibleSessionRegisseurId: $data->getSession()?->getRegisseur()?->getId(),
    jetonRejeu: $this->lireJetonRejeu(),
));
// même match que §6.1

// --- code existant inchangé : $this->handler->rembourser($data, $montant, $motif, $auteur) ---
```

CA-8 (remboursement partiel cumulé) découle directement de cette conception : chaque appel à `process()`
est **un nouvel appel isolé** à `ServiceAutorisation::evaluer()`, sans état partagé entre deux requêtes
HTTP successives (pas de cumul, RG-AUTZ-11 non retenu v1, conforme à la spec).

### 6.3 Traduction en réponse HTTP observable (RG-AUTZ-06)

- `ResultatDecision::Refuse` → `AccessDeniedException` (socle Symfony Security) → **403** standard, corps
  d'erreur par défaut de la plateforme (comme toute exception d'accès refusé aujourd'hui).
- `ResultatDecision::EscaladeRequise` → nouvelle exception applicative
  `App\Autorisation\Exception\EscaladeRequiseException` (porte la `Decision`), interceptée par un
  `ExceptionListener` dédié (`App\Autorisation\EventListener\EscaladeRequiseExceptionListener`,
  `kernel.exception`, priorité avant le listener générique API Platform) qui produit exactement :

```json
{
  "decision": "escalade_requise",
  "demandeEscalade": "<jeton uuid>",
  "operation": "vente.annuler",
  "plafond": "50.00",
  "montant": "120.00"
}
```
avec code HTTP **403** — conforme littéralement à RG-AUTZ-06.

### 6.4 Rejeu

Le corps de la requête `POST /ventes/{id}/annuler` / `.../rembourser` accepte un champ optionnel
supplémentaire `"demandeEscalade": "<jeton>"` (en plus de `motif`/`montant` existants) — lu par
`LecteurCorps` (déjà injecté, aucune modification du service), **aucun changement de signature** des
routes API Platform existantes.

---

## 7. Migrations

1. **`VersionAutorisation_structure`** — crée `atz_operation_sensible`, `atz_limite_autorisation`,
   `atz_demande_escalade` (§1, index/contraintes listés). FK sortantes vers `sec_role`, `sec_utilisateur`,
   `org_etablissement` — suppose les migrations socle (L0) et L2 (Vente/Caisse) déjà jouées (référence
   `Etablissement`/`Utilisateur`/`Role` uniquement, pas de FK vers `App\Vente\*`). Réversible (`down` :
   `DROP TABLE` en ordre inverse des FK).
2. **`VersionAutorisation_permissions`** — `INSERT IGNORE INTO sec_permission (id, module, action) VALUES
   (?, 'autorisation', 'gerer'|'approuver'|'lire')` — idempotente, même patron exact que
   `Version20260817173400` (Stock). `down` : `DELETE FROM sec_permission WHERE module = 'autorisation'`.
3. **`VersionAutorisation_seed_catalogue`** — seed `OperationSensible` (idempotent, `INSERT IGNORE`) :
   - câblées v1 : `vente.annuler` / `Vente — Annulation` / `vente.annuler` ; `vente.rembourser` /
     `Vente — Remboursement` / `vente.rembourser` ;
   - déclarées non câblées v1 (§4.1 spec, extensibilité) : `caution.retenue`, `compta.detaxe`,
     `vente.remise_exceptionnelle`, `caisse.reouverture_session` — insérées avec `active = true` pour
     être configurables en back-office dès maintenant, **sans aucun effet observable** tant qu'aucun
     handler correspondant n'appelle `ServiceAutorisation` (documenté explicitement, §7 cas limite de la
     spec, Risque n°10 ci-dessous).
   `down` : `DELETE FROM atz_operation_sensible WHERE code IN (...)`.
4. **Modification additive hors migration DB** — `AuditWriteSubscriber::CLASSES_SURVEILLEES` +=
   `LimiteAutorisation::class`, `OperationSensible::class` (§0 en-tête, T9).

Toutes rejouables, versionnées Doctrine ; jamais de `schema:update --force`.

---

## 8. Tests (PHPUnit + ApiTestCase)

| Test | Type | Couvre |
|---|---|---|
| Sous plafond (80 € ≤ 100 €), périmètre propre_session respecté → AUTORISE, `Avoir` créé, aucune `DemandeEscalade` | API | CA-1, RG-AUTZ-04 |
| Au-dessus du plafond (250 € > 100 €), `escaladeAuDela=true` → 403 `escalade_requise`, `DemandeEscalade(en_attente)` créée avec les bons champs (montant, opération, auteur), annulation NON exécutée | API | CA-2, RG-AUTZ-06 |
| Approbation par superviseur habilité (établissement identique, ≠ demandeur) → statut `approuvee`, `EntreeAudit` qui/quand/montant ; rejeu `POST /ventes/{id}/annuler` avec le jeton → AUTORISE sans recomparaison au plafond, `Avoir` créé | API | CA-3, RG-AUTZ-06/08 |
| Rejet avec motif → statut `rejetee`, rejeu bloqué (jeton refusé), nouvelle tentative crée une **nouvelle** `DemandeEscalade` distincte | API | CA-4, RG-AUTZ-06 |
| Périmètre `propre_session`, vente d'un **autre** opérateur (montant sous le plafond, droit binaire présent) → REFUSE 403 « hors périmètre » | API | CA-5, RG-AUTZ-05 |
| Aucune `LimiteAutorisation` configurée → AUTORISE quel que soit le montant, **aucune régression**, **aucune requête d'écriture supplémentaire** (assertion sur le nombre de requêtes/flush) | API + Unit | CA-6, RG-AUTZ-09, §0 n°3 |
| `escaladeAuDela=false`, dépassement (150 € > 100 €) → REFUSE définitif, **aucune** `DemandeEscalade` créée, message explicite | API | CA-7, RG-AUTZ-04 |
| Deux remboursements partiels successifs de 60 € (plafond 100 €, pas de cumul configuré) → chacun évalué isolément et AUTORISÉ | API | CA-8, RG-AUTZ-11 |
| Aucun superviseur habilité sur l'établissement → `DemandeEscalade` reste `en_attente`, commande `autorisation:escalades:expirer` la passe `expiree` après le délai, opération toujours bloquée, aucune dérogation | API + Unit (commande) | CA-9, RG-AUTZ-07 |
| Montant strictement égal au plafond → AUTORISE (comparaison inclusive) | Unit (`ServiceAutorisation`) | §4.3/§7 spec, cas limite |
| Auto-approbation (superviseur == auteur, même habilité) → refus 403 explicite | API | RG-AUTZ-13 |
| Rejeu avec montant/cible différents de la demande approuvée → refus | Unit (`evaluerRejeu`) | §4.6 spec |
| Rejeu d'un jeton déjà utilisé (`dateRejeu` déjà renseigné) → refus 409 | Unit (`evaluerRejeu`) | §0 n°6 |
| Priorité de résolution : limite utilisateur prévaut sur limite de rôle ; entre deux limites de rôle, la plus restrictive (plafond puis périmètre) l'emporte | Unit (`ResolveurLimiteAutorisation`) | RG-AUTZ-03 |
| Délégation temporaire active (`DelegationDroit`) : le bénéficiaire hérite des `LimiteAutorisation` du rôle délégué pendant la fenêtre active | Unit (`ResolveurLimiteAutorisation`) | RG-AUTZ-12 |
| Cloisonnement établissement : un utilisateur sans affectation sur l'établissement d'une `DemandeEscalade`/`LimiteAutorisation` ne la voit pas (`PerimetreAutorisationExtension`) ; configuration d'une limite sur un établissement non géré par l'auteur → refus (`VerificateurPerimetreLimite`) | API | RG-SOCLE-05, §4.1 |
| `LimiteAutorisation` : cible exclusive role/utilisateur (422 si les deux ou aucun) ; doublon (opération, cible, établissement) refusé (422) | API | RG-AUTZ-02, §1 garde applicative |
| Architecture : `App\Vente\Service\ContrePassationHandler` non modifié par ce lot (contrôle statique diff) | Unit (architecture) | §0 n°2, non-régression M2 |

---

## 9. Tâches (voir tasks-autorisation.md)

T1 Enums (`ResultatDecision`, `PerimetreAutorisation`, `StatutEscalade`) + entités `OperationSensible`/
`LimiteAutorisation`/`DemandeEscalade` + migration structure →
T2 DTO `RequeteAutorisation`/`Decision` + `ResolveurLimiteAutorisation` (RG-AUTZ-03/12, tests unitaires
en priorité) →
T3 `ServiceAutorisation::evaluer()` (rétrocompat §0 n°3, périmètre, plafond, flush ciblé §0 n°4) + tests
unitaires isolés →
T4 `GestionnaireEscalade` (créer/approuver/rejeter, garde RG-AUTZ-13) + `evaluerRejeu()` (§2.4, jeton à
usage unique §0 n°6) + tests →
T5 `ExpirerEscaladesCommand` (`autorisation:escalades:expirer`) + test →
T6 API Platform `OperationSensible` + `LimiteAutorisation` (+ `LimiteAutorisationProcessor`,
`VerificateurPerimetreLimite`, `PerimetreAutorisationExtension`) →
T7 API Platform `DemandeEscalade` (Get/GetCollection + `ApprouverEscaladeProcessor`/
`RejeterEscaladeProcessor`) + tests API →
T8 `EscaladeRequiseException` + `EscaladeRequiseExceptionListener` (§6.3) →
T9 Intégration `AnnulerVenteProcessor`/`RembourserVenteProcessor` (§6.1/§6.2, tests fonctionnels CA-1 à
CA-8 en priorité) →
T10 Migration permissions `autorisation.{gerer,approuver,lire}` + seed catalogue (§7.3) + extension
`AuditWriteSubscriber::CLASSES_SURVEILLEES` →
T11 Tests de non-régression M2 (CA-6, cloisonnement, contrôle statique §0 n°2) + mise à jour de ce plan
si divergence d'implémentation.

---

## 10. Risques / à valider

1. **US-AUTZ-01 à 09 / RG-AUTZ-01 à 13 non validées/numérotées officiellement** dans le backlog (spec
   préambule, hypothèse assumée hors `backlog.html`/`cahier-detaille.html`) — à faire inscrire/valider
   avant planification effective, même réserve que les modules verticaux récents (Stock, Personnel).
2. **Deux mécanismes de plafonnement caisse coexistent sans fusion** — `MouvementCaisse::
   alerteRegisseur`/`PointDeVente::seuilAlerteRetrait` (non bloquant, simple indicateur, code M2 existant
   inchangé) vs l'escalade bloquante introduite ici (RG-AUTZ) : aucune convergence prévue en v1, à
   documenter clairement pour ne pas laisser croire à un mécanisme unique.
3. **Ordre de priorité « plafond vs périmètre » entre deux limites de rôle (§2.3 point 3)** — RG-AUTZ-03
   ne tranche pas explicitement quel critère prime quand plafond et périmètre divergent entre deux
   limites applicables ; ce plan retient le plafond comme critère primaire — à confirmer avec le métier.
4. **Garde « au plus une limite par cible » purement applicative, sans verrou pessimiste** (contrairement
   à `GardeDernierAdministrateur`) — risque de course concurrente rare (deux créations quasi simultanées
   de la même limite) non couvert en v1 ; impact limité (config back-office, faible fréquence d'écriture
   concurrente).
5. **`jeton` vs `id` de `DemandeEscalade` (§0 n°5)** — la spec mentionne les deux dans des passages
   différents (corps 403 §4.6 vs tableau §5) sans lien explicite ; ce plan les distingue par construction
   (id = PK interne superviseur, jeton = référence externe rejeu) — décision arbitrée à faire confirmer.
6. **`dateRejeu` est un champ additif non présent au tableau §5 de la spec** (§0 n°6) — nécessaire pour
   empêcher le rejeu multiple d'un jeton approuvé (non tranché explicitement par la spec) ; sans lui, un
   remboursement partiel approuvé une fois pourrait techniquement être rejoué plusieurs fois au même
   montant tant que le jeton reste valide — à valider.
7. **Cumul journalier non retenu (RG-AUTZ-11)** — fractionnement en plusieurs opérations sous le plafond
   non détecté (CA-8, limitation documentée et assumée, pas un bug).
8. **Mode dégradé/hors-ligne non traité** (§7 spec) — le mécanisme d'escalade suppose un aller-retour
   serveur ; en hors-ligne, l'opération reste bloquée en `ESCALADE_REQUISE` jusqu'au retour réseau,
   potentiel écart avec le principe « la caisse fonctionne hors-ligne » (constitution §4 point 6) si les
   opérations sensibles concernées s'avèrent fréquentes en usage déconnecté — à arbitrer avec le métier.
9. **`OperationSensible.code` comme PK string, dérogation mineure à la convention « id = UUID »** (§0
   n°1) — jugée conforme à l'intention de la règle (pas d'auto-incrément exposé), mais à faire valider
   explicitement si une lecture plus stricte de la constitution est retenue.
10. **Catalogue non câblé v1** (`caution.retenue`, `compta.detaxe`, `vente.remise_exceptionnelle`,
    `caisse.reouverture_session`) — configurer une `LimiteAutorisation` dessus n'a aucun effet
    observable tant que les handlers correspondants n'appellent pas `ServiceAutorisation` (câblage hors
    périmètre de ce plan) ; risque de confusion pour l'administrateur back-office si l'UI ne le signale
    pas clairement (déjà noté §7 spec) — point à porter à l'équipe UI/back-office.
11. **Établissement de la limite résolu sur l'établissement actif (`X-Etablissement`)** — un utilisateur
    multi-établissements a des limites potentiellement différentes selon le contexte actif, cohérent avec
    le socle mais à bien couvrir par les tests de cloisonnement (§8).
12. **NF525/comptable** — aucune écriture scellée générée par ce module (`Decision` non persistée hors
    `DemandeEscalade`, qui n'est pas une opération financière NF525 en soi) ; à confirmer par un expert
    NF525 seulement si un besoin futur de scellement de l'escalade elle-même émergeait (hors périmètre
    v1, non demandé par la spec).
