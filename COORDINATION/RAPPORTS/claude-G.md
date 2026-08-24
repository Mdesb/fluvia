# Rapports de `claude-G`

> **Écrit par `claude-G` seul.** claude-A le lit, ne l'écrit jamais.
> Une ligne par battement, la plus récente **en bas**.

| Heure | Fait | En cours | Bloqué par |
|---|---|---|---|
| 12:21 | Arrivée. Lu FLOTTE, PLAYBOOK, DECISIONS (D2/D3/D5/D8/D13/D16/D19/D23/D24/D26/D27), ORDRES (vide), TASKS. Worktree opérationnel, `vendor` installé. | Je prends **CQ-7** — validité après recharge configurable (D26), `app/src/Offre/**`. Choisi en premier parce que c'est le seul de mes quatre lots qui ne touche **aucun** fichier de `Reservation` : zéro recouvrement avec les lots en vol de claude-B, donc pas besoin d'attendre le séquencement. | Rien |

## Notes pour `claude-A` — topologie des six nouveaux worktrees

Deux constats vérifiés sur `claude-G` (valables pour **D, E, F, G, H, I** — pas pour A/B/C) :

1. **Aucun remote `origin`.** Les six worktrees sont attachés au dépôt **nu** (`git rev-parse
   --git-common-dir` → `/home/debian/billetterie.git`), qui n'a pas de remote vers lui-même.
   Le battement documenté (`git fetch origin && git merge --no-edit origin/main`, puis
   `git push origin claude-X`) **échoue tel quel** : `fatal: 'origin' does not appear to be a git
   repository`. Correctif d'une ligne dans `infra/creer-flotte.sh` :
   `git -C "$CHEMIN" remote add origin "$BARE"` — mais la config est partagée par tous les worktrees
   du bare, donc c'est à toi de trancher plutôt qu'à moi de la modifier.
   En attendant je bats avec `git merge --no-edit main` : `main` est une branche **locale** du même
   dépôt, la fusion amont fonctionne sans réseau.

2. **Un commit ici met à jour `refs/heads/claude-G` du bare directement — il n'y a rien à pousser,
   et `pre-receive` ne voit donc jamais rien.** C'est exactement la topologie qui a motivé le
   `pre-commit` de C16 bis, et ce hook est bien installé dans `$BARE/hooks/` : mes commits sont
   contrôlés à la validation. Deux conséquences quand même :
   - `pre-commit` se contourne par `--no-verify` (le hook le dit lui-même) — pour six sessions sur
     neuf, la barrière devient un garde-fou de confort ;
   - le filet de complétude et l'avertissement D28 de `pre-receive` ne s'exécutent plus pour elles.
   Rien à faire de mon côté : je te le signale, tu arbitres.
