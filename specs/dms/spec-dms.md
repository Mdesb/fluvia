# Spec — GED / gestion documentaire interne (`App\Dms`)

- **Lot / module :** **service transverse partagé**, au sens du noyau commun
  (`COORDINATION/CONTRACT/noyau-commun.md`) — pas un module métier vendu/activable par établissement.
  Le docblock de `App\Ocr\OcrModule` cite déjà « OCR, GED, signature, i18n » comme exemples de services
  transverses dont `capability()` renvoie `null` : `App\Dms` suit exactement ce patron. Manifeste :
  `App\Dms\DmsModule implements ModuleManifest`.
- **Stories couvertes :** ⚠ **HORS BACKLOG** — comme `App\Ocr` (spec-ocr.md), c'est un service
  entièrement nouveau, demandé par l'intégrateur et non issu de `backlog.html`/`cahier-detaille.html`
  (vérifié : aucune occurrence de « GED », « gestion documentaire », « coffre-fort » dans le cahier ;
  seules deux US mentionnent un « justificatif » sans lien avec ce lot). Numérotation proposée :
  **US-DMS-01 à US-DMS-06** (à faire valider si le backlog doit les accueillir formellement).
- **Règles de gestion :** **RG-DMS-01 à RG-DMS-26** (nouvelles).
- **Statut :** brouillon — étape 1 du pipeline SDD ; un `sdd-architecte` produira ensuite le plan
  technique. Cette spec ne code rien et reste orientée comportement observable.

## 1. Objectif

Offrir à tous les modules qui produisent ou consomment des documents (Finance/Compta pour les pièces
comptables et notes de frais, un futur module intervention/signature électronique pour les PV signés,
un futur module social/marketing pour les visuels publiés en externe, etc.) un **coffre documentaire
unique, cloisonné par établissement, versionné et conforme aux obligations de conservation légale**,
plutôt que chaque module ne réinvente son propre stockage de fichiers avec ses propres failles.

Le sujet central de ce lot n'est pas « stocker un fichier » — c'est **qui a le droit de le lire, à quel
moment, et pendant combien de temps on n'a pas le droit de le détruire**. Le cloisonnement (§4.1), la
rétention réglementaire (§4.3) et le versionnement comme preuve (§4.4) sont traités comme le cœur du
lot, pas comme des annexes.

## 2. Périmètre

### Inclus
- **Stockage de documents** avec métadonnées (`Document`), **versionnement immuable**
  (`DocumentVersion`), et une abstraction de stockage enfichable (port `Storage`, §9.1).
- **Cloisonnement multi-établissement** strict (D3/D8) sur toutes les opérations, y compris les routes
  qui ne sont pas de simples lectures de collection (téléchargement, remplacement de version,
  révocation de lien).
- **Rétention réglementaire** : politique de conservation par catégorie de document, refus explicite de
  toute suppression sous obligation légale, suppression logique puis purge physique différée.
- **URL publiques signées, expirantes et révocables** — la seule brèche autorisée au cloisonnement,
  explicite, tracée, jamais le mode par défaut (§4.2).
- **Catalogue d'événements** `document.*` (§6), déclarés au `CONTRACT/catalogue-evenements.md` avant
  toute implémentation (D2/RG-PLAT-06).
- **Un unique écran** — l'explorateur de documents (§7) — et des modales pour chaque action.

### Exclu (pour l'instant)
- **La signature électronique** — `App\Dms` stocke le PV signé et ses versions (avant/après signature),
  mais ne signe rien : le processus de signature est un **consommateur** de la GED, pas ce lot.
- **La validation d'intervention terrain** (`intervention.validated`, déjà listé au catalogue avec
  « DMS » comme consommateur probable) — module non construit à ce jour ; ce lot **n'implémente pas**
  encore `eventsConsumed()` pour cet événement (§8, note). Il fournit seulement la capacité d'y stocker
  un document une fois ce module existant.
- **L'analyse d'appels d'offres** (`tender.analyzed`) — consommateur potentiel futur, pas ce lot.
- **Le module social / la publication effective sur Instagram** — ce lot **rend possible** l'URL
  publique signée dont ce module aura besoin (§10), il ne construit pas la publication elle-même.
- **L'antivirus / le scan de contenu à l'upload** — ⚠ HYPOTHÈSE : hors périmètre v1, à cadrer au plan
  technique si le risque est jugé significatif (cas limite §10).
- **La classification automatique par IA** (OCR/catégorisation) du contenu déposé — `App\Ocr` reste un
  service distinct ; un consommateur peut appeler les deux séparément (upload GED + extraction OCR sur
  le même fichier), mais `App\Dms` ne dépend pas d'`App\Ocr`.
