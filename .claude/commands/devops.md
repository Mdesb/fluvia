---
name: devops
description: Lance l'agent devops pour mettre en place ou maintenir l'outillage CI/CD, les conteneurs, la configuration d'environnement et les scripts de déploiement — avec revue humaine avant tout changement de production.
user_invocable: true
---

# /devops

Travaille l'outillage d'intégration et de déploiement du projet.

## Ce que tu fais quand cette commande est invoquée

1. **Précise le besoin** : mettre en place la CI, ajouter une étape (tests, scan de secrets, audit deps), écrire un Dockerfile, préparer un déploiement, corriger un pipeline rouge…
2. **Lance l'agent `devops`** sur ce besoin.
3. **Présente sa sortie** : le fichier de config proposé (dans une branche) + explication en langage clair, prérequis (variables à définir, droits à accorder), et plan de rollback si c'est un déploiement.
4. **Signale ce qui exige une action humaine** : définir un secret, accorder un accès, valider une mise en production. L'agent ne déclenche jamais un déploiement réel de lui-même.

## Garde-fous

Revue humaine obligatoire avant tout changement touchant la production. Jamais de secret en dur, jamais de commande destructive sur une ressource réelle sans confirmation explicite.
