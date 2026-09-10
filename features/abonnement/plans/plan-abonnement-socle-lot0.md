# Plan — Abonnement transverse, **Lot 0 : le socle**

**Spec :** `features/abonnement/specs/spec-abonnement-transverse.md` (CP‑1 validée le 07/09)
**Portée :** le seul **lot 0** du §6 de la spec. Aucune bascule, aucun consommateur recâblé, aucun écran.
**Branche :** `feature/abonnement-socle-lot0`
**État :** en attente de **CP‑2**.

---

## 0. Objectifs numérotés

La spec est antérieure à la convention de numérotation ; elle décrit le lot 0 en une phrase (§6) et
ses invariants en §2 et §5. Les objectifs ci‑dessous en sont la transcription littérale, chacun avec
sa source. Rien n'y est ajouté.

| # | Objectif | Source |
|---|---|---|
| **G‑1** | Un module d'abonnement **transverse**, auto‑enregistré au registre de modules. | §2 tiret 1, §6 lot 0 |
| **G‑2** | Le module est **atteignable** : sa déclaration de capacité est cohérente avec le catalogue. | §2 tiret 1, §6 lot 0 |
| **G‑3** | Une **entité d'abonnement** reprenant la structure d'`AbonnementFitness`. | §2 tiret 2, §6 lot 0 |
| **G‑4** | Cette entité est **cloisonnée par établissement**. | §2 tiret 2, §5 |
| **G‑5** | Une **migration écrite à la main** crée sa table. | §5, §6 lot 0 |
| **G‑6** | **Aucune bascule** : rien de l'existant n'est modifié, la suite reste verte. | §5 « ne rien casser », §6 lot 0 |

**Hors périmètre du lot 0**, et nommé pour qu'on ne me le reproche pas plus tard : la migration des
données, le recâblage de Sepa/Recouvrement/Boutique, les avoirs et la proratisation, l'onglet
Exploitation, toute exposition d'API. Ce sont les lots 1 à 3.

---

## 1. Décisions

### D‑1 — Le nom technique sera **`Membership`**, l'affichage restera « Abonnement »

**Le problème.** Le §8 de la spec posait la question du nom et la réponse CP‑1 fut « abonnement ».
Or ce nom ne peut pas être porté par du code neuf, pour deux raisons mesurées :

- Le garde‑fou n°2 (règle D5) refuse un identifiant français dans tout fichier **ajouté** sous
  `app/src/` ou `app/migrations/`. Il contrôle les noms de classes, les cas d'énumération, les noms
  de tables, les noms de colonnes explicites et les codes de permission. Vérifié :
  **`abonnement` est au lexique français** (`bin/nommage.lexique-francais.txt:68`), ainsi que
  `adherent` (ligne 69) et `echeance` (ligne 50). Une classe `Abonnement` serait refusée au commit.
- Le namespace `App\Subscription` est **déjà pris** : c'est la facturation SaaS de l'éditeur, celle
  qui vend les plans Fluvia aux établissements (`Plan`, `SubscriptionInvoice`, `EditorSubscription`).

**Ce qui est décidé.** Le module s'appelle `Membership`. Vérifié libre : ni `App\Membership`, ni
`App\Enrollment`, ni `App\Engagement` n'existent, et aucun de ces mots n'est au lexique.

**Pourquoi celui‑là.** `Membership` est ce qu'un **adhérent** détient auprès de l'établissement ;
`Subscription` est ce que l'**établissement** achète à Fluvia. Ces deux objets portent aujourd'hui le
même mot français « abonnement », et le dépôt sait ce que ça coûte : `ReversementsOta.jsx` ne sert
pas les OTA de la boutique mais celles du musée, et l'écran a gardé une liste vide sans que personne
comprenne pourquoi. Deux mots distincts ferment cette porte à l'avance.

**Ce que la décision ne change pas.** L'interface continue d'afficher « Abonnements ». D5 sépare
explicitement le technique (anglais, stable) de la présentation. Il n'y a pas de bibliothèque i18n
dans ce dépôt ; le rôle est tenu par `frontend/src/api/vocabulaire.js`, la carte qui traduit les codes
serveur en mots français. C'est là que le mot « abonnement » vivra, au lot 3.

