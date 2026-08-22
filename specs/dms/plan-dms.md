# Plan technique — GED / gestion documentaire interne (`App\Dms`, lot `DMS-1`)

- **Spec source :** specs/dms/spec-dms.md (statut **arbitrée**, bloc « Arbitrages intégrateur D18 » — bornes
  fermes, non rouvertes ici)
- **Stack :** Symfony 7 · API Platform · Doctrine/MariaDB · libsodium (`ext-sodium`)
- **Couvre :** US-DMS-01 à 06 (hors backlog, cf. spec en-tête) · RG-DMS-01 à 29 · CA-1 à CA-14
- **Nature du module :** service **transverse partagé** (`capability(): null`), même famille que `App\Ocr` —
  pas un module métier vendu/activable par établissement (spec §1, arbitrage D18 pt.1 sur le manifeste).
- **Catalogue d'événements :** les 8 événements `document.*` figurent **déjà**
  `COORDINATION/CONTRACT/catalogue-evenements.md` lignes 66-73 — RG-DMS-21/RG-PLAT-06 sont donc déjà
  satisfaites *avant* l'implémentation ; `DmsModule::eventsEmitted()` doit correspondre exactement à cette
  liste, aucune ligne à ajouter au contrat par ce lot.

> **Réutilisé, non recréé** — `App\Securite\Service\ContexteEtablissement` (périmètre serveur, jamais un id
> client), `App\Securite\Security\PermissionVoter` (`is_granted('PERM', 'module.action')`),
> `App\Securite\Service\CalculateurDroits`, `App\Platform\Event\{DomainEvent,EventBus,EventTenant,
> EventSubject,EventActor}`, `App\Platform\Module\ModuleManifest`. **Patrons copiés** (jamais appelés
> directement, module transverse = zéro dépendance dure) : `PerimetreOcrExtension` /
> `PerimetreFinanceExtension` (cloisonnement Doctrine), `App\Stock\Security\PerimetreEtablissementVerificateur`
> (revérification serveur hors lecture), `App\Vente\Nf525\InalterabiliteListener` (garde ORM append-only),
> `App\Crm\Command\AppliquerConservationCommand` (commande planifiée de conservation).

---

## 0. Décisions d'architecture (au-delà du texte littéral de la spec)

### 0.1 La contrainte de clé circulaire `Document.currentVersion` ⇄ `DocumentVersion.document`

La table de données (spec §5) exige que `Document.currentVersion` soit **requis dès la création** et que
`DocumentVersion.document` soit **requis**. Les deux FK NOT NULL en même temps sont **impossibles à insérer**
en une seule transaction MariaDB classique (pas de FK différée comme PostgreSQL) : il faut une ligne
`Document` existante pour insérer `DocumentVersion.document_id`, mais `Document.current_version_id` ne peut
pointer une version qui n'existe pas encore.

**Décision :** `dms_document.current_version_id` est **NULLABLE au niveau schéma** (contrainte technique),
mais l'invariant métier « toujours renseigné » est garanti par construction :
1. `UploadDocumentHandler` (service partagé HTTP + `DocumentStore`, §5) exécute dans **une seule transaction
   Doctrine** (`$em->wrapInTransaction(...)`) : `persist(Document)` (current_version = null) → `flush()` →
   `persist(DocumentVersion v1)` → `flush()` → `Document::setCurrentVersion($v1)` → `flush()`.
2. **Aucune opération de lecture** (`GetCollection`/`Get`/`DocumentStore::currentVersion()`) n'est jamais
   exposée pendant cette fenêtre transactionnelle — un `Document` n'est donc **jamais observable** avec
   `currentVersion = null` depuis l'extérieur du service.
3. Défense en profondeur : un test unitaire (`UploadDocumentHandlerTest`) vérifie qu'un `Document` fraîchement
   créé a systématiquement `currentVersion !== null` immédiatement après l'appel, et une contrainte
   applicative (`Assert\NotNull` sur le getter côté DTO de sortie, jamais sur la colonne Doctrine) empêche
   toute sérialisation d'un `Document` sans version courante.

Ce point s'écarte de la lecture strictement littérale du tableau spec §5 (« requis » au sens schéma), mais
préserve intégralement le comportement observable (CA-8, RG-DMS-19) — à signaler comme adaptation technique,
pas un changement de comportement.

### 0.2 Chiffrement en flux — `ChiffreurFluxDocument` (RG-DMS-25/28)

**Ne réutilise pas** `ChiffreurApiKeyOcr`/`ChiffreurIban` tels quels : ces deux services chiffrent une courte
chaîne en une opération mémoire (`sodium_crypto_secretbox`). RG-DMS-28 impose un traitement **par blocs**,
jamais le fichier entier en mémoire. Nouveau service, algorithme `crypto_secretstream_xchacha20poly1305`
(AEAD, détecte troncature/altération via `TAG_FINAL`) :

```php
namespace App\Dms\Crypto;

final class ChiffreurFluxDocument
{
    private const TAILLE_BLOC_CLAIR = 1_048_576; // 1 MiB — mémoire bornée quel que soit le poids du fichier

    public function __construct(
        #[Autowire(env: 'DMS_ENCRYPTION_KEY')] string $cleBase64,
    ) {
        $cle = base64_decode($cleBase64, true);
        if ($cle === false || \strlen($cle) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
            // ÉCHEC FERMÉ STRICT (RG-DMS-25) : contrairement à ChiffreurIban, AUCUNE dérivation de
            // convenance en dev/test — le conteneur refuse de démarrer si DMS_ENCRYPTION_KEY est
            // absente ou mal formée. Voir §0.6 pour la valeur de convenance à committer en .env/.env.test.
            throw new \RuntimeException(
                'DMS_ENCRYPTION_KEY absente ou invalide (32 octets base64 requis) — démarrage refusé (RG-DMS-25).',
            );
        }
        $this->cle = $cle;
    }

    /** Lit $source (resource) par blocs, écrit le flux chiffré dans $destination (resource). Retourne le sha256 hex du CONTENU EN CLAIR. */
    public function chiffrer($source, $destination): string
    {
        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($this->cle);
        fwrite($destination, $header); // 24 octets

        $hash = hash_init('sha256');
        while (!feof($source)) {
            $bloc = fread($source, self::TAILLE_BLOC_CLAIR);
            if ($bloc === false) {
                throw new \RuntimeException('Lecture du flux source impossible.');
            }
            hash_update($hash, $bloc);
            $dernier = feof($source);
            $tag = $dernier
                ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
                : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;
            fwrite($destination, sodium_crypto_secretstream_xchacha20poly1305_push($state, $bloc, '', $tag));
        }

        return hash_final($hash);
    }

    /** Lit $source (resource, format ci-dessus) par blocs, écrit le CLAIR dans $destination (ex. php://output). */
    public function dechiffrer($source, $destination): void
    {
        $header = fread($source, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
        if ($header === false || \strlen($header) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES) {
            throw new \RuntimeException('En-tête de flux chiffré invalide.');
        }
        $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $this->cle);
        $tailleBlocChiffre = self::TAILLE_BLOC_CLAIR + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;

        while (!feof($source)) {
            $bloc = fread($source, $tailleBlocChiffre);
            if ($bloc === false || $bloc === '') {
                break;
            }
            $resultat = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $bloc);
            if ($resultat === false) {
                throw new \RuntimeException('Flux chiffré invalide ou altéré (échec d\'authentification AEAD).');
            }
            [$clair, $tag] = $resultat;
            fwrite($destination, $clair);
            if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                break;
            }
        }
    }
}
```

