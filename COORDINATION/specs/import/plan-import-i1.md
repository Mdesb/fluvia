# Plan technique — Import / Reprise initiale d'un client (`App\Import`, incrément I1, tâche `T2`)

- **Spec source :** `COORDINATION/specs/import/SPEC-REPRISE-INITIALE.md` (D97/D98/D99/D100, décidés par
  Maxime le 31/08)
- **Stack :** Symfony 7 · API Platform · Doctrine/MariaDB
- **Contrat de plateforme :** D2 (contract-first, pas d'appel direct module→module — écart assumé et
  documenté §0.6), D3/D8 (cloisonnement à périmètre serveur, revérification explicite), D5 (identifiants
  techniques anglais), D41 (l'établissement d'une écriture vient de la session serveur, jamais du corps)
- **Précédent généralisé, pas dupliqué (mission explicite, §2 de la spec) :** `App\Finance\Treasury\
  {Entity\BankStatementImport, Entity\BankStatementLine, Enum\BankStatementImportFormat, Enum\
  BankStatementImportStatus, Enum\BankStatementLineStatus, Port\BankStatementParserInterface, Service\
  CsvBankStatementParser, State\ImportBankStatementProcessor}` — lu intégralement avant d'écrire ce plan.
  Repris : `contentHash`, statut fini par enum, message(s) d'erreur porté(s) par l'objet import, port de
  parseur branchable, écriture manuelle dans une transaction (`$em->wrapInTransaction()`), revérification
  D8 explicite même après résolution par le provider standard. **Deux écarts assumés par rapport au
  précédent, et justifiés ci-dessous** : (1) `content` est ici **persisté** (le précédent le rend
  transitoire, §0.1) ; (2) deux temps stricts validation/application (le précédent écrit en une seule
  passe, §0.2).
- **Couvre :** D97 (reprise initiale d'abord), D98 (deux temps, tout ou rien), D100 (rapprochement par
  `externalRef`) — **pas** D99 (ventes historiques, hors périmètre par construction : ce lot ne touche à
  aucune entité de vente).
- **Portée stricte de ce plan (I1) :** le framework `ImportBatch` + **le seul type `customers`**. Les
  types `products`/`tariffs`/`subscribers`/`card_credits`/`staff` sont hors périmètre (incréments I2+) —
  le framework est conçu pour les accueillir sans réécriture (§0.3/§0.4), mais aucun n'est implémenté ici.

---

## 0. Décisions d'architecture

### 0.1 `content` est persisté, contrairement au précédent — et c'est un écart volontaire

`BankStatementImport.content` est **transitoire** : seul `contentHash` survit au traitement, le fichier
brut n'est jamais gardé (docblock explicite de l'entité : « référence/empreinte, jamais le document brut
en base »). La spec de reprise **inverse ce choix explicitement**, §2 : *« Sans lui, un import contesté
six mois plus tard ne se rejuge pas : on n'a que le résultat, pas ce qui l'a produit. »* Ce plan retient
donc `ImportBatch.content` **persisté**, en base64 dans une colonne `LONGTEXT` (`Types::TEXT`, longueur
forcée pour éviter la limite 64 Ko d'un `TEXT` MySQL/MariaDB nu — **à vérifier sur la migration générée
avant de la committer, D32**), plutôt qu'un `blob` binaire : évite la gestion de flux/ressource PHP de
Doctrine `blob` (source classique d'ennuis), au prix d'un gonflement ~33 % — acceptable pour des fichiers
de reprise (quelques centaines à quelques milliers de lignes), **pas** pour des exports massifs (§7
point 5, risque RGPD à traiter séparément).

`App\Dms\Storage\Storage` (port de stockage enfichable, déjà construit — `LocalFilesystemStorage`, D18)
serait l'endroit naturel pour porter un contenu volumineux hors base. Il n'est **pas** réutilisé ici : il
vit dans `App\Dms`, pas dans `App\Platform`, et D2 interdit l'appel direct module→module — le réutiliser
tel quel reproduirait exactement le problème que D42 a nommé pour `ClientNotificationInterface`
(capacité utile à plusieurs modules, mal placée). Recommandation, non traitée par ce plan : promouvoir
`Storage` vers `App\Platform\Storage` avant qu'un troisième consommateur n'apparaisse (§7 point 5).

### 0.2 Deux temps stricts (D98) — le précédent écrit en une passe, celui-ci ne peut pas

`ImportBankStatementProcessor` parse et écrit dans le **même** appel HTTP (`POST /bank_statement_imports`
crée les `BankStatementLine` directement). D98 l'interdit explicitement pour la reprise : *« un import qui
écrit et valide en même temps ne peut pas tenir la règle "tout refuser" — quand il découvre la ligne
4 217, les 4 216 premières sont déjà là. »* Ce plan sépare donc :

    POST /imports                analyse + valide TOUT, n'écrit RIEN en base métier (Crm, etc.)
                                  → status = validated | rejected (+ liste ENTIÈRE des lignes en erreur)
    POST /imports/{id}/appliquer applique, en UNE transaction → status = applied
    POST /imports/{id}/annuler   supprime ce que CE lot a créé → status = reverted

`ImportBatch` lui-même (métadonnées, fichier, verdict) est écrit dès la première étape — ce n'est pas de
la « base métier » au sens de D98 (aucun `Client`, aucune vente). Seule l'écriture dans les modules
cibles (Crm pour I1) est différée à `/appliquer`.

### 0.3 Framework par type — `RowImporterInterface`, un seul type implémenté (I1)

    interface RowImporterInterface
    {
        public function type(): ImportType;

        /** @param list<ParsedImportRow> $rows @return array<int,string> ligne => message */
        public function validate(array $rows, Etablissement $establishment): array;

        /** @param list<ParsedImportRow> $rows @return int nombre de créations (hors mises à jour, §0.5) */
        public function apply(array $rows, ImportBatch $batch): int;

        public function countCreated(Uuid $batchId): int;

        public function isReferenced(Uuid $batchId): bool;

        public function revert(Uuid $batchId): void;
    }

`validate()` est **pure** : aucune écriture, une requête en lecture seule au plus (recherche d'un
`externalRef` déjà connu pour signaler une mise à jour plutôt qu'une création — informatif seulement).
`App\Import\Service\RowImporterRegistry` (construit sur un `iterable<RowImporterInterface>` tagué,
dispatch par `type()`, même idiome que `BankStatementParserInterface::supports()`) résout l'implémentation
pour un `ImportType` donné ; absence d'implémentation → 422 explicite (« type non encore supporté »),
jamais 500 — **c'est le point d'extension pour I2+** (`products`/`tariffs`/`subscribers`/`card_credits`/
`staff` arrivent chacun comme un nouveau `RowImporter*` enregistré, zéro modification du framework).

**Seul `App\Import\Service\CustomerRowImporter` est construit par ce lot** (`type()` = `ImportType::
Customers`), détaillé §0.5.

### 0.4 Port de parsing — format derrière une interface, CSV seul construit (spec §6, point ouvert)

    interface ImportFileParserInterface
    {
        public function supports(string $mimeType, string $fileName): bool;

        public function parse(string $rawContent): ParsedImportBatch;
    }

`ParsedImportBatch { list<ParsedImportRow> $rows; list<string> $globalErrors; }`, `ParsedImportRow { int
$lineNumber; array<string,string> $columns; }` (`App\Import\Dto`). **Volontairement générique** :
contrairement au format positionnel minimal de `CsvBankStatementParser` (`date;libelle;montant;reference`,
4 colonnes fixes), une fiche client a une dizaine de champs — `App\Import\Service\CsvImportParser` lit une
ligne d'en-tête et restitue chaque ligne suivante en tableau associatif **par nom de colonne** (clés
normalisées : minuscules, espaces retirés), délimiteur `;`. Numérotation des lignes en base 1, en-tête =
ligne 1 (cohérent avec les messages d'erreur qui doivent nommer la ligne du fichier tel que l'utilisateur
le voit dans son tableur, §5 spec CA-1 implicite « nommer les lignes »).

**XLSX (point ouvert §6 de la spec, laissé à Maxime) n'est pas construit par ce lot** — le port existe
justement pour que l'ajout d'un `XlsxImportParser` n'exige aucune modification du cœur (`registry` par
`supports(mimeType, fileName)`, même dispatch que le port de parseur bancaire).

