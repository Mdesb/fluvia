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
| 22:31 | **ACT-1 point 3 livré** : créneaux consommés stockés (D33), `tests/Reservation` **90/90** (1077 assertions). Le cas de D16 est exprimable — un créneau de 4 places sur le bassin plafonne un créneau de 6 sur la ligne d'eau, message à l'appui. Deux corrections que je me suis faites en route, détaillées sous le tableau : ma première jauge ne comptait rien, et la reprise de données que je disais inutile est devenue obligatoire. | `tests/Platform`, rejeu des migrations depuis zéro, puis vérification de voisinage `Boutique`/`Musee`/`Padel`/`Reporting`. | Rien. |
| 22:35 | `tests/Platform` 58/58 et **chaîne de migrations rejouée sur une base réellement vide** (`database:drop` puis `create`, ton conseil) : **76 migrations, OK jusqu'à la mienne**. Suites voisines en cours. **Et j'ai trouvé pourquoi CQ-3 ne peut pas être « juste ouvrir un paramètre » : CQ-5, déjà fusionnée, a tranché à ma place — mais dans un sens qui ne tient pas debout.** Détail sous le tableau, c'est pour toi. | Je prends **ACT-1 point 2** pendant que tu regardes CQ-3 : il est dans mes ordres, c'est le dernier des trois manques de D16, et il n'attend rien. | Rien. |
| 22:38 | Arbitrage CQ-3/CQ-6 reçu et **je prends le lot fusionné** (voir plus bas pourquoi le claim reste ici et pas dans `TASKS.md`). Autorisation de rouvrir CQ-5 notée. | **Mais le lot est bloqué sur CQ-0, et personne ne l'avait vu** : `DroitAcces` n'a aucun lien vers un porteur, donc « la carte de séances DE CE bénéficiaire » n'est pas résoluble. Vérifié dans l'entité, pas supposé. Je propose une tranche livrable sans CQ-0 — ci-dessous. | **CQ-0 (claude-C, statut CLAIM, pas commencée)** pour la partie nominative. Le reste avance. |
| 23:01 | **Voisinage vérifié sur ACT-1 point 3** : `Boutique` 56/56, `Musee` 22/22, `Reporting` 46/46. **`Padel` a un échec — et il n'est pas de moi : il existe déjà sur `main`, et même à `79cbf20`, avant que je ne touche quoi que ce soit aujourd'hui.** Démonstration sous le tableau. | Le lot CQ-3+CQ-6 (tranche « carte désignée »). | Rien. |
| 23:40 | **PRÉSENTATION HORAIRE + CQ-3 & CQ-6 LIVRÉS** (tranche « carte désignée », sans CQ-0). `tests/Reservation` **93/93** (1095 assertions), `tests/Platform` **58/58**, chaîne de migrations rejouée depuis une base vide jusqu'à `Version20260824230500`. Les 10 échecs rencontrés en route étaient **exactement ceux que le recentrage devait produire** — détail sous le tableau. | Prêt à fusionner. Ensuite ACT-1 point 2 (réserver un type), sauf ordre contraire. | Rien. **Un arbitrage commercial t'attend** : la carte désignée l'emporte aujourd'hui sur un quota de formule éventuel. |
| 00:13 | **ACT-1 EST COMPLET — les trois manques de D16 sont couverts.** Point 2 livré : on réserve un type, l'instance s'affecte plus tard. `tests/Reservation` **96/96** (1138 assertions), `tests/Platform` **58/58**, migrations rejouées depuis une base vide jusqu'à `Version20260824234300`. | Prêt à fusionner. Périmètre : il me reste CQ-4 côté `Acces` (pas à moi) et la moitié nominative de CQ-3/CQ-6 (bloquée sur CQ-0). **Donne-moi la suite** — sinon je prends la dette de mon module. | Rien. |
| 00:22 | **IDOR RÉEL TROUVÉ ET FERMÉ** dans `ArbitrerConflitRecurrenceProcessor` : la ressource venait du corps de la requête et n'était résolue que par son identifiant — on pouvait déplacer le créneau d'un établissement sur la ressource d'un autre. Test rouge **vérifié sans la garde** avant d'être déclaré vert, preuve d'exploitation dans le rapport. | Padel : tu me l'ouvres pour rendre `EclairageTest` vert, je m'y mets tout de suite — `main` rouge passe avant ma dette. | Rien. |
| 00:28 | **`main` EST RÉPARÉ — `tests/Padel` 23/23.** Et la cause n'est pas celle qu'on cherchait : **aucune ligne de code n'a changé, c'est le calendrier qui a changé.** Le test était rouge deux jours par semaine depuis toujours. Démonstration chiffrée sous le tableau. | Retour à la dette de cloisonnement de mon module. | Rien. |
| 00:38 | **SECOND IDOR FERMÉ, et celui-là fait plus mal** : l'action de masse du catalogue permettait d'**archiver — irréversiblement — le produit d'un autre établissement**, à qui savait deviner des UUID. Vérifié sans la garde : `nbTraites=1`, le produit étranger était bien archivé. `tests/Offre` 26/26. | Reste de la dette : `Emarger`, `AjouterParticipant`, `InscrireListeAttente`, `Convertir`. Mon audit en cours dit que plusieurs sont de la **fausse** dette — je te le démontrerai plutôt que d'ajouter des gardes décoratives. | Rien. |
| 01:02 | **Troisième et quatrième trous fermés — et ce sont des fuites de données personnelles, pas des défauts de cloisonnement.** On désignait le bénéficiaire de n'importe qui comme participant ou inscrit en liste d'attente. Vérifié sans les gardes : l'inscription était créée, la fiche étrangère référencée. `tests/Reservation` 97/97 avec les gardes, mes 3 tests dédiés verts. | Audit de la dette terminé : **2 vraies fuites corrigées, 2 fausses dettes démontrées**. Tableau sous le tableau. | Rien. |
| 08:54 | **PRÉSENTATION HORAIRE.** D'abord ceci : **je me suis encore arrêtée, de 01h15 à 08h45** — sept heures et demie, la seconde fois en deux jours. Ce n'est pas un blocage, c'est la règle zéro. Ensuite : ton ordre long est lu, et **le point 1 était déjà fait** quand tu l'as écrit (01h00) — quatre entrées résorbées, deux fausses dettes démontrées. **Point 2 fait aussi** : les disponibilités et indisponibilités étaient lisibles d'un établissement à l'autre, c'est corrigé et vérifié rouge sans la jointure. | Reste du point 2 : les 9 entrées `Offre`. Puis D41 sur mes trois entités. | Rien. |
| 09:16 | **Point 2 terminé côté dérivable** : `GrilleTarifaire`, `ConversionType` et `PrixHistorique` suivent désormais leur produit. On lisait **les prix pratiqués par un voisin**, tarif par tarif et saison par saison. Vérifié rouge sans les chemins. `tests/Offre` 27/27, `tests/Reservation` 102/102. | **Les 6 dernières entrées ne sont pas de la dette : c'est une décision de modèle, et elle est pour toi.** Recommandation motivée entité par entité sous le tableau. Ensuite D41 sur mes trois entités. | Rien. |
| 14:45 | **PRÉSENTATION HORAIRE + point 3 de ton ordre long fait.** D41 appliqué à `Activite`, `RegleAnnulation` et `Ressource` : l'établissement d'une création vient de la session serveur, le corps est ignoré. `tests/Reservation` 102/102, test dédié vérifié rouge sans le changement. **Deux essais ratés avant le bon, écrits dans le code plutôt qu'effacés.** | Point 4 : je vérifie si CQ-0 est livrée ; sinon point 5, la note sur `CommanderEclairageCommand` pour `claude-I`. | Rien. |
| 23:17 | **Point 4 : CQ-0 n'est toujours pas livrée** — `DroitAcces` ne porte toujours aucun lien vers un porteur, statut `CLAIM` chez `claude-C` depuis le 23/08. La moitié nominative de CQ-3/CQ-6 reste donc bloquée ; je ne l'attends pas. **Point 5 fait, et il y a urgence dedans** : le balayage sans borne de `CommanderEclairageCommand` était inoffensif tant que rien ne le lançait — l'ordonnanceur vient de le lancer. | Note d'analyse écrite pour `claude-I` sous le tableau. | Rien. |
| 23:40 | **`Promotion` est cloisonnée — Maxime a tranché et m'a dit de la prendre.** Elle ne portait aucun rattachement : lisible par tous les exploitants de la base, d'un groupe à l'autre, **y compris avant sa date de début**. `tests/Offre` 29/29, chaîne de migrations rejouée depuis une base vide, test vérifié rouge sans le cloisonnement. | Les cinq autres entrées « référentiel présumé » attendent toujours ton arbitrage — je n'en prends aucune sans. | Rien. |
| 10:13 | **Le chargement complet des fixtures passe de nouveau.** C'était bien chez moi : `CaisseClotureRoleFixtures` créait le rôle « Caissier » que `L7Fixtures` crée aussi. Corrigé là et dans `ReservationFixtures`. `tests/Caisse` 15/15, `tests/Reservation` 103/103. **Et le second passage bute maintenant chez toi**, sur `SocleFixtures:51`. | D48 : historique de `Vente` livré (débloque `claude-H`). Suite : les cinq chantiers `Vente`/`Caisse`. | Rien. |
| 12:11 | **D45 livré** : la correction de ventilation d'un règlement s'ajoute et se scelle, la vente n'est jamais touchée, et elle est datée du **jour du geste**. `tests/Vente` **52/52**, migrations rejouées depuis une base vide. Avant : D46-bis (canal → attente + débiteur), D44 (rien à faire côté serveur, prouvé), descriptions d'opérations, fixtures idempotentes. | D46 : rattacher une correction à l'écart de caisse qu'elle explique. | Rien. |
| 13:05 | **D46 livré avec D45** : une correction peut désigner l'`AlerteEcartCaisse` qu'elle explique. `tests/Vente` **54/54**, migrations rejouées depuis une base vide. Il ne me reste de D48 que **D44-bis** (vente directe sans session). | D44-bis. | Rien. |

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


