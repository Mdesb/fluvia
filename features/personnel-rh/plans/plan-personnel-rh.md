# Plan d'implémentation — personnel-rh, lot 1

**Spec de référence :** `features/personnel-rh/specs/spec-personnel-rh.md` (CP-1 accordé le 2026-09-08)
**Matière première :** `features/personnel-rh/refs/faits-verifies-rh.md`
**Branche :** `feature/personnel-rh` · worktree `/home/debian/wt/personnel`
**Auteur :** agent `architecte`, 2026-09-08 — écrit en lecture seule sur le code

---

## 0. Ce que la mesure a corrigé dans la spec avant même de planifier

Trois faits mesurés pendant la rédaction de ce plan **contredisent la spec validée**. Ils ne changent
pas les objectifs G-1 à G-8, mais ils changent ce qu'il faut construire pour les atteindre.

### 0.1 ⚠ `POST /personnel/employes/{id}/badges` ne peut PAS aboutir depuis l'écran — jamais

`frontend/src/api/client.js:2418-2419` envoie **`body: {}`**. Le processeur exige trois champs :

| Contrôle | Source | Effet sur un corps vide |
|---|---|---|
| `etablissement` | `EmissionBadgeStaffProcessor.php:66` → `resoudre(..., null, 'etablissement')` | **422 « Référence "etablissement" obligatoire »** — le premier atteint |
| `modeHoraire` | `EmissionBadgeStaffProcessor.php:73-76` | 422 « Champ "modeHoraire" invalide ou manquant » |
| `espacesAutorises` non vide | `EmissionBadgeStaffHandler.php:75-77` | 422 « Au moins un espace autorisé est requis (RG-PERSO-06/07) » |

Le bouton « Émettre » de `Personnel.jsx:394` n'a donc **jamais** fonctionné.

**Conséquence dirimante sur G-4.** Son critère — « poser un rattachement : le bandeau disparaît, et
**l'émission du badge aboutit** » — échouera en 422 quel que soit le rattachement. **Le lot doit
réparer l'appel d'émission, sinon G-4 n'est pas démontrable.**

**Pourquoi la suite PHP est verte pendant ce temps.** `app/tests/Personnel/Api/BadgeStaffTest.php:29-32`
envoie le corps **complet**. Le serveur est prouvé, l'écran ne l'est pas : deux moitiés, deux preuves
— et une seule était faite.

### 0.2 ⚠ « `EmissionBadgeStaffHandler` ne pose aucune zone » est un **zéro faux**

`EmissionBadgeStaffHandler.php:110` fait `$portee->addEspaceAutorise($espace)`. La méthode de
`PorteeAccesEmploye` s'appelle **`addEspaceAutorise`** (`PorteeAccesEmploye.php:94`), en français.
`addAuthorisedSpace` est la méthode d'une **autre classe**, `App\Acces\Entity\DroitAcces:365`. La
mesure avait cherché la chaîne rendue par une source voisine, pas celle du sujet.

**Conséquence sur G-7.** La spec demandait à l'écran de « distinguer *aucune zone* de *pas encore
mesuré* » en croyant le serveur incapable d'en poser. C'est l'inverse : le serveur **refuse**
d'émettre sans zone (`EmissionBadgeStaffHandler.php:75-77`). `PorteeAccesEmploye` porte donc toujours
au moins un espace. G-7 devient une lecture simple, et l'état « aucune zone » est **inatteignable par
construction** — l'écran ne doit pas inventer une colonne pour un cas que le serveur interdit.

**D88 n'est pas comblé pour autant, et pour une autre raison :** les zones viennent du **corps de la
requête**, pas de la fonction de l'employé. Hors périmètre de ce lot ; à consigner.

### 0.3 Le garde-fou n°28 est **aveugle** aux entrées `{root}.` de `CHEMINS_DIRECTS`

Vérifié en appliquant sa propre expression au fichier : son motif
(`bin/garde-fou-champ-cloisonnement.php:249`) est `['"]([\w.]+)['"]` — l'accolade de `{root}` le fait
échouer. Sur les cinq entrées de `PerimetrePersonnelExtension`, il n'en voit que **deux**
(`'pp_ct.etablissement'`, `'pp_bs.etablissement'`). Les trois entrées `{root}.etablissement` ne sont
**pas contrôlées**, et une entrée neuve `{root}.establishment` ne le sera pas davantage. Le témoin
positif (deux lignes trouvées) prouve que la commande n'est pas muette : c'est une exemption, pas un
instrument mort.

**Conséquence :** on peut déclarer une entité anglaise avec `'{root}.establishment'` — le mécanisme
partagé (`str_replace('{root}', $rootAlias, …)` puis `IDENTITY(alias.establishment)`, l. 140-176) le
supporte sans modification. Mais **le garde-fou n°28 vert ne prouvera rien à ce sujet.** La preuve du
cloisonnement de l'entité neuve doit être un **test d'exécution**.

---

## 1. Décisions d'architecture

