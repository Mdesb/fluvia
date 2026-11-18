# Plan — nf525-alerte-scellement

**Statut :** en attente de CP-2 (porte automatique : CI verte + résumé d'une page) — révisé le 18/11/2026 : G-7 amendé (spec), fiches `dbal`, `mariadb`, `api-platform` lues
**Spec :** `features/nf525-alerte-scellement/specs/spec-nf525-alerte-scellement.md` (CP-1 validé par Maxime, 18/11/2026)
**Faits :** `features/nf525-alerte-scellement/refs/faits-chercheur.md`
**Issue :** #370 — branche `feature/nf525-alerte-scellement`, empilée sur `feature/nf525-ordre-cles` (#369, PR #373 non fusionnée). Dès que #373 est fusionnée : `git fetch origin && git merge origin/main`.
**Date :** 2026-11-18

## 0. Le plan en sept lignes (pour qui ne lit pas le code)

1. Quand un scellement est refusé, **le refus est écrit dans une alerte** par une **seconde connexion à la base**, qui valide tout de suite : l'annulation de la vente ne l'emporte pas (G-2).
2. Une alerte par chaîne bloquée : le deuxième refus ne fait que compter (G-1). Une règle de base de données l'impose, même quand deux ventes se refusent en même temps.
3. La cloche sonne **à l'ouverture seulement**, chez les administrateurs de l'établissement visé (G-4).
4. Le caissier ne lit plus qu'une phrase générale et une référence (G-8). Le détail technique n'existe plus que dans l'alerte.
5. Une vérification qui trouve la chaîne rompue ouvre ou met à jour la même alerte (G-3).
6. Une alerte née d'un **refus** se referme au premier scellement qui passe ; une alerte née d'une **vérification** rompue ne se referme qu'à la prochaine vérification qui retrouve la chaîne intacte (G-7 amendé).
7. Deux écrans : l'administrateur lit sans détail technique (G-5) ; l'éditeur voit tout et peut poser une note (G-6).

## 1. Décisions

### D-A. G-2 : comment l'alerte survit à l'annulation de la vente

Mesuré dans le code : le refus est levé **dans** le scellement (`SealKeyring::assertCanExtend` / `assertCanExtendInOrderFrom`, appelés par `ScellementHandler::sceller` l. 42-57, `ScellementEcritureHandler::sceller` l. 45-73, `ScellementFactureHandler::sceller` l. 57-81). Ces trois `sceller()` ont **une quinzaine de classes appelantes** (`ValiderVenteService` l. 193 dans `connection->transactional()`, `ContrePassationHandler`, `SettlementCorrectionHandler`, `DailyClosureHandler`, `PeriodClosureHandler`, `CloturerSessionProcessor`, `GenerateurEcrituresHandler`, `RegieHandler`, `RepriseAuPassageHandler`, `RepriseMensuellePcaHandler`, `AvoirFactureHandler`, `EmettreFactureDirecteHandler`, `EmissionFactureJustificativeHandler`, `ExtourneEcritureProcessor`, `DirectLedgerEntryBuilder`), dont certains imbriqués (avoir = facture + écriture) ou retenus dans la transaction d'un appelant (no-show).

| Option | Verdict | Pourquoi |
|---|---|---|
| **Capture après annulation** : écouteur `kernel.exception`, ou `try/catch` au point d'appel | **Rejetée** | (1) Un écouteur ne voit que les requêtes HTTP : les commandes et les abonnés du bus, qui avalent leurs exceptions (D7), n'alertent jamais. (2) `SynchroOperationsProcessor::rejouer` (l. 168-181) **attrape `\Throwable`**, fait `em->clear()` et range le message en quarantaine : l'exception ne sort jamais, l'écouteur ne la voit pas. (3) Un `try/catch` au point d'appel demanderait de modifier une quinzaine d'appelants, et le prochain appelant oublié ne serait jamais alerté : c'est précisément le défaut qu'on répare. |
| **Message Messenger** | **Rejetée** | (1) Le transport `async` est `in-memory://` en test (`messenger.yaml` l. 48) : aucun test d'intégration ne pourrait prouver « l'alerte existe en base ». (2) La référence de l'alerte doit être dans le 409, **donc connue tout de suite** : un message asynchrone ne la donnerait pas. (3) Le commentaire de `messenger.yaml` choisit le transport **Doctrine** (`doctrine://default`) : un `dispatch` fait dans la transaction du scellement serait écrit **sur la même connexion** et annulé avec elle. La valeur de `MESSENGER_TRANSPORT_DSN` en préprod/production (point UNVERIFIED de la spec) devient sans objet : on n'emploie pas Messenger. |
| **Connexion DBAL séparée**, ouverte au moment du refus, transaction courte, validée aussitôt | **RETENUE** | Indépendante de l'appelant, de la transaction englobante, de la quarantaine (`em->clear()`/fermé n'y change rien : on n'utilise pas l'ORM pour écrire), des commandes. Se place **une seule fois**, dans les trois handlers qui sont déjà l'unique porte de scellement. La référence est connue immédiatement. Testable avec la vraie base (la suite ne tourne pas dans une transaction enveloppante : `SchemaDuHarnais::reinitialiser` fait des `TRUNCATE`). |

**Conséquence assumée** : l'alerte décrit **la chaîne**, pas la vente. Elle existe même si la vente est annulée : c'est voulu.

**Conception de la seconde connexion** (une classe, `Nf525AlertConnection`) :
- créée à la demande avec `DriverManager::getConnection($main->getParams(), $main->getConfiguration())` ; `close()` appelé en `finally` (le bundle ne la ferme jamais) ; jamais partagée.
  **`getParams()` est `@internal` en DBAL 4.4.4** (fiche `doctrine-dbal-4.4.md`). Recours **justifié** et non évité : l'autre voie (recevoir `DATABASE_URL` par la DI) obligerait à réimplémenter l'analyse d'URL, le `charset`, la collation et le `dbname_suffix` de test que DoctrineBundle 3.3.1 applique (`ConnectionFactory::addDatabaseSuffix`), donc à dériver de la connexion principale sans le savoir ; `getParams()` en rend le résultat déjà résolu (`dbname` contient le suffixe). Parades : (1) l'usage est confiné dans **une seule classe** (`Nf525AlertConnection`) ; (2) **test sentinelle** (étape 4) : la seconde connexion lit `SELECT DATABASE()` égal à celui de la principale, une écriture par la seconde survit à l'annulation de la principale, et `getParams()` contient encore `host`, `dbname`, `user` ; il casse si le contenu change à une montée de DBAL ; (3) si la construction échoue, c'est un `\Throwable` du « meilleur effort » ci-dessous : le 409 part sans référence. **Ne jamais** repasser ces paramètres par `ConnectionFactory::createConnection()` du bundle (suffixe ajouté deux fois) ;