Le mapping colonnes-du-fichier → champs métier (`externalRef`, `nom`, `prenom`, `raisonSociale`, `siret`,
`email`, `telephone`, `dateNaissance`, `civilite`, `adresse.*`) est de la responsabilité de
`CustomerRowImporter`, **pas** du parseur générique — celui-ci ne connaît que des lignes et des colonnes
nommées, jamais la sémantique d'un type.

### 0.5 Le type `customers` — écriture cross-module assumée vers `App\Crm\Entity\Client`

`App\Import\Service\CustomerRowImporter` crée/met à jour des `App\Crm\Entity\Client` **directement**
(`new Client()`, `$em->persist()`) — un appel synchrone module→module, en contradiction littérale avec D2
(« les modules communiquent par événements, jamais par appel direct »). **Ce n'est pas un oubli, c'est
assumé et nommé explicitement** (patron « couplage d'écriture assumé », même famille que la note CQ-5
déjà tracée ailleurs dans le dépôt pour des cas similaires) : la reprise doit savoir, **dans la même
transaction et avant de répondre**, combien de lignes ont été créées, lesquelles ont échoué et pourquoi —
un événement asynchrone ne peut pas porter cette garantie transactionnelle (D7 : bus synchrone in-process,
mais toujours un mécanisme de notification, pas un canal d'écriture piloté). **À confirmer par
claude-A/l'architecte avant merge** (§7 point 1) : soit cette exception est actée au niveau du manifeste
`App\Import` (une dépendance déclarée, documentée, plutôt qu'un événement), soit une autre lecture de D2
est retenue.

**D100 impose un rapprochement exact par `externalRef`, jamais par le nom** — conséquence directe :
`apply()` fait un **upsert** ligne à ligne, pas une création systématique :

- `externalRef` **inconnu** pour `(establishment, ImportType::Customers)` → **création** d'un `Client`,
  `Client.importBatchRef` posé à l'id du lot courant (§0.6), + une ligne `ImportedEntityRef` créée.
- `externalRef` **déjà connu** → **mise à jour** du `Client` existant (retrouvé par `ImportedEntityRef.
  targetId`) avec les valeurs de la ligne. **`importBatchRef` du `Client` n'est PAS touché** — il reste
  pointé sur le lot qui l'a **créé** (§0.6, condition nécessaire pour que l'annulation reste exacte).
  `ImportedEntityRef.importBatchRef` (lui, informatif) est en revanche rafraîchi au dernier lot qui a
  touché la ligne.

C'est la lecture retenue de D98 *« rejouer un fichier corrigé ne duplique pas »* : un fichier corrigé
réappliqué ne recrée pas les lignes déjà entrées, il **corrige** celles qui avaient une valeur fausse —
plus cohérent avec le mot « corrigé » qu'un simple silence (skip) qui laisserait l'erreur en place.

**Validation propre à `customers`** (`validate()`, aucune écriture) :
- `externalRef` obligatoire, non vide.
- `externalRef` **unique dans le fichier lui-même** — deux lignes du même dépôt avec le même
  `externalRef` sont **toutes deux** en erreur (impossible de savoir laquelle est la bonne) : cas non
  couvert par la seule contrainte `(establishment, type, externalRef)` d'`ImportedEntityRef`, qui ne voit
  qu'une ligne à la fois.
- `type` (`physique`/`morale`) requis et valide.
- Si `physique` : `nom` requis (même règle que `Client::validerCoherenceType()`, dupliquée ici en
  validation d'import — **volontairement**, §7 point 2 : rejouer la même règle métier deux fois est un
  risque de divergence documenté, pas un oubli).
- Si `morale` : `raisonSociale` requis.
- Aucune colonne « établissement » n'est lue, même présente dans le fichier (§0.7, D41).

### 0.6 `importBatchRef` — porté par l'entité cible, jamais une relation Doctrine (D2 littéral de la spec)

`Client.importBatchRef` (`?Uuid`, nullable, colonne nue — **pas** de `ManyToOne` vers `ImportBatch`) :
posé **une seule fois, à la création**, jamais réécrit par une mise à jour ultérieure (§0.5). C'est ce qui
rend `countCreated()`/`isReferenced()`/`revert()` exacts :

- `countCreated(batchId)` = `COUNT(Client) WHERE importBatchRef = batchId` — ne compte que les créations,
  jamais les mises à jour, même si elles ont été faites par ce même lot pour d'autres lignes.
- `revert(batchId)` = supprime **exactement** `Client WHERE importBatchRef = batchId` (+ la ligne
  `ImportedEntityRef` correspondante) — jamais les `Client` que ce lot a seulement mis à jour, cohérent
  avec la spec §5 : *« supprime exactement ce que ce lot a créé »*, pas ce qu'il a touché.
- `isReferenced(batchId)` (détaillé §0.8) porte sur ce même ensemble.

**Pourquoi une colonne nue sur `Client` plutôt qu'une jointure côté `App\Import`.** Une jointure
exigerait qu'`App\Import` sache retrouver « les clients du lot X » en repassant par `ImportedEntityRef`
(possible, mais un aller-retour de plus) ; une colonne directe sur `Client` rend la requête locale à
`App\Crm` (index dédié, `idx_client_import_batch_ref`) et généralise **littéralement** au motif futur
(`Product.importBatchRef`, `Subscriber.importBatchRef`, etc., un ajout par module au fil des incréments
I2+, chacun sa propre migration — aucune n'est faite par ce plan).

### 0.7 `establishment` — jamais dans le corps de la requête (D41)

`ImportBatch` n'est **jamais** créé via le processeur de persistance générique d'API Platform : les trois
opérations d'écriture (`POST /imports`, `.../appliquer`, `.../annuler`) sont chacune un `Processor` dédié
qui appelle lui-même `$em->persist()`/`flush()` — même construction qu'`ImportBankStatementProcessor`, qui
ne décore pas `persist_processor`. **Conséquence directe : le décorateur global `App\Platform\Security\
EstablishmentScopeWriteGuard` (D41) ne s'applique PAS ici**, puisqu'il décore justement
`api_platform.doctrine.orm.state.persist_processor`, jamais appelé sur ce chemin. Ce n'est pas un trou :
`establishment` n'apparaît dans **aucun** groupe de sérialisation en écriture (`import_batch:write` ne le
porte pas) — il n'existe tout simplement pas de valeur cliente à ignorer ou à valider, il est posé par
`ValidateImportBatchProcessor` depuis `ContexteEtablissement::etablissementActif()` (même source que
`CrmEstablishmentStampProcessor`/`ProjectEstablishmentStampProcessor`), 422 si absent.

⚠ **Point d'attention signalé, pas un défaut de ce plan** : `EstablishmentScopeWriteGuard` ne teste que
`method_exists($data, 'getEtablissement')` (français) — `Project`/`Legal`/`Marketing`, qui utilisent déjà
`getEstablishment()` (D5, anglais), ne sont **pas couverts** par ce garde-fou global. `App\Import` n'en a
de toute façon pas besoin (paragraphe ci-dessus), mais le constat mérite d'être remonté séparément (§7
point 6) — une classe D41 encore ouverte, différente de celle déjà corrigée.

