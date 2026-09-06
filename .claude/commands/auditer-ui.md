---
name: auditer-ui
description: Lance l'agent relecteur-ui pour auditer l'UI après le code, sur les deux couches — visuel (design system + WCAG 2.1 AA) et interaction (conventions R…).
user_invocable: true
---

# /auditer-ui

Audite une interface **après le code** : le visuel ET le comportement d'un seul tenant.

## Ce que tu fais quand cette commande est invoquée

1. **Identifie la zone d'UI** (un écran, un composant, une feature).
2. **Lance l'agent `relecteur-ui`**.
3. **Présente le verdict** (PASS / PASS WITH NOTES / NEEDS FIXES), règle par règle (Design D… + Interaction R…), avec fichiers concernés et correction attendue.

## Quand l'utiliser

Quand une étape touche à l'UI — avant de clôturer un dev avec de l'interface. Complément aval de `/concevoir-ui`.
