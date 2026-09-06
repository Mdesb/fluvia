---
name: point-projet
description: Étape de clôture de tout dev — met à jour la visibilité du projet (TASKS + journal + tableau de bord) pour que le responsable produit voie où on en est d'un coup d'œil, sans lire le code.
user_invocable: true
---

# /point-projet

La **clôture obligatoire de chaque dev** (voie légère comme cycle complet). Son but : donner au responsable produit une **visibilité claire et à jour** sur l'état du projet, sans qu'il ait à lire une ligne de code. C'est d'autant plus important qu'ici personne ne code au quotidien et que les livraisons sont approuvées en confiance : ce point est ta fenêtre sur le projet.

## Ce que tu fais quand cette commande est invoquée

1. **Mets à jour `TASKS.md`** : coche ce qui a été livré, ajoute ce qui a émergé, garde ce qui reste ouvert (avec les points UNVERIFIED bloquants signalés).
2. **Ajoute une entrée dans `JOURNAL_DECISIONS.md`** si une décision a été prise pendant le dev (date, titre court, décision, pourquoi).
3. **Régénère le tableau de bord `docs/etat-projet.md`** (voir ci-dessous) — la vue d'ensemble lisible en 30 secondes.
4. **Présente un résumé dans le chat** : ce qui a été fait, ce qui reste, ce qui attend une décision de ta part.

## Le tableau de bord `docs/etat-projet.md`

C'est une **vue**, pas une source de vérité : elle est **régénérée** à partir de `TASKS.md`, `JOURNAL_DECISIONS.md` et `features/`. Ne t'en sers jamais comme d'un endroit où stocker une info qu'on ne trouverait nulle part ailleurs. Contenu recommandé, en langage clair :

- **Où on en est** — 2-3 phrases : le chantier en cours, son avancement.
- **Dernières livraisons** — les N derniers devs terminés (date + une phrase d'impact utilisateur).
- **Reste à faire** — par module/feature, ce qui est ouvert et ce qui est prioritaire.
- **En attente de toi** — les décisions ou validations qui bloquent la suite (le plus important pour un responsable produit).
- **Santé** — état de la CI, dernier audit de cohérence et de performance (date + verdict).

<!-- Option : générer aussi une version HTML ouvrable d'un clic (tableau de bord visuel). À décider selon le projet. -->

## Pourquoi c'est une étape obligatoire, pas optionnelle

Sans clôture systématique, le tableau de bord se périme dès le premier dev non journalisé et perd toute valeur — exactement le piège des audits « quand on y pense ». Ici, chaque dev se termine par ce point : c'est ce qui garde la visibilité fiable.