**Test explicite requis (§5)** : une colonne `establishment`/`etablissement` présente dans le fichier
importé est purement et simplement ignorée par `CsvImportParser`/`CustomerRowImporter` — ni lue, ni
source d'erreur si absente des colonnes attendues.

### 0.8 Annulation — `isReferenced()` générique par scan de métadonnées Doctrine, pas une liste figée par type

La spec exige : *« refuse dès qu'une ligne du lot a été employée depuis »* (§5). Pour `customers`, « avoir
servi » signifie qu'un `Client` créé par ce lot est référencé ailleurs (une `Vente`, un `Abonnement`, un
`Beneficiaire`, une `Reservation`…) — une liste figée dans `CustomerRowImporter` serait exacte aujourd'hui
et fausse dès qu'un nouveau module ajoute une relation vers `Client` sans que quiconque pense à mettre à
jour cette liste (même classe de risque que D41 : une garde correcte qui devient fausse en silence). Ce
plan retient donc un utilitaire **partagé et générique**, `App\Import\Service\ReverseReferenceChecker` :

    public function isReferenced(string $entityClass, Uuid $id): bool

Implémentation : parcourt `EntityManager::getClassMetadata()` de **toutes** les entités mappées, retient
celles qui portent une association (`ManyToOne`/`OneToOne`) vers `$entityClass`, exécute une requête
`EXISTS` par association trouvée. Coût non négligeable (scan de métadonnées + N requêtes), **jugé
acceptable** : `/annuler` est une action rare, déclenchée à la main, jamais un chemin chaud. Réutilisable
tel quel par chaque futur `RowImporter` (I2+) sans connaître à l'avance le graphe des modules consommateurs
— généralisation demandée par la mission (§2 spec), appliquée ici à l'annulation autant qu'au framework
d'import lui-même.

