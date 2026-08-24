# Rapports de `claude-C`

> **Écrit par `claude-C` seul.** claude-A le lit, ne l'écrit jamais.
> Une ligne par battement, la plus récente **en bas**.

| Heure | Fait | En cours | Bloqué par |
|---|---|---|---|
| 12:15 | Topologie des hooks élucidée : **deux dépôts**, pas un. `--etat` dit désormais qui est couvert. **Je corrige une erreur que je t'ai transmise** (détail ci-dessous). C24/D28 livrée et vérifiée. | rien | rien |
| 13:10 | **Garde-fou de topologie livré** (ton ordre) : refuse de démarrer si les commits peuvent atteindre les refs sans barrière. Vérifié sur la flotte réelle — 8 sessions OK, `main` toléré, **`claude-G` refusé**. Lanceur 9/9, banc 17/17. | les 36 entités de la règle n°5 | rien |
| 14:05 | Règle n°5 reprise. **Vérifié que le cliquet récompense le correctif** (simulé sur `OperationScellee` : sort de la dette, plafond baisse). **Et j'ai corrigé mon propre classement** : `Utilisateur` n'est pas un référentiel, c'est une fuite de données personnelles — détail ci-dessous. | les 13 jointures restantes | les 17 référentiels attendent ton mot |
| 15:00 | **Message corrigé** (ton ordre) : le n°6 ne propose plus `--nettoyer` comme issue. J'ai aussi trouvé **sept autres messages de plafond qui ne disaient rien du tout** — même cul-de-sac, par omission. Les neuf disent maintenant la marche à suivre. Rejoué ton scénario. | les 13 jointures de la règle n°5 | les 17 référentiels attendent ton mot |
| 16:00 | **Tu n'avais pas tort de vouloir déclarer sans émettre — c'est D2.** Ma règle confondait dette anonyme et travail engagé. Livré un **registre d'attente nominatif** : hors plafond, mais affiché à chaque exécution et refusé sans preneur. Inerte par défaut, tu peux l'ignorer. | les 13 jointures de la règle n°5 | rien |
| 17:00 | **J'avais rouvert le trou que j'avais passé deux jours à fermer** : mon garde-fou de topologie est un `.sh`, or les deux filets ne globent que `.php` — retirer son appel n'aurait rien déclenché. Filet posé sur le lanceur, sans distinction d'extension, vérifié par mutation. `main` est vert (9/9). | les 13 jointures de la règle n°5 | rien |
| 18:00 | **`claude-G` est toujours sur le dépôt nu** — et tu viens de lui donner du travail dans `Reservation`. Il a commité il y a 13 min directement dans les refs. Faits vérifiés ci-dessous. **Et j'ai corrigé mon propre message**, qui exagérait le constat. Lanceur 9/9, banc 17/17. | les 13 jointures de la règle n°5 | rien |
| 19:00 | **Ma réinstallation automatique n'avait jamais servi** : `post-receive` ne voit que les push, or tu fusionnes dans `wt/main` — le reflog ne contient que des `merge`. Corrigé par `post-commit`/`post-merge` + un script partagé. Deuxième bogue trouvé au passage : `GIT_DIR` l'emporte sur `git -C`. | les 13 jointures de la règle n°5 | rien |
| 21:00 | **Ramasseur livré** (`bin/ramasser-piles-test.sh`), fondé sur l'activité InnoDB et non sur l'âge — ta date de création surestimait l'abandon **de deux jours**. Ma pile démontée : 27→26. Les 21 autres ne sont pas à moi, commande vérifiée ci-dessous. Points 2 et 3 dans `infra/`, patch fourni. | règle n°5 | rien |
| 22:00 | **`schema_filter` prouvé à l'exécution** — tu l'avais posé en écrivant qu'il ne l'était pas. Preuve avec témoin : une table non mappée sans exemption ressort en `DROP`, `messenger_messages` non. Pile montée puis **démontée** (27→27). Deux fausses preuves écartées en chemin. | garde-fou n°10 des migrations (D32) | 4 index DMS pas encore déclarés (claude-B) |
| 22:30 | **Présentation** — `claude-C`, outillage & garde-fous (`bin/`, `hooks/`, `.github/`). En ligne, périmètre non vide. | garde-fou n°10 des migrations (D32) | les 4 index DMS de claude-B, pas encore déclarés |
| 23:00 | **Présentation** — `claude-C`, outillage & garde-fous. ⚠ **D34 est inapplicable telle qu'écrite : `claude-A` n'est joignable par messagerie depuis aucune session** (constat croisé avec claude-F). Corrigé le lexique du n°1 sur son signalement — la casse. | garde-fou n°10 (D32) | les 4 index DMS |
| 23:45 | **Présentation** — `claude-C`, en ligne. La famille de défauts trouvée par claude-F est réelle et **mesurée : il n'en reste qu'un**, `reservation_regle_annulation.issue_credit_no_show`. C'est la base exacte du garde-fou n°10, bien meilleure que le tri des `DROP`. | garde-fou n°10, base de mesure enfin fiable | rien |

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


---

### 2026-08-24 · 13:10 — Garde-fou de topologie (n°0)

**Livré, et il passe avant les autres** — c'est le point : les sept contrôles ne valent que s'ils sont
*traversés*. Six sessions ont eu sept garde-fous verts et rien qui les exécute.

**L'invariant que j'ai retenu.** Tout commit atteignant les refs partagées doit franchir une barrière,
et il n'y a que deux chemins :

1. **worktree d'un clone** → les commits n'arrivent que par `git push`, donc par `pre-receive`, non
   contournable côté client. C'est le cas normal d'une session ;
2. **worktree du dépôt nu** → commiter met à jour la ref partagée immédiatement. `pre-receive` ne
   s'exécute jamais ; seul `pre-commit` peut contrôler, et il est contournable.

**J'ai dû amender ta formulation, et je te le signale plutôt que de l'appliquer en silence.** Tu
demandais de vérifier « qu'un worktree possède bien un `origin` pointant vers le dépôt nu, et refuse de
démarrer sinon ». Pris à la lettre, ce contrôle **refuse aussi `wt/main`** : ton worktree d'intégration
est légitimement sur le dépôt nu et n'a pas d'`origin`. Le cas 2 est donc **toléré pour `main` seul**,
et à la condition que `pre-commit` y soit installé — sinon il échoue aussi, avec la commande pour le
réparer. Si tu préfères la règle stricte, dis-le, mais elle t'arrêterait à chaque intégration.

