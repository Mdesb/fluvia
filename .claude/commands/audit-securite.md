---
name: audit-securite
description: Lance l'agent security-reviewer sur une étape, une fonctionnalité, ou une zone de code donnée.
user_invocable: true
---

# /audit-securite

Lance une revue de sécurité ciblée.

## Ce que tu fais quand cette commande est invoquée

1. **Demande le périmètre** si ce n'est pas évident (une étape de `features/<nom>/impl/`, un fichier précis, ou une zone comme "tout ce qui touche à l'authentification").
2. **Lance l'agent `security-reviewer`** sur ce périmètre.
3. **Présente le verdict** (PASS / PASS WITH NOTES / NEEDS FIXES) et la liste des problèmes par sévérité.
4. Si **NEEDS FIXES**, propose de renvoyer à l'agent `developpeur` avant tout passage au CP-3.

## Quand l'utiliser

Systématiquement quand une étape du cycle Construire touche à l'authentification, aux paiements, aux intégrations externes, aux données personnelles, à l'upload de fichiers, ou à des requêtes SQL — mais aussi à la demande, hors cycle SDD, sur du code existant.
