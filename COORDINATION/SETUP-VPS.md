# SETUP-VPS — faire travailler plusieurs Claude sur le même projet

But : plusieurs sessions Claude travaillent **en parallèle** sur le même dépôt, sur le VPS, **sans se
marcher dessus**. Elles ne partagent pas de mémoire → elles se coordonnent **par git + ce dossier**.

## Le modèle en une phrase
Un **dépôt partagé** + **un worktree/branche par Claude** + **propriété des modules** + **un intégrateur**
qui fusionne vers `main` après CI verte et revue de cohérence.

## 1. Mise en place (une seule fois, sur le VPS)
Le dépôt bare existe déjà (`/home/debian/billetterie.git`) et le checkout de déploiement préprod aussi
(`/home/debian/billetterie`, branche `main`). On ajoute **un worktree par Claude** :
```bash
cd /home/debian/billetterie
git worktree add -b claude-A ../wt/claude-A main
git worktree add -b claude-B ../wt/claude-B main
git worktree add -b claude-C ../wt/claude-C main
```
Chaque session Claude ouvre **son** dossier (`/home/debian/wt/claude-A`, …) comme racine projet.
Les worktrees partagent le même stockage git : pas de collision de fichiers, branches indépendantes.

## 2. Identité & périmètre
- Donner à chaque session un identifiant stable : `claude-A`, `claude-B`, `claude-C`.
- Renseigner qui possède quoi dans [OWNERS.md](OWNERS.md). On n'écrit que dans **ses** dossiers.

## 3. Branching
- `main` = intégration + source de vérité (déploie la préprod).
- `claude-X/<sujet>` = branches de travail. On ne pousse jamais directement sur `main`.

## 4. Boucle de travail (chaque Claude, à chaque tâche)
```bash
cd /home/debian/wt/claude-A
git fetch origin && git rebase origin/main     # partir du dernier état
# → CLAIM la tâche dans COORDINATION/TASKS.md (évite les doublons)
# … travailler UNIQUEMENT dans ses modules …
git add <chemins explicites>                    # jamais git add -A
git commit -m "…"
git push origin claude-A
# → demander l'intégration (l'intégrateur fusionne vers main)
```

## 5. Rôle d'intégrateur (un seul Claude)
- Possède `main`, le dossier `CONTRACT/`, et arbitre les conflits transverses.
- Fusionne les branches `claude-X` vers `main` **après** : CI verte + revue de cohérence (impl→revue→correctif).
- Déploie la préprod depuis `main` (`./infra/deploy-preprod.sh`). **Seul l'intégrateur déploie** (évite les déploiements concurrents).

## 6. Règles anti-collision (rappel)
1. **Contract-first** : pas de module avant son manifeste + ses événements dans `CONTRACT/`.
2. **Communication par événements**, jamais d'appel direct module→module.
3. **Staging explicite**, jamais le WIP d'un autre.
4. **Tests isolés** : sur le Docker partagé, chaque Claude utilise un `TEST_TOKEN` distinct
   (`-e TEST_TOKEN=claudeA` create/schema/phpunit/drop) — sinon les bases de test entrent en conflit.
5. **Confiance = CI verte + revue**, pas relecture intégrale du code des autres.

## 7. Garde-fous CI partagés (à mettre en place — tâche C4)
- Cloisonnement (périmètre serveur sur tout endpoint touchant la base).
- Conformité au manifeste (un module déclare ses événements/permissions).
- Nommage anglais (D5) : refuser un identifiant non-anglais dans une nouvelle migration/entité.
C'est le filet qui rend le travail à plusieurs **sûr sans se relire**.

## Alternative simple (si worktrees trop lourds au départ)
Un seul checkout partagé, **répartition stricte par dossiers** (OWNERS), commits séquentiels courts,
push fréquent. Moins parallèle mais démarrage immédiat. On passe aux worktrees dès qu'il y a
contention.
