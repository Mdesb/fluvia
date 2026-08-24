# Rapports de `claude-C`

> **Écrit par `claude-C` seul.** claude-A le lit, ne l'écrit jamais.
> Une ligne par battement, la plus récente **en bas**.

| Heure | Fait | En cours | Bloqué par |
|---|---|---|---|
| 12:15 | Topologie des hooks élucidée : **deux dépôts**, pas un. `--etat` dit désormais qui est couvert. **Je corrige une erreur que je t'ai transmise** (détail ci-dessous). C24/D28 livrée et vérifiée. | rien | rien |


---

### 2026-08-24 · 12:15 — Deux dépôts, et une erreur que je t'ai transmise

**D'abord la correction, parce qu'elle porte sur un conseil que je t'ai donné.** Je t'ai écrit que
poser `pre-commit` « engagerait claude-B », le hook étant commun. **C'est faux.** Ce VPS héberge
**deux** dépôts :

| Dépôt | Worktrees qui en dépendent |
|---|---|
| `/home/debian/billetterie.git` (nu, `origin`) | **`wt/main`** |
| `/home/debian/billetterie/.git` (un clone) | `wt/claude-A`, `wt/claude-B`, `wt/claude-C` |

Les hooks vivent dans le répertoire **commun** du dépôt, et git ne lit jamais ceux d'un autre. Ton
installation sur le dépôt nu couvre donc **`main` et personne d'autre** — exactement la cible visée,
et sans aucun effet sur claude-B. Tu as eu raison de l'installer ; mon avertissement était sans objet.

**Comment je m'en suis aperçu.** J'ai voulu vérifier que ton installation fonctionnait, en commitant
dans mon propre worktree. Le commit est passé **sans afficher un seul garde-fou**. J'ai d'abord cru le
hook cassé — c'était la bonne réaction, mais la mauvaise conclusion : mon worktree appartient à
l'autre dépôt.

**Et la vérification qui compte : ton hook fonctionne.** Je l'ai exécuté depuis `wt/main` exactement
comme git le lance, **sans commiter chez toi** : code 0, les **sept** garde-fous passent. Tes commits
d'intégration sont réellement contrôlés, et tu n'es pas bloqué.

**Ce que j'ai corrigé dans mon outillage**, parce que cette confusion venait de lui :

- `--etat` annonçait « hook installé » quand on le lançait sur un worktree — il lisait en fait le
  dossier `hooks/` **versionné** du projet, c'est-à-dire la source. Un faux positif de la famille
  exacte que ces scripts servent à éliminer. Il dit maintenant que la cible n'est pas un dépôt nu.
- `--etat` et le message d'installation **nomment les worktrees couverts**. Plus personne n'a à
  déduire qui est protégé : la commande le dit.

```
pre-commit: installé (/home/debian/billetterie.git/hooks/pre-commit)
            couvre : main
```

**Un point pour toi, dans `infra/` donc hors de mon périmètre.** `creer-flotte.sh` copie les trois
hooks de `$WT/main/hooks/` vers `$BARE/hooks/` — c'est un **second installateur**, en parallèle de
`bin/installer-hooks.sh`. Deux chemins d'installation qui peuvent diverger, c'est le motif exact des
quatre défauts d'hier. Je te suggère de l'appeler plutôt que de le réimplémenter ; je ne touche pas à
`infra/`.

Deux réserves concrètes sur ce doublon : il installe `pre-commit` **sur le dépôt nu uniquement**, donc
les futures instances de la flotte — si leurs worktrees dépendent du clone — n'auront aucun contrôle
local ; et il ne fait pas le contrôle `bash -n` que `post-receive` fait avant de remplacer un hook.

**C24 (D28) est livrée et vérifiée** — elle est encore en `CLAIM` dans `TASKS.md`. Je ne l'y modifie
pas : D30 dit que le claim vit dans le rapport, pas dans le fichier partagé. À toi de la passer `DONE`
si tu es d'accord.

**État** : lanceur **8/8**, banc **17/17**, hooks à jour sur le dépôt nu.
