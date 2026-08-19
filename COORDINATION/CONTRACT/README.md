# CONTRAT DE PLATEFORME — v0 (brouillon)

Le contrat est la **seule dépendance** autorisée entre modules. Il définit ce qu'un module peut
supposer présent (le **noyau commun**), comment il se déclare (le **manifeste**), et comment il
communique (le **catalogue d'événements**). Tant qu'un module respecte le contrat, il peut être
écrit, testé, activé et facturé indépendamment.

> **v0 = brouillon de travail.** Objectif : donner aux instances Claude une base commune pour
> démarrer, pas un standard figé. Les points ouverts sont marqués « ⚠️ à trancher ».

## Les 3 pièces
1. **[noyau-commun.md](noyau-commun.md)** — les services que tout module peut utiliser :
   Identité, Organisations/Tenant, Cloisonnement, Permissions, Audit, Billing & Features,
   Communication, Automation/Scheduler, Bus d'événements, Registre de modules.
2. **[manifeste-module.md](manifeste-module.md)** — la fiche d'identité qu'un module déclare au core
   (id, version, dépendances, permissions, événements émis/écoutés, routes, réglages, features).
3. **[catalogue-evenements.md](catalogue-evenements.md)** — les événements métier de premier rang
   et la forme de leur charge utile.

## Principe directeur
Le core **émet des faits** ; les modules **réagissent**. Le core n'appelle jamais un module par son
nom. Un module ne connaît pas les autres modules — seulement les événements et le noyau.

## État
| Pièce | État |
|---|---|
| Noyau commun | v0 rédigé — à confronter au code réel des 3 projets |
| Manifeste de module | v0 rédigé — impl core à faire (`app/src/Platform/ModuleManifest`) |
| Catalogue d'événements | v0 rédigé — ⚠️ langue des noms à trancher |
| Bus d'événements (impl) | à faire — tâche C5 |