- `SET SESSION innodb_lock_wait_timeout = 3` dès l'ouverture (seul `SET SESSION` est permis à l'utilisateur applicatif ; la valeur 0 = échec immédiat, pas « sans délai » ; mesuré sur 11.4.13) : voir le risque de **verrou croisé** ci-dessous. **Après une erreur 1205 la transaction reste OUVERTE** (`innodb_rollback_on_timeout = OFF`) : le magasin fait un `rollBack()` explicite puis `close()` ;
- **toutes les lectures** (établissements visés, destinataires) se font sur la connexion et l'EM **de l'appelant** (vue cohérente avec la transaction en cours) ; **toutes les écritures** de l'alerte et des notifications sur la seconde connexion, en **une** transaction courte ;
- la table d'alerte **ne porte aucune clé étrangère** vers les lignes que la transaction de l'appelant peut verrouiller (point de vente, profil, journal : identifiants simples, comme `TreasuryCashAlert::causeSourceId`). Restent les clés étrangères vers `org_etablissement` (table de liaison) et `sec_utilisateur`/`org_etablissement` (notifications). **Mesuré (fiche MariaDB, 11.4.13)** : un `INSERT` enfant attend dès que la ligne parente est verrouillée par une autre session, même par un simple `UPDATE` d'une colonne hors clé, puis échoue en 1205 ; seule la ligne référencée est concernée, pas la table. Si la transaction de l'appelant tient un tel verrou sur un établissement ou un utilisateur visé, la seconde connexion attendrait une connexion qui l'attend. **L'absence de FK ne couvre donc que pdv/profil/journal ; c'est le délai court de 3 s (plus le `rollBack()` explicite) qui borne le reste**, et le test d'étape 4 le prouve avec un `UPDATE` (pas seulement `FOR UPDATE`) sur la ligne d'établissement ; l'échec est alors journalisé et le refus part **sans référence** (le 409 n'est jamais masqué par une panne de l'alerte).
- **Meilleur effort sur l'alerte, jamais sur le refus** : tout `\Throwable` de l'enregistrement est journalisé (`critical`, identifiants nettoyés) et le refus (409) est levé quand même.

### D-B. Dédoublonnage (G-1)

Sur le modèle exact de `TreasuryCashAlert` + `TreasuryCashAlertSchemaListener` : colonne virtuelle `open_chain_key` (`chain_key` si `status = 'open'`, sinon `NULL`) portant un index `UNIQUE`. MariaDB exclut les `NULL` d'un index unique : au plus **une alerte ouverte par chaîne**, garanti par la base.

