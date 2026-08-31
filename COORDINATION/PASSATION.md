# Passation — ce que les sessions savaient et que le dépôt ne disait pas

**26/08/2026, arrêt de la flotte.** Maxime continue avec `claude-A` seul.

Ce document ne contient ni décision ni code : il contient **ce qui allait disparaître**. Le code est
dans `git`, les arbitrages dans `DECISIONS.md`. Ce qui suit est ce que trois sessions avaient compris en
manipulant le produit, et qui n'était écrit nulle part.

Chaque affirmation porte son degré de certitude. **Les doutes non levés sont signalés comme tels** — les
traiter comme des faits serait la première façon de perdre l'information.

---

## 1. La règle qui explique la journée

> **Ce dépôt ne livre pas ses défauts à la relecture. Il les livre à qui essaie de s'en servir.**
> — `claude-H`

La moitié des défauts trouvés le 26/08 l'ont été **en ouvrant un fichier pour une autre raison**.
`claude-D` sur `Compta` : *« ce n'est pas la suite qui a produit l'information, c'est quelqu'un qui
essayait de s'en servir. »* `claude-H`, deux fois dans la journée, sur des vérifications demandées par
quelqu'un d'autre.

**Conséquence pratique : brancher un écran vaut mieux qu'auditer un module.**

---

## 2. Les modules piégeux, par ordre de traîtrise

### `Stock` — le plus abouti et le plus dangereux

**Trois replis silencieux dans le même fichier** (`InventaireRegularisationHandler`,
`DisponibiliteStockHandler`) : `estSignificatif()`, `incrementer()`, `decrementer()`. **Une donnée
absente y fait taire un contrôle au lieu de le déclencher.**

- Sans `ParametrageStock`, **aucun écart d'inventaire n'est jamais significatif**, si grand soit-il —
  donc `stock.valider_ecart` ne se déclenche jamais.
- Un `ArticleStock` sans produit **ne manque jamais de stock** : la seule garde de disponibilité lui est
  inatteignable. Et il fabrique de faux écarts d'inventaire dans l'autre sens.
- **Les trois modes de périmètre d'inventaire, dont deux faux en sens inverse** :

  | Mode | Ce qui est compté |
  |---|---|
  | `Tous` | correct |
  | `Rayon` | **tout le magasin** — le filtre est stocké et jamais relu |
  | `Selection` | **rien** — `a.id IN (:ids)` avec des chaînes sur une colonne `Uuid` |

  `claude-H` n'a **pas** offert le mode `Rayon` dans l'écran. *Si quelqu'un l'offre plus tard sans
  savoir, il fera compter trois cents références au lieu de trente.*

### `Reservation` — **le premier endroit à vérifier de toute cette liste**

> *« Toutes les fois où j'ai regardé ce motif cette semaine, elles divergeaient. Si tu ne devais vérifier
> qu'une chose de cette liste, c'est celle-là. »* — `claude-G`

**`JaugeRessourceMereHandler` maintient un compteur d'occupation sur la ressource mère** — incrémenté à
la promotion d'une liste d'attente, décrémenté à l'expiration. **C'est un état dérivé, entretenu à la
main, à côté d'une jauge qui, elle, se recalcule.**

**Deux sources pour la même question.** La divergence n'a jamais été démontrée ici — elle n'a jamais été
cherchée non plus. Et c'est exactement le motif que le dépôt a puni quatre fois le 26/08.

⚠ Autre point du même module : **`OptionsDisponiblesProvider` fait un `find()` direct puis vérifie le
cloisonnement à la main.** C'est correct aujourd'hui — le contrôle a été lu. Mais c'est le motif que le
garde-fou C19 traque, et il y échappe **parce que la vérification est écrite juste en dessous**. *Le jour
où quelqu'un déplace ces lignes, il n'y aura plus rien.*

### `Compta` — ce qui saute en silence

- **La génération d'écritures saute les ventes au mapping incomplet.** Le tableau `anomalies` est
  renvoyé et n'était affiché nulle part : **des ventes réelles restaient hors comptabilité sans que rien
  ne le dise.** `claude-H` l'affiche désormais **avant** le nombre d'écritures générées — *si quelqu'un
  « simplifie » cet ordre, il remet le trou*.
- ⚠ **DOUTE NON LEVÉ, à regarder en premier** (`claude-D`) : `ClotureGuard::pointsBloquants()` ne
  contrôle que deux choses — écritures déséquilibrées, régies au-dessus du plafond. **Il ne vérifie ni
  que toutes les ventes de la période sont comptabilisées, ni qu'aucun étalement PCA ne reste en
  suspens.** On peut donc clôturer en laissant des ventes hors comptabilité, **et c'est irréversible**.
- ⚠ **DOUTE NON LEVÉ** : `GenerateurEcrituresHandler` cherche l'écriture d'origine d'un avoir par
  `venteOrigine` + `pieceExtourneDe IS NULL`. Si une vente a produit **deux** écritures d'origine — non
  vérifié —, `findOneBy` en prend une arbitrairement et **l'extourne porte sur la mauvaise**.
