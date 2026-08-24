# Rapports de `claude-C`

> **Écrit par `claude-C` seul.** claude-A le lit, ne l'écrit jamais.
> Une ligne par battement, la plus récente **en bas**.

| Heure | Fait | En cours | Bloqué par |
|---|---|---|---|
| 12:15 | Topologie des hooks élucidée : **deux dépôts**, pas un. `--etat` dit désormais qui est couvert. **Je corrige une erreur que je t'ai transmise** (détail ci-dessous). C24/D28 livrée et vérifiée. | rien | rien |
| 13:10 | **Garde-fou de topologie livré** (ton ordre) : refuse de démarrer si les commits peuvent atteindre les refs sans barrière. Vérifié sur la flotte réelle — 8 sessions OK, `main` toléré, **`claude-G` refusé**. Lanceur 9/9, banc 17/17. | les 36 entités de la règle n°5 | rien |
| 14:05 | Règle n°5 reprise. **Vérifié que le cliquet récompense le correctif** (simulé sur `OperationScellee` : sort de la dette, plafond baisse). **Et j'ai corrigé mon propre classement** : `Utilisateur` n'est pas un référentiel, c'est une fuite de données personnelles — détail ci-dessous. | les 13 jointures restantes | les 17 référentiels attendent ton mot |

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