Algorithme de `store->open(...)` (une transaction sur la seconde connexion) :
1. `INSERT` de l'alerte ouverte ; succès → **ouverte** (`opened = true`), on écrit liaisons + notifications (D-D), `COMMIT`.
2. `UniqueConstraintViolationException` (déjà ouverte, y compris perdue dans une course : mesuré, le second `INSERT` **attend** le `COMMIT` du premier puis échoue en 1062, ou réussit si le premier annule) → `UPDATE … SET refusal_count = refusal_count + 1, last_event_at = :now, cause = :cause, technical_detail = :detail WHERE open_chain_key = :key` (atomique, pas de lecture-puis-écriture ; **la clause `WHERE` nomme la colonne `open_chain_key`** : avant MariaDB 11.8 l'optimiseur ne reconnaît pas l'expression) ; une détection par vérification pose en plus `origin = 'verification'` (voir D-G) ; on relit la référence, **aucune notification** (`opened = false`).
3. `UPDATE` à 0 ligne (l'alerte a été refermée entre-temps) → un seul nouvel essai du pas 1.
4. Collision sur la référence (colonne unique séparée) : nouvelle référence, trois essais au plus.

### D-C. Modèle de données

Entité `App\Platform\Entity\Nf525Alert`, table `platform_nf525_alert` (le dépôt nomme `platform_notification`, `finance_treasury_cash_alert` : préfixe du module). **Pas de ressource API Platform sur l'entité** : les deux API sont des DTO à fournisseur explicite (patron `EditorSupportAccess`), donc aucune sérialisation accidentelle du détail technique.

| Colonne | Type | Rôle |
|---|---|---|
| `id` | BINARY(16) PK | UUID v4 |
| `reference` | VARCHAR(16) NOT NULL, UNIQUE | donnée au caissier : `NF525-` + 8 hexadécimaux majuscules tirés de `random_bytes` |
| `chain_type` | VARCHAR(16) NOT NULL | `sales` / `accounting` / `invoicing` (enum `SealChainType`) |
| `chain_key` | VARCHAR(100) NOT NULL | `sales:<pdv>` · `accounting:<profil>:<journal>` · `invoicing:<profil>` |
| `chain_label` | VARCHAR(160) NOT NULL | libellé **public** : « Caisse <libellé> », « Comptabilité — journal <code> », « Facturation » |
| `point_of_sale_id`, `operator_profile_id`, `journal_id` | BINARY(16) NULL | identifiants de la chaîne, **sans clé étrangère** (voir D-A) |
| `cause` | VARCHAR(24) NOT NULL | enum `SealRefusalCause` : `invalid_signature`, `unknown_key`, `key_regression`, `unreadable_order`, `chain_broken` (ce dernier pour G-3 : la spec n'en nomme que quatre, la vérification en exige un cinquième, voir Q-2) |
| `origin` | VARCHAR(16) NOT NULL | `refusal` ou `verification` : **commande la règle de fermeture** (D-G). Posée à l'ouverture ; une détection par vérification sur une alerte ouverte la fait passer à `verification` (jamais l'inverse) |
| `status` | VARCHAR(10) NOT NULL défaut `open` | `open` / `resolved` |
| `refusal_count` | INT NOT NULL défaut 0 | refus de sceller comptés |
| `detection_count` | INT NOT NULL défaut 0 | vérifications rompues comptées (sépare deux faits : l'écran dit « nombre de refus », pas un mélange) |
| `first_event_at`, `last_event_at` | DATETIME NOT NULL | premier / dernier événement |
| `technical_detail` | LONGTEXT NOT NULL (≤ 2000 car. tronqués) | **éditeur seulement** : identifiants `safeId`, n° de maillon, **jamais une clé** (test d'étanchéité sur les valeurs de clé de test) |
| `note`, `note_at`, `note_by` | LONGTEXT / DATETIME / VARCHAR(180), NULL | note de l'éditeur (précédent `granted_by`) |
| `resolved_at` | DATETIME NULL | G-7 |
| `open_chain_key` | VARCHAR(100) **virtuelle**, UNIQUE `uniq_nf525_alert_open_chain` | D-B. **NULL sous UNIQUE autorise les doublons** (mesuré) : elle ne doit donc être NULL que pour une alerte résolue. Garanti par `chain_key` NOT NULL et `status` NOT NULL (l'enum PHP n'écrit que `open`/`resolved`) ; **test d'étape 3** : toute ligne `open` a `open_chain_key IS NOT NULL`, toute ligne `resolved` l'a NULL, et une insertion avec `chain_key` ou `status` NULL est refusée par la base ; ajoutée au schéma de test par `Nf525AlertSchemaListener` (comme `TreasuryCashAlertSchemaListener`), car la suite construit son schéma depuis les métadonnées ORM, pas depuis les migrations |

Table de liaison `platform_nf525_alert_establishment (alert_id, establishment_id)` : clé primaire composée, FK vers l'alerte (`ON DELETE CASCADE`) et vers `org_etablissement`, index sur `establishment_id`. **C'est elle qui porte le cloisonnement.** Chaîne de vente → l'établissement du point de vente. Chaîne compta/facturation → principal **et** rattachés du profil exploitant (même définition que `ProfilExploitant::couvre`, l. 486), y compris un profil à plusieurs établissements. À chaque événement les liaisons manquantes sont ajoutées par un `INSERT` **simple**, une ligne par établissement, qui n'attrape **que** la violation d'unicité 1062 (déjà liée) ; **jamais `INSERT IGNORE`**, qui, mesuré, avale aussi la violation de clé étrangère (1452), tronque et convertit en silence, et dédoublonne selon la collation. Une erreur 1452 ou autre fait échouer l'écriture (meilleur effort, D-A). Jamais retirées. **Chaîne sans établissement identifiable** (hors périmètre de la spec) : aucune liaison, aucune notification ; seul l'éditeur la voit.

Index secondaire : `idx_nf525_alert_status_last (status, last_event_at)` pour les deux listes.

**Migration écrite à la main**, horodatée après `Version20261118120000` ; SQL d'abord demandé à Doctrine (`doctrine:schema:update --dump-sql`) et **seul le nécessaire** est repris ; vérifier l'absence de dérive après (`bin/verifier-derive-schema.sh`). Colonne générée : `GENERATED ALWAYS AS (CASE WHEN status = 'open' THEN chain_key END) VIRTUAL`, comme `Version20260901090100`.

### D-D. La cloche (G-4)

- Nouvelle règle dans `NotificationRule::table()` : `nf525.chain_blocked`, gravité `Critical`, droit `securite`/`gerer`, écran `alertes-nf525`, paramètre `alerte`, ancre `chain_label` → titre « Chaîne fiscale bloquée — Caisse X », texte « La caisse (ou la facturation) est bloquée. L'éditeur est prévenu ; vous n'avez rien à réparer vous-même. Ouvrez l'alerte pour suivre sa résolution. » (une phrase de plus à valider, voir Q-3). Critère d'admission (rare, appelle un geste, mène à un écran) : rempli, le geste étant « informer le site » ; le commentaire de classe passe de « sept » à « huit » règles.
- ⚠ Cette règle **ne passe pas par le bus d'événements** : `NotifyOnDomainEvent` persiste dans la transaction de l'émetteur sans `flush` (donc annulé avec elle, ce qui est juste pour tout autre événement mais faux ici). Un champ `viaBus` (défaut `true`) exclut cette règle de `admittedEvents()` ; `NotifyOnDomainEvent` ne s'abonne donc pas à un événement que personne n'émet (garde-fou « événements orphelins »).
- `Nf525AlertNotifier` lit la règle (`forEvent('nf525.chain_blocked')`), résout les destinataires par **`NotificationRecipientResolver::resolve($établissementVisé, 'securite', 'gerer')`**, pour **chaque établissement lié** (établissement **visé**, jamais `ContexteEtablissement` ni l'établissement actif de l'appelant), puis écrit une ligne `platform_notification` par destinataire et par établissement **sur la seconde connexion**, dans la même transaction que l'alerte : cloche à l'ouverture ⇔ alerte ouverte, exactement une fois. Un administrateur affecté à deux établissements couverts reçoit deux lignes (une par établissement, comme le reste de la cloche : `NotificationScopeExtension` filtre sur l'établissement actif).
- L'écriture est un `INSERT` SQL : un test d'étanchéité compare ses colonnes aux métadonnées ORM de `Notification` et relit la ligne par l'ORM (une colonne ajoutée à `Notification` sans toucher au notifieur fait échouer ce test, pas la production).
- Ni courriel (E-8), ni notification pour l'éditeur : il a son écran.

### D-E. Les deux API (cloisonnement serveur)

**Établissement — lecture seule, sans détail technique** : `GET /api/nf525-alerts` (filtre `status`), `GET /api/nf525-alerts/{id}`. DTO `Nf525AlertView` : `id`, `reference`, `chainType`, `chainLabel`, `cause`, `causeLabel`, `status`, `firstEventAt`, `lastEventAt`, `refusalCount`, `resolvedAt`. **Absents** : `technicalDetail`, `note`, `detectionCount`, identifiants de chaîne, autres établissements (Q-4 sur la note). Garde : `security: is_granted('PERM', 'securite.gerer')` ; fournisseur : `WHERE EXISTS (liaison avec l'établissement actif)` ; établissement actif absent → liste vide (échec fermé, comme `NotificationScopeExtension`) ; `{id}` d'un autre établissement → **404** (un 403 confirmerait l'existence). Aucune opération d'écriture n'est déclarée (pas de `Post`/`Patch`/`Delete` : 405).

**Éditeur** : `GET /api/editor/nf525-alerts` (filtre `status`), `GET /api/editor/nf525-alerts/{id}`, `POST /api/editor/nf525-alerts/{id}/note` (corps `{ "note": "…" }`, ≤ 2000 caractères, vide = efface). DTO `EditorNf525Alert` : tout l'établissement + `technicalDetail`, `detectionCount`, `origin`, noms des établissements liés, `note`, `noteAt`, `noteBy`. Garde : `EditorOnly::assertEditor('editor.nf525_alerts')` **dans le fournisseur et le processeur** (404 hors tenant éditeur, 403 sans le droit) ; `bin/garde-fou-routes-editeur.php` l'exige. La note est le **seul** geste d'écriture ; elle est tracée au journal d'audit (`JournalAudit::enregistrer`, précédent `SynchroOperationsProcessor::composer`). Le processeur lit la note par `LecteurCorps` (précédent) et la borne avant toute écriture.

Nouvelle permission **`editor.nf525_alerts`** (une seule, précédent `editor.manage_website`) : comment une permission neuve atteint les rôles en préprod/production est **UNVERIFIED** (aucune migration ne sème `editor.manage_website`) ; à mesurer au début de l'étape 11 (lecture de `InstallPlatformCommand`, `ModuleRegistry`, `ManifestCatalogueTest`) avant d'écrire du code.

### D-F. Message à la caisse et à la quarantaine (G-8)

- `SealingRefusedException` change de rôle : son **message** (`getMessage()`, ce que l'API rend et ce que la quarantaine copie) est une phrase fixe : « Scellement refusé : la chaîne fiscale de cette caisse est bloquée. L'administrateur et l'éditeur sont prévenus. Référence de l'alerte : NF525-XXXXXXXX. » (sans référence en cas de panne de l'alerte : la fin de phrase est omise). Elle garde `cause` (enum) et `technicalDetail()` (l'ancien message, identifiants nettoyés) : jamais rendus par l'API, seulement journalisés et copiés dans l'alerte.
- `SynchroOperationsProcessor::rejouer` l. 118 (« Chaîne serveur rompue : ancrage refusé… ») reçoit lui aussi la référence (G-3 + G-8) ; la quarantaine (l. 180 : `$e->getMessage()`) hérite du message générique **sans aucune modification**, ce que le test prouve.
- **Frontal de caisse** (mesuré, le point UNVERIFIED de la spec est levé) : `Caisse.jsx` l. 1272-1315 affiche `saleErrorMessage(e, 'validate', …)` = `e.message` (de l'`ApiError`, donc `detail`) dans une bannière `banner banner-error` ; `client.js` `messageFromPayload` (l. 108-126) rend `payload.message || hydra:description || detail || description || title`. **Fiche `api-platform-4.3.md` (v4.3.17, config du projet)** : un 409 `ConflictHttpException` rend `title` « An error occurred », **`detail` = le message de l'exception**, `description` (JSON-LD) = le même texte ; **il n'y a ni `message` ni `hydra:description`** (préfixe Hydra désactivé), et le masquage hors debug ne vaut que pour les 5xx : le 409 garde son message **en production**. Le frontal lit donc `detail` (par défaut de `message` et `hydra:description`) : G-8 et l'écran concordent. Le message générique doit rester sans détail technique, car il part tel quel au client en production ; la file hors ligne affiche `v.raison` (`OfflineCounter.jsx` l. 116). **Aucun changement de code frontal côté caisse** : un test `node --test` épingle ce passage du message tel quel (étape 15).

### D-G. Résolution (G-7, amendé au CP-2 par Maxime)

Deux règles, selon l'`origin` de l'alerte :
- **Origine `refusal`** : à chaque scellement dont les gardes passent (après `assertCanExtend*`, avant le retour du handler), `recorder->resolveOnSeal($chaîne)` : un `SELECT` indexé `WHERE open_chain_key = :k AND origin = 'refusal'` sur la connexion de l'appelant (aucune écriture tant qu'il n'y a rien à fermer), puis `UPDATE … status='resolved', resolved_at=:now` **sur la seconde connexion** (pas de verrou croisé avec la vente). Un échec **postérieur** du flush de la vente ne rouvre pas l'alerte : ce que la porte a prouvé (la chaîne accepte de se prolonger) reste vrai.
- **Origine `verification`** : un scellement réussi **la laisse ouverte** (il ne regarde que la fin de la chaîne). Elle ne se ferme qu'à la prochaine **vérification vivante intacte** de la même chaîne (`recorder->recordVerification($chaîne, intact: true)`) : `UPDATE … WHERE open_chain_key = :k AND origin = 'verification'`. Les vérifications vivantes sont les trois points de vérification et le rejeu hors ligne (D-I) ; la vérification d'une **archive** n'en fait pas partie (elle ne prouve rien sur la chaîne vivante).
- **Alerte mixte** : une vérification rompue sur une alerte ouverte par un refus la fait passer à `origin = 'verification'` (règle la plus stricte) ; un refus sur une alerte née d'une vérification ne la rétrograde jamais. Sinon un scellement réussi fermerait une chaîne que la vérification a trouvée rompue.
- Dans tous les cas, une chaîne qui refuse de nouveau après fermeture ouvre une **nouvelle** alerte et la cloche sonne de nouveau.

### D-H. Où brancher (principe 3 : un seul point par chaîne)

Chaque handler reçoit en **dernier argument de constructeur optionnel** `?SealAlertRecorder $alerts = null` (même patron que `$logger = new NullLogger()` du trousseau) : la douzaine de constructions manuelles des tests existants (`new ScellementHandler(…)`, etc.) restent valides ; Symfony autowire le vrai service. Le corps de `sceller()` est enveloppé :

```
try { gardes + calcul … } catch (SealingRefusedException $e) { throw $this->alerts?->recordRefusal($chaîne, $e) ?? $e; }
$this->alerts?->resolveOnSeal($chaîne);   // après les gardes
```

`HashChainSignataire::scelle` (l. 40-47) rejuge `assertCanExtend` : son exception remonte dans le `try` du handler, donc elle est alertée aussi. `ScellementEcritureHandler` et `ScellementFactureHandler` lèvent déjà `\LogicException` pour une écriture sans profil/journal (erreur de programmation, **pas** une alerte fiscale) : elle n'est pas capturée.

### D-I. Vérification rompue (G-3)

Appels placés **après** les contrôles de périmètre (jamais pour une requête refusée en 404) : `recordVerification($chaîne, intact: false)` ouvre ou met à jour l'alerte ; `intact: true` ferme une alerte d'origine `verification` (D-G). Chaîne dont l'identité vient du serveur, jamais du corps de la requête :
- `VerifierChaineProcessor` (l. 61), `VerifierChaineEcritureProcessor` (l. 77), `VerifierChaineFactureProvider` (l. 84) ;
- `SynchroOperationsProcessor::rejouer` (l. 116-119) ;
- `FiscalArchiveChecker` : **seulement** `verify(FiscalArchive)` (ouvre ou met à jour ; ne ferme jamais) d'une archive **enregistrée par le serveur**, jamais `verifyContent` (contenu fourni par un utilisateur : un faux ZIP ne doit pas pouvoir ouvrir d'alerte sur la chaîne d'un tiers) ; `verifierChaine` (l. 86-129) rend désormais les identifiants complets des points de vente en défaut (aujourd'hui `substr($pdv, 0, 8)` dans le texte), validés comme UUID existants avant tout enregistrement. Une archive en défaut ne dit pas que la chaîne vivante l'est : Q-5.
- Ce sont des `GET` (fournisseurs) qui écrivent : effet de bord assumé, idempotent par le dédoublonnage, et c'est la seule façon de ne rien oublier. Aucune bascule de fournisseur en processeur.

## 2. Étapes

Chaque étape : un objectif, tests d'abord, une commande de validation, moins de 400 lignes de code par commit. `<jeton>` = le jeton de test propre à la session (`./infra/test-stack.sh up <jeton>` une fois, dans un **worktree**). Après chaque étape : `./bin/garde-fous.sh` (54 garde-fous). `git checkout -- app/config/reference.php` avant chaque commit. Frontal : `node --test <fichier>` puis `cd frontend && npm run verifier`.

### Étape 0 — Fiches (prérequis, hors développeur)
La session lance `documentaliste` **une fois** pour la section 5, **avant l'étape 4**. Si une fiche contredit D-A (par exemple `DriverManager::getConnection` indisponible en DBAL 4.4.4), l'architecte corrige les étapes 4 à 6 avant CP-2.

### Étape 1 — Vocabulaire de la chaîne et de la cause  · G-1
- **Fichiers (nouveaux, `app/src/Platform/Nf525/`)** : `SealChainType.php` (enum), `SealRefusalCause.php` (enum), `SealChainRef.php` (valeur : type, `key()`, `label()`, identifiants optionnels ; fabriques `forSales(PointDeVente)`, `forAccounting(ProfilExploitant, Journal)`, `forInvoicing(ProfilExploitant)`).
- **Tests d'abord** (`app/tests/Platform/Unit/SealChainRefTest.php`) : la clé est stable et distincte par chaîne ; **témoin** : deux journaux du même profil → deux clés ; le libellé ne contient aucun UUID ni `NF525_` ; clé ≤ 100 caractères.
- **Fait** : `./infra/test-stack.sh run <jeton> app/tests/Platform/Unit/SealChainRefTest.php` vert ; garde-fous verts. ~110 lignes.

### Étape 2 — Le refus porte sa cause, son détail, et un message générique  · G-8, G-1
- **Fichiers** : `Platform/Nf525/SealingRefusedException.php` (cause, `technicalDetail()`, `withReference(string)`, message générique), `Platform/Nf525/SealKeyring.php` (les trois sites de levée l. 193, 280, 294, 335 passent la cause et l'ancien texte comme détail ; **journal `critical` inchangé**). Aucune dépendance nouvelle.
- **Tests d'abord** : (a) mise à jour mécanique des assertions sur l'ancien texte vers `technicalDetail()` : `tests/Platform/Unit/SealKeyringTest.php`, `SealKeyringOrderRefusalTest.php`, `tests/Vente/Unit/SealKeyRotationTest.php` (l. 66-67 : le préfixe « Scellement refusé » reste dans le message générique), `tests/Vente/Api/SealKeyOrderRefusalTest.php`, `tests/Compta/Api/SealKeyOrderTest.php`, `SealKeyRotationTest.php`, `tests/Facturation/SealKeyOrderTest.php`, `SealKeyRotationTest.php` ; (b) nouveau `SealingRefusedExceptionTest` : pour chacune des quatre causes, `getMessage()` ne contient ni `NF525_`, ni 16 hexadécimaux, ni UUID, ni « Procédure », ni `infra/` ; **témoin positif** : `technicalDetail()` contient bien l'identifiant (sinon le test précédent serait vrai pour une mauvaise raison) ; `withReference` ajoute la référence et ne modifie pas l'original.
- **Fait** : `./infra/test-stack.sh run <jeton> app/tests/Platform app/tests/Vente/Unit app/tests/Vente/Api/SealKeyOrderRefusalTest.php app/tests/Compta/Api app/tests/Facturation` vert (comparer au nombre de tests d'avant) ; garde-fous verts. ~90 lignes de code, ~60 lignes de tests modifiées.

### Étape 3 — Table, entité, index unique, migration  · G-1
- **Fichiers** : `Platform/Entity/Nf525Alert.php` (+ liaison `ManyToMany` vers `Etablissement` avec `JoinTable`, précédent `ProfilExploitant` l. 165-169), `Platform/Doctrine/Nf525AlertSchemaListener.php` (copie du patron `TreasuryCashAlertSchemaListener`), `app/migrations/Version<horodatage>.php` (écrite à la main, `up`/`down`).
- **Avant d'écrire la migration** : `doctrine:schema:update --dump-sql`, ne garder que les ordres de ce lot. Lire `infra/deploy-preprod.sh` l. 184 : la migration passe **après** le démarrage du nouveau code (fenêtre de quelques secondes sans table) ; c'est pourquoi les étapes 6 et 7 sont tolérantes à l'absence de la table (D-A).
- **Tests d'abord** (`tests/Platform/Integration/Nf525AlertSchemaTest.php`) : deux alertes `open` même chaîne → `UniqueConstraintViolationException` ; **témoin** : une `open` + une `resolved` même chaîne → acceptées ; deux chaînes différentes `open` → acceptées ; deux références identiques → refusées ; suppression d'une alerte → ses liaisons disparaissent ; **`open_chain_key` jamais NULL pour une alerte ouverte** (NULL autoriserait les doublons) et NULL pour une résolue ; `status` ou `chain_key` NULL refusés ; la colonne virtuelle existe dans le schéma de test (sinon l'unicité ne serait testée que sur le papier de la migration, piège déjà documenté par `TreasuryCashAlertSchemaListener`).
- **Fait** : test vert ; `./bin/verifier-derive-schema.sh` sans dérive ; garde-fous verts (en particulier migrations immuables, drop-migrations, nullable-non-nul, nommage anglais). Revue `architecte-donnees` (migration, index, types, `ALGORITHM`). ~170 lignes (hors migration).

### Étape 4 — Écriture de l'alerte sur la seconde connexion  · G-1, G-2
- **Fichiers** : `Platform/Nf525/Alert/Nf525AlertConnection.php` (création/fermeture/délai de verrou), `Platform/Nf525/Alert/Nf525AlertStore.php` (`open(SealChainRef, SealRefusalCause, origin, detail, list<Uuid> establishmentIds): OpenedAlert{reference, opened, alertId}` ; `resolve(chainKey, origin): bool`) — algorithme D-B, requêtes **toutes paramétrées**. Pas encore de notification.
- **Tests d'abord** (`tests/Platform/Integration/Nf525AlertStoreTest.php`, base réelle) : trois `open` sur la même chaîne → **une** ligne, `refusal_count = 3`, `first_event_at ≤ last_event_at`, `opened` = vrai, faux, faux ; **témoin** : deux chaînes → deux alertes ; chemin « perdu dans une course » : alerte ouverte insérée à part, `open` → compteur +1, `opened = false` ; `resolve` puis `open` → **nouvelle** ligne ; `resolve(clé, 'refusal')` sur une alerte `verification` → **rien** (témoin) ; détection par vérification sur alerte `refusal` → `origin` devient `verification`, refus sur alerte `verification` → reste `verification` ; **sentinelle `getParams()`** (`SELECT DATABASE()` égal, clés attendues présentes) ; **liaisons** : un doublon de liaison est absorbé (1062 seul), un établissement inexistant fait échouer l'écriture (1452 **non** avalé) ; **G-2 au niveau du magasin** : ouvrir une transaction sur la connexion principale, y écrire une ligne, appeler `open`, `ROLLBACK` principal → **l'alerte existe, la ligne non** (le témoin est la ligne annulée : sans lui, « l'alerte existe » ne prouverait rien) ; **verrou croisé** : la connexion principale fait un `UPDATE` (et, second cas, un `FOR UPDATE`) sur la ligne d'un établissement lié sans valider, `open` avec un délai de 1 s → `LockWaitTimeoutException` en moins de 3 s, transaction de la seconde connexion **annulée explicitement**, aucune ligne d'alerte laissée ; **témoin** : verrou sur un **autre** établissement → écriture immédiate.
- **Fait** : `./infra/test-stack.sh run <jeton> app/tests/Platform/Integration/Nf525AlertStoreTest.php` vert. ~230 lignes.

### Étape 5 — La cloche à l'ouverture  · G-4
- **Fichiers** : `Platform/Notification/NotificationRule.php` (règle + champ `viaBus`, commentaire « huit »), `Platform/Notification/Nf525AlertNotifier.php` (destinataires par établissement visé, `INSERT` des lignes), appelé par `Nf525AlertStore::open` **dans** la transaction de la seconde connexion quand `opened`.
- **Tests d'abord** (`tests/Platform/Integration/Nf525AlertBellTest.php`) : à l'ouverture, une notification par administrateur (`securite.gerer`) de l'établissement visé, gravité `critique`, `ecran = alertes-nf525`, `params.alerte` = id de l'alerte ; **deuxième refus : aucune notification de plus** (compter avant/après) ; **témoin négatif** : un utilisateur affecté à l'établissement **sans** `securite.gerer` (le lecteur des fixtures, établissement A) ne reçoit rien ; **établissement visé et non actif** : l'appelant est actif sur A, la chaîne est celle de B → les administrateurs de B reçoivent, pas ceux de A seuls ; profil à deux établissements → administrateurs des deux (un même administrateur affecté aux deux reçoit deux lignes) ; chaîne sans établissement → aucune notification ; **étanchéité** : les colonnes insérées = colonnes non nulles de `Notification` (métadonnées ORM) et la ligne est relue par l'ORM ; la règle est absente de `NotificationRule::admittedEvents()`.
- **Fait** : test vert + `app/tests/Platform/Integration/NotificationFromEventTest.php` toujours vert ; garde-fou événements orphelins vert. ~140 lignes.

### Étape 6 — Le service d'enregistrement  · G-1, G-2, G-4, G-7, G-8
- **Fichiers** : `Platform/Nf525/Alert/SealAlertRecorder.php` : `recordRefusal(SealChainRef, SealingRefusedException): SealingRefusedException` (résout les établissements visés par SQL sur la connexion de l'appelant, ouvre l'alerte, rend l'exception **avec référence** ; tout `\Throwable` → `critical` + l'exception d'origine sans référence) ; `recordVerification(SealChainRef, bool $intact, ?string $detail): ?string` (G-3 et G-7 : rompue → ouvre/met à jour et rend la référence ; intacte → ferme l'alerte d'origine `verification`) ; `resolveOnSeal(SealChainRef)` (D-G, origine `refusal` seulement, tolérant à l'absence de table). Le résolveur d'établissements (`SealChainEstablishments`) lit le point de vente (`caisse_point_de_vente.etablissement_id`) ou le principal + rattachés du profil (`compta_profil_etablissement_rattache`) : **noms de table et de colonne lus dans les métadonnées ORM**, pas recopiés.
- **Tests d'abord** (`tests/Platform/Integration/SealAlertRecorderTest.php`) : refus d'une chaîne de vente → exception portant `NF525-…` et alerte en base ; **panne simulée** de la seconde connexion → 409 quand même, sans référence, `critical` journalisé ; `resolveOnSeal` sans alerte ouverte → aucune écriture (témoin : compteur de requêtes d'écriture nul) ; avec alerte `refusal` ouverte → `resolved` + `resolved_at` ; avec alerte `verification` ouverte → **inchangée** ; `recordVerification(intact: true)` ferme l'alerte `verification` et pas l'alerte `refusal`.
- **Fait** : test vert. ~200 lignes.

### Étape 7 — Brancher les trois handlers de scellement  · G-1, G-2, G-7, G-8
- **Fichiers** : `Vente/Nf525/ScellementHandler.php`, `Compta/Nf525/ScellementEcritureHandler.php`, `Facturation/Nf525/ScellementFactureHandler.php` (D-H : argument optionnel + `try/catch` + `resolveOnSeal`) ; `config/services.yaml` seulement si l'autowiring de l'argument optionnel ne suffit pas (à constater, pas à supposer).
- **Tests d'abord** (un par chaîne, base réelle, **à travers l'API** pour la vente) :
  - `tests/Vente/Api/SealAlertEndToEndTest.php` — **G-2 de bout en bout** : une chaîne dont le dernier maillon est signé par une autre clé ; `POST …/valider` → **409**, la vente est restée « en cours » (rollback prouvé), **l'alerte existe** (une ligne, `refusal_count = 1`), le **`detail`** du corps du 409 (clé réelle d'API Platform 4.3.17, pas `message`) contient la référence et ne contient ni `NF525_`, ni identifiant de clé, ni l'UUID du point de vente (**G-8**) ; **témoin** : même scénario avec la clé correcte → 201, **aucune** alerte ; trois ventes refusées → **une** alerte, `refusal_count = 3`, dates ordonnées, **une seule** vague de notifications (**G-1**, **G-4**) ; deux points de vente → deux alertes ; **G-7 (origine refus)** : refus → ouverte ; restauration de la clé (clé retirée) puis vente réussie → `resolved` + date ; nouveau refus → **nouvelle** alerte et la cloche sonne de nouveau.
  - `tests/Compta/Api/SealAlertTest.php`, `tests/Facturation/SealAlertTest.php` : refus (signature invalide, clé inconnue, régression, ordre illisible) → alerte du bon type, **profil à deux établissements → liaisons et cloche des deux**, détail technique absent du message.
  - **Appelants hors transaction et imbriqués** : un test appelle `ScellementEcritureHandler::sceller` sans transaction (commande) et un autre via l'avoir (`AvoirFactureHandler`, imbriqué) : alerte présente dans les deux cas.
  - Les tests existants qui construisent les handlers à la main (`new ScellementHandler($em, $signataire, $ring)`) restent verts sans modification (argument optionnel) ; un test confirme qu'**avec** `null`, aucun accès à la table d'alerte n'a lieu.
- **Fait** : `./infra/test-stack.sh run <jeton> app/tests/Vente app/tests/Compta app/tests/Facturation app/tests/Platform` vert ; garde-fous verts. ~90 lignes de code, ~300 de tests (deux commits si > 400 au total).

### Étape 8 — La quarantaine hors ligne  · G-8, G-3
- **Fichiers** : `Vente/State/SynchroOperationsProcessor.php` (l. 116-119 : `recordVerification` ; rompue → message avec référence, intacte → ferme une alerte d'origine `verification` ; **rien d'autre** : la quarantaine l. 180 reprend déjà `getMessage()`).
- **Tests d'abord** (`tests/Vente/Api/SealAlertOfflineReplayTest.php`, voisin de `OfflineSalesReplayTest.php`) : une remontée de trois ventes sur une chaîne bloquée → trois entrées en quarantaine dont la `raison` contient la référence et **aucun** `NF525_`, identifiant de clé ou UUID de point de vente ; **une** alerte, `refusal_count = 3` ; l'`EntityManager` est vidé entre deux ventes (`em->clear()`) et l'alerte est tout de même écrite ; **témoin** : remontée sur chaîne saine → `inseres`, aucune alerte, quarantaine vide.
- **Fait** : test vert + `OfflineSalesReplayTest` vert. ~30 lignes de code.

### Étape 9 — Vérification rompue, vérification retrouvée intacte  · G-3, G-7
- **Fichiers** : `Vente/State/VerifierChaineProcessor.php`, `Compta/State/VerifierChaineEcritureProcessor.php`, `Facturation/State/VerifierChaineFactureProvider.php`, `Vente/Nf525/FiscalArchiveChecker.php` + son appelant d'archive enregistrée (à repérer par `grep` : ne **jamais** brancher `verifyContent`).
- **Tests d'abord** : **G-7 (origine vérification)**, pour chaque chaîne : vérification rompue → alerte ouverte ; **scellement réussi → alerte toujours ouverte** (témoin de l'amendement) ; vérification intacte (maillon réparé) → alerte `resolved` ; nouvelle rupture → **nouvelle** alerte et cloche ; alerte mixte (refus puis vérification rompue, puis scellement réussi) → toujours ouverte ; archive intacte → ne ferme rien. Et pour chacune des trois chaînes, une chaîne altérée (empreinte modifiée, comme les tests de vérification existants) → la vérification répond comme avant **et** une alerte `chain_broken` s'ouvre, notification comprise ; une **seconde** vérification → même alerte, `detection_count = 2`, **pas** de nouvelle notification ; **témoin** : chaîne intacte → **aucune** alerte (compter les lignes) ; **cloisonnement** : une vérification refusée en 404 (autre établissement) **n'ouvre rien** ; archive : une archive enregistrée dont une chaîne est altérée → alerte sur **ce** point de vente ; `verifyContent` d'un ZIP fourni par l'utilisateur, même en défaut → aucune alerte ; un point de vente inexistant dans le ZIP → aucune alerte.
- **Fait** : tests verts + tests de vérification existants verts (`app/tests/Vente`, `Compta`, `Facturation`). ~120 lignes.

### Étape 10 — API de lecture, côté établissement  · G-5
- **Fichiers** : `Platform/ApiResource/Nf525AlertView.php` (DTO, `GetCollection` + `Get`, aucune écriture), `Platform/State/Nf525AlertViewProvider.php`.
- **Tests d'abord** (`tests/Platform/Api/Nf525AlertViewTest.php`, administrateur A / B des fixtures, en-tête `X-Etablissement`) : l'administrateur voit les alertes ouvertes **et** résolues de son établissement ; **il ne voit pas** celle de B (liste) et reçoit **404** sur son `{id}` ; la réponse **n'a pas** les clés `technicalDetail`, `note`, `detectionCount`, ni identifiant de chaîne (`assertArrayNotHasKey`, **et** recherche d'un marqueur placé dans `technical_detail` en base : **témoin positif** que le champ existe côté base et ne sort pas) ; un profil à deux établissements : visible des deux ; `POST`, `PATCH`, `DELETE` → 405 ; le lecteur (sans `securite.gerer`) → 403 ; non authentifié → 401 ; pas d'établissement actif → liste vide.
- **Fait** : test vert ; garde-fous « couverture de périmètre », « entité rattachable », « filtres déclarés » verts. ~150 lignes.

### Étape 11 — API éditeur et note  · G-6
- **Première action (UNVERIFIED à lever)** : lire `Platform/Command/InstallPlatformCommand.php`, `ModuleRegistry`, `ManifestCatalogueTest`, `SocleFixtures` (l. 107-190) pour savoir où déclarer `editor.nf525_alerts` et comment il arrive aux rôles « Éditeur — Assistance/Administratif/Direction » en préprod ; consigner la réponse dans le suivi. **Aucun code avant.**
- **Fichiers** : `Platform/ApiResource/EditorNf525Alert.php`, `Platform/State/EditorNf525AlertProvider.php`, `Platform/State/EditorNf525AlertNoteProcessor.php` (`assertEditor('editor.nf525_alerts')` en tête), déclaration de la permission, fixtures de test.
- **Tests d'abord** (`tests/Platform/Api/EditorNf525AlertTest.php`) : l'éditeur voit **toutes** les alertes de tous les établissements, avec `technicalDetail` ; il pose une note → relue ; note vide efface ; note > 2000 caractères → 422 ; entrée journal d'audit présente ; **un administrateur d'établissement (même `securite.gerer`) sur `/editor/nf525-alerts` → 404, et sur `/note` → 404 (jamais 403)** ; un compte éditeur sans le droit → 403 ; **témoin** : le même compte éditeur **avec** le droit → 200 ; la note ne modifie ni statut ni compteurs.
- **Fait** : test vert ; `bin/garde-fou-routes-editeur.php`, `garde-fou-post-sans-suppression`, `garde-fou-validation-avant-processeur` verts (suivre les précédents `GrantSupportAccessProcessor`). ~170 lignes.

### Étape 12 — Frontal : client, fonctions pures, tests  · G-5, G-6
- **Fichiers** : `frontend/src/api/client.js` (ajout à côté de `editorSupportAccesses`, l. 3254 : `nf525Alertes`, `nf525Alerte`, `editorNf525Alertes`, `editorNf525Note`), `frontend/src/api/nf525Alerts.js` (libellés de cause en français, libellé de statut, tri, filtre ouvertes/résolues, borne de la note), `frontend/src/api/nf525Alerts.test.js`.
- **Tests d'abord** (`node --test`) : chaque cause connue a un libellé en clair ; **une cause inconnue** donne un libellé de repli lisible, pas `undefined` ; tri « ouvertes d'abord, récentes d'abord » ; la note de 2001 caractères est refusée côté client ; **aucun** champ technique n'apparaît dans les libellés.
- **Fait** : `cd frontend && node --test src/api/nf525Alerts.test.js && npm run verifier && npm run build`. ~130 lignes.

### Étape 13 — Frontal : écran de l'administrateur et lien de la cloche  · G-5, G-4
- **Fichiers** : `frontend/src/pages/AlertesNf525.jsx` (lecture seule : tableau cause en clair, dates, nombre de refus, filtre ouvertes/résolues, **aucun** bouton d'écriture ; un texte dit « l'éditeur est prévenu, rien à réparer de votre côté » ; tokens existants `card`, `card-h`, `banner`, `empty`, classes de gravité de `Cloche.jsx`), `frontend/src/App.jsx` (route `onglet === 'alertes-nf525'`, paramètre `alerte` pour surligner l'alerte ouverte depuis la cloche), `frontend/src/components/AppShell.jsx` (entrée de menu sous Administration, `perms: ['securite.gerer']`, voisine de `autorisations` l. 268), `docs/` si le design system l'exige.
- **Pas de changement dans `Cloche.jsx`** : `allerA(n.ecran, n.params)` (l. 115) mène déjà à l'écran nommé ; un test vérifie que l'identifiant d'écran `alertes-nf525` existe dans le menu (précédent `moduleMenu.test.js`).
- **Tests** : `moduleMenu.test.js` étendu (l'entrée n'apparaît qu'avec `securite.gerer`, y compris via le joker `*.*` : `aLeDroit`, pas `includes`, voir `App.jsx` l. 100-106) ; `npm run verifier` (droits) vert.
- **Fait** : `node --test src/components/moduleMenu.test.js && npm run verifier && npm run build`. Revue `relecteur-ui` (états vide / chargement / erreur / non branché, comme la cloche). ~150 lignes.

### Étape 14 — Frontal : écran de l'éditeur  · G-6
- **Fichiers** : `frontend/src/editeur/pages/AlertesNf525.jsx` (liste complète, filtre ouvertes/résolues, détail technique en bloc de texte (jamais en HTML), établissements liés, champ « note » + bouton « Enregistrer la note » : le seul geste), `frontend/src/editeur/EditeurApp.jsx` (ensemble `EDITEUR` l. 163, menu l. 177 avec `perms: ['editor.nf525_alerts']`, rendu l. 328, repli 404 → `onRefus` comme `AccesAssistance.jsx`).
- **Tests** : fonctions pures déjà couvertes (étape 12) ; `npm run verifier`.
- **Fait** : `cd frontend && npm run verifier && npm run build`. Revue `relecteur-ui`. ~160 lignes.

### Étape 15 — La caisse n'a rien à changer : on l'épingle  · G-8
- **Fichiers** : `frontend/src/api/counterWording.test.js` (ajout), éventuellement `offlineSales.test.js`.
- **Tests** : `saleErrorMessage` d'une `ApiError` 409 de message générique avec référence rend le `detail` **tel quel** (corps de forme réelle API Platform 4.3.17 : `title`, `detail`, `description`, sans `message`) ; la raison de quarantaine d'une vente hors ligne est affichée telle quelle (`raison: q.raison`, `offlineSales.js` l. 167-169).
- **Fait** : `cd frontend && node --test src/api/counterWording.test.js src/api/offlineSales.test.js`. ~30 lignes.

### Étape 16 — Documentation, décision, clôture
- **Fichiers** : `infra/README.md` § « Trousseau NF525 » (ce que fait l'alerte, où la lire, qui est prévenu, la procédure de rotation reste serveur), `COORDINATION/DECISIONS.md` (entrée **append-only** : seconde connexion, résolution au scellement, note éditeur ; numéro suivant la ligne de base de `garde-fou-numeros-de-decision`), index des chantiers.
- **Fait** : `./bin/garde-fous.sh` complet vert ; **suite PHP complète** lancée par le développeur (la CI ne la lance pas) : `./infra/test-stack.sh run <jeton>` ; `/valider-module` ; `/verifier-specs` ; `/point-projet`.

## 3. Tests — récapitulatif par objectif

| Objectif | Tests (étape) | Témoin positif / négatif |
|---|---|---|
| G-1 | 3 (index), 4 (magasin), 7 (API vente, compta, facturation) | 3 refus → 1 alerte ; 2 chaînes → 2 alertes ; ouverte + résolue autorisées |
| G-2 | 4 (rollback principal, alerte présente, ligne absente), 7 (API de bout en bout, appelants hors transaction et imbriqués), 8 (EM vidé) | la ligne annulée prouve que le rollback a eu lieu ; clé correcte → aucune alerte |
| G-3 | 8, 9 | chaîne intacte → aucune alerte ; 404 → rien ; ZIP utilisateur → rien |
| G-4 | 5, 7, 9 | non-administrateur → rien ; deuxième refus → rien ; établissement visé ≠ actif |
| G-5 | 10 | champ technique absent **et** présent en base ; autre établissement → 404 |
| G-6 | 11, 12, 14 | administrateur → 404 ; éditeur sans le droit → 403 ; avec → 200 |
| G-7 | 4, 6, 7, 9 | refus → résolue ; vérification rompue → scellement la laisse **ouverte** → vérification intacte la ferme ; nouveau refus → **nouvelle** alerte + cloche |
| G-8 | 2, 7, 8, 15 | message sans `NF525_`/clé/UUID **et** `technicalDetail()` les contient |

## 4. Couverture Goal → Étapes

| Goal | Étapes |
|---|---|
| G-1 Alerte persistée, une par chaîne | 1, 2, 3, 4, 6, 7 |
| G-2 L'alerte survit au refus | 4, 6, 7, 8 |
| G-3 Vérification rompue | 6, 8, 9 |
| G-4 Cloche à l'ouverture seulement | 5, 6, 7, 9, 13 |
| G-5 Écran établissement, lecture seule | 10, 12, 13 |
| G-6 Écran éditeur et note | 11, 12, 14 |
| G-7 Résolution automatique (refus : scellement ; vérification : vérification intacte) | 4, 6, 7, 9 |
| G-8 Message générique à la caisse et en quarantaine | 2, 6, 7, 8, 15 |

Toute étape couvre au moins un objectif de la spec ; l'étape 16 (documentation, clôture) ne couvre aucun objectif nouveau et n'en invente pas. Aucun objectif n'est cité qui n'existe pas dans la spec (G-1 à G-8).

## 5. Fiches à produire

**Produites le 18/11/2026 par `documentaliste` (lues)** : `docs/references/doctrine-dbal-4.4.md` (§ « Seconde `Connection` indépendante », § « DoctrineBundle 3.3.1 et `dbname_suffix` »), `docs/references/mariadb-11.4.md` (§ colonne générée, FK parent verrouillé, `SET SESSION`, `INSERT IGNORE`, mesurés sur 11.4.13), `docs/references/api-platform-4.3.md` (rendu des erreurs, v4.3.17). Les autres fiches de `docs/references/README.md` restent valides (vérifiées le 2026-10-07, à revoir avant le 2027-01-05) et sont citées.

**Fiches qui contredisent le plan, et correction faite** :
| Fiche | Contradiction | Correction |
|---|---|---|
| dbal | `Connection::getParams()` est `@internal` | D-A : recours justifié (l'autre voie réimplémente URL, charset, suffixe de test), confiné à une classe, test sentinelle (étape 4) |
| mariadb | `INSERT IGNORE` avale 1452, tronque, suit la collation | D-C : `INSERT` simple qui n'attrape que 1062 ; test d'étape 4 |
| mariadb | UNIQUE sur colonne générée : NULL autorise les doublons | D-C : `open_chain_key` NULL seulement si résolue, testé (étape 3) |
| mariadb | `INSERT` enfant attend un parent verrouillé (même par `UPDATE`), 1205, transaction laissée ouverte | D-A : l'absence de FK ne couvre que pdv/profil/journal ; délai 3 s + `rollBack()` explicite ; test avec `UPDATE` (étape 4) |
| api-platform | le message d'un 409 est dans `detail` (et `description`), pas `message` ni `hydra:description` | D-F, étapes 7 et 15 : les tests lisent `detail` ; le frontal l'atteint par `messageFromPayload` ; concordance confirmée |

**Rien à produire de plus.** API déjà employées dans le code (on recopie, pas de fiche) :
- DTO `#[ApiResource]` + fournisseur/processeur : `Subscription/ApiResource/EditorSupportAccess.php`, `State/EditorSupportAccessProvider.php`, `GrantSupportAccessProcessor.php`.
- `EditorOnly::assertEditor` : `Subscription/Security/EditorOnly.php` l. 66.
- `ToolEvents::postGenerateSchemaTable`, `#[AsDoctrineListener]` : `Finance/Treasury/Doctrine/TreasuryCashAlertSchemaListener.php`.
- `ManyToMany` + `JoinTable` : `Compta/Entity/ProfilExploitant.php` l. 165-169.
- `executeStatement`, `UniqueConstraintViolationException`, `transactional()` : fiche dbal. `#[Autowire]`, argument optionnel : `ScellementHandler.php` l. 27, `SealKeyring.php` l. 57.
- `Uuid` : fiche symfony-uid. React, `fetch`, `node --test`, PHPUnit : fiches react-18.3, web-fetch-blob, node-20, phpunit-13.3.
- Messenger et `kernel.exception` : non employés (D-A).

## 6. Risques

| # | Risque | Parade |
|---|---|---|
| R-1 | **Verrou croisé** : la seconde connexion attend une ligne d'établissement ou d'utilisateur que la transaction de la vente tient (mesuré : même un `UPDATE` suffit) ; la vente attend la fin de l'alerte. | Pas de FK vers pdv/profil/journal ; délai 3 s ; `rollBack()` explicite après 1205 ; échec = 409 sans référence + `critical` ; test d'étape 4. Coût résiduel : 3 s de plus sur ce refus-là seulement. |
| R-14 | `getParams()` `@internal` change à une montée de DBAL. | Une classe, test sentinelle ; l'échec dégrade en 409 sans référence, jamais en vente bloquée. |
| R-13 | Alerte mixte : une alerte née d'un refus, puis vérifiée rompue, ne se ferme plus au scellement. | Voulu (D-G) ; documenté ; testé. |
| R-2 | Une panne de l'alerte masquerait le refus. | Meilleur effort : le 409 est toujours levé (étape 6, test de panne simulée). |
| R-4 | Fenêtre de déploiement : le code neuf tourne avant que la migration soit passée (`deploy-preprod.sh` l. 184). | Étapes 6 et 7 tolérantes à l'absence de table (journalisé, jamais fatal) ; la vente ne dépend jamais de l'alerte. |
| R-5 | Faux ZIP d'archive qui ouvre des alertes sur la chaîne d'un tiers, ou qui inonde. | `verifyContent` jamais branché ; identifiants validés ; dédoublonnage ; test d'étape 9. |
| R-6 | Fuite du détail technique à l'administrateur. | DTO séparé sans le champ ; test avec marqueur en base ; revue `security-reviewer`. |
| R-7 | Le texte d'une clé ou d'un identifiant forgé en base (`seal_key_id`) traverse jusqu'à l'écran de l'éditeur. | `safeId` à l'écriture ; tronqué à 2000 ; rendu en texte, jamais en HTML ; test avec un identifiant piégé (`<script>`). |
| R-8 | Churn de ~70 assertions de tests existants sur l'ancien texte. | Étape 2 les migre mécaniquement vers `technicalDetail()` ; construction des handlers inchangée (argument optionnel). |
| R-9 | Un appelant de `sceller()` ignoré (une quinzaine recensés par `grep`) : un chemin futur. | Le branchement est dans les handlers, pas chez les appelants : un nouvel appelant est couvert par construction. |
| R-10 | Un `GET` de vérification qui écrit. | Assumé, idempotent, après contrôle de périmètre. |
| R-11 | Nouvelle permission `editor.nf525_alerts` qui n'arrive pas aux rôles en préprod. | UNVERIFIED levé en tête de l'étape 11, avant tout code. |
| R-12 | Un administrateur qui perd son droit après l'ouverture garde sa notification. | Comportement existant et voulu (`NotificationRecipientResolver`, « liste figée »). |

## 7. Décisions par défaut, à confirmer au CP-2

Q-1 est tranchée : G-7 est amendé par Maxime (spec, 18/11/2026) et le plan l'applique (D-G).

- **Q-2** Cinquième cause `chain_broken` pour les alertes de vérification (la spec en nomme quatre, toutes de refus). Retenu : oui.
- **Q-3** Texte de la cloche de D-D (titre « Chaîne fiscale bloquée — <chaîne> », texte court sans détail technique), proposé par l'architecte. Retenu : tel quel, ajustable en une ligne de `NotificationRule`.
- **Q-4** L'administrateur ne voit **pas** la note de l'éditeur (elle peut citer du technique). Retenu : non ; une ligne à changer dans `Nf525AlertView`.
- **Q-5** Une archive fiscale en défaut ouvre l'alerte de la chaîne **vivante** du point de vente (origine `verification`, cause `chain_broken`) ; elle est fermée à la prochaine vérification **vivante** intacte, jamais par une archive.

## 8. Stratégie de revue

- **Après chaque étape** : `./bin/garde-fous.sh`, tests de l'étape, `relecteur` (voie normale).
- **`architecte-donnees`** : étape 3 (migration, colonne virtuelle, index, types, liaison, absence de FK volontaire, `down()`).
- **`relecteur` + `security-reviewer` en mode adversarial** (zone sensible : NF525, cloisonnement, argent, base) sur les étapes **4 à 11** : à essayer de casser — deux refus simultanés, alerte créée pour une requête non autorisée, faux ZIP, identifiant de clé forgé jusqu'à l'écran, IDOR sur `{id}`, administrateur sur `/editor/…`, note de 10 Mo, notification chez un utilisateur d'un autre établissement, `resolve` déclenché par un tiers, injection par `chain_label`, verrou croisé délibéré pour geler la caisse.
- **`relecteur-ui`** : étapes 13 et 14 (états vide/chargement/erreur, clavier, contrastes, tokens existants, pas de nouveau langage visuel).
- **CP-3 (porte automatique)** : CI verte (« Tous les garde-fous du lanceur », « Banc d'essai des garde-fous »), **suite PHP complète lancée par le développeur** (la CI ne la lance pas), résumé d'une page, puis `/verifier-specs` code ↔ spec.
- **Git** : PR en brouillon tant que #373 n'est pas fusionnée ; commits conventionnels en français (`feat(nf525):`, `securite(nf525):` pour les étapes 4-9) ; jamais de `git add -A` ; aucune poussée sans confirmation humaine.