Mémoire consommée : bornée à ~1 Mio + overhead PHP-FPM, **indépendante** du poids du fichier — respecte le
plafond conteneur 512 Mo (`docker/php/conf.d/zz-memory.ini`) quel que soit le volume uploadé/téléchargé.

`fileHash` (sha256, RG-DMS-17) est calculé sur le **contenu en clair**, en une seule passe pendant le
chiffrement — pas de second passage sur le fichier (coût CPU/E-S doublé sinon).

### 0.3 Plafond de taille d'upload (proposé, une fois le flux conçu — cf. mandat)

**Proposition : 100 Mo par fichier (104 857 600 octets), configurable via `DMS_MAX_UPLOAD_BYTES`
(paramètre, valeur par défaut committée — pas un secret, RG-DMS-25 ne s'applique qu'à la clé).** Couvre
largement les cas d'usage listés (PDF de contrat, photo/scan de justificatif, PV d'intervention) ; exclut
volontairement la vidéo (hors périmètre v1, spec §10). Trois réglages d'infrastructure à aligner par
l'intégrateur (§12) : `client_max_body_size` (nginx), `upload_max_filesize`/`post_max_size` (php.ini),
tous ≥ 100 Mo + marge pour l'overhead multipart. Le worker PHP-FPM reste occupé le temps du transfert
(streaming, pas d'async v1 — même compromis assumé que pour le téléchargement public, spec §8.1) : à
surveiller si le volume d'usage augmente, pas une limite bloquante aujourd'hui.

### 0.4 Jeton de lien public — pas de HMAC, DB-anchored uniquement (déviation assumée vs. la piste non normative RG-DMS-08)

La spec propose *à titre non normatif* de réutiliser `GenerateurCodeSupport` (HMAC + clé env). **Décision :
jeton aléatoire opaque simple, sans HMAC.** Justification : la seule autorité de vérité est l'enregistrement
persisté `DocumentPublicLink` (RG-DMS-08 l'exige explicitement pour porter la révocation) — un attaquant
sans le jeton ne peut de toute façon pas deviner un hash SHA-256 d'une valeur à 256 bits d'entropie, HMAC ou
non. Ajouter une signature HMAC ajouterait une clé d'environnement supplémentaire sans durcir le modèle de
menace réel (contrairement à `GenerateurCodeSupport`, qui doit rester vérifiable **hors ligne**, sans accès
BDD — pas le cas ici : le contrôleur public interroge toujours la base pour l'expiration/révocation).
`GenerateurJetonLienPublic` : `genererToken(): string` (`random_bytes(32)`, encodage base64url sans
padding, ~43 caractères) ; `hacher(string $token): string` (`hash('sha256', $token)`, hex, stocké dans
`tokenHash`). Aucune nouvelle variable d'environnement requise.

### 0.5 « Rôle dédié » `dms.manage_public_link` — traduction dans le modèle de sécurité existant (limite à signaler)

Le socle (`App\Securite`) n'a **qu'un seul mécanisme** de RBAC : permission = couple `module.action`,
regroupée en `Role` (agrégat de permissions librement modifiable par quiconque a `securite.gerer`). Il
n'existe pas de second palier « rôle système » distinct des permissions. La traduction techniquement fidèle
de l'arbitrage D18 pt.1 (« rôle dédié, pas une permission générique ») dans ce modèle est donc une mesure de
**gouvernance + fixtures**, pas un nouveau mécanisme structurel :
- `dms.manage_public_link` reste une permission `module.action` comme les autres (le voter ne distingue pas
  les permissions « sensibles »).
- Une fixture crée un `Role` dédié **« GED — Gestion des liens publics »** (`estModele: true`) portant
  **exactement** `dms.read` (nécessaire pour retrouver/consulter le document à lier) + `dms.manage_public_link`
  — **rien d'autre**.
- **Aucun rôle générique d'administration** (« Super Administrateur », « Administrateur établissement », etc.)
  ne doit inclure `dms.manage_public_link` dans ses fixtures/rôles-modèles.
- Test de garde `DedicatedRolePublicLinkFixtureTest` : sur les fixtures livrées, seul le rôle dédié porte
  `dms.manage_public_link`. **Limite explicite à consigner (§14)** : ce test protège les fixtures livrées,
  pas un administrateur qui composerait plus tard un rôle personnalisé incluant cette permission — le socle
  actuel ne peut pas l'en empêcher structurellement. Si ce risque doit être fermé, c'est une évolution du
  socle `App\Securite` (hors périmètre DMS-1), pas quelque chose que ce lot peut garantir seul.

### 0.6 `DMS_ENCRYPTION_KEY` — provisionnement dev/test (cohérent avec RG-DMS-25, différent de `ChiffreurIban`)

`app/.env` / `app/.env.test` reçoivent une valeur de convenance générée (32 octets base64, ex.
`DMS_ENCRYPTION_KEY=<base64>` — même façon que `MFA_ENCRYPTION_KEY`/`OCR_API_KEY_ENCRYPTION_KEY`), **mais**
contrairement à `ChiffreurIban` (qui dérive silencieusement une clé si la valeur n'est pas conforme),
`ChiffreurFluxDocument` **lève une exception au constructeur** si la valeur est absente/mal formée — aucune
dérivation de secours. C'est un écart volontaire par rapport au patron `ChiffreurIban`, dicté explicitement
par RG-DMS-25 (« échec fermé », repris tel quel de l'arbitrage D18 pt.3).

---

## 1. Entités & schéma

| Entité (`App\Dms\Entity\*`) | Champ | Type Doctrine | Null | Index/Contrainte | Relation |
|---|---|---|---|---|---|
| **`Document`** | id | `uuid` | non | PK | — |
| | establishment | `uuid` (FK) | non, **immuable** (RG-DMS-04) | index composite | `App\Organisation\Entity\Etablissement` |
| | category | `string(30)` enum `DocumentCategory` | non | index composite | — |
| | title | `string(255)` | non | — | — |
| | tags | `json` (`array<string>`) | oui | — | filtrage explorateur, hors périmètre SQL avancé v1 |
| | sourceModule | `string(60)` | oui | — | traçabilité d'origine, jamais une FK dure |
| | currentVersion | `uuid` (FK) | **nullable au schéma** (§0.1), jamais observable null en sortie | — | `DocumentVersion` |
| | status | `string(10)` enum `DocumentStatus` (`active`\|`deleted`) | non, défaut `active` | index composite | — |
| | retentionPolicy | `uuid` (FK) | oui | — | `RetentionPolicy` |
| | retainUntil | `date_immutable` | oui, calculé une fois (RG-DMS-11) | index | — |
| | createdBy | `uuid` (FK) | oui (= système) | — | `App\Securite\Entity\Utilisateur` |
| | createdAt | `datetime_immutable` | non | — | — |
| | updatedAt | `datetime_immutable` | non | — | — |
| | deletedAt | `datetime_immutable` | oui | — | RG-DMS-14 |
| **`DocumentVersion`** *(append-only)* | id | `uuid` | non | PK | — |
| | document | `uuid` (FK) | non | index, **UNIQUE(document_id, version_number)** | `Document` |
| | versionNumber | `integer` | non, ≥ 1 | (cf. UNIQUE ci-dessus) | — |
| | previousVersion | `uuid` (FK, self) | oui | — | `DocumentVersion` |
| | storageKey | `string(190)` | non, opaque | **UNIQUE** | pointeur `Storage` (RG-DMS-24) |
| | fileHash | `char(64)` | non, sha256 hex, **immuable** | index | — |
| | sizeBytes | `integer` | non, > 0 | — | ⚠ borne SQL INT (~2,1 Go), cf. §14 |
| | mimeType | `string(127)` | non | — | — |
| | originalFilename | `string(255)` | non | — | — |
| | uploadedBy | `uuid` (FK) | oui (= système) | — | `Utilisateur` |
| | uploadedAt | `datetime_immutable` | non, **immuable** | index | — |
| **`DocumentPublicLink`** | id | `uuid` | non | PK | — |
| | document | `uuid` (FK) | non | index | `Document` |
| | version | `uuid` (FK) | non, épinglée à l'émission (RG-DMS-05/20) | — | `DocumentVersion` |
| | tokenHash | `char(64)` | non, sha256 hex, **jamais le clair stocké** | **UNIQUE** (lookup public O(1)) | — |
| | expiresAt | `datetime_immutable` | non, requis (RG-DMS-06, 7j déf./30j plafond) | index | — |
| | revokedAt | `datetime_immutable` | oui | — | RG-DMS-07 |
| | createdBy | `uuid` (FK) | non | — | `Utilisateur` |
| | createdAt | `datetime_immutable` | non | — | — |
| | accessCount | `integer` | non, défaut 0 | — | RG-DMS-10 |
| | lastAccessedAt | `datetime_immutable` | oui | — | RG-DMS-10 |
| **`RetentionPolicy`** *(catalogue fixe v1)* | id | `uuid` | non | PK | — |
| | code | `string(60)` | non | **UNIQUE** (ex. `fr_accounting_10y`) | ⚠ écart vs. spec §5 (PK=`code`) : `id` UUID reste la PK pour rester conforme à l'invariant constitution « id = UUID », `code` devient une colonne unique — comportement observable identique |
| | durationMonths | `integer` | non | — | — |
| | legalBasisKey | `string(120)` | non | — | clé i18n, jamais un libellé en dur |
| | defaultForCategory | `string(30)` enum `DocumentCategory` | oui | **UNIQUE** (une politique par défaut max par catégorie) | — |

> id = UUID (`symfony/uid`), sauf remarque `RetentionPolicy` ci-dessus. Rattachement multi-entités :
> **Établissement** uniquement (`Document`/`DocumentVersion`/`DocumentPublicLink`) — `RetentionPolicy` est un
> catalogue **global**, non cloisonné (v1 fixe, pas de configuration par établissement, arbitrage D18 pt.7).

### 1bis. Enums (`App\Dms\Enum\*`, valeurs anglaises D5)
- `DocumentCategory` : `AccountingPiece = 'accounting_piece'` · `ExpenseReceipt = 'expense_receipt'` ·
  `Contract = 'contract'` · `InterventionReport = 'intervention_report'` · `HrDocument = 'hr_document'` ·
  `QuoteAttachment = 'quote_attachment'` · `MarketingAsset = 'marketing_asset'` · `Other = 'other'`.
- `DocumentStatus` : `Active = 'active'` · `Deleted = 'deleted'`.
- `RetentionStatus` *(calculé, jamais persisté — RG-DMS-12)* : `None = 'none'` · `Active = 'active'` ·
  `Expired = 'expired'`.

### 1ter. Tables (préfixe `dms_`, cf. avertissement migration §4)
`dms_document` · `dms_document_version` · `dms_document_public_link` · `dms_retention_policy`.

---

## 2. API (API Platform)

Ajout préalable requis (config partagée) : `src/Dms/Entity` dans `mapping.paths` de
`app/config/packages/api_platform.yaml` (nécessaire pour les `#[ApiFilter]`, même remarque que les autres
modules — **à faire avec précaution**, cf. mandat, ne toucher à aucune autre ligne).

| Ressource | Opérations | `security:` | Groupes sérialisation | Filtres |
|---|---|---|---|---|
| `Document` | `GetCollection`, `Get` | `is_granted('PERM', 'dms.read')` | `document:read` | `establishment` exact, `category` exact, `status` exact, `title` partial (SearchFilter), `retentionStatus` (filtre custom, cf. §2.1) |
| | `Post` (`/documents`, upload initial) | `is_granted('PERM', 'dms.write')` | input `DocumentUploadInput` (multipart), sortie `document:read` | processor `UploadDocumentProcessor` |
| | `Patch` (`/documents/{id}`, renommer/métadonnées) | `is_granted('PERM', 'dms.write')` | `document:write` (title, category, tags — **jamais** establishment/sourceModule/currentVersion) | processor `RenameDocumentProcessor` |
| | `Post` (`/documents/{id}/replace-version`) | `is_granted('PERM', 'dms.write')` | input `DocumentReplaceInput` (multipart, `file` seul), sortie `document:read` | processor `ReplaceDocumentVersionProcessor` |
| | `Post` (`/documents/{id}/retention`) | `is_granted('PERM', 'dms.manage_retention')` | input `SetRetentionInput`, sortie `document:read` | processor `SetRetentionProcessor` |
| | `Delete` (`/documents/{id}`, suppression logique) | `is_granted('PERM', 'dms.delete')` | — (204 ou 409) | processor `DeleteDocumentProcessor` |
| `DocumentVersion` | `GetCollection`, `Get` | `is_granted('PERM', 'dms.read')` | `document_version:read` | `document` exact (historique, modale 3) |
| *(aucun `Post`/`Patch`/`Delete` exposé — append-only, RG-DMS-17, même patron que `ExtractionAttempt`)* | | | | |
| `DocumentPublicLink` | `GetCollection`, `Get` | `is_granted('PERM', 'dms.manage_public_link')` | `document_public_link:read` (jamais `tokenHash`) | `document` exact |
| | `Post` (`/documents/{id}/public-links`, émission) | `is_granted('PERM', 'dms.manage_public_link')` | input `IssuePublicLinkInput`, sortie **DTO** `PublicLinkIssued` (jamais l'entité — porte le jeton en clair **une seule fois**) | processor `IssuePublicLinkProcessor` |
| | `Post` (`/public-links/{id}/revoke`) | `is_granted('PERM', 'dms.manage_public_link')` | sortie `document_public_link:read` | processor `RevokePublicLinkProcessor` |
| `RetentionPolicy` | `GetCollection`, `Get` | `is_granted('PERM', 'dms.read')` | `retention_policy:read` | — (catalogue fixe v1, pas de CRUD exposé) |

### 2.1 Filtre custom `RetentionStatusFilter`

`retentionStatus` n'est **pas** une colonne (RG-DMS-12 : calculé, jamais figé) — implémente
`ApiPlatform\Doctrine\Orm\Filter\FilterInterface`, traduit `?retentionStatus=active|none|expired` en
prédicat SQL sur `retentionPolicy`/`retainUntil` :
- `none` → `retentionPolicy IS NULL`
- `active` → `retainUntil IS NOT NULL AND retainUntil > CURRENT_DATE()`
- `expired` → `retainUntil IS NOT NULL AND retainUntil <= CURRENT_DATE()`

### 2.2 DTO d'entrée/sortie (`App\Dms\Dto\*`, non persistés)

| DTO | Champs | Notes |
|---|---|---|
| `DocumentUploadInput` | `category: DocumentCategory`, `title: string`, `tags: ?array`, `sourceModule: ?string`, `file: UploadedFile` | `#[ApiProperty(schema: ['type' => 'string', 'format' => 'binary'])]` sur `file` (doc OpenAPI correcte) ; opération `inputFormats: ['multipart' => ['multipart/form-data']]` |
| `DocumentReplaceInput` | `file: UploadedFile` | idem multipart |
| `SetRetentionInput` | `retentionPolicyCode: ?string`, `retainUntilOverride: ?string` (date ISO, cas exceptionnel « lever », RG-DMS-16) | `null`+`null` = lever la politique |
| `IssuePublicLinkInput` | `versionId: ?string` (IRI/UUID, défaut = currentVersion), `expiresInDays: int` (1..30, défaut 7, RG-DMS-06) | `Assert\Range(min: 1, max: 30)` |
| `PublicLinkIssued` *(sortie uniquement)* | `id`, `url` (jeton en clair inclus, **une seule fois**), `expiresAt`, `versionId` | jamais réutilisé en lecture ultérieure — l'entité `DocumentPublicLink` ne porte que `tokenHash` |

### 2.3 Contrôleurs binaires — hors CRUD API Platform (item #4 du mandat)

Le contenu binaire n'est **jamais** servi par une opération API Platform standard (négociation de contenu
JSON par défaut, mauvais candidat pour un `StreamedResponse`) — même choix que
`App\Securite\Controller\ExportAuditController` (contrôleur Symfony `#[AsController]` classique).

**`DocumentDownloadController`** — `GET /dms/documents/{id}/download` (+ `?version={uuid}` optionnel,
défaut = `currentVersion`) :
1. `is_granted('PERM', 'dms.read')` (403 sinon).
2. Résolution manuelle (`EntityManager::find`, pas de query extension) + **revérification explicite**
   (`PerimetreDmsVerificateur::verifier($document->getEstablishment())`, RG-DMS-02) — **404** uniforme
   (jamais 403) que le document soit hors périmètre ou inexistant (CA-2).
3. Si `?version` fourni : vérifie `version->getDocument() === $document` sinon 404 (pas de traversée
   inter-documents via un id de version deviné).
4. `Storage::get($version->getStorageKey())` → `ChiffreurFluxDocument::dechiffrer()` → `StreamedResponse`
   avec `Content-Type: $version->getMimeType()`, `Content-Disposition: attachment; filename="..."`
   (nom échappé, `originalFilename` jamais interprété comme chemin).

**`DocumentPublicDownloadController`** — `GET /dms/public/{token}`, **hors `/api`**, **aucune
authentification, aucune session/cookie applicatif** (RG-DMS-09) :
1. `hash('sha256', $token)` → `findOneBy(['tokenHash' => $hash])` — 404 si absent (jamais de distinction
   observable avec un jeton syntaxiquement valide mais révoqué/expiré — même statut 404/410 constant, à
   trancher entre 404 et 410 selon préférence UX, **404 recommandé par cohérence avec RG-DMS-03**).
2. Vérifie dans l'ordre : `revokedAt === null`, `expiresAt > now()`, `document->getStatus() === Active`
   (CA-14 — un document supprimé logiquement invalide l'usage de tous ses liens, révoqués ou non).
3. Incrémente `accessCount`, met à jour `lastAccessedAt`, `flush()` — **aucun événement de domaine émis**
   (RG-DMS-10, note §6 spec).
4. Sert le contenu de `$link->getVersion()` (**jamais** `document->getCurrentVersion()` — RG-DMS-20, CA-13)
   via le même pipeline `Storage::get()` → `ChiffreurFluxDocument::dechiffrer()` → `StreamedResponse`.

---

## 3. Sécurité & droits

### 3.1 Permissions (`module = dms`)
| Permission | Qui | Portée |
|---|---|---|
| `dms.read` | Utilisateur back-office dans son périmètre | Lister/consulter/télécharger (auth.) |
| `dms.write` | Utilisateur habilité | Téléverser, renommer, remplacer une version |
| `dms.delete` | Utilisateur habilité | Suppression logique (soumise à RG-DMS-13) |
| `dms.manage_retention` | Rôle conformité/compta restreint | Attacher/prolonger/lever une politique |
| `dms.manage_public_link` | **Rôle dédié uniquement** (§0.5) | Émettre/révoquer un lien public |

Consommateur applicatif (Finance, etc.) via `DocumentStore` (§5) : **aucune permission `dms.*` vérifiée** —
gardé par la permission du module appelant (ex. `finance.expense_report_submit`), conforme à l'acteur
« Consommateur applicatif » de la spec §3.

### 3.2 Cloisonnement (D3/D8)

- **`PerimetreDmsExtension`** (`App\Dms\Doctrine`, `QueryCollectionExtensionInterface` +
  `QueryItemExtensionInterface`) — copie stricte du patron `PerimetreOcrExtension`/`PerimetreFinanceExtension`
  (jointure `Affectation` sur `establishment` + utilisateur courant, `Security::getUser()`) :
  ```
  CHAINES = [
      Document::class => [],
      DocumentVersion::class => ['document'],
      DocumentPublicLink::class => ['document'],
  ]
  ```
  `RetentionPolicy` **volontairement absent** (catalogue global, non cloisonné). S'applique à
  `GetCollection`/`Get` uniquement — échec fermé (404, jamais une liste vide indiscernable, CA-1).

- **`PerimetreDmsVerificateur`** (`App\Dms\Security`) — copie du patron
  `App\Stock\Security\PerimetreEtablissementVerificateur` (pas d'appel direct : `App\Dms` est transverse,
  `dependencies(): []`, ne doit rien importer de `App\Stock`). Méthode `verifier(?Etablissement, string
  $message = '...'): Etablissement` — lève `NotFoundHttpException` (404, **pas** `AccessDeniedHttpException`
  403 — RG-DMS-03 exige l'indiscernabilité « existe mais interdit » vs « n'existe pas »). **Appelé
  explicitement au début de chaque processor/contrôleur non-`GetCollection`/`Get`** : `ReplaceDocumentVersionProcessor`,
  `RenameDocumentProcessor`, `SetRetentionProcessor`, `DeleteDocumentProcessor`, `IssuePublicLinkProcessor`,
  `RevokePublicLinkProcessor`, `DocumentDownloadController` — **y compris quand l'opération API Platform a
  déjà `read: true`** (défense en profondeur explicite, cf. mandat D8, pas une confiance implicite dans le
  provider par défaut).

### 3.3 Établissement — dérivation serveur, jamais du client

`UploadDocumentProcessor` (voie HTTP) dérive `establishment` de `ContexteEtablissement::etablissementActif()`
— **jamais** d'un champ du DTO d'entrée (`DocumentUploadInput` ne porte pas de champ `establishment`).
Échec fermé : aucun établissement actif résolvable → `UnprocessableEntityHttpException` (pas de document
orphelin). Voie `DocumentStore` (PHP, §5) : l'établissement est un **paramètre explicite obligatoire** du
DTO `StoreDocumentRequest`, fourni par le module appelant qui connaît déjà son propre périmètre (ex.
`ExpenseReport.establishment`) — différence documentée en §5, cohérente avec D6 (le tenant de l'événement
dérive toujours de `Document.establishment`, jamais du contexte HTTP).

---

## 4. Migrations

**Une seule migration additive**, aucune dépendance destructive. ⚠ **AVERTISSEMENT À RÉPÉTER AU MOMENT DE
LA GÉNÉRATION** (`doctrine:migrations:diff`) : l'outil va très probablement **reproposer la suppression
d'index d'autres modules** déjà constatée sur ce dépôt (notamment l'index **FULLTEXT du module `App\Support`**)
parce que Doctrine compare le schéma complet, pas seulement `App\Dms`. **La migration générée doit être
relue ligne à ligne et ne garder QUE les instructions `CREATE TABLE dms_*` / `ALTER TABLE dms_* ADD
CONSTRAINT FK_DMS_*`** — toute ligne touchant une table hors `dms_*` (`DROP INDEX`, `ALTER TABLE` sur une
autre table) est supprimée du fichier avant merge.

Contenu attendu (DDL à la main, cf. patron `Version20260818090400.php`) :
- `CREATE TABLE dms_document` (id BINARY(16), establishment_id BINARY(16) NOT NULL, category VARCHAR(30),
  title VARCHAR(255), tags JSON NULL, source_module VARCHAR(60) NULL, current_version_id BINARY(16) NULL
  — cf. §0.1, status VARCHAR(10) DEFAULT 'active', retention_policy_id BINARY(16) NULL, retain_until DATE
  NULL, created_by_id BINARY(16) NULL, created_at DATETIME, updated_at DATETIME, deleted_at DATETIME NULL,
  PRIMARY KEY(id)) + index `IDX_DMS_DOCUMENT_ETAB_CATEGORY (establishment_id, category)`, `IDX_DMS_DOCUMENT_ETAB_STATUS
  (establishment_id, status)`.
- `CREATE TABLE dms_document_version` (id, document_id NOT NULL, version_number INT, previous_version_id
  NULL, storage_key VARCHAR(190), file_hash CHAR(64), size_bytes INT, mime_type VARCHAR(127),
  original_filename VARCHAR(255), uploaded_by_id NULL, uploaded_at DATETIME, PRIMARY KEY(id)) +
  `UNIQUE INDEX uniq_dms_version_document_number (document_id, version_number)`,
  `UNIQUE INDEX uniq_dms_version_storage_key (storage_key)`.
- `CREATE TABLE dms_document_public_link` (id, document_id NOT NULL, version_id NOT NULL, token_hash
  CHAR(64), expires_at DATETIME, revoked_at DATETIME NULL, created_by_id NOT NULL, created_at DATETIME,
  access_count INT DEFAULT 0, last_accessed_at DATETIME NULL, PRIMARY KEY(id)) +
  `UNIQUE INDEX uniq_dms_public_link_token_hash (token_hash)`, `IDX_DMS_PUBLIC_LINK_DOCUMENT (document_id)`.
- `CREATE TABLE dms_retention_policy` (id, code VARCHAR(60), duration_months INT, legal_basis_key
  VARCHAR(120), default_for_category VARCHAR(30) NULL, PRIMARY KEY(id)) + `UNIQUE INDEX
  uniq_dms_retention_policy_code (code)`, `UNIQUE INDEX uniq_dms_retention_policy_default_category
  (default_for_category)`.
- FK vers `org_etablissement`, `sec_utilisateur`, et FK internes (`dms_document.current_version_id` →
  `dms_document_version.id`, `dms_document_version.document_id` → `dms_document.id`,
  `dms_document_version.previous_version_id` → self, `dms_document.retention_policy_id` →
  `dms_retention_policy.id`, `dms_document_public_link.document_id`/`version_id` → respectivement).
- **Seed du catalogue `RetentionPolicy` v1** dans la même migration (ex. `fr_accounting_10y` → 120 mois,
  `defaultForCategory = accounting_piece` ; `fr_hr_5y` → 60 mois, `defaultForCategory = hr_document` ;
  autres catégories sans politique par défaut, `retentionStatus = none`) — ⚠ valeurs légales exactes à
  confirmer avec un expert compta/RH avant merge (§14).

---

## 5. Services & ports

| Service | Rôle |
|---|---|
| `Storage` (interface, racine `App\Dms\Storage`) | `put(string $storageKey, $stream): void` · `get(string $storageKey)` (resource) · `delete(string $storageKey): void` · `exists(string $storageKey): bool`. Domaine `App\Dms` ne dépend **jamais** d'un SDK concret (RG-DMS-24). |
| `LocalFilesystemStorage implements Storage` | Adaptateur v1 (arbitrage D18 pt.2) — racine `%kernel.project_dir%/var/dms/storage` (**hors `public/`**, jamais servi par nginx). Clé shardée (`aa/bb/<32 hex>.bin`) pour éviter un répertoire à plat. |
| `ChiffreurFluxDocument` (§0.2) | Chiffrement/déchiffrement en flux, blocs 1 MiB, `DMS_ENCRYPTION_KEY` sans défaut. |
| `GenerateurJetonLienPublic` (§0.4) | Jeton opaque 256 bits + hachage sha256, pas de HMAC. |
| `CalculateurStatutRetention` | `statutPour(?\DateTimeImmutable $retainUntil): RetentionStatus` — pure, sans effet de bord, utilisé par l'API (champ calculé), `DeleteDocumentProcessor`, `PurgeDocumentsCommand`. |
| `UploadDocumentHandler` | Cœur partagé HTTP (`UploadDocumentProcessor`) **et** `DocumentStore::store()` — transaction §0.1, calcul retention initiale (RG-DMS-11), émission `document.stored`. |
| `ReplaceVersionHandler` | Cœur partagé HTTP (`ReplaceDocumentVersionProcessor`) et `DocumentStore` — verrouillage pessimiste (§5.1), émission `document.version_added`. |
| **`DocumentStore` (interface, racine `App\Dms\DocumentStore`)** | Port PHP synchrone pour les modules consommateurs — même famille que `App\Ocr\DocumentExtractor` : `store(StoreDocumentRequest $r): StoredDocument` · `currentVersion(Uuid $documentId): ?StoredDocumentVersion` (métadonnées seules) · `readContent(Uuid $documentId, ?Uuid $versionId = null)` (resource déchiffrée en flux). `StoreDocumentRequest` porte **explicitement** `establishment: Etablissement` (le module appelant connaît déjà son périmètre — pas de `ContexteEtablissement` ici, cf. §3.3). `readContent()` **ne revérifie aucune permission `dms.*`** — le module appelant est responsable de son propre contrôle d'accès (acteur « Consommateur applicatif », spec §3), exactement comme `DocumentExtractor` ne vérifie pas `ocr.*`. |
| `DefaultDocumentStore implements DocumentStore` | Implémentation par défaut, aliasée en DI (`App\Dms\DocumentStore → DefaultDocumentStore`). |

### 5.1 Concurrence — deux remplacements simultanés (cas limite spec §10)

`ReplaceVersionHandler::remplacer()` s'exécute dans une transaction avec **verrou pessimiste** sur la ligne
`Document` :
```
$em->wrapInTransaction(function () use ($em, $documentId, ...) {
    $document = $em->find(Document::class, $documentId, LockMode::PESSIMISTIC_WRITE);
    $prochainNumero = $document->getCurrentVersion()->getVersionNumber() + 1;
    $nouvelleVersion = new DocumentVersion($document, $prochainNumero, $document->getCurrentVersion(), ...);
    $em->persist($nouvelleVersion);
    $document->setCurrentVersion($nouvelleVersion);
    $em->flush();
});
```
La deuxième requête concurrente **attend** le verrou (bloquée par MariaDB InnoDB, pas d'erreur), puis relit
`currentVersion` déjà mis à jour par la première — elle produit donc `version_number = N+2`, **jamais** de
collision ni de perte d'écriture (cas limite spec §10 : « aucune des deux écritures n'est perdue »).
La `UNIQUE(document, versionNumber)` reste un **filet de sécurité** (défense en profondeur) si le verrou
était contourné (ex. accès direct ORM hors handler) : `UniqueConstraintViolationException` remonte alors en
409, à ne pas masquer silencieusement.

### 5.2 Émission des lien publics — collision de jeton (improbable, gérée)

`IssuePublicLinkProcessor` catch `UniqueConstraintViolationException` sur `tokenHash` (probabilité
astronomiquement faible avec 256 bits d'entropie, mais gérée proprement) → régénère un nouveau jeton, un
seul retry, sinon `RuntimeException` (alerte, jamais silencieux).

---

## 6. Listeners — inaltérabilité (`App\Dms\Doctrine\DocumentIntegriteListener`)

Copie du patron `App\Vente\Nf525\InalterabiliteListener` (`#[AsDoctrineListener(Events::preUpdate)]` +
`preRemove`), **deux responsabilités distinctes dans le même fichier** (même esprit que le listener NF525
qui couvre plusieurs entités) :

1. **`DocumentVersion` append-only (RG-DMS-17, CA-7)** — toute `preUpdate`/`preRemove` sur une
   `DocumentVersion` **déjà persistée** (present en base, pas une création en cours) lève
   `DocumentVersionInalterableException` (message : « Modification/suppression interdite : la version est
   scellée (GED, append-only) »). Couvre **API et accès ORM direct** (fixtures, commande) — pas seulement
   les processors HTTP.
2. **`Document.establishment` immuable (RG-DMS-04)** — sur `preUpdate` d'un `Document`, si
   `$args->getEntityChangeSet()` contient la clé `establishment`, lève une exception dédiée — défense en
   profondeur en complément du groupe de sérialisation `document:write` qui n'expose déjà pas ce champ en
   écriture API (§3.3 couvre la voie normale, ce listener couvre l'ORM direct).

---

## 7. Commande de purge différée

`App\Dms\Command\PurgerDocumentsCommand` (`dms:purger-documents-expires`) — patron
`App\Crm\Command\AppliquerConservationCommand` (planifiée, ex. cron quotidien) :

1. Sélectionne les `Document` où `status = deleted` **ET** `deletedAt <= now() - 30 jours` (grâce, arbitrage
   D18 pt.6) **ET** `CalculateurStatutRetention::statutPour(retainUntil) === Expired` (ou `None` — un
   document jamais soumis à rétention et supprimé depuis plus de 30 jours est purgeable aussi ; seul le
   statut `Active` bloque, cohérent RG-DMS-12/15).
2. Pour chaque version du document : `Storage::delete($version->getStorageKey())` (retire le contenu binaire
   — les métadonnées `DocumentVersion` restent en base, RG-DMS-14, seule la ligne `storageKey`/contenu physique
   disparaît, à documenter comme état terminal « purgé » — ⚠ le modèle actuel (§1) ne porte pas de flag
   `purgedAt` sur `DocumentVersion` : proposition d'ajout mineur à la migration si l'implémentation le juge
   nécessaire pour distinguer « version historique intacte » de « version purgée, métadonnées seules » dans
   l'explorateur — point à trancher à l'implémentation, non bloquant pour CA-11).
3. Publie `document.purged` (payload `category`, `retainUntilWas`).
4. **CA-11** : un document `expired` mais **jamais explicitement supprimé** (`status = active`) n'est
   **jamais** sélectionné par cette commande — l'expiration seule n'entraîne aucune purge automatique
   (garde explicite dans la requête : `status = deleted` est une condition **obligatoire**, pas optionnelle).

---

## 8. Manifeste `App\Dms\DmsModule`

```php
final class DmsModule implements ModuleManifest
{
    public function id(): string { return 'dms'; }
    public function version(): string { return '0.1.0'; }
    public function capability(): ?string { return null; } // transverse (§1, D18 pt.1)
    public function dependencies(): array { return []; }
    public function permissions(): array
    {
        return ['dms.read', 'dms.write', 'dms.delete', 'dms.manage_retention', 'dms.manage_public_link'];
    }
    public function eventsEmitted(): array
    {
        return [
            'document.stored', 'document.version_added', 'document.retention_set',
            'document.deletion_refused', 'document.deleted', 'document.purged',
            'document.public_link_issued', 'document.public_link_revoked',
        ]; // exactement les 8 lignes déjà catalogées (CONTRACT/catalogue-evenements.md:66-73)
    }
    public function eventsConsumed(): array { return []; } // intervention.validated : futur module non construit (spec §11)
    public function features(): array { return []; }
    public function routes(): array { return ['/dms/documents']; } // écran unique, RG-DMS-26
    public function settingsSchema(): array { return []; }
}
```

---

## 9. Événements de domaine — points d'implémentation

Tous construits via `new DomainEvent($nom, new EventTenant($document->getEstablishment()->getId()),
new EventSubject('Document', (string) $document->getId()), $payload, $actor)` — **jamais**
`ContexteEtablissement` comme source du tenant (RG-DMS-22, CA-9). `$actor` = `null` pour
`PurgerDocumentsCommand` (système), sinon `new EventActor($utilisateurCourant->getId())`.

| Événement | Émetteur | Payload (RG-DMS-23 — jamais de contenu/jeton) |
|---|---|---|
| `document.stored` | `UploadDocumentHandler` | `category`, `versionId`, `mimeType`, `sizeBytes`, `sourceModule?` |
| `document.version_added` | `ReplaceVersionHandler` | `versionId`, `versionNumber`, `previousVersionId`, `mimeType`, `sizeBytes` |
| `document.retention_set` | `SetRetentionProcessor` | `retentionPolicyCode` (nullable si levée), `retainUntil` (nullable) |
| `document.deletion_refused` | `DeleteDocumentProcessor` (avant le 409) | `retainUntil`, `reasonCode` (ex. `retention_active`) |
| `document.deleted` | `DeleteDocumentProcessor` | `category` |
| `document.purged` | `PurgerDocumentsCommand` | `category`, `retainUntilWas` |
| `document.public_link_issued` | `IssuePublicLinkProcessor` | `versionId`, `publicLinkId`, `expiresAt` — **jamais** le jeton (CA-10, testé négativement) |
| `document.public_link_revoked` | `RevokePublicLinkProcessor` | `publicLinkId` |

`DomainEvent::assertPayloadIsCarryable()` refuse déjà toute clé `token`/`secret` — aucun contournement par
un nom de clé différent portant la même donnée (ex. pas de `linkSecret`/`accessCode`).

---

## 10. Plan de tests PHPUnit

| Test | Type | Couvre |
|---|---|---|
| `ChiffreurFluxDocumentTest` | Unit | Round-trip chiffrement/déchiffrement sur un flux de plusieurs blocs (> 1 MiB, force ≥ 2 itérations de boucle) ; altération d'un octet du flux chiffré → échec d'authentification détecté (jamais un déchiffrement silencieux erroné) ; démarrage refusé si `DMS_ENCRYPTION_KEY` absente/mal formée (CA-12, exception au constructeur, pas au premier appel) |
| `GenerateurJetonLienPublicTest` | Unit | Jeton non énumérable (format), `hacher()` déterministe, deux jetons générés jamais identiques (entropie) |
| `CalculateurStatutRetentionTest` | Unit | `null` → `none` ; futur → `active` ; passé → `expired` (CA-6, RG-DMS-12) |
| `UploadDocumentHandlerTest` | Unit/Intégration | `Document.currentVersion` jamais `null` après retour (§0.1) ; `fileHash` = sha256 correct du contenu ; retention auto-assignée si `defaultForCategory` correspond, sinon `none` |
| `DocumentIntegriteListenerTest` | Unit/Intégration | CA-7 : `preUpdate`/`preRemove` sur `DocumentVersion` existante → exception, aussi bien via `$em->flush()` direct que via l'API ; `preUpdate` sur `Document.establishment` → exception (RG-DMS-04) |
| `ReplaceVersionConcurrencyTest` | Intégration | §5.1 : deux appels concurrents (threads/process simulés ou verrou testé unitairement) produisent deux versions distinctes séquentielles, aucune perte |
| `DocumentApiTest` (`CloisonnementDmsTest`) | Fonctionnel API | CA-1 : établissement A ne voit jamais un document de B, même avec l'UUID exact ; CA-2 : `download` sur un id hors périmètre → 404 identique à un id inexistant |
| `DocumentUploadApiTest` | Fonctionnel API | Upload multipart → 201 + `document.stored` publié avec tenant dérivé de `Document.establishment` (CA-9), indépendamment de l'en-tête `X-Etablissement` envoyé |
| `ReplaceDocumentVersionApiTest` | Fonctionnel API | CA-8 : v2 créée, `currentVersion` pointe v2, v1 reste `GET`-able et téléchargeable inchangée |
| `DeleteDocumentApiTest` | Fonctionnel API | CA-5 : rétention active → 409, document intact, `document.deletion_refused` publié ; CA-6 : rétention expirée → succès, `status = deleted` |
| `SetRetentionApiTest` | Fonctionnel API | `dms.manage_retention` requis (403 sinon) ; `document.retention_set` publié avec `retentionPolicyCode`/`retainUntil` corrects |
| `PublicLinkIssueApiTest` | Fonctionnel API | `dms.manage_public_link` requis (403 sinon, y compris pour un utilisateur `dms.manage_retention` ou admin générique sans le rôle dédié) ; jeton en clair présent **une seule fois** dans la réponse `PublicLinkIssued`, jamais dans `GetCollection`/`Get` ultérieurs ; CA-10 : payload de `document.public_link_issued` sans jeton |
| `PublicLinkRevokeApiTest` | Fonctionnel API | CA-4 : révocation puis accès public → échec immédiat, avant l'échéance naturelle |
| `PublicDownloadControllerTest` | Fonctionnel HTTP (route publique) | CA-3 : `expiresAt` dépassé → échec même jeton syntaxiquement valide ; CA-13 : lien épinglé sur v1, document remplacé en v2 → sert toujours v1 inchangée ; CA-14 : document `status = deleted` avec lien actif non révoqué → échec ; incrémente `accessCount`/`lastAccessedAt`, **aucun** événement de domaine émis par accès (assert : aucun événement publié sur le bus pendant l'appel) |
| `PurgeDocumentsCommandTest` | Fonctionnel/Unit | CA-11 : document `expired` jamais supprimé explicitement → rien purgé ; document `deleted` depuis > 30j et `expired` → `Storage::delete` appelé, `document.purged` publié ; document `deleted` depuis < 30j (grâce) → non purgé |
| `DocumentVersionApiTest` | Fonctionnel API | `POST`/`PATCH`/`DELETE` non exposés (405/404) sur `DocumentVersion` — lecture seule stricte |
| `DedicatedRolePublicLinkFixtureTest` | Fonctionnel/Fixtures | §0.5 : seul le rôle dédié « GED — Gestion des liens publics » porte `dms.manage_public_link` parmi les rôles-modèles livrés |
| `RenameDocumentApiTest` | Fonctionnel API | Changer `category` ne recalcule **jamais** implicitement `retainUntil`/`retentionPolicy` (RG-DMS-11) |

---

## 11. UI (rappel — lot de suivi, non porté par ce plan backend)

Rappel spec §7 (RG-DMS-26, D13) : **un seul écran** (« l'explorateur de documents », `/dms/documents`) +
**7 modales** (Téléverser, Remplacer la version courante, Historique des versions, Renommer/métadonnées,
Générer un lien public, Révoquer un lien public, Supprimer avec refus explicite si rétention active). Ce
plan couvre exclusivement le **backend `App\Dms`** (entités, API, services) — le front consommant cette API
est un lot séparé, hors périmètre DMS-1.

---

## 12. Procédure d'exploitation (RG-DMS-27, condition de mise en production)

1. **Sauvegarde conjointe fichiers + base** — la routine de sauvegarde existante (dump MariaDB) doit
   **inclure systématiquement** le répertoire `var/dms/storage` dans la **même fenêtre de sauvegarde**
   (idéalement le même run, snapshot cohérent) — une sauvegarde base sans fichiers (ou l'inverse) viole
   RG-DMS-27 et rend une restauration incohérente (un `Document`/`DocumentVersion` pointant vers un
   `storageKey` absent).
2. **Vérification d'intégrité post-restauration (recommandé, non bloquant CA)** — commande
   `dms:verifier-integrite-stockage` : pour chaque `DocumentVersion`, vérifie `Storage::exists(storageKey)`
   ; rapporte toute incohérence avant remise en production. À exécuter après chaque restauration de
   sauvegarde (procédure manuelle documentée, pas un test automatisé du lot).
3. **Provisionnement `DMS_ENCRYPTION_KEY`** — générée une fois (`openssl rand -base64 32` ou équivalent
   PHP `base64_encode(random_bytes(32))`), stockée en secret/vault en production (jamais committée en clair
   hors `.env`/`.env.test` de convenance dev), **jamais régénérée** après le premier document stocké (une
   rotation de clé nécessiterait un rechiffrement complet du contenu existant — hors périmètre v1, à
   cadrer si le besoin apparaît).
4. **Réglages infra pour l'upload** (§0.3) — `client_max_body_size` (nginx), `upload_max_filesize`/
   `post_max_size` (php.ini) ≥ `DMS_MAX_UPLOAD_BYTES` + marge multipart (~10 %).
5. **Répertoire `var/dms/storage`** — hors `public/` (jamais servi statiquement par nginx), permissions
   restreintes à l'utilisateur du pool PHP-FPM, exclu de tout `git`/déploiement versionné (`.gitignore`).

---

## 13. Tâches (voir tasks-dms.md)

- **T1** — Contrats/DTO racine (`Storage`, `DocumentStore`, DTO associés), enums `DocumentCategory`/
  `DocumentStatus`/`RetentionStatus`, exceptions dédiées.
- **T2** — Entités `Document`/`DocumentVersion`/`DocumentPublicLink`/`RetentionPolicy` + migration
  (avertissement §4 relu ligne à ligne) + seed catalogue rétention.
- **T3** — `ChiffreurFluxDocument` (+ tests round-trip/altération/échec démarrage) + `LocalFilesystemStorage`
  + `GenerateurJetonLienPublic` + `CalculateurStatutRetention`.
- **T4** — `PerimetreDmsExtension` + `PerimetreDmsVerificateur` + `DocumentIntegriteListener` + ajout
  `src/Dms/Entity` à `api_platform.yaml` (config partagée, prudence).
- **T5** — `UploadDocumentHandler`/`UploadDocumentProcessor` (§0.1 transaction) + `ReplaceVersionHandler`/
  `ReplaceDocumentVersionProcessor` (§5.1 verrou) + tests concurrence.
- **T6** — `RenameDocumentProcessor`, `SetRetentionProcessor`, `DeleteDocumentProcessor` (409 + événement
  `deletion_refused`) + tests.
- **T7** — `IssuePublicLinkProcessor`/`RevokePublicLinkProcessor` + `DocumentDownloadController` +
  `DocumentPublicDownloadController` (route hors `/api`) + tests CA-2/3/4/13/14.
- **T8** — `PurgerDocumentsCommand` + planification (cron) + tests CA-11.
- **T9** — `DmsModule` (manifeste) + permissions + fixtures rôle dédié (§0.5) + `DedicatedRolePublicLinkFixtureTest`.
- **T10** — `DefaultDocumentStore` (port PHP, §5) + doc d'intégration pour `App\Finance` (consommateur
  pressenti, spec §11) — **aucun code Finance modifié dans ce lot**, juste le port exposé.
- **T11** — Revue de cohérence finale (constitution §8) : `GET /health` vert, aucun secret en clair
  observable, i18n des messages d'erreur (`dms.error.*`), procédure d'exploitation §12 documentée pour
  l'équipe infra.

---

## 14. Risques / points à valider

1. **§0.1 — `current_version_id` nullable au schéma** malgré « requis » dans la table de données de la
   spec : écart technique nécessaire (FK circulaire), comportement observable préservé — à confirmer que
   ce n'est pas bloquant pour le propriétaire de la spec.
2. **§0.4 — jeton de lien public sans HMAC** (déviation de la piste non normative RG-DMS-08) — jugé
   équivalent en sécurité réelle (DB-anchored), mais à valider si une exigence de vérifiabilité hors ligne
   du jeton apparaît plus tard (non requise aujourd'hui, le contrôleur public interroge toujours la base).
3. **§0.5 — « rôle dédié » = permission + fixture + test de garde, pas un second palier RBAC structurel.**
   Limite explicite : ne protège pas contre un administrateur qui composerait plus tard un rôle personnalisé
   incluant `dms.manage_public_link`. Fermer ce risque structurellement demanderait une évolution du socle
   `App\Securite` (hors DMS-1) — à signaler à l'intégrateur comme dette potentielle, pas un bug de ce lot.
4. **`RetentionPolicy` : PK `id` UUID + `code` unique**, au lieu de `code` en PK comme littéralement décrit
   spec §5 — pour rester conforme à l'invariant constitution « id = UUID partout » ; comportement API/métier
   identique (le `code` reste la clé métier visible).
5. **`DocumentVersion.sizeBytes` en `INT` SQL** (~2,1 Go max) — suffisant avec le plafond d'upload proposé
   (100 Mo, §0.3) ; si ce plafond est relevé significativement au-delà de 2 Go dans un lot futur, migrer la
   colonne en `BIGINT`.
6. **Valeurs légales exactes du catalogue `RetentionPolicy` v1** (durées, bases légales) — proposées par
   analogie (ex. 10 ans pièces comptables France), **à faire valider par un expert compta/RH** avant merge
   de la migration de seed (même réserve que les autres modules Finance/Compta sur ce dépôt).
7. **Plafond d'upload 100 Mo (`DMS_MAX_UPLOAD_BYTES`)** — proposition raisonnée mais non normative ; à
   confirmer avec le produit selon les cas d'usage réels (photos haute résolution de justificatifs,
   contrats scannés multi-pages).
8. **Antivirus/scan de contenu — hors v1, réserve RG-DMS-29 consignée telle quelle.** Ce plan n'ajoute
   aucun scan à l'upload. Risque à rouvrir explicitement le jour où l'émission de lien public deviendrait
   libre-service (non prévu par ce lot).
9. **Pas de rotation de clé `DMS_ENCRYPTION_KEY`** en v1 (§12 pt.3) — si une rotation devient nécessaire
   (fuite suspectée, conformité), un rechiffrement complet du contenu existant est hors périmètre de ce
   plan, à cadrer séparément.
10. **Purge et flag `purgedAt` sur `DocumentVersion`** (§7 pt.2) — le modèle de données actuel ne distingue
    pas explicitement en base « version historique intacte » de « version purgée, contenu binaire retiré » ;
    proposition d'ajout mineur (`purgedAt: ?datetime_immutable`) laissée à l'arbitrage de l'implémentation,
    non bloquante pour les CA listés.
