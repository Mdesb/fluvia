# Spec — Service transverse d'extraction documentaire (`App\Ocr`, lot `FIN-0`)

- **Lot / module :** **service partagé**, pas un module métier activable par tenant — au sens du
  noyau commun (`COORDINATION/CONTRACT/noyau-commun.md`), au même titre que « Communication » ou
  « Automation ». Consommé par les features `ocr_supplier_invoices` (FIN-2) et `ocr_expense_reports`
  (FIN-3) du module `finance`. Convention de nommage : identifiants techniques en anglais, prose en
  français (`spec-finance-suite.md` §0). Interface : **`DocumentExtractor`** (nom imposé par la
  correction de consigne).
- **Stories couvertes :** **US-OCR-01 à US-OCR-05** — ⚠ **HORS BACKLOG**, service entièrement nouveau.
- **Règles de gestion :** **RG-OCR-01 à RG-OCR-08** (nouvelles).
- **Statut :** brouillon — **contrat d'interface avant tout** ; aucune implémentation n'est codée par
  cette spec, conformément à la consigne (« spécifie le contrat »).

## 1. Objectif

Offrir aux briques **Supplier invoices** (FIN-2) et **Expense reports** (FIN-3) un moyen **optionnel
et jamais bloquant** de pré-remplir un document financier (facture fournisseur, ticket/reçu de note de
frais) à partir d'une image ou d'un PDF, via une **interface stable et branchable**
(`DocumentExtractor`), avec au moins deux implémentations : une **manuelle/dégradée** (toujours
disponible, ne fait qu'accepter l'absence d'extraction) et une **réelle** branchée sur l'API Anthropic
(vision), sans jamais coupler le métier (Factures fournisseur, Notes de frais) à un fournisseur
d'extraction particulier.

## 2. Périmètre

### Inclus
- **Interface `DocumentExtractor`** (port), DTO **`ExtractedDocument`** (résultat), enum
  **`ExtractionStatus`** (US-OCR-01, RG-OCR-01).
- **Adaptateur `ManualExtractorAdapter`** — mode dégradé, **toujours disponible**, ne retourne jamais
  d'extraction (force la saisie manuelle explicitement, jamais une erreur) (US-OCR-02, RG-OCR-02).
- **Adaptateur `AnthropicDocumentExtractorAdapter`** — implémentation réelle branchable, utilisant
  l'API Anthropic (vision multimodale) pour extraire les champs structurés d'une facture/d'un reçu
  (US-OCR-03, RG-OCR-03).
- **Traçabilité de l'extraction** : chaque tentative (réussie, en échec, en faible confiance) est
  journalisée pour audit et pour permettre au consommateur (FIN-2/FIN-3) d'afficher « pré-rempli par
  IA, à vérifier » (US-OCR-04, RG-OCR-04/05).
- **Configuration par établissement/tenant** : choix du fournisseur actif (`manual`/`anthropic`),
  clé API stockée chiffrée (US-OCR-05, RG-OCR-06).

### Exclu (pour l'instant)
- **Toute validation/décision automatique** — l'extraction **ne valide jamais** un document financier
  à la place d'un humain (cohérent §4.4). `App\Ocr` **ne connaît pas** `SupplierInvoice` ni
  `ExpenseReport` (aucune dépendance dans ce sens — le service est **en amont**, générique).
  Si l'implémentation réelle a besoin de préciser le type de document (facture vs reçu) pour améliorer
  l'extraction, cette information est fournie en **paramètre d'appel** (`documentKind`), pas en
  connaissance structurelle du domaine appelant.
- **OCR généraliste** (extraction de texte brut sans structuration) — hors périmètre ; le service
  retourne des **champs structurés typés** (montants, dates, identifiants), pas un flux de texte libre
  (un champ `rawText` optionnel peut être renvoyé pour audit, sans obligation de l'exploiter).
- **Stockage long terme des documents sources** — la gestion du fichier (upload, conservation) reste
  de la responsabilité de l'appelant (`SupplierInvoice.attachment`, `ExpenseLine.receipt`) ; `App\Ocr`
  **reçoit** un fichier/flux en entrée, ne le persiste pas lui-même au-delà de la trace d'audit
  minimale (§4.4).
- **D'autres fournisseurs d'extraction** (Google Document AI, AWS Textract, Mindee…) — l'interface est
  **conçue pour les accueillir** (aucune dépendance à l'API Anthropic dans le contrat lui-même), mais
  seuls `ManualExtractorAdapter` et `AnthropicDocumentExtractorAdapter` sont **spécifiés** ici.

## 3. Acteurs & droits

| Acteur | Peut | Permission |
|---|---|---|
| **Administrateur** | Choisir/configurer le fournisseur d'extraction actif par établissement, saisir la clé API | `ocr.configure` |
| **Consommateur applicatif** (FIN-2, FIN-3, tout module futur) | Appeler `DocumentExtractor::extract()` pour son propre document | *(pas de permission dédiée — gardé par la permission du consommateur, ex. `finance.supplier_invoice_create`)* |
| **Comptable / Superviseur** (lecture) | Consulter la trace d'une extraction (confiance, champs bruts) pour arbitrer un litige de saisie | `ocr.read_extraction` |

⚠ HYPOTHÈSE — noms de permissions proposés par analogie, à arbitrer avec M8.

## 4. Comportements & règles

### 4.1 Contrat d'interface (US-OCR-01, RG-OCR-01)

```php
namespace App\Ocr;

interface DocumentExtractor
{
    /** Nom technique du fournisseur (ex. 'manual', 'anthropic') — pour la traçabilité (§4.4). */
    public function provider(): string;

    /**
     * Extrait les champs structurés d'un document financier. Ne lance jamais d'exception pour un
     * document illisible/non extractible : retourne un `ExtractedDocument` en statut `failed` ou
     * `low_confidence` (RG-OCR-04). Une exception ne peut provenir que d'une erreur d'infrastructure
     * (ex. fournisseur externe injoignable) — auquel cas l'appelant doit basculer en dégradé (§4.2).
     */
    public function extract(DocumentToExtract $document, DocumentKind $kind): ExtractedDocument;
}
```

- **RG-OCR-01** — `DocumentToExtract` (DTO d'entrée) porte le **flux binaire** (image/PDF) et son
  **type MIME**. `DocumentKind` (enum) distingue `supplier_invoice`/`expense_receipt` — seul indice
  métier transmis, **jamais** de référence à une entité (`SupplierInvoice`, `ExpenseLine`) : le
  contrat reste **générique**.
- **RG-OCR-01.1** — `ExtractedDocument` (DTO de sortie) porte, **tous les champs optionnels** (une
  extraction partielle reste un succès partiel) :
  - `status` (`ExtractionStatus` : `success`, `low_confidence`, `failed`) ;
  - `supplierName`, `documentNumber`, `documentDate`, `amountExclTax`, `amountInclTax`, `vatAmount`,
    `vatRate` ;
  - `confidenceScore` (float 0..1, `null` si non fourni par le provider) ;
  - `rawText` (optionnel, pour audit) ;
  - `provider` (nom technique de l'implémentation utilisée).

### 4.2 Mode dégradé — toujours disponible (US-OCR-02, RG-OCR-02)
- **RG-OCR-02** — `ManualExtractorAdapter` implémente `DocumentExtractor` et retourne
  **systématiquement** `ExtractedDocument{status: failed, provider: 'manual'}` (tous les autres champs
  `null`) — **jamais d'exception**, **jamais de latence réseau**. C'est l'implémentation **par
  défaut** quand aucun fournisseur réel n'est configuré pour l'établissement (§4.5, RG-OCR-06), et le
  **repli automatique** si le fournisseur réel échoue (RG-OCR-04) : cohérent avec l'invariant
  « dégradation propre » (`spec-finance-suite.md` §7 invariant #6) — l'OCR n'est **jamais** un point de
  blocage de la saisie d'une facture fournisseur ou d'une note de frais.

### 4.3 Implémentation réelle — Anthropic (US-OCR-03, RG-OCR-03)
- **RG-OCR-03** — `AnthropicDocumentExtractorAdapter` implémente `DocumentExtractor` en soumettant le
  document (image/PDF encodé) à l'API Anthropic (modèle multimodal, capable de lecture de documents),
  avec un prompt structuré demandant une **sortie JSON strictement typée** correspondant aux champs de
  `ExtractedDocument`. Toute réponse **non conforme au schéma attendu** (JSON invalide, champ hors
  format) est traitée comme `status: low_confidence` (pas `failed` — le document a bien été traité,
  la confiance est simplement dégradée) plutôt que de propager une exception au consommateur.
  - ⚠ HYPOTHÈSE — le **modèle exact**, le **prompt**, le **format de sortie strict (JSON schema /
    structured output)** et la **gestion du coût/quota** ne sont pas fixés par cette spec
    (comportement observable uniquement) — à trancher au plan technique.
  - ⚠ HYPOTHÈSE — **limite de taille/format de document** (résolution image, nombre de pages PDF) non
    fixée — à cadrer avec les contraintes de l'API Anthropic au moment de l'implémentation.

### 4.4 Traçabilité de l'extraction (US-OCR-04, RG-OCR-04/05)
- **RG-OCR-04** — Toute tentative d'extraction (succès, faible confiance, échec) produit un enregistrement
  `ExtractionAttempt` (entité, persistée — **distincte** du DTO `ExtractedDocument` retourné à
  l'appelant) : `documentKind`, `provider`, `status`, `confidenceScore`, `extractedFields` (JSON),
  `requestedAt`, `requestedBy` — **jamais** le document source lui-même (référence uniquement,
  cohérent avec « aucun secret/PII inutile », `catalogue-evenements.md` règles de nommage appliquées
  par analogie à la traçabilité).
- **RG-OCR-05** — Une extraction en `low_confidence` (`confidenceScore < seuil paramétrable`, défaut
  `0.7`) est **présentée à l'utilisateur avec un avertissement visuel explicite** (« pré-rempli par
  IA, à vérifier ») — jamais silencieusement traitée comme une extraction fiable. Ce seuil est une clé
  de configuration, pas une valeur codée en dur (constitution §4.4).

### 4.5 Configuration par tenant (US-OCR-05, RG-OCR-06)
- **RG-OCR-06** — Un établissement/tenant choisit son fournisseur actif (`manual` par défaut à
  l'activation de la feature, jusqu'à configuration explicite) via `OcrProviderConfig` (`provider`,
  `apiKey` chiffrée au repos — même patron que les autres secrets du dépôt, ex.
  `App\Securite\Crypto\ChiffreurSecret`/`App\Sepa\Service\ChiffreurIban`, **pas un troisième
  mécanisme de chiffrement**). `ocr.configure` requis pour modifier.

## 5. Objets de données

| Objet | Champ | Type | Contraintes | Notes |
|---|---|---|---|---|
| *(DTO, non persisté)* `DocumentToExtract` | content, mimeType | binary, string | requis | entrée |
| *(DTO, non persisté)* `ExtractedDocument` | status | enum ExtractionStatus {success, low_confidence, failed} | requis | RG-OCR-01.1 |
| | supplierName, documentNumber | string, nullable | — | — |
| | documentDate | date, nullable | — | — |
| | amountExclTax, amountInclTax, vatAmount, vatRate | decimal, nullable | — | — |
| | confidenceScore | float 0..1, nullable | — | RG-OCR-05 |
| | rawText | string, nullable | — | audit uniquement |
| | provider | string | requis | RG-OCR-01 |
| **`ExtractionAttempt`** *(entité persistée)* | id | uuid | PK | RG-OCR-04 |
| | establishment | ref Etablissement (socle) | requis | cloisonnement |
| | documentKind | enum {supplier_invoice, expense_receipt} | requis | — |
| | provider | string | requis | — |
| | status | enum ExtractionStatus | requis | — |
| | confidenceScore | float, nullable | — | — |
| | extractedFields | json | — | snapshot du DTO retourné |
| | requestedAt, requestedBy | datetime, ref Utilisateur | requis | — |
| **`OcrProviderConfig`** | id, establishment | uuid, ref | 1 par établissement | RG-OCR-06 |
| | provider | enum {manual, anthropic} | défaut = manual | — |
| | apiKey | string, chiffré | jamais exposé en clair via l'API | — |
| | confidenceThreshold | float 0..1 | défaut = 0.7 | RG-OCR-05 |

## 6. Critères d'acceptation

- **CA-1 (US-OCR-01)** — *Étant donné* n'importe quelle implémentation de `DocumentExtractor`, *quand*
  `extract()` est appelée sur un document illisible, *alors* elle retourne un `ExtractedDocument` avec
  `status: failed` — **jamais** une exception non gérée propagée au consommateur.
- **CA-2 (US-OCR-02, RG-OCR-02)** — *Étant donné* un établissement sans fournisseur configuré, *quand*
  une brique consommatrice tente une extraction, *alors* `ManualExtractorAdapter` répond
  **immédiatement** `status: failed`, sans latence réseau ni erreur bloquante ; la saisie manuelle
  reste **pleinement fonctionnelle**.
- **CA-3 (US-OCR-03, RG-OCR-03)** — *Étant donné* un établissement configuré sur `anthropic` et une
  facture lisible, *quand* l'extraction est appelée, *alors* elle retourne `status: success` avec les
  champs structurés renseignés et un `confidenceScore`.
- **CA-4 (US-OCR-03, cas API injoignable)** — *Étant donné* l'API Anthropic indisponible, *quand*
  l'extraction est tentée, *alors* le consommateur (FIN-2/FIN-3) **bascule sur le mode dégradé** (§4.2)
  plutôt que de bloquer la saisie — comportement observable : la facture/note reste saisissable
  manuellement.
- **CA-5 (US-OCR-04/05)** — *Étant donné* une extraction retournant `confidenceScore = 0.4`
  (< seuil 0.7), *alors* l'`ExtractionAttempt` est journalisée en `low_confidence`, et le champ
  correspondant est présenté à l'utilisateur avec l'avertissement « à vérifier ».
- **CA-6 (US-OCR-05, RG-OCR-06)** — *Étant donné* une `apiKey` configurée, *quand* la configuration est
  consultée via l'API, *alors* la clé **n'apparaît jamais en clair**.

## 7. Cas limites
- **Document dans une langue non française** — ⚠ HYPOTHÈSE : non exclu par le contrat (le prompt
  Anthropic peut gérer plusieurs langues), mais non testé/garanti par cette spec.
- **PDF multi-pages avec plusieurs factures** — ⚠ HYPOTHÈSE : hors périmètre v1, un appel = un
  document = une extraction ; le découpage d'un PDF multi-factures reste manuel (à faire par
  l'utilisateur avant upload).
- **Extraction réussie mais montants incohérents** (`amountExclTax + vatAmount ≠ amountInclTax`) — Le
  service **ne corrige ni ne rejette pas** ; il retourne les champs tels qu'extraits avec un
  `confidenceScore` potentiellement dégradé si le provider le détecte lui-même ; la cohérence finale
  reste **validée par l'humain** au moment de la confirmation du brouillon (§0, principe repris de
  RG-SINV-02/RG-EXP-03).
- **Changement de fournisseur en cours d'établissement** (`manual` → `anthropic` ou inverse) — Sans
  effet rétroactif sur les `ExtractionAttempt` déjà journalisées (append-only, cohérent `RG-SOCLE-07`).

## 8. Dépendances
- **Dépend de : socle L0** — permissions `ocr.*`, cloisonnement établissement, chiffrement des secrets
  (patron réutilisé de `App\Securite\Crypto\ChiffreurSecret`/`App\Sepa\Service\ChiffreurIban`).
- **Consommé par : Supplier invoices (FIN-2)** et **Expense reports (FIN-3)** — en option, jamais
  bloquant.
- **Point de coordination : `COORDINATION/CONTRACT/noyau-commun.md`** — proposition d'inscrire
  « Document extraction (OCR) » comme brique du noyau commun (au même titre que Communication/
  Automation), dans la mesure où d'autres domaines futurs (ex. contrôle de pièces d'identité,
  numérisation de contrats) pourraient vouloir réutiliser exactement ce même contrat — **non tranché**,
  à faire remonter au propriétaire du contrat de plateforme.

---

## Points ouverts / hypothèses (récapitulatif)
1. **⚠ HORS BACKLOG** — service entièrement nouveau (en-tête).
2. Modèle Anthropic exact, prompt, format de sortie strict, gestion du coût/quota — non fixés,
   comportement observable uniquement (§4.3).
3. Limite de taille/format de document — non fixée (§4.3).
4. Documents multilingues — non garantis (§7).
5. PDF multi-factures — hors périmètre v1 (§7).
6. OCR candidat au noyau commun — proposition non tranchée (§8).