**Vérifié sur la flotte réelle, pas sur des cas construits** :

| Worktree | Verdict |
|---|---|
| `claude-A/B/C/D/E/F/H/I` | OK — clone, push contrôlé par `pre-receive` |
| `main` | OK — intégration sur le nu, contrôlé par `pre-commit` |
| **`claude-G`** | **ÉCHEC** — session sur le dépôt nu, aucune barrière |

`claude-G` est exactement celle que tu signalais comme non réparée. Le garde-fou l'isole sans que
j'aie eu à lui dire où regarder.

**Et les trois chemins testés de bout en bout** sur un dépôt jetable, parce que « ça marche sur la
flotte » ne prouve que ce que la flotte contient aujourd'hui : session sur le nu → refus et arrêt du
lanceur (code 1) ; intégration sur le nu avec `pre-commit` → accepté ; clone privé d'`origin` → refus.

**Deux choix que j'assume et que tu peux renverser.**

- **Il s'arrête, il n'avertit pas.** Continuer produirait un vert qui ne protège personne — c'est ton
  raisonnement de D28 appliqué un cran plus tôt, comme tu l'écrivais.
- **Il n'est pas dans `pre-receive`.** Le hook analyse un arbre extrait dans un `mktemp`, sans remotes
  ni worktree : la question « par où passent mes commits » n'y a pas de sens. Il vit dans le lanceur,
  qui est l'endroit où une session démarre. Conséquence assumée : une session qui ne lance jamais le
  lanceur ne le voit pas — mais elle est alors couverte par `pre-receive` au push, sauf si elle est sur
  le nu, cas que ton script de flotte ne recrée plus.

**Ce que ça ne prétend pas faire** : il vérifie le *chemin*, pas la bonne foi. `--no-verify` sur
`main`, ou un `push --force` (interdit par D30), restent hors de sa portée.

**Suite** : je prends les 36 entités de la règle n°5, comme tu me l'as rendue. Rappel de leur état, il
n'a pas bougé : 13 en jointure directe (dont **`OperationScellee`**, la chaîne NF525 listable d'un
établissement à l'autre — toujours ouverte), 2 ordonnées, 18 référentiels présumés **en attente de ta
confirmation**, 3 migrations. Un mot de ta part sur les 18 fait tomber la moitié du plafond.


---

### 2026-08-24 · 14:05 — Le cliquet récompense bien le correctif, et `Utilisateur` n'est pas un référentiel

**1. J'ai vérifié que corriger fait descendre le plafond.** Avant de te demander treize corrections
d'une ligne, je devais m'assurer qu'elles se voient. Simulé dans une copie jetable — je ne touche pas
`app/src`, même pour un essai — en ajoutant `OperationScellee::class => 'pdv.etablissement'` à la table
de `PerimetreVenteExtension` :

```
Bonne nouvelle : 1 entité(s) sont désormais cloisonnées.
  - Vente/Nf525/Entity/OperationScellee.php
```

et `--nettoyer` la retire et abaisse le plafond. Les treize entrées du groupe A sont donc bien
mécaniques, et chacune se constate immédiatement. Tu peux les prendre une par une sans rien coordonner.

**2. J'ai remplacé ma présomption sur le groupe B par une mesure.** Ces dix-huit entrées attendaient un
mot de toi depuis hier, et je te demandais de confirmer une intuition — ce qui est un mauvais marché.
J'ai donc cherché la trace : un référentiel réellement global est créé **une fois**, pas une fois par
établissement. Sur les 30 fichiers de fixtures du dépôt, **aucune des dix-huit n'est créée dans une
boucle sur les établissements**. La présomption tient.

**3. Sauf pour une, et c'est la correction qui compte : `Utilisateur`.**

Je l'avais classée « référentiel global — identité plateforme ». **C'est faux, et l'erreur est de
lecture** : l'entité ne porte aucune relation, j'en ai conclu qu'elle n'était pas rattachable. Je n'ai
pas cherché plus loin. Or `Securite/Entity/Affectation` porte `(Utilisateur, Role, Etablissement)` —
le rattachement existe, il est simplement ailleurs.

**Ce que la collection expose aujourd'hui**, en `GetCollection` sous `securite.gerer`, sans aucune
extension : `email`, `nom`, `statut`, `dernierAcces`, `rolesSecurite`, `clientLie` — et **`mfaActif`**.

Ce dernier champ est celui qui me fait remonter le cas maintenant plutôt qu'en fin de lot. Ce n'est
pas une donnée personnelle de plus : c'est un **indicateur de posture de sécurité**. Il permet de
lister les comptes **sans second facteur** — de tous les établissements — puis de ne viser que
ceux-là. Les autres champs disent qui sont les gens ; celui-là dit lesquels sont les plus faciles.

**Le correctif n'est pas une ligne**, contrairement aux douze autres du groupe A : il faut une
extension avec **jointure inverse** sur `Affectation`, puisque c'est `Affectation` qui pointe vers
`Utilisateur` et non l'inverse. Je l'ai reclassée en A avec le chemin inscrit, mais je te signale la
différence pour que personne ne la prenne en croyant ajouter une entrée de table.

C'est `app/src/Securite`, donc hors de mon périmètre — je ne la corrige pas.

**Deux notes améliorées au passage**, parce que « référentiel » était paresseux :

- **`Role`** reste en B, avec la raison vérifiée : la *définition* d'un rôle est partagée, c'est son
  *attribution* qui est par établissement — et elle vit dans `Affectation`, qui est cloisonnée.
- **`Groupe`** reste en B mais ce n'est pas un référentiel : c'est une entité de **structure**,
  au-dessus de `Region`, elle-même au-dessus d'`Etablissement`. Elle est globale **par construction**,
  pas par convention. La nuance compte le jour où quelqu'un voudra la cloisonner.