**Alternatives écartées.** `Engagement` (le mot de T38, absent du lexique, mais ambigu : il désigne
la période d'engagement, pas le contrat) · `Adhesion` (absent du lexique mais français, et `Adhésion`
accentué serait refusé par la règle des accents du même garde‑fou) · retirer `abonnement` du lexique
(le garde‑fou l'autorise, à condition d'écrire pourquoi dans `MESSAGES.md`, mais ça désarmerait le
contrôle pour toutes les sessions et toute la famille de mots — le fichier le dit lui‑même).

### D‑2 — ⚠ `capability()` rendra **`null`**, et cela **contredit une ligne de la spec**

C'est le seul point du plan qui demande vraiment ton arbitrage.

**Ce que dit la spec** (§2, tiret 1) : *« avec une capability ajoutée à `CapaciteCode` (sinon le
module est présent mais inaccessible — RG‑PLAT) »*.

**La prémisse est fausse, et c'est vérifiable.** Le docblock de `ModuleManifest`
(`app/src/Platform/Module/ModuleManifest.php:9‑24`) dit l'inverse : `capability()` rendant `null`
**désigne un service transverse** — une brique partagée, ni vendue ni activable par établissement.
Le cas « présent mais inaccessible » est celui d'un code **inventé, absent du catalogue** : là,
`ModuleAccess::hasModule()` répond `false` pour toujours, sans erreur ni journal. `null` ne produit
pas cet effet ; c'est même le cas prévu, et il exempte du garde‑fou n°41.

**Le précédent est à côté.** `App\Group` rend `null`, et son manifeste explique pourquoi :
*« un exploitant qui reçoit des groupes le fait quel que soit son métier ; en faire une capacité à
cocher créerait une porte fermée là où il n'en faut pas. »* Le raisonnement vaut mot pour mot ici :
piscine, padel, patinoire, musée et sport vendent tous des abonnements. Le titre même de la spec dit
« abonnement **transverse** ».

**Et une capacité coûterait quelque chose aujourd'hui.** Au lot 0 le module n'a aucun écran. Le
catalogue devrait donc la déclarer `peutServir = false`, comme `lodging`, `stay` et `dining` depuis
ton arbitrage du 04/09 — c'est‑à‑dire annoncer dans la vitrine une option qu'on ne peut pas vendre,
et mettre le module sous la surveillance du garde‑fou n°52, qui refusera chaque lot suivant tant
qu'on ne rescellera pas sa mesure.

**Ce que je propose.** `capability(): null` au lot 0. Si tu veux vendre l'abonnement comme une option
à part, la bascule est d'une ligne dans le manifeste plus un descripteur au catalogue, et elle se
fait au lot 3 quand il y a un écran à vendre. **Dis‑moi si tu préfères la capacité tout de suite : je
la pose, avec `peutServir = false` et le rescellement du n°52.**

### D‑3 — La propriété de cloisonnement s'appellera `etablissement`, en français

Ce n'est pas un oubli de D5, c'est une contrainte de l'outillage. Les extensions de périmètre
écrivent `IDENTITY(%s.etablissement)` **en dur**, et le garde‑fou n°28 refuse une entité qui
nommerait cette relation autrement : elle sortirait du filtre **sans aucune erreur Doctrine**, donc
sans symptôme. Le précédent est net — `App\Group`, module au nommage anglais, nomme malgré tout ses
propriétés `etablissement`.

Le garde‑fou de nommage ne s'y oppose pas : les **propriétés sont hors de son périmètre**,
délibérément, et `#[ORM\JoinColumn(name: …)]` n'est pas contrôlé non plus (seul `#[ORM\Column(name: …)]`
l'est). Tout le reste du module est en anglais.

### D‑4 — Énumérations neuves, cases **et** valeurs en anglais

Le module ne réutilise pas `App\Sport\Enum\PeriodiciteAbonnementFitness` ni `StatutAbonnementFitness` :
ça créerait une dépendance `Membership → Sport`, exactement l'inverse de ce que la spec veut (§3 :
*« Sport déclarera une dépendance vers abonnement »*).

Les valeurs suivent D5 et sont donc en anglais, alors que les valeurs Sport sont françaises. La
correspondance est écrite **maintenant**, appliquée au **lot 1** par la migration de données :

