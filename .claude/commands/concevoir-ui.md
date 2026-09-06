---
name: concevoir-ui
description: Lance l'agent concepteur-ui pour concevoir l'UI en amont — langage visuel (tokens) + parcours/wireframes — à valider en CP-1.
user_invocable: true
---

# /concevoir-ui

Conçoit l'interface **avant le code** : direction visuelle (design system) et parcours d'écrans. Produit de la matière à valider en CP-1, pas du code.

## Ce que tu fais quand cette commande est invoquée

1. **Identifie l'écran/parcours à concevoir** et la surface (web, mobile…). Demande l'ancre visuelle (marque/référence) si elle n'est pas connue.
2. **Lance l'agent `concepteur-ui`**.
3. **Présente sa sortie** : direction(s) visuelle(s) + tokens ; flux clic-par-clic (avec erreurs et états vides) ; wireframes ; options ouvertes avec recommandation ; points UNVERIFIED ; ancrage design system.
4. **Attends CP-1** avant toute écriture en dur (les tokens/wireframes ne sont figés qu'après validation, via `developpeur`).

## Quand l'utiliser

Au démarrage d'un produit, lors d'une refonte, ou dès qu'un écran est introduit/refondu — avant le plan et le code. Complément amont de `/auditer-ui` (`concepteur-ui` conçoit avant, `relecteur-ui` audite après).
