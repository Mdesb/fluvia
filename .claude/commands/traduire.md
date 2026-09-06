---
name: traduire
description: Lance l'agent traducteur pour générer les traductions (langues cibles) des chaînes source posées à un passage du chantier i18n, ou de tout ajout de champ traduisible. Ne touche jamais le texte source.
user_invocable: true
---

# /traduire

Génère les traductions des langues cibles pour les chaînes source nouvellement posées.

## Ce que tu fais quand cette commande est invoquée

1. **Identifie le périmètre du passage** : quelles clés source viennent d'être ajoutées. Constate-le dans le code, ne le devine pas — les clés présentes en source mais absentes/vides côté traductions sont le travail.
2. **Lance l'agent `traducteur`** sur ce périmètre.
3. **Présente le rapport** : nombre de clés traduites par langue, où les traductions ont été écrites, placeholders `{jeton}` et marques/identifiants laissés intacts, et le résultat de la validation (test de format + import à blanc sur la base de test).
4. **UI interne : traductions finales, pas de relecture requise.** Ne demande pas à l'humain de relire l'UI interne. **Exception : contenu public** → relecture humaine avant publication maintenue. L'import en **production** reste une action humaine/infra, jamais faite par l'agent.

## Ne pas faire

- Ne jamais modifier le texte source : si le source paraît fautif, le signaler, pas le corriger.
- Ne jamais lancer l'import sur la base de production, ni committer/pousser sans demande explicite.