| Sport (existant) | `Membership` (neuf) |
|---|---|
| `mensuel` | `monthly` |
| `hebdomadaire` | `weekly` |
| `annuel` | `yearly` |
| `actif` | `active` |
| `pause` | `paused` |
| `impaye` | `unpaid` |
| `resilie` | `terminated` |
| `echu` | `expired` |

L'alternative — garder les valeurs françaises pour que le lot 1 soit une copie brute — a été écartée :
elle économise cinq lignes de SQL écrites une fois, contre une énumération à moitié traduite qu'on
lira pendant des années.

### D‑5 — **Aucune exposition d'API au lot 0**

L'entité est créée, migrée et cloisonnée ; elle ne porte pas `#[ApiResource]`. Trois raisons :

1. La spec dit « aucune bascule encore ». Une ressource exposée que rien n'appelle **est** une
   bascule, à moitié.
2. Le garde‑fou d'écart client/serveur mesure aujourd'hui 504 opérations inatteignables pour un
   plafond de 521 : **dix‑sept crans de marge**. En consommer pour des opérations sans écran serait
   payer la dette d'un lot qu'on n'a pas encore écrit.
3. Sans `#[ApiResource]`, les garde‑fous n°35 (entité rattachable hors liste), n°5 (couverture de
   périmètre) et n°29 (création sans suppression) ne s'appliquent pas — ils ne mordent que sur les
   entités exposées. L'extension de périmètre est écrite quand même (D‑6), pour que l'exposition du
   lot 3 n'ait rien à rattraper.

### D‑6 — L'extension de périmètre est écrite dès maintenant

Convention retenue : `MembershipScopeExtension`, la forme des modules récents à nommage anglais
(`GroupScopeExtension`, `StayScopeExtension`, `DiningScopeExtension`), et non `Perimetre*Extension`
qui est celle des modules français. Chaque module écrit la sienne : vérifié, 46 extensions coexistent
sans classe de base ni trait commun.

### D‑7 — Table `membership`, identifiants `BINARY(16)`

`BINARY(16)` est la convention du dépôt, et s'en écarter coûte cher : la migration du 07/09 a déclaré
ses identifiants en `CHAR(36)`, la clé étrangère a été refusée par MariaDB (errno 150), et **tous les
déploiements étaient bloqués** jusqu'à la réparation du 09/09.

---

## 2. Étapes

Chacune est autonome : le build et les garde‑fous doivent passer **après chaque étape**, pas
seulement à la fin.

### Étape 1 — Le manifeste du module — couvre G‑1, G‑2

**Fichier ajouté :** `app/src/Membership/MembershipModule.php`

Sur le modèle exact de `app/src/Group/GroupModule.php`. `id()` rend `'membership'` ; `version()` rend
`'0.1.0'` ; `capability()` rend `null` (D‑2) ; `permissions()` rend `['membership.read', 'membership.manage']`
sur le modèle de `group.read` / `group.manage` ; tout le reste rend un tableau vide.

`dependencies()` reste **vide** bien que l'entité référence `App\Crm`, `App\Offre`, `App\Sepa` et
`App\Organisation` : on ne déclare pas de dépendance vers un module sans manifeste, sinon RG‑PLAT‑07
fait échouer le démarrage. C'est la règle que `GroupModule` et `MuseeModule` appliquent déjà, et le
typage exprime le lien.

Le manifeste doit être **constructible sans argument** (`ManifestCatalogueTest` le vérifie) et vivre
à `app/src/<Module>/<Xxx>Module.php`, sinon le garde‑fou n°41 ne le découvre pas — il cherche par
`glob('app/src/*/[A-Z]*Module.php')`.

**Fait quand :** `php bin/console platform:modules` liste `membership` · `./bin/garde-fous.sh` vert.

### Étape 2 — Les deux énumérations — couvre G‑3

**Fichiers ajoutés :** `app/src/Membership/Enum/MembershipPeriodicity.php`,
`app/src/Membership/Enum/MembershipStatus.php`

Cases et valeurs selon la table de D‑4. Les noms de cas sont contrôlés par le garde‑fou de nommage,
les valeurs ne le sont pas — les deux sont en anglais quand même, par D5.

