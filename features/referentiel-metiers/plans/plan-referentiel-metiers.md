# Plan — référentiel-metiers

**Statut :** proposé (CP-2)
**Spec de référence :** `features/referentiel-metiers/specs/spec-referentiel-metiers.md` (validée CP-1)
**Faits :** `features/referentiel-metiers/refs/faits-etablis.md`
**Branche :** `feature/referentiel-metiers`

> **Convention de preuve.** Chaque affirmation sur l'existant porte VERIFIED si le fichier a été
> ouvert sur le VPS pendant la rédaction, UNVERIFIED sinon. Aucune écriture n'a été faite.

---

## 0. Ce que la lecture du dépôt impose au plan

| # | Fait | Preuve | Conséquence |
|---|---|---|---|
| C-1 | **Aucun garde-fou n'ouvre de base de données.** | VERIFIED — `grep -l 'PDO\|mysqli\|getConnection' bin/*.php` → vide | G-6 ne peut pas comparer l'énumération à des **lignes**. Il compare à la **liste de repli en code**, qui est aussi la source de la commande. §5 |
| C-2 | `SiteBlocks::all()` est **statique** et s'annonce « lisible sans conteneur ». | VERIFIED — `SiteBlocks.php:161` | Un 6e métier créé en base n'aurait **aucun bloc de corps déclaré**, et l'enregistrement lèverait `UnknownBlockException`. G-5 tombe sans traitement. §3.5 |
| C-3 | `MetierCatalog` n'a que **trois appelants**. | VERIFIED | La bascule est chirurgicale ; la forme du tableau rendu est le contrat avec les gabarits. |
| C-4 | `website:blocks:seed` **n'est pas appelée par `deploy-preprod.sh`**. | VERIFIED | Une commande non câblée ne tourne jamais et le repli sert **sans rien dire**. §4 |
| C-5 | La panne du 06/09 est documentée dans le code : une énumération grossie sans sa table. | VERIFIED — `CatalogueCapacites.php:263-267` | Classe de risque n°1 du lot. §5, §7, §8 |

---

## 1. Le modèle

### 1.1 `App\Website\Entity\Trade` → `website_trade`

| Colonne | Type | G-N | Justification |
|---|---|---|---|
| `id` | `BINARY(16)`, `Uuid::v7()` | — | v7 comme `ApiCredential`, l'entité la plus récente : ordonné, index primaire contigu. |
| `code` | `VARCHAR(64)` UNIQUE | G-6, G-1 | Clé technique **immuable**, porte la correspondance avec `Metier::cases()` et compose `metier.<code>.body`. ⚠ **Longueur calculée** : `ContentBlock.block_key` est `VARCHAR(80)` et la clé fait `12 + strlen(code)`. À 80 on tronquerait en silence. |
| `slug` | `VARCHAR(120)` UNIQUE | G-4, G-1 | L'URL. Séparé de `code` : un slug publié est une promesse tenue par quelqu'un d'autre (l'argument existe déjà sur `BlogPost`). |
| `name` | `VARCHAR(160)` | G-1 | Libellé de menu. |
| `searchTitle` | `VARCHAR(200)` | G-1 | Titre de recherche — le docblock dit pourquoi les deux diffèrent. |
| `lead` | `TEXT` | G-1 | Chapô. `TEXT` car le gabarit le tronque à 300 pour la meta description : pas de borne métier. |
| `position` | `SMALLINT` | G-1 | ⚠ **Le tri actuel est l'ordre de déclaration de `Metier`**, ni alphabétique ni arbitraire. La commande pose 10..50 dans cet ordre. Condition de G-4. |
| `status` | `VARCHAR(12)`, `PublicationStatus`, défaut `draft` | G-1, G-5 | On **réutilise** l'énumération existante. Sans `publishedAt` : personne n'a demandé de programmer l'ouverture d'une page, et un champ sans usage se remplit de travers. |

Index : `UNIQUE(code)`, `UNIQUE(slug)`, `INDEX(status, position)` — la seule lecture publique.

### 1.2 `App\Website\Entity\TradeActivity` → `website_trade_activity`