## ACT-1 point 3 — ce qu'une spec aurait dit (tu m'as dit d'aller au code, le voici)

**Retenu.** Une réservation garde **un seul créneau visé** (`Reservation::creneau`, inchangé,
RG-M5-01 non réinterprétée) et porte un ensemble de **créneaux consommés** : le visé, plus ceux des
ressources ancêtres qui le couvrent dans le temps. Résolus à la réservation par
`ConsumedSlotResolver`, contrôlés un par un, **stockés** dans `reservation_consumed_slot`.

**Écarté n°1 — dériver au lieu de stocker.** C'est ton argument, je n'y reviens pas.

**Écarté n°2 — ma première implémentation, et c'est un test qui me l'a apprise.** J'avais écrit la
jauge en `OR` : « les réservations qui **visent** ce créneau **ou** qui le **consomment** ». Deux
défauts, dont un que je n'ai pas vu venir :
- le paramètre était l'entité `Creneau`, dont l'identifiant est un type Doctrine personnalisé : il ne
  se liait pas, la requête ne comptait plus **rien**, et un créneau plein acceptait tout ;
- même corrigé, un `OR` avec jointure aurait compté **deux fois** une réservation dont le visé est
  aussi dans les consommés.

**Retenu à la place, et c'est meilleur que ce que j'avais prévu :** `Reservation::setCreneau()`
enregistre lui-même le visé comme consommé. L'invariant vit dans **l'entité**, donc il vaut pour tous
les chemins — j'ai relu les dix-huit points d'appel : `Boutique`, `Musee` et `Padel` créent tous
leurs réservations par `setCreneau()`, et **aucun ne réassigne** le créneau d'une réservation
existante (ce qui laisserait un créneau consommé fantôme). Ils sont couverts sans que j'écrive une
ligne chez eux, et la jauge devient une jointure simple, sans `OR` et sans doublon.

**Conséquence que j'avais niée et qui est vraie : la reprise de données est obligatoire.** J'avais
écrit dans l'en-tête de ma migration qu'il n'y en avait pas besoin. Faux : la jauge comptant
désormais par les créneaux consommés, toute réservation antérieure au lot en serait absente — donc
invisible à la jauge, donc son créneau passerait pour libre et se revendrait. Même famille exacte que
le défaut de promotion corrigé une heure plus tôt, mais massive et immédiate. La migration reprend
donc le créneau visé de chaque réservation existante. Elle ne recalcule **pas** les ancêtres :
ce serait inventer rétroactivement une consommation jamais contrôlée, sur des créneaux passés.

**Deux choix mineurs, dits pour qu'ils ne soient pas découverts plus tard :**
- **Couvrir, pas chevaucher.** Un créneau ancêtre ne compte que s'il commence au plus tard et finit
  au plus tôt aux bornes du visé. Le chevauchement partiel ne dit pas combien d'unités lui imputer :
  c'est une question ouverte, pas un cas à deviner.
- **La chaîne est remontée en entier**, avec un garde-fou anti-cycle.
  `Ressource::ressourcePorteuseJauge()` s'arrête à `ressourceMere ?? $this` — correct pour la jauge
  globale qu'elle sert, insuffisant ici : `ressourceMere` est une auto-référence, donc une ligne d'eau
  peut avoir un bassin qui a lui-même un espace.
- **Les créneaux consommés ne sont pas sérialisés.** Les exposer ferait grossir chaque ligne de liste
  d'un créneau imbriqué par ancêtre, et aucun écran ne les demande (D13). On ouvrira le jour où un
  écran le réclame.

**DDL non deviné** : relevé par `SHOW CREATE TABLE` sur la table que Doctrine crée réellement depuis
le mapping, noms d'index et de contraintes compris, pour qu'un futur `migrations:diff` ne propose pas
de les renommer. Migration écrite à la main, horodatée en heure locale (D32).


## CQ-3 — le modèle est déjà contraint par du code fusionné, et la contrainte est incohérente

Tu m'as dit d'aller au code sans spec. J'y suis allé, et le code m'a appris quelque chose que ni D23
ni D24 ne disent.

**Ce que fait CQ-5, aujourd'hui, dans `main`.** `ApplyNoShowCreditIssueHandler::apply()` résout le
« droit créditable » d'une réservation ainsi : `ProjectionAccesReservation` → `droitAccesRef` →
`DroitAcces`. C'est-à-dire **le droit projeté de cette réservation-là**, celui que
`ProjectionAccesReservationHandler` crée à raison d'**un par réservation**, avec la fenêtre du
créneau. Puis il fait `credit_restant = credit_restant + 1` dessus, et va chercher un `Appairage`
actif pour bousculer `Support.versionMaj` — donc il suppose ce droit **appairé à un support
physique**.

Le commentaire du code le dit lui-même : il retourne `noCredit()` quand `creditRestant === null`,
avec la mention « cas universel Booking ». Autrement dit : **CQ-5 attend que CQ-3 ouvre `creditRestant`
sur ce droit-là**, et l'hypothèse est écrite noir sur blanc — « §3.3, décompte au booking ».

**Pourquoi ça ne tient pas.** Une carte de dix réservations est un solde qui survit aux dix
réservations. Un droit projeté meurt avec sa réservation : il en existe un par réservation, et il
n'est appairé à aucune carte. Mettre le solde dessus, c'est mettre le compteur dans l'objet qui a la
durée de vie la plus courte du système. Concrètement : la deuxième réservation ne verrait pas ce que
la première a consommé, et la restitution d'un no-show créditerait un droit que plus personne ne
regarde.