- `ProjectionVenteDoctrineAdapter` charge **toutes** les ventes validées du dépôt puis filtre en PHP.
  Contournement du piège `Uuid`. *Ça tiendra jusqu'au premier gros volume, et le jour où ça cède,
  personne ne comprendra pourquoi la génération met huit minutes.*

### `Vente` / `Caisse` — l'écran et le serveur ne se confrontent jamais

**Trois fois le même défaut le 26/08** : ticket affiché 10 € encaissé 15 ; panier annonçant un prix
indicatif ; réception d'achat **datée de la veille** entre minuit et deux heures.

> **Sur un document ou un envoi, ne jamais afficher une valeur que l'écran a calculée si le serveur en a
> une.** — `claude-H`

### `Sepa` — ce qui n'existe pas

- **Le module n'émet aucun événement.** Des notifications directes, pas des événements : *si quelqu'un
  veut brancher du reporting ou de la relance sur les rejets, il n'a rien à écouter.*
- **`RejetSepa` est alimenté uniquement à la main** (`POST /sepa/rejets`) — **aucun parseur pain.002.**
  En production, **les rejets bancaires réels ne remontent pas** tant que personne ne les saisit. Écrit
  dans le docblock de l'entité, nulle part ailleurs.
- ⚠ **DOUTE NON LEVÉ** : `SeqTpResolver` décide `FRST`/`RCUR` d'après `nbCollectesReussies` ; la bascule
  carte pose `paiementUnique: true` → `OOFF`. Un client avec échéancier récurrent **et** bascule
  ponctuelle produit deux séquences sur le même mandat le même jour. *« Je crois que c'est correct, je ne
  l'ai pas prouvé. »*

### `Facturation`

- ⚠ **DOUTE NON LEVÉ** : `DocumentChain::facturer()` saute les lignes livrées à zéro. **Si toutes les
  lignes sont à zéro**, la facture serait vide et probablement **scellée quand même, avec un numéro
  consommé**.
- **`CommercialDocument::estPerimeAu()` n'est appelé par personne** : aucune tâche ne fait passer un
  devis émis à `Expired`. **Un devis reste indéfiniment acceptable après sa date de validité.**

---

## 3. Où l'API ment sur ce qu'elle rend

**Un champ présent dans un groupe de sérialisation ne veut pas dire un champ lisible.**

- **`MouvementStock.imputations`** est dans `mouvement:read`, mais **aucune propriété
  d'`ImputationLotStock` ne porte ce groupe** : la collection sort en simples IRI. Il faut la charger à
  part.
- **`LigneInventaire.articleStock`** : même piège, le nom de l'article n'y est pas.
- **Les ventes antérieures à `db8990b`** portent le nom que le produit a **aujourd'hui** : le libellé
  figé n'existe qu'à partir de là. **Un duplicata n'est opposable qu'à partir de cette migration**, et
  rien à l'écran ne distingue les deux périodes.
- ⚠ **Piège de nommage à effet silencieux : `AlerteEcartCaisse.expliquee`** s'appelle ainsi et **pas**
  `estExpliquee`. Le sérialiseur n'accepte que `get`/`is`/`has`/`can`/`set`. **Si quelqu'un écrit le nom
  « logique », toutes les alertes resteront ouvertes en silence.**

---

## 4. Ce que personne n'a jamais exécuté

- **Aucun des vingt écrans livrés le 26/08 n'a tourné contre un vrai serveur.** Build vert, garde-fous
  verts, **zéro exécution**. *« Le premier qui les ouvre trouvera des choses. »*
- ⚠ **Le lien vente → mouvement de stock n'a jamais été vu s'exécuter.** Si un produit vendu n'a pas
  d'`ArticleStock` en face, **rien ne bouge, volontairement et sans trace**.
- ⚠ **Le POSS de la piscine et le seuil du musée affichent `presents` sans que sa source ait été
  vérifiée.** Si le compteur du contrôle d'accès dérive, **l'écran affirmera un chiffre de sécurité faux
  avec l'autorité du logiciel.**
- **`tests/Finance` n'a jamais rendu de verdict.** Deux tentatives, deux interruptions dues à deux
  suites en parallèle sur la même base. *« Ne conclus rien de mes messages précédents à son sujet. »*

---

## 5. Mécanismes construits et jamais appelés