- **Un configurateur de catégories/politiques de rétention par établissement** — v1 propose un **jeu de
  catégories et de politiques fixe, codé en anglais** (§5), pas un écran d'administration extensible
  par tenant. ⚠ HYPOTHÈSE, à confirmer par l'intégrateur si un besoin réel de personnalisation par
  établissement apparaît (ce serait alors un nouvel écran, justifié par la raison D13 n°2 : contenu
  d'administration qui ne tient pas dans une modale).

## 3. Acteurs & droits

| Acteur | Peut | Permission (module × action) |
|---|---|---|
| Utilisateur back-office (dans son périmètre) | Lister, consulter, télécharger un document de son établissement | `dms.read` |
| Utilisateur back-office habilité | Téléverser un document, le renommer, remplacer sa version courante | `dms.write` |
| Utilisateur back-office habilité | Supprimer (logiquement) un document — refusé si rétention active | `dms.delete` |
| Rôle conformité / compta (restreint) | Attacher, prolonger une politique de rétention sur un document | `dms.manage_retention` |
| Rôle habilité (marketing/communication ou admin, ⚠ à trancher par M8) | Émettre ou révoquer une **URL publique signée** | `dms.manage_public_link` |
| Consommateur applicatif (autre module : Finance, futur Field Service, futur Social) | Stocker/lire un document pour son propre usage via l'interface PHP `DocumentStore` (port synchrone, même patron que `App\Ocr\DocumentExtractor`) | *(pas de permission dédiée — portée par la permission du module appelant, ex. `finance.expense_report_submit`)* |
| Public anonyme (aucun compte) | Lire **uniquement** le contenu d'un document via une URL publique signée, valide et non révoquée | *(aucune — accès hors authentification, borné par le jeton, §4.2)* |

⚠ HYPOTHÈSE — noms de permissions proposés par analogie avec `App\Finance`/`App\Ocr` ; `dms.manage_public_link`
mérite un arbitrage explicite avec M8 tant il est sensible (§4.2) : peut-être faut-il un rôle dédié plutôt
qu'une simple permission, pour qu'aucun rôle « générique » ne l'obtienne par accident.

## 4. Comportements & règles

### 4.1 Cloisonnement — l'identifiant du document n'est jamais l'autorisation (D3/D8)

- **RG-DMS-01** — Périmètre serveur obligatoire sur toute lecture de collection/item. `PerimetreDmsExtension`
  (copie stricte du patron `App\Ocr\Doctrine\PerimetreOcrExtension` / `App\Finance\*\Doctrine\PerimetreFinanceExtension`)
  filtre `Document`, `DocumentVersion` et `DocumentPublicLink` sur `establishment` + `Affectation` de
  l'utilisateur courant (`Security::getUser()`, jamais un id transmis par le client). Échec fermé : hors
  périmètre = **404**, jamais une liste vide indiscernable d'un « pas encore de documents ».
- **RG-DMS-02** — D8 : toute route qui résout un `Document`/`DocumentVersion` depuis un identifiant fourni
  par le client et qui **n'est pas** un `GetCollection`/`Get` standard (téléchargement de fichier,
  remplacement de version, révocation de lien, modification de rétention, suppression) **revérifie
  explicitement** le périmètre serveur avant d'agir. Ce contrôle ne peut pas être délégué aux extensions
  Doctrine, qui ne s'exécutent que sur les opérations de lecture déclarées `read: true`.
- **RG-DMS-03** — L'identifiant d'un document (UUID) n'est **jamais**, à lui seul, une autorisation. Aucune
  route ne sert un fichier sur la seule base d'un `{id}` syntaxiquement correct : il faut authentification
  **et** appartenance au périmètre, **ou**, pour un accès anonyme, un jeton de lien public valide, non
  expiré, non révoqué (§4.2). Un id deviné/énuméré sans droit ne distingue jamais « existe mais interdit »
  de « n'existe pas » — réponse uniforme.
- **RG-DMS-04** — Établissement porteur immuable. `Document.establishment` est fixé à la création et n'est
  jamais modifiable par une écriture ultérieure (pas de « déplacement » d'un document d'un établissement à
  un autre par un simple `PATCH`) ; un tel besoin, s'il apparaît, est une opération dédiée, explicite et
  auditée — hors périmètre v1.

### 4.2 URL publiques signées — la seule brèche autorisée, jamais le mode par défaut

- **RG-DMS-05** — Émission volontaire et explicite. Aucun document n'est accessible anonymement par défaut.
  Un accès public exige la création explicite d'un `DocumentPublicLink` par un utilisateur habilité
  (`dms.manage_public_link`), pour un document et **une version précise, épinglée au moment de
  l'émission** — jamais « toujours la version courante » (cohérent §4.4, RG-DMS-20).
- **RG-DMS-06** — Expiration obligatoire. `DocumentPublicLink.expiresAt` est **requis** ; il ne peut être ni
  nul ni « sans limite ». Une durée maximale par défaut est imposée par configuration (⚠ HYPOTHÈSE : 30
  jours par défaut, paramétrable, plafonné à une valeur elle-même configurée — jamais un lien signé
  permanent).
