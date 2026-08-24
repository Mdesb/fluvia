# Rapports de `claude-D`

> **Écrit par `claude-D` seul.** claude-A le lit, ne l'écrit jamais.
> Une ligne par battement, la plus récente **en bas**.

| Heure | Fait | En cours | Bloqué par |
|---|---|---|---|
| 12:22 | Situé : FLOTTE, PLAYBOOK, DECISIONS, ORDRES et `specs/editeur/spec-editeur.md` lus. Constat : ED-1 et ED-2 sont livrés dans `app/src/Subscription/`, pas dans `app/src/Editeur/` — qui n existe sur aucune branche. La spec tranche le nommage (§Nommage : le module s appelle `App\Subscription`) ; la colonne « Chemins » de ED-1/ED-3 dans TASKS.md est donc périmée, pas le code. | Je prends **ED-3** — tunnel de souscription SEPA + provisioning idempotent. Je commence par `ProvisioningRequest` et le service de provisioning, qui ne dépendent pas de B-2. | B-1, B-2 — détail ci-dessous |

---

## Blocages ouverts

**B-1 — La flotte D→I a été créée sur le dépôt nu, pas sur le clone.** `creer-flotte.sh` a posé les
worktrees D→I dans `/home/debian/billetterie.git` alors que A/B/C vivent dans `/home/debian/billetterie`.
Trois effets vérifiés :

1. **Pas de `origin`** dans D→I : `git fetch origin && git merge origin/main` et `git push origin claude-D`
   — le battement prescrit par FLOTTE.md — ne peuvent pas s exécuter tels quels.
2. **`pre-receive` ne s exécute jamais** pour ces six sessions : un worktree du dépôt nu écrit sa branche
   sans push. `pre-commit` s exécute bien ; ce sont les garde-fous de poussée qui sont contournés — soit
   précisément ce que la règle 5 protège.
3. **Identité de commit partagée** : `creer-flotte.sh:70` fait `git config user.name` sans `--worktree`.
   Les worktrees partagent `config`, chaque tour de boucle écrase le précédent, et les six sessions
   commitent aujourd hui sous `claude-I`. Je contourne par `git -c user.name=claude-D` à chaque commit,
   ce qui ne touche pas la configuration partagée.

Maxime a tranché : le correctif appartient à `claude-A`, je ne sors pas de mon périmètre. **Les six
branches avaient `0` commit au-delà de `main` au moment du constat** — c est la fenêtre où recréer les
worktrees sur le clone ne coûte rien. Elle se referme à chaque commit.

**B-2 — Cinq événements manquent au catalogue.** RG-ED-04 impose que le tunnel émette
`subscription.activated` et que le provisioning y soit abonné. Or `catalogue-evenements.md` ne connaît
que `subscription.created` et `subscription.suspended`. Manquent : `subscription.activated`,
`subscription.cancelled`, `subscription_option.added`, `subscription_option.removed`,
`establishment.provisioned` (liste déjà dressée par la spec, §8). `COORDINATION/CONTRACT/**` est le
périmètre de `claude-A` : je ne les ajoute pas. Je construis tout ce qui n en dépend pas et je câble
l émission en dernier.

**Point annexe, sans blocage.** `app/config/reference.php` ressort modifié dans chaque worktree
(`composer install` le regénère, `messenger.enabled` false→true). Ce n est pas du travail : je ne le
commite pas. Et le brief du document de lancement prescrit `git add -A` là où PLAYBOOK §7.2 impose un
staging explicite ; je suis le PLAYBOOK.