| # | Décision | Pourquoi, et ce qu'elle coûte |
|---|---|---|
| **A-1** | Les fixtures de démonstration vont dans une classe **neuve** `HrDemoFixtures`, pas dans `PersonnelFixtures`. | `PersonnelApiTestCase::setUp()` recharge `PersonnelFixtures` **avant chaque test** des 9 fichiers de `app/tests/Personnel/`. Y semer des employés modifierait la ligne de base de toute la suite, dont `LireSoiPersonnelTest.php:110` qui construit **son propre** `Employe` lié à `EMAIL_EMPLOYE_SOI` — un second employé lié au même utilisateur rendrait son `findOneBy` non déterministe. Une classe séparée `implements DependentFixtureInterface` est chargée par `doctrine:fixtures:load` et **ignorée** par le harnais. Coût : la démo et les tests divergent — assumé et écrit. |
| **A-2** | Le paramètre d'établissement est une entité neuve **`HrSettings`** (table `personnel_hr_settings`), `Get` + `Patch` seulement, sur une **URI singleton** `/personnel/hr-settings`, servie par un **provider sur mesure** qui résout l'établissement actif. | Le patron `ParametrePiscineEtablissement` n'a **aucun moyen de créer sa première ligne** hors fixtures, et `Get /{id}` est impossible faute d'id. Le singleton lève les deux : l'URI ne dépend d'aucun id, et le provider rend un objet **transitoire porteur des défauts** quand la ligne n'existe pas. Le premier `Patch` la persiste. |
| **A-3** | `HrSettings.establishment` **n'est dans aucun groupe d'écriture** ; posé par le provider depuis `ContexteEtablissement::etablissementActif()`. | D41. Et `EstablishmentScopeWriteGuard.php:68` teste `method_exists($data, 'getEtablissement')` — **le nom français seul** : une entité anglaise lui échappe en silence. On ne se repose donc pas sur lui, on ne lui donne rien à rattraper. Patron : `BillingSettingsStampProcessor.php`. |
| **A-4** | `HrSettings::class => '{root}.establishment'` déclaré dans `PerimetrePersonnelExtension::CHEMINS_DIRECTS`. | Exigé par le garde-fou n°35. **Ne protège pas le `Get` singleton** : le provider est sur mesure, aucune extension Doctrine ne s'y applique — c'est le provider lui-même qui filtre. |
| **A-5** | Seuil par défaut **14 jours**, aligné sur `CheckQualificationsCommand::HORIZON_PAR_DEFAUT`. | Lève l'UNVERIFIED n°1 dans le sens **le plus conservateur** : la valeur en base ne change aucun comportement existant le jour de la migration. |
| **A-6** | La commande résout le seuil **par établissement du créneau**, pas globalement. | La commande n'a pas d'établissement actif (CLI). Fenêtre de requête = **maximum** des seuils configurés (aucun cas manqué), puis filtrage avec le seuil de **son** établissement. `--jours` explicite prime sur tout. |
| **A-7** | Le refus de périmètre de G-5 est un **404**, pas le 403 des trois processeurs existants. | Convention du dépôt depuis l'audit du 06/09, et exigence explicite de G-5. Divergence assumée — voir R-7. |
| **A-8** | Le lien roster → qualification exige d'ajouter l'**identité** des employés affectés à la charge utile du roster. | `RosterProvider.php:116-120` publie `employesAffectes` comme un tableau de **noms affichés**, sans identifiant. On ne peut pré-remplir un formulaire à partir d'un nom. |
| **A-9** | Toute étape qui **déclare une opération serveur neuve** livre son appel client **et** son usage d'écran dans la même étape. | `lib/ecart.mjs` compte chaque `new Get(`/`new Patch(` en « exposée », et « atteignable » seulement si un helper de `client.js` est référencé par un écran. Une étape back-seule ferait **monter** l'écart, et le garde-fou n°15 est un cliquet qui ne remonte pas. |

---

## Étape 1 — Semer des employés de démonstration (G-8)

**Zone sensible :** aucune.

### Ce qu'elle change

**Créé** — `app/src/Personnel/DataFixtures/HrDemoFixtures.php` (nom anglais, D5).
`final class HrDemoFixtures extends Fixture implements DependentFixtureInterface`,
`getDependencies(): [PersonnelFixtures::class]`. Sur les établissements `Site A Personnel` /
`Site B Personnel` de `PersonnelFixtures`, il sème :

- **6 employés** couvrant les cas de bord : un rattaché à A, un à B, un aux **deux** (multi-site), un
  **orphelin** (aucun rattachement), un **suspendu**, un **sorti** (`dateSortie` renseignée) ;
- **des qualifications** : une **expirée** (J−30), une **expirant sous 14 jours** (J+7), une
  **expirant sous 30 mais pas sous 14** (J+21 — c'est elle qui rendra le réglage de l'étape 7
  visible), une largement valide (J+400) ;
- **des rattachements**, un **créneau de travail** avec `qualificationRequise`, et une **affectation**
  dessus pour que le roster ait quelque chose à montrer ;
- **aucun badge** : l'émission passe par `EmissionBadgeStaffHandler` (appairage, génération de code,
  séquence de snapshot) et non par un `new BadgeStaff()`. Les badges se posent depuis l'écran (étape 5).

⚠ `Employe.dateEntree` est `date_immutable` **non nul** avec `Assert\NotNull` (`Employe.php:97-100`),
`typeContrat` idem (`:92-95`). Une fixture persiste par l'`EntityManager` : `Assert\NotNull`
**ne s'exécute pas**. L'oubli ne se verrait qu'au `flush()`.

### Comment on prouve qu'elle marche

- **Avant :** `grep -rn "new Employe(" app/src` rend **0 ligne** (témoin positif : la même commande
  rend 10 lignes sous `app/tests` — l'instrument n'est pas muet).
- **Après :** `doctrine:fixtures:load` puis `GET /api/employes` avec le compte RH rend **≥ 6**
  éléments, dont un dont `RattachementEmploye?employe=<id>` est **vide**.
- **Témoin négatif (le cas qui doit être MASQUÉ) :** le même appel avec le rôle *Lecture seule*
  (`personnel.lire` sans `gerer_employe`) rend **5**, pas 6 — l'orphelin est absent
  (`PerimetrePersonnelExtension::orphelinReserveAuxGestionnaires`). Un compte qui verrait 6
  signalerait que le semis a contourné le cloisonnement.
- **Non-régression obligatoire :** `tests/Personnel` reste vert (42 tests, 329 assertions). S'il
  bouge, la fixture a fui dans le harnais — ce que A-1 interdit.

### Dépendances
Aucune. **À faire en premier** : toutes les preuves « en exécutant » s'y appuient.

---

## Étape 2 — G-5 : garder l'écriture de `RattachementEmploye`

**⚠ ZONE SENSIBLE — autorisation et cloisonnement multi-tenant.**

### Ce qu'elle change

**Créé** — `app/src/Personnel/State/StaffAssignmentScopeProcessor.php`, branché sur le `Post` **et** le
`Patch` de `RattachementEmploye` (`RattachementEmploye.php:34-35` modifié). Il fait, dans cet ordre :

1. `\assert($data instanceof RattachementEmploye)` ;
2. l'`Employe` porté par `$data` confronté à `PerimetreEmployeVerificateur::estDansLePerimetre` →
   **`NotFoundHttpException` (404)** sinon, message qui **ne nomme pas l'employé** ;
3. délégation à `$this->persist->process(...)`.