`trade_id` (FK `ON DELETE CASCADE` — une activité n'existe pas hors de son métier), `activity`
(`VARCHAR(32)`, `enumType: ActivityType`), `position`. Contrainte `UNIQUE(trade_id, activity)` :
**c'est la structure qui interdit le doublon**, pas une vérification applicative.

**Pourquoi une table fille et pas du JSON**, dans l'ordre :
1. `enumType` refuse **dans les deux sens** — écriture et hydratation. Le JSON n'échoue qu'où
   quelqu'un a pensé à valider, c'est-à-dire chez le premier écrivain, jamais chez le second.
2. La question du tunnel est l'inverse : « quels métiers portent `equipment_rental` ? ».
   Colonne fille = `WHERE` indexé ; JSON = balayage.
3. `UNIQUE(trade_id, activity)` n'a pas d'équivalent JSON.

### 1.3 `ActivityType` — les neuf de D15

`entry`, `resource_booking`, `membership`, `equipment_rental`, `product_sale`, `coaching`,
`appointment`, `lodging`, `dining`.

Quatre orthographes **reprises telles quelles** de `paquet.md` (VERIFIED) ; cinq proposées par la
spec. ⚠ `lodging` et `dining` sont **aussi** des valeurs de `CapaciteCode` : pas une collision
technique, mais une collision de **lecture**. Le garde-fou lit donc les **cas** (`case Lodging`),
jamais les valeurs nues.

**Où elle vit : `App\Fonctionnalite\Enum`**, à côté de `Metier` et `CapaciteCode` — c'est un
vocabulaire du **produit**, que le tunnel consommera. La mettre dans `App\Website` obligerait le
tunnel à dépendre de la vitrine, dépendance inverse de celle qui existe.
**UNVERIFIED — périmètre : `.github/CODEOWNERS` est ABSENT du dépôt.** Impossible de prouver que ce
dossier appartient à quelqu'un d'autre. **À trancher avant l'étape 1.**

### 1.4 Ce qui NE va PAS dans le modèle

| Reste en code | Pourquoi |
|---|---|
| Le **corps rédigé** | Déjà en base (`metier.<code>.body`). Une colonne `body` créerait deux textes pour une page, dont un invisible à l'écran d'administration. |
| **`SPECIFICITES`** | ⚠ **C'est la seule chose du site qui soit vérifiable** : le docblock impose que chaque ligne nomme une entité du dépôt. La rendre éditable, c'est autoriser une promesse commerciale sans entité derrière — invendable le jour de la démonstration. |
| **`ECRANS`** | Données d'illustration, avec leur avertissement (« aucun nom de client réel »). En base, l'écran inviterait à y saisir un vrai client. |
| **La déduction** | §2. |
| **Un `etablissement_id`** | Absent délibérément : catalogue de l'éditeur, pas donnée de client. L'exception au cloisonnement s'écrit ; ce qui la remplace, c'est le guichet `/editor/**`. |

---

## 2. La déduction activités → modules (G-3)

### 2.1 Elle vit en code, pas en base

**Une capacité est déjà un artefact de code** : l'ajouter demande un cas dans `CapaciteCode` *et* un
descripteur dans `CatalogueCapacites` — la panne du 06/09 est arrivée faute du second. Mettre le
rattachement en base ajouterait une **troisième liste** que rien ne relierait aux deux autres : le
défaut que ce lot existe pour supprimer, réintroduit un étage plus bas.

Cela ne coûte rien à G-5 : ajouter un **métier** reste une ligne de données ; c'est ajouter une
**capacité** qui demande un déploiement, ce qui est déjà vrai.

### 2.2 Une couverture déclarée pour les 26, pas une table à trous

26 capacités, 9 activités. Une simple table `activité → capacités` laisse **douze capacités sans
mention**, et « sans mention » se lit comme « pas encore traitée ». Un `?? []` de plus et on a
reconstruit `PresetVerticale`.

Chacune des 26 est donc classée dans **exactement une** catégorie, par un `match` **sans branche par
défaut** : une 27e fait lever. C'est délibérément le comportement de C-5 — le seul des neuf points
de figement qui échouait **bruyamment**, et donc le seul corrigé le jour même.

`ByActivity` (14) · `Common` (2) · `OwnRule` (5) · `Vertical` (5). **La somme fait 26, et c'est un test.**

### 2.3 La table

```
entry            → controle_acces
resource_booking → reservation, no_show
membership       → sepa, recouvrement, porte_monnaie
equipment_rental → location_materiel, casiers
product_sale     → boutique_en_ligne
coaching         → encadrants, reservation
appointment      → reservation, agenda
lodging          → lodging, stay
dining           → dining
```

`casiers` sous `equipment_rental` n'est pas une facilité : `paquet.md` écrit littéralement
`type: equipment_rental` / `subject: locker` pour la piscine.

**Les 5 en règle propre** — `poss`, `acces_nocturne`, `finance`, `social`, `connecteurs` — ne sont
**jamais suggérées**. Ce n'est pas un trou : `composition.md` classe explicitement le POSS et la
sécurité du travailleur isolé en « ce qui n'entre dans aucune brique ». **Les 5 verticales**
délèguent à `CatalogueCapacites::estVerticale()` au lieu de recopier cinq noms — sinon une 6e
verticale ferait diverger les deux listes, le défaut d'origine.

### 2.4 L'écart avec `PresetVerticale` — et pourquoi il ne doit PAS être nul

| Métier | Déduit et absent du préréglage | Préréglé et non déduit |
|---|---|---|
| piscine | `no_show`, `location_materiel` | `poss`, `porte_monnaie`, `sepa`, `recouvrement` |
| sport | — | `acces_nocturne`, `no_show` |
| padel | **`location_materiel`**, `casiers` | `controle_acces`, `boutique_en_ligne`, `porte_monnaie` |
| patinoire | — | `reservation`, `encadrants` |
| musee | **`casiers`**, `location_materiel`, `sepa`, `recouvrement`, `porte_monnaie` | `boutique_en_ligne` |

**Deux lectures, et les deux comptent :**

1. **Le `location_materiel` du padel donne raison à l'écran, pas au préréglage.** `composition.md`
   donne `equipment_rental` au padel, et le padel loue effectivement (`Padel\Entity\LocationMateriel`,
   `CautionMateriel`). C'est **le préréglage qui a dérivé.** Issue séparée, hors de ce lot.
2. **Le `casiers` du musée casse un test existant** : `WebsiteMetiersTest` asserte que la page musée
   ne contient **pas** « Casiers ». Servir les modules déduits le ferait tomber, et le rendu ne
   serait plus identique.

⚠ **Conséquence architecturale, et elle commande §3 : dans ce lot, la colonne « modules » de la page
métier continue de venir de `PresetVerticale`.** La déduction est calculée, comparée, publiée dans
un rapport — elle n'alimente pas encore le rendu. G-3 demande qu'elle soit calculée pour ce qu'elle
alimentera (l'étape 3 du tunnel) ; G-4 demande un rendu identique. Les deux ne se concilient qu'ainsi.

### 2.5 Le rapport d'écart

`website:trades:modules-diff` affiche, par métier, `déduit` / `préréglé` / `+` / `−`. **Sortie 0
toujours** : ce n'est pas un garde-fou, un écart légitime ne doit pas refuser une fusion. Son
résultat, justifié ligne à ligne, va dans `impl/ecart-modules.md` — **c'est le critère G-3**.

---

## 3. La bascule du site (G-4)

### 3.1 Une façade, trois sources, aucun changement de forme

`MetierCatalog` reste la seule chose que le site connaît et **rend exactement le même tableau**.
Aucun gabarit n'est touché : ce qui n'est pas modifié ne peut pas régresser.

### 3.2 Le repli est TOUT-OU-RIEN

Zéro ligne → les constantes. Au moins une ligne → les lignes seules. **Pas de fusion, pas de
`?? NOMS[$code]`.** Un repli par champ rendrait une base à moitié semée indiscernable d'une base
saine : un métier dont la ligne existe mais dont le nom est vide sortirait avec le nom de la
constante, et personne ne saurait jamais que la ligne est cassée.

### 3.3 Une seule liste de repli, trois lecteurs

`App\Website\Config\TradeFallback` : les cinq entrées **déplacées telles quelles** depuis `NOMS` —
**pas retapées** (§8, R-3). Elle sert `MetierCatalog` (état vide), la commande, et le garde-fou.
Trois usages, une source : ils ne peuvent pas diverger. Et elle **ne grandit jamais** : un 6e métier
est une ligne de base.

### 3.4 Les modules

Un code que l'application connaît → `PresetVerticale`, inchangé. Un code créé en base → la
déduction. ⚠ `Metier::tryFrom()` est ici employé pour ce qu'il fait bien : il ne décide pas si le
métier existe (la ligne le décide), seulement **de quelle source** viennent les modules.

### 3.5 `SiteBlocks` (contrainte C-2)

`all()` et `typeOf()` gagnent un paramètre optionnel : vide → les codes du repli, soit **exactement
le comportement actuel**. Les deux services qui atteignent la base passent la vraie liste. La classe
reste lisible sans conteneur, son invariant déclaré. `garde-fou-blocs-orphelins` n'est pas touché :
il exempte déjà le préfixe `metier.`.

### 3.6 La preuve de G-4

Neuf surfaces : `/metiers`, les cinq pages, `/sitemap.xml`, `/llms.txt`, `/`. Empreintes capturées
avant, puis recapturées **dans les deux états** — base vide et base semée — et identiques.
⚠ **Les deux états doivent être mesurés** : ne mesurer que le repli prouverait qu'on n'a rien cassé
en n'ayant rien branché.

---

## 4. La commande (G-1)

`website:trades:seed`, sur le patron de `website:blocks:seed`.

**Pourquoi pas une migration : D66-ter** — « une migration ne fabrique jamais de donnée métier ».
La migration crée les deux tables, **vides**.

Réconciliation par `slug`. Ligne présente → **rien n'est touché** sans `--force` : c'est la règle 2
de `paquet.md`, et un déploiement qui réécrirait le chapô que Maxime vient de corriger le ferait
revenir en arrière sans explication. Les activités ne sont **jamais supprimées** sans `--force`.

**Sortie :** « N créée(s), M conservée(s), total en base : T ». Le total n'est pas décoratif : il
distingue « tout existait » de « la base est vide ».

⚠ **Câblage au déploiement (C-4).** Une ligne dans `infra/deploy-preprod.sh`. Sans elle, la
préproduction sert éternellement le repli et le lot est **invisible** — c'est le cas mesuré de
`website:blocks:seed`.

---

## 5. Le garde-fou n°55 (G-6)

`bin/garde-fou-metiers-sans-ligne.php`. Nom français : `bin/` n'est pas surveillé par le garde-fou
de nommage, et les 40 fichiers frères sont français.

**Statique** (C-1) : il compare les cas de `Metier` aux codes de `TradeFallback`.
**Lecture explicite de G-6 :** « sa ligne » = « son entrée dans ce que la commande matérialise ».
C'est la seule lecture exécutable — un contrôle de fusion tourne sur un arbre extrait, sans base.
**À confirmer à CP-2.** Le sens qui échappe au statique est couvert par un test d'intégration.

**Les deux sens.** Un cas sans entrée → refusé (la page n'existerait dans aucun des deux états, sans
erreur). Une entrée sans cas → refusée (aucun préréglage, et un métier qui doit vivre sans
déploiement n'a rien à faire dans du code).

**Le témoin de l'instrument.** Moins de 5 cas lus, ou moins de 5 entrées → échec « c'est
l'analyseur, pas le dépôt ». Un analyseur cassé rendrait zéro écart : un vert obtenu en ne mesurant
rien.

**Câblage — trois listes, et une quatrième :** `bin/garde-fous.sh`, `hooks/pre-commit`,
`hooks/pre-receive`, plus deux cas dans `bin/essai-garde-fous.sh` (exigés par le critère G-6 :
« éprouvés en cassant volontairement »). ⚠ Script **et** câblages dans **un seul commit** : l'inverse
est un blocage circulaire déjà rencontré ici.

---

## 6. Les tests (G-7)

**Le test qui ment.** `WebsiteMetiersTest` promet « les cinq métiers, et eux seuls » et ne vérifie
que cinq présences. Corrigé : on extrait **tous** les `href` de la page et on compare **en
ensemble** (`assertSame`, pas `assertStringContainsString`) ; chaque entrée a un nom **non vide**.
Idem pour le plan du site, qui ne vérifie aujourd'hui que deux métiers sur cinq.

⚠ **`testChaqueMetierAffichSonProprePrereglage` reste tel quel, et c'est un choix** : il tomberait
le jour où la page servirait les modules déduits. Le laisser intact en fait **le témoin qui refusera
la bascule tant que l'écart n'est pas arbitré**.

**Sept témoins neufs**, dont : la somme fait 26 ; deux passages de la commande donnent toujours cinq
lignes et **conservent un chapô modifié à la main** ; `/metiers` rendu **base vide** puis **base
semée**, comparé octet à octet ; un 6e métier de bout en bout **dont on enregistre le corps** — c'est
la vérification de C-2, celle qui manquerait si on ne lisait pas `SiteBlocks`.

⚠ **Ne pas semer les métiers dans `SocleFixtures`** : toute la suite basculerait sur le chemin des
lignes et le repli ne serait plus jamais parcouru — un vert obtenu en ne testant qu'une moitié.

---

## 7. L'ordre des étapes

| # | Étape | Rendu | Preuve |
|---|---|---|---|
| 0 | Empreinte de référence des 9 surfaces | rien | leur existence — sans elles, « octet à octet » est une figure de style |
| 1 | `ActivityType`, `CapabilityCoverage`, `ActivityCapabilities` — **aucun appelant** | rien | la somme fait 26 ; garde-fous verts |
| 2 | Les deux entités + migration **à la main** | rien | `--dump-sql` : l'écart ne grandit pas |
| 3 | `TradeFallback` (déplacement) + commande + **ligne de déploiement** | rien | deux passages, cinq lignes ; **empreintes inchangées** |
| 4 | Rapport d'écart + `impl/ecart-modules.md` | rien | les 22 écarts justifiés un par un — **c'est G-3** |
| 5 | **La bascule** | rien — *et c'est ce qu'il faut prouver* | empreintes identiques **dans les deux états** ; **les tests existants passent sans être modifiés** |
| 6 | `SiteBlocks` élargi | rien pour les cinq | un 6e métier enregistre son corps |
| 7 | Garde-fou n°55 + 3 câblages + 2 cas de banc, **un seul commit** | rien | on casse exprès, dans les deux sens |
| 8 | Les tests G-7 | rien | chaque test neuf **vu rouge d'abord** — un test jamais vu rouge ne prouve rien |
| 9 | Opérations d'API éditeur | rien côté public | non-éditeur → 404 ; activité hors des neuf → 422 nommant les neuf |

**Les étapes 1 à 4 n'ont aucun lecteur** : jusqu'à la 5, le site est celui d'aujourd'hui, et chacune
peut être fusionnée seule. L'étape 5 est la seule risquée, et elle arrive avec sa preuve déjà prête
et son écart déjà arbitré.

---

## 8. Les risques

- **R-1 — Une énumération qui grossit sans sa table.** Le précédent du 06/09, mot pour mot. Attrapé
  par des `match` sans branche par défaut et le test de la somme, **en CI avant la fusion**. Le
  `?? []` — la forme silencieuse de `PresetVerticale` — est **interdit** dans ces deux fonctions.
- **R-2 — La base à moitié semée.** Quatre lignes sur cinq : `/metiers` en affiche quatre,
  `/metiers/patinoire` rend 404, **aucune erreur**. Un 404 sur une URL indexée ne se voit pas d'ici,
  il se voit chez les moteurs des semaines plus tard. Attrapé par un test d'intégrité ; réparé en
  relançant la commande, qui est idempotente.
- **R-3 — Un octet du chapô.** Les chapôs portent des apostrophes typographiques ; les retaper, c'est
  une chance sur cinq d'en normaliser une, et aucun test existant ne le verrait. Attrapé par
  l'architecture : `TradeFallback` est un **déplacement**, source unique des trois lecteurs.
- **R-4 — Le corps d'un 6e métier refusé à l'enregistrement.** Tout marcherait sauf la seule chose
  que le parcours utilisateur décrit. Attrapé par un test qui **enregistre** et relit.
- **R-5 — Une activité inconnue fait tomber `/metiers`.** Assumé : c'est le comportement de
  `CatalogueCapacites`, le seul des neuf défauts qui ait été corrigé le jour même. Une lecture
  tolérante afficherait un métier amputé, indiscernable d'un métier sain.
- **R-6 — Garde-fou vert, production vide.** Le statique peut être vert pendant que la base n'a
  aucune ligne. Attrapé par la ligne de déploiement et le total imprimé.
- **R-7 — Dérive de schéma.** Le dépôt tolère déjà 108 lignes d'écart. `--dump-sql` avant d'écrire,
  vérification après. **L'écart ne doit pas grandir.**
- **R-8 — Les trois listes de câblage.** Un contrôle a déjà été câblé dans deux sur trois.
- **R-9 — L'orthographe des cinq activités.** Bornée tant qu'aucun paquet n'est publié : une
  migration `UPDATE`. **La fenêtre se referme avec `ACT-0`**, en attente depuis le 22/08.
