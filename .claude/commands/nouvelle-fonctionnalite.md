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

6. **Contradiction avant CP-1.** Lance l'agent `contradicteur` sur la spec (une passe, ≤ 350 mots), plus au plus deux perspectives si le domaine le demande (`perspective-juridique`, `perspective-finance`, `perspective-marketing`, `perspective-direction` ; `simplificateur` et `perspective-signature` dès qu'un écran est touché). Réponds à chaque objection **dans la spec** (« Contradiction : … — Réponse : … »). Le harnais plafonne le nombre de lancements par session.

7. **Présente la spec à l'utilisateur AVEC les objections et tes réponses — c'est le CP-1.** Le seul jugement humain du cycle reçoit le contre-argument, pas la spec seule. N'avance pas vers le plan sans un accord explicite ("oui", "valide", "go").

8. **Une fois CP-1 validé**, lance l'agent `architecte` pour produire le plan (`features/<nom>/plans/plan-<nom>.md`). Le plan annonce une **taille attendue** (fichiers, lignes) et une section « ce qu'on ne construit pas ». Repasse le plan au `contradicteur` si la taille dépasse ce que la spec justifie, puis présente-le — **CP-2**.

9. **Une fois CP-2 validé**, passe en phase Construire avec les agents `developpeur` et `relecteur`, étape par étape, en mettant à jour `impl-all.md` après chaque étape.

## Rappels

- Ne saute jamais un checkpoint humain, même si la demande semble simple.
- Si l'utilisateur modifie son avis en cours de route, mets à jour la spec (avec son accord) plutôt que de laisser le plan/code diverger silencieusement de la spec.
- Si la fonctionnalité a un volet UI, envisage `/concevoir-ui` en phase Spec (avant CP-1).
- Clôture toujours par `/point-projet` (visibilité à jour).
