---
description: Déroule le pipeline SDD (spec → plan → implémentation → revue) pour une story ou un module donné.
argument-hint: <US-Lx-nn | Mx | nom-de-fonctionnalité>
---

Objectif : livrer `$ARGUMENTS` en suivant la méthode SDD du projet.

Étapes (n'en saute aucune, valide chacune avant la suivante) :

1. **Cadre** — Lis `specs/constitution.md`. Repère les `RG`/`US` et décisions actées concernées
   dans `cahier-detaille.html` et `backlog.html`.
2. **Spécifier** — Lance l'agent `sdd-analyste` → produit `specs/<lot>/spec-<slug>.md`.
3. **Planifier** — Lance l'agent `sdd-architecte` sur la spec → `specs/<lot>/plan-<slug>.md`
   et `specs/<lot>/tasks-<slug>.md`.
4. **Implémenter** — Lance l'agent `sdd-dev-symfony` (ou plusieurs en parallèle sur des modules
   indépendants, en worktrees isolés) → code + tests dans `app/`. Applique les migrations.
5. **Vérifier** — Lance l'agent `sdd-revue`. Corrige les points bloquants/majeurs. Confirme
   `GET /health` vert et `phpunit` au vert.
6. **Clore** — Mets à jour la spec si divergence, coche la Definition of Done, commit en français
   référençant les `US`/`RG`.

Pour plusieurs modules indépendants : orchestre les agents **de concert** (Workflow / worktrees),
un module par agent, puis intègre. Le socle (L0) se fait d'abord et de façon cohérente, pas en parallèle.
