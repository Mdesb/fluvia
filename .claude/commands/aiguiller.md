---
name: aiguiller
description: Point d'entrée de toute demande — lance l'agent aiguilleur pour décider voie légère (petit changement) ou cycle SDD complet (vraie fonctionnalité), puis route vers la bonne commande.
user_invocable: true
---

# /aiguiller

La **porte d'entrée** du travail. À lancer sur toute demande de dev ou de correctif avant de commencer quoi que ce soit — c'est ce qui empêche la méthode d'être contournée.

## Ce que tu fais quand cette commande est invoquée

1. **Reformule la demande** en une phrase pour t'assurer de l'avoir comprise.
2. **Lance l'agent `aiguilleur`** dessus.
3. **Présente sa décision** : verdict (VOIE LÉGÈRE / SDD COMPLET), justification condition par condition, découpage éventuel si la demande est mixte.
4. **Route** : une fois l'humain d'accord avec l'aiguillage, enchaîne sur `/correctif-rapide` (voie légère) ou `/nouvelle-fonctionnalite` (cycle complet).

## Règle d'or

En cas de doute, c'est **SDD complet**. La voie légère est une exception justifiée, jamais un raccourci par défaut. Toute zone sensible (base de données, auth, paiements, données personnelles, intégration externe) → SDD complet obligatoire.
