---
name: valider-module
description: Audit complet post-module (sécurité, CSS, cohérence, couverture de tests) avant CP-3 — produit un rapport 1 page en langage clair, sans jargon de code.
user_invocable: true
---

# /valider-module

Bundle de validation à lancer **après qu'un module (ou une fonctionnalité) est considéré terminé**, juste avant de demander CP-3 (revue avant merge) au responsable produit.

## Pourquoi cette commande existe

Quand le·s décideur·s de CP-3 n'ont pas de compétences de développeur, CP-3 ne peut pas reposer sur une relecture de code. Le garde-fou doit être automatique (CI verte : tests, lint, analyse statique, scan de secrets, audit des dépendances) **et** produire un résumé en langage clair que le responsable produit peut lire sans ouvrir un seul fichier de code. Cette commande fabrique ce résumé. Cadence : après chaque module terminé — pas de calendrier fixe.

## Ce que tu fais quand cette commande est invoquée

1. **Identifie le module concerné** et son dossier `features/<nom>/`.
2. **Lance, dans cet ordre** :
   - `security-reviewer` sur le code du module (injections SQL, isolation multi-tenant si applicable, secrets, auth)
   - `relecteur-ui` si le module a un volet UI (les deux couches : design system + WCAG 2.1 AA, ET conventions d'interaction R… — modale vs page, confirmation destructive, filtrage live, pagination, auto-save, cards, drag-and-drop, dropzone)
   - `coherence-reviewer` scopé au module (le code correspond-il à la spec et au plan validés en CP-1/CP-2 ?)
   - Vérifie que `test-generator` n'a pas de trou de couverture évident sur les endpoints/fonctions critiques du module
3. **Vérifie l'état de la CI** sur la branche du module — si la CI n'est pas verte, **arrête-toi ici** et signale-le : CP-3 ne doit pas être demandé tant que la CI n'est pas verte.
4. **Rédige un rapport d'une page**, sans jargon technique, dans `features/<nom-module>/impl/rapport-validation-cp3.md` :
   - Verdict global en une phrase : prêt pour CP-3 / réserves à lire avant d'approuver / bloquant
   - Ce qui a été vérifié automatiquement (liste courte, résultat par ligne : ✅/⚠️/❌)
   - S'il y a des réserves : qu'est-ce que ça veut dire concrètement pour l'utilisateur final (pas pour le code) et qu'est-ce qui est recommandé
   - Rien à faire manuellement de leur part au-delà de lire ce rapport
5. **Présente le rapport dans le chat** en plus de l'écrire sur disque, et attends la décision du responsable produit avant tout merge.

## Ce que cette commande ne fait pas

- Ne corrige rien automatiquement — signale, ne modifie pas le code du module.
- Ne remplace pas la CI (qui reste le premier filtre, bloquant, avant même d'en arriver ici).
- Ne se déclenche pas toute seule sur un calendrier — c'est un geste explicite, à la fin de chaque module.
