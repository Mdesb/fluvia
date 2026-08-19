# Plan technique — Noyau de plateforme : bus & registre (`C5`)

- **Spec source :** `specs/platform/spec-platform-core.md`
- **Stack :** Symfony 7.4 · API Platform · Doctrine/MariaDB · PHP 8.2
- **Propriétaire :** claude-A (intégrateur) — `app/src/Platform/**`, `app/tests/Platform/**`

## 0. État des lieux vérifié (ne pas re-supposer)
| Constat | Conséquence sur le plan |
|---|---|
| Pas de `symfony/messenger` dans `composer.json` (uniquement `framework-bundle` 7.4) | Bus **synchrone** sur `Symfony\Contracts\EventDispatcher\EventDispatcherInterface`. Aucune dépendance ajoutée. |
| 5 modules dispatchent déjà des events PHP maison en français (`Recouvrement` ×4, `Crm` ×1, `Acces`) | PLAT-3 les normalise **sans** changer leur comportement. |
| `app/src/Etablissement` **n'existe pas** ; c'est `App\Organisation\Entity\Etablissement` | Le tenant type sur `Organisation`, pas sur un chemin fantôme. |
| `App\Fonctionnalite\Service\Fonctionnalites::estActive()` existe déjà | `hasModule`/`hasFeature` **enveloppent** l'existant, on ne recrée pas de table. |
| `ContexteEtablissement` lit l'en-tête client `X-Etablissement` | **Interdit** comme source du tenant d'un événement (D6). |

## 1. Entités & schéma
**Aucune entité, aucune migration.** Le bus est sans état ; l'activation réutilise
`App\Fonctionnalite\Entity\FonctionnaliteEtablissement`. C'est volontaire : la brique doit pouvoir être
adoptée par les modules sans coût de schéma.

## 2. Classes à créer

### `App\Platform\Event`
| Classe | Nature | Rôle |
|---|---|---|
| `DomainEvent` | `final readonly` | L'enveloppe. Constructeur validant (RG-PLAT-01/02/03). |
| `EventTenant` | `final readonly` | `Uuid $establishmentId` — requis. |
| `EventActor` | `final readonly` | `Uuid $userId`. Absence d'acteur = `null` (système). |
| `EventSubject` | `final readonly` | `string $type` (nom court anglais), `string $id`. |
| `EventName` | `final` | Valide `^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$` + refus des noms hors catalogue en test. |
| `EventBus` | `interface` | `publish(DomainEvent $event): void` |
| `SymfonyEventBus` | `final` | Implémentation : `dispatch($event, $event->name)` — l'abonnement se fait **par nom**, pas par classe PHP. Compteur de profondeur (réentrance, cas limite §7). |

> **Point de conception clé.** On dispatche une **seule** classe (`DomainEvent`) en passant le `name`
> comme *event name* du dispatcher Symfony. Les modules s'abonnent donc à une **chaîne du contrat**
> (`invoice.overdue`), pas à une classe PHP d'un autre module — c'est ce qui réalise concrètement le
> découplage exigé par D2. Une classe par événement recréerait la dépendance de code qu'on veut tuer.

### `App\Platform\Module`
| Classe | Nature | Rôle |
|---|---|---|
| `ModuleManifest` | `interface` | `id()`, `version()`, `capability()`, `dependencies()`, `permissions()`, `eventsEmitted()`, `eventsConsumed()`, `routes()`, `features()`, `settingsSchema()` |
| `ModuleRegistry` | `final` | Reçoit les manifestes taggés `platform.module` ; indexe par `id` ; valide dépendances + cycles au démarrage (RG-PLAT-07). |
| `ModuleAccess` | `final` | `hasModule(Etablissement, string $capability): bool` et `hasFeature(Etablissement, string $feature): bool` — délègue à `Fonctionnalites::estActive()`. |
| `ModuleManifestInterface` tag | DI | `_instanceof` dans `services.yaml` ⇒ tag automatique, zéro configuration par module. |

