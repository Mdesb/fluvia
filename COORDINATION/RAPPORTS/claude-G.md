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
| 18:30 | **ACT-1 point 1 écrit** : `quantity` sur `Reservation` et `ListeAttente` (défaut 1), jauge du créneau en **somme** au lieu d'un `COUNT`, jauge de la ressource porteuse qui bouge de la quantité, refus distinct « places insuffisantes » vs « créneau complet », lecteur de quantité partagé et validant, migration `Version20260824182500`, 5 tests d'API — `QuantiteConsommeeTest` 5/5 vert. | Suite complète `tests/Reservation` en cours, puis `tests/Platform`. Ensuite le point 3 (capacité imbriquée sur une fenêtre), puis le point 2. | Rien. **Trois arbitrages ouverts pour toi ci-dessous** — j'ai tranché au plus conservateur dans les trois cas et je continue, tu corriges si tu veux autre chose. |
| 18:32 | **ACT-1 point 1 livré et vert** : `tests/Reservation` **85/85** (1012 assertions), `tests/Platform` **58/58**, `QuantiteConsommeeTest` 5/5. Aucune régression sur les huit fichiers de CQ-5 — l'issue du no-show sur le crédit n'est pas touchée, seul le décrément de jauge y passe de 1 à la quantité. Prêt à fusionner. | **Je prends CQ-3** (carte de N réservations : ouvrir `creditRestant` sur les droits de type `Booking`). Choisi parce que D23 point 4 le cadre entièrement — « un paramètre à ouvrir, pas une mécanique à écrire » — donc il n'attend aucun arbitrage, contrairement au point 3 d'ACT-1. | Rien. Le point 3 d'ACT-1 attend ta réponse (proposition ci-dessous) ; je ne m'arrête pas pour autant. |
| 21:35 | **ARBRE PROPRE, PRÊTE POUR LA RÉPARATION DE TOPOLOGIE.** Tout est commité, `main` fusionné (3b232b2), rien en cours d'écriture — tu peux recréer mon worktree depuis le clone quand tu veux. **Mais lis d'abord le point ci-dessous : ta correction d'identité de 18h34 a cassé tous les worktrees du dépôt nu, y compris `/home/debian/wt/main`.** | Ordres lus (18:10, 18:25, D32 migrations, correction de l'alias SSH). Je vérifie ma migration ACT-1 sur une base repartie de zéro, comme D32 l'exige, puis je continue CQ-3. | Rien. |

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


## ACT-1 point 1 — ce que j'ai fait, et les trois choses que je n'ai pas voulu décider seul

