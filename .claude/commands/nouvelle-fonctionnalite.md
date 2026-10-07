---
name: nouvelle-fonctionnalite
description: Démarre le cycle SDD pour une nouvelle fonctionnalité — crée le dossier de suivi et lance la phase Spec avec l'agent chercheur.
user_invocable: true
---

# /nouvelle-fonctionnalite

Démarre une nouvelle fonctionnalité en Specification-Driven Development.

## Ce que tu fais quand cette commande est invoquée

1. **Demande le nom de la fonctionnalité** si l'utilisateur ne l'a pas donné (format court, en kebab-case, ex. `paiement-recurrent`).

2. **Crée le dossier de suivi** :
   ```
   features/<nom>/refs/
   features/<nom>/specs/
   features/<nom>/plans/
   features/<nom>/impl/
   ```
   Copie `features/_template/impl/impl-all.md` dans `features/<nom>/impl/impl-all.md`, état initial `discovery`.

3. **Lance l'agent `chercheur`** (Task tool) pour établir les faits VERIFIED/UNVERIFIED sur l'existant pertinent (code + base de connaissances). Ne saute pas cette étape même pour une petite fonctionnalité — elle évite les specs fondées sur de fausses suppositions.

4. **Pose des questions de découverte, une à la fois**, idéalement à choix multiple, pour clarifier ce que l'utilisateur veut vraiment. Ne passe pas à la spec tant que les objectifs (G-1, G-2, ...) ne sont pas clairs.

5. **Rédige la spec** dans `features/<nom>/specs/spec-<nom>.md` à partir du template `features/_template/specs/spec-exemple.md`. Chaque exigence numérotée G-N. Sépare clairement le "dans le périmètre" du "hors périmètre".

6. **Présente la spec à l'utilisateur pour validation — c'est le CP-1.** N'avance pas vers le plan sans un accord explicite ("oui", "valide", "go").

7. **Une fois CP-1 validé**, lance l'agent `architecte` pour produire le plan (`features/<nom>/plans/plan-<nom>.md`). Si sa section « Fiches à produire » n'est pas « aucune », lance **une seule fois** `documentaliste` avec toute la liste, puis rends les fiches à l'`architecte` s'il y a un écart (API retirée, signature différente) pour qu'il corrige le plan. Ensuite présente-le pour validation — **CP-2**.

8. **Une fois CP-2 validé**, passe en phase Construire avec les agents `developpeur` et `relecteur`, étape par étape, en mettant à jour `impl-all.md` après chaque étape.

## Rappels

- Ne saute jamais un checkpoint humain, même si la demande semble simple.
- Si l'utilisateur modifie son avis en cours de route, mets à jour la spec (avec son accord) plutôt que de laisser le plan/code diverger silencieusement de la spec.
- Si la fonctionnalité a un volet UI, envisage `/concevoir-ui` en phase Spec (avant CP-1).
- Clôture toujours par `/point-projet` (visibilité à jour).
