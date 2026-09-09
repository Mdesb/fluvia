# Spec — personnel-rh, lot 1 : refermer l'écart du module Personnel

**Statut :** **validée (CP-1)** — accordée par Maxime le 2026-09-08
**Auteur :** Claude (session `allaccess-a1`)
**Date :** 2026-09-08 · amendée le 2026-09-08 après CP-1 (seuil réglable, contenu de la fiche)
**Matière première :** `features/personnel-rh/refs/faits-verifies-rh.md` (tous les faits ci-dessous
y sont sourcés `chemin:ligne`).

---

## Contexte & problème

Le module Personnel a un serveur riche — 8 entités, 52 fichiers, ~36 opérations d'API — et **un seul
écran**, `frontend/src/pages/Personnel.jsx` (632 lignes, 5 sous-onglets). **13 de ces 36 opérations ne
sont déclenchables par personne** : `Qualification` (4), `RattachementEmploye` (5),
`PorteeAccesEmploye` (2), plus le `PATCH` de `Employe` et celui de `CreneauTravail`.

Ce n'est pas une liste de manques abstraits : **deux d'entre eux forment un cul-de-sac**, c'est-à-dire
un endroit où le produit refuse un geste et n'offre aucun moyen de lever le refus.

**Cul-de-sac n°1 — la qualification.**
`AffecterEmployeProcessor.php:100` refuse d'affecter un employé à un créneau qui exige une
qualification qu'il ne détient pas (422, CA-5). `RosterProvider.php:134` publie
`qualificationManquanteOuExpiree`, et `Personnel.jsx:102` affiche un badge rouge nommant le brevet
attendu. Le produit **désigne** donc le problème avec précision — et `POST /api/qualifications`,
exposé depuis l'origine, n'est appelé par aucun écran. Ajouter le BNSSA d'un maître-nageur passe
aujourd'hui par la base de données.

**Cul-de-sac n°2 — le rattachement.**
`EmissionBadgeStaffHandler.php:122-138` refuse un badge à tout employé sans `RattachementEmploye`
actif sur l'établissement (RG-PERSO-09). Or `Personnel.jsx:541-548` n'envoie à la création que
`{nom, prenom, poste, typeContrat, matricule?, dateEntree}` — **jamais de rattachement**. Tout employé
créé par le produit naît donc orphelin : pas de badge possible, invisible pour quiconque n'a pas
`personnel.gerer_employe` (`PerimetrePersonnelExtension.php:199-211`), et **aucun écran ne sait le
rattacher**.

S'y ajoutent deux constats qui ne sont pas des manques d'écran :

- **Une écriture non gardée.** `RattachementEmploye` expose `employe` **et** `etablissement` en
  écriture et n'a aucun processor. L'établissement est rattrapé par le décorateur global
  `EstablishmentScopeWriteGuard.php:49` (404 hors périmètre) ; **l'employé n'est rattrapé par rien**.
  Et `PerimetreEmployeVerificateur::estDansLePerimetre` rend `true` pour un employé sans rattachement
  — c'est-à-dire précisément l'état qu'un écran de rattachement manipule.
- **Aucun employé en base.** `new Employe(` : **0 occurrence dans `app/src`** (témoin positif :
  5 fichiers sous `app/tests`). Aucune fixture ne sème d'employé — un écran RH livré aujourd'hui se
  démontrerait sur une liste vide.

---

## Décision produit à acter (préalable au CP-1)

