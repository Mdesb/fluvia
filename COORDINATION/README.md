# COORDINATION — travailler à plusieurs Claude sur la plateforme

Ce dossier est le **point de rendez-vous** des différentes instances Claude (et humains) qui
construisent la plateforme. Les instances **ne partagent pas de mémoire vive** : elles se
coordonnent **par ce dépôt git**, de façon explicite et asynchrone — comme une équipe distribuée.

> 👉 **Tu arrives ? Commence par [ONBOARDING.md](ONBOARDING.md)** (5 min), qui te renvoie au document
> unique [PLAYBOOK.md](PLAYBOOK.md) : vision, décisions, architecture, contrat, méthode et **toutes les
> commandes**. Puis laisse/lis un mot dans [MESSAGES.md](MESSAGES.md) et claim ta tâche dans
> [TASKS.md](TASKS.md).

## Décision fondatrice (19/08/2026)
Convergence en **socle unique**. Le core **Symfony 7 / API Platform / Doctrine / MariaDB** de la
billetterie devient **LA coquille** de la plateforme. Les autres domaines (Finance, Smart Flow,
Revenue Recovery, puis progressivement les domaines OFS et Vespera) deviennent des **modules
activables** dessus. Retrofit **incrémental**, jamais big-bang.

## Les 4 règles de collaboration
1. **Source de vérité = le dépôt bare sur le VPS** (`vps main`). On pousse tout, on ne garde rien en local.
2. **Propriété des modules** (voir [OWNERS.md](OWNERS.md)) : chaque instance possède des dossiers.
   On ne commit **jamais** le WIP non committé d'un autre. Staging **explicite**, jamais `git add -A`.
3. **Contract-first** : aucun module ne se construit avant que son **manifeste** et ses **événements**
   soient posés dans [CONTRACT/](CONTRACT/). Le contrat est la seule dépendance entre modules.
4. **Confiance = CI verte + revue de cohérence**. On ne relit pas tout le code des autres ;
   on fait confiance aux garde-fous automatiques (cloisonnement, CSRF) + à la boucle de revue
   (impl → revue → correctif). Toute décision transverse va dans [DECISIONS.md](DECISIONS.md).

## Cadence
- **Claim** une tâche dans [TASKS.md](TASKS.md) avant de la démarrer (évite les collisions).
- **Un rôle d'intégrateur** maintient le CONTRACT et arbitre les conflits transverses.
- Les modules communiquent **par événements**, jamais par appel direct de module à module.

## Fichiers
| Fichier | Rôle |
|---|---|
| [OWNERS.md](OWNERS.md) | Carte de propriété : module → instance responsable |
| [TASKS.md](TASKS.md) | Tableau de claim : qui fait quoi, maintenant |
| [DECISIONS.md](DECISIONS.md) | Journal **append-only** des décisions transverses |
| [CONTRACT/](CONTRACT/) | Le contrat partagé : noyau commun, manifeste de module, catalogue d'événements |