⚠ **L'injection du processeur délégué décide de tout.** Il faut
`#[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]`, exactement comme
`BillingSettingsStampProcessor.php:47-48`. Ce nom de service porte le décorateur
`EstablishmentScopeWriteGuard`. Injecter le processeur **interne** désarmerait D41 sur
`RattachementEmploye` en silence : l'`etablissement` du corps ne serait plus contrôlé du tout, et le
lot **aggraverait** le défaut qu'il vient fermer.

**Non modifié, délibérément :** `RattachementEmploye.etablissement` reste dans `rattachement:write`.
Dette gelée et connue (`bin/etablissement-ecrivable.ligne-de-base.json:117-120`), protégée par le
décorateur global — l'entité porte bien `getEtablissement()` en français. La sortir du groupe
casserait l'écran de l'étape 4, qui doit choisir le site.

### ⚠ Le trou qui reste ouvert, et pourquoi on ne le referme pas ici

`PerimetreEmployeVerificateur::estDansLePerimetre` rend **`true`** pour un employé **sans aucun
rattachement**. Le contrôle est donc vide de sens sur exactement le geste que l'écran fait le plus :
poser le premier rattachement d'un orphelin.

On ne peut pas le refermer par un discriminant : **`Employe` ne porte ni créateur, ni horodatage de
création, ni ancrage d'établissement** (`Employe.php:62-110` : `id`, `utilisateur`, `nom`, `prenom`,
`matricule`, `poste`, `typeContrat`, `dateEntree`, `dateSortie`, `statut` — rien d'autre). Aucune
donnée ne distingue « l'orphelin que j'ai créé » de « l'orphelin d'un autre client ».

**Ce qui borne le risque, mesuré :** l'appelant doit détenir `personnel.gerer_employe` sur
l'établissement **actif** ; l'`etablissement` du corps est confronté au périmètre par le décorateur
global → 404 sinon ; un orphelin n'est **lisible** que par un porteur de `gerer_employe` sur l'actif ;
l'attaque restante suppose de **connaître l'UUID** d'un orphelin d'un autre client, obtenu ailleurs.

**Ce qui le lèverait :** un ancrage de création sur `Employe` (`createdBy` + `createdAt`, ou un
`Groupe` propriétaire). Une colonne, donc une migration, donc son propre lot. **À porter au lot 2**,
avec les documents RH qui poseront la même question.

### Comment on prouve qu'elle marche

Nouveau fichier `app/tests/Personnel/Api/RattachementScopeTest.php` :

1. **Le refus.** Un `Employe` rattaché **uniquement à B**, un compte n'ayant d'`Affectation` que sur A.
   `POST /api/rattachement_employes` avec cet employé et l'établissement A → **404**.
   ⚠ **Vérifier l'absence d'écriture en base**, pas seulement le code de retour :
   `count(['employe' => $employe])` doit être **inchangé**. Un 404 rendu après un `flush()` réussi
   serait un test vert sur un défaut vivant. *Avant l'étape : cet appel rend **201**.*

2. **⚠ TÉMOIN NÉGATIF — le cas que le contrôle doit AUTORISER.** Un contrôle trop large ne se
   démasque jamais par ses refus : ils passent, et mieux qu'avant. Trois cas doivent **rester en 201** :
   - **a.** rattacher à A un employé déjà rattaché à A, par un compte affecté à A ;
   - **b.** **poser le PREMIER rattachement d'un orphelin** — le geste central de l'étape 4, et le
     seul que le trou ci-dessus laisse passer. S'il tombe en 404, l'étape 4 est morte-née ;
   - **c.** un compte RH affecté à **A et B** rattachant à B un employé de B, avec **A comme
     établissement actif** — c'est la divergence d'axe (`PerimetreEmployeVerificateur` raisonne sur
     **toutes** les affectations, l'extension sur l'**actif**). Prouve que le processeur s'aligne sur
     le vérificateur et n'a pas inventé un troisième axe.

3. **Le garde D41 est toujours vivant** (preuve que la délégation est bien câblée) : `POST` avec un
   `etablissement` hors périmètre **et** un employé légitime → **404**, rendu par
   `EstablishmentScopeWriteGuard`. Si ce test est vert **avant** l'étape et rouge après, le processeur
   a été branché sur le processeur interne — le défaut exact que A-2 décrit.

### Dépendances
Étape 1. Aucune sur le front.

---

## Étape 3 — G-1 / G-2 : l'onglet « Qualifications »

**Zone sensible :** aucune côté serveur (les 4 opérations existent depuis l'origine). Front seul.

### Ce qu'elle change

**Modifié** — `frontend/src/api/client.js`, quatre appels neufs après le bloc Personnel (l. 2384+) :
`qualifications(params)`, `qualification(id)`, `creerQualification(corps)`, `majQualification(id, corps)`.

⚠ **`method:` doit être sur la MÊME ligne que `request(`** — la mesure d'écart détecte la méthode HTTP
sur la ligne d'appel (avertissement écrit dans `client.js:402`). Un `method` renvoyé à la ligne
suivante fait compter l'opération comme non appelée.
⚠ Le `PATCH` part en `application/merge-patch+json` — déjà géré par `request()` (`client.js:217-218`).

**Modifié** — `frontend/src/pages/Personnel.jsx` :

- un **6ᵉ sous-onglet** `qualifications` dans la liste de `:51-62` ;
- `OngletQualifications({ etabActif, droits })` : liste à plat, filtres `employe` et `type`
  (`SearchFilter` exact, `Qualification.php:41`), tri par défaut `dateValidite` croissant ;
- **trois états visuels** calculés dans l'écran : `expiree` (`crit`), `bientot` (`warn`), `valide`
  (`good`).
  ⚠ **La table de couleurs se construit sur ce que le serveur produit réellement.**
  `Qualification::getStatut()` (`:147-151`) ne rend que **deux** valeurs, `'valide'` et `'expiree'` —
  l'état « bientôt » **n'existe pas côté serveur** et se calcule ici. Ne pas indexer une table de
  statuts sur un troisième nom que rien ne rend : c'est le piège déjà payé sur `COUV`
  (`Personnel.jsx:35`).
- **`const SEUIL_EXPIRATION_JOURS = 14`**, avec un commentaire disant qu'il double
  `CheckQualificationsCommand::HORIZON_PAR_DEFAUT` et que **l'étape 7 le remplacera** par le paramètre
  d'établissement. Une valeur en dur qui s'annonce provisoire, pas une qui se croit définitive.
- formulaire de création/correction gardé par `aLeDroit(droits, 'personnel.gerer_qualification')` ;
- ⚠ si `type = autre`, le **libellé est obligatoire** (`QualificationLibelleCoherentValidator`) — le
  formulaire doit le refléter plutôt que laisser le serveur refuser ;
- **état vide en toutes lettres**, et affichage du **message rendu par l'API** en cas de refus.

### Comment on prouve qu'elle marche

- **Avant :** relever `npm run mesurer-ecart` ; `grep -n "qualification" frontend/src/api/client.js`
  rend **0 ligne d'appel** (hors `qualification_encadrant`, autre module).
- **Après :** la mesure descend de **4** ; `npm run garde-fou-ecart` passe.
- **En exécutant**, contre l'API réelle, base semée : créer une qualification, la retrouver, corriger
  sa `dateValidite`, recharger, voir la correction → **G-1**. La J+7 s'affiche `warn`, la J−30 `crit`,
  la J+400 `good`, et le tri place J+7 avant J+21 avant J+400 → **G-2**.
- **Garde-fous front :** `verifier-droits.mjs` (D39 — `aLeDroit(undefined, …)` répond « non » en
  silence), `verifier-imports.mjs`, `verifier-portee.mjs` (n°40 — variables déclarées dans `Personnel`
  et lues dans un sous-composant : le défaut est déjà arrivé deux fois dans ce fichier).
- **Témoin négatif d'écran :** avec *Responsable Planning* (`personnel.lire`, **sans**
  `gerer_qualification`), la liste s'affiche et le bouton de création **n'apparaît pas**. Un bouton
  qui apparaît et rend 403 serait un écran qui ment.