| Mécanisme | État |
|---|---|
| **ED-4 — accès d'assistance** | API livrée (ouvrir / révoquer / lister), **port serveur sans adaptateur**, **aucun écran**. Sans écran, l'assistance passe par des appels HTTP à la main — donc **elle ne passera pas**, et on retombe sur l'affectation permanente que RG-ED-07 interdit. |
| **PAY-2 — bascule carte → prélèvement** | Émetteur et contrat prêts des deux côtés. **Il manque l'abonné : vingt lignes.** `CardDebitFallbackNonBrancheTest` échouera quand il existera — c'est voulu. |
| **`estPerimeAu()`** | Aucun appelant : les devis n'expirent jamais. |
| **`piscineValiderCreneauBassin`** | Créé le 26/08, jamais branché. À faire ou à retirer. |
| **`revoquerBadgeStaff`** | **Révoquer un badge est une mesure de sécurité, et personne ne peut la déclencher.** Petit et grave. |
| **`pieceCommerciale`, `padelTournois`** | Écrits pour des écrans jamais venus. |

**Journaux, pas des files** — ne pas les traiter comme des listes mortes : `rejetsSepa`,
`ventesImpayeesRegie`. Ils n'ont aucun champ de statut ; un journal qui grandit se comporte correctement.

---

## 6. Les quatre signaux muets qui comptent

Sur 23 candidats relevés par `npm run mesurer-signaux-muets` :

1. **`RosterHebdomadaire.qualificationManquanteOuExpiree`** — de loin le plus grave. Un agent planifié
   sans qualification valable, et aucun écran ne le dit. **Dans une piscine, ce sont les surveillants.**
   Un badge sur une ligne de planning suffit. *(La détection côté serveur existe désormais :
   `personnel:qualifications:verifier`, quotidienne.)*
2. **`ClassementTournoi.poules` / `matchs`** — un club qui organise un tournoi de padel **ne peut pas en
   montrer le tableau**. Fonctionnalité entière invisible.
3. **`SalleEtatLive.modeSeuil`** — le seuil du musée est affiché **sans dire ce qu'il déclenche**
   (blocage ou alerte).
4. **`TableauBordRecouvrement.tauxResolutionSelfService`** — mineur, mais c'est la seule mesure
   d'efficacité du recouvrement.

---

## 6-bis. Les dates sans fuseau : un angle mort, pas trois accidents

**Trois défauts de fuseau en une journée, à trois couches différentes** — le handler de clôture qui
bornait la journée sur l'heure du serveur, le `toISOString()` du front qui range les créneaux de nuit au
mauvais jour, et `Etablissement` qui ne portait **aucun** fuseau.

> *« Ce n'est pas une coïncidence, c'est un angle mort du dépôt : les dates sont stockées sans fuseau,
> en heure serveur, et personne n'a de raison d'y penser tant que tout le monde est à Paris. »*
> — `claude-G`

`Etablissement.fuseauHoraire` existe désormais (défaut `Europe/Paris`, setter qui refuse un identifiant
IANA inconnu). **Tout le reste du dépôt l'ignore encore.**

---

## 6-ter. Le lot `d54ed75` — à ne pas prendre pour ce qu'il n'est pas

**⚠ Ne pas fusionner en croyant le calendrier livré.**

| Dans ce commit | État |
|---|---|
| Jauge en lot (SQL + `UNHEX`) | **bon à prendre**, `tests/Reservation` 103/103 |
| Oracle de comparaison | **bon à prendre**, 4/4, **vérifié en le sabotant** — « 0 identical to 6 » |
| `ResourceOccupancy` + `OccupancyProvider` | **compilent, rien de plus** |

Il manque : l'opération sur `Ressource`, le `DateFilter` sur les deux entités d'indisponibilité, le test,
et les suites du lot complet. **Le commit le dit lui-même.**

---

## 7. La leçon de méthode, en une phrase

> **Quand tu trouves une forme inhabituelle qui a l'air d'une préférence de style, c'est presque toujours
> une cicatrice. Ne la « simplifie » pas avant d'avoir cherché pourquoi elle est là.** — `claude-D`

`ProjectionVenteDoctrineAdapter` et `PerimetreFacturationExtension` savaient tous les deux la même chose
d'important sur les identifiants `Uuid`. **L'un l'a écrit, l'autre non, et personne n'a lu ni l'un ni
l'autre** — jusqu'à ce que le même piège coûte une jauge à zéro, une remise vide et un inventaire
fantôme, le même jour.

**Et le corollaire, qui décide de la façon de travailler** (`claude-G`) :

> Les six défauts que j'ai déclarés aujourd'hui, **aucun n'a été trouvé par relecture**. Tous par un test
> qui essayait vraiment de s'en servir, ou par quelqu'un qui vérifiait ce que j'affirmais. Le seul que
> j'ai trouvé en relisant, c'est celui que j'avais écrit **dans le commentaire au-dessus de la faute**.
> **La relecture confirme ce qu'on croit ; elle ne trouve pas ce qu'on ne cherche pas.**

---

## 8. État à l'arrêt

    Opérations exposées      1 046
    Atteignables             234  (22,4 %)   —  135 ce matin
    Paquet du caissier       36,6 ko          —  54,7 ko ce matin
    Livraisons sur main      ~200 dans la journée
    Garde-fous               14
    Disque VPS               45 %             —  85 % à 16 h
