# ONBOARDING — tu es un Claude qui rejoint la plateforme

Bienvenue. Tout le projet est dans ce dépôt (source de vérité = le VPS). Tu te mets à jour en 5 minutes.

## En arrivant, dans l'ordre
1. **Lis [PLAYBOOK.md](PLAYBOOK.md)** — le document unique (vision, décisions, architecture, contrat, méthode, commandes). C'est tout ce dont tu as besoin pour comprendre le projet.
2. **Prends une identité + un périmètre** dans [OWNERS.md](OWNERS.md) (`claude-A/B/C` + les modules que tu possèdes). N'écris QUE dans tes dossiers.
3. **Entre dans ton worktree** (voir commandes ci-dessous) — un dossier/branche à toi, isolé des autres.
4. **Dis bonjour** dans [MESSAGES.md](MESSAGES.md) (commit = envoyer). Lis les messages en attente.
5. **Claim ta tâche** dans [TASKS.md](TASKS.md) AVANT de coder (= un verrou anti-collision).

## Les 5 règles à ne jamais oublier
- **Anglais** pour tout le technique (code/DB/API/événements/permissions) ; UI via clés i18n.
- **Cloisonnement** : périmètre dérivé de la session serveur, jamais d'un id client. Échec fermé.
- **Contract-first** : pas de module avant son manifeste + événements dans `CONTRACT/`.
- **Staging explicite**, jamais `git add -A` ; jamais le WIP d'un autre.
- **Tests isolés** : un `TEST_TOKEN` distinct par Claude.

## Premières commandes
```bash
# créer/entrer ton worktree (remplace claude-A par ton id)
cd /home/debian/billetterie
git worktree add -b claude-A ../wt/claude-A main   # si pas déjà fait
cd ../wt/claude-A
git fetch origin && git rebase origin/main

# vérifier que tu es à jour
cat COORDINATION/PLAYBOOK.md | head -40
```

## Rôle d'intégrateur
Un seul Claude tient `main` + `CONTRACT/`, fusionne les branches après CI verte + revue, et déploie
seul la préprod. Si personne ne l'a pris, propose-toi dans MESSAGES.md.

→ Tout le détail (commandes complètes, conflits, checklists) est dans [PLAYBOOK.md](PLAYBOOK.md).
