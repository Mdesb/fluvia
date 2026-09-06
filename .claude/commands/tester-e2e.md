---
name: tester-e2e
description: Lance l'agent testeur-e2e pour écrire/maintenir les tests de bout en bout (parcours utilisateur complets pilotés dans un vrai navigateur) sur les parcours critiques d'une fonctionnalité.
user_invocable: true
---

# /tester-e2e

Couvre un parcours utilisateur complet par un test de bout en bout (navigateur réel).

## Ce que tu fais quand cette commande est invoquée

1. **Identifie le parcours critique à couvrir** (ex. « connexion → créer un client → le voir dans la liste »). Si ce n'est pas explicite, demande lequel.
2. **Lance l'agent `testeur-e2e`** sur ce parcours.
3. **Présente sa sortie** : le(s) test(s) écrit(s), les parcours désormais couverts, ceux qui restent non couverts, et le résultat d'exécution sur l'environnement de test.
4. Signale toute dette côté UI repérée en chemin (élément sans libellé ciblable, sélecteur fragile).

## Quand l'utiliser

Sur les **parcours critiques** d'une feature avec UI, avant le CP-3 — en complément de `/generer-tests` (unitaire/intégration). Réserve-le aux chemins dont l'échec coûte cher (auth, flux cœur, argent/irréversible) ; les cas limites restent du ressort de `test-generator`.

## Garde-fous

Jamais contre la production : environnement de test dédié, données synthétiques, comptes de test, intégrations tierces en bac à sable ou mockées. Un test rouge signale un vrai bug (→ `/deboguer`) ou un test à corriger — jamais une assertion à affaiblir.