### Dépendances
Étape 1 (sinon la liste est vide et les trois états ne se démontrent pas).

---

## Étape 4 — G-3 / G-4 : la fiche employé

**⚠ ZONE SENSIBLE — cet écran pilote l'écriture gardée à l'étape 2.**

### Ce qu'elle change

**Modifié** — `frontend/src/api/client.js` : `employe(id)`, `majEmploye(id, corps)`,
`rattachements(params)`, `creerRattachement(corps)`, `majRattachement(id, corps)`,
`supprimerRattachement(id)`.
(Les filtres nécessaires existent : `RattachementEmploye?employe=`, `Absence?employe=`,
`BadgeStaff?employe=` — `RattachementEmploye.php:41`, `Absence.php:65`, `BadgeStaff.php:82`.)

**Modifié** — `Personnel.jsx` : un composant `FicheEmploye`, ouvert depuis une ligne de
`ListeEmployes`. Quatre blocs :

1. **Identité, modifiable** (G-3) : `nom`, `prenom`, `matricule`, `poste`, `typeContrat`,
   `dateEntree`, `dateSortie`.
   ⚠ **`statut` n'est pas proposé** : il n'est pas dans `employe:write` (`Employe.php:106-108`) et se
   change par `/suspendre` et `/reactiver`, déjà branchés. Le proposer produirait un champ qui a l'air
   d'écrire et n'écrit rien.
   ⚠ **`utilisateur` n'est pas proposé non plus**, bien qu'il soit dans `employe:write`
   (`Employe.php:69-71`) : c'est le lot 3, et l'exposer ici sans écran de choix de compte offrirait un
   champ IRI brut.
2. **Rattachements** (G-4) : liste + ajout + modification + retrait.
3. **Bandeau orphelin**, quand la liste est vide — *« Pas encore rattaché à un site : il ne peut pas
   recevoir de badge, et n'est visible que du rôle RH »*, avec le bouton qui pose le rattachement. Les
   deux conséquences sont **mesurées** : `EmissionBadgeStaffHandler.php:56-61` (RG-PERSO-09) et
   `PerimetrePersonnelExtension.php:199-211`.
4. **Qualifications** (renvoi vers l'onglet de l'étape 3, pré-filtré), **absences** et **badges** **en
   lecture seule** (amendement du 2026-09-08).

### Comment on prouve qu'elle marche

- **Après :** `npm run mesurer-ecart` descend de **7** de plus (1 `Get` employé + 1 `Patch` employé
  + 5 rattachement).
- **En exécutant :** corriger le poste depuis la fiche, recharger, voir la correction → **G-3** ;
  ouvrir la fiche de l'**orphelin** : le bandeau apparaît ; poser un rattachement : **le bandeau
  disparaît** → **G-4, première moitié**.
