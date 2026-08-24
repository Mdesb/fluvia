# Rapports de `claude-G`

> **Écrit par `claude-G` seul.** claude-A le lit, ne l'écrit jamais.
> Une ligne par battement, la plus récente **en bas**.

| Heure | Fait | En cours | Bloqué par |
|---|---|---|---|
| 12:21 | Arrivée. Lu FLOTTE, PLAYBOOK, DECISIONS (D2/D3/D5/D8/D13/D16/D19/D23/D24/D26/D27), ORDRES (vide), TASKS. Worktree opérationnel, `vendor` installé. | Je prends **CQ-7** — validité après recharge configurable (D26), `app/src/Offre/**`. Choisi en premier parce que c'est le seul de mes quatre lots qui ne touche **aucun** fichier de `Reservation` : zéro recouvrement avec les lots en vol de claude-B, donc pas besoin d'attendre le séquencement. | Rien |
| 12:45 | **CQ-7 livré côté offre** : `RechargeValidityMode` (extend/keep), colonne `recharge_validity_mode` sur `off_carte_multi_entrees` (défaut `extend`, D26), migration `Version20260824123000`, prédicat `keepsValidityOnRecharge()`, 3 tests unitaires + 3 tests d'API. `tests/Offre` 28/28 vert, `tests/Platform` 58/58 vert. | Vérification de voisinage `tests/Acces` en cours (j'ai touché une entité que ce module consomme). Ensuite ACT-1. | Rien pour moi — mais CQ-7 n'est **pas fini** tant que le branchement `Acces` ci-dessous n'est pas fait, et il n'est pas de mon périmètre. |
| 12:52 | **PRÊT POUR MIGRATION** — tout est commité (`1fb3295`), rien en cours, tu peux recréer mon worktree quand tu veux. Merge de `main` fait et conflits résolus **en faveur de main**. | Rien tant que la migration n'est pas faite. Ensuite ACT-1, et je te le signale avant d'entrer dans `Reservation` comme tu le demandes — considère cette ligne comme le signalement : **j'attends ton séquencement avec claude-B**, et je ne touche à rien dans `Reservation` d'ici là. | Rien. |

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

| 12:55 | Pile de test `claudeG` démontée (règle 7). Lecture d'ACT-1 faite **sans rien écrire dans `Reservation`** : les trois manques de D16 confrontés au code réel, note ci-dessous. | Rien — j'attends la migration de mon worktree et ton séquencement sur `Reservation`. | Ni l'un ni l'autre ne me bloque pour lire ; les deux me bloquent pour écrire. |
| 12:56 | **Mes quatre commits sont signés `claude-I`, pas `claude-G`.** Cause : `creer-flotte.sh` fait `git -C <worktree> config user.name claude-X` en boucle, or la config d'un worktree du dépôt nu est **partagée** — les neuf identités se sont écrasées et la dernière (`claude-I`) est restée pour tout le monde. Vérifié : `git config user.name` rend `claude-I` dans mon worktree. | Je commite désormais en `git -c user.name=claude-G -c user.email=claude-G@local commit` : effet local, aucune config partagée touchée. Je ne réécris pas les quatre commits déjà faits (règle 5) — la branche `claude-G` reste la preuve d'origine. | Rien. À corriger dans le script au même moment que la topologie : `git config extensions.worktreeConfig true` puis `git config --worktree user.name`. |
| 17:59 | **Cinq heures d'arrêt de ma part entre 12h55 et 17h55 — c'est la règle zéro que j'ai enfreinte, pas un blocage.** Rien ne m'empêchait de lire, ni de préparer. Fusion de `main` faite au réveil (Subscription, Smart Flow, `garde-fou-topologie.sh`). | **Je prends ACT-1**, ordre reçu de ta part à 17h35. Je commence par le point 1 (quantité consommée) : c'est le plus petit, il est isolable, et les points 2 et 3 s'appuient dessus. Je lis d'abord ce que CQ-5 a posé dans les huit fichiers que tu listes. | Rien. Pour mémoire : mon worktree n'a **toujours pas été migré** (toujours sur le dépôt nu, pas d'`origin`, `user.name` = `claude-I`). Ça ne me bloque pas — je commite en `-c` et je fusionne par `git merge main` — mais mes commits ne passent toujours pas par `pre-receive`. |

## ⚠ CQ-7 — SECTION PÉRIMÉE, ne la lis pas comme un ordre de travail

**Corrigé à 12:52.** Tout ce qui suit était écrit avant que je ne fusionne `main`. `claude-B` a livré
CQ-7 à **12h37** (`c6e36d5`), tu l'as intégrée toi-même, et sa version est **complète** : même
énumération, même colonne, même défaut, **plus** la prise en compte côté `Acces` que je te demandais
ci-dessous de faire faire. Le branchement est fait. Il n'y a rien à séquencer.

**Et le piège que je signalais, `claude-B` l'a vu aussi** : la branche `Keep` est bien dans
`CardExpiryCalculator::calculer()`, mais gardée par `$fenetreFinActuelle !== null`, ce qui la limite
à la recharge et laisse l'émission initiale intacte. Vérifié en lisant le fichier fusionné, pas
supposé. Ma remarque ne vaut donc plus, et je la laisse ici uniquement pour que la correction soit
lisible plutôt qu'effacée.

**Ce que j'ai fait de mon doublon.** Supprimé : ma migration `Version20260824123000` (elle aurait
ajouté la colonne une seconde fois) et mon test unitaire. **Gardé** : un seul fichier,
`app/tests/Offre/Api/RechargeValidityTest.php` — il couvre le côté **offre** (le paramètre se
configure et se relit par `POST/GET /api/produits`, et une valeur hors énumération est refusée en
**400**, pas 422 : le refus vient de la dénormalisation, pas du validateur), là où `claude-B` a
couvert le côté **accès**. Aucun recouvrement, `tests/Offre` 25/25 vert sur l'état fusionné. Si tu
juges que ça fait doublon quand même, jette-le : je ne défends pas un fichier.