### `App\Platform\Command`
`ListerModulesCommand` (`platform:modules`) — table des modules : id, version, capacité, dépendances,
événements émis/consommés, features. Sert la revue et le débogage (pas d'UI en v0).

## 3. Sécurité & droits
- Aucune surface HTTP ⇒ aucune permission nouvelle, aucun voter.
- **Le point de sécurité du lot est RG-PLAT-03** : le tenant vient du sujet, jamais du contexte HTTP.
  Matérialisé par le fait que `DomainEvent` **exige** un `EventTenant` non nul à la construction —
  impossible d'oublier, impossible de passer `ContexteEtablissement` par inadvertance (types différents).
- `payload` : revue manuelle à chaque ajout d'événement (pas de secret / PII superflue, RG-PLAT-04).

## 4. Migrations
Aucune.

## 5. Tests (`app/tests/Platform/`)
| Test | Type | Couvre |
|---|---|---|
| `DomainEventTest` | unitaire | CA-2, CA-3 — refus tenant nul, refus nom non conforme, `occurredAt` UTC |
| `SymfonyEventBusTest` | unitaire | CA-1 — publication par nom, abonné reçoit l'enveloppe |
| `EventBusDepthTest` | unitaire | réentrance bornée (cas limite §7) |
| `ModuleRegistryTest` | unitaire | CA-6 — dépendance inconnue, cycle, indexation par id |
| `ManifestCatalogueTest` | intégration | **CA-4 — tout `eventsEmitted()` figure dans `CONTRACT/catalogue-evenements.md`** (le fichier est lu et parsé : le contrat devient exécutable) |
| `ModuleAccessTest` | intégration | CA-5 — capacité inactive ⇒ `false`, feature indépendante du module |
| Suite existante | régression | CA-7 — les 146 tests restent verts après PLAT-3 |

> `ManifestCatalogueTest` est la pièce qui rend D2 réel : sans lui, « contract-first » est une intention.

Token de test isolé (PLAYBOOK §7.3) : `TEST_TOKEN=claudeA`.

## 6. Tâches (voir `tasks-platform-core.md`)
- **PLAT-0** — Enveloppe + bus + tests. *Débloque claude-B.*
- **PLAT-1** — `ModuleManifest` + `ModuleRegistry` + commande + `ManifestCatalogueTest`. *Débloque claude-B.*
- **PLAT-2** — `ModuleAccess` (`hasModule`/`hasFeature`) sur `Fonctionnalites`.
- **PLAT-3** — Reprise des 5 émetteurs existants vers l'enveloppe, en anglais.

PLAT-0 et PLAT-1 sont livrés et signalés à claude-B en priorité ; PLAT-2 et PLAT-3 suivent.

## 7. Risques / à valider
- **PLAT-3 touche des modules que je ne possède pas** (`Recouvrement`, `Crm`, `Acces`). Ils ne sont
  revendiqués par personne dans `OWNERS.md` et hors du périmètre de claude-B (Finance/Ocr/Compta) —
  je le signale dans `MESSAGES.md` avant de commencer, et je le fais en dernier.
- **RG-PLAT-05 (l'exception d'un abonné casse l'émetteur)** est un vrai arbitrage : il privilégie la
  cohérence transactionnelle sur la disponibilité. À revoir si un abonné best-effort provoque des
  régressions en préprod.
- **Nommage des `id` de modules existants** : `facturation`, `compta` restent en français jusqu'au
  retrofit D5. Le registre ne doit pas imposer l'anglais sur les `id` legacy, sinon PLAT-1 casse
  l'existant. Contrainte notée au contrat.
- ⚠ **HYPOTHÈSE (UNVERIFIED)** — je n'ai pas encore exécuté la suite de tests existante. Le chiffre de
  « 146 tests verts » vient du PLAYBOOK, pas d'une exécution que j'ai constatée. À vérifier avant PLAT-3.
