---
name: verifier-coherence
description: Lance l'agent coherence-reviewer sur tout ou partie de la documentation/du code du projet, pour détecter les contradictions et dérives.
user_invocable: true
---

# /verifier-coherence

Vérifie la cohérence globale du projet — entre documents, et entre documents et code réel.

## Ce que tu fais quand cette commande est invoquée

1. **Détermine le périmètre** : tout le projet actif par défaut (décisions d'architecture, specs, `features/`, `CLAUDE.md`, base de connaissances) — les archives legacy sont hors périmètre par défaut (pas la réalité actuelle), sauf demande explicite. Ou un sous-ensemble si précisé (ex. "juste la stack technique").
2. **Lance l'agent `coherence-reviewer`** sur ce périmètre.
3. **Présente le rapport** : incohérences trouvées, documents concernés, sévérité, et le verdict global (COHÉRENT / COHÉRENT AVEC RÉSERVES / INCOHÉRENCES À TRAITER).
4. **Ne corrige rien automatiquement** — présente chaque incohérence à l'utilisateur et attend une décision avant de modifier quoi que ce soit.

## Quand l'utiliser

Avant une réunion d'équipe importante, après une réorganisation importante de la documentation, ou périodiquement — pas systématiquement à chaque commit.