**Fait quand :** `./bin/garde-fous.sh` vert (c'est le nommage qui est en jeu ici).

### Étape 3 — L'entité — couvre G‑3

**Fichier ajouté :** `app/src/Membership/Entity/Membership.php`

Miroir des treize propriétés persistées d'`AbonnementFitness` (`app/src/Sport/Entity/AbonnementFitness.php`),
renommées en anglais sauf la relation de cloisonnement (D‑3) :

| `AbonnementFitness` | `Membership` | colonne | contrainte |
|---|---|---|---|
| `id` | `id` | `id` | `BINARY(16)`, clé primaire |
| `adherent` (`Beneficiaire`) | `member` | `member_id` | ManyToOne, non nul |
| `payeur` (`Client`) | `payer` | `payer_id` | ManyToOne, non nul |
| `formule` (`Formule`) | `formula` | `formula_id` | ManyToOne, non nul |
| `periodicite` | `periodicity` | `periodicity` | `VARCHAR(12)`, enum |
| `statut` | `status` | `status` | `VARCHAR(12)`, enum, défaut `active` |
| `dateSouscription` | `subscribedOn` | `subscribed_on` | `DATE` |
| `dateDebutEngagement` | `commitmentStartsOn` | `commitment_starts_on` | `DATE` |
| `dateFinEngagement` | `commitmentEndsOn` | `commitment_ends_on` | `DATE` |
| `preavisResiliationJours` | `noticePeriodDays` | `notice_period_days` | `SMALLINT`, défaut 30 |
| `montantCentimes` | `amountCents` | `amount_cents` | `INT`, défaut 0 |
| `mandatSepa` (`MandatSepa`) | `sepaMandate` | `sepa_mandate_id` | ManyToOne, non nul |
| `etablissement` | **`etablissement`** | `etablissement_id` | ManyToOne, non nul (D‑3) |

⚠ **Le mandat est un `ManyToOne`, pas un `OneToOne`, et l'index ne doit pas être unique.** La
migration d'origine de `sport_abonnement_fitness` (`Version20260815114107.php:25`) avait posé un
`UNIQUE INDEX` sur `mandat_sepa_id` ; il a fallu le retirer le 01/09, parce qu'un même mandat porte
plusieurs abonnements. On ne refait pas l'erreur dans la table neuve.

Pas d'`#[ApiResource]` (D‑5). Pas de groupes de sérialisation : sans exposition ils ne servent à rien.

**Fait quand :** `php bin/console doctrine:schema:validate --skip-sync` rend un mapping valide.

### Étape 4 — Le cloisonnement — couvre G‑4

**Fichier ajouté :** `app/src/Membership/Doctrine/MembershipScopeExtension.php`

Sur le modèle de `PerimetreSportExtension` pour la mécanique, et de `GroupScopeExtension` pour le
nom. Une seule entrée : `Membership::class => []` — tableau vide parce que l'entité porte elle‑même
`etablissement`, aucune jointure n'est nécessaire.

Reprend les deux comportements du modèle, qui ne sont pas négociables :
- filtre sur l'établissement **actif** (`ContexteEtablissement::idActif()`), pas sur le périmètre du
  lecteur — un exploitant multi‑sites verrait sinon les données de tous sous le titre d'un seul ;
- **fermeture par défaut** : `idActif()` nul ⇒ `andWhere('1 = 0')`. Une liste vide se remarque, une
  liste inter‑établissements a seulement l'air plus longue.

**Fait quand :** garde‑fous n°28 (champ de cloisonnement), n°35 et n°5 verts.

### Étape 5 — La migration — couvre G‑5

**Fichier ajouté :** `app/migrations/Version<AAAAMMJJHHMMSS>.php`

Écrite à la main, jamais un `migrations:diff` brut. La méthode prescrite par le CLAUDE.md est
inversée par rapport à l'intuition : **on demande d'abord le SQL à Doctrine**
(`doctrine:schema:update --dump-sql`), on l'écrit à la main, puis on vérifie l'absence de dérive.

Forme reprise de `Version20260909200000.php` : namespace `DoctrineMigrations`, `final class`,
trois méthodes, `CREATE TABLE` en heredoc nowdoc, index nommés en minuscules dans le `CREATE`, clés
étrangères posées par des `ALTER TABLE` séparés et nommées `fk_…`, suffixe
`DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB`.

Cinq clés étrangères, vers `crm_beneficiaire`, `crm_client`, `off_formule`,
`sport_mandat_sepa_fitness` et `org_etablissement`. `down()` défait dans l'ordre inverse.

⚠ **Aucun `DROP` dans `up()`** : le garde‑fou n°13 le refuserait sans annotation `@drop-voulu:`, et
il n'y a rien à supprimer ici. ⚠ Le numéro de version doit être **libre** : le garde‑fou n°50 rend
toute migration présente dans `origin/main` immuable.

**Fait quand :** `doctrine:schema:update --dump-sql` ne propose plus rien après application, c'est‑à‑dire
que la migration écrite et le mapping disent la même chose · garde‑fous n°13 et n°50 verts.

### Étape 6 — Le test du socle — couvre G‑6

**Fichier ajouté :** `app/tests/Membership/MembershipSocleTest.php`

Trois assertions, et pas une de plus, parce qu'un socle n'a pas de comportement à éprouver :

1. le module est **enregistré** au `ModuleRegistry` sous l'identifiant `membership` ;
2. le mapping Doctrine de `Membership` est **valide** et sa table s'appelle `membership` ;
3. `MembershipScopeExtension` **nomme** `Membership::class` — le témoin qui empêche l'entité de
   sortir du filtre le jour où le lot 3 l'exposera.

⚠ Le troisième est celui qui compte. Sans exposition d'API, aucun garde‑fou ne vérifie aujourd'hui
que l'extension couvre l'entité : ce test est le seul filet jusqu'au lot 3.

**Fait quand :** `./infra/test-stack.sh run abolot0 tests/Membership` vert.

### Étape 7 — La suite complète, non modifiée — couvre G‑6

Aucun fichier touché. On **vérifie** que rien de l'existant n'a bougé.

**Fait quand :** `./infra/test-stack.sh run abolot0` vert sur l'ensemble · `./bin/garde-fous.sh` vert ·
`git diff --stat origin/main` ne montre que des fichiers **ajoutés**, aucun modifié — sauf
`CapaciteCode` et `CatalogueCapacites` si tu tranches D‑2 dans l'autre sens.

---

## 3. Tests

| Étape | Ce qui la valide |
|---|---|
| 1 | `ManifestCatalogueTest` (existant, couvre automatiquement le module neuf) + `platform:modules` |
| 2 | Garde‑fou n°2 (nommage anglais) |
| 3 | `doctrine:schema:validate` (mapping) |
| 4 | Garde‑fous n°28, n°35, n°5 |
| 5 | `doctrine:schema:update --dump-sql` sans dérive + garde‑fous n°13, n°50 |
| 6 | `MembershipSocleTest` (ajouté) |
| 7 | Suite PHP complète + les 54 garde‑fous |

Ligne de base établie avant toute écriture, sur la branche vierge : **50 garde‑fous verts, code de
sortie 0**. Tout rouge après coup est donc imputable à ce lot, et non hérité.

---

## 4. Couverture

| Objectif | Étapes qui le couvrent |
|---|---|
| G‑1 — module auto‑enregistré | 1 |
| G‑2 — module atteignable | 1 (par `capability(): null`, D‑2) |
| G‑3 — entité miroir | 2, 3 |
| G‑4 — cloisonnement | 4, 6 |
| G‑5 — migration à la main | 5 |
| G‑6 — aucune bascule, suite verte | 6, 7 |

Aucune étape ne couvre un objectif absent de la spec. Aucun objectif n'est sans étape.

---

## 5. Ce sur quoi j'attends ta réponse (CP‑2)

1. **D‑2 — la capacité.** `capability(): null` comme je le propose, ou une capacité au catalogue tout
   de suite comme l'écrit la spec ? La prémisse de la spec sur ce point est factuellement inexacte,
   et c'est la seule raison pour laquelle je la conteste.
2. **D‑1 — le nom `Membership`.** Il ne contredit pas ton CP‑1, qui portait sur le mot **produit** ;
   il le complète côté code. Mais tu vas lire ce mot pendant des années, donc autant qu'il te
   convienne.

Le reste du plan n'appelle pas d'arbitrage : ce sont des contraintes mesurées de l'outillage.