**Rien n'est cassé aujourd'hui** — `creditRestant` étant `null` sur les droits `Booking`, CQ-5 sort
proprement par `noCredit()`. Le défaut n'apparaîtrait qu'au moment où CQ-3 ouvre le champ, c'est-à-
dire dans le lot que tu viens de me confier.

**Les deux modèles possibles, et il faut choisir avant que j'écrive une ligne :**

1. **Le solde vit sur un droit de type carte** (comme `CarteQuota` aujourd'hui : un droit, un
   support, un porteur, N réservations). La réservation le décompte au moment de réserver — quatrième
   branche de `ModeDecompteReservation`, à côté de `QuotaFormule` et `VenteUnite`, exactement le
   « même point de consommation » que décrit D24. Le droit `Booking` projeté reste ce qu'il est :
   l'accès physique au créneau, `creditRestant` à `null`. **Conséquence : CQ-5 doit changer de
   cible** — sa résolution du droit créditable est à revoir, et c'est du code de `claude-B` déjà
   fusionné.

2. **On garde la cible de CQ-5** et le droit projeté porte un solde. Il faut alors expliquer d'où il
   vient à chaque projection, et ce que veut dire restituer un crédit sur un objet qui ne survit pas
   à la réservation. Je ne vois pas de réponse, mais je peux me tromper — c'est ton arbitrage, pas
   le mien.

**Ma recommandation est le modèle 1**, et la conséquence assumée est qu'il faut rouvrir une partie
de CQ-5. Mieux vaut le voir maintenant, sur un champ encore à `null`, qu'après avoir livré une carte
de séances dont le solde ne se décompte pas.

**Et si c'est le modèle 1, CQ-3 et CQ-6 sont le même lot vu de deux côtés** : ouvrir le décompte de
stock à la réservation *est* la carte de séances nominative. Je te propose de les fusionner en une
seule tâche plutôt que de les écrire séparément et de les recoudre après.


## Le piège qui m'a coûté une itération — un paramètre Doctrine qui ne se lie pas, et zéro à la place

`claude-A` me demande de le consigner pour les autres sessions, et il a raison : **il est silencieux**.

J'avais écrit, dans `JaugeCreneauGuard` :

```php
->andWhere('r.creneau = :creneau OR :creneau MEMBER OF r.consumedSlots')
->setParameter('creneau', $creneau)          // l'ENTITÉ
```

L'identifiant de `Creneau` est un type Doctrine personnalisé (`UuidType`). Passé comme entité, le
paramètre **ne se lie pas** — et la requête ne lève rien : elle renvoie simplement **zéro**. Un
agrégat `SUM(...)` sur un `WHERE` qui ne matche plus rien vaut `0`, donc la jauge a répondu
« créneau vide » sur un créneau plein, et tout est passé.

**Ce qui l'a rattrapé, c'est un test de non-régression**, pas le test de la fonctionnalité neuve : le
cas « la ligne se remplit jusqu'à sa capacité » est passé de rouge à vert au mauvais moment.

**À retenir :** avec un identifiant de type personnalisé, passer `$entite->getId()` **et le type**
(`->setParameter('x', $e->getId(), 'uuid')`), jamais l'entité. Et se méfier d'un agrégat qui renvoie
zéro : c'est indiscernable d'un résultat légitime.

## CQ-3 + CQ-6 fusionnés — je prends, et voici le blocage réel

**Le claim reste dans ce fichier, pas dans `TASKS.md`.** Tu me demandes de l'y écrire ; la règle 6 de
`FLOTTE.md` dit l'inverse, et elle est posée par Maxime : « tu claimes dans ton rapport, l'intégrateur
le reporte au carnet », précisément pour que `TASKS.md` garde un seul auteur — il avait déjà produit
un conflit le 24/08. Je ne lève pas une consigne que tu n'as pas posée (précédent `claude-C`, que tu
as toi-même retenu). **Considère cette ligne comme le claim, et reporte-la si tu veux qu'elle figure
au carnet.**

**Le blocage, vérifié dans le code et pas supposé :** `App\Acces\Entity\DroitAcces` porte
`billetSupportRef`, `produitRef`, `reservationRef`, `fenetreDebut/Fin`, `creditRestant`, `sousReseau`,
`statutProjection`, `etablissement` — et **aucun lien vers un porteur**. `Support` non plus.
C'est exactement ce que CQ-0 doit créer, et CQ-0 est au statut `CLAIM` chez `claude-C`, pas commencée.

Or D24 le dit : le rattachement à un porteur est « facultatif au niveau du modèle, mais
**obligatoire pour ce type de carte** ». Sans lui, « trouver la carte de séances de ce bénéficiaire au
moment de réserver » n'est pas résoluble. Le seul chemin existant serait
`Beneficiaire → Client → Vente → BilletSupport → identifiantSupport → Support → Appairage → DroitAcces` :
quatre modules traversés en lecture directe, exactement le couplage que D2 interdit. Je ne l'écrirai
pas.

**Ce que je livre quand même, et qui n'attend personne — la carte désignée explicitement.** L'agent
scanne ou saisit la carte, la requête de réservation la désigne, le crédit se décompte à la
réservation. C'est le cas « carte au porteur » que D23 tient pour légitime, il couvre le comptoir, et
il pose **toute la mécanique** : la troisième branche de `ModeDecompteReservation`, le décompte
atomique, la restitution sur tous les chemins de sortie, et la correction de la cible de CQ-5. Le jour
où CQ-0 arrive, la résolution automatique par bénéficiaire n'est plus qu'un résolveur en amont — pas
une reprise du lot.

**Les trois exigences que tu as ajoutées sont notées et je les traite dans cet ordre :** tous les
chemins de sortie rendent le crédit (j'ai déjà la liste, elle m'a servi pour la jauge, et elle inclut
un chemin **hors de mon périmètre** : `Musee\Service\PrioriteOtaResolver` passe une réservation en
`AnnuleeLibre` sans rien restituer) ; le no-show suit D27 et reste orthogonal à la facturation ; le
quota de stock ne partage que le point de consommation avec le quota périodique, pas la mécanique.


## `main` porte un test rouge dans `Padel`, et il n'a rien à voir avec moi

**Le fait.** `App\Tests\Padel\Api\EclairageTest::testCa11AllumageEtExtinctionAutomatiquesSurFenetreReservee`
échoue : « CA-11 : allumage déclenché à l'heure de début. Failed asserting that **3** is identical to
**1** ». La commande d'éclairage traite trois fenêtres là où le test en attend une.

**Comment je sais que ce n'est pas moi**, plutôt que de l'affirmer parce que ça m'arrange. Je l'ai
rejoué en détachant mon worktree sur deux points d'histoire :

| Révision | Contient mon travail ? | `EclairageTest` |
|---|---|---|
| `claude-G` (ma branche) | tout | rouge |
| `main` (`7a37f83`) | ACT-1 points 1 et 3, correctif de jauge — tous fusionnés | rouge |
| **`79cbf20`** | **rien de moi ce jour** | **rouge** |

`79cbf20` date de ce matin, avant ma première ligne. L'échec est donc **préexistant**, et il ne vient
ni de la quantité consommée, ni des créneaux consommés, ni du correctif de jauge.

**Ce que ça dit de plus, et qui me paraît le vrai sujet :** personne ne l'a vu. La règle 8 veut que
chaque session ne lance que la suite de son module plus `tests/Platform` — c'est la bonne règle, la
suite complète coûte deux heures — mais la conséquence est qu'un module **sans session ouverte** n'est
lancé par personne. `Padel` est précisément dans ce cas : `claude-I`, qui porte les verticales, n'est
pas ouverte. Le rouge peut donc dormir indéfiniment.

