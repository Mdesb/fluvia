# Plan technique — Service transverse d'extraction documentaire (`App\Ocr`, lot `FIN-0`)

- **Spec source :** specs/finance/spec-ocr.md (+ manifeste de suite specs/finance/spec-finance-suite.md §0/§3)
- **Stack :** Symfony 7 · API Platform · Doctrine/MariaDB
- **Contrat de plateforme :** COORDINATION/CONTRACT/noyau-commun.md (candidat noyau commun, non tranché),
  COORDINATION/CONTRACT/manifeste-module.md, COORDINATION/CONTRACT/i18n-traduction.md (libellés = clés,
  jamais de chaîne en dur), COORDINATION/CONTRACT/catalogue-evenements.md (aucun événement émis — §7)
- **Dépend de :** socle L0 (`App\Securite`, `ContexteEtablissement`, `PermissionVoter`), patron de
  chiffrement réversible déjà posé par `App\Securite\Crypto\ChiffreurSecret` / `App\Sepa\Service\
  ChiffreurIban` (réutilisé, pas un 3ᵉ mécanisme, RG-OCR-06)
- **Couvre :** US-OCR-01 à US-OCR-05 · RG-OCR-01 à RG-OCR-08 · CA-1 à CA-6
- **Statut du périmètre :** service **pur** au maximum — seules 2 entités sont persistées parce que la
  spec l'exige explicitement (RG-OCR-04 traçabilité, RG-OCR-06 configuration par tenant) ; le contrat
  d'interface lui-même (`DocumentExtractor`, DTO, enums, adaptateurs) ne porte **aucun schéma**.

> **Réutilisé, non recréé** — `App\Securite\Crypto\ChiffreurSecret` (patron libsodium
> `crypto_secretbox`, clé dédiée par usage) ; `App\Securite\Service\ContexteEtablissement` (périmètre
> serveur, en-tête `X-Etablissement`) ; `App\Securite\Security\PermissionVoter` (`is_granted('PERM',
> 'module.action')`) ; le patron de registre tagué Symfony (`#[AutowireIterator]`) déjà utilisé par
> `App\Compta\Export\ExportComptableResolver`/`App\Compta\Regime\RegimeComptableResolver`.

---

## 0. Décisions d'architecture

### 0.1 Le contrat littéral de la spec, respecté au mot près

```php
namespace App\Ocr;

interface DocumentExtractor
{
    public function provider(): string;
    public function extract(DocumentToExtract $document, DocumentKind $kind): ExtractedDocument;
}
```

`DocumentExtractor` vit **à la racine** `app/src/Ocr/DocumentExtractor.php` (pas de sous-namespace
`Port`/`Contract`), conformément au code-exemple imposé par la spec (§4.1). Tout le reste (DTO, enums,
entités, adaptateurs, services d'orchestration) est rangé dans des sous-namespaces `App\Ocr\Dto`,
`App\Ocr\Enum`, `App\Ocr\Entity`, `App\Ocr\Adapter`, `App\Ocr\Service`, `App\Ocr\Exception` — même
convention que `App\Compta`/`App\Sepa`.

### 0.2 Le point non couvert littéralement par la spec : qui sélectionne le provider par tenant, et qui bascule en dégradé ?

La spec (§4.1) dit : *« Une exception ne peut provenir que d'une erreur d'infrastructure […] auquel cas
l'appelant doit basculer en dégradé »* — littéralement, elle met la responsabilité du fallback sur le
**consommateur** (FIN-2/FIN-3). Dupliquer ce `try/catch` + choix de provider + seuil de confiance dans
chaque module consommateur romprait le principe DRY et le risque de divergence entre FIN-2 et FIN-3.

**Décision** (au-delà du texte, mais strictement conforme à tous les CA) : `App\Ocr` fournit **un
service composite qui implémente lui-même `DocumentExtractor`** —
`App\Ocr\Service\TenantAwareDocumentExtractor` — qui :
1. résout l'établissement actif (`ContexteEtablissement`) et son `OcrProviderConfig` (défaut `manual`
   si absent, RG-OCR-06) ;
