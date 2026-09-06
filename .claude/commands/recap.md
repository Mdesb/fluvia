---
name: recap
description: Produit un récapitulatif de session — ce qui a été fait, décidé, et ce qui reste ouvert — et le journalise.
user_invocable: true
---

# /recap

Récapitule la session en cours (ou la dernière session importante) pour que la prochaine personne (humaine ou agent) ait le contexte sans tout relire.

## Ce que tu fais quand cette commande est invoquée

1. **Résume** : ce qui a été fait (fichiers créés/modifiés), ce qui a été décidé (et pourquoi), ce qui reste ouvert (UNVERIFIED, tâches en attente).
2. **Ajoute une entrée** dans `JOURNAL_DECISIONS.md` (racine du repo) : date, titre court, ce qui a été décidé, statut.
3. **Met à jour `TASKS.md`** (racine du repo) si des tâches ont été complétées ou ajoutées pendant la session.
4. **Signale explicitement** toute hypothèse non vérifiée (UNVERIFIED) qui bloquerait une étape suivante.

## Pourquoi cette commande existe

Un résumé factuel, commité, à la fin de chaque session significative, évite la perte de contexte entre deux sessions de travail. C'est la mémoire durable du projet.