- **RG-DMS-07** — Révocation à tout moment. Un utilisateur habilité peut révoquer un lien avant son
  expiration naturelle ; la révocation est **immédiate et définitive** (pas de « dé-révocation » — il faut
  émettre un nouveau lien si l'accès doit être rétabli).
- **RG-DMS-08** — Jeton opaque et non énumérable. Le jeton exposé dans l'URL publique n'est ni l'UUID du
  document ni une valeur prévisible, et son état (expiré/révoqué) est vérifié **côté serveur, à chaque
  accès** — un simple HMAC sans état persisté ne suffirait pas à porter la révocation (RG-DMS-07). ⚠ piste
  d'implémentation non normative pour le comportement observable : réutiliser la famille de génération
  signée déjà en place (`App\Vente\Service\GenerateurCodeSupport`, HMAC applicatif avec clé d'environnement
  dédiée), combinée à un enregistrement persisté (`DocumentPublicLink`) qui porte la vérité sur l'expiration
  et la révocation — l'architecte tranche le détail au plan technique.
- **RG-DMS-09** — Portée minimale. Un accès via lien public ne donne droit **qu'à la lecture du contenu de
  la version épinglée** — jamais aux métadonnées d'autres versions, aux autres documents de l'établissement,
  ni à une quelconque action d'écriture. Le contrôleur public est **distinct** du contrôleur authentifié
  (RG-DMS-03) et ne partage aucune session/aucun cookie applicatif.
- **RG-DMS-10** — Traçabilité de l'usage. Chaque accès via lien public incrémente `accessCount` et
  horodate `lastAccessedAt` sur `DocumentPublicLink`, pour permettre l'audit — sans qu'un événement de
  domaine soit émis par accès individuel (fréquence trop élevée pour constituer un fait métier au sens du
  catalogue, cf. §6 note).

### 4.3 Rétention réglementaire — la suppression est refusée, pas seulement déconseillée

- **RG-DMS-11** — Politique de rétention par catégorie. Chaque `Document` porte une `category` (enum
  anglais, §5) associée à une politique de rétention par défaut (ex. `accounting_piece` → 10 ans / 120
  mois, base légale France). `retainUntil` est calculé **une fois**, à la création du document (ou de sa
  première version), et **stocké** — jamais recalculé implicitement à chaque lecture.
- **RG-DMS-12** — Statut de rétention dérivé (calculé depuis `retainUntil`, pas un simple booléen figé en
  base) :
  - `none` — aucune politique attachée, suppression libre sous réserve des droits (`dms.delete`) ;
  - `active` — `retainUntil` dans le futur : **suppression refusée** ;
  - `expired` — `retainUntil` dépassé : suppression **autorisée**, document éligible à la purge après
    délai de grâce (RG-DMS-15).
- **RG-DMS-13** — Suppression **refusée**, pas seulement déconseillée. Toute tentative de suppression
  (logique ou physique) d'un `Document` en statut de rétention `active` échoue avec un refus explicite
  (**409 Conflict** — c'est l'état de la ressource qui motive le refus, pas une question de droits) et
  **rien n'est modifié**. L'événement `document.deletion_refused` est publié. Ce refus s'applique **quel
  que soit le rôle de l'appelant**, y compris un profil d'administration — seule une action dédiée et
  auditée (RG-DMS-16) peut lever une politique de rétention ; la suppression ne peut jamais la contourner.
  Même famille que l'inaltérabilité NF525 (`App\Vente\Nf525\InalterabiliteListener` /
  `App\Compta\Nf525\EcritureInalterableListener`) : un mécanisme de garde exécutable, pas une convention.
- **RG-DMS-14** — Suppression logique vs physique. Un document dont la rétention le permet (`none` ou
  `expired`) et qui est supprimé passe en statut `deleted` (suppression **logique** : les métadonnées et
  l'historique de versions restent en base, marqués supprimés, pour l'audit). Le contenu binaire n'est pas
  nécessairement retiré immédiatement — la purge physique est une opération distincte et différée
  (RG-DMS-15).
