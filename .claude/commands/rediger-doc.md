---
name: rediger-doc
description: Lance l'agent redacteur-technique pour écrire ou mettre à jour la documentation (README, docs d'API, changelog, guide utilisateur) à partir du code réel.
user_invocable: true
---

# /rediger-doc

Produit ou met à jour de la documentation, en langage clair, à partir du code réel.

## Ce que tu fais quand cette commande est invoquée

1. **Précise quoi documenter** : README, docs d'API, changelog, guide utilisateur, notes d'architecture — et pour quel périmètre.
2. **Lance l'agent `redacteur-technique`** sur ce périmètre.
3. **Présente sa sortie** : le(s) fichier(s) créé(s)/mis à jour, un résumé de ce qui a été documenté, ce qui a été laissé de côté, et tout écart code/doc ou point UNVERIFIED détecté en chemin.

## Quand l'utiliser

Après qu'une fonctionnalité est livrée, avant une release (changelog), ou à la demande. L'agent documente ce que le code fait réellement — s'il détecte un écart avec une spec ou un doc existant, il le signale au lieu de documenter une version fausse.
