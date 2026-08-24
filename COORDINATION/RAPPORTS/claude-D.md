# Rapports de `claude-D`

> **Écrit par `claude-D` seul.** claude-A le lit, ne l'écrit jamais.
> Une ligne par battement, la plus récente **en bas**.

| Heure | Fait | En cours | Bloqué par |
|---|---|---|---|
| 12:22 | Situé : FLOTTE, PLAYBOOK, DECISIONS, ORDRES et `specs/editeur/spec-editeur.md` lus. Constat : ED-1 et ED-2 sont livrés dans `app/src/Subscription/`, pas dans `app/src/Editeur/` — qui n existe sur aucune branche. La spec tranche le nommage (§Nommage : le module s appelle `App\Subscription`) ; la colonne « Chemins » de ED-1/ED-3 dans TASKS.md est donc périmée, pas le code. | Je prends **ED-3** — tunnel de souscription SEPA + provisioning idempotent. Je commence par `ProvisioningRequest` et le service de provisioning, qui ne dépendent pas de B-2. | B-1, B-2 — détail ci-dessous |
| 12:31 | **B-1 corrigé côté claude-A** : mon worktree est revenu sur le clone, à mon commit, avec un `origin` — les poussées repassent par `pre-receive`. Mon travail était intact. Reste ouvert : l identité de commit est toujours partagée (`git config user.name` = claude-I dans `/home/debian/billetterie/.git/config`) ; je commite avec `git -c user.name=claude-D`. Les cinq autres sessions auront le même défaut. | **ED-3, premier lot** : `ProvisioningRequest` (idempotence par contrainte d unicité sur `subscription_id`), `ProvisioningStatus`, `ProvisioningOutcome`, `ProvisioningService`. Les quatre passent `php -l`. Ensuite : les tests de CA-1 et CA-2. | B-2 (catalogue) pour l émission ; **B-3, nouveau** — voir ci-dessous |

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

**B-1 — état au 24/08 12:31 : partiellement levé.** Les worktrees D→I sont passés sur le clone et
disposent d un `origin` : le battement prescrit fonctionne, et `pre-receive` s exécute de nouveau.
**Reste ouvert le point 3** — `git config user.name` vaut toujours `claude-I` pour tout le monde,
désormais dans `/home/debian/billetterie/.git/config`. Le correctif tient en une ligne
(`git config extensions.worktreeConfig true`, puis `git config --worktree user.name claude-X` dans
chaque worktree) ; il appartient à `claude-A`. En attendant je commite avec `git -c user.name=claude-D`.

**B-3 — le rôle modèle « Administrateur etablissement » n existe pas.** `ProvisioningService` duplique
un `Role` marqué `estModele = true` pour habiliter l administrateur du client, exactement comme
`DuplicationRoleProcessor`. Ce modèle n existe dans aucune fixture : à ce jour, CA-1 échouerait avec
le message prévu (« Rôle modèle absent »).

**C est délibéré, et je ne le contourne pas.** Composer ici la liste des permissions d un
administrateur de client reviendrait à écrire une seconde politique d habilitation à côté de celle
de `Securite`, qui divergerait dès la première évolution — et à décider seul, dans un module de
facturation, de ce qu un client a le droit de faire. Le rôle modèle relève de `Securite`, donc de
`claude-A`. **Ce qu il me faut :** un `Role` `estModele = true` nommé `Administrateur etablissement`,
portant le bundle de permissions d un administrateur d établissement. Je m aligne sur le nom qu il
retiendra ; seule la constante `ProvisioningService::ADMIN_ROLE_TEMPLATE` est à changer.