Je n'y touche pas — ce n'est pas mon périmètre, et je n'ai pas cherché la cause au-delà de la
constatation. Deux pistes gratuites pour qui le prendra : le compteur vaut 3 et pas 2, donc ce n'est
probablement pas un simple doublon de fixture ; et `ReserverTerrainProcessor` crée une réservation de
coach **en plus** de la réservation de terrain, ce qui fait deux réservations pour un acte.


## CQ-3 + CQ-6 livrés — et les dix échecs qui prouvent que le recentrage a mordu

**Ce que fait le lot.** Une carte de N réservations se décompte **à la réservation** :
`ModeDecompteReservation::CarteStock`, troisième branche à côté du quota de formule et de la vente à
l'unité. Le solde vit sur un droit de type carte, qui survit aux N réservations ; le droit `Booking`
projeté reste l'accès physique au créneau, `creditRestant` à `null`.

**La carte est désignée explicitement** dans la requête — le cas du comptoir, où l'agent la scanne.
La résolution automatique « la carte de ce bénéficiaire » attend CQ-0, qui n'existe pas ; elle ne
sera qu'un résolveur en amont, pas une reprise du lot.

**Les dix échecs.** La suite du module est passée à 10 rouges après le recentrage de CQ-5. C'était le
résultat **attendu** : les deux fichiers de test de CQ-5 encodaient l'ancien modèle — crédit forcé sur
le droit projeté, restitution vérifiée là. S'ils étaient restés verts, cela aurait voulu dire que mon
changement ne changeait rien.

Je n'ai touché que **les constructeurs de scénario et la résolution du droit**. Aucune assertion
métier n'a été affaiblie : un no-show « décompté » n'écrit toujours rien, un « restitué » incrémente
toujours de un, le cloisonnement défensif reste un no-op silencieux, la course perdue reste distincte
de l'absence de crédit. C'est la ligne que je me suis fixée — **un test qu'on ajuste pour qu'il passe
ne teste plus rien**, et la tentation était réelle sur dix rouges d'un coup.

**Une assertion change de statut plutôt que de contenu** : « appairage actif → `Support.versionMaj`
bascule » passait auparavant sur un droit projeté auquel le test attachait artificiellement un
support physique. Elle passe maintenant sur une carte — l'objet qui en porte réellement un. Tu
l'avais pressenti : ce code n'était pas à corriger, il attendait ce modèle.

**Vérifications :** `tests/Reservation` 93/93 (1095 assertions), `tests/Platform` 58/58, et la chaîne
complète de migrations rejouée sur une base **réellement vide** (`database:drop` puis `create`)
jusqu'à `Version20260824230500`.

### L'arbitrage que je te laisse

**La carte désignée l'emporte sur un quota de formule éventuel.** Si le client présente une carte
alors qu'il a par ailleurs deux séances hebdomadaires incluses dans son abonnement, c'est la carte
qui est débitée. Mon raisonnement : l'agent qui scanne exprime une intention, et deviner l'inverse
serait pire.

L'argument contraire se défend tout aussi bien : consommer d'abord le quota inclus — qui expire de
toute façon en fin de semaine et **ne se reporte pas** (RG-M1-12) — est plus favorable au client, et
lui garde sa carte pour plus tard. À bien y regarder, c'est même l'ordre que je choisirais comme
client.

Je ne l'ai pas tranché parce que c'est une politique commerciale, pas une contrainte technique. Le
choix actuel est écrit en commentaire au point de décision, pas enfoui : inverser l'ordre est une
ligne.


## ACT-1 point 2 — réserver un type, affecter l'instance après

**La solution n'a demandé aucune entité neuve, et c'est le point important.** Le type est une
`Ressource` qui porte des sous-ressources ; les instances sont ses enfants. La structure existait
déjà — ce qui change est l'usage : réserver **l'enfant**, c'est choisir une instance précise (la
ligne d'eau 1, le court 3) ; réserver **le parent**, c'est réserver un type, et l'instance arrive
plus tard, parfois à l'arrivée du client. C'est le même arbre de ressources qui sert la capacité
englobante du point 3, utilisé dans l'autre sens.

Aucune colonne sur `Creneau`, aucune entité créée : `Reservation.ressourceAffectee` et une opération
`POST /reservation/reservations/{id}/affecter`. La « couche mince » de D16, littéralement.

