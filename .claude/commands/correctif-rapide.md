---
name: correctif-rapide
description: La voie légère — process allégé pour un petit changement déjà aiguillé (bug localisé, libellé, ajustement, doc, refactor mineur). Garde les non-négociables (tests verts, revue, feu vert humain) sans le poids du cycle SDD complet.
user_invocable: true
---

# /correctif-rapide

Le process **allégé** pour un petit changement. À n'emprunter qu'après un aiguillage `VOIE LÉGÈRE` (voir `/aiguiller`) — si tu n'es pas sûr de l'éligibilité, passe d'abord par `/aiguiller`.

## Ce que tu fais quand cette commande est invoquée

1. **Vérifie l'éligibilité** (rappel express du test de l'aiguilleur) : petit, localisé, bien compris, aucune zone sensible (base/auth/paiements/données perso/intégration externe), pas une capacité neuve, réversible. **Si un seul point coince → stop, bascule en `/nouvelle-fonctionnalite`.**
2. **Note l'intention en une ligne** dans `TASKS.md` — pas de spec ni de plan formels (c'est tout l'intérêt de la voie légère).
3. **Fais le changement**, chirurgicalement (esprit `developpeur` : ne touche que ce qui est nécessaire), sur une branche `fix/<nom>`.
4. **Si c'est un bug** : ajoute un **test de non-régression** (échoue avant, passe après — discipline `debogueur`). Pour un simple libellé/doc, ce n'est pas requis.
5. **Valide** : lint + tests restent **verts**. CI verte.
6. **Revue ciblée** : lance `relecteur` (qualité). Ajoute `security-reviewer` **uniquement** si le changement frôle une limite sensible.
7. **Un feu vert humain avant merge**, puis merge sur une branche `fix/<nom>` (jamais de push direct sur `main`).
8. **Clôture obligatoire** : lance `/point-projet` — mise à jour de `TASKS.md`, du journal si décision, et du tableau de bord. Aucun dev ne se termine sans ce point.

## La soupape d'échappement (essentielle)

Si en cours de route le changement **grossit** — plus de fichiers que prévu, une zone sensible apparaît, une décision de design devient nécessaire — **arrête-toi et rebascule en cycle SDD complet** (`/nouvelle-fonctionnalite`). Un « petit correctif » ne doit jamais enfler en douce : c'est exactement ce que la voie légère ne doit pas permettre.

## Ce qui reste non-négociable même ici

Pas de secret en dur · requêtes SQL paramétrées · tests verts · validation humaine avant merge · jamais de migration en production improvisée. La voie légère allège le **process**, pas les **garde-fous de sécurité**.
