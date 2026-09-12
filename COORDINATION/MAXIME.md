# Façon de faire de Maxime — mémoire partagée des sessions

> Injecté au démarrage de **chaque** session par le plugin `garde-fous-sdd`. Tenu par l'agent `greffier` après
> chaque arbitrage ; éditable à la main. Pas de « je » : lu par les neuf sessions. Les décisions ponctuelles
> (premier client, périmètre, PSP…) vont dans `COORDINATION/DECISIONS.md`, pas ici.

## Comment lui parler

- **Questions en QCM, jamais ouvertes.** Une question fermée, 2 à 4 options lettrées, chacune avec ce qu'elle
  implique (coût, risque, ce qu'on perd), la recommandation de la session marquée et justifiée, une option
  « Autre ». Il répond par une lettre. Raison donnée : « ça force les agents à pousser l'analyse jusqu'au bout ».
- **Une question à la fois.** Ordonner les points ouverts, poser le premier.
- **Il écrit, il n'invoque aucune commande ni skill.** C'est la session qui suit le protocole que le harnais lui
  donne ; ne jamais lui demander de taper une commande slash.
- **Les questions ne sont pas des instructions.** Une question appelle une réponse, pas une modification.
- **Répondre en français**, sans jargon inutile, chiffres à l'appui quand il y en a.

## Préférences

- 2026-09-12 · **Rien d'externe ne tourne chez nous : construire maison avec les briques natives, copier sélectivement ce qui vaut le coup (licence permettant), refuser tout runtime tiers.** — parce que « ça éviterait qu'un truc externe tourne chez nous » — source : « On peut pas copier et faire nous-mêmes un bundle » — portée : générale
- 2026-09-12 · **Livrer en fichiers et en commits locaux ; ne jamais pousser, publier ni ouvrir de PR sans son accord explicite.** — parce que c'est lui qui décide de ce qui part vers GitHub — source : consigne du 03/09 et du 12/09 — portée : générale
- 2026-09-12 · **Le code le plus court qui passe les tests. Pas d'abstraction pour un seul usage, pas de fichier nouveau si une fonction suffit, une retouche reste une retouche.** — parce que « les agents me faisaient 400 lignes de code pour une fonctionnalité qui ne pourrait en prendre que quelques dizaines » — portée : générale
- 2026-09-12 · **Toute décision, spec ou plan est challengé avant validation (contradicteur + perspectives), sous plafond de crédits et en cohérence avec le journal des décisions.** — source : « un agent qui challenge les décisions, tout en gardant la limite de crédit et la cohérence globale » — portée : générale
- 2026-09-12 · **Les parcours utilisateurs restent simples : l'utilisateur cible n'est pas à l'aise avec l'informatique.** — source : « tout le monde n'est pas à l'aise avec l'outil informatique… il faut qu'on puisse garder des paths simples » — portée : projet
- 2026-09-12 · **Rien du look générique des logiciels faits par IA ; une signature propre au produit.** — source : « tous les logiciels faits par Claude Code, sites webs etc se ressemblent » — portée : projet
- 2026-09-13 · **Donner de la liberté aux agents métier (proposer, décider, saisir) à condition qu'ils soient challengés ; il garde un veto plutôt qu'une validation.** — source : « aller plus loin sur les agents métiers, sur leurs niveaux de liberté tant qu'ils sont challengés » — portée : générale
- 2026-09-13 · **Une session garde en mémoire sa façon de faire (ce fichier) ; ne pas la lui redemander.** — source : « un agent qui gardait en mémoire ma façon de faire » — portée : générale

## Manière d'arbitrer

- 2026-09-12 · **Il demande d'abord « en quoi ça améliore » et « quoi d'autre » avant d'aller plus loin : présenter l'évaluation honnête, chiffrée, avant de construire la suite.** — source : « dis-moi d'abord en quoi ça améliorerait nos méthodes » — portée : générale
- 2026-09-12 · **Il empile les demandes au fil de l'eau pendant qu'on construit ; les prendre comme des ajouts au même chantier, pas comme des changements de cap, et les articuler en un tout cohérent.** — source : « à toi de trouver la meilleure manière pour articuler tout ça » — portée : générale
- 2026-09-03 · **Il tranche vite par QCM sur le périmètre et l'organisation ; il renvoie l'argent et le fiscal à un professionnel (expert-comptable, LNE/Infocert) plutôt que de trancher seul.** — source : réponses aux huit QCM du 03/09 — portée : projet

## Ce qu'il refuse

- 2026-09-12 · **La cérémonie pour la cérémonie : « ne re-densifie pas ». Si quelque chose coince, retirer de la friction avant d'ajouter un agent ou une étape.** — source : kit SDD, historique d'allègement — portée : générale
- 2026-09-03 · **Qu'on lui propose de supprimer le code hors mission (Finance, Social, Legal, Dining…) : gardé en l'état, modules séparés non commercialisés.** — source : QCM du 03/09 — portée : projet
