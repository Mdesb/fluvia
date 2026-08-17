---
titre: "Bornes ITBOX et mode dégradé (fonctionnement hors connexion)"
categorie: acces-controle
publicCible: agent
portee: global
moduleLie: acces
statut: publie
resume: "Les bornes ITBOX continuent de contrôler les accès même en cas de coupure réseau."
motsCles: [itbox, borne, mode degrade, hors ligne, synchronisation]
---
## Pourquoi un mode dégradé

Une borne **ITBOX** doit continuer à contrôler les accès même en cas de coupure du réseau ou du
serveur central : elle embarque donc une copie locale des droits valides et des supports révoqués, et
prend ses décisions d'autorisation **localement** tant que la connexion n'est pas rétablie.

## Fonctionnement

- En fonctionnement normal, l'ITBOX se **synchronise régulièrement** avec le serveur (nouveaux droits,
  révocations, mise à jour de la liste de révocation).
- En cas de coupure, la borne bascule automatiquement en **mode dégradé** : elle continue de scanner
  et d'autoriser/refuser les passages sur la base de sa dernière synchronisation connue.
- Les passages enregistrés hors connexion sont **journalisés localement** puis **remontés** dès le
  retour de la connexion, sans perte d'information.

## Ce qu'il faut savoir en exploitation

- Un support bloqué **après** la dernière synchronisation d'une borne en mode dégradé peut encore être
  accepté par cette borne jusqu'à sa prochaine synchronisation — à signaler si un cas litigieux est
  suspecté.
- L'état de synchronisation de chaque borne (dernière synchro, mode courant) est visible depuis
  l'écran de supervision technique.