- **RG-DMS-15** — Purge après expiration. Une tâche planifiée (même patron que
  `App\Crm\Command\AppliquerConservationCommand`) retire physiquement le contenu des versions d'un document
  dont la rétention est `expired` **et** qui a été **explicitement supprimé** (statut `deleted`) depuis au
  moins un délai de grâce paramétrable (⚠ HYPOTHÈSE : 30 jours, pour permettre une restauration en cas
  d'erreur humaine). L'événement `document.purged` est publié. **Un document jamais supprimé n'est jamais
  purgé automatiquement** du seul fait que sa rétention a expiré — l'expiration ouvre le *droit* de
  supprimer, elle ne déclenche pas la suppression elle-même.
- **RG-DMS-16** — Modification de la politique de rétention. Attacher, prolonger ou (exceptionnellement,
  cas très encadré) lever une politique de rétention sur un document est une action **distincte**, réservée
  à `dms.manage_retention`, tracée (`document.retention_set`) — jamais un effet de bord d'une autre action
  (upload, remplacement de version).

### 4.4 Versionnement = preuve

- **RG-DMS-17** — Chaîne de versions append-only. Chaque `DocumentVersion` est créée une fois pour toutes :
  `fileHash` (sha256 du contenu), `sizeBytes`, `mimeType`, `storageKey`, `uploadedBy`, `uploadedAt` sont
  figés à la création et **ne sont jamais modifiés** après coup — garde au niveau ORM (`preUpdate`/
  `preRemove` rejettent toute écriture sur une `DocumentVersion` existante), même patron que
  `App\Vente\Nf525\InalterabiliteListener`.
- **RG-DMS-18** — « Remplacer » un document crée une nouvelle version. Il n'existe pas d'opération « modifier
  le fichier » d'une version existante. Remplacer ajoute une `DocumentVersion` avec `versionNumber` =
  précédent + 1 et `previousVersion` = version précédente (chaîne), puis met à jour
  `Document.currentVersion` vers la nouvelle — **sans supprimer ni altérer l'ancienne**, qui reste
  consultable et téléchargeable par quiconque a le droit de lire le document.
- **RG-DMS-19** — Version courante vs versions passées. `Document.currentVersion` désigne la version
  affichée par défaut dans l'explorateur et pointée par tout **nouveau** lien public émis après le
  remplacement. Les versions passées restent accessibles via l'historique (modale dédiée, §7), avec mention
  explicite « version remplacée le [date] » — jamais masquées ni supprimées par le remplacement. C'est ce
  qui rend une signature électronique défendable : un PV signé puis remplacé garde ses deux états.
- **RG-DMS-20** — Un lien public déjà émis reste épinglé à **sa** version au moment de l'émission
  (RG-DMS-05). Remplacer le document par une nouvelle version **n'invalide pas silencieusement** un lien
  existant et **ne le fait pas non plus pointer** vers le nouveau contenu. ⚠ HYPOTHÈSE — un besoin de « lien
  qui suit toujours la dernière version » (ex. visuel « menu du jour » republié) est hors v1 ; s'il apparaît,
  c'est un second type de lien à spécifier explicitement, pas un comportement implicite du premier.

### 4.5 Événements — contract-first (D2/D6)

- **RG-DMS-21** — Catalogue avant implémentation (RG-PLAT-06). Tout événement `document.*` figure dans
  `COORDINATION/CONTRACT/catalogue-evenements.md` **avant** toute implémentation ; `DmsModule::eventsEmitted()`
  correspond exactement à cette liste (§6).
- **RG-DMS-22** — Tenant dérivé du sujet (D6). `tenant.establishmentId` de tout événement `document.*` est
  dérivé de `Document.establishment`, **jamais** de `App\Securite\Service\ContexteEtablissement` (en-tête
  `X-Etablissement`, sélecteur client).
- **RG-DMS-23** — Charge utile minimale (RG-PLAT-04). Aucun événement `document.*` ne transporte le contenu
  du fichier, le jeton d'un lien public en clair, ni de PII superflue — uniquement des identifiants et le
  strict nécessaire (catégorie, taille, mime, dates). Le garde-fou `DomainEvent::assertPayloadIsCarryable()`
  refuse d'office les clés `token`/`secret`/etc. : ne pas tenter de les contourner par un nom de clé
  différent portant la même donnée.

### 4.6 Stockage & chiffrement — enfichable, jamais de clé en dur

- **RG-DMS-24** — Le domaine `App\Dms` ne connaît jamais un chemin physique ou une clé d'objet en dehors du
  port `Storage` (interface enfichable, même patron que `App\Vente\Nf525\SignataireOperation` ou
  `App\Ocr\DocumentExtractor`) : aucune dépendance directe à un SDK de stockage dans les entités/services
  métier (détail au §9.1).
- **RG-DMS-25** — Si le chiffrement au repos est retenu (§9.2), la clé est injectée **exclusivement** via
  une variable d'environnement dédiée + `#[Autowire(env: 'DMS_ENCRYPTION_KEY')]`, **sans valeur par défaut
  codée en dur** ; son absence empêche le démarrage du conteneur (échec fermé) — même patron que
  `App\Ocr\Service\ChiffreurApiKeyOcr` / `App\Sepa\Service\ChiffreurIban`. Deux clés en dur ont déjà été
  trouvées en deux jours de revue de cohérence sur ce dépôt : ce lot n'en fabrique pas une troisième.

### 4.7 Interface — le moins d'écrans possible (D13)

- **RG-DMS-26** — L'unique écran contribué par `App\Dms` est l'**explorateur de documents** (§7), justifié
  par la raison D13 n°1 (espace de travail durable : on y navigue, filtre, recherche, on y revient) et la
  raison n°3 (lien filtré partageable / reprise après interruption — retrouver « les pièces comptables du
  T1 » par une URL). Toute action (téléverser, renommer, remplacer une version, générer/révoquer un lien
  public, supprimer) se fait dans une **modale** ouverte depuis l'explorateur — jamais une page dédiée.

## 5. Objets de données

| Objet | Champ | Type | Contraintes | Notes |
|---|---|---|---|---|
| **`Document`** | id | uuid | PK | — |
| | establishment | ref `Etablissement` (`App\Organisation\Entity\Etablissement`) | requis, **immuable** | RG-DMS-01/04 |
| | category | enum `DocumentCategory` {`accounting_piece`, `expense_receipt`, `contract`, `intervention_report`, `hr_document`, `quote_attachment`, `marketing_asset`, `other`} | requis | RG-DMS-11 |
| | title | string | requis, ≤ 255 | — |
| | tags | array\<string\> | optionnel | recherche/filtre explorateur |
| | sourceModule | string, nullable | — | traçabilité de l'origine (ex. `expense_report`), jamais une FK dure vers un autre domaine |
| | currentVersion | ref `DocumentVersion` | requis dès la création (créée atomiquement avec le document) | RG-DMS-19 |
| | status | enum {`active`, `deleted`} | défaut `active` | RG-DMS-14 |
| | retentionPolicy | ref `RetentionPolicy`, nullable | — | RG-DMS-11 |
| | retainUntil | date, nullable | calculé une fois | RG-DMS-11/12 |
| | createdBy | ref `Utilisateur`, nullable | nullable = système | — |
| | createdAt, updatedAt, deletedAt | datetime, deletedAt nullable | — | RG-DMS-14 |
| **`DocumentVersion`** *(append-only)* | id | uuid | PK | — |
| | document | ref `Document` | requis | — |
| | versionNumber | int | requis, ≥ 1, séquentiel par document | RG-DMS-18 |
| | previousVersion | ref `DocumentVersion`, nullable | chaîne | RG-DMS-18 |
| | storageKey | string | requis, opaque | pointeur du port `Storage`, RG-DMS-24 |
| | fileHash | string | requis, sha256 hex (64 car.), **immuable** | RG-DMS-17 |
| | sizeBytes | int | requis, > 0 | — |
| | mimeType | string | requis | — |
| | originalFilename | string | requis | — |
| | uploadedBy | ref `Utilisateur`, nullable | nullable = système | — |
| | uploadedAt | datetime immutable | requis, **immuable** | RG-DMS-17 |
| **`DocumentPublicLink`** | id | uuid | PK | — |
| | document | ref `Document` | requis | — |
| | version | ref `DocumentVersion` | requis, épinglée à l'émission | RG-DMS-05/20 |
| | tokenHash | string | requis — le jeton en clair n'est **jamais** stocké, seulement remis une fois à l'émission | RG-DMS-08 |
| | expiresAt | datetime | requis | RG-DMS-06 |
| | revokedAt | datetime, nullable | — | RG-DMS-07 |
| | createdBy | ref `Utilisateur` | requis | `dms.manage_public_link` |
| | createdAt | datetime | requis | — |
| | accessCount | int | défaut 0 | RG-DMS-10 |
| | lastAccessedAt | datetime, nullable | — | RG-DMS-10 |
| **`RetentionPolicy`** *(catalogue fixe v1, ⚠ HYPOTHÈSE)* | code | string | PK, ex. `fr_accounting_10y` | — |
| | durationMonths | int | requis, ex. 120 | RG-DMS-11 |
| | legalBasisKey | string | clé i18n (ex. `dms.retention.fr_accounting_10y`) | jamais de libellé en dur |
| | defaultForCategory | enum `DocumentCategory`, nullable | — | RG-DMS-11 |

## 6. Événements de domaine proposés

Tous dérivent `tenant.establishmentId` de `Document.establishment` (D6, RG-DMS-22). `subject.type` =
`"Document"` (sauf mention contraire). Aucun payload ne porte de contenu de fichier ni de jeton en clair
(RG-DMS-23).

| Événement | Émis quand | Payload clé | Consommateurs probables |
|---|---|---|---|
| `document.stored` | Un `Document` est créé avec sa première `DocumentVersion` (upload initial) | `category`, `versionId`, `mimeType`, `sizeBytes`, `sourceModule?` | Compta/Finance, Reporting |
| `document.version_added` | Une nouvelle `DocumentVersion` remplace la version courante d'un document existant | `versionId`, `versionNumber`, `previousVersionId`, `mimeType`, `sizeBytes` | Le module consommateur du document (ex. futur Field Service) |
| `document.retention_set` | Une politique de rétention est attachée ou modifiée sur un document | `retentionPolicyCode`, `retainUntil` | Compta (audit légal) |
| `document.deletion_refused` | Une suppression est refusée car la rétention est `active` | `retainUntil`, `reasonCode` | Audit / Supervision |
| `document.deleted` | Une suppression logique est effectuée (rétention `none`/`expired`) | `category` | Audit |
| `document.purged` | La purge physique différée retire le contenu binaire | `category`, `retainUntilWas` | Audit |
| `document.public_link_issued` | Un `DocumentPublicLink` est créé | `versionId`, `publicLinkId`, `expiresAt` — **jamais le jeton** | Futur module Social/Marketing (Instagram), Audit |
| `document.public_link_revoked` | Un lien public est révoqué **manuellement** par un utilisateur habilité (RG-DMS-07) | `publicLinkId` | Futur module Social/Marketing, Audit |

**Note (RG-DMS-10)** — `document.public_link_accessed` (un événement par consultation anonyme) est
**volontairement exclu** du catalogue v1 : la fréquence (chaque vue Instagram/robot) en ferait un flux
technique, pas un fait métier au sens de la constitution du catalogue. L'usage est tracé sur l'entité
(`accessCount`/`lastAccessedAt`), consultable par les habilités, sans publier sur le bus à chaque accès.
⚠ Point ouvert si un futur besoin de réaction en temps réel à chaque vue apparaît (ex. déclenchement
marketing) — décision à prendre alors, pas anticipée ici.

## 7. Interface (UI)

**Écran unique : l'explorateur de documents** (`/dms/documents`, raccroché en filtre/onglet dans les
modules consommateurs — ex. l'onglet « Pièces jointes » d'une note de frais peut ouvrir l'explorateur
filtré sur ce document). Il liste/filtre par établissement (périmètre), catégorie, statut de rétention,
recherche par titre/tag. Un clic sur une ligne ouvre le **détail dans un panneau/modale**, pas une page —
cohérent D13 (« pas de page de détail en lecture seule quand une modale suffit »).

