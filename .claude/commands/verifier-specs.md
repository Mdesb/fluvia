---
name: verifier-specs
description: Rétropropagation — compare le code livré à la spec d'origine, et si un écart légitime est trouvé, corrige la spec (jamais le contraire silencieusement).
user_invocable: true
---

# /verifier-specs

Vérifie qu'une fonctionnalité livrée correspond toujours à sa spec, et corrige la spec si le code a évolué pour de bonnes raisons en cours de route.

## Ce que tu fais quand cette commande est invoquée

1. **Demande quelle fonctionnalité vérifier** si ce n'est pas évident (nom du dossier dans `features/`).

2. **Lance l'agent `relecteur`** en passe "Conformité à la spec" : compare le code réellement produit à `features/<nom>/specs/spec-<nom>.md`, objectif par objectif (G-1, G-2, ...).

3. **Pour chaque écart trouvé**, détermine s'il est :
   - **Un bug** — le code ne fait pas ce que la spec (correcte) demande → corriger le code, pas la spec.
   - **Un écart légitime** — le code a dû s'adapter à une réalité découverte en cours de route (contrainte technique, retour utilisateur, incohérence détectée) → corriger la spec pour refléter la réalité, avec une note expliquant pourquoi.

4. **Ne corrige jamais la spec silencieusement.** Présente chaque écart légitime à l'utilisateur avant de modifier `spec-<nom>.md` : quoi, pourquoi, et ce que ça change pour la documentation le cas échéant.

5. **Journalise** la correction dans la section "Journal de Rétropropagation" de `features/<nom>/impl/impl-all.md` : date, écart trouvé, décision prise, fichier spec modifié.

6. **Si la fonctionnalité est terminée et validée**, propose de la déplacer vers `features/archive/<nom>/`.

## Pourquoi cette étape existe

Sans elle, la spec devient obsolète dès la première adaptation en cours de code, et perd sa valeur de documentation de référence. La rétropropagation garde la spec vivante et fiable pour la prochaine personne (humaine ou Claude) qui la lira.