- ⚠ **G-4, seconde moitié (« l'émission aboutit ») dépend de l'étape 5.** Elle ne peut pas être
  prouvée ici : l'émission échoue en 422 avant d'atteindre le contrôle de rattachement (§0.1).
  **Ne pas déclarer G-4 satisfaite à la fin de cette étape.**
- **Témoin négatif d'écran :** avec *Lecture seule*, la fiche s'ouvre, aucun bouton d'écriture, et
  **l'orphelin n'est pas dans la liste** — l'écran ne doit pas afficher « fiche introuvable » comme
  une erreur technique quand c'est le cloisonnement qui répond.
- **Le mensonge du zéro :** un `GET /api/rattachement_employes?employe=…` qui rend `[]` **sur un
  refus** ferait afficher le bandeau orphelin à tort, et inviterait à créer un doublon. L'écran doit
  distinguer *liste vide* de *appel en échec*, et ne jamais afficher le bandeau tant que la requête
  n'a pas abouti.

### Dépendances
Étapes 1 et 2. **L'étape 2 est un prérequis dur** : cet écran envoie `employe` **et** `etablissement`
dans le corps ; le livrer avant sa garde exposerait l'écriture non gardée à un vrai utilisateur.

---

## Étape 5 — Réparer l'émission de badge, et livrer G-7

**⚠ ZONE SENSIBLE — contrôle d'accès physique. Un badge est une clé.**

Cette étape n'était **pas** dans la spec : elle naît de la mesure §0.1. Sans elle, G-4 n'est pas
démontrable et G-7 n'a rien à montrer.

### Ce qu'elle change

**Modifié** — `client.js:2418-2419` : `emettreBadgeStaff(employeId, corps)` prend un **corps** au lieu
de `{}`. Plus `porteesAcces(params)` (`PorteeAccesEmploye?badgeStaff=`, filtre déclaré
`PorteeAccesEmploye.php:38`).

**Modifié** — `Personnel.jsx:394` : le bouton « Émettre » ouvre une **modale** recueillant les trois
champs que le serveur exige :
- `etablissement` — pré-rempli avec l'établissement **actif** ;
- `modeHoraire` — `shifts_uniquement` | `permanent` ; en `shifts_uniquement`, `margeAvantApres`
  devient **obligatoire** (`EmissionBadgeStaffHandler.php:72-74`) ;
- `espacesAutorises` — **au moins un**, choisi dans `api.espacesAcces()` (déjà présent,
  `client.js:1500`).

**Modifié** — le détail d'un badge affiche sa portée : espaces ouverts, `modeHoraire`,
`margeAvantApres` → **G-7**.
⚠ **Ne pas construire d'affichage « aucune zone »** : le serveur refuse d'émettre sans zone (§0.2).
Un état vide qu'aucun chemin ne produit est du code mort qui rassure.

### Comment on prouve qu'elle marche

- **Le test qui échoue AVANT.** Aujourd'hui `api.emettreBadgeStaff(id)` rend **422 « Référence
  "etablissement" obligatoire »**. C'est le rouge de départ, et il se constate **en exécutant** —
  aucun test PHP ne le voit, puisque `BadgeStaffTest.php` envoie le corps complet.
- **Après, l'enchaînement complet qui prouve G-4 :** ouvrir la fiche de l'orphelin → bandeau →
  **tenter l'émission** → refus du serveur avec sa raison affichée (« aucun rattachement actif sur cet
  établissement, RG-PERSO-09 ») → **poser le rattachement** → bandeau disparu → **émettre** → **201**,
  le badge apparaît dans l'onglet Badges et sa portée nomme l'espace choisi.
  **C'est le test qui prouve que le cul-de-sac est refermé.**
- **Témoin négatif :** émettre pour un employé **déjà porteur d'un badge actif** sur le même
  établissement → **409** (`EmissionBadgeStaffHandler.php:63-70`), affiché avec la raison du serveur.
  Un écran qui rendrait 201 deux fois signalerait qu'il court-circuite le handler.
- `npm run mesurer-ecart` descend de **2**.

### Dépendances
Étapes 1 et 4.

---

## Étape 6 — G-6 : modifier un créneau, et le lien roster → qualification

**Zone sensible :** `RosterProvider` est un provider **sur mesure** — aucune extension Doctrine ne s'y
applique, il filtre lui-même (`:53-54`, `:154+`).

### Ce qu'elle change

- **Modifié** — `client.js` : `majCreneauTravail(id, corps)`.
- **Modifié** — `Personnel.jsx`, composant `PlanningTravail` : édition d'un créneau (horaires,
  `libellePoste`, `effectifRequis`, `qualificationRequise`) → **G-6**.
- **Modifié** — `RosterHebdomadaire` et `RosterProvider.php:116-120` : `employesAffectes` publie
  désormais, **en plus du nom**, l'`id` de l'employé et l'`id`/`type` de la qualification utilisée.
  ⚠ **Ce n'est pas une opération neuve** : un champ de plus dans une ressource déjà exposée. Le
  cliquet d'écart ne bouge pas, A-9 ne s'applique pas.
- **Modifié** — `Personnel.jsx:99-114` : le badge rouge « manquante ou périmée » devient **cliquable**
  et mène à l'onglet Qualifications, pré-rempli `employe` + `type`.
  ⚠ **Deux cas distincts derrière le même badge**, et un seul se pré-remplit.
  `RosterProvider.php:121-134` pose `qualificationManquanteOuExpiree = true` dès qu'**aucune**
  affectation ne couvre le type requis — y compris quand le créneau n'a **personne** dessus. Dans ce
  cas il n'y a **pas d'employé** à pré-remplir : le lien mène alors à l'onglet filtré sur le **type
  seul**, et n'invente pas un employé.

### Comment on prouve qu'elle marche

- **G-6, le test qui échoue AVANT :** créer un créneau, y poser **deux** affectations, modifier son
  heure de fin. **Après :** `GET /api/affectation_travails?creneauTravail=<id>` rend toujours **2**
  éléments. *Avant, le seul chemin était annuler + recréer, qui perd les deux.* La preuve porte sur ce
  qui **survit**, pas sur le code de retour du `PATCH`.
- **Le lien roster :** sur le créneau semé avec une qualification **expirée**, cliquer le badge ouvre
  l'onglet pré-filtré sur **cet** employé et **ce** type ; prolonger la `dateValidite` ; revenir au
  roster : le badge **disparaît**. Fermeture littérale du cul-de-sac.
- **Témoin négatif de cloisonnement (provider sur mesure) :**
  `app/tests/Personnel/Api/ProvidersCloisonnementTest.php` doit rester vert **et s'étendre au champ
  neuf** : un compte affecté à A seul ne doit pas voir l'`id` d'un employé d'un créneau de B. Un champ
  ajouté à une charge utile est une **nouvelle voie de sortie** : le témoin doit porter sur cette
  voie, pas sur l'organe de lecture qu'on croit déjà prouvé.
- `npm run mesurer-ecart` descend de **1**.

### Dépendances
Étapes 1 et 3.

---

## Étape 7a — `HrSettings` : entité, migration, résolveur, commande