**Livré.** `Reservation.quantity` et `ListeAttente.quantity` (colonne `INT DEFAULT 1 NOT NULL` :
c'est ce que valait implicitement chaque ligne avant, donc l'historique reste juste sans reprise de
données). `JaugeCreneauGuard::placesOccupees()` passe de `COUNT(r.id)` à `SUM(r.quantity)`, et gagne
`peutAccueillir($creneau, $quantite)`. `JaugeRessourceMereHandler` bouge de la quantité aux quatre
points de relâche (annulation, annulation de créneau, no-show, honorée). Refus dédoublé :
« créneau complet » (message d'origine intact, c'est lui qui appelle la liste d'attente) et
« places insuffisantes : 3 demandée(s), 2 restante(s) » — trois couverts libres ne sont pas la même
information qu'un service plein.

**Le contrôle de la quantité n'est pas décoratif** : les deux points d'entrée lisent le corps brut,
donc sans validation un `0` passait en base (réservation gratuite en capacité) et un négatif
**libérait** des places. D'où `RequestedQuantityReader`, partagé par la réservation et la liste
d'attente pour que les deux validations ne divergent pas.

### 1. Liste d'attente : un groupe qui ne rentre pas bloque-t-il la file ?

Une table de huit est première en liste d'attente, quatre places se libèrent. J'ai choisi de **ne
promouvoir personne** et de lui garder son rang, plutôt que de sauter au suivant qui rentrerait.
Raison : sauter romprait le premier arrivé premier servi de RG-M5-06, et c'est une politique
commerciale que ni D16 ni RG-M5-06 ne posent — ce n'est pas à moi de la choisir. Le choix est
verrouillé par un test nommé, pour qu'un changement soit visible et non silencieux.

### 2. Quantité et participants : je ne les ai pas reliés

Aujourd'hui, une réservation de cours avec trois participants nommés consomme **une** unité si
l'appelant ne précise rien. On peut soutenir que la quantité devrait être au moins le nombre de
participants. Je ne l'ai pas écrit : D16 dit que les deux notions coexistent, pas qu'elles se
contraignent, et une règle inventée ici casserait le cas des couverts (huit unités, zéro participant
nommé). Si tu veux la contrainte, dis-la et je la pose ; en l'état c'est explicite et non deviné.

### 3. La promotion de liste d'attente n'a jamais incrémenté la jauge de la ressource mère

Défaut **préexistant**, trouvé en lisant : `PromotionListeAttenteHandler` crée une réservation sans
appeler `JaugeRessourceMereHandler::incrementer()`, alors que `ReserverProcessor` le fait. À
l'expiration, elle passe en `AnnuleeLibre` sans décrément non plus — c'est donc symétrique et ça ne
fuit pas, mais la jauge globale **sous-compte** toutes les réservations issues d'une promotion.
Avec la quantité, l'écart devient proportionnel au groupe au lieu d'être d'une unité.

Je ne l'ai **pas corrigé dans ce lot** : c'est un changement de comportement qui déborde d'ACT-1 et
qui touche CA-14. Dis-moi si je le prends (c'est mon périmètre, une ligne et un test) ou si tu
préfères une tâche à part.


## ACT-1 point 3 — ma proposition, et pourquoi je ne l'écris pas sans ton feu vert

Rappel de ce que j'ai constaté en lisant : le second niveau **existe**
(`Ressource.occupationCourante` vs `capacitePropre`, RG-M5-08/CA-14) mais il est **global et aveugle
au temps**. Il répond à « combien de réservations en cours sur cet arbre de ressources », pas à
« soixante couverts **sur le service de 20 h** ». L'exemple de D16 est un quota sur une fenêtre.

**Ma proposition, et elle n'invente aucune entité :** le « service » est déjà exprimable — c'est un
`Creneau` posé sur la ressource **mère**. `Creneau` porte déjà ressource, début, fin et capacité.
Une réservation sur le créneau d'une table consommerait alors aussi le créneau de la salle qui la
couvre dans le temps. Deux niveaux, deux créneaux, une seule mécanique de jauge — celle que je viens
de rendre quantitative. C'est la « couche mince » que D16 demande, et non un module parallèle.

**Pourquoi je ne le fais pas de moi-même :** cela change ce qu'est un `Creneau` (aujourd'hui, une
réservation en vise exactement un), et donc la lecture de `RG-M5-01`. C'est un choix de modèle, pas
une extension — et le précédent de la semaine dit que ce genre de décision se prend avant
l'implémentation, pas pendant. Tranche, et je l'écris au battement suivant.

En attendant je prends CQ-3, qui n'attend rien ni personne.

| 21:46 | **PRÉSENTATION HORAIRE** — `claude-G` en ligne. Nouvelle règle de Maxime, reçue à l'instant et applicable à toute la flotte : *se présenter à claude-A toutes les heures, quoi qu'on fasse ; tâche en cours, on dit laquelle ; pas de tâche, on en demande une.* Détail sous le tableau — elle est de Maxime, donc c'est à toi de la porter dans FLOTTE.md, je ne l'y écris pas. | **J'ai une tâche : CQ-3.** Avant de l'attaquer je solde D32 point 5 sur le lot ACT-1 — rejeu de toute la chaîne de migrations sur une base vidée, en cours. Ensuite CQ-3 (ouvrir `creditRestant` sur les droits de type `Booking`, D23 point 4). | Rien. Rappel : `wt/main` est toujours cassé (worktreeConfig), et j'attends toujours la réparation de topologie — arbre propre depuis 21h40. |

## ⚠ Pour claude-A — `extensions.worktreeConfig` a cassé tous les worktrees du dépôt nu

**Constaté à 21h33, et c'est un effet de bord de la correction que ma remontée t'a fait faire.** À
18h34 tu as posé `extensions.worktreeConfig = true` sur `/home/debian/billetterie.git` et écrit un
`config.worktree` par worktree pour rétablir les identités de commit. L'intention est la bonne, la
combinaison ne l'est pas.

**Ce qui se passe.** La config partagée du dépôt nu contient `core.bare = true` — c'est normal pour
un dépôt nu. Tant que `worktreeConfig` était désactivé, les worktrees liés s'en accommodaient. Une
fois l'extension activée, `core.bare` cesse d'être une valeur partageable au sens de git : elle doit
vivre dans le `config.worktree` du worktree principal. Elle est restée dans la config partagée, donc
**chaque worktree lié se croit désormais bare**.

**Symptôme, à l'identique partout :**

    $ git status
    fatal: this operation must be run in a work tree

`git log` continue de marcher, ce qui rend le diagnostic trompeur : le dépôt a l'air sain.

**Qui est touché, vérifié un par un :**

| Chemin | État |
|---|---|
| `/home/debian/wt/claude-G` (moi) | était cassé — réparé localement, voir plus bas |
| `/home/debian/wt/main` | **cassé en ce moment** |
| `/home/debian/billetterie` (le clone) | sain |
| `/home/debian/wt/claude-H` et les autres migrés | sains |

Seuls les worktrees **du dépôt nu** sont touchés. `wt/main` est à `4fb2184` en HEAD détachée : si tu
ne t'en sers plus depuis que l'intégration est passée sur le clone, il n'y a rien à sauver — mais si
tu comptais y revenir, tu tomberais sur l'erreur sans comprendre pourquoi.

**Ce que j'ai fait, et strictement rien de plus.** J'ai ajouté `[core] bare = false` dans **mon
seul** `config.worktree` :

    /home/debian/billetterie.git/worktrees/claude-G/config.worktree

Fichier **propre à mon worktree**, aucune config partagée touchée, et j'ai laissé une sauvegarde
`config.worktree.bak-claudeG` à côté pour que tu puisses revenir en arrière d'un `mv`. Sans ça je ne
pouvais plus ni fusionner `main`, ni commiter, ni même lire mon `git status` — c'était l'arrêt
complet, et la règle zéro dit de ne pas s'arrêter.

**Le correctif propre est le tien**, sur le dépôt nu, et il tient en deux lignes :

```bash
git --git-dir=/home/debian/billetterie.git config --unset core.bare
printf '[core]
	bare = true
' >> /home/debian/billetterie.git/config.worktree
```

C'est le remède documenté : quand `worktreeConfig` est actif, `core.bare` et `core.worktree`
appartiennent au `config.worktree` du dépôt principal, jamais à la config partagée. Le plus simple
reste évidemment de finir la migration des worktrees vers le clone — après quoi plus personne ne
dépend du nu, et la question disparaît.

**Ce que j'en retiens pour moi** : j'ai signalé l'identité de commit sans signaler comment la
corriger sans effet de bord. Une remontée qui décrit le symptôme et laisse le correctif à l'autre
lui fait porter le risque. La prochaine fois je donne les deux, ou je dis que je ne sais pas.

| 21:48 | **D32 point 5 soldé sur ACT-1** : chaîne complète rejouée sur une base vidée — **75 migrations, 1153 requêtes, `[OK] Successfully migrated`** jusqu'à `Version20260824200000`. Ma `Version20260824182500` s'applique dans l'ordre, entre `181500` et `190000` : horodatée en heure locale comme D32 l'exige, pas en UTC. | CQ-3 : lecture faite, et il manque une spec — voir la question ci-dessous, je ne bloque pas dessus. | Rien. |
| 22:10 | **Correctif de jauge livré, seul et vert** : la promotion de liste d'attente prend désormais sa place sur `Ressource.occupationCourante`, l'expiration de promotion la rend, et la promotion contrôle enfin la jauge mère. `tests/Reservation` **87/87** (1042 assertions). **Je me corrige : ce défaut n'était pas un sous-comptage, il libérait des places qui ne sont pas libres** — détail sous le tableau. | ACT-1 point 3, avec la distinction de D33 : créneau visé unique, créneaux consommés stockés. Le résolveur est écrit et attendait que ce commit parte seul. | Rien. |
| 22:20 | **PRÉSENTATION HORAIRE** (D35) — `claude-G` en ligne. Depuis la précédente : correctif de jauge poussé seul et vert (87/87), puis **ACT-1 point 3 écrit** — créneaux consommés stockés, `CapaciteEnglobanteTest` 3/3, le cas de D16 refuse bien « une table libre quand le service est plein ». | Tâche en cours : suite complète `tests/Reservation` sur l'arbre final, lancée il y a quinze minutes. Je commite sur vert, puis `tests/Platform`, rejeu de la chaîne de migrations depuis zéro (D32 point 5), et vérification de voisinage sur `Boutique`, `Musee`, `Padel` et `Reporting` — leurs réservations passent maintenant par mon invariant, c'est à moi de montrer que je ne les ai pas cassées. | Rien. |

## Nouvelle règle de Maxime — présentation horaire à `claude-A`

Reçue le 24/08 au soir, mot pour mot : « présente toi à A chaque heure peu importe ce que tu fais.
Si tu as une tache en cours, dis lui ce que tu fais, sinon demande lui une tache. »

**Ce que ça ajoute au battement de D29**, et ce n'est pas la même chose : le battement dit ce qui est
*fini*. Il ne dit ni qu'on est encore là, ni sur quoi. Une session absorbée par un gros lot peut être
parfaitement régulière sur le fond et injoignable sur la forme — et une session silencieuse est
indiscernable d'une session morte, ce que tu as constaté quatre fois aujourd'hui.

**Comment je l'applique, à partir de maintenant :** une ligne par heure dans ce fichier, préfixée
`PRÉSENTATION HORAIRE`, même au milieu d'un lot, même s'il n'y a rien de neuf. Tâche en cours → je
dis laquelle et où elle en est. Rien en cours → **je demande une tâche**, je n'attends pas qu'on
m'en propose une.

**Ce qui te revient :** la règle vient de Maxime, donc elle vaut pour les neuf, pas seulement pour
moi. `FLOTTE.md` et `DECISIONS.md` sont ton périmètre — je te la signale, je ne l'y écris pas.
Deux choses à trancher au passage, et elles comptent plus que la règle elle-même :

- **Les sessions lancées depuis un bureau s'arrêtent dès qu'elles ont fini de répondre.** Tu l'as
  écrit toi-même à 18h10. Une présentation horaire ne part pas toute seule d'une session arrêtée :
  la règle ne peut pas *créer* de la présence, elle ne fait que rendre l'absence visible plus vite.
  C'est déjà beaucoup, mais il ne faut pas croire qu'elle règle la règle zéro.
- **Fais-en un signe de vie, pas un rapport.** Si la présentation horaire devient un point d'étape,
  elle coûtera assez cher pour être sautée les jours chargés — c'est-à-dire exactement les jours où
  elle sert.


## CQ-3 — une question de méthode avant d'écrire, et je continue pendant que tu réponds

**Ce que j'ai établi en lisant.** CQ-3 est bien cadrée sur le *quoi* : `ProjectionAccesReservationHandler`
fige `setCreditRestant(null)` avec un commentaire qui l'assume, et la spec de CQ-1 la range
explicitement en lot séparé — « mécanisme voisin, lot séparé ». D23 point 4 ajoute que le décompte
est identique et que c'est « un paramètre à ouvrir, pas une mécanique à écrire ».

**Ce qui n'est écrit nulle part**, et c'est le cœur du lot : *d'où vient le crédit d'un droit
`Booking`, et où se décompte-t-il ?* Le droit projeté aujourd'hui est **par réservation**, sa fenêtre
est celle du créneau — ce n'est pas une carte. Une carte de dix réservations est un crédit qui
autorise dix **actes de réservation**, donc consommé à la réservation, pas au passage. Ouvrir
`creditRestant` sur le droit projeté ne suffit pas : il faut dire lequel des deux objets porte le
solde. Poser ça de travers, c'est refaire le mélange que D24 reproche à `ModeFacturationNoShow`.

**Ma question, une seule :** est-ce que j'écris `specs/reservation/spec-cq3-carte-n-reservations.md`
avant d'implémenter ? Le précédent dit oui — CQ-1, CQ-5 et CQ-7 ont toutes leur spec, et `claude-B`
a écrit `specs/reservation/spec-cq5-noshow-credit.md` alors même que `Reservation` n'était pas son
périmètre : la spec suit le **lot**, pas le répertoire. Je penche donc pour l'écrire, mais `specs/**`
n'est attribué à personne dans FLOTTE.md pour `reservation`, et je ne m'attribue pas un périmètre
tout seul — c'est la règle 2, et c'est `claude-C` qui a eu raison de la tenir contre toi le 24/08.

**En attendant, je ne m'arrête pas** : je prépare la spec en brouillon dans mon rapport plutôt que
dans `specs/`, ce qui ne prend de périmètre à personne et te donne quelque chose à trancher plutôt
qu'une question sèche.


## Le défaut de la promotion — requalifié, parce que ma première description était fausse

**Ce que j'avais écrit :** « la promotion n'incrémente pas la jauge, c'est symétrique, ça ne fuit
pas, la jauge sous-compte ». `claude-A` l'a repris tel quel dans son arbitrage. C'était faux, et
c'est moi qui l'ai induit en erreur.

**Ce qui se passait réellement.** La promotion créait une réservation sans incrémenter
`Ressource.occupationCourante`. Mais cette réservation-là s'annule ensuite par les chemins
ordinaires — `AnnulerReservationProcessor`, `AnnulerCreneauProcessor`, `BasculerNoShowCommand` — qui
**décrémentent tous**. La jauge perdait donc une unité qu'elle n'avait jamais prise pour cette
réservation : une unité appartenant à **une autre**.

**Le nom correct n'est pas « la jauge sous-compte », c'est « la jauge libère des places qui ne sont
pas libres ».** C'est du surbooking silencieux, sur un compteur qui a l'air cohérent parce qu'il ne
descend jamais sous zéro. Le symptôme visible est un client qui se présente et dont la place a été
revendue — à un moment et sur une ressource qui n'ont aucun rapport avec la promotion qui l'a causé.

**Les trois changements, et pourquoi chacun :**

1. **La promotion incrémente**, de la quantité de l'inscription. C'est le correctif.
2. **`expirerPromotionsDepassees()` décrémente.** C'est le point que `claude-A` m'avait demandé de
   vérifier avant de pousser, et il avait raison de le demander : c'est **la seule sortie qui ne
   passe par aucun autre service**. Sans elle, incrémenter à la promotion aurait transformé un
   défaut inoffensif en fuite active — pire qu'avant.
3. **La promotion contrôle la jauge mère** avant de promouvoir. Elle ne vérifiait que la capacité du
   créneau : c'était le seul chemin capable de faire déborder la jauge globale (RG-M5-08, CA-14).

**Ce que je n'ai pas touché**, et qui reste à confier : `JaugeRessourceMereHandler` n'est appelé que
depuis `app/src/Reservation`. `Padel\State\ReserverTerrainProcessor` et
`Padel\Service\GenererPoulesEtBlocageHandler` créent des réservations confirmées sans l'appeler ;
`Musee\Service\PrioriteOtaResolver` en passe en `AnnuleeLibre` sans l'appeler non plus. Même
famille exactement. Je n'ai pas vérifié comment les réservations OTA du musée sont créées, donc je
ne l'affirme pas — `claude-A` a pris le signalement, ces périmètres n'étant attribués à personne.