**Nouvelle répartition : A=14, A2=2, B=17, C=3.** Ton mot sur les dix-sept ferait tomber le plafond de
36 à 19 — et je te le demande maintenant avec des preuves plutôt qu'une intuition.

**État** : lanceur **9/9**, banc **17/17**. Le garde-fou de topologie de ce matin n'est pas encore
fusionné ; `claude-G` reste la seule session que la topologie refuse.


---

### 2026-08-24 · 15:00 — Le mauvais conseil, et les sept qui n'en donnaient aucun

**Ton diagnostic était exact.** Le n°6 proposait `--nettoyer` pour « assumer » un événement déclaré
sans émetteur, alors que `--nettoyer` recalcule le plafond sur l'état courant : il l'aurait fait
**monter**, et le contrôle contre la référence l'aurait refusé aussitôt. Un cul-de-sac présenté comme
une issue. Corrigé — le message dit maintenant qu'un événement entre au catalogue **dans le même
commit que son émetteur**, et pourquoi `--nettoyer` n'est pas une porte de sortie.

**Et en cherchant, j'ai trouvé le même défaut en pire, sept fois.** Les messages
« plafond relevé » et « la ligne de base a grossi » des quatre cliquets ne donnaient **aucune**
indication : juste le constat et le refus. Ton cas était un mauvais conseil ; ceux-là étaient un
cul-de-sac par omission — le temps perdu est le même, et il n'y avait même pas de piste à suivre. Les
neuf messages portent désormais la même explication :

```
  Un cliquet ne monte pas — c'est exactement ce qui lui donne sa valeur.

  « --nettoyer » n'est PAS l'issue : il recalcule le plafond sur l'état courant, donc
  il le ferait monter, et le contrôle contre la référence le refuserait aussitôt. Il ne
  sert qu'à RÉSORBER un stock qui a déjà baissé.

  Les deux seules issues :
    · corriger ce qui a fait monter le compte — l'endroit exact est listé ci-dessus ;
    · si la hausse est délibérée, elle demande l'accord de l'intégrateur : le plafond
      de référence se change sur « main », pas ici.
```

**Ce que j'ai vérifié plutôt que supposé** :

- **ton scénario rejoué** — un nom ajouté au catalogue sans émetteur, dans une copie jetable : le
  nouveau message sort, et il est juste ;
- **le bloc s'affiche bien ailleurs** : je ne l'avais vu que dans le n°6, où je viens justement de le
  supprimer dans un cas. Testé sur le n°5 en abaissant son plafond — il sort correctement. Sans ce
  contrôle j'aurais livré une aide que huit garde-fous sur neuf n'affichent jamais ;
- **les neuf fichiers passent `php -l`**, lanceur **9/9**, banc **17/17**.

**Deux détails de finition, parce qu'ils comptent pour un texte qu'on lit sous pression.** Le bloc
partagé était écrit sans accents alors que tout le reste du dépôt l'est — corrigé. Et dans le n°6 les
deux messages se déclenchent presque toujours ensemble (un nom nouveau fait aussi monter le compte) :
la même explication apparaissait deux fois à trois lignes d'intervalle, ce qui la fait lire comme du
remplissage et finit par faire sauter les deux. Elle ne s'affiche plus qu'une fois.

**Ce que je retiens de ton signalement.** J'ai écrit ces messages en pensant à celui qui a le défaut
sous les yeux, pas à celui qui découvre l'outil. Toi tu connaissais le mécanisme et tu as quand même
perdu un quart d'heure — deux fois. La prochaine règle que j'écris, je testerai son message d'échec
comme je teste son déclenchement.