**Limite assumée, à valider avant I2 (§7 point 3)** : ce scan ne voit que les associations Doctrine
déclarées ; une référence portée par une colonne libre (ex. un identifiant stocké dans un champ `json`
plutôt qu'une vraie relation) lui échapperait. Aucun cas de ce type n'est connu pour `Client` aujourd'hui
(vérification à refaire avant chaque nouveau type en I2+, en particulier `card_credits`, le plus sensible
selon la spec elle-même §4).

`CustomerRowImporter::isReferenced(batchId)` = pour chaque `Client` où `importBatchRef = batchId`, appelle
`ReverseReferenceChecker::isReferenced(Client::class, $client->getId())` ; vrai dès le premier trouvé.

`RevertImportBatchProcessor` orchestre : statut ≠ `applied` → 409 ; `isReferenced()` → 409 (« des lignes de
ce lot ont été utilisées depuis ») ; sinon `revert()` dans une transaction, statut → `reverted`.

### 0.9 Idempotence de fichier — `contentHash`, détectable mais pas bloquant à la validation

Contrairement à `BankStatementImport` (`UNIQUE(bank_account_id, content_hash)`, bloquant dès le dépôt), ce
plan **ne pose pas de contrainte `UNIQUE`** sur `(establishment, content_hash)` : la spec autorise
explicitement de revalider un fichier corrigé plusieurs fois (aucune écriture métier n'a lieu tant qu'il
n'est pas appliqué, donc aucun risque à laisser coexister plusieurs `ImportBatch` `validated`/`rejected`
partageant un hash). Le contrôle qui compte est à l'application :

- `POST /imports` calcule `contentHash` et le persiste, **sans bloquer** — « doublon détectable » (mission)
  se fait en interrogeant la collection (`GET /imports?contentHash=…`, filtre exposé, §2).
- `POST /imports/{id}/appliquer` **refuse (409)** si un **autre** `ImportBatch` du même `establishment`
  partage ce `contentHash` et a déjà `status = applied` — c'est le seul moment où « déjà importé » doit
  bloquer, littéralement ce que demande la mission.

---

## 1. Entités & schéma

| Entité (`App\Import\Entity\*`) | Champ | Type Doctrine | Null | Index/Contrainte | Relation |
|---|---|---|---|---|---|
| **`ImportBatch`** | id | uuid | non | PK | — |
| | establishment | uuid (FK) | non | index — **jamais en groupe d'écriture** (§0.7, D41) | `Etablissement` |
| | type | `string(20)` enum `ImportType` | non | — | `customers` (seule valeur I1) |
| | fileName | `string(255)` | oui | — | déclaratif, informatif |
| | mimeType | `string(100)` | oui | — | déclaratif |
| | fileSize | `int` | non | — | calculé serveur (octets décodés) |
| | contentHash | `string(64)` | non | index (`establishment_id`, `content_hash`), **pas unique** (§0.9) | SHA-256 hex du contenu brut |
| | content | `text` (forcé `LONGTEXT`, §0.1) | non | jamais dans le groupe de liste (§2) | fichier source, base64, **conservé** |
| | expectedTotal | `decimal(14,2)` | oui | — | total annoncé par le client, **ignoré par `customers`** (I1) — réservé à `card_credits` (I2+, §4 spec) |
| | status | `string(10)` enum `ImportBatchStatus` | non | défaut `pending`, index | `pending` quasi jamais persisté en I1 (§1 enum) |
| | rowCount | `int` | non | défaut `0` | nombre de lignes de données (hors en-tête) |
| | errors | `json` | oui | — | `array<int,string>` ligne → message, **liste ENTIÈRE**, jamais tronquée |
| | createdAt | `datetime_immutable` | non | — | — |
| | createdBy | uuid (FK) | oui | — | `Utilisateur` |
| | appliedAt | `datetime_immutable` | oui | — | posé par `/appliquer` |
| **`ImportedEntityRef`** *(nouveau, interne à `App\Import`, pas une `#[ApiResource]`)* | id | uuid | non | PK | — |
| | establishment | uuid (FK) | non | — | `Etablissement` |
| | type | `string(20)` enum `ImportType` | non | — | même énumération que `ImportBatch.type` |
| | externalRef | `string(190)` | non | **`UNIQUE(establishment_id, type, external_ref)`** | clé du client dans l'ancien système (D100) |
| | targetId | uuid | non | index | id de l'entité cible (`Client.id` pour I1) — **pas de FK** (D2, polymorphe par construction : une seule table pour N types de cibles dans N modules) |
| | importBatchRef | uuid | non | — | dernier lot qui a créé **ou mis à jour** cette ligne (informatif, §0.6 — distinct d'`importBatchRef` sur l'entité cible) |
| | createdAt | `datetime_immutable` | non | — | — |
| | updatedAt | `datetime_immutable` | oui | — | posé à chaque mise à jour |
| **`App\Crm\Entity\Client`** *(modifiée, pas nouvelle)* | importBatchRef | uuid | **oui** | index (`idx_client_import_batch_ref`) | `?Uuid` nu, posé **une seule fois** à la création par import (§0.6), jamais réécrit par une mise à jour |

> id = UUID (`symfony/uid`). Rattachement : `establishment` **direct** sur `ImportBatch`/
> `ImportedEntityRef` (ancre D6/D8/D41). Aucune relation Doctrine `importBatchRef`→`ImportBatch`
> nulle part (D2 littéral de la spec, §5) : toujours un `Uuid` nu.

**Enums** (`App\Import\Enum`, valeurs anglaises D5) :

    ImportType: string
      Customers = 'customers'
      // I2+ (non implémentés par ce lot) : Products, Tariffs, Subscribers, CardCredits, Staff

    ImportBatchStatus: string
      Pending = 'pending'     // réservé à un futur mode asynchrone (D7) — jamais observé en base par ce lot
      Rejected = 'rejected'
      Validated = 'validated'
      Applied = 'applied'
      Reverted = 'reverted'

---

## 2. API (API Platform)

| Ressource / route | Opération | `security:` | Processor/Provider | Groupes sérialisation |
|---|---|---|---|---|
| `ImportBatch` | `GetCollection`, `Get` | `import.read` | — (filtré par `PerimetreImportExtension`, chaîne directe `establishment`) | `import_batch:read` (métadonnées ; `content` **exclu** de la collection, inclus seulement sur `Get`, §0.1) |
| `ImportBatch` | `POST /imports` | `import.create` | `ValidateImportBatchProcessor` (§0.2/§0.7/§0.9) — **ne décore pas** `persist_processor`, gère lui-même `persist()`/`flush()` | in: `import_batch:write` (`type`, `fileName`, `mimeType`, `content`, `expectedTotal?`) ; out: `import_batch:read` |
| `ImportBatch` | `POST /imports/{id}/appliquer` | `import.apply` | `ApplyImportBatchProcessor` (§0.2/§0.5/§0.9), `read: true`, `input: false` | out: `import_batch:read` + `import_batch:read_content` |
| `ImportBatch` | `POST /imports/{id}/annuler` | `import.revert` | `RevertImportBatchProcessor` (§0.6/§0.8), `read: true`, `input: false` | out: `import_batch:read` |

**Filtres** (`ApiFilter(SearchFilter::class, ...)`) : `type` exact, `status` exact, `contentHash` exact
(doublon détectable, §0.9).

**Pièges API Platform rappelés explicitement (demande explicite de la mission, item 6)** :

1. **`read: false` (défaut d'un `Post`) est correct pour `POST /imports`** — il n'existe pas encore de
   ressource à lire, `$data` est l'entité désérialisée du corps. **Mais `read: true` est obligatoire sur
   `.../appliquer` et `.../annuler`** : sans lui, API Platform désérialise le corps de la requête (vide,
   `input: false` de toute façon) en tentant de **construire un nouvel `ImportBatch`** au lieu de charger
   l'existant par `{id}` — échec de validation immédiat (`type`/`content` requis absents), jamais l'entité
   voulue. Même piège déjà rencontré et corrigé sur `ConfirmReconciliationProcessor`/
   `IgnoreStatementLineProcessor` (Treasury).
2. **`input: false` est nécessaire dès que l'action ne prend aucun corps** (les deux sous-routes ci-dessus)
   — sans lui, une absence de corps de requête est traitée comme une erreur de désérialisation avant même
   d'atteindre le processor.
3. **`uriVariables` implicite ici, mais à surveiller** : l'identifiant de route `{id}` correspond
   littéralement à la propriété `id` d'`ImportBatch`, la résolution automatique fonctionne sans
   déclaration explicite. Le jour où une route composite ou un identifiant renommé apparaît, `uriVariables:
   ['id' => new Link(fromClass: ImportBatch::class)]` devient obligatoire — rappel générique, aucune
   action requise par ce plan.
4. **`ImportedEntityRef` n'est volontairement pas une `#[ApiResource]`** — table de correspondance interne
   à `App\Import`, jamais consultée directement par un client API (l'auditabilité passe par `ImportBatch`
   + `Client.importBatchRef`, pas par cette table).

> **`mapping.paths` (`api_platform.yaml`)** — `src/Import/Entity` **n'existe pas encore** dans la liste
> blanche (vérifié). C9 est fail-hard : la ligne ne doit être ajoutée **qu'une fois le dossier présent sur
> `main`** (même règle que rappelée par chaque plan précédent) — signalé à l'intégrateur/au développeur qui
> code T1, à faire dans le même commit que les entités, pas anticipée.

---

## 3. Sécurité & droits

- **Permissions déclarées par ce lot** (module `App\Import`, manifeste à créer — §7 point 7) :
  `import.read`, `import.create`, `import.apply`, `import.revert`. ⚠ Noms **proposés par analogie**, comme
  tous les modules déjà livrés, à arbitrer avec M8 avant figement.
- **Voters** : aucun voter propre, `PermissionVoter` existant suffit.
- **Cloisonnement — gardes explicites, tous échec fermé** :
  1. `ValidateImportBatchProcessor` : `establishment` posé depuis `ContexteEtablissement::
     etablissementActif()`, 422 si absent (aucun champ client, §0.7) — **hors du filet du décorateur
     global** `EstablishmentScopeWriteGuard`, par construction (§0.7), donc responsabilité pleine et
     entière de ce processor, comme `ImportBankStatementProcessor` l'est déjà pour `bankAccount`.
  2. `ApplyImportBatchProcessor`/`RevertImportBatchProcessor` : l'`ImportBatch` est déjà résolu par le
     provider d'item standard, donc déjà filtré par `PerimetreImportExtension` (D8 point 1) —
     **revérification explicite en plus** (D8 littéral, comme `ImportBankStatementProcessor` revérifie
     `bankAccount` même après résolution filtrée) : comparaison de `batch.establishment` avec
     `ContexteEtablissement::idActif()`, 404 sinon (jamais 403 — ne pas confirmer l'existence d'un lot
     hors périmètre).
  3. `CustomerRowImporter` (validate/apply) ne lit ni n'écrit jamais de champ `establishment`/
     `etablissement` venu du fichier (§0.7, testé explicitement, §5).
- **Aucun secret manipulé** — `content` est un fichier de reprise (données nominatives, pas un secret
  cryptographique), mais porte des données personnelles : voir le point RGPD explicite §7 point 5.

---

## 4. Migrations

Trois migrations additives, timestamps après `Version20260831210000` (dernière constatée dans
`app/migrations/` au moment de la rédaction) :

- **`Version20260831220000`** — `CREATE TABLE import_batch` (`id BINARY(16) PK`, `establishment_id
  BINARY(16) NOT NULL FK → org_etablissement`, `type VARCHAR(20) NOT NULL`, `file_name VARCHAR(255) NULL`,
  `mime_type VARCHAR(100) NULL`, `file_size INT NOT NULL`, `content_hash VARCHAR(64) NOT NULL`, `content
  LONGTEXT NOT NULL`, `expected_total DECIMAL(14,2) NULL`, `status VARCHAR(10) NOT NULL DEFAULT
  'pending'`, `row_count INT NOT NULL DEFAULT 0`, `errors JSON NULL`, `created_at DATETIME NOT NULL`,
  `created_by_id BINARY(16) NULL FK → sec_utilisateur`, `applied_at DATETIME NULL`) + `INDEX
  idx_import_batch_establishment (establishment_id)` + `INDEX idx_import_batch_hash (establishment_id,
  content_hash)` (non unique, §0.9) + `INDEX idx_import_batch_status (status)`.
- **`Version20260831220100`** — `CREATE TABLE import_entity_ref` (`id BINARY(16) PK`, `establishment_id
  BINARY(16) NOT NULL FK → org_etablissement`, `type VARCHAR(20) NOT NULL`, `external_ref VARCHAR(190) NOT
  NULL`, `target_id BINARY(16) NOT NULL`, `import_batch_ref BINARY(16) NOT NULL`, `created_at DATETIME NOT
  NULL`, `updated_at DATETIME NULL`) + `UNIQUE INDEX uniq_import_entity_ref (establishment_id, type,
  external_ref)` + `INDEX idx_import_entity_ref_target (target_id)`.
- **`Version20260831220200`** — `ALTER TABLE crm_client ADD COLUMN import_batch_ref BINARY(16) NULL` +
  `INDEX idx_client_import_batch_ref (import_batch_ref)`. **Seule migration touchant une table
  existante** — additive, colonne nullable, aucune donnée existante affectée.

**Down** : les trois réversibles (`DROP TABLE`/`DROP COLUMN`, ordre inverse). Rejouables (D32) — **à
relire sur la migration réellement générée avant commit**, en particulier le type MariaDB effectif de la
colonne `content` (§0.1).

---

## 5. Tests (`tests/Import`)

| Test | Type | Couvre |
|---|---|---|
| **`ValidateImportBatchTest::testFichierAvecUneLigneMauvaiseRejetteToutEtNommeLesLignes`** | Fonctionnel API | **D98** : fichier de 10 lignes dont 1 invalide (nom manquant) → `status = rejected`, `errors` contient l'entrée de la ligne fautive, **aucun** `Client` créé (les 9 valides non plus) |
| `ValidateImportBatchTest::testValidationNecriteRienEnBaseMetier` | Fonctionnel API | **D98 deux temps** : fichier 100 % valide → `status = validated`, `rowCount` correct, **aucun** `Client` en base tant que `/appliquer` n'a pas été appelé |
| `ApplyImportBatchTest::testApplicationEcritToutOuRienDansUneTransaction` | Fonctionnel API | §0.2 — application d'un fichier valide crée exactement `rowCount` `Client` (I1 : que des créations, aucun `externalRef` préexistant) |
| `ApplyImportBatchTest::testApplicationRefuseeSiStatutNestPasValidated` | Fonctionnel API | double appel `/appliquer`, ou appel sur un lot `rejected` → 409, pas de seconde écriture |
| **`ApplyImportBatchTest::testMemeContentHashDejaAppliqueRefuse409`** | Fonctionnel API | §0.9 — un second `ImportBatch` (même établissement, même `contentHash`) validé séparément → `/appliquer` refuse 409, le premier reste seul `applied` |
| `ApplyImportBatchTest::testUpsertExternalRefConnuMetAJourSansDupliquer` | Fonctionnel API | **D100/§0.5** : un second fichier corrigé, même `externalRef` qu'une ligne déjà appliquée → `Client` mis à jour (nouvelle valeur visible), **aucun** second `Client` créé, `Client.importBatchRef` reste celui du **premier** lot |
| `CustomerRowImporterTest::testExternalRefManquantRefuseLaLigne` | Unit | validation métier |
| `CustomerRowImporterTest::testExternalRefDupliqueDansLeMemeFichierRefuseLesDeuxLignes` | Unit | §0.5 — ambiguïté intra-fichier, aucune sélection arbitraire |
| `CustomerRowImporterTest::testPhysiqueSansNomRefuse` / `testMoraleSansRaisonSocialeRefuse` | Unit | cohérence de type (même règle que `Client::validerCoherenceType`, dupliquée en import) |
| **`EstablishmentStampTest::testColonneEtablissementDuFichierIgnoreeEtablissementVientDuServeur`** | Fonctionnel API | **D41** — fichier contenant une colonne `establishment`/`etablissement` avec une valeur arbitraire → le `Client` créé est rattaché à l'établissement de la **session**, jamais à celui du fichier |
| `EstablishmentStampTest::testAucunEtablissementActifRefuse422` | Fonctionnel API | §0.7 |
| **`RevertImportBatchTest::testAnnulationSupprimeExactementCeQueLeLotACree`** | Fonctionnel API | **spec §5** — lot ayant créé 3 clients et mis à jour 1 client préexistant → `/annuler` supprime les 3 créés, laisse intact le mis-à-jour |
| **`RevertImportBatchTest::testAnnulationRefuseeSiUneLigneAServiDepuis`** | Fonctionnel API | **spec §5** — un `Client` créé par le lot est ensuite référencé ailleurs (fixture : une entité liée via une association Doctrine réelle) → `/annuler` refuse 409, rien n'est supprimé |
| `RevertImportBatchTest::testAnnulationRefuseeSiStatutNestPasApplied` | Fonctionnel API | garde de statut |
| `ReverseReferenceCheckerTest::testDetecteUneAssociationDoctrineVersLaCible` | Unit | §0.8 — scan de métadonnées, positif/négatif |
| `CloisonnementImportTest::testLotHorsPerimetreInvisibleEnLecture` | Fonctionnel API | D8 point 1 — `PerimetreImportExtension` |
| `CloisonnementImportTest::testAppliquerSurLotDunAutrePerimetreRefuse404` | Fonctionnel API | D8 point 2 — revérification explicite dans le processor |
| `ImportFileParserTest::testCsvImportParserRestitueColonnesParNomEtNumeroDeLigne` | Unit | §0.4 |
| `ImportBatchContentHashTest::testFiltreContentHashPermetDeDetecterUnDoublonSansBloquer` | Fonctionnel API | §0.9 — deux dépôts identiques coexistent en `validated`, visibles via le filtre |

---

## 6. Tâches (voir `tasks-import-i1.md`, à produire séparément)

- **T1** — `App\Import\{Enum\ImportType, Enum\ImportBatchStatus}` + entité `ImportBatch` (sans API
  Platform) + `App\Import\ImportModule` (manifeste, `permissions()`/`features()` — §7 point 7) + migration
  `Version20260831220000` + ajout `src/Import/Entity` à `api_platform.yaml` `mapping.paths` (**dans le
  même commit**, C9) + tests d'entité.
- **T2** — `App\Import\Dto\{ParsedImportRow, ParsedImportBatch}` + `Port\ImportFileParserInterface` +
  `Service\CsvImportParser` + tests unitaires de parsing — indépendant de T1, peut être fait en parallèle.
- **T3** — `Port\RowImporterInterface` + `Service\RowImporterRegistry` (dispatch générique, aucune
  implémentation) + tests du registre (absence de type → 422 explicite) — dépend de T1.
- **T4** — Entité `ImportedEntityRef` (interne, pas `#[ApiResource]`) + migration
  `Version20260831220100` — dépend de T1.
- **T5** — Colonne `Client.importBatchRef` (`App\Crm`) + migration `Version20260831220200` + index —
  coordination avec le propriétaire du module Crm avant merge (fichier hors `App\Import`, §7 point 4).
- **T6** — `Service\ReverseReferenceChecker` (§0.8, générique, ne dépend d'aucun type précis) + tests —
  indépendant, peut précéder T7.
- **T7** — `Service\CustomerRowImporter` (§0.5, upsert par `externalRef`) + enregistrement dans
  `RowImporterRegistry` — dépend de T2, T3, T4, T5.
- **T8** — `Doctrine\PerimetreImportExtension` (`ImportBatch => []`, établissement direct) + `#[ApiResource]`
  lecture seule (`GetCollection`/`Get`) sur `ImportBatch` + tests de cloisonnement en lecture — dépend de
  T1.
- **T9** — `State\ValidateImportBatchProcessor` (`POST /imports`, §0.2/§0.7/§0.9) + tests (D98 refuse-tout,
  deux temps, contentHash détectable) — dépend de T7, T8.
- **T10** — `State\ApplyImportBatchProcessor` (`POST /imports/{id}/appliquer`, §0.2/§0.5/§0.9) + tests
  (transaction tout-ou-rien, upsert, double-apply, contentHash déjà appliqué) — dépend de T9.
- **T11** — `State\RevertImportBatchProcessor` (`POST /imports/{id}/annuler`, §0.6/§0.8) + tests
  (suppression exacte, refus si référencé) — dépend de T6, T10.
- **T12** — Revue de cohérence (constitution §8) : `GET /health`, non-régression `App\Tests\Crm\*`
  existants (ce lot ajoute une colonne nullable, ne modifie aucun comportement Crm existant), vérification
  qu'aucun libellé n'est en dur (clés `import.*`) — dépend de tout ce qui précède.

---

## 7. Risques / à valider

1. **[CRITIQUE] Écriture cross-module directe `App\Import → App\Crm\Entity\Client`** (§0.5) — contredit
   littéralement D2 (« jamais par appel direct module→module »). Assumé et documenté ici, mais **pas
   encore acté au niveau du contrat de plateforme** (`CONTRACT/manifeste-module.md`) — à traiter avant
   merge : soit une exception nommée pour la famille « import », soit une relecture de D2 pour ce cas
   précis. Sans arbitrage, tout futur `RowImporter` (I2+, vers `Offre`/`Abonnement`/`Personnel`) reproduira
   la même question.
2. **Doublon de règle métier** (§0.5) — la validation `customers` réimplémente la contrainte
   `Client::validerCoherenceType()` (nom requis / raison sociale requise) plutôt que de l'appeler : les
   deux peuvent diverger si `Client` évolue sans que `CustomerRowImporter` soit mis à jour. Alternative
   écartée (appeler directement le validateur de `Client` depuis `App\Import`) : possible sans violer D2
   plus que le reste de ce plan ne le fait déjà (§7 point 1), à reconsidérer une fois ce point tranché.
3. **`ReverseReferenceChecker` ne voit que les associations Doctrine déclarées** (§0.8) — une référence
   portée hors relation ORM (colonne libre, `json`) lui échapperait silencieusement, rendant une
   annulation possible alors qu'une ligne a réellement servi. Aucun cas connu pour `Client` aujourd'hui ;
   **à revérifier avant chaque type I2+**, en particulier `card_credits` (le plus sensible selon la spec
   elle-même, §4).
4. **Migration sur `App\Crm\Entity\Client`, module hors `App\Import`** (T5, §1) — seule migration de ce
   plan touchant une table existante d'un autre module : coordination explicite requise avec quiconque
   possède `App\Crm` en parallèle, plutôt qu'un merge silencieux.
5. **RGPD — `ImportBatch.content` conserve indéfiniment un fichier nominatif complet** (§0.1) — nom,
   prénom, email, téléphone, date de naissance de tous les clients repris, en base, sans politique de
   purge (contrairement à `App\Dms`, qui a `RetentionPolicy`/`PurgeDocumentsCommand`). La constitution
   pose explicitement la conformité RGPD dès la conception (§4 point 5) — ce plan **ne tranche pas** cette
   question, il la nomme : soit une durée de rétention/purge est ajoutée avant mise en production réelle,
   soit une décision explicite documente pourquoi la conservation est jugée nécessaire sans limite (valeur
   probatoire en cas de contestation, §2 de la spec) malgré le principe de minimisation.
6. **`EstablishmentScopeWriteGuard` (D41) ne couvre que `getEtablissement()` (français)**, pas
   `getEstablishment()` (anglais, D5) — constat fait en préparant ce plan, **hors périmètre** de ce lot
   (`App\Import` n'a pas besoin du décorateur global, §0.7) mais signalé : `Project`/`Legal`/`Marketing`
   utilisent déjà la forme anglaise et ne sont donc, en l'état, **pas** protégés par ce garde-fou global —
   une classe D41 rouverte, distincte de celle déjà corrigée le 25/08.
7. **`App\Import\ImportModule` (manifeste) à créer (T1)** — ce plan suppose sa forme (permissions,
   features) sans la détailler en profondeur : `App\Platform\Module\ModuleManifest` déjà utilisé par
   `App\Finance\FinanceModule`/`App\Dms\DmsModule` sert de patron direct, aucune surprise attendue.
8. **`import.applied`/`import.reverted` — émettre un événement ou non** — non demandé par la mission I1,
   mais un futur module consommateur (reprise de documents DMS, écran d'audit transverse) pourrait en
   avoir besoin. Décision différée à I2, pour éviter de figer un contrat d'événement sans consommateur
   identifié (cohérent avec D7 : l'asynchrone s'ajoute quand un besoin réel apparaît).
9. **Fichiers volumineux et validation synchrone** — `POST /imports` parse et valide **tout** dans le même
   appel HTTP (D7 : pas de `messenger`, bus synchrone). Un fichier de plusieurs dizaines de milliers de
   lignes risque un dépassement de délai HTTP. Non traité par ce plan (les fichiers de reprise initiale
   sont typiquement de quelques centaines à quelques milliers de lignes) — à surveiller si un client
   dépasse cet ordre de grandeur ; le statut `Pending` de l'enum est réservé à ce scénario futur (§1).
10. **`mapping.paths` — dossier `src/Import/Entity` à ajouter dans le même commit que le code** (§2,
    fail-hard C9) — rappel explicite, pas une nouveauté propre à ce plan mais une erreur régulièrement
    commise (constat répété sur Finance/Platform/Organisation).

---

## Récapitulatif pour l'intégrateur A

- **Entités nouvelles** : `ImportBatch` (`#[ApiResource]`), `ImportedEntityRef` (interne, pas exposée) —
  `App\Import\Entity`. **Une entité existante modifiée** : `App\Crm\Entity\Client` (+ colonne
  `importBatchRef`, migration T5, coordination requise, §7 point 4).
- **Migrations** : `Version20260831220000` à `…220200` (additives, après `Version20260831210000`, dernière
  du dépôt au moment de la rédaction) — **à relire sur le SQL réellement généré avant commit** (D32,
  attention particulière au type MariaDB de `content`, §0.1).
- **Généralisé, pas dupliqué** : patron `BankStatementImport`/`ImportBankStatementProcessor`
  (`contentHash`, statut enum, transaction, revérification D8 explicite) — deux écarts assumés et
  justifiés (§0.1 contenu persisté, §0.2 deux temps stricts).
- **Nouveau, pensé pour I2+ sans réécriture** : `RowImporterInterface`/`RowImporterRegistry` (un seul
  type construit, `customers`) ; `ImportFileParserInterface`/`CsvImportParser` (XLSX reste un point ouvert
  de la spec, §6) ; `ImportedEntityRef`, table de correspondance générique pour `externalRef`
  (`(establishment, type, externalRef)` unique) plutôt qu'une colonne dupliquée dans chaque module cible ;
  `ReverseReferenceChecker`, scan de métadonnées Doctrine générique pour l'annulation (§0.8).
- **Décision structurante la plus discutable de ce plan** : écriture cross-module directe
  `App\Import → App\Crm\Entity\Client` (§0.5, §7 point 1) — **à faire arbitrer avant tout code**, elle
  conditionne la forme de tout `RowImporter` futur (I2+, vers `Offre`/`Abonnement`/`Personnel`).
- **Risque RGPD nommé, non tranché** : rétention indéfinie du fichier source dans `ImportBatch.content`
  (§7 point 5) — à statuer avant une mise en production réelle avec des données de clients réels.
- **Ordre des tâches** : T1/T2/T3/T4 (framework, parallélisables) → T5 (coordination Crm) → T6 → T7
  (`CustomerRowImporter`, dépend de T2/T3/T4/T5) → T8 (lecture + cloisonnement) → T9/T10/T11 (les trois
  actions, dans cet ordre — chacune dépend de la précédente) → T12 (revue de cohérence).
