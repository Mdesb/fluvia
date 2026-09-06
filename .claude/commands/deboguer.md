---
name: deboguer
description: Lance l'agent debogueur pour reproduire un bug, isoler sa cause racine, et proposer le correctif minimal + un test de non-régression.
user_invocable: true
---

# /deboguer

Diagnostique un bug — hors cycle SDD (le cycle sert à construire, pas à réparer).

## Ce que tu fais quand cette commande est invoquée

1. **Recueille le symptôme** : comportement observé vs attendu, entrées/étapes de reproduction, environnement, depuis quand (un commit récent ?).
2. **Lance l'agent `debogueur`** sur ce symptôme.
3. **Présente sa sortie** : symptôme reproduit (ou raison si non reproductible), cause racine VERIFIED avec preuve (fichier/ligne), correctif minimal proposé, test de non-régression, effets de bord.
4. **N'applique pas le correctif automatiquement** — présente-le, et laisse l'humain décider : correction directe (urgence) ou passage par une étape SDD (developpeur + revue).

## Quand l'utiliser

Dès que « ça plante » ou qu'un comportement est incorrect. Pour construire une fonctionnalité neuve, c'est `/nouvelle-fonctionnalite`, pas cette commande.