2. délègue à l'adaptateur concret (`ManualExtractorAdapter` ou une instance
   `AnthropicDocumentExtractorAdapter` construite à la volée par une factory avec la clé déchiffrée du
   tenant) ;
3. **capture tout `\Throwable`** de l'adaptateur délégué et retombe sur un résultat `failed` façon
   `ManualExtractorAdapter` (CA-4) — la dégradation est donc automatique et centralisée, **sans que
   FIN-2/FIN-3 aient à écrire le moindre `try/catch`** ;
4. **réévalue** le `confidenceScore` retourné par le delegate contre `OcrProviderConfig::confidenceThreshold`
   (RG-OCR-05, défaut `0.7`) et **rétrograde** un `success` en `low_confidence` si le seuil n'est pas
   atteint — logique tenant-aware, donc **hors** des adaptateurs bruts (qui restent génériques, sans
   connaissance du tenant) ;
5. journalise **systématiquement** un `ExtractionAttempt` (RG-OCR-04), y compris en cas de bascule
   infrastructure.

`App\Ocr\DocumentExtractor` est **aliasé en DI** vers `TenantAwareDocumentExtractor` : tout consommateur
qui type-hinte l'interface obtient ce comportement résilient par défaut. Les adaptateurs bruts
(`ManualExtractorAdapter`, `AnthropicDocumentExtractorAdapter`) restent **autowireables directement par
leur classe** pour les tests unitaires ciblés (CA-1, CA-2, CA-3) et pour un consommateur qui voudrait
explicitement forcer un provider (cas non couvert par cette spec, laissé ouvert).

⚠ **Point à documenter au client** : cette composition n'est pas décrite littéralement dans
`spec-ocr.md`, mais ne contredit **aucun** CA — elle les renforce (CA-4 devient garanti par construction
plutôt que délégué à chaque consommateur). À confirmer/entériner avec le propriétaire de la spec avant
implémentation (point ouvert §7).

### 0.3 Sélection de provider — registre tagué, pas de `switch`

`App\Ocr\Service\DocumentExtractorRegistry` (même patron que `ExportComptableResolver`) :
`#[AutowireIterator('ocr.extractor')]` sur `ManualExtractorAdapter` (statique, autoconfiguré) —
`AnthropicDocumentExtractorAdapter` n'est **pas** taggé dans ce registre car il n'existe pas en instance
unique (une clé API par tenant) : il est produit à la demande par
`App\Ocr\Service\AnthropicDocumentExtractorAdapterFactory::pour(OcrProviderConfig $config): DocumentExtractor`.

### 0.4 Chiffrement de la clé API — réutilisation stricte du patron `ChiffreurSecret`

RG-OCR-06 impose *« même patron que les autres secrets du dépôt […] pas un troisième mécanisme »*.
`App\Securite\Crypto\ChiffreurSecret` chiffre déjà le secret MFA via libsodium `crypto_secretbox`
avec une clé dédiée (`MFA_ENCRYPTION_KEY`). On **réutilise la classe telle quelle** (aucune duplication
de code), avec une **seconde instance de service**, liée à une **clé dédiée par usage** (invariant noyau
commun #4) :

```yaml
# app/config/services.yaml
App\Ocr\Service\ChiffreurApiKeyOcr:
    class: App\Securite\Crypto\ChiffreurSecret
    arguments: ['%env(OCR_API_KEY_ENCRYPTION_KEY)%']
```

Les arguments explicites d'une définition de service Symfony **priment** sur l'attribut `#[Autowire(env:
'MFA_ENCRYPTION_KEY')]` porté par le paramètre du constructeur — ce n'est donc pas un nouvel algorithme,
juste une seconde instance avec sa propre clé d'environnement (`OCR_API_KEY_ENCRYPTION_KEY`, valeur de
convenance dérivée en dev/test comme `SEPA_IBAN_KEY`, secret/vault en production).