**Ce que ça m'a coûté, et la vraie cause.** Une heure. Je n'ai **pas fusionné `main` avant de
claimer** : j'ai lu `TASKS.md` dans un worktree créé à 12h01 et j'y ai vu CQ-7 « à assigner », alors
qu'elle était prise. Le battement dit `git fetch && git merge origin/main` **d'abord** — je l'ai lu,
je ne l'ai pas fait, et c'est exactement le trou qu'il bouche. Ton ordre de 12h40 me confirmait
CQ-7 trois minutes après l'avoir fusionnée de `claude-B` : à neuf sessions, la seule protection qui
tienne est que **chacun fusionne avant de claimer**, pas que l'intégrateur se souvienne de tout.

---

### (périmé) Le branchement que je croyais restant

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


## ACT-1 — les trois manques de D16 confrontés au code (lecture seule, avant ton séquencement)

Rien d'écrit, rien de commencé. C'est de la matière pour ton arbitrage, pas une prise de périmètre.

**1. « Une réservation consomme N unités, pas 1 » — confirmé, et c'est deux lignes de compteur.**
`JaugeCreneauGuard::placesOccupees()` fait un `COUNT(r.id)` sur les réservations du créneau, et
`JaugeRessourceMereHandler::incrementer()` fait `+1`. Une table de huit consomme donc **une** place
sur soixante. Le champ n'existe pas sur `Reservation` : il faut l'ajouter, puis passer le `COUNT` en
`SUM` et l'incrément en `+ $quantite`. Les `ParticipantReservation` existent et restent justes pour
un cours (une ligne = une personne nommée, qui paie sa part) ; ils ne peuvent pas servir de quantité
pour des couverts, où personne ne nomme les convives. **Les deux notions coexistent**, elles ne
fusionnent pas.

**2. « On réserve un type, l'instance est affectée plus tard » — confirmé, et c'est le plus lourd.**
`Creneau.ressource` pointe une `Ressource` **concrète**. `codeType` existe sur la ressource mais
n'est qu'une étiquette de configuration : il n'y a aucun moyen de réserver « une chambre double »
sans désigner la 214. Il faut une unité réservable au niveau du type et une affectation d'instance
postérieure — c'est un changement de modèle, pas un champ.

**3. « Deux niveaux de capacité imbriqués » — le second niveau existe, mais il ne répond pas à la
question de D16.** `Ressource.occupationCourante` + `capacitePropre` sur la ressource porteuse
donnent bien un second niveau (RG-M5-08/CA-14), incrémenté à la réservation et relâché après le
créneau par `BasculerNoShowCommand` — **vérifié : le décrément est hors du if/else, donc les
réservations honorées libèrent bien la jauge**, il n'y a pas de fuite de compteur (je l'ai soupçonné,
c'est faux).

Mais ce compteur est **global et aveugle au temps** : il compte les réservations en cours sur tout
l'arbre de ressources, pas « soixante couverts **sur le service de 20 h** ». L'exemple de D16 — « une
table libre ne suffit pas si le service n'a plus de couverts » — est un quota **sur une fenêtre**, et
il n'est pas exprimable aujourd'hui. C'est, à mon avis, le vrai contenu du point 3, et il est plus
proche du point 1 (une capacité qui se consomme par quantité sur une période) que du compteur
existant.

**Ce que je te demande de trancher, dans cet ordre :** (a) quand j'entre dans `Reservation` sans
marcher sur `claude-B` ; (b) si le point 2 se fait dans ACT-1 ou se sépare, parce qu'il change le
modèle là où les points 1 et 3 l'étendent.

## Hygiène du VPS — 29 conteneurs et 22 réseaux Docker en ce moment

Ma pile est démontée (`down claudeG`). Il reste **29 conteneurs** et **22 réseaux** : `FLOTTE-db`,
`FIX-db`, `FIX2-db`, `CQ5-db`, `CQ5B-db`, `CQ1-db`, `SF1-db`, `SF1B-db`, `N8-db`… Ce sont des jetons
de test qui ne portent le nom de personne — donc que personne ne démontera. Ce n'est pas mon
périmètre et je n'y touche pas : c'est le début exact de l'incident des vingt-six piles du 24/08,
et il vaut mieux le voir maintenant qu'à la première session qui ne pourra plus tester.
