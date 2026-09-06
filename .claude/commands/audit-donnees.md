---
name: audit-donnees
description: Lance l'agent architecte-donnees pour concevoir/relire un schéma, relire une migration, ou auditer la performance des requêtes (jointures coûteuses, index manquants, N+1, scans complets).
user_invocable: true
---

# /audit-donnees

Fait intervenir le spécialiste base de données — conception **et** audit de performance des requêtes.

## Ce que tu fais quand cette commande est invoquée

1. **Précise la cible** : concevoir/relire un schéma, relire une migration avant de l'appliquer, ou **auditer la performance des requêtes** (tout le projet, ou un écran/endpoint lent).
2. **Lance l'agent `architecte-donnees`** sur cette cible.
3. **Présente sa sortie** : proposition de schéma / relecture de migration (risques + rollback) / rapport de performance priorisé avec preuve (plan d'exécution, requête concernée) et action recommandée. Verdict PASS / PASS WITH NOTES / NEEDS FIXES.

## Quand l'utiliser

- Quand une étape crée ou modifie des tables, ou écrit une migration → **avant** de l'appliquer.
- **Périodiquement**, pour l'audit de perf (la dette de requêtes s'accumule silencieusement), au même titre que `/verifier-coherence`.
- Quand une page ou un endpoint est lent → pour traquer jointure coûteuse, index manquant, requête N+1, scan complet.

## Garde-fou

L'agent ne lance jamais une migration destructive sur une base réelle — l'application passe par une étape de plan explicite et une validation humaine. Pour l'audit injection/isolation multi-tenant approfondi, c'est `/audit-securite`.