**Modales (une action = une modale) :**
1. **Téléverser un document** — catégorie, titre, tags, fichier → crée `Document` + `DocumentVersion` v1
   (`document.stored`).
2. **Remplacer la version courante** — fichier → crée une nouvelle `DocumentVersion`, met à jour
   `currentVersion` (`document.version_added`). Confirmation explicite : « la version précédente reste
   consultable dans l'historique », pour que l'utilisateur comprenne qu'il ne perd rien.
3. **Historique des versions** — liste des `DocumentVersion` (numéro, date, auteur, taille), chacune
   téléchargeable, la courante marquée explicitement.
4. **Renommer / modifier les métadonnées** — titre, catégorie, tags.
5. **Générer un lien public** — durée d'expiration (préréglages bornés par la config, RG-DMS-06), rappel
   explicite « accessible sans compte, à toute personne détenant ce lien » ; affiche l'URL **une seule
   fois** avec un bouton copier.
6. **Révoquer un lien public** — confirmation, effet immédiat (RG-DMS-07).
7. **Supprimer** — si rétention `active` : la modale **affiche le refus** avec la date de fin de rétention
   et la base légale (clé i18n) au lieu de proposer l'action ; si `none`/`expired` : confirmation classique
   de suppression logique.

Aucune de ces actions ne justifie un écran séparé au sens des 3 raisons D13 : ce sont des actions courtes,
au-dessus du contexte de l'explorateur.

## 8. Décisions ouvertes (à trancher par l'intégrateur)