**⚠ ZONE SENSIBLE — entité neuve, migration, cloisonnement.**
**Aucune opération d'API n'est exposée à cette étape** (pas d'`#[ApiResource]`) : le cliquet d'écart ne
bouge pas, ce qui permet de prouver le back avant que le front s'y branche.

### Ce qu'elle change

**Créé** — `app/src/Personnel/Entity/HrSettings.php` (fichier neuf → **D5, nommage anglais**) :
- `#[ORM\Table(name: 'personnel_hr_settings')]`, `UniqueConstraint` sur `establishment_id` ;
- `private ?Etablissement $establishment` — `OneToOne`, `JoinColumn(nullable: false, unique: true)` ;
- `private int $expiringSoonHorizonDays = 14` — `smallint`, `default 14`, `Assert\Range(1, 365)` ;
- `public const DEFAULT_EXPIRING_SOON_HORIZON_DAYS = 14;`

⚠ **Vérifier chaque nom contre `bin/nommage.lexique-francais.txt`** (143 lignes) : `seuil` **y est**
(l. 61), `employe` **y est** (l. 86). Un champ `seuilExpiration` ou une table `personnel_employe_*`
serait refusé par le garde-fou n°2. Il ne contrôle que classes, cas d'énumération, tables, colonnes et
codes de permission — le lexique est **partiel**, la règle ne l'est pas.

⚠ **Aucun code de permission neuf.** Lecture `personnel.lire`, écriture `personnel.gerer_qualification`
— deux des 8 permissions réellement déclarées. Ne **jamais** utiliser `personnel.gerer` : elle
n'existe dans aucune migration ni fixture et garde déjà 6 opérations de `Project`/`ProjectTask`/
`Calendar` qui ne passent que par leur alternative.

**Créé** — `app/src/Personnel/Service/HrSettingsResolver.php` :
`horizonDays(?Etablissement): int` → la valeur de la ligne, ou la constante.
**Un seul calcul, tous les appelants** — c'est ce qui garantit que l'écran et la commande ne se
contrediront pas. Patron : `RelancerCasierHandler.php:37`, `PossProrataCalculator.php:41`.

**Créé** — `app/migrations/Version2026<AAAAMMJJHHMMSS>.php`, **écrite à la main**.
⚠ Demander d'abord le SQL par `doctrine:schema:update --dump-sql`, **jamais** `migrations:diff`.
⚠ L'horodatage du nom est en **UTC** : le vérifier contre la dernière migration existante pour ne pas
insérer une migration *avant* une existante.
`up()` : `CREATE TABLE personnel_hr_settings` + FK vers `org_etablissement` + index unique.
`down()` : `DROP TABLE`.
**Aucune ligne semée** : le repli sur la constante rend le produit correct sans donnée, et une
migration qui insère une ligne par établissement se périme au prochain établissement créé.

**Modifié** — `PerimetrePersonnelExtension.php:50-56` : ajout de
`HrSettings::class => '{root}.establishment'` + l'`use`. Le mécanisme partagé n'a **rien** à changer
(§0.3). ⚠ Ce fichier **n'est pas neuf**, D5 ne s'y applique pas.

**Modifié** — `CheckQualificationsCommand.php` :
- `HORIZON_PAR_DEFAUT = 14` devient le **repli** de `HrSettingsResolver`, plus la source ;
- `--jours` explicite **prime sur tout** (comportement inchangé) ;
- sans `--jours` : fenêtre de requête = **maximum** des seuils configurés (A-6), puis chaque
  affectation filtrée avec le seuil de **l'établissement de son créneau**.
⚠ **La commande garde son en-tête corrigé.** Son docblock porte déjà la trace de deux affirmations
fausses qui ont survécu à leur correction. Ne pas y ajouter une troisième phrase qui décrira l'état
d'avant.

### Comment on prouve qu'elle marche

`app/tests/Personnel/Api/CheckQualificationsCommandTest.php` (**existant**, à étendre) :
1. Semer une affectation dont la qualification expire à **J+20**, sur `Site A Personnel`.
2. Sans ligne `HrSettings` : la commande **ne la signale pas** (horizon 14). *Vert avant comme
   après — c'est la non-régression.*
3. Insérer `HrSettings(A, 30)`.
4. La commande **la signale**. **Avant l'étape, ce test échoue** : rien ne lit la table.

**Le témoin qui prouve que le résolveur est per-établissement, pas global** (A-6) : semer une seconde
affectation à J+20 sur `Site B Personnel` **sans** ligne `HrSettings`. Après l'étape 3, la commande
signale **celle de A et pas celle de B**. Un résolveur qui prendrait le maximum comme seuil effectif
(au lieu de simple fenêtre de requête) signalerait les deux — défaut invisible sur un jeu de données à
un seul établissement.

**Voir le filet attraper :** casser volontairement le résolveur (rendre la constante en toute
circonstance) une minute, et vérifier que le test **tombe**. Un vert peut l'être pour une raison qui
n'a rien à voir.

**Migration :** `./bin/verifier-derive-schema.sh` — aucune dérive après application.
`garde-fou-migrations-immuables.sh` vert.
**Garde-fous d'entité neuve :** n°2 (nommage), n°19 (`nullable` sur colonne non nulle — `$establishment`
est `?Etablissement` sur `nullable: false` : motif exact du garde-fou, à traiter en posant
l'établissement au plus tôt), n°34 (pas de collision : `establishment` n'est dans aucun groupe
d'écriture), n°29 (silencieux, aucun `Post`).
⚠ **Le garde-fou n°28 restera vert et ne prouvera RIEN** sur `'{root}.establishment'` (§0.3). Ne pas
l'invoquer comme preuve de cloisonnement.

### Dépendances
Aucune sur le front. Peut être menée **en parallèle** de 3 à 6.

---

## Étape 7b — `HrSettings` : l'exposer et la brancher à l'écran

**⚠ ZONE SENSIBLE — cloisonnement en lecture par un provider sur mesure, et écriture d'une entité
anglaise invisible au décorateur global. Soumise à A-9.**

### Ce qu'elle change

**Modifié** — `HrSettings.php` : ajout de l'`#[ApiResource]` —
`Get` et `Patch` sur `uriTemplate: '/personnel/hr-settings'`, `provider: HrSettingsProvider::class`,
`processor: HrSettingsProcessor::class` pour le `Patch`, `security` respectivement `personnel.lire` et
`personnel.gerer_qualification`.
- `expiringSoonHorizonDays` : `['hr_settings:read', 'hr_settings:write']` ;
- **`establishment` : `['hr_settings:read']` SEUL.** C'est D41, et c'est vital ici :
  `EstablishmentScopeWriteGuard.php:68` teste `method_exists($data, 'getEtablissement')` — le nom
  **français**. `HrSettings::getEstablishment()` lui est **invisible**. Le décorateur ne rattrapera
  rien ; il n'y a donc rien à lui donner à rattraper.

**Créé** — `app/src/Personnel/State/HrSettingsProvider.php` :
- résout `ContexteEtablissement::etablissementActif()` → **404** si nul (pas de 422 : on n'affirme pas
  qu'une ressource existe ailleurs) ;
- `findOneBy(['establishment' => $actif])` ;
- **si absent, rend un `HrSettings` transitoire** (non persisté) portant l'établissement actif et les
  défauts de colonne. Le `Get` répond **200 avec les valeurs par défaut**, jamais 404 — un écran qui
  reçoit 404 afficherait « pas de réglage » là où il y a un réglage effectif.
- ⚠ **Le provider filtre lui-même.** `PerimetrePersonnelExtension` ne s'applique **pas** à un provider
  sur mesure : famille de défauts « providers sur mesure et cloisonnement », deux fuites déjà trouvées
  ainsi dans ce dépôt. La déclaration A-4 satisfait le garde-fou n°35 ; elle ne protège pas cette route.
- ⚠ **Un `Get` n'écrit rien.** Ne pas persister à la lecture : sonder par une lecture laisserait une
  trace définitive sur une ressource sans `Delete`.

**Créé** — `app/src/Personnel/State/HrSettingsProcessor.php` :
- `$this->scope->assertReachable($data->getEstablishment())` (`EstablishmentScopeAsserter`) — le geste
  que le décorateur global ne peut pas faire pour une entité anglaise. **404**, jamais 403 ;
- délégation à `#[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]`, qui
  persiste l'objet transitoire au premier `Patch` (l'upsert). Même règle qu'à l'étape 2.

**Modifié** — `client.js` : `hrSettings()`, `majHrSettings(corps)`.
**Modifié** — `Personnel.jsx` : sur l'onglet Qualifications, un champ « **Alerter quand une
qualification expire dans moins de N jours** », gardé par `personnel.gerer_qualification`.
**`SEUIL_EXPIRATION_JOURS` (étape 3) est supprimée** et remplacée par la valeur chargée ; le repli, si
l'appel échoue, reste **14** — jamais une liste sans couleur, jamais un `NaN` en seuil.
Le libellé dit explicitement que **la commande `personnel:qualifications:verifier` utilise le même
réglage** : c'est la seule chose qui rende l'exigence visible à l'exploitant.

### Comment on prouve qu'elle marche

`app/tests/Personnel/Api/HrSettingsTest.php` :

1. **Le défaut par défaut :** `GET /api/personnel/hr-settings` (RH sur A, aucune ligne) → **200**,
   `expiringSoonHorizonDays = 14`. *Avant l'étape : 404 (route inexistante).*
2. **L'upsert :** `PATCH` à `30` → **200** ; la table contient **1** ligne ; second `GET` → `30`. Un
   second `PATCH` à `21` → toujours **1** ligne.
3. **⚠ Le cloisonnement en lecture, prouvé par une écriture croisée :** poser `30` sur A, puis `GET`
   avec **B comme établissement actif** → **14** (le défaut), pas 30. Puis poser `7` sur B, et re-lire
   A → **30**. Deux lignes, deux valeurs, aucune fuite.
   *Une lecture qui rendrait 30 sur B signalerait que le provider ignore l'actif — et cette fuite ne
   produit pas d'erreur, elle produit une valeur plausible.*
4. **⚠ TÉMOIN NÉGATIF — le cas que le contrôle doit AUTORISER :** un compte RH affecté à **A et B**
   doit pouvoir écrire sur **les deux**, en basculant l'en-tête `X-Etablissement`. Un `assertReachable`
   trop large refuserait le second et **tous les tests de refus resteraient verts** : seul ce cas-là le
   démasque.
5. **Sans en-tête `X-Etablissement`** → **404**, pas 500 ni une valeur par défaut sortie de nulle part.
6. **Droits :** *Responsable Planning* (`personnel.lire`, sans `gerer_qualification`) **lit** (200) et
   **n'écrit pas** (403). Le refus de **permission** est un 403 ; le refus de **périmètre** est un 404 ;
   deux questions différentes, et le test doit les distinguer.

**La cohérence écran ↔ commande, prouvée de bout en bout** (l'exigence de l'amendement) : poser `30`
depuis l'écran sur A, puis lancer `personnel:qualifications:verifier` **sans `--jours`** : elle signale
l'affectation à J+20 de A. C'est la mesure qui prouve que les deux lisent la **même** ligne, et non
deux constantes qui se ressemblent aujourd'hui.

### Dépendances
Étapes 3 et 7a.

---

## Étape 8 — Clôture : les cliquets, la suite, l'exécution

**Zone sensible :** le regel du cliquet d'écart.

- **Modifié** — `frontend/scripts/ecart.ligne-de-base.json`, **en dernier et une seule fois** :
  `npm run geler-ecart`.
  ⚠ **Ce geste gèle la dérive de toutes les autres sessions en même temps que la baisse de celle-ci.**
  Il ne se fait qu'après un `git merge main` frais, et le nouveau plafond doit être inférieur à
  l'ancien de ce qu'on a réellement branché :
  `4 (qualifications) + 7 (employé/rattachements) + 2 (portées) + 1 (créneau) − 2 (HrSettings, neuves) = −12`.
  Un écart mesuré différent se **comprend avant** de geler ; il ne se gèle pas parce qu'il est plus petit.
- **Modifié** — `COORDINATION/DECISIONS.md` : la décision produit du 2026-09-08 (Fluvia va jusqu'aux
  bulletins et à la DSN), avec sa contrepartie. **Append-only.**
- **Non modifié :** `app/config/reference.php` — régénéré par la suite, `git checkout --` avant commit.

### Comment on prouve

1. `./bin/garde-fous.sh` **vert** — et le verdict doit **nommer ce qui n'a pas tourné** : les contrôles
   front sont sautés sans `node`, et un contrôle sauté ne fait pas baisser le vert, il fait baisser le
   **total**.
2. `test-stack.sh run <jeton>` sur **tout le domaine touché** : `tests/Personnel`, plus `tests/Finance`
   et `tests/Calendar` (ils construisent des `Employe` : `ExpenseReportApiTestCase.php:73,106`,
   `CalendarTest.php:319,392`). ⚠ Dans le **worktree**, jeton propre.
   ⚠ Un vert partiel s'annonce **avec son périmètre**. Ce lot modifie une entité, un provider partagé
   et une extension de cloisonnement : « vert sur le module touché » ne dit rien de `main`.
3. **En exécutant** (Vite + tunnel contre l'API réelle, base semée) : les critères d'acceptation de la
   spec, dans l'ordre, avec **G-4 en enchaînement complet** (étape 5).
4. `./bin/operations-sans-ecran.sh Personnel` : la liste nommée du module doit avoir **rétréci des
   opérations branchées**.

---

## RISQUES

### R-1 — ⚠ G-4 est indémontrable sans l'étape 5, qui n'était pas dans la spec
`api.emettreBadgeStaff` envoie `{}` et le serveur exige trois champs (§0.1). Si l'étape 5 est écartée,
**G-4 doit être déclaré non satisfait**, pas « satisfait côté rattachement ». Ce que ça touche : un
geste de contrôle d'accès physique ; une modale mal remplie pose de mauvaises zones sur un badge réel.

### R-2 — ⚠ Le trou de l'orphelin reste ouvert après G-5, par construction
`PerimetreEmployeVerificateur` rend `true` pour un employé sans rattachement, et `Employe` ne porte
**aucun** ancrage. Quiconque détient `personnel.gerer_employe` quelque part et connaît l'UUID d'un
orphelin d'un autre client peut le rattacher chez lui, donc le capturer. **Le fermer casserait le geste
que le lot livre.** Ce qui le lèverait : une colonne d'ancrage sur `Employe` — lot dédié.

### R-3 — ⚠ Le garde-fou n°28 ne contrôlera pas la ligne de cloisonnement de `HrSettings`
Mesuré (§0.3). **Un vert du n°28 après l'étape 7a serait un vert qui n'a pas mesuré.** La seule preuve
valable est le test croisé A/B de l'étape 7b. Deux issues, aucune tranchée ici parce qu'elle touche un
garde-fou partagé par dix sessions : élargir le motif à `[\w.{}]+`, ou déclarer l'exemption en en-tête.

### R-4 — Le seuil global d'un exploitant multi-sites
A-6 résout par établissement du créneau. Un exploitant qui règle 30 sur A et laisse 14 sur B recevra un
rapport où deux créneaux identiques sont traités différemment. **C'est le comportement demandé**, mais
il surprendra. Ce qui le lèverait : une décision disant si le seuil est par établissement ou par groupe.

### R-5 — `PersonnelFixtures` est la ligne de base de 9 fichiers de test
A-1 l'évite. Si quelqu'un enrichit `PersonnelFixtures` — ce que la lettre de G-8 suggère —
**`LireSoiPersonnelTest.php:110` casse** et le harnais de 4 modules bouge.

### R-6 — `RosterProvider` est un provider sur mesure, et l'étape 6 élargit sa charge utile
Aucune extension ne le protège : il filtre lui-même. Ajouter l'`id` d'un employé y ajoute une **voie de
sortie**. `ProvidersCloisonnementTest.php` doit être étendu **à ce champ**, pas seulement relancé — un
témoin sur un autre champ prouve que le provider filtre là-bas, jamais ici.

### R-7 — Le 403 des trois processeurs existants contredit le 404 de G-5
`AffecterEmployeProcessor.php:70`, `EmissionBadgeStaffProcessor.php:69`,
`CreerCreneauTravailProcessor.php:60` lèvent `AccessDeniedHttpException`. Le lot ajoute un quatrième
point qui rend **404** : le module devient incohérent avec lui-même. **Choix fait sans certitude** :
la spec et la convention du 06/09 l'emportent sur l'homogénéité locale. Ce qui le lèverait : une
décision de Maxime — (a) aligner les trois sur 404 dans ce lot (3 processeurs et leurs tests, hors
périmètre annoncé), (b) laisser la divergence et la consigner, (c) lot d'alignement séparé.

### R-8 — Le cliquet d'écart peut geler la dérive d'autres sessions
`geler-ecart` réécrit un plafond global. Sans `git merge main` frais, il gèle ce que huit autres
sessions ont ajouté. **Un seul geler, en toute fin, après merge.**

### R-9 — Points choisis sans certitude, et ce qui les lèverait

| Choix | Ce qui le lèverait |
|---|---|
| **URI singleton** plutôt que `Get`+`Patch` par id (patron Piscine/Musée) | Le patron cité n'a aucun moyen de créer sa première ligne hors fixtures, et `Get /{id}` est inutilisable sans id. Préférer l'homogénéité stricte imposerait un `Post` (donc `@sans-suppression:` pour le n°29) et un `GetCollection` filtré : trois opérations au lieu de deux, pour le même service. |
| **`personnel.gerer_qualification`** comme permission d'écriture du réglage | Le seuil concerne les qualifications, mais un exploitant pourrait attendre `gerer_employe`. Créer `personnel.gerer` serait pire : elle n'existe nulle part. Arbitrage produit. |
| **Seuil 14 par défaut** plutôt que 30 | Choix conservateur : la migration ne change aucun comportement. Si l'usage veut 30, c'est un `UPDATE`, pas un correctif. |
| **Le lien roster ne pré-remplit pas d'employé quand le créneau est vide** | `RosterProvider:121-134` allume le même drapeau dans deux situations distinctes. Les distinguer demanderait deux champs côté serveur — une charge utile de plus, donc une décision. |
| **Les badges ne sont pas semés par `HrDemoFixtures`** | Les poser demanderait de rejouer l'appairage et la séquence de snapshot hors du handler. Il faudrait appeler `EmissionBadgeStaffHandler` depuis la fixture — faisable, mais c'est un service applicatif dans une fixture, sans précédent mesuré ici. |

### R-10 — Ce que ce lot ne referme pas, et qu'il faut consigner sans attendre
- **D88 reste ouvert** : les zones d'un badge viennent du **corps de la requête**, pas de la fonction
  de l'employé (§0.2). L'étape 5 fait fonctionner l'émission ; elle ne comble pas D88.
- **`personnel.gerer` n'existe toujours pas** et garde 6 opérations de `Project`/`ProjectTask`/
  `Calendar`. Défaut réel, hors module.
- **Le rôle *Personnel Administrateur RH* n'a aucune permission `dms.*`**
  (`PersonnelFixtures.php:104-107`) : l'import d'un contrat par le RH sera impossible au lot 2, avant
  même d'écrire une ligne.
- **La destination de `personnel:qualifications:verifier`** : l'étape 7a lui donne le bon seuil ; elle
  imprime toujours dans les journaux d'un conteneur que personne ne lit. **Chantier à part**, et il ne
  doit pas être planifié en l'état.
