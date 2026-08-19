# HANDBOOK — méthode commune de la plateforme (À LIRE EN PREMIER)

Document unique de référence pour **toute** session Claude (ou humain) qui travaille sur la
plateforme. Même méthode pour tout le monde. Les détails vivent dans `CONTRACT/` ; ici on a
l'essentiel + **toutes les commandes**.

---

## 0. Les 7 règles d'or
1. **Source de vérité = le dépôt** (bare sur le VPS). On pousse tout, on ne garde rien en local.
2. **Un worktree + une branche par Claude.** On ne pousse jamais direct sur `main`.
3. **Propriété des modules** ([OWNERS.md](OWNERS.md)) : on n'écrit que dans ses dossiers, jamais le WIP d'un autre. **Staging explicite, jamais `git add -A`.**
4. **Contract-first** : pas de module avant son manifeste + ses événements dans `CONTRACT/`. Communication **par événements**, jamais d'appel direct module→module.
5. **Anglais** pour tout le technique (code/DB/API/événements/permissions) ; UI via clés **i18n** (jamais de chaîne en dur). Voir [DECISIONS D5](DECISIONS.md).
6. **Cloisonnement** : périmètre (tenant) toujours dérivé de la session serveur, jamais d'un id client. Échec fermé (403/404).
7. **Confiance = CI verte + revue de cohérence**, pas relecture intégrale du code des autres.

---

## 1. La méthode (SDD)
Chaque module suit le pipeline : **spec → plan → impl → revue de cohérence → correctif**.
- `spec` (agent `sdd-analyste`) : le quoi + règles de gestion.
- `plan` (agent `sdd-architecte`) : entités, endpoints, migrations, sécurité, tests.
- `impl` (agent `sdd-dev-symfony`) : code + tests PHPUnit.
- `revue` (agent `sdd-revue`) : cherche activement les défauts (elle a trouvé 5 vraies failles le 19/08).
- Correctif jusqu'au **vert**, puis intégration.

---

## 2. Comment on collabore (pas de canal live → git async)
- Le **dépôt est le canal** : commit = envoyer, `pull` = recevoir.
- **Tableau de messages** async : [MESSAGES.md](MESSAGES.md) — pour se laisser des demandes/réponses entre Claude.
- **Claim** : réserver une tâche dans [TASKS.md](TASKS.md) **avant** de démarrer (= un verrou).
- **Intégrateur** (un seul Claude) : possède `main` + `CONTRACT/`, fusionne les branches après CI verte + revue, et **déploie seul** la préprod.

---

## 3. Installation VPS (une seule fois)
Dépôt bare + checkout préprod déjà en place. Créer un worktree par Claude :
```bash
cd /home/debian/billetterie
git worktree add -b claude-A ../wt/claude-A main
git worktree add -b claude-B ../wt/claude-B main
git worktree add -b claude-C ../wt/claude-C main
```
Chaque session ouvre **son** dossier (`/home/debian/wt/claude-A`, …) comme racine projet.

---

## 4. Boucle de travail quotidienne (chaque Claude)
```bash
cd /home/debian/wt/claude-A

# 1. partir du dernier état intégré
git fetch origin && git rebase origin/main

# 2. lire les messages + claim la tâche
#    -> éditer COORDINATION/MESSAGES.md (lire) et COORDINATION/TASKS.md (claim)

# 3. travailler UNIQUEMENT dans ses modules (voir OWNERS.md)

# 4. tester en isolation (token distinct par Claude !)
docker compose exec -T -e TEST_TOKEN=claudeA php php bin/console doctrine:database:create --env=test
docker compose exec -T -e TEST_TOKEN=claudeA php php bin/console doctrine:schema:create   --env=test
docker compose exec -T -e TEST_TOKEN=claudeA php vendor/bin/phpunit tests/<Module>
docker compose exec -T -e TEST_TOKEN=claudeA php php bin/console doctrine:database:drop --force --env=test

# 5. committer (staging explicite) et pousser SA branche
git add app/src/<Module> app/tests/<Module>
git commit -m "…"
git push origin claude-A

# 6. demander l'intégration : laisser un message dans MESSAGES.md pour l'intégrateur
```

---

## 5. Intégration & déploiement (intégrateur uniquement)
```bash
cd /home/debian/billetterie            # worktree main
git fetch origin
git merge --no-ff origin/claude-A      # ou rebase ; résoudre les conflits ici
# CI + revue de cohérence OK ?
git push origin main
./infra/deploy-preprod.sh              # SEUL l'intégrateur déploie (pas de déploiement concurrent)
```

---

## 6. Gestion des conflits
- **Le meilleur anti-conflit = la propriété disjointe** : deux Claude sur des dossiers différents ne
  peuvent pas entrer en conflit. Répartir les modules dans OWNERS.md pour que ça n'arrive presque jamais.
- **Fichiers réellement partagés** (peu) : `config/packages/api_platform.yaml`, numérotation des
  migrations, `CONTRACT/`. Règle : **l'intégrateur les possède**, ou patch chirurgical
  (`git apply --cached --recount <patch>`) après claim explicite dans TASKS.md.
- **Migrations** : nom horodaté unique (`Version20260819HHMMSS.php`) → pas de collision de numéro ;
  l'intégrateur rebase si besoin.
- **Sérialisation** : les branches fusionnent **une par une** via l'intégrateur → les conflits
  éventuels sortent au merge et se résolvent par rebase, jamais en cachette.

---

## 7. Garde-fous CI partagés (le filet de confiance)
À faire tourner en CI sur chaque push (tâche C4) :
- **Cloisonnement** : refuser tout endpoint touchant la base sans contrôle de périmètre (marqueur
  d'exemption explicite pour les rares cas légitimes).
- **Manifeste** : un module déclare ses événements/permissions/features.
- **Nommage anglais (D5)** : refuser un identifiant non-anglais dans une nouvelle migration/entité.
- **CSRF / sécurité** de base.
C'est ce qui permet de fusionner le travail des autres **sans tout relire**.

---

## 8. Checklists
**Avant de pousser sa branche :** tests du module verts (token isolé) · staging explicite ·
identifiants anglais · libellés en clés i18n · événements déclarés au manifeste · TASKS.md à jour.

**Avant de merger vers main (intégrateur) :** CI verte · revue de cohérence passée · pas de
régression sur les modules voisins · migration horodatée · MESSAGES.md répondu.

---

## Fichiers de coordination
[HANDBOOK.md](HANDBOOK.md) (ce doc) · [README.md](README.md) · [OWNERS.md](OWNERS.md) ·
[TASKS.md](TASKS.md) · [MESSAGES.md](MESSAGES.md) · [DECISIONS.md](DECISIONS.md) ·
[SETUP-VPS.md](SETUP-VPS.md) · [CONTRACT/](CONTRACT/)