**La demande renverse une exclusion écrite du cahier des charges.**
`specs/personnel/spec-personnel.md:72` — section « Exclu (pour l'instant) » : « Recrutement,
entretiens, dossiers administratifs complets (contrats signés, bulletins de salaire, DPAE) — hors
périmètre logiciel de billetterie/accès, relève d'un SIRH. » Et `:59-63` : « Paie, solde de congés
légal, arrêts maladie CPAM, éléments variables de paie — délégués à un SIRH externe (décision actée
du cahier, panel `p-m5`) », repris en RG-PERSO-10 (`:175-177`).

**Aucune décision `Dxx` ne lève cette exclusion.** Le 2026-09-08, Maxime a arbitré que Fluvia irait
« jusqu'aux bulletins et à la DSN », en connaissance de la réserve énoncée (la DSN est une obligation
mensuelle normée NEODeS avec agrément ; une erreur de paie se règle aux prud'hommes).

→ **À inscrire dans `COORDINATION/DECISIONS.md`** à la validation de cette spec, avec sa contrepartie :
Fluvia devient un SIRH, la spec `personnel` doit être amendée, et chaque lot dira explicitement ce qui
est **opposable** (produit un document ou une déclaration qui engage) et ce qui ne l'est pas encore.

**Ce lot 1 ne dépend pas de cet arbitrage** — il ne construit rien que la spec exclue. Il est
livrable même si la décision est retardée.

---

## Objectifs

- **G-1 — Saisir et tenir à jour les qualifications d'un employé.** Lister, ajouter, corriger,
  prolonger. Les 4 opérations `Qualification` deviennent atteignables, gardées par
  `personnel.gerer_qualification` en écriture et `personnel.lire`/`lire_soi` en lecture.
- **G-2 — Voir venir les échéances.** Une vue qui répond à « qui perd sa qualification bientôt, et
  qui est déjà planifié dessus ». `Qualification.estValideA()` calcule le statut à la volée (le
  statut n'est pas persisté — décision n°10 du plan initial) ; l'écran s'appuie sur le `DateFilter`
  déjà déclaré sur `dateValidite` et le `SearchFilter` sur `employe`/`type`.
- **G-2b — Le préavis d'expiration est réglable par établissement.** Arbitrage Maxime du 2026-09-08.
  Une entité de paramétrage neuve, sur le patron déjà employé quatre fois dans le dépôt
  (`ParametrePiscineEtablissement`, `ParametreMuseeEtablissement`, `ParametrePmvEtablissement`,
  `ParametreFacturationEtablissement`) : relation 1‑1 avec l'établissement, opérations `Get` + `Patch`,
  valeur par défaut en colonne.
  ⚠ **`CheckQualificationsCommand` doit lire le même paramètre.** Son `HORIZON_PAR_DEFAUT = 14` est
  aujourd'hui une constante ; laisser l'écran réglable et la commande figée créerait deux réponses à
  la même question — la commande signalerait une échéance que l'écran affiche encore comme tranquille.
  C'est une exigence du lot, pas une amélioration facultative.
- **G-3 — Corriger la fiche d'un employé.** `PATCH /api/employes/{id}` devient atteignable : nom,
  prénom, matricule, poste, type de contrat, date d'entrée, date de sortie. (`statut` ne s'écrit pas
  ici — il se change par `/suspendre` et `/reactiver`, déjà branchés.)
- **G-4 — Rattacher un employé à un site, le modifier, le retirer.** Les 5 opérations
  `RattachementEmploye` deviennent atteignables depuis la fiche employé. **Facultatif à la création**
  (arbitrage Maxime du 2026-09-08 : « on va revoir cette notion de site, donc pour le moment
  facultatif »), avec un bandeau qui dit ce que l'absence coûte : ni badge, ni visibilité hors du
  rôle RH.
- **G-5 — ~~Fermer l'écriture non gardée du rattachement~~ → FIGER CE QUI LA PROTÈGE, ET NOMMER CE
  QUI NE LA PROTÈGE PAS.** *(Objectif réécrit le 2026-09-08 après mesure — voir « Le correctif qui ne
  corrigeait rien ».)*
  L'écriture **est** protégée, mais pas là où l'audit le croyait : un `employe` ou un `etablissement`
  hors périmètre n'est pas résolvable à la **dénormalisation** (API Platform passe par le provider
  d'item, donc par l'extension de cloisonnement) et la requête meurt en **400 « Item not found »**
  avant toute couche d'écriture. G-5 devient donc : **un test qui fige ce mécanisme**, pour qu'un
  élargissement futur de la résolution d'IRI fasse tomber quelque chose — et qui **mesure la limite
  qui reste ouverte**.