**Trois refus, dont un seul protège quelqu'un.** Instance étrangère au type réservé (422) — sinon
« chambre double » pourrait être honorée par un emplacement de camping. Instance d'un autre
établissement (404, échec fermé, l'instance étant désignée par le client). Et surtout : **instance
déjà affectée sur un créneau qui chevauche** (409). C'est celui-là qui compte — sans lui, deux
personnes reçoivent la chambre 214 pour la même nuit, et personne ne le découvre avant l'arrivée.

Le chevauchement se lit sur les créneaux et non sur les journées : une chambre n'est pas libre
« à moitié ».

**Droit retenu :** `reservation.reserver` et non `gerer_ressource`. Affecter une chambre est un acte
d'exploitation courant, fait au comptoir par qui prend les réservations, pas une administration du
référentiel. Dis-moi si tu vois les choses autrement, c'est une ligne.

## Deux fois ce soir, j'ai rendu un verdict sur un arbre qui avait bougé

À consigner, parce que c'est une erreur de méthode et qu'elle est invisible dans le résultat.

1. **Suite lancée, puis annotation de sérialisation retirée en cours de route** (ACT-1 point 3).
2. **Suite lancée, puis classe renommée** après le refus du garde-fou D5 (ACT-1 point 2). Résultat :
   trois rouges, `Processor "AffecterRessourceProcessor" not found` — la suite cherchait une classe
   qui n'existait plus. J'ai relancé et obtenu 96/96.

Les deux fois, le verdict ne valait rien. Les deux fois, j'aurais pu le prendre pour argent comptant
si je n'avais pas su ce que j'avais touché entre-temps — et la seconde aurait été pire dans l'autre
sens : un rouge que j'aurais pu attribuer à mon code alors qu'il ne venait que du renommage.

**Règle que je m'applique désormais :** on ne modifie pas l'arbre pendant qu'une suite tourne. Si
c'est urgent, on tue la suite et on la relance — dix minutes perdues valent mieux qu'un verdict faux.

## Le garde-fou D5 m'a arrêtée, et il a eu raison

Mon premier nom de classe était `AffecterRessourceProcessor`. Le hook a refusé le commit :
« identifiant français dans un fichier nouvellement ajouté ». Renommé en `AssignResourceProcessor`.

J'avais lu D5 douze heures plus tôt et je l'ai enfreinte quand même. C'est exactement l'argument que
tu défendais ce matin : la barrière ne doit pas dépendre de la vigilance de qui écrit.


## Dette de cloisonnement — premier IDOR fermé, et il était bien réel

`app/src/Reservation/State/ArbitrerConflitRecurrenceProcessor.php`, entrée gelée le 20/08.

**Ce qui était possible.** L'arbitrage d'un conflit de récurrence accepte une `ressource` dans le
corps de la requête et la résout par `find()`. Aucune confrontation avec le périmètre. Un exploitant
pouvait donc déplacer **son** créneau sur la ressource d'un **autre** établissement.

**Vérifié par exploitation, pas déduit.** J'ai écrit le test de non-régression, puis j'ai retiré la
garde et relancé — comme tu me l'as rappelé, un test de sécurité qu'on n'a pas vu rouge ne prouve
rien. Réponse obtenue sans la garde, en 200 :

```json
"ressource": { "libelle": "Terrain B …" },
"etablissement": "/api/etablissements/3992383c-…"   ← l'établissement A
```

Le créneau de A pointait la ressource de B. Avec la garde : **404**, et le créneau n'a pas bougé —
c'est la seconde assertion du test, parce qu'un refus qui laisserait l'écriture faite serait pire
qu'une absence de refus : il aurait l'air d'avoir protégé quelque chose.

**Le cas est plus vicieux qu'il n'y paraît**, et c'est pour ça qu'il a survécu : l'admin de
démonstration est affecté à A **et** à B, donc la ressource de B lui est légitimement visible. Ce
qu'il faut refuser n'est pas la lecture, c'est le **rapprochement**. Le périmètre qui compte est
celui du créneau, jamais celui de l'utilisateur.

**Ce que je n'ai pas fait** : toucher aux lignes de base. Elles vivent dans `bin/`, périmètre de
`claude-C`. Je te donnerai la liste de ce que le garde-fou constate réellement résorbé — pas ce que
je pense avoir résorbé.


## `EclairageTest` — personne n'avait rien cassé, c'était le calendrier

Tu m'avais donné le bon point de départ : `CommanderEclairageCommand::commander()` balaie **toutes**
les `ReservationPadel` sans borne de date, et deux réservations étaient devenues éligibles. Restait
à savoir lesquelles, et pourquoi maintenant.

**Ce n'est ni `ReserverTerrainProcessor`, ni `GenererPoulesEtBlocageHandler`, ni mon correctif de
jauge.** C'est la réservation de démonstration des fixtures.

**Les dates, calculées dans le conteneur et non déduites :**

```
conteneur               : Mon 24/08/2026 22:23 (UTC)
créneau démo (fixtures) : Tue 25/08/2026 09:00   ← « next tuesday »
créneau du test         : Mon 31/08/2026 19:00   ← « next monday »
horloge simulée du test : Mon 31/08/2026 19:01
démo éligible ?           OUI → 2 commandes parasites
```

La démo précède l'horloge simulée de six jours : son créneau a donc **commencé et fini**, ce qui
déclenche un allumage **et** une extinction. Deux, plus la commande légitime du test : **trois**.
C'est exactement le « 3 au lieu de 1 » observé.

**Pourquoi c'est arrivé « tout seul ».** Le commentaire de la fixture annonçait un créneau
« délibérément distinct des scénarios de test ». Il l'était par le **jour de la semaine**, pas dans
le **temps**. Or `next tuesday` ne tombe après `next monday` que cinq jours sur sept : lancée un
dimanche ou un lundi, la suite devient rouge. Nous étions lundi soir côté conteneur — 22h23 UTC pour
00h23 chez nous, le décalage de deux heures dont parle D32, qui frappe ici sous un autre déguisement.

**Ce test était donc rouge deux jours par semaine depuis son écriture.** Personne ne l'a vu parce que
personne ne lance `Padel` : `claude-I` n'est pas ouverte, et la règle 8 veut — à juste titre — que
chacun ne lance que son module.

**Le correctif, une ligne** : ancrer la démo sur `next monday +1 semaine`. Elle est ainsi toujours
huit à quatorze jours devant, donc toujours après l'horloge de n'importe quel test, quel que soit le
jour de lancement. J'ai gardé le créneau matinal en heure creuse, qui est ce que la fixture voulait.

**`tests/Padel` 23/23.**

**Ce que je n'ai pas fait, et que je te signale** : `commander()` reste sans borne de date. Il
rattrape tout le passé à chaque exécution — c'est inoffensif aujourd'hui grâce au contrôle
d'événement déjà émis, mais c'est la cause structurelle. La borner serait un vrai lot, dans un
périmètre qui n'est pas le mien : tu m'as ouvert Padel pour rendre ce test vert, pas pour le
refondre.


## Second IDOR — l'action de masse du catalogue archivait le produit des autres

`app/src/Offre/State/ActionsDeMasseProcessor.php`, entrée gelée le 20/08, sensibilité « autre ».
**Elle est mal classée : archiver est irréversible, et l'action porte sur une liste entière.**

**Ce qui était possible.** Le corps de la requête donne `{"action": "archiver", "produits": [...],
"confirmer": true}`. Chaque identifiant était résolu par un `find()` sec. Aucune confrontation au
périmètre. Qui savait deviner des UUID pouvait donc archiver le catalogue d'un autre établissement —
et l'archivage ne se défait pas.

**Vérifié sans la garde**, comme pour le précédent : `nbTraites = 1`, le produit d'un établissement
où l'utilisateur n'a aucune affectation était bel et bien archivé. Avec la garde : `nbTraites = 0`,
et le produit n'a pas changé de statut — c'est la seconde assertion du test.

**La restriction est celle de `PerimetreProduitExtension`, mot pour mot** : le produit doit être
commercialisé dans un établissement où l'utilisateur possède une affectation. Je l'ai recopiée plutôt
que d'inventer plus strict — refuser un cas que la lecture autorise aurait été une régression
déguisée en durcissement.

**Un détail qui n'en est pas un :** un produit hors périmètre retourne `null`, donc rejoint les échecs
« introuvable » **déjà prévus** par le processor. Même forme de réponse qu'un identifiant inexistant,
donc aucun oracle d'énumération. Il n'y avait pas besoin d'inventer un code d'erreur : le bon
comportement existait déjà, il n'était simplement jamais atteint.

**Le montage du test mérite d'être dit**, parce qu'un test mal monté aurait « passé » sans rien
prouver : l'admin de démonstration est affecté à **A et à B**. Un produit rattaché à B lui est donc
légitimement accessible. J'ai créé un établissement neuf, sans affectation, et déplacé le produit
dessus. C'est le seul montage qui teste le périmètre plutôt que la chance.

**`Categorie` n'est pas concernée** — vérifié : l'entité ne porte aucun établissement, c'est un
référentiel global. La résoudre sans contrôle de périmètre n'est donc pas une fuite de cloisonnement.


## Audit terminé de la dette de cloisonnement de mon périmètre

Tu m'avais dit : ne me donne que ce que le garde-fou constate. Voici les quatre entrées restantes,
tranchées sur pièce et pas au jugé.

| Entrée gelée | Verdict | Pourquoi |
|---|---|---|
| `Offre/ConvertirProcessor` | **fausse dette** | `TypeProduit` ne porte aucun établissement — référentiel global, comme `Categorie`. Le produit converti, lui, arrive déjà filtré par `PerimetreProduitExtension`. |
| `Reservation/EmargerProcessor` | **fausse dette** | L'émargement est résolu *depuis* la réservation (`findOneBy(['reservation' => …])`), elle-même filtrée. Résolution dérivée, jamais une entrée client. |
| `Reservation/AjouterParticipantProcessor` | **vraie fuite** | corrigée |
| `Reservation/InscrireListeAttenteProcessor` | **vraie fuite** | corrigée |

**Sur les deux fausses dettes, je n'ai rien écrit.** Ajouter une garde là où il n'y a rien à garder
donnerait l'illusion d'un durcissement et coûterait une lecture à chaque relecture future. Elles
restent à retirer des lignes de base, pas à « corriger » — c'est ton geste, pas le mien.

**Le garde-fou avait raison de sonner, mais pas sur le bon objet.** Sur les deux vraies, il pointait
`ListeAttente` et une liste d'entités vide. Le trou, lui, était le **`Beneficiaire`** — que ce
garde-fou ne surveille pas, parce qu'il appartient au CRM. Il a détecté la bonne famille de code pour
la mauvaise raison. Ça vaut peut-être une remarque à `claude-C` : le contrôle porte sur les entités du
module, pas sur celles qu'on résout **chez les autres**.

## Ce que ces deux trous permettaient réellement

Ce n'est pas « voir » une donnée d'un autre établissement, c'est **l'attacher à soi**. On désignait la
fiche de n'importe qui comme participant d'une réservation — elle apparaît ensuite dans la liste avec
son identité et sa part de paiement — ou on l'inscrivait en liste d'attente, où le rang obtenu
confirme son existence. **Une fuite de données personnelles**, et un levier de nuisance : inscrire un
inconnu à un créneau qu'il n'a jamais demandé.

**Vérifié sans les gardes**, comme pour les deux IDOR précédents. Réponse obtenue, en 201 :

```json
"@type": "ReservationListeAttente",
"beneficiaire": "/api/beneficiaires/4d0143c7-…"   ← fiche d'un autre groupe
```

**La règle appliquée est celle de `PerimetreCrmExtension`**, mot pour mot : le groupe du client
porteur doit être celui d'une région d'un établissement où l'utilisateur possède une affectation. Un
seul résolveur partagé (`ScopedBeneficiaryFinder`), deux points d'appel.

**Ce que j'ai vérifié avant d'écrire, et qui aurait pu tout casser :** je craignais de couper le
libre-service — un client d'espace personnel sans affectation aurait perdu l'accès à ses propres
bénéficiaires. Vérifié dans les fixtures : le rôle client **porte une affectation** sur son
établissement. Le contrôle `reserver_soi` reste par-dessus : l'un borne au groupe, l'autre à la fiche.
Et un troisième test vérifie qu'un bénéficiaire du périmètre passe toujours — sans lui, j'aurais pu
livrer un mur au lieu d'une garde, avec une suite verte pour le prouver.

## Et un cinquième trou, identique, que je n'ai pas touché

`ReserverProcessor` résout l'**organisateur** exactement de la même manière, et le garde-fou ne l'a
**jamais** signalé. C'est le chemin principal de réservation.

Je ne l'ai pas emporté dans ce lot : c'est le cœur du module, il mérite son propre commit et sa
propre suite verte plutôt que d'être corrigé à une heure du matin dans un lot de dette. Le résolveur
est écrit, le branchement est d'une ligne. Dis-moi si tu veux que je le prenne au prochain battement.


## Couverture de périmètre — les deux entrées `Reservation` sont fermées

`DisponibiliteRessource` et `IndisponibiliteRessource` ne portent pas d'établissement : elles le
tiennent de leur **ressource**. Sans jointure, leurs collections étaient lisibles d'un établissement
à l'autre.

**Ce que ça donnait à voir n'est pas anodin** : les plages d'ouverture et les fermetures
exceptionnelles d'un voisin, c'est-à-dire son activité réelle — quand il ouvre, quand il ferme, quand
il est en travaux.

**Correctif :** deux lignes dans `PerimetreReservationExtension`, avec la jointure vers la ressource.
Aucun mécanisme neuf — la ligne de base l'avait d'ailleurs déjà écrit dans son champ `traitement` :
groupe A, chemin `ressource`, cible `Ressource`. Il n'y avait qu'à l'appliquer.

**Vérifié rouge sans la jointure** : la disponibilité étrangère apparaissait bien dans la collection.

**Et le `risque_ecriture` de la ligne de base, lui, était déjà couvert** — je l'ai vérifié plutôt que
de le supposer. Déclarer une disponibilité **sur** la ressource d'un autre établissement échoue en
« Item not found » : la résolution d'IRI par API Platform passe par le fournisseur d'item, donc par
l'extension de périmètre, et `Ressource` y était déjà. Mon test le documente au lieu de le corriger —
il vaut mieux qu'un invariant réel soit tenu par un test que par la mémoire de celui qui l'a lu.

## Et je dois dire ceci avant le reste

**Je me suis arrêtée sept heures et demie**, de 01h15 à 08h45. C'est la seconde fois en deux jours,
après les cinq heures d'hier. Rien ne me bloquait : ton ordre long de 01h00 était précisément écrit
pour que je puisse continuer sans toi, et il est resté non lu jusqu'à ce matin.

Je ne peux pas garantir que ça ne se reproduira pas — c'est la limite que tu as toi-même décrite à
18h10, une session lancée depuis un bureau s'arrête quand elle a fini de répondre. Ce que je peux
faire, et que je fais : le dire au lieu de le laisser deviner, et reprendre par le battement plutôt
que par le travail — fusionner, lire les ordres, me présenter, puis coder.


## Couverture de périmètre — trois de plus, et les six dernières ne m'appartiennent pas

**Les trois dérivables sont fermées.** `GrilleTarifaire` et `ConversionType` par leur produit,
`PrixHistorique` par `grille.produit`. Le classement de `claude-C` dans la ligne de base était juste
(groupes A et A2, chemins écrits noir sur blanc) : je n'ai eu qu'à l'appliquer. L'extension produit
accepte maintenant un chemin d'association et le remonte segment par segment — deux jointures pour
`grille.produit`.

**Ce que la fuite donnait à voir** : la grille tarifaire d'un voisin, tarif par tarif et saison par
saison, plus l'historique de ses changements de prix. Ce n'est pas de la configuration partagée,
c'est sa politique commerciale — et l'historique dit en plus **quand** il l'a changée.

Vérifié rouge sans les chemins. `tests/Offre` 27/27, et `tests/Reservation` 102/102 après le lot
précédent.

### Les six dernières : ce n'est plus de la dette, c'est un choix de modèle

`Categorie`, `Promotion`, `Saison`, `TypeProduit`, `TypeTarif`, `TrancheQuotientFamilial`. Ta ligne
de base les classe en « groupe B — référentiel présumé, à trancher module par module ». Je suis le
module ; voici mon tranchage, mais il ne se met pas en œuvre sans toi, parce qu'il demande une
colonne et une migration, pas une jointure.

**Le fait, d'abord** : aucune ne porte d'établissement **ni de groupe**. Elles ne sont donc pas
« globales au groupe » — elles sont globales à **toute la base**, c'est-à-dire partagées entre des
exploitants qui n'ont aucun rapport entre eux.

| Entité | Mon avis | Pourquoi |
|---|---|---|
| `TypeProduit`, `TypeTarif` | **laisser global** | Vocabulaire structurant du logiciel (« entrée », « carte », « plein tarif »). Les cloisonner interdirait à deux établissements de partager un référentiel commun, ce qui est précisément l'intérêt d'une plateforme. Et `TypeProduit` porte les compatibilités de conversion : cloisonné, il faudrait les redéclarer partout. |
| `Categorie` | **laisser global**, mais à revoir si la personnalisation arrive | Arbre de classement. Même raison, avec moins de force : un exploitant peut vouloir son propre axe. |
| `TrancheQuotientFamilial` | **à cloisonner par groupe** | Les tranches de quotient familial sont fixées par la collectivité. Deux clients n'ont pas les mêmes, et les voir mutuellement n'a aucun sens. |
| `Saison` | **à cloisonner par groupe** | Une saison est un calendrier d'exploitation. Celui du voisin ne le regarde pas, et une saison créée par l'un apparaît aujourd'hui dans les listes de l'autre. |
| `Promotion` | **à cloisonner par groupe, et c'est la plus urgente** | Une promotion est une **arme commerciale**. Aujourd'hui, un exploitant voit celles de ses concurrents, y compris avant leur date de début. |

**Ce que je ne fais pas et pourquoi.** Ajouter un `groupe` à trois entités, c'est une migration, une
reprise de données (à quel groupe rattacher l'existant ?) et un choix d'échelle — groupe ou
établissement — que je ne peux pas trancher seul. Sur `Promotion`, il y a en plus une question de
compatibilité : si des promotions existantes sont déjà partagées entre groupes, les cloisonner en
casse l'usage.

**Dis-moi si tu veux que je le prenne**, et à quelle échelle. En attendant, ces six entrées restent
au cliquet — elles y sont à juste titre, et les retirer sans corriger serait pire que de les laisser.


## D41 sur mes trois entités — et les deux essais qu'il a fallu

`Activite`, `RegleAnnulation` et `Ressource` acceptaient `etablissement` **en écriture** : le client
choisissait à quel établissement rattacher ce qu'il créait. Ton garde global refuse maintenant les
écritures hors périmètre, mais un champ qu'il faut garder n'aurait jamais dû être ouvert.

**Les deux essais ratés, constatés et non supposés — je les laisse dans le docblock parce que le
prochain qui touchera à ça se posera exactement les mêmes questions :**

1. **Un `processor`** posant l'établissement après désérialisation : **trop tard**. La validation
   s'exécute *entre* la désérialisation et l'écriture, donc l'`Assert\NotNull` du champ échouait en
   422 avant qu'il ne soit atteint.
2. **Un `provider`**, donc plus tôt dans la chaîne : **jamais appelé**. Une opération `Post` a
   `read: false`, et le fournisseur d'une opération n'est consulté qu'à la lecture. J'ai vérifié
   qu'il était bien enregistré comme service avant de conclure — il l'était.

**Retenu :** le processor, et l'`Assert\NotNull` retirée du champ. Elle protégeait d'un client qui
**omettait** l'établissement ; il ne peut désormais plus l'envoyer du tout. Elle ne protégeait donc
plus que d'une erreur du serveur — et de celle-là, trois choses plus solides s'occupent :
l'estampilleur, qui **refuse plutôt que de deviner** quand aucun établissement n'est actif ; la
colonne `NOT NULL` ; et ton garde global.

Retirer une contrainte de validation ressemble à un affaiblissement. J'ai donc écrit la raison à
l'endroit exact où un relecteur se posera la question — dans le commentaire du champ, pas seulement
dans le message de commit.

**Le montage du test est délibérément *légitime*, et c'est le point important.** L'admin est affecté
à A **et** à B : rien ne lui interdit de travailler sur B. Ce que le test vérifie n'est donc pas un
refus, c'est que le champ envoyé est **ignoré** — la ressource naît dans l'établissement du contexte,
pas dans celui que le corps désigne. Un test bâti sur un établissement interdit aurait été vert grâce
à ton garde global, sans rien prouver sur la conception. Vérifié rouge sans le changement : la
ressource naissait bien chez B.


## Point 5 — `CommanderEclairageCommand` : ce que je ferais, et ce qui est urgent

Padel ne m'est pas ouvert pour ça. Voici l'analyse pour `claude-I`, et **un point qui ne peut pas
attendre son ouverture.**

### L'urgence : cette commande vient de passer de « jamais lancée » à « lancée »

`commander()` charge **toutes** les `ReservationPadel` sans aucune borne de date, et déclenche un
allumage pour chaque créneau déjà commencé, une extinction pour chaque créneau déjà fini. Tant que
rien ne la lançait, c'était une inefficacité. Depuis que l'ordonnanceur tourne, **sa première
exécution rattrape tout l'historique en une fois**.

Et ce n'est pas qu'une écriture en base : `PilotageEclairageHandler::declencher()` appelle
`PiloteEclairage::commander()`, c'est-à-dire **le port qui pilote le relais physique**. L'adaptateur
actuel est un simulateur, donc l'effet réel est nul aujourd'hui — mais le jour où un vrai relais est
branché, la première exécution allume et éteint les projecteurs de chaque terrain autant de fois
qu'il y a de réservations passées.

Le contrôle `evenementExiste()` limite les dégâts à **une seule** salve : chaque réservation n'est
traitée qu'une fois. C'est une salve de rattrapage, pas une boucle — mais elle a lieu exactement une
fois, et c'est maintenant.

**Ce que je ferais tout de suite, avant même le correctif** : vérifier si la commande a déjà tourné
(`platform:scheduler:status`) et, si oui, compter les `EvenementEclairage` créés dans la dernière
heure. S'il y en a un par réservation padel historique, la salve a eu lieu et il faut le savoir
plutôt que de le découvrir sur un incident terrain.

### Le correctif, tel que je l'écrirais

**1. Borner la requête par le temps, pas par l'historique déjà traité.** Un créneau terminé depuis
deux semaines n'a aucune commande à recevoir. Deux requêtes ciblées remplacent le balayage :
les créneaux dont le **début** tombe dans une fenêtre récente pour l'allumage, ceux dont la **fin**
y tombe pour l'extinction. La fenêtre doit couvrir plusieurs cycles d'ordonnanceur — une heure est
large et suffit.

**2. Garder `evenementExiste()` comme garde d'idempotence, jamais comme borne.** C'est aujourd'hui
la seule chose qui empêche de tout rejouer, et elle coûte deux requêtes par réservation : un N+1 qui
grandit avec l'historique. Bornée par le temps, elle ne s'exécute plus que sur une poignée de lignes.

**3. Joindre le relais au lieu de le chercher par réservation.** `findOneBy(['terrain' => …])` dans
la boucle est le même N+1. Une jointure le supprime et écarte d'emblée les terrains sans relais.

**4. Ne pas rattraper le passé au démarrage.** C'est le point de conception, et il déborde du reste :
une commande périodique qui n'a pas tourné depuis longtemps doit reprendre **à maintenant**, pas
depuis le début des temps. Sinon toute remise en service d'un ordonnanceur produit une salve.
La borne temporelle du point 1 y répond mécaniquement — c'est pour ça que je la mets en premier.

**Ce que je ne toucherais pas** : la logique métier elle-même (allumer au début, éteindre à la fin,
basculer le relais en défaut sur échec) est juste. Le défaut n'est pas dans ce qu'elle décide, il est
dans **l'ensemble sur lequel elle décide**.


## `Promotion` cloisonnée — l'échelle retenue, et le piège que ça ouvrait

**Maxime a tranché** : je prends `Promotion`. C'était la plus urgente des six, et pour une raison
que le test dit mieux que moi — il pose une promotion **à venir** et vérifie qu'un concurrent ne la
voit pas. Une promotion visible avant sa date de début, ce n'est pas une fuite de configuration,
c'est un plan commercial lisible par ceux qu'il vise.

### L'échelle : les sites, comme `Produit`

J'avais recommandé « par groupe » dans mon analyse. **Je change d'avis après avoir lu l'entité**, et
il vaut mieux le dire que de livrer l'un en ayant annoncé l'autre.

Une promotion porte sur des **produits**, et un produit est commercialisé **site par site**
(`Produit::etablissements`, ManyToMany). Lui donner l'échelle du groupe aurait créé deux notions de
périmètre dans le même module : le produit visible sur un site, la promotion qui s'y applique visible
sur tous les autres. Calquée sur `Produit`, elle réutilise la règle existante **mot pour mot** — une
seule ligne dans l'extension, aucun mécanisme neuf, et un exploitant multi-sites peut appliquer la
même promotion à plusieurs sites sans la dupliquer.

### La reprise de données : le seul choix qui ne détruit rien

La lecture passe par une jointure interne : une promotion rattachée à zéro établissement devient
invisible **pour tout le monde**. Ne rien reprendre aurait donc fait disparaître l'existant.

Retenu : rattacher chaque promotion existante à **tous** les établissements. Ce n'est pas un idéal —
cela **préserve exactement la visibilité actuelle, y compris son excès**. Le trou reste donc ouvert
pour les lignes déjà là, et se referme pour toutes les suivantes ; le restreindre devient un geste
d'exploitant, ligne par ligne, en connaissance de cause. L'alternative aurait été de deviner un
rattachement, c'est-à-dire de fabriquer de la donnée que personne n'a jamais saisie. C'est écrit
dans l'en-tête de la migration, pas seulement ici.

### Le piège que le cloisonnement ouvrait, et que j'ai fermé dans le même lot

Une promotion créée **sans site** aurait renvoyé un 201 rassurant puis disparu de toutes les listes,
y compris celle de son auteur. Un défaut la rattache donc au site actif quand le client n'en précise
aucun, et un test le verrouille.

**Ce n'est pas D41 et il ne faut pas les confondre** : D41 *retire* au client le droit de choisir
l'établissement, ce que j'ai fait sur `Reservation\Ressource`. Ici le choix des sites lui reste
légitimement — c'est une décision commerciale, pas un périmètre technique. On ne lui retire rien, on
lui donne un défaut sensé quand il ne dit rien. Le garde global de D41 continue de refuser tout site
hors de son périmètre.

### Les cinq autres attendent

`Categorie`, `Saison`, `TypeProduit`, `TypeTarif`, `TrancheQuotientFamilial`. Mon avis est écrit plus
haut, il n'a pas changé — mais aucune ne se prend sans arbitrage, et surtout pas `TypeProduit` et
`TypeTarif`, que je recommande de **laisser globales**. Les cloisonner par confort de cliquet serait
le contraire du travail : on résorberait une entrée en cassant le partage de référentiel qui fait
l'intérêt d'une plateforme.


## Fixtures idempotentes — l'ordre parlait des rôles, il n'y avait pas que ça

**Le blocage était bien le mien** : `CaisseClotureRoleFixtures` créait un rôle « Caissier » que
`Securite\DataFixtures\L7Fixtures` crée également, et `Role.nom` porte une unicité globale. Corrigé
là et dans `ReservationFixtures`, avec le patron `roleNomme()`.

**Mais en corrigeant, j'ai trouvé trois autres familles dans mes deux fixtures, et je les ai toutes
gardées :**

- les **`Permission`** — le couple (module, action) porte aussi une unicité, et j'en créais deux dans
  `Caisse` et quinze dans `Reservation` ;
- les **`Utilisateur`** — l'email est unique ;
- les **`Affectation`**, et c'est la plus vicieuse : **elle ne porte aucune unicité en base**. Un
  second chargement ne casse pas, il **empile des doublons**. Silencieux, et faux — les droits
  effectifs d'un utilisateur se calculent en parcourant ses affectations.

Me limiter aux rôles aurait fait passer la seconde passe **sans rendre les fixtures idempotentes pour
autant**. C'est le genre de correctif qui a l'air fini parce que le symptôme a disparu.

### Ce que la vérification a donné, et où ça bute maintenant

J'ai fait le double chargement que tu prescris, dans mon conteneur :

- **premier chargement : passe** — il allait au mur avant ;
- **second chargement (`--append`) : échoue**, sur `Duplicate entry 'organisation-gerer'`.

La cause est `App\DataFixtures\SocleFixtures` ligne 51 —
`(new Permission())->setModule('organisation')->setAction('gerer')`, sans garde. Elle t'appartient.
Tant qu'elle n'est pas gardée, ton `FixturesIdempotentesTest` sera rouge sur la seconde passe : je te
suggère de le fusionner **avec** ce correctif, pas avant.

### La méthode, et pourquoi je ne me fie pas à mes suites ici

`tests/Caisse` 15/15 et `tests/Reservation` 103/103 — **et elles étaient vertes avant ma correction
aussi**. Elles ne prouvent rien sur l'idempotence : le harnais recrée le schéma depuis les entités à
chaque classe, donc les fixtures partent toujours d'une base vide et sont chargées sélectivement. La
formule de `claude-D` est la bonne — « la suite passait déjà avant ma correction, c'est tout le
problème ». Le seul geste qui révèle le défaut est de charger deux fois.


## D45 — la correction de règlement, et les deux murs que j'ai heurtés

**Ce que ça pose.** `SettlementCorrection` : −X sur un moyen, +X sur un autre, rattachée à la vente,
motivée, signée, scellée dans la chaîne sous un type dédié. **La vente n'est jamais modifiée** — ni
par écriture directe (la chaîne la refuserait), ni par avoir (il annulerait et rejouerait le chiffre
d'affaires pour une erreur qui n'a rien changé au montant).

### L'inaltérabilité a refusé mon propre test

J'écrivais le test « datée du jour du geste » en antidatant la vente **après** validation :

    OperationInalterableException: Modification interdite : la vente est validée (NF525) ;
    champ « date » figé.

C'est exactement le mécanisme sur lequel repose D45, et il m'a arrêtée moi aussi. Je n'ai pas cherché
à le contourner — j'ai antidaté **avant** la validation, seul chemin honnête. La garantie n'est pas
déclarative : je viens de la heurter, et c'est la meilleure preuve que le lot repose sur du solide.

### J'avais écrit du `bcmath`, qui n'existe pas sur cette image

Premier échec : `Call to undefined function bccomp()`. Et le dépôt le savait déjà —
`Autorisation\Service\ComparateurMontant` existe précisément pour ça, avec le même contrat, et
`Stay\Service\StayBalance` porte la même note. J'ai repris leur convention (centimes entiers) plutôt
que d'en introduire une troisième. C'est la règle que tu m'as rappelée ce matin sur les patrons
nullable, appliquée ailleurs : **vérifier avant d'inventer**.

### Le contrôle qui fait le lot

Le cœur n'est pas de créer l'écriture, c'est de **borner ce qu'elle déplace** : on ne sort d'un moyen
que ce qui y reste **net des corrections déjà passées**. Sans ça, deux corrections successives
déplaceraient deux fois la même somme, et on créditerait la carte depuis des espèces jamais
encaissées. Un test le vérifie en repassant la même correction — la seconde est refusée.

Motif obligatoire (ce geste déplace de l'argent : qui peut sortir des espèces vers la carte peut
masquer un manquant), droit `vente.corriger_reglement` distinct de `caisse.gerer`, auteur signé.

### Ce que j'assume et qu'il faudra dire à l'exploitant

Le Z d'hier garde sa ventilation fausse ; celui d'aujourd'hui porte la correction. **Ce n'est pas un
défaut, c'est ce qui rend le Z digne de foi** — et ce n'est pas une perte d'information, puisque la
correction pointe la vente d'origine : un état par date de vente reste calculable. C'est une question
de restitution, pas de donnée. Quelqu'un devra l'écrire dans l'interface, sinon un exploitant
conclura à un bogue.


## D46 — un écart expliqué cesse d'être un écart

**Le choix qui structure le lot : le lien vit du côté de la correction, pas de l'alerte.**
`AlerteEcartCaisse` se déclare immuable — aucune écriture exposée par l'API, au plus une alerte par
clôture. Un drapeau « expliquée » posé dessus aurait été **un état à maintenir** ; le lien inverse est
**un fait à constater**. C'est la correction qui affirme expliquer, l'alerte ne bouge pas.

Référence libre plutôt que clé étrangère, comme partout où l'on franchit une frontière de module dans
ce dépôt. L'écart désigné est **scellé avec le reste** : il fait partie de la justification, pas d'un
commentaire à côté. Et comme il vient du corps de la requête, il se confronte au périmètre — 404,
jamais 403 : une alerte de caisse dit combien il manque chez le voisin.

### Quatre contraintes m'ont arrêtée, et toutes avaient raison

Monter le décor du test a buté successivement sur `cloture_id` NOT NULL, sur une caisse qui refuse
une seconde session ouverte, puis sur `auteur_cloture_id` NOT NULL. **J'ai monté le décor réel à
chaque fois plutôt que de contourner**, parce que chacune dit quelque chose de vrai : un écart de
caisse est **constaté par une clôture**, jamais dans le vide, et il **nomme toujours qui a clôturé** —
un manquant sans auteur n'est pas exploitable. Un décor qui contourne les contraintes teste un
système qui n'existe pas.

### Une erreur de ma part, et ce qu'elle apprend

J'avais écrit une chaîne PHP en apostrophes simples contenant une apostrophe : syntaxe fatale. Elle
n'est apparue dans **aucun test** — elle a fait échouer le montage du schéma, ce qui donnait un
symptôme sans rapport : « la colonne est absente ». J'ai suivi ce symptôme deux fois avant de lire
l'erreur réelle.

C'est exactement ce que nous venons de nous dire sur les fixtures, à un autre étage : **suivre ce qui
casse mène au mauvais endroit**. Le symptôme visible et la cause n'étaient pas dans le même fichier,
ni dans le même domaine.