### 0.5 Le seul cas d'exception légitime (RG-OCR-01 docblock)

`App\Ocr\Exception\OcrProviderUnavailableException extends \RuntimeException` — levée **uniquement**
par `AnthropicDocumentExtractorAdapter::extract()` sur un échec de transport HTTP (timeout, DNS,
5xx du fournisseur) — **jamais** pour un document illisible ou une réponse JSON non conforme (ces deux
cas retournent respectivement `status: failed`/`status: low_confidence`, RG-OCR-03). C'est cette
exception (ou tout `\Throwable` par défense en profondeur) que `TenantAwareDocumentExtractor` capture
(§0.2).

---

## 1. Entités & schéma

| Entité (`App\Ocr\Entity\*`) | Champ | Type Doctrine | Null | Index/Contrainte | Relation |
|---|---|---|---|---|---|
| **`ExtractionAttempt`** | id | `uuid` | non | PK | — |
| | establishment | `uuid` (FK) | non | index | `App\Organisation\Entity\Etablissement` (socle) — cloisonnement |
| | documentKind | `string(20)` enum `DocumentKind` | non | — | valeur applicative (enum PHP `DocumentKind`) |
| | provider | `string(30)` | non | — | nom technique (`manual`/`anthropic`/futur) |
| | status | `string(20)` enum `ExtractionStatus` | non | index (avec `establishment`) | `success`/`low_confidence`/`failed` |
| | confidenceScore | `float` | oui | — | RG-OCR-05 |
| | extractedFields | `json` | oui | — | snapshot du DTO `ExtractedDocument` retourné (hors `rawText` volumineux, tronqué) |
| | requestedAt | `datetime_immutable` | non | index | — |
| | requestedBy | `uuid` (FK) | oui | — | `App\Securite\Entity\Utilisateur` — `null` si appel système |
| **`OcrProviderConfig`** | id | `uuid` | non | PK | — |
| | establishment | `uuid` (FK) | non | **unique** | `App\Organisation\Entity\Etablissement` — 1 config par établissement (RG-OCR-06) |
| | provider | `string(16)` enum `OcrProvider` (`manual`\|`anthropic`) | non | défaut `manual` | — |
| | apiKeyChiffree | `text` | oui | **jamais** `#[Groups]` | chiffré via `ChiffreurApiKeyOcr` (§0.4) |
| | confidenceThreshold | `decimal(3,2)` | non | défaut `0.70` | RG-OCR-05 |
| | createdAt / updatedAt | `datetime_immutable` | non | — | audit léger |

> id = UUID (`symfony/uid`). Rattachement multi-entités : **Établissement** (socle) — pas de
> `ProfilExploitant` (M6) : l'OCR est un service transverse, indépendant de la comptabilité, activable
> par n'importe quel domaine consommateur (RG-OCR-06 parle d'« établissement/tenant », pas de profil
> comptable).

### 1bis. DTO non persistés (`App\Ocr\Dto\*`, RG-OCR-01/01.1)

| DTO | Champ | Type | Notes |
|---|---|---|---|
| `DocumentToExtract` | `content` | `string` (binaire) | requis |
| | `mimeType` | `string` | requis |
| `ExtractedDocument` *(final readonly, `withStatus()` wither pour §0.2 point 4)* | `status` | `ExtractionStatus` | requis |
| | `supplierName`, `documentNumber` | `?string` | — |
| | `documentDate` | `?\DateTimeImmutable` | — |
| | `amountExclTax`, `amountInclTax`, `vatAmount` | `?string` (décimal texte, cohérent avec `TauxTva::taux`/`Facture` — jamais de flottant monétaire) | — |
| | `vatRate` | `?string` | — |
| | `confidenceScore` | `?float` (0..1) | RG-OCR-05 |
| | `rawText` | `?string` | audit uniquement, non exploité par défaut |
| | `provider` | `string` | requis |