**Suite** : les 13 jointures du groupe A restent ouvertes, `OperationScellee` en tête. Et les 17
référentiels présumés attendent toujours ton mot — j'ai posté hier la preuve par les fixtures
(aucune n'est créée par établissement). Un mot, et le plafond passe de 36 à 19.


---

### 2026-08-24 · 16:00 — Je crois que tu avais raison, et que ma règle avait tort

Tu as écrit : *« j'avais tort de vouloir déclarer sans émettre »*. **Je ne le pense pas**, et je
préfère te le dire plutôt que d'encaisser une concession qui m'arrange.

**Mon propre fichier te donnait raison contre ma règle.** Le `_lisez_moi` de la ligne de base dit,
mot pour mot : *« Le contrat précède le code (D2) : un nom inscrit au catalogue que personne n'émet
encore est la méthode normale du projet. »* Puis la règle refuse exactement cela. J'ai écrit la
contradiction et je ne l'avais pas vue — il a fallu que tu t'y cognes deux fois.

**Ce que la règle confondait.** Deux choses très différentes tombaient dans le même compteur :

- une **dette anonyme** — un nom au catalogue, personne ne sait qui l'émettra ni quand. C'est ce que
  le cliquet doit empêcher de grossir, et c'était bien 28 noms accumulés en trois jours ;
- un **travail engagé** — `subscription.renewed` sera émis par ED-3, dans claude-D, qui l'a claimé.
  Ce n'est pas de la dette, c'est du travail en cours dont le contrat est écrit d'abord, comme D2 le
  demande.

À trois sessions, la confusion était théorique. À neuf, un événement déclaré par l'une et émis par
l'autre est le **cas normal** — tu l'as rencontré au premier essai.

**Le registre d'attente.** Un événement peut sortir du plafond, à une condition : **un émetteur
nommé**, tâche et session.

```
php bin/garde-fou-evenements-orphelins.php --attendre=subscription.renewed=ED-3/claude-D
```

Il ne devient pas invisible pour autant — c'est le point. Il s'affiche à **chaque** exécution :

```
En attente d'émetteur, avec preneur (1) — hors plafond, mais suivis :
  subscription.renewed               ED-3 (claude-D)
```

et le jour où son émetteur existe, la ligne le dit : `← émetteur livré, retire-le : --nettoyer`.

**Ce qui est refusé, et qui garde la règle honnête :** une inscription **sans preneur**. Sans cela le
registre deviendrait l'endroit où l'on range ce qu'on ne veut pas compter — c'est-à-dire une seconde
ligne de base, sans le nom. La dette anonyme reste gelée à **26** et ne bouge pas d'un pouce.

**Vérifié dans les cinq états** sur une copie jetable : déclaration sans preneur toujours refusée
(code 1, inchangé) ; inscription acceptée ; exécution suivante verte avec la ligne de suivi ;
inscription anonyme refusée (code 2, avec l'usage) ; émetteur livré → la ligne signale qu'il faut
nettoyer. Lanceur **9/9**, banc **17/17**.

**Tu peux l'ignorer sans rien casser.** Le registre est **vide** et le comportement est identique à
celui d'aujourd'hui tant que personne n'écrit `--attendre`. Si tu juges que ta conclusion tenait — que
déclarer sans émettre doit rester interdit, point — dis-le et je retire le mécanisme. C'est ta
décision de contrat, pas la mienne : je te donne l'outil qui rend les deux positions tenables, pas le
choix entre elles.

**Une remarque sur les 17 référentiels, et j'arrête d'en faire un blocage.** Je te les ai signalés
trois fois. En y regardant : les faire tomber de 36 à 19 **n'ajoute aucune protection** — le plafond
protège contre l'apparition d'une entité *nouvelle* non cloisonnée, et il le fait aussi bien à 36 qu'à
19. Ce n'était donc pas un blocage, c'était de l'esthétique de compteur, et j'ai eu tort de le
présenter comme urgent. Quand tu auras un moment.


---

### 2026-08-24 · 17:00 — Le filet que je n'avais pas mis sous mon propre garde-fou

**Le défaut, et il est de moi.** J'ai passé les 24 et 25/08 à poser des filets de complétude : dans
`pre-receive` d'abord, puis dans `pre-commit`. Les deux énumèrent `bin/garde-fou-*.php` et refusent si
l'un n'a pas été lancé.

Le garde-fou de topologie que je t'ai livré hier est un **`.sh`**. Il n'entre donc dans aucun des deux.
Et le lanceur — le **seul** endroit où il s'exécute — n'avait pas de filet du tout. **Retirer son appel
de `bin/garde-fous.sh` n'aurait rien déclenché nulle part.** C'est exactement la classe de défaut que
je venais de corriger deux fois, réintroduite le lendemain par un choix d'extension.

**Corrigé** : `bin/garde-fous.sh` porte maintenant son propre filet, et il globe `bin/garde-fou-*`
**sans distinction d'extension** — un garde-fou est un garde-fou, quel que soit le langage. Le nom du
script est relevé dans les arguments réellement passés à `executer`, pas dans le libellé : le libellé
est décoratif, le chemin ne ment pas.

**Vérifié par mutation, dans les deux sens**, sur une copie jetable :

- appel de la topologie retiré → `✗ Garde-fou présent dans bin/ mais jamais lancé : garde-fou-topologie.sh` ;
- garde-fou fantôme ajouté sans appel → même refus sur son nom.

**Et j'ai inscrit pourquoi les hooks, eux, gardent le glob `.php`** — parce que sans cette note la
différence ressemble à un oubli, et quelqu'un l'« harmoniserait ». La topologie répond à « par où
passent mes commits » : dans un hook, la question n'a pas de sens, on analyse un arbre extrait dans un
`mktemp`, sans remote ni worktree. Exiger son exécution là ferait échouer toutes les poussées.

**Ce que j'en tire, et c'est la deuxième fois cette semaine.** Mes filets protègent ce que j'ai pensé à
protéger. J'ai vérifié que le garde-fou de topologie *fonctionne* — trois chemins testés, la flotte
entière passée en revue — sans vérifier qu'il *sera lancé*. C'est la distinction exacte que le filet
de `pre-receive` existe pour porter, et je ne l'ai pas appliquée à ma propre livraison.

**Vérifications de routine** : `main` est vert, **9/9**, je l'ai relancé chez toi. Banc **17/17**.
Aucun empiètement sur `bin/`, `hooks/` ou `.github/`.

**En attente chez toi, sans urgence** : le registre d'attente nominatif proposé à 16:00 — il est inerte
tant que personne n'écrit `--attendre`, donc rien ne presse. Et les 13 jointures du groupe A, dont
`OperationScellee`.


---

### 2026-08-25 · 18:00 — `claude-G` code dans `Reservation` depuis le dépôt nu

**Le fait d'abord, parce qu'il est daté.** Tu as débloqué `claude-G` sur ACT-1 et il a repris : commit
`63085f6` il y a treize minutes, et **des modifications non commitées** dans
`Reservation/Entity/Reservation.php`, `Entity/ListeAttente.php`, `Service/JaugeCreneauGuard.php`,
`Service/JaugeRessourceMereHandler.php`.

Or sa topologie n'a pas été réparée : worktree du **dépôt nu**, sans `origin`. Son commit de tout à
l'heure est **déjà dans les refs partagées** — vérifié, `git branch --contains` sur le nu le renvoie —
sans être passé par un push, donc sans `pre-receive`.

**Ce qui n'est PAS vrai, et que mon garde-fou affirmait à tort.** Mon message disait *« cette session
écrit sans franchir aucune barrière »*. **Faux.** `pre-commit` est installé sur le dépôt nu depuis que
tu l'as posé, et `claude-G` en est un worktree : je l'ai exécuté depuis chez lui comme git le lance —
**7 garde-fous, code 0, pas d'interrupteur**. Il est couvert.

**Ce qui reste vrai, et qui justifie quand même le refus** :

- `pre-commit` **se contourne** — `git commit --no-verify` suffit — là où `pre-receive` ne se contourne
  pas ; c'est toute la différence entre un filet et une barrière ;
- il ne lance **ni la topologie ni le manifeste** (7 sur 9) ;
- et les cliquets tournent sans `--contre` : le plafond de référence sur `main` n'est jamais opposé à
  son travail, donc un plafond relevé chez lui passerait inaperçu.

**J'ai corrigé le message.** Il distingue maintenant les deux cas et dit lequel s'applique : avec
`pre-commit` installé, *« cette session contourne la seule barrière non contournable »* et l'explication
qui va avec ; sans, *« RIEN ne le remplace »*. Vérifié dans les deux états — sur `claude-G` réel, et sur
un dépôt nu jetable dont j'ai retiré le hook.

C'est la leçon que tu m'as donnée hier sur les messages qui mènent dans un mur, appliquée à un message
qui **exagérait**. Un garde-fou qui surestime son constat use la confiance exactement comme celui qui
donne un mauvais conseil — et celui-ci criait « aucune barrière » alors qu'il y en avait une.

**Ce que ça te demande, et c'est court.** Recréer le worktree de `claude-G` depuis le clone
(`/home/debian/billetterie/.git`), comme les huit autres. Rien n'est perdu : sa branche `claude-G` est
déjà dans le dépôt, il lui suffit de commiter son travail en cours avant. Tant que ce n'est pas fait,
`ACT-1` avance sur du code de `Reservation` — jauges et liste d'attente — sans que la barrière
non contournable ne le voie jamais.

**Je ne le fais pas moi-même** : c'est le worktree d'une autre session, avec des modifications non
commitées dedans. Une seule session par worktree (D30), et je ne touche pas au travail en vol de
quelqu'un d'autre.

**État** : lanceur **9/9**, banc **17/17**, `main` vert. Aucun empiètement sur mon périmètre.


---

### 2026-08-25 · 19:00 — Le mécanisme qui n'avait jamais tourné, et l'avertissement qui l'a dit

**C'est ma propre poussée qui a levé le lièvre.** En poussant le rapport de 18:00, le hook a affiché
l'avertissement d'obsolescence : *« LE HOOK INSTALLÉ N'EST PAS CELUI DE main »*. Or `post-receive`
était censé le réinstaller tout seul depuis hier.

**Pourquoi il ne l'a jamais fait.** `main` change de deux façons : par **push**, et par **merge dans
`wt/main`**. `post-receive` ne s'exécute qu'à la **réception**. J'ai regardé le reflog de `main` :

```
4fb2184 main@{0}: merge claude-C: Merge made by the 'ort' strategy.
9a3b78c main@{1}: merge claude-A: Merge made by the 'ort' strategy.
968a700 main@{2}: merge claude-C: Merge made by the 'ort' strategy.
```

**Que des `merge`.** Le chemin que je couvrais n'est jamais emprunté ; celui que tu utilises n'était
pas couvert. Le mécanisme était inerte depuis sa livraison — et c'est l'**avertissement**, que j'avais
gardé « comme filet », qui a rattrapé la défaillance du dispositif qu'il doublait. Je note que sans
lui, personne n'aurait rien vu.

**La correction, et elle évite de refaire la même erreur une quatrième fois.** La logique de
réinstallation vit maintenant dans **un seul** fichier, `bin/reinstaller-hooks.sh`, appelé par trois
déclencheurs : `post-receive` (push), `post-commit` et `post-merge` (ton chemin). Trois portes, un
seul énoncé — la leçon des listes redites qui se désynchronisent, appliquée avant qu'elle ne se
répète. `post-receive` est devenu mince : n'ayant pas d'arbre de travail, il extrait le script depuis
`main` et l'exécute.

**Et j'ai trouvé un second bogue en testant, celui-là bien caché.** Ma première version ne
fonctionnait toujours pas, alors que le script lancé à la main marchait. La cause : **dans un hook,
git exporte `GIT_DIR` — et l'environnement l'emporte sur `git -C` et `--git-dir`.** Mon test « ce
dépôt commun est-il nu ? » interrogeait donc le répertoire du worktree, répondait « false », et le
script **sortait en silence**. Réinstallation jamais faite, aucun message, aucune trace. Corrigé en
relevant le chemin d'abord, puis en nettoyant l'environnement.

C'est la troisième fois cette semaine qu'un de mes mécanismes est inerte sans le dire. Le motif est
toujours le même : je vérifie qu'une chose *fonctionne quand je la lance*, pas qu'elle *sera lancée
dans les conditions réelles*. Ici la différence tenait à une variable d'environnement que seul git
positionne.

**Vérifié dans les deux chemins**, sur un dépôt nu jetable avec son `wt/main` : un **commit** dans
`wt/main` réinstalle ; un **merge** dans `wt/main` réinstalle. Marqueur retrouvé dans le hook installé
les deux fois. Lanceur **9/9**, banc **17/17**.

**Un geste pour toi, une fois** : `post-commit` et `post-merge` n'existent pas encore sur le dépôt nu,
donc ils ne peuvent pas s'installer eux-mêmes — même amorçage que la dernière fois. Après fusion :

```
bash bin/installer-hooks.sh /home/debian/billetterie.git
```

Il pose désormais les trois hooks automatiques d'un coup, et `--etat` les liste tous.

**Et `claude-G` reste sur le dépôt nu** — signalé à 18:00, rien n'a changé. Il code dans `Reservation`
(jauges, liste d'attente) avec des modifications non commitées. Ce n'est pas urgent au sens où
`pre-commit` le couvre, mais c'est la seule session dont le travail n'est jamais opposé aux cliquets
de `main`.


---

### 2026-08-25 · 21:00 — Le ramasseur, et pourquoi l'âge ne suffisait pas

**Point 1 livré : `bin/ramasser-piles-test.sh`.** Simulation par défaut, `--faire` pour agir,
`--age=N` (défaut 6 h), `--moi=<jeton>` pour se limiter aux siennes. Il passe par
`infra/test-stack.sh down` plutôt que par `docker rm` : c'est lui qui sait ce qu'une pile comporte, et
une seconde implémentation finirait par diverger.

**Ta crainte était la bonne, et l'âge n'y répondait pas.** Tu écrivais ne rien supprimer parce qu'une
pile tuée sous une session qui teste lui coûte son verdict. L'âge de création ne dit rien de l'usage —
une pile de cinq jours peut avoir servi il y a dix minutes. Le ramasseur lit donc le **mtime des
fichiers InnoDB**, que le moteur touche quand la suite travaille.

L'écart n'est pas théorique :

| Pile | Selon la création | Activité réelle |
|---|---|---|
| `claudeC` | 104 h | **49 h** |
| `claudeA2` | 122 h | **69 h** |
| `claudeA` | 125 h | **103 h** |

La date de création surestimait l'abandon de **deux jours** sur trois piles. Un ramasseur fondé
dessus aurait été juste par accident.

**Deux défauts de mon propre script, trouvés en lisant sa sortie plutôt qu'en la croyant.** Ma
première version affichait « (création) » sur les vingt-deux lignes — donc mon signal d'activité ne
marchait pas et je retombais **en silence** sur le critère faible que je venais d'annoncer vouloir
éviter. Cause : `docker logs ... 2>/dev/null`, or **MariaDB journalise sur stderr** — je jetais le
signal. Et même corrigé, il ne valait rien ici : la dernière ligne de MariaDB date du **démarrage**,
elle ne distingue pas une pile utilisée d'une pile oubliée. D'où la sonde InnoDB, et une source
affichée à chaque ligne — un verdict rendu sur la source faible ne vaut pas celui rendu sur la bonne.

**Ce que j'ai fait, et ce que je n'ai pas fait.** J'ai démonté **la mienne** — `claudeC`, inactive
49 h. **27 → 26 réseaux**, cinq créneaux. Je n'ai pas touché aux vingt et une autres : elles ne sont
pas à moi, tu as choisi de ne pas les supprimer, et je ne renverse pas ce choix — je te donne
seulement la mesure qui te manquait. **Aucune des 22 n'a été touchée depuis moins de 8 h**, mesuré,
pas supposé. Quand tu veux :

```
bash bin/ramasser-piles-test.sh --age=8          # vérifie
bash bin/ramasser-piles-test.sh --age=8 --faire  # ramasse
```

---

**Points 2 et 3 : ils sont dans `infra/test-stack.sh`, hors de mon périmètre.** Je ne l'édite pas.
Voici les deux morceaux, prêts à coller — à toi ou à qui tu l'assignes.

**Avertissement au montage**, juste après `case "$ACTION" in` / `up)` :

```sh
    # Le pool d'adresses par défaut permet ~31 réseaux. En dessous de cinq, la panne est proche — et
    # elle se manifeste par une erreur Docker incompréhensible en plein milieu d'une suite.
    RESTANTS=$(( 31 - $(docker network ls -q | wc -l) ))
    if [ "$RESTANTS" -lt 5 ]; then
        echo "⚠ Il reste ~$RESTANTS réseaux Docker sur 31." >&2
        echo "⚠ Ramasse les piles oubliées avant de monter : bin/ramasser-piles-test.sh --age=8" >&2
    fi
```

**Préfixe d'identité obligatoire**, dans le contrôle d'usage :

```sh
case "$TOKEN" in
    claude[A-I]*) ;;
    *)
        echo "✗ Le jeton doit commencer par l'identité de la session (ex. claudeC-cq5)." >&2
        echo "  « N12T », « SOIR », « GL » ne disent pas qui doit démonter — c'est ainsi qu'on" >&2
        echo "  se retrouve à 27 réseaux sans savoir à qui parler." >&2
        exit 2
        ;;
esac
```

Le préfixe change tout pour le ramasseur : aujourd'hui il ne peut que mesurer l'inactivité ; avec lui,
`--moi=` devient utilisable par chacun et personne n'a besoin de trancher sur la pile d'un autre.

**Sur ta dernière phrase.** Tu écris que c'est le troisième mécanisme de la journée qui existe sans
tourner. C'est le quatrième : entre-temps j'ai trouvé que ma **réinstallation automatique des hooks**
n'avait jamais tourné non plus — `post-receive` ne voit que les push, et ton reflog ne contient que
des `merge`. Corrigé ce soir. Le motif est le même à chaque fois, et il vaut pour moi autant que pour
toi : on vérifie qu'une chose *fonctionne quand on la lance*, jamais qu'elle *sera lancée*.


---

### 2026-08-25 · 22:00 — `schema_filter` fonctionne, et voici pourquoi je peux le dire

Tu as écrit, dans le commit qui le pose : *« pas prouvé à l'exécution »*, plutôt que de le déclarer
fonctionnel. C'est exactement la bonne façon de livrer, et c'est aussi une invitation — un mécanisme
non vérifié attend quelqu'un pour le vérifier. Je l'ai fait.

**Le résultat : il fonctionne.** Preuve avec témoin, sur une pile montée pour l'occasion :

| Table en base, non mappée | Exemptée ? | Ce que propose `schema:update --dump-sql --complete` |
|---|---|---|
| `zz_temoin_claudec` | non | `DROP TABLE zz_temoin_claudec;` |
| `messenger_messages` | oui, par `schema_filter` | **rien** |

Le témoin est ce qui rend la preuve valable : il montre que le mécanisme **propose bel et bien des
suppressions** dans ces conditions. Sans lui, l'absence de `messenger_messages` aurait pu venir de
n'importe quoi.

**Deux fausses preuves écartées en chemin, et je les note parce qu'elles étaient convaincantes.**

1. **Premier essai : « 0 occurrence de `messenger_messages` ».** Je l'ai presque rapporté comme une
   preuve. En réalité `infra/test-stack.sh run` lance **PHPUnit**, pas une commande arbitraire : ma
   console ne s'était jamais exécutée, la sortie disait `Test file "php" not found`. Un zéro obtenu
   parce que rien n'a tourné.
2. **Deuxième essai : « Nothing to update ».** Vrai, mais sans valeur : `doctrine:schema:update` **ne
   supprime pas de tables** sans `--complete`. Le silence ne venait pas du filtre, il venait de la
   prudence de la commande.

C'est le même motif que ce que tu as trouvé toi-même dans les migrations : une sortie qu'on lit comme
une réponse alors qu'elle répond à une autre question. Deux fois de suite ici, sur une vérification que
j'avais entreprise **précisément** pour ne pas commettre cette faute.

**Coût en réseaux : nul.** Pile montée, utilisée, **démontée** dans le même tour — 27 réseaux avant,
27 après. C'est ce que le ramasseur d'hier soir sert à rendre normal.

---

**Ce que ça change pour le garde-fou n°10 que tu m'as demandé.** Ta simplification tient : avec
`schema_filter` prouvé, `messenger_messages` **n'a plus besoin d'être une exception du garde-fou** —
elle ne remonte plus dans les diffs, donc il n'a rien à connaître d'elle. Restent :

- **le `FULLTEXT` `support_ft_article_recherche`** — irréductible, non exprimable en mapping ORM, ce
  sera l'exception unique que tu annonçais ;
- **les quatre index DMS** confiés à claude-B, encore non déclarés. Tant qu'ils ne le sont pas, ils
  ressortiront en `DROP INDEX` chez tout le monde et le garde-fou devrait les geler — ce qui ferait
  une ligne de base de cinq exceptions au lieu d'une.

**Je préfère donc attendre les quatre index avant de figer la ligne de base**, sinon je scelle une
dette qui va disparaître d'elle-même dans les heures qui viennent, et le plafond ne redescendra que
par un `--nettoyer` que personne ne pensera à lancer. Dis-moi si tu préfères que je le livre tout de
suite avec les cinq.

**Vérifié aussi, sans rapport avec ce qui précède :** `#[ORM\UniqueConstraint(name:
'uniq_article_aide_cle_import')]` et les deux index Compta que tu as posés sont bien dans le mapping.

**Un détail dans ton fichier d'ordres**, puisque tu es le seul à pouvoir le corriger : la section
« Le garde-fou de topologie est écrit » apparaît **deux fois** dans `ORDRES/claude-C.md`, et la
première s'interrompt au milieu d'une phrase — *« Ce que ça a coûté aujourd'hui, concrètement : est un
worktree du dépôt »*. Rien de grave, mais la phrase tronquée portait un constat que je n'ai jamais lu.


---

### 2026-08-25 · 22:30 — Présentation, et nouvelle règle de cadence

**`claude-C`, en ligne.** Périmètre : `bin/**`, `hooks/**`, `.github/**` — outillage et garde-fous.

**Maxime m'impose une règle supplémentaire, à partir de maintenant** : je me présente à toi **à chaque
battement, sans exception**. Si j'ai une tâche en cours, je te dis laquelle ; si je n'en ai pas, **je
t'en demande une**. Le silence n'était déjà pas une information (D25, D29) ; désormais l'absence de
présentation n'en est pas une non plus.

Concrètement, chaque ligne de ce tableau commencera par où j'en suis, même quand la réponse est
« rien de neuf ».

**Ce que je fais en ce moment** — je n'ai donc pas de tâche à te demander :

1. **Garde-fou n°10, migrations (D32)** — ta demande. Je l'ai instruit et j'attends volontairement les
   **quatre index DMS** de claude-B avant de figer la ligne de base : les sceller maintenant
   reviendrait à graver une dette qui disparaîtra d'elle-même, et le plafond ne redescendrait que par
   un `--nettoyer` que personne ne penserait à lancer. Si tu préfères que je livre tout de suite avec
   les cinq exceptions, dis-le et je le fais — c'est ton arbitrage, pas le mien.
2. **Les 13 jointures du groupe A** (règle n°5) restent ouvertes, `OperationScellee` en tête — la
   chaîne NF525 toujours listable d'un établissement à l'autre. Elles sont dans `app/src`, donc je ne
   peux que te les préparer, pas les appliquer.

**Si tu veux me réaffecter**, mon périmètre a de la place : les deux verdicts « sans objet » (i18n,
CSRF) tiennent toujours, et rien d'autre n'attend dans `bin/`, `hooks/` ou `.github/`.

**Deux choses en attente chez toi, sans urgence**, que je rappelle une fois puis que je laisse :

- le **registre d'attente nominatif** du garde-fou n°6 (`--attendre=<evenement>=<tache>/<session>`) —
  inerte tant que personne ne l'utilise, à valider ou à retirer ;
- les **17 référentiels présumés globaux**, dont j'ai admis qu'ils ne bloquaient rien : le plafond
  protège aussi bien à 36 qu'à 19.


---

### 2026-08-25 · 23:00 — Présentation, et D34 ne peut pas être appliquée telle qu'elle est écrite

**`claude-C`, en ligne.** Périmètre `bin/**`, `hooks/**`, `.github/**`. Remote Control actif et il le
reste.

**⚠ Le point qui prime sur le reste : je n'ai pas pu me présenter à toi.**

D34 demande de lister ses pairs, de trouver `claude-A` — *« elle s'affiche sous un nom générique,
écris-lui quand même »* — et de lui écrire avant de lire ses ordres. J'ai suivi la consigne à la
lettre. `ListAgents` ne remonte **qu'un seul pair**, affiché `F`. Je lui ai écrit, en précisant que si
elle n'était pas toi elle devait me le dire plutôt que de me laisser polluer sa session.

**Elle m'a répondu : c'est `claude-F`, hébergement/restauration/séjour.** Et elle ajoute un constat que
je n'aurais pas pu faire seul : **de son côté aussi, le seul pair visible est moi.** Nous sommes donc
deux sessions, chacune ne voyant que l'autre, et **aucune ne voit `claude-A`**.

Ce n'est pas une négligence de notre part : c'est que le canal n'existe pas. Ta consigne suppose que
ta session est listée par `ListAgents` chez les autres ; elle ne l'est pas. **Tant que ce n'est pas
réparé, D34 ne peut pas être exécutée**, et une session qui la suit à la lettre s'arrêtera en croyant
avoir manqué quelque chose.

Deux constats indépendants — claude-F le signale dans son propre rapport, sans que ni elle ni moi
n'écrivions dans le fichier de l'autre. **C'est pour toi, pas pour nous** : nous n'avons aucun moyen
d'agir dessus.

**Ce que je fais en attendant** — je n'ai donc pas de tâche à te demander :

1. **Garde-fou n°10 (D32)**, ta demande — instruit, j'attends volontairement les quatre index DMS de
   claude-B avant de figer la ligne de base. Ton arbitrage si tu préfères les cinq exceptions tout de
   suite.
2. **Les 13 jointures du groupe A** restent ouvertes, `OperationScellee` en tête.

---

### Un correctif né d'un signalement de claude-F, et il était plus large que son cas

Son contrôle de périmètre s'appelait `$this->garde->verify(...)` — propriété française, méthode
anglaise. Mon garde-fou ne l'a pas vu **alors que le contrôle existait**. Elle a renommé en
`$scopeGuard`, c'est passé, et elle a eu la rigueur de préciser que ce n'était **pas un faux positif à
corriger** mais un écart de vocabulaire : D5 pousse le code neuf vers l'anglais pendant que mon lexique
de détection est resté français.

**En regardant, j'ai trouvé pire que son cas.** Le motif était `/Verificateur|Guard|verifierAcces/` —
**sensible à la casse**. Donc `$this->guard` en minuscules, le nom anglais le plus naturel, échouait
lui aussi. Son `$scopeGuard` est passé grâce à la majuscule, **par chance et non par conception**.
Corrigé en `[Gg]uard` : sans risque de collision, `guard` n'est pas un mot français et ni `garde` ni
`regarde` ne le contiennent.

**Et le vrai défaut était dans le message.** Il disait *« si ton contrôle passe par une forme que ce
garde-fou ne sait pas lire »* — sans jamais dire **lesquelles il sait lire**. claude-F a donc dû
deviner en renommant au hasard. La liste y figure maintenant, avec son cas nommé et daté dedans. C'est
le troisième message d'échec que je corrige cette semaine, après ceux que tu m'avais signalés.

**Ce que je n'ai pas fait, et pourquoi.** Je n'ai **pas** ajouté `verify` aux formes reconnues : le
même nom sert à vérifier une signature, un jeton ou un mot de passe. Le compter comme un contrôle de
périmètre masquerait de vrais défauts partout ailleurs. C'est écrit dans le message pour que le
prochain ne le redemande pas. L'échappatoire reste `@cloisonnement-verifie : <raison>` — greppable,
datée, attribuable, ce qu'un assouplissement de détection n'est jamais.

**Vérifié après coup**, parce qu'élargir une détection peut masquer sans qu'on le voie : la dette
gelée est restée **exactement** à 37 et 14. Aucune entrée n'est devenue « contrôlée » en silence.
Lanceur **9/9**, banc **17/17**.

**Rappel, une dernière fois puis je laisse** : la section « Le garde-fou de topologie est écrit » de
`ORDRES/claude-C.md` apparaît deux fois et la première s'arrête au milieu d'une phrase. Tu es seul à
pouvoir l'écrire.


---

### 2026-08-25 · 23:45 — La famille des `DEFAULT` non déclarés : mesurée, et il n'en reste qu'un

**`claude-C`, en ligne.** Remote Control actif. Rappel du point qui prime : **D34 reste inapplicable**,
`claude-A` n'est joignable par messagerie depuis aucune des deux sessions qui se voient.

**claude-F a trouvé la bonne base pour ton garde-fou n°10, et ce n'est pas celle que tu m'avais
suggérée.** Elle a vérifié sa migration sur une base repartie de zéro et découvert un motif : un
`DEFAULT` posé en migration, absent du mapping ORM. La colonne ressort alors en `CHANGE` dans le diff
de **chaque** session, éternellement — troisième cause structurelle de D32, que personne n'avait
nommée.

C'est mécanique et sans faux positif plausible, contrairement au tri des `DROP` que j'allais coder.

**J'ai mesuré la famille au lieu de la croire, et il a fallu trois versions pour que la mesure vaille
quelque chose.** Je les note parce que les deux premières étaient présentables :

- **v1 — 167 écarts sur 171.** Ce n'était pas une trouvaille, c'était mon analyseur : je cherchais le
  bloc d'attributs avec un motif qui s'arrête au premier `]`, or les attributs PHP en contiennent
  (`options: ['default' => 1]`). J'ai failli livrer un audit de 167 défauts inexistants.
- **v2 — 3 écarts.** Crédible, presque juste… et **faux dans les deux sens**. Il signalait
  `dms_document`, `dms_document_public_link` et `stay_stay`, tous **déjà corrigés** entre-temps ; et il
  **manquait le seul vrai**, que j'avais pourtant vérifié à la main dix minutes plus tôt. Cause : mon
  motif `ALTER TABLE …[^;']*` **s'arrête au premier apostrophe**, donc tout `DEFAULT 'chaîne'` posé par
  un `ALTER` était invisible. Un faux négatif dans un garde-fou vaut moins que rien : il rassure.
- **v3 — 1 écart, et c'est le bon.** On extrait l'argument complet de chaque `addSql` avant de
  l'analyser, et la fenêtre de lecture du mapping est bornée au point-virgule précédent — le bloc
  d'attributs de *cette* propriété, pas de sa voisine.

**Le résultat, sur 142 `DEFAULT` posés par les migrations :**

| | |
|---|---|
| sans entité mappée (ignorés) | 4 |
| propriété introuvable | 1 |
| **écart réel** | **1** |

```
reservation_regle_annulation . issue_credit_no_show   DEFAULT 'restored_with_reschedule'
```

`RegleAnnulation` déclare `#[ORM\Column(length: 24, enumType: IssueCreditNoShow::class)]` — **sans
`options: ['default' => …]`**. C'est la colonne de CQ-5, donc `app/src/Reservation` : pas mon
périmètre, je ne la corrige pas. **Une ligne suffit**, et elle retire une ligne du diff de toute la
flotte.

**Les trois autres sont déjà tombées** pendant que je mesurais : claude-F a corrigé `stay_stay`, et les
deux DMS ont disparu du relevé. La famille était de quatre, il en reste un.

**Ce que ça donne pour le garde-fou n°10.** Une ligne de base de **1**, ce qui est l'état idéal pour
figer un cliquet : presque rien à geler, et tout nouveau `DEFAULT` non déclaré refusé d'emblée. Je le
livre au prochain tour sur cette base plutôt que sur le tri des `DROP` — sauf si tu préfères l'inverse.

**Deux confirmations indépendantes de claude-F, depuis sa base et non la mienne** : `messenger_messages`
n'apparaît pas dans son diff — ton `schema_filter` tient donc sur deux bases distinctes — et
`DROP INDEX support_ft_article_recherche` ressort bien seul, ce qui confirme que le `FULLTEXT` est
l'exception unique, comme tu l'annonçais.

Elle signale aussi une quinzaine de `RENAME INDEX` de dette nommée à la main dans Finance, Compta et
DMS, vus sur une base propre — ce n'est ni son périmètre ni le mien.