- **G-6 — Modifier un créneau de travail sans le détruire.** `PATCH /api/creneau_travails/{id}`
  devient atteignable, pour ne plus avoir à annuler et recréer — geste qui perd aujourd'hui les
  affectations déjà posées.
- **G-7 — Montrer la portée d'accès d'un badge.** Les 2 opérations `PorteeAccesEmploye` (lecture
  seule, créée en side-effect de l'émission) deviennent visibles : quels espaces le badge ouvre, sur
  quelle plage horaire.
- **G-9 — Réparer l'émission d'un badge, qui n'a jamais pu aboutir.** *(Ajouté après CP-1, sur une
  mesure faite pendant la planification — voir plus bas « Correction d'un fait faux ».)*
  `client.js:2418-2419` envoie **`body: {}`** ; le serveur exige `etablissement`, `modeHoraire`, et au
  moins un `espacesAutorises`. Le bouton « Émettre » de `Personnel.jsx:394` rend donc 422 depuis
  toujours. **Sans ce correctif, G-4 n'est pas démontrable** : son critère d'acceptation se termine par
  « l'émission du badge aboutit », et elle échouerait pour une raison sans rapport avec le
  rattachement.
- **G-8 — Semer des employés en base de démo.** Une fixture `PersonnelFixtures` qui crée des employés
  avec rattachements, qualifications (dont une expirée et une expirant sous 30 jours), et badges —
  pour que les écrans se démontrent et que les cas limites soient visibles sans saisie manuelle.

---

## Hors périmètre

Explicitement **pas** dans ce lot, et chacun fera l'objet de sa propre spec :

- Documents RH : contrat de travail, avenants, convention collective, DPAE, visite médicale,
  mutuelle, registre du personnel (**lot 2**).
- Création ou rattachement d'un compte utilisateur depuis la fiche employé (**lot 3**).
- Recrutement : offre, candidature, entretien (**lot 4**).
- Temps de travail réel, pointage, compteurs, solde de congés payés (**lot 5**).
- Éléments variables, bulletin de paie, DSN (**lot 6**).
- **La refonte de la notion de site**, annoncée par Maxime le 2026-09-08. Ce lot construit le
  rattachement tel que le serveur le définit aujourd'hui, **sans investissement lourd** sur cette
  surface, précisément parce qu'elle va bouger.
- La suppression d'une `Qualification` : le serveur n'expose **pas** de `Delete`. Une saisie erronée
  se corrige par `PATCH` (y compris en changeant l'employé). Limite connue, non levée ici.
- La destination de `personnel:qualifications:verifier` (la commande imprime dans les journaux d'un
  conteneur que personne ne lit). G-2 donne un écran ; **un écran ne montre que ce qu'on ouvre**, et
  le cas dangereux reste le planning monté il y a trois semaines. La notification est un chantier à
  part.
- `personnel.gerer`, permission exigée par 6 opérations de `Project`/`ProjectTask`/`Calendar` et
  créée par aucun code. Défaut réel, hors de ce module.

---

## Parcours utilisateur

Tout se greffe sur `Personnel.jsx`, qui a déjà ses 5 sous-onglets (`Employés`, `Planning`, `Roster`,
`Badges staff`, `Incidents de badge`).

**1. La fiche employé — l'objet qui manque.** Aujourd'hui tout se lit en liste ; `GET /api/employes/{id}`
n'est appelé par aucun écran. On ouvre une fiche depuis la liste. Elle porte :
l'identité (modifiable — G-3), les **rattachements** (G-4), les **qualifications** (G-1), et **en
lecture seule ses badges et ses absences** (arbitrage CP-1). La règle est nette : **on lit ici, on
agit là-bas** — émettre, révoquer, valider une absence restent dans leurs onglets dédiés, pour qu'il
n'y ait jamais deux endroits où faire le même geste.

**2. L'employé sans rattachement le dit lui-même.** Bandeau sur la fiche : *« Pas encore rattaché à un
site — il ne peut pas recevoir de badge, et n'est visible que du rôle RH »*, avec le bouton qui pose
le rattachement. Le bandeau nomme les deux conséquences réelles, mesurées, pas une inquiétude vague.

**3. Un nouvel onglet « Qualifications ».** Liste à plat, filtrable par employé et par type, avec
trois états visuels distincts : **valide**, **expire sous 30 jours**, **expirée**. Le tri par défaut
met les échéances les plus proches en tête — c'est la question qu'on se pose en ouvrant l'écran.
⚠ La table de couleurs se construit sur les états que le serveur **produit réellement**
(`estValideA()` rend un booléen ; le seuil des 30 jours est calculé côté écran), pas sur des noms
supposés — le même piège a déjà été corrigé sur `COUV` dans ce fichier (`Personnel.jsx:35`).

**4. Depuis le roster, le badge rouge devient cliquable.** Le roster nomme déjà le brevet manquant ;
il mènera à la saisie de cette qualification pour cet employé, pré-remplie. C'est la fermeture
littérale du cul-de-sac : le refus et le geste qui le lève au même endroit.

**5. États vides et cas d'erreur.** Chaque liste neuve porte son état vide en toutes lettres. Un refus
serveur s'affiche avec **la raison rendue par l'API**, jamais un message générique : un 404 sur un
rattachement hors périmètre dit « employé introuvable », un 422 de validation dit ce que le validateur
a refusé.

---

## Contraintes & décisions techniques connues

- **Cloisonnement.** `Employe` **ne porte aucun `Etablissement`** : son périmètre est entièrement
  dérivé de ses `RattachementEmploye` (`PerimetrePersonnelExtension.php:59,161-195`). L'axe de
  filtrage est l'**établissement actif** (en-tête `X-Etablissement`), pas le périmètre du lecteur.
  Aucun écran ne doit supposer qu'un employé « appartient » à un site.
- **Divergence d'axe connue.** `PerimetrePersonnelExtension` filtre sur l'établissement **actif** ;
  `PerimetreEmployeVerificateur` raisonne sur **toutes** les affectations de l'agent. Un employé peut
  donc être écrivable sans être lisible dans le même contexte. G-5 s'aligne sur le vérificateur
  existant plutôt que d'inventer un troisième axe.
- **Refus en 404, jamais 403** — convention du dépôt depuis l'audit du 06/09 (`e915c94e`) : un 403
  confirmerait l'existence de l'objet.
- **D41 — aucune entité neuve n'expose un `Etablissement` en écriture.** Ce lot ne crée aucune entité,
  donc la contrainte ne mord pas ici ; elle pèsera sur le lot 2.
- **Nommage D5** — les fichiers ajoutés utilisent des identifiants anglais. ⚠ Piège mesuré :
  `PerimetrePersonnelExtension.php:51-55` filtre sur `{root}.etablissement` **en dur en français**, et
  `EstablishmentScopeWriteGuard.php:68` teste `method_exists($data, 'getEtablissement')` — le nom
  français uniquement. Toute entité RH neuve nommée en anglais échapperait silencieusement aux deux.
  Sans effet sur ce lot (aucune entité neuve) ; **à trancher avant le lot 2**.
- **Peu de backend neuf.** G-1, G-3, G-4, G-6 et G-7 n'en demandent aucun : les opérations existent,
  seuls les appels clients et les écrans manquent. G-5 ajoute une garde, G-8 des fixtures — et
  **G-2b ajoute une entité, une migration et un écran de réglage**. C'est l'amendement du CP-1 qui
  alourdit ce lot ; il est assumé, mais il ne faut pas le lire comme un simple branchement d'écrans.
- **G-2b et le piège de nommage.** L'entité de paramétrage est un fichier **neuf**, donc D5 impose
  des identifiants anglais. Deux conséquences mesurées : (a) elle ne doit **pas** exposer son
  établissement en écriture (D41), ce qui rend inopérant l'angle mort de
  `EstablishmentScopeWriteGuard` — il n'aura rien à rattraper ; (b) son cloisonnement en lecture se
  déclare dans `PerimetrePersonnelExtension`, dont le tableau `CHEMINS_DIRECTS` porte le chemin
  **par entité** — une entité anglaise peut donc y être déclarée avec `'{root}.establishment'` sans
  toucher au mécanisme partagé par les autres. Le garde-fou n°28 vérifie cette cohérence.

---

## Points UNVERIFIED — levés au CP-1 (2026-09-08)

- [x] **Le seuil « expire bientôt ».** → **Réglable par établissement** (voir G-2b). Ni 14 ni 30 en
  dur ; la commande lit le même paramètre que l'écran.
- [x] **Ce que la fiche employé montre des absences et des badges.** → **En lecture seule sur la
  fiche.** Elle répond à « tout ce que je sais de cette personne » sans faire cliquer ailleurs ; les
  gestes (émettre, révoquer, valider) restent dans leurs onglets dédiés. On lit ici, on agit là-bas.

## ⚠ Le correctif qui ne corrigeait rien (2026-09-08, mesuré à la construction)

G-5 devait fermer une écriture non gardée : `RattachementEmploye` expose `employe` et `etablissement`
en écriture sans processeur, et le décorateur global ne regarde que le second. Un
`StaffAssignmentScopeProcessor` a été écrit, branché, testé.

**Il ne changeait rien.** Suite lancée avec et sans lui : **5 tests, 18 assertions, résultats
identiques**. Il a été **supprimé**, pas livré.

La raison est structurelle. Le refus arrive **avant** tout processeur, à la dénormalisation : API
Platform résout `/api/employes/<uuid>` par le provider d'item, donc **à travers l'extension de
cloisonnement**. Un employé hors périmètre n'est pas résolvable — 400 « Item not found », sans jamais
atteindre la couche d'écriture. Et le recoupement proposé était **strictement plus large** que ce
filtre :

| | condition |
|---|---|
| extension de lecture | aucun rattachement **ou** rattachement sur l'établissement **actif** |
| `PerimetreEmployeVerificateur` | aucun rattachement **ou** rattachement sur un établissement où l'agent a **une affectation quelconque** |

La seconde contient la première. Tout employé résolvable passait le contrôle : il n'avait aucun cas où
mordre.

> **Un contrôle placé après un filtre plus strict que lui ne refuse jamais rien — et tous ses tests de
> refus passent, ce qui le fait paraître utile.**

Livrer ce fichier aurait été pire que de ne rien livrer : son en-tête annonçait une protection, et un
audit ultérieur l'aurait comptée comme faite.

### ⚠⚠ Et la limite qui reste, mesurée, est plus large que l'audit ne le disait

L'audit bornait le risque à « il faut connaître l'UUID d'un orphelin étranger ».
**C'est faux : l'orphelin se parcourt.** `testPorteeReelleDeLaVisibiliteDesOrphelins` le prouve — un
`Employe` sans aucun rattachement **apparaît dans la collection `/api/employes`** de tout détenteur de
`personnel.gerer_employe`, quel que soit son établissement actif
(`PerimetrePersonnelExtension:210`, `NOT EXISTS (rattachement)`, sans aucune condition de groupe).

Et ce n'est pas un état rare : **un employé sans rattachement n'appartient à aucun établissement, donc
à aucun client.** Or l'écran de création n'envoie jamais de rattachement, et aucun écran ne sait en
poser — donc **tout employé créé par le produit aujourd'hui est dans cet état, définitivement**.

→ **L'étape 4 (le rattachement depuis la fiche employé) n'est donc pas un confort : c'est ce qui sort
les employés d'un état où ils sont visibles hors de leur client.** Le fermer complètement demande un
ancrage sur `Employe` (créateur, ou groupe propriétaire) : une colonne, donc une migration, donc un
lot à part — à arbitrer avec Maxime.

## ⚠ Correction d'un fait faux que cette spec a porté (2026-09-08, après CP-1)

Cette spec a affirmé, en G-7 et dans son critère d'acceptation, que
**« `EmissionBadgeStaffHandler` ne pose aucune zone (0 occurrence de `addAuthorisedSpace`) »**, et
elle en tirait une exigence d'écran : distinguer « aucune zone » de « pas encore mesuré ».

**C'était un zéro faux, et il a été relayé sans être remesuré.** La méthode réelle est
`addEspaceAutorise` — `EmissionBadgeStaffHandler.php:110` l'appelle, et `PorteeAccesEmploye.php:94`
la déclare. `addAuthorisedSpace` appartient à une **autre classe**,
`App\Acces\Entity\DroitAcces.php:365`. La recherche portait sur la chaîne rendue par une source
voisine, pas sur celle du sujet ; elle ne pouvait que rendre zéro.

Le serveur pose donc bien les zones, et **refuse** d'émettre sans zone
(`EmissionBadgeStaffHandler.php:75-77`). L'exigence d'écran qui en découlait est **supprimée** : elle
aurait produit un état vide inatteignable par construction.

**D88 reste ouvert, mais pour une autre raison** : les zones viennent du **corps de la requête**, pas
de la fonction de l'employé. La contrepartie énoncée par Maxime (« il faut que les rôles existent déjà
et soient justes ») n'est pas remplie. Hors périmètre de ce lot.

*Conservé ici plutôt que réécrit en silence : la phrase fausse a voyagé du rapport de recherche à la
spec puis à un compte rendu oral, et rien n'aurait relié la correction à ses trois copies.*

## Suites annoncées par Maxime au CP-1 — hors de ce lot, à ne pas perdre

Consignées ici parce qu'elles changent la forme des lots suivants, et parce qu'un fait mesuré
aujourd'hui vieillit mal s'il n'est écrit nulle part.

- **Les employés sont des ressources d'activité, et leur calendrier se lie à l'agenda.**
  ⚠ Une liaison existe **déjà** et elle est délibérément étroite :
  `app/src/Personnel/Calendar/WorkShiftsCalendarSource.php` publie dans l'agenda les créneaux de
  travail **de celui qui regarde uniquement** (portée `mine`). Son docblock donne le motif : publier
  le planning de l'équipe ferait de l'agenda « une seconde porte vers la même donnée », et exposerait
  les horaires de toute l'équipe à qui a le droit d'ouvrir un agenda. Élargir cette portée est donc un
  **renversement de décision écrite**, pas un ajout — à arbitrer explicitement.
  Le concept de ressource planifiable existe par ailleurs dans un autre module :
  `app/src/Reservation/Entity/Ressource.php`, avec `DisponibiliteRessource`,
  `IndisponibiliteRessource`, `AssignResourceProcessor`, `ReferentielTypeRessource`.
  **Mesuré le 2026-09-08 : aucun lien n'existe entre une `Ressource` et un `Employe`.** `Ressource`
  porte `etablissement`, `espace`, `codeType` (chaîne libre, `ReferentielTypeRessource` ne fait que
  suggérer), `libelle`, `capacitePropre`, `ressourceMere`, `partageable`, `ouvreAcces`, et une
  `competenceRequise` **en texte libre (string nullable)** — sans aucun rapport avec
  `TypeQualification`. Le module `Reservation` ne cite jamais `App\Personnel\Entity\Employe` (témoin
  positif : la même recherche trouve 7 fichiers dans `Finance/ExpenseReport`, elle n'est pas muette).
  → Le lot « employé-ressource » devra **créer** ce lien, et décider si `competenceRequise` se
  raccorde aux qualifications du personnel ou reste un champ libre parallèle. Deux référentiels de
  compétence côte à côte, l'un typé et l'autre libre, se contrediraient en silence.
- **Les indépendants.** Un guide peut être à la fois une ressource planifiée par l'établissement
  **et** un prestataire qui facture — sa facture devant apparaître des deux côtés en même temps
  (celui qui la reçoit, celui qui l'émet), avec une automatisation possible. Touche au moins
  `Personnel`, `Reservation` (la ressource), `Facturation` et les factures fournisseur
  (`app/src/Finance/SupplierInvoice/`). L'enum `TypeContrat` porte déjà un cas `prestataire`, ce qui
  est un point d'accroche mais **pas** un modèle de facturation. Lot à spécifier séparément.

---

## Critères d'acceptation

Vérifiables, et vérifiés **en exécutant** contre l'API réelle — pas seulement par des tests.

- **G-1** — Depuis un écran, créer une qualification pour un employé, puis la retrouver dans la
  liste ; la modifier (date de validité), et voir la modification. `mesurer-ecart.mjs` compte 4
  opérations `Qualification` de plus en atteignables.
- **G-2** — Une qualification expirant dans 10 jours apparaît distinctement d'une valide et d'une
  expirée ; le tri par défaut la place avant une qui expire dans 6 mois.
- **G-2b** — Régler le préavis d'un établissement à 30 jours : une qualification expirant dans
  20 jours devient « expire bientôt » **sur cet établissement** et reste tranquille sur un autre
  laissé à 14. Et — la moitié qui se prouve séparément — `personnel:qualifications:verifier` lancée
  sans `--jours` signale exactement les mêmes qualifications que l'écran sur le même établissement.
  Les deux moitiés se vérifient chacune par son chemin : l'écran contre l'API, la commande en la
  lançant.
- **G-3** — Corriger le poste d'un employé depuis sa fiche, recharger, voir la correction. Une
  tentative d'écrire `statut` par ce chemin ne change rien (le champ n'est pas dans `employe:write`)
  — et l'écran ne le propose pas.
- **G-4** — Créer un employé sans site : le bandeau apparaît, et l'émission d'un badge est refusée par
  le serveur avec sa raison affichée. Poser un rattachement depuis la fiche : le bandeau disparaît, et
  l'émission du badge aboutit. **C'est le test qui prouve que le cul-de-sac est refermé.**
- **G-5** — Un `POST /api/rattachement_employes` désignant un employé (ou un établissement) hors du
  périmètre de l'appelant est **refusé en 400 à la dénormalisation** et n'écrit rien en base (vérifié
  **en base**, pas seulement au code de retour). Et — les cas qui démasquent un contrôle trop large —
  le **premier rattachement d'un orphelin** et le **second rattachement d'un employé déjà rattaché**
  continuent d'aboutir en 201 : ce sont deux branches distinctes du filtre, et un durcissement casse
  la première sans qu'aucun test de refus ne bronche.
  ⚠ Le code est **400, pas 404** : le refus précède le code du projet, donc la convention 404 du dépôt
  ne s'y applique pas. Il reste indiscernable (« Item not found » ne distingue pas « n'existe pas » de
  « hors périmètre »), donc il ne confirme rien à qui sonde.
- **G-6** — Modifier l'heure de fin d'un créneau qui porte déjà deux affectations : les deux
  affectations sont toujours là après la modification.
- **G-7** — Le détail d'un badge montre les espaces qu'il ouvre, son mode horaire et sa marge.
  ⚠ **Ne PAS construire d'affichage « aucune zone »** : le serveur **refuse** d'émettre sans zone
  (`EmissionBadgeStaffHandler.php:75-77`, RG-PERSO-06/07), donc une portée porte toujours au moins un
  espace. Un état vide qu'aucun chemin ne produit est du code mort qui rassure.
- **G-9** — Depuis la fiche d'un employé rattaché, émettre un badge en choisissant établissement, mode
  horaire et au moins un espace : **201**, le badge apparaît dans l'onglet Badges, et sa portée nomme
  l'espace choisi. Témoin négatif : émettre une seconde fois pour le même employé sur le même
  établissement rend **409**, affiché avec la raison du serveur — un écran qui rendrait 201 deux fois
  signalerait qu'il court-circuite le handler.
- **G-8** — Après `doctrine:fixtures:load`, la liste des employés n'est pas vide, et contient au moins
  une qualification expirée et une expirant sous 14 jours.
- **Transverse** — `./bin/garde-fous.sh` vert ; la suite PHP du domaine touché verte ; le cliquet
  d'écart (`garde-fou-ecart.mjs`) constate une baisse du nombre d'opérations inatteignables et se
  regèle sur la nouvelle valeur.
