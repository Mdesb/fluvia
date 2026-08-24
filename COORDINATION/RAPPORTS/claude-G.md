# Rapports de `claude-G`

> **Écrit par `claude-G` seul.** claude-A le lit, ne l'écrit jamais.
> Une ligne par battement, la plus récente **en bas**.

| Heure | Fait | En cours | Bloqué par |
|---|---|---|---|
| 12:21 | Arrivée. Lu FLOTTE, PLAYBOOK, DECISIONS (D2/D3/D5/D8/D13/D16/D19/D23/D24/D26/D27), ORDRES (vide), TASKS. Worktree opérationnel, `vendor` installé. | Je prends **CQ-7** — validité après recharge configurable (D26), `app/src/Offre/**`. Choisi en premier parce que c'est le seul de mes quatre lots qui ne touche **aucun** fichier de `Reservation` : zéro recouvrement avec les lots en vol de claude-B, donc pas besoin d'attendre le séquencement. | Rien |
| 12:45 | **CQ-7 livré côté offre** : `RechargeValidityMode` (extend/keep), colonne `recharge_validity_mode` sur `off_carte_multi_entrees` (défaut `extend`, D26), migration `Version20260824123000`, prédicat `keepsValidityOnRecharge()`, 3 tests unitaires + 3 tests d'API. `tests/Offre` 28/28 vert, `tests/Platform` 58/58 vert. | Vérification de voisinage `tests/Acces` en cours (j'ai touché une entité que ce module consomme). Ensuite ACT-1. | Rien pour moi — mais CQ-7 n'est **pas fini** tant que le branchement `Acces` ci-dessous n'est pas fait, et il n'est pas de mon périmètre. |

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


## CQ-7 — le branchement qui reste, et il est dans `Acces` (pour `claude-A`)

Ce que j'ai livré, c'est le **paramètre** : l'exploitant peut choisir, l'API l'accepte, la base le
garde. Ce que je n'ai pas le droit de livrer, c'est sa **prise en compte** : elle se fait dans
`app/src/Acces/Service/CardRechargeHandler.php`, périmètre de `claude-B`. Tant qu'elle n'est pas
faite, `keep` est un bouton qui ne fait rien — exactement la famille de promesse creuse que D23
reproche à `propositionRecharge`. À toi de séquencer ; le lot est d'une ligne.

**Le patch, à l'endroit exact** (`CardRechargeHandler::recharge()`, calcul de `$nouvelleEcheance`) :

```php
$carte = $this->carteVendue($support);
$nouvelleEcheance = $carte !== null && !$carte->keepsValidityOnRecharge()
    ? $this->cardExpiry->calculer($carte, $droit->getFenetreFin(), new \DateTimeImmutable())
    : $droit->getFenetreFin();
```

**Le piège, à dire avant que quelqu'un ne le contourne :** ne pas mettre ce test à l'intérieur de
`CardExpiryCalculator::calculer()`, alors que son docblock invite pourtant à y voir le point
d'extension CQ-7. Ce service a **deux appelants** — la recharge, et `StubProjectionDroit` à
l'émission initiale du droit (T6). À l'émission, `$fenetreFinActuelle` vaut `null` : une branche
`keep` posée là rendrait toute carte `keep` **illimitée dès sa vente**, ce qui est le contraire de
ce que le paramètre demande. `keep` ne veut rien dire tant qu'il n'y a pas d'échéance à conserver.
C'est pour ça que le prédicat vit sur l'entité et que le test est au **site d'appel de la recharge**,
pas dans le calcul.

**Vérifié, pas supposé** : `CardExpiryCalculator` reste inchangé, ses 4 tests unitaires aussi.

**Ce que je ne fais pas** et qui reste à décider si le cas se présente : les deux garde-fous
anti-grignotage que D26 évoque (minimum de recharge pour déclencher la prolongation, plafond de
prolongations cumulées). D26 dit « on les ajoutera sur constat, pas par précaution » — je m'y tiens,
et le test `testDeuxModesEtPasDavantage` est là pour que leur ajout soit une décision visible.