### 8.1 Où vivent les fichiers : système de fichiers du VPS ou stockage objet (S3-compatible) ?

**Recommandation : démarrer sur le système de fichiers du VPS OVH, derrière un port `Storage`
enfichable — pas de S3 en v1.**

- Port `Storage` (interface `put(storageKey, stream): void`, `get(storageKey): stream`,
  `delete(storageKey): void`, `exists(storageKey): bool`) — même patron que `SignataireOperation`
  (NF525) ou `DocumentExtractor` (OCR) : le domaine `App\Dms` ne dépend jamais d'un SDK de stockage
  concret (RG-DMS-24). Adaptateur v1 : `LocalFilesystemStorage`, écriture sous un répertoire **hors
  racine web** (jamais servi directement par nginx — tout accès passe par un contrôleur PHP qui
  applique RG-DMS-01 à 03 ou RG-DMS-05 à 10).
- **Argument principal :** le déploiement actuel est **un seul VPS OVH** (mémoire projet). Introduire une
  dépendance S3 maintenant ajoute de l'infrastructure (bucket, identifiants, egress réseau, coût
  mensuel) sans consommateur qui la justifie aujourd'hui — c'est le même raisonnement que D7 a tenu pour
  différer `symfony/messenger` : ne pas ajouter d'infrastructure avant un besoin réel.
- **Ce que l'abstraction préserve :** le jour où le volume, la haute disponibilité multi-instance ou la
  sauvegarde déportée le justifient, un `S3CompatibleStorage` (OVH Object Storage est nativement
  compatible S3 — migration facilitée, même fournisseur) remplace l'adaptateur **sans toucher au
  domaine** `Document`/`DocumentVersion`/`DocumentPublicLink`.
- **Compromis assumé pour v1 :**
  - **Sauvegarde** — le répertoire de stockage local doit être inclus dans la routine de sauvegarde déjà
    en place pour la base (sinon les métadonnées survivent à une restauration mais pas les fichiers) ;
    point à porter au plan technique, pas une limite du domaine.
  - **URL publique signée servie depuis le FS** — chaque requête anonyme (Instagram, robot) tient un
    worker PHP-FPM le temps du transfert (`StreamedResponse`), contrairement à un stockage objet où l'on
    redirigerait (302) vers une URL S3 pré-signée et laisserait le transfert au stockage objet. Acceptable
    au volume actuel ; à réévaluer si le trafic public devient significatif (métrique à surveiller, pas
    une décision à prendre maintenant).
- **Alternative rejetée pour v1 :** démarrer directement sur S3-compatible — plus scalable et plus
  simple pour les liens publics (redirection au lieu de streaming applicatif), mais introduit une
  dépendance externe et un coût récurrent avant qu'aucun module consommateur (Finance, futur Social) n'ait
  produit un volume qui le justifie. Le port `Storage` rend ce choix réversible sans réécriture — cohérent
  avec le principe déjà acté pour le modèle tenant (base mutualisée vs instance dédiée, réversible sans
  réécriture).

### 8.2 Chiffrement au repos : oui/non, et avec quelle clé ?

**Recommandation : oui, chiffrement au repos par défaut pour tous les documents (pas une option par
catégorie), avec une clé d'application dédiée en environnement — pas de clé par document/utilisateur.**

- **Argument principal (menace) :** la GED stocke par construction des pièces comptables, justificatifs
  de frais et contrats — des documents sensibles **par défaut**, pas par exception. Sur un VPS unique
  (§9.1), une compromission au niveau du système de fichiers (permissions mal posées, snapshot/sauvegarde
  exfiltrée, disque volé, compte applicatif compromis) exposerait l'intégralité du contenu en clair si rien
  ne chiffre au repos. Le chiffrement applicatif ajoute une **seconde barrière indépendante** du contrôle
  d'accès (RG-DMS-01 à 03) — cohérent avec le cadrage de ce lot : le cloisonnement est LE sujet, pas une
  case à cocher.
- **Patron imposé, pas inventé :** réutiliser le mécanisme déjà en place — `libsodium
  crypto_secretbox`, clé 32 octets injectée par `#[Autowire(env: 'DMS_ENCRYPTION_KEY')]`, **aucune valeur
  par défaut** (RG-DMS-25), même famille que `App\Ocr\Service\ChiffreurApiKeyOcr`,
  `App\Sepa\Service\ChiffreurIban`, `App\Securite\Crypto\ChiffreurSecret`. Deux clés en dur ont déjà été
  trouvées en deux jours de revue de cohérence sur ce dépôt — ce lot n'en fabrique pas une troisième, il
  réutilise le patron existant avec sa propre variable d'environnement dédiée (une clé par usage, invariant
  noyau commun #4).
