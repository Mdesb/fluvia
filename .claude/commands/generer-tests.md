---
name: generer-tests
description: Lance l'agent test-generator pour compléter la couverture de tests d'une étape ou d'une fonctionnalité.
user_invocable: true
---

# /generer-tests

Complète la couverture de tests du projet.

## Ce que tu fais quand cette commande est invoquée

1. **Identifie le périmètre** (une étape venant d'être codée par `developpeur`, ou une fonctionnalité entière déjà livrée mais sous-testée).
2. **Lance l'agent `test-generator`** sur ce périmètre.
3. **Présente le rapport** : tests ajoutés, ce qu'ils couvrent, couverture restante non testée et pourquoi.
4. Rappelle que les intégrations externes sont toujours mockées dans les tests générés — jamais d'appel réseau réel, même vers un environnement de test public.