### 1ter. Enums (`App\Ocr\Enum\*`)
- `ExtractionStatus` : `Success = 'success'` · `LowConfidence = 'low_confidence'` · `Failed = 'failed'`.
- `DocumentKind` : `SupplierInvoice = 'supplier_invoice'` · `ExpenseReceipt = 'expense_receipt'`.
- `OcrProvider` : `Manual = 'manual'` · `Anthropic = 'anthropic'` — liste **fermée** ici volontairement
  (contrairement à `ExpenseAccountMapping.expenseNatureCode` côté FIN-1) : ce ne sont pas des catégories
  métier paramétrables mais des **implémentations logicielles** enregistrées ; ajouter un fournisseur =
  ajouter un cas d'enum + un adaptateur, acte de développement, pas de paramétrage tenant.

---

## 2. API (API Platform)

| Ressource | Opérations | `security:` | Groupes sérialisation | Filtres |
|---|---|---|---|---|
| `OcrProviderConfig` | `GetCollection`, `Get`, `Post`, `Patch` | `is_granted('PERM', 'ocr.configure')` (les 4 opérations — lecture de config = geste admin, RG-OCR-06) | `ocr_config:read` / `ocr_config:write` — **`apiKeyChiffree` jamais dans un groupe** ; expose un `hasApiKey(): bool` calculé (`Groups(['ocr_config:read'])`) à la place | `establishment` exact |
| `ExtractionAttempt` | `GetCollection`, `Get` | `is_granted('PERM', 'ocr.read_extraction')` | `extraction_attempt:read` | `documentKind`, `status`, `establishment` exact |

Pas d'opération `POST /ocr/extract` exposée en HTTP : `App\Ocr` est **consommé en PHP** (injection de
service `DocumentExtractor`) par les points d'upload de FIN-2/FIN-3 — cohérent avec « service transverse,
pas un module métier » (`spec-finance-suite.md` §3). Une éventuelle route de test manuel (bac à sable
admin) est **hors périmètre FIN-0**, à réévaluer si un besoin produit apparaît.

---

## 3. Sécurité & droits

- Permissions nouvelles (module `ocr`, à créer dans `sec_permission`, via l'API `Permission` existante
  ou fixtures de test — **pas** de migration de données, cohérent avec le patron des autres modules) :
  - `ocr.configure` — choisir/configurer le fournisseur actif, saisir la clé API (Administrateur).
  - `ocr.read_extraction` — consulter la trace d'une extraction (Comptable/Superviseur, arbitrage litige
    de saisie).
- **Aucune permission dédiée pour `extract()`** — l'appel est gardé par la permission du **consommateur**
  (ex. `finance.supplier_invoice_create`), cf. spec §3 : `App\Ocr` ne réimplémente pas de RBAC métier.
- **Cloisonnement** : `ExtractionAttempt`/`OcrProviderConfig` portent `establishment` **en direct**
  (comme `MandatSepa`/`ConfigCreancierSepa`, pas de chaîne de jointure) → nouvelle extension Doctrine
  `App\Ocr\Doctrine\PerimetreOcrExtension` (`QueryCollectionExtensionInterface`/`QueryItemExtensionInterface`),
  **copie stricte** du patron `App\Sepa\Doctrine\PerimetreSepaExtension` (jointure `Affectation` sur
  `etablissement` + utilisateur courant, `CHAINES = [OcrProviderConfig::class => [], ExtractionAttempt::class => []]`).
  Périmètre **dérivé serveur** (`Security::getUser()` + `Affectation`), **jamais** un id transmis par le
  client — conforme à l'invariant #1 du noyau commun et au rappel explicite du contexte de mission (5
  IDOR déjà corrigés ailleurs, à ne pas reproduire).
- `TenantAwareDocumentExtractor` résout l'établissement actif via `ContexteEtablissement::idActif()`
  **uniquement** (jamais un id porté par `DocumentToExtract`/`DocumentKind`, qui restent des DTO
  génériques sans notion de tenant) — échec fermé : si aucun établissement actif n'est résolvable, le
  service journalise `provider: 'manual'`/`status: failed` (dégradation propre) plutôt que de planter.
