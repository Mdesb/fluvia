# Tasks — Noyau de plateforme (`C5`)

- **Plan source :** `specs/platform/plan-platform-core.md`
- **Instance :** claude-A · **Branche :** `claude-A` · **Token de test :** `claudeA`

Statuts : `À FAIRE` · `EN COURS` · `FAIT` · `BLOQUÉ`

| # | Tâche | Fichiers | Statut |
|---|---|---|---|
| **PLAT-0** | **Enveloppe + bus** | | **À FAIRE** |
| T1 | `DomainEvent`, `EventTenant`, `EventActor`, `EventSubject`, `EventName` | `app/src/Platform/Event/` | À FAIRE |
| T2 | `EventBus` (interface) + `SymfonyEventBus` (dispatch par nom, profondeur bornée) | `app/src/Platform/Event/` | À FAIRE |
| T3 | `DomainEventTest`, `SymfonyEventBusTest`, `EventBusDepthTest` | `app/tests/Platform/Event/` | À FAIRE |
| T4 | Signaler à claude-B que le bus est disponible | `COORDINATION/MESSAGES.md` | À FAIRE |
| **PLAT-1** | **Registre de modules** | | **À FAIRE** |
| T5 | `ModuleManifest` (interface, identifiants anglais) | `app/src/Platform/Module/` | À FAIRE |
| T6 | `ModuleRegistry` : tag `platform.module`, indexation, dépendances, cycles | `app/src/Platform/Module/`, `config/services.yaml` | À FAIRE |
| T7 | `ManifestCatalogueTest` — parse `CONTRACT/catalogue-evenements.md`, vérifie chaque `eventsEmitted()` | `app/tests/Platform/Module/` | À FAIRE |
| T8 | `ModuleRegistryTest` | `app/tests/Platform/Module/` | À FAIRE |
| T9 | Commande `platform:modules` | `app/src/Platform/Command/` | À FAIRE |
| **PLAT-2** | **Activation à deux niveaux** | | **À FAIRE** |
| T10 | `ModuleAccess::hasModule()` / `hasFeature()` sur `Fonctionnalites::estActive()` | `app/src/Platform/Module/` | À FAIRE |
| T11 | `ModuleAccessTest` | `app/tests/Platform/Module/` | À FAIRE |
| **PLAT-3** | **Reprise des émetteurs existants** *(en dernier — modules non possédés)* | | **À FAIRE** |
| T12 | Prévenir dans `MESSAGES.md` avant de toucher `Recouvrement`/`Crm`/`Acces` | `COORDINATION/MESSAGES.md` | À FAIRE |
| T13 | Exécuter la suite existante et **constater** le nombre de tests verts (lève l'hypothèse du plan §7) | — | À FAIRE |
| T14 | `Recouvrement` : `IncidentImpayeDetecteEvent` → `payment.failed`, `IncidentImpayeResoluEvent` → `payment.succeeded`, `AccesRedevableChangeEvent` → à nommer | `app/src/Recouvrement/` | À FAIRE |
| T15 | `Crm` : `PassageMajoriteEvent` → à ajouter au catalogue (`customer.came_of_age`) | `app/src/Crm/` | À FAIRE |
| T16 | Ajouter au catalogue les événements manquants découverts en T14/T15 | `COORDINATION/CONTRACT/catalogue-evenements.md` | À FAIRE |
| T17 | Suite complète verte, sans changement de comportement métier (CA-7) | — | À FAIRE |

## Ordre et raison
PLAT-0 puis PLAT-1 d'abord : ce sont les seuls livrables dont **claude-B** a besoin pour FIN-0/FIN-1.
PLAT-3 en dernier parce qu'il touche du code que je ne possède pas et qu'il n'a aucun caractère bloquant.

## Points de coordination
- **T4 / T12** : messages à claude-B — le premier annonce le déblocage, le second prévient d'une
  intrusion dans des modules partagés.
- **T16** modifie `CONTRACT/` — je suis intégrateur, donc c'est dans mon périmètre, mais tout ajout au
  catalogue est signalé dans `MESSAGES.md` (le contrat est lu par tout le monde).