- **Différence à anticiper avec les usages existants :** `ChiffreurIban`/`ChiffreurApiKeyOcr` chiffrent de
  **courtes chaînes** en une seule opération mémoire. Un fichier (PDF, photo) peut peser plusieurs Mo :
  chiffrer/déchiffrer en mémoire reste raisonnable pour des documents (⚠ HYPOTHÈSE : plafond de taille
  d'upload à fixer au plan technique, ex. quelques dizaines de Mo), mais **ne passe pas à l'échelle pour de
  la vidéo** — si la GED devait un jour porter des médias volumineux (au-delà des visuels marketing), un
  chiffrement par flux/enveloppe (clé par fichier, chunké) deviendrait nécessaire. Point à trancher au plan
  technique, pas à cette étape.
- **Interaction avec l'URL publique signée (§4.2) :** un fichier chiffré au repos doit être **déchiffré à
  la volée côté serveur** pour être servi via une URL publique — la clé de chiffrement doit donc être
  utilisable **sans contexte utilisateur/session** (une clé d'application symétrique, pas une clé dérivée
  d'un compte). C'est exactement ce que permet le patron `crypto_secretbox` à clé unique recommandé
  ci-dessus ; une conception à base de clé par utilisateur serait **incompatible** avec l'accès anonyme
  requis par la contrainte n°2, à écarter d'emblée.
- **Alternative rejetée :** chiffrement au niveau du volume/disque uniquement (LUKS côté infrastructure
  VPS). Protège contre le vol physique du disque, mais **pas** contre une sauvegarde exfiltrée en clair,
  des permissions de fichier mal posées, ou un compte applicatif compromis qui lit le disque monté — ce
  sont précisément les scénarios que ce lot doit couvrir. À faire **en plus**, pas à la place, si
  l'hébergeur le permet (point d'infrastructure, hors périmètre de cette spec).
- **Alternative rejetée :** chiffrer seulement certaines catégories (`accounting_piece`, `hr_document`) et
  laisser les autres (ex. `marketing_asset`) en clair sur disque. Rejetée pour éviter un risque de
  classification erronée (« on pensait que ce n'était pas sensible ») — chiffrer uniformément est plus
  simple à garantir et moins coûteux à auditer qu'une règle par catégorie.

## 9. Critères d'acceptation

- **CA-1 (RG-DMS-01)** — *Étant donné* un utilisateur authentifié de l'établissement A, *quand* il liste
  ou consulte les documents, *alors* il ne voit **jamais** un document de l'établissement B, même s'il en
  connaît l'UUID exact.
- **CA-2 (RG-DMS-02/03, D8)** — *Étant donné* un utilisateur authentifié de l'établissement A, *quand* il
  appelle `GET /dms/documents/{id}/download` avec l'`{id}` d'un document de l'établissement B (deviné ou
  énuméré), *alors* la réponse est **404**, identique à celle d'un id inexistant — pas de distinction
  observable entre « interdit » et « n'existe pas ».
- **CA-3 (RG-DMS-06)** — *Étant donné* un lien public émis avec `expiresAt = T+24h`, *quand* il est utilisé
  après `T+24h`, *alors* l'accès échoue, même si le jeton est syntaxiquement valide.
- **CA-4 (RG-DMS-07)** — *Étant donné* un lien public actif et non expiré, *quand* un utilisateur habilité
  le révoque puis quelqu'un tente d'y accéder, *alors* l'accès échoue **immédiatement**, avant même
  l'échéance naturelle.
- **CA-5 (RG-DMS-13)** — *Étant donné* un document `category = accounting_piece` avec `retainUntil` dans le
  futur, *quand* une suppression est demandée (par n'importe quel rôle, y compris administrateur), *alors*
  elle est **refusée (409)**, le document reste intact à l'identique, et `document.deletion_refused` est
  publié.
- **CA-6 (RG-DMS-12)** — *Étant donné* le même document une fois `retainUntil` dépassé, *quand* la
  suppression est redemandée par un utilisateur `dms.delete`, *alors* elle **réussit** (statut `deleted`,
  logique — le fichier n'est pas nécessairement retiré au même instant).
- **CA-7 (RG-DMS-17)** — *Étant donné* un document à deux versions, *quand* une tentative de modification ou
  de suppression de la version 1 (non courante) est effectuée, que ce soit via l'API ou directement via
  l'ORM, *alors* elle est **rejetée** — la version 1 reste inchangée et téléchargeable.
- **CA-8 (RG-DMS-18/19)** — *Étant donné* un document en version 1, *quand* un utilisateur habilité
  téléverse un remplacement, *alors* une version 2 est créée, `currentVersion` pointe désormais vers elle,
  **et** la version 1 reste consultable dans l'historique, non altérée.
- **CA-9 (RG-DMS-22, D6)** — *Étant donné* un document de l'établissement E, *quand* `document.stored` est
  publié, *alors* `event.tenant.establishmentId == Document.establishment.id`, **indépendamment** de la
  valeur de l'en-tête `X-Etablissement` envoyée par le client qui a fait la requête.
- **CA-10 (RG-DMS-23)** — *Étant donné* l'émission d'un lien public, *quand* `document.public_link_issued`
  est publié, *alors* son payload contient `publicLinkId` et `expiresAt` mais **jamais** le jeton en clair
  ni une valeur permettant de le reconstruire.
- **CA-11 (RG-DMS-15)** — *Étant donné* un document `expired` mais **jamais explicitement supprimé**,
  *quand* la tâche planifiée de purge s'exécute, *alors* **rien n'est purgé** — l'expiration seule
  n'entraîne aucune suppression automatique.
- **CA-12 (RG-DMS-25, si §9.2 retenue)** — *Étant donné* un environnement où `DMS_ENCRYPTION_KEY` est
  absente, *quand* le conteneur applicatif démarre, *alors* il **refuse de démarrer** plutôt que de
  chiffrer avec une valeur par défaut.
- **CA-13 (RG-DMS-20)** — *Étant donné* un lien public émis sur la version 1 d'un document, *quand* ce
  document est ensuite remplacé par une version 2, *alors* le lien existant continue de servir le contenu
  de la **version 1**, inchangé.
- **CA-14 (RG-DMS-09, cas limite §10)** — *Étant donné* un document supprimé logiquement (rétention
  expirée), *quand* un lien public actif associé est utilisé, *alors* l'accès échoue — la suppression
  logique du document invalide l'usage de ses liens publics même non révoqués individuellement.

## 10. Cas limites

- **Fichier vide ou de type non déclaré à l'upload** — refusé avant création de la `DocumentVersion`
  (validation de taille > 0 et de cohérence mime/extension) ; ⚠ HYPOTHÈSE : le scan antivirus/contenu
  malveillant est **hors périmètre v1**, à cadrer séparément si le risque est jugé significatif.
- **Deux remplacements de version concurrents** — la contrainte d'unicité `(document, versionNumber)`
  tranche l'ordre d'arrivée serveur ; **aucune des deux écritures n'est perdue** — chacune produit sa
  propre version (contrairement à un vrai écrasement « dernier gagne » qui supprimerait un état).
- **Suppression logique d'un document avec liens publics actifs** — le contrôleur d'accès public vérifie
  aussi `Document.status == active`, pas seulement la validité du lien (CA-14) : la suppression invalide
  l'usage des liens sans qu'il faille les révoquer un par un.
- **Catégorie sans politique de rétention (`other`)** — `retentionStatus = none`, suppression soumise
  uniquement aux droits normaux (`dms.delete`), pas de blocage de rétention.
- **Changement d'établissement porteur d'un document** — explicitement hors périmètre (RG-DMS-04) ; si ce
  besoin apparaît (ex. fusion d'établissements), c'est une opération dédiée et auditée, pas ce lot.
- **Volume/format volumineux (vidéo)** — hors périmètre v1 ; le plafond de taille d'upload et son
  interaction avec le chiffrement en mémoire (§9.2) sont à fixer au plan technique.
- **Lien public généré pour une version déjà « historique » au moment de l'émission** (l'utilisateur choisit
  explicitement une ancienne version plutôt que la courante) — autorisé : RG-DMS-05 épingle « une version
  précise », pas nécessairement la courante ; cas d'usage valide (ex. partager une version antérieure d'un
  contrat pour référence).

## 11. Dépendances

- **Dépend de :**
  - Socle L0 — `App\Securite` (Utilisateur, Affectation, permissions module×action), `App\Organisation\Entity\Etablissement`,
    UUID (`symfony/uid`).
  - `App\Platform\Event` (bus synchrone in-process, `DomainEvent`/`EventBus`/`EventTenant`/`EventSubject`/
    `EventActor`) et `App\Platform\Module` (registre `ModuleManifest`) — briques déjà posées par D1/D2/D7.
  - `COORDINATION/CONTRACT/catalogue-evenements.md` — les 8 événements `document.*` (§6) doivent y être
    ajoutés **avant** l'implémentation (RG-PLAT-06).
- **Consommé par (actuels/prévus) :**
  - **Finance** (pièces comptables, justificatifs de frais) — usage synchrone via l'interface PHP
    `DocumentStore` (port à spécifier au plan technique, même famille que `DocumentExtractor` d'OCR).
  - **Futur module intervention terrain / signature électronique** — déjà anticipé au catalogue
    (`intervention.validated` liste « DMS » comme consommateur probable) ; **non construit à ce jour**, donc
    `DmsModule::eventsConsumed()` reste `[]` en v1 (même prudence que `FinanceModule` sur les dépendances à
    des modules qui n'implémentent pas encore `ModuleManifest`) — à activer quand ce module existera.
  - **Futur module Social/Marketing (Instagram et assimilés)** — dépend **directement** de la capacité
    d'URL publique signée (§4.2) : sans elle, aucun média ne peut être exposé publiquement par ce module.
    Ce lot lève ce blocage même si le stockage retenu reste le système de fichiers (§9.1) : l'URL signée
    reste servie, via un contrôleur dédié qui déchiffre/stream depuis le FS, plutôt qu'inexistante — le
    choix filesystem-vs-objet ne conditionne **pas** la disponibilité de la fonctionnalité, seulement son
    mode de service (streaming applicatif vs redirection vers un stockage objet).
- **Limite explicite :** ce lot ne construit ni la signature électronique, ni la validation d'intervention,
  ni l'analyse d'appel d'offres, ni la publication effective sur les réseaux sociaux — uniquement la
  capacité documentaire dont ces futurs modules dépendront (§2).

---

## Points ouverts / hypothèses (récapitulatif)

1. **⚠ HORS BACKLOG** — service entièrement nouveau, numérotation `US-DMS-01..06` proposée, à faire
   valider (en-tête).
2. **Permission `dms.manage_public_link`** — peut mériter un rôle dédié plutôt qu'une permission parmi
   d'autres, vu sa sensibilité ; arbitrage M8 (§3).
3. **Durée max par défaut des liens publics** (30 jours proposé) — à confirmer (RG-DMS-06).
4. **Délai de grâce avant purge physique** (30 jours proposé) — à confirmer (RG-DMS-15).
5. **`RetentionPolicy` en catalogue fixe vs entité configurable par établissement** — v1 propose un
   catalogue fixe codé ; à revoir si un besoin réel de personnalisation par tenant apparaît (§2, exclu).
6. **Plafond de taille d'upload et son interaction avec le chiffrement en mémoire** — non fixé, à cadrer au
   plan technique (§9.2, §10).
7. **Antivirus/scan de contenu à l'upload** — hors périmètre v1 (§2, §10).
8. **Décision 8.1 (stockage FS vs S3-compatible)** — recommandation FS + port `Storage` enfichable ;
   tranchée par l'intégrateur.
9. **Décision 8.2 (chiffrement au repos)** — recommandation oui, uniforme, `DMS_ENCRYPTION_KEY` ; tranchée
   par l'intégrateur.