- Aucun secret (clé API en clair) n'est jamais sérialisé — testé négativement (§5), même garde que l'IBAN
  SEPA.

---

## 4. Migrations

Une seule migration additive, aucune dépendance destructive, aucun impact sur un schéma existant :

- **`Version20260819130000`** — `CREATE TABLE ocr_provider_config` (id, establishment_id FK unique,
  provider, api_key_chiffree TEXT NULL, confidence_threshold DECIMAL(3,2) DEFAULT '0.70', created_at,
  updated_at) + `CREATE TABLE ocr_extraction_attempt` (id, establishment_id FK indexé, document_kind,
  provider, status, confidence_score FLOAT NULL, extracted_fields JSON NULL, requested_at, requested_by_id
  FK NULL) + `FOREIGN KEY` vers `org_etablissement`/`sec_utilisateur`, index
  `IDX_OCR_ATTEMPT_ETAB_STATUS (establishment_id, status)`.

---

## 5. Tests

| Test | Type | Couvre |
|---|---|---|
| `ManualExtractorAdapterTest` | Unit | CA-1/CA-2 : retourne toujours `status: failed`/`provider: manual`, aucune exception, aucune latence (mesure de temps d'exécution quasi nulle) |
| `AnthropicDocumentExtractorAdapterTest` (HttpClient mocké — `MockHttpClient`) | Unit | CA-3 : réponse JSON conforme → `status: success` + champs + `confidenceScore` ; CA-3bis : JSON non conforme → `status: low_confidence` (jamais d'exception) ; échec transport (mock 500/timeout) → lève `OcrProviderUnavailableException` |
| `TenantAwareDocumentExtractorTest` | Unit | CA-2 (aucune config → délègue à `manual`) ; CA-4 (delegate anthropic lève `OcrProviderUnavailableException` → résultat `failed` retourné, **jamais** propagée) ; CA-5 (delegate `success` avec `confidenceScore=0.4` et seuil `0.7` → rétrogradé `low_confidence`) ; un `ExtractionAttempt` est bien journalisé dans chaque cas |
| `DocumentExtractorRegistryTest` | Unit | résolution par `provider()`, erreur explicite si provider inconnu |
| `ChiffreurApiKeyOcrTest` | Unit | round-trip chiffrement/déchiffrement, échec propre sur valeur corrompue (même patron que `ChiffreurIbanTest`/`ChiffreurSecretTest` existants) |
| `OcrProviderConfigApiTest` | Fonctionnel API | CA-6 : `apiKeyChiffree` **jamais** dans la réponse JSON (positif : champ absent du payload, pas juste `null`) ; `hasApiKey` reflète l'état ; `security: ocr.configure` sur les 4 opérations (403 sans permission) |
| `ExtractionAttemptApiTest` | Fonctionnel API | lecture seule (`POST`/`PATCH`/`DELETE` → 405/404 non exposés) ; filtre `documentKind`/`status` |
| `CloisonnementOcrTest` | Fonctionnel API | même patron que `App\Tests\Compta\Api\CloisonnementTest` : un utilisateur affecté à l'établissement B ne voit ni la config ni les tentatives de l'établissement A (403/404, jamais un filtre silencieux qui renverrait une liste vide sans distinguer « vide » de « hors périmètre ») |
| `ExtractionAttemptTraceabilityTest` | Fonctionnel/Unit | RG-OCR-04 : chaque appel `extract()` (quel que soit le statut) produit exactement 1 `ExtractionAttempt`, jamais le contenu binaire du document source (seule une référence/aucune donnée du fichier n'est en base) |

---

## 6. Tâches (voir tasks-ocr.md)

- **T1** — Contrat : `DocumentExtractor` (racine), DTO `DocumentToExtract`/`ExtractedDocument`, enums
  `ExtractionStatus`/`DocumentKind`/`OcrProvider`, exception `OcrProviderUnavailableException`.
- **T2** — `ManualExtractorAdapter` (+ tag `ocr.extractor`) et ses tests (aucune dépendance).
- **T3** — Entités `OcrProviderConfig`/`ExtractionAttempt` + migration `Version20260819130000` +
  extension de cloisonnement `PerimetreOcrExtension` (copiée du patron SEPA) + `ChiffreurApiKeyOcr`
  (service défini en YAML, §0.4) + `OCR_API_KEY_ENCRYPTION_KEY` en `.env`/`.env.test`.
- **T4** — API Platform `OcrProviderConfig` (CRUD admin) + permissions `ocr.configure`/`ocr.read_extraction`
  (fixtures de test `App\Ocr\DataFixtures\OcrFixtures`) + tests CA-6.
- **T5** — `AnthropicDocumentExtractorAdapter` (HttpClient Symfony, prompt JSON structuré — modèle/prompt
  exact ⚠ à trancher, §7) + `AnthropicDocumentExtractorAdapterFactory` + tests avec `MockHttpClient`.
- **T6** — `DocumentExtractorRegistry` + `OcrProviderConfigResolver` + `TenantAwareDocumentExtractor`
  (orchestration §0.2) + alias DI `App\Ocr\DocumentExtractor → TenantAwareDocumentExtractor` + tests CA-2/4/5.
- **T7** — API Platform `ExtractionAttempt` (lecture seule) + tests fonctionnels + `CloisonnementOcrTest`.
- **T8** — Revue de cohérence finale (constitution §8) : `GET /health` reste vert, aucun secret en clair
  observable, i18n des libellés d'erreur (`ocr.error.*` clés, jamais de message métier en dur dans les
  exceptions HTTP exposées à l'API — vérifier `UnprocessableEntityHttpException`/messages traduits côté
  consommateur, pas côté `App\Ocr` qui ne renvoie pas de payload utilisateur directement).

---

## 7. Risques / à valider

1. **Modèle Anthropic exact, prompt, format de sortie strict (JSON schema / structured output), gestion
   du coût/quota** — non fixés par la spec (comportement observable uniquement). Proposition
   d'implémentation : API Messages Anthropic, un modèle multimodal courant au moment du build, prompt
   demandant une sortie JSON strict correspondant 1:1 aux champs `ExtractedDocument` — **à valider avec
   le propriétaire produit avant implémentation** (impact coût direct, pas seulement technique).
2. **Limite de taille/format de document** (résolution image, pages PDF) — non fixée, à cadrer selon les
   contraintes réelles de l'API Anthropic au moment du build.
3. **Documents multilingues / PDF multi-factures** — hors garantie v1, comportement non testé
   spécifiquement (cohérent spec §7).
4. **Composite `TenantAwareDocumentExtractor` (§0.2)** — décision d'architecture qui va au-delà du texte
   littéral de la spec (qui met le fallback côté « appelant ») ; ne contredit aucun CA mais **doit être
   validée** avec le propriétaire de `spec-ocr.md` avant implémentation, car elle centralise une
   responsabilité (choix de provider + seuil + fallback) que la spec avait laissée aux consommateurs.
5. **OCR candidat au noyau commun** (`noyau-commun.md`, non tranché) — cette suite construit néanmoins
   `App\Ocr` comme un service techniquement autonome (pas de dépendance dure à `App\Finance`), ce qui
   rend la migration vers le noyau commun **triviale** le jour où elle est actée — aucun blocage
   technique identifié.
6. **`hash`/format `extractedFields`** — snapshot JSON du DTO retourné : à borner en taille (ne pas
   stocker un `rawText` de plusieurs Mo) — proposition : tronquer `rawText` à N caractères avant
   persistance dans `ExtractionAttempt.extractedFields`, valeur exacte de N à trancher à l'implémentation
   (non bloquant, valeur de convenance type 5000 caractères).
7. **Nom exact des permissions** (`ocr.configure`, `ocr.read_extraction`) — proposées par analogie avec
   le modèle `module × action`, à arbitrer avec M8 avant figement (comme toutes les permissions
   proposées par cette suite, cf. `spec-finance-suite.md` §5).
