# TASKS — ce qui reste, et qui le tient

> **Refait le 31/08 par Jarvis (intégrateur).** Le tableau précédent datait du 22–25/08 et ne disait
> plus la vérité : 55 lignes sur 85 étaient en `CLAIM` sans nom. Un `CLAIM` sans instance n'est pas
> une réservation — c'est une intention que personne n'a reprise.
>
> ⚠ **Ce fichier est le SEUL canal entre les sessions qui ne partagent pas la même machine.** Écris
> ici ce qu'une session neuve doit savoir sans pouvoir te le demander.

---

## 1. Avant de toucher quoi que ce soit

**Lis dans cet ordre, ça prend dix minutes et évite une journée refaite :**

1. `COORDINATION/DECISIONS.md` — ce que Maxime a tranché, avec la raison ET la contrepartie.
   108 décisions. Les huit dernières (D101→D108) portent la feuille de route.
2. `COORDINATION/BLOQUEURS-EXTERNES.md` — sept blocages qui ne dépendent pas de nous.
3. `git log origin/main` — ce qui est **réellement** parti.

⚠ **`main` local n'est pas `origin/main`.** « J'ai poussé » désigne un geste ; « `origin/main`
contient X » désigne un état. Et « poussé » n'est pas « servi » : la préproduction n'a que ce que
`./infra/deploy-preprod.sh` y a mis. Vérifie avec `curl .../version.json`.

**Puis, avant ta première ligne de code :**

```
git fetch origin && git merge --no-edit origin/main
./bin/garde-fous.sh
```

Le second doit être vert AVANT que tu commences, sinon tu hériteras d'un rouge qui n'est pas le tien.

### ⚠ La chaîne NF525 de la préprod rend « intacte: false », et c'est NORMAL

`POST /api/nf525/verifier-chaine` répond, sur la préprod :

    "intacte": false, "anomalies": [{ "sequence": 1, "probleme": "signature invalide" }, …]

**Ce n'est pas une panne, et il n'y a rien à réparer.** Les clés de scellement ont été régénérées le
31/08 (décision de Maxime : une clé par installation). Les opérations scellées AVANT portent une
signature calculée avec l'ancienne clé.

⚠ **Lis le type d'anomalie, pas le booléen.** Deux mots différents, deux gravités opposées :

| ce que dit l'anomalie | ce que ça veut dire |
|---|---|
| `signature invalide` | la clé a changé depuis le scellement — attendu ici |
| `empreinte incohérente` | **la donnée a été altérée** — ça, c'est grave |

L'empreinte ne dépend d'aucune clé (`hash(payload + empreinte précédente)`) ; seule la signature en
dépend (`hash_hmac(empreinte, clé)`). La chaîne est donc toujours vérifiable, et le contrôle le dit
correctement : zéro `empreinte incohérente` sur les 14 opérations.

**En production, ce ne sera jamais normal** — les clés y seront générées au premier déploiement et
ne changeront plus jamais.

---

### ⚠ Un changement de schéma doit tenir avec les DEUX versions du code

`deploy-preprod.sh` applique les migrations **avant** de redémarrer FPM. Entre les deux, le schéma
est neuf et le code est ancien. La fenêtre dure quelques secondes en temps normal — **elle a duré
deux heures le 31/08**, parce que j'ai migré sans déployer dans la foulée.

Ce qui s'est passé :

    base          `adresse JSON NOT NULL`, aucun défaut
    PHP servi     une classe qui ne connaît pas ce champ (21 commits de retard)

Un `POST /api/profil_exploitants` aurait omis une colonne obligatoire sans défaut : **erreur SQL,
500**, sur un chemin exposé. Relevé par `b8`.

⚠ **Inverser l'ordre ne résout rien** : du code neuf sur un schéma ancien casse tout autant. La
seule forme qui tient est un changement compatible avec les **deux** versions.

**En pratique, pour toute migration :**

- une colonne neuve `NOT NULL` porte un **`DEFAULT`** — l'ancien code ne l'écrit pas, la base la
  remplit ; le nouveau l'écrit, et le défaut ne sert plus ;
- le `DEFAULT` se déclare **aussi au mapping** (`options: ['default' => …]`), sinon le garde-fou D32
  refuse un défaut que la base porte et que le mapping ignore ;
- retirer une colonne se fait en **deux temps** : le code cesse de l'écrire, on déploie, puis on la
  supprime.

**Et après avoir migré, déploie.** Une migration appliquée sans déploiement laisse la fenêtre
ouverte aussi longtemps que personne ne s'en aperçoit.

---

### ⚠ Une suite verte ne dit RIEN de ce que le web sert

`opcache.validate_timestamps=0` : **FPM ne relit jamais les fichiers.** Il sert le code tel qu'il
était à son dernier démarrage. Et `opcache.enable_cli=0` : **phpunit et `bin/console` voient toujours
le code frais.**

Les deux ensemble : ta suite peut être verte sur un code que le web ne sert pas.

Mesuré le 31/08 — un correctif de cloisonnement écrit à 13:38 sur un conteneur démarré à 13:31 est
resté hors d'opcache treize minutes, pendant que tout était vert. Trouvé par `b8`.

⚠ **Lire le fichier dans le conteneur ne prouve rien** : le volume est monté, donc le fichier est
frais, et opcache sert quand même une image figée. La seule mesure qui vaut vient du processus.

    curl -s -H 'Accept: application/json' https://smartaccess.hector-conseil.com/api/plateforme/version-chargee

Ce point d'entrée rend le commit **que PHP a chargé**. Le marqueur est un fichier PHP : si opcache
sert du code figé, il sert la constante figée avec elle — l'instrument hérite du défaut qu'il mesure.
`version.json`, lui, voyage avec le `rsync` du frontal : il ne décrit que la moitié frontale.

**Trois versions à comparer, pas deux** : l'arbre, le frontal servi, le PHP chargé.
`./infra/deploy-preprod.sh` les vérifie désormais toutes les trois.

---

### ⚠ Déployer tue toute suite en cours — la tienne comme celle des autres

`./infra/deploy-preprod.sh` lance `composer install --no-dev`, qui **retire phpunit** du `vendor/`
**de l'arbre d'où part le déploiement**. Une suite qui tourne sur cet arbre meurt alors en plein
milieu, et son message accuse l'opérateur de ne pas avoir réinstallé — alors qu'il l'avait fait.

⚠ **Les worktrees ne sont PAS concernés, et ma première version disait le contraire.** `git worktree`
partage le `.git`, pas le `vendor/` : chaque worktree a le sien (deux inodes distincts, mesuré par
`8e` puis vérifié). Il y a **dix worktrees** ici — avertir pour les dix apprenait à tout le monde à
sauter la ligne, ce que j'ai fait moi-même une heure après l'avoir écrite. Le déploiement lit
désormais le **montage** des conteneurs de test, pas un fichier, et ne nomme que les suites du même
arbre.

Constaté le 31/08 : déploiement à 12:04:32, déploiement d'une autre session à 12:05:50, suite
complète perdue **sans qu'un seul test soit exécuté**. La notification de tâche annonçait « exit
code 0 ».

**Ce n'est la faute de personne : c'est structurel.** Toute session qui déploie casse toute session
qui teste, et la flotte s'agrandit à d'autres comptes.

On ne supprime pas la collision, et c'est délibéré : le garde-fou n°20 existe parce que
`symfony/http-client` était déclaré en `require-dev` alors que huit classes de production
l'utilisaient — un défaut *invisible partout où on le cherche, et visible seulement sur la machine
déployée*. La préprod sans dépendances de dev est le seul endroit où cette classe se voit.

Donc la collision est rendue **bruyante**, pas supprimée :

- `test-stack.sh run` pose `/tmp/suite-en-cours-<jeton>`, retiré par `trap` — il dit qu'une suite
  tourne, y compris entre deux conteneurs.
- `deploy-preprod.sh` lit le montage `/repo` des conteneurs `*-run` et ne nomme que ceux montés sur
  **son propre** arbre. Un montage ne peut pas devenir périmé ; un marqueur survit à un processus tué.
- **Il avertit, il ne bloque pas.** Bloquer transformerait une gêne en panne : la suite complète
  dure des heures et personne ne pourrait livrer pendant ce temps.

**Ce qu'on te demande :** lis l'avertissement. S'il liste un jeton qui n'est pas le tien, préviens
avant de déployer. Et si ta suite meurt sur « phpunit est absent », ce n'est pas toi :

```
./infra/reinstaller-dev.sh
```

---

## 2. Qui tient quoi au 31/08

| session | voie | ne pas toucher |
|---|---|---|
| **Jarvis** (intégrateur) | `main`, `bin/**`, `hooks/**`, `infra/**`, `COORDINATION/**`, NF525 | les garde-fous, les crochets, la pile |
| **allaccess-8e** | référentiel de TVA légale, `App\Compta\**` | `app/src/Compta/**` tant que son lot n'est pas poussé |
| **allaccess-c2** | module Réservation, écrans et contrôles frontaux | `app/src/Reservation/**`, `frontend/scripts/**` |
| **allaccess-89** | continuation directe de `34` — même arbre, même branche, seul le nom d'adresse a changé ; écrans, facture rendue, destinataires | `frontend/src/pages/**` qu'il ouvre |
| **allaccess-b8** | mesure et diagnostic, relais vers Maxime | — (il ne pose pas de code) |

**Pour prendre un lot :** ajoute ton nom dans la colonne « tenu par » du tableau §3, commite ce seul
changement, et pousse-le **avant** de commencer. Un lot pris sans être poussé n'est pas pris.

### Ce qui peut être ajouté ici, et par qui

⚠ **Tout constat MESURÉ se pose par celui qui l'a mesuré**, sans passer par personne — avec ses
chiffres **et la commande qui les produit**, pour qu'un autre puisse les refaire.

    un lot manquant que tu as mesuré        pose-le, avec la mesure
    une ligne de ce fichier qui est fausse  corrige-la, en disant ce qui l'était
    la structure du fichier                 voie de Jarvis

**Pourquoi cette règle existe :** `allaccess-89` a mesuré quatre modules construits sans écran et
n'a pas posé la ligne, parce que §3 ne l'y autorisait pas. Il a bien fait de respecter la règle —
c'est la règle qui était mauvaise.

Et dans le même échange, il a relevé qu'une de mes lignes était fausse : « 6 fichiers sur 118 »
comptait des **fichiers** et concluait à une **couverture**. Un fichier qui fait autorité et qu'une
seule session peut corriger n'est pas auto-suffisant : c'est un goulot qui se trompe aussi.

---

## 3. Prêt à prendre

Ordonné par ce que ça débloque, pas par difficulté.

| # | lot | pourquoi maintenant | tenu par |
|---|---|---|---|
| **T1** | **L'API publique pour les tiers** — clés délivrables, webhooks sortants, versions | ⚠ **Commande trois des quatre autres axes** (D102). Sans elle, l'appli mobile, les agrégateurs et les machines connectées produisent trois couplages privés au lieu d'une surface | *(libre)* |
| **T2** | **Reprise initiale d'un client** — `ImportBatch`, deux temps, `externalRef` | Bloque une signature : sans elle un client ressaisit son fichier d'abonnés et les crédits de ses cartes. Spec écrite : `COORDINATION/specs/import/` | *(libre)* |
| **T22** | **Garde-fou n°34 — la validation s'exécute AVANT les processeurs** | **Cause du témoin raté trouvée** : le détecteur indexait les setters des processeurs **par nom court**. Le dépôt compte **neuf** classes `EstablishmentStampProcessor`, une par module — les neuf s'écrasaient, seule la dernière parcourue survivait, et celle de `Boutique` est la seule à poser le slug. Mesuré : 312 processeurs, 303 noms courts, **2 en collision**. Résolu par nom pleinement qualifié via les `use` ; preuve faite dans les deux sens (le nom court accuse un homonyme à tort, le nom complet l'épargne).<br>⚠ **ET LE TÉMOIN POSITIF ÉTAIT PÉRIMÉ.** `Vitrine::$slug` était un vrai cas ; il est **corrigé depuis**, par le bon remède — `VitrineResolver::fabriquerSlug()` refuse lui-même les noms d'hôte réservés, là où la valeur finale existe (D106). Le signaler serait accuser du code juste : il sert désormais de témoin **négatif**.<br>⚠ **ET LES 31 NE DEVAIENT PAS ÊTRE GELÉS**, la mise en garde était juste. Les 32 écarts de l'énoncé large se classent : **28 écrivables par le client** (`:write`, `:create`, `:update`, `:patch`) — la contrainte y fait un vrai travail ; **4 contraintes inertes** (non écrivables, défaut qui satisfait la contrainte, valeur du processeur jamais contrôlée) ; **0 refus garanti** — le symptôme bruyant de `8e` n'existe plus. Un cliquet à 32 aurait gelé 28 non-défauts.<br>⚠ **Ma propre première mesure était fausse aussi** : je ne cherchais que `write` dans les groupes, ce qui classait `Produit::type` — exposé en `produit:create` — comme non écrivable, et inventait un défaut certain qui n'en était pas un.<br>**Ce que le n°34 ne fera pas** : décider si un processeur FABRIQUE une valeur ou RECOPIE celle du client demande de lire son intention — ça ne se décide pas sur du texte. Les 28 écrivables ne sont donc pas signalés. **Livré** : `bin/garde-fou-validation-avant-processeur.php`, cliquet à 4, trois témoins intégrés (un positif, deux négatifs) qui refusent de parler s'ils ne se comportent pas. ⚠ **En attente de la fusion de `220a571`** — le filet de `pre-commit` rend tout garde-fou neuf incommittable | `c2` — 01/09 |
| **T24** | **Garde-fou n°35 — une entité rattachable absente de la liste blanche de son module** | ⚠ **Trois oublis de la MÊME liste, dont le dernier fermé aujourd'hui.** `CardRejection` et `DailyClosure` ajoutées le 28/08 ; `OperationScellee` le 31/08 — et celle-là exposait `payloadCanonique`, le contenu canonique de chaque transaction. Le commentaire de l'extension disait déjà la leçon : *« une liste blanche ne protège que ce qu'on a pensé à y écrire, et son oubli ne se voit pas »*. Écrire la leçon n'a pas suffi. **Motif** : comparer les entités d'un module portant une relation de rattachement (`pointDeVente`, `etablissement`, `profilExploitant`, `groupe`) à celles que son extension énumère. **Trois témoins historiques pour le calibrer** : les trois doivent ressortir sur le dépôt d'avant leur correctif, aucun sur le dépôt actuel. ⚠ **Il criera sur les référentiels globaux légitimes et sur ce qui est cloisonné AUTREMENT** — par un fournisseur, par `RattachementNiveauInterface`, par une table de relations. Il lui faut une dette gelée dès le départ ET une exclusion explicite **avec sa raison écrite**, sinon il crie tous les jours et on cesse de le lire. Proposé par `b8`, sur une mesure de `8e` | **fait** — claude-B, 01/09 (a5900293). Discriminant = le rattachement : le n°5 gelait ces entités en vrac. Calibré 0 sur main, 3 témoins ressortent pré-correctif. Câblé aux 3 endroits + banc. ⚠ Plafond 0 : les modules en vol sans extension (ReferentielOffre, Intervenant, Formulaire, Billetterie, Parametrage) le déclencheront à leur push — c est le contrôle qui mord, pas un faux positif. |
| **T23** | **Le frontal public bascule sur la résolution par hôte** | Le serveur sait le faire depuis T3 (`GET /boutique/vitrine-courante`, D104) et `PublicApp.jsx` l'appelle déjà en repli. Reste : servir le front sous `<slug>.fluvia-app.com` (nginx + joker TLS), et faire du chemin `/b/<slug>` une redirection plutôt qu'une forme parallèle | *(libre)* |
| **T6** | **Fixtures rejouables en préproduction** | `doctrine:fixtures:load` est absente (`--no-dev`). 38 classes décrivent la démo et ne peuvent pas être rejouées : la démonstration dérive | *(libre)* |
| **T7** | **Format de facture électronique** — EN 16931 | Aucun format n'existe. Chorus Pro et l'e-reporting REFUSENT désormais au lieu de mentir (D94), mais ne transmettent toujours rien. ⚠ **Le modèle sémantique EN 16931 est COMMUN** à la France, l'Espagne, l'Italie et l'Allemagne : le construire une fois sert les quatre. Les plateformes nationales (Chorus/PDP, VeriFactu, SdI, XRechnung) ne sont que des transports par-dessus. Mesuré le 31/08 : 0 fichier pour chacune, et « Factur-X » n'apparaît que dans les commentaires de deux bouchons | **Jarvis — 02/09 : les 28 termes obligatoires sont MODELISES.** `facturation:einvoicing:etat` mesurait 3 trous structurels ; il n'en reste aucun. BT-5 devise (`EUR` etait implicite partout), BT-130 unite de mesure (`quantite` etait un entier nu, code UN/ECE Rec 20), BT-151 categorie de TVA (portee par le TAUX, pas par la ligne). ⚠ **La categorie NE SE DEDUIT PAS du taux** : 6 taux a 0 % en base sont « hors champ » (`O`), pas `Z` ni `E` — la colonne est nullable et la migration n'a pose `S` que sur les 25 taux positifs, ou il n'y a pas d'ambiguite. Les 6 restent sans categorie et leurs factures non emettables : c'est un arbitrage fiscal a rendre, pas un defaut de code (D66-ter). ⚠ **Et 9 termes sur 28 n'etaient JAMAIS verifies** : le rapport aurait annonce « emettable » sans les regarder des que les adresses acheteur auraient ete saisies. `testChaqueTermeObligatoireEstVerifieOuExplicitementExempte` ferme le trou par construction. **Reste** : (1) la SAISIE — identite vendeur et adresse acheteur, aucun raccordement ne la remplacera ; (2) le SERIALISEUR CII/Factur-X, qui n'existe pas ; (3) la conformite, qui se prouve contre le schematron officiel et celui de chaque CIUS national — porter les termes rend *emettable*, pas *conforme*. |
| **T8** | **Notion de pays** — champ, devise configurable, TVA par pays | ⚠ **LA DESCRIPTION PRECEDENTE ETAIT PERIMEE, MESURE DU 02/09.** Elle disait « aucun champ pays, `EUR` en dur, e-reporting indexe sur le SIREN ». En realite : `LegalVatRate` porte DEJA `country` avec ses dates de validite et sa source ; `VatRateCatalogProvider` sert DEJA les taux par pays ; et trois entites portent une devise (`Facture`, `PorteMonnaieVirtuel`, `Mesure`). **Une fiche perimee fait rebatir ce qui existe** — c'est la deuxieme fois aujourd'hui, apres les deux taches annoncees libres alors qu'elles etaient faites. | **Jarvis — 02/09, ETAT AU 02/09 AU SOIR : les quatre restes sont traites, trois par du code et un par un refus.** (1) **Les taux legaux ne sont plus seulement francais** : 28 pays en base, taux standard, depuis un export TEDB (Commission europeenne, DG TAXUD) recupere par `infra/recuperer-taux-tva-ue.sh` et lu par `vat:import-tedb`. ⚠ **CORRIGE LE 02/09 AU SOIR, ET LA CORRECTION PORTE SUR CE QUE J AVAIS ECRIT ICI.** J affirmais que « TEDB ne dit pas lequel est le second reduit, le super reduit ou le parking », et cette phrase a servi a ecarter 1 114 taux. **C est faux** : chaque taux porte un champ `rates[].key` (`Reduced rate`, `Super-reduced rate`, `Parking rate`, `Exempted`). Je lisais le `type` du BLOC, grossier, et je n ai jamais ouvert la cle de chaque taux — j ai conclu « la source ne le dit pas » d une lecture partielle de la source. Apres correction : **55 taux en base** (31 standard, 10 reduits, 8 super-reduits, 5 parking), classes PAR LA SOURCE. Ce qui reste ecarte, et pourquoi : (a) **22 paires (pays, cle) rendent plusieurs valeurs**, et la cause n est pas un manque de classement mais des **territoires aplatis** — la France sort six « Reduced rate » : 13 et 0,9 (Corse), 8,5 et 1,05 (DOM), 10 et 5,5 (metropole) ; le Portugal sort 22 et 16, qui sont Madere et les Acores ; (b) l **Espagne** (7 % et 21 % a la meme date : Canaries et peninsule) ; (c) les cles `Exempted` / `Not applicable` / `Out of scope` — `Exempted` melange l exoneration AVEC et SANS droit a deduction, distinction invisible sur la facture et decisive pour ce que l exploitant recupere. (2) **La devise de la facture est desormais POSEE** : `Facture::setEtablissement()` en herite. Il n y a toujours **aucune conversion**, et c est le seul vrai reste de T8. (3) **`Pain008Generator` fige toujours `Ccy="EUR"`, et c est JUSTE** : `GenerationRemiseHandler` refuse un etablissement non-EUR AVANT de l appeler, et c est son unique appelant (verifie). Etiqueter des montants etrangers en EUR prelverait le mauvais montant. (4) **L e-reporting n est plus indexe sur le SIREN pour tout le monde** — et le defaut trouve en ouvrant ce point etait plus large : `ProfilExploitant::$siren` portait `NotBlank` + 9 chiffres SANS CONDITION, donc **un exploitant belge ne pouvait pas etre enregistre du tout**. Aucun test ne pouvait rougir : les fixtures sont francaises et `Etablissement::pays` vaut `FR` par defaut. La validation suit maintenant le pays ; hors de France un SIREN est REFUSE (il partirait en BT-30 `schemeID 0002` comme un identifiant SIRENE — faux et opposable) ; `InvoiceReadiness` ne reclame BT-30 qu aux vendeurs francais et BT-31 a tous ; l e-reporting refuse un exploitant hors de France. **RESTE OUVERT** : (a) aucune conversion de devise ; (b) quel identifiant legal demander a un exploitant etranger (BE : numero d entreprise, DE : Handelsregister) et sous quel `schemeID` ISO 6523 le publier — mesure du 02/09 : seule la France prescrit un code de repertoire que nous puissions soutenir, d ou `REGISTRES_CONNUS = [FR => 0002]` dans `CiiSerializer`. C est un arbitrage a rendre quand un client etranger arrivera, pas avant. |
| **T9** | **Accessibilité — la navigation au clavier** | ⚠ **Ma première mesure était fausse** : « 6 fichiers sur 118 portent un `alt=` » comptait des FICHIERS et concluait à une couverture. Recompté : **7 balises `<img>`, aucune sans alternative**, et `lang="fr"` est bien déclaré dans `index.html`. Ce qui est mince, c'est le clavier — **2 `tabIndex` et 5 `onKeyDown` sur 115 fichiers**, contre 308 `htmlFor` et 72 `aria-label`. Le lot est donc : parcours au clavier, gestion du focus, contrastes.<br>⚠ **Un troisième manque que ni l'un ni l'autre n'avait compté, et qui est fait** : **neuf boutons `↻` sans nom accessible** — un symbole n'est pas prononçable, un lecteur d'écran annonçait « bouton » et rien d'autre, sur des écrans qui en comptent trente. Nommés, et `frontend/scripts/verifier-boutons-nommes.mjs` les compte désormais tous (666 lus, invariant à zéro, pas de cliquet). Fait aussi : `lang` suit la langue de la vitrine — `index.html` portait `fr` en dur, et une vitrine en anglais était lue avec la prononciation française. ⚠ **Et « 2 `tabIndex` sur 115 fichiers » ne se lit pas non plus comme un déficit** : `<button>`, `<a href>` et `<input>` reçoivent le focus sans qu'on écrive rien, et ajouter `tabIndex` est le plus souvent le signe qu'on a rendu cliquable ce qui ne l'était pas. Le vrai défaut se comptait autrement — **sept éléments inertes rendus cliquables, dont cinq sans aucun chemin au clavier**, parmi lesquels le seul accès à la fiche d'un client. Corrigés par un vrai bouton dans la cellule identifiante (et **non** par `role="button"` sur un `<tr>`, qui casse la structure annoncée par les lecteurs d'écran). Garde-fou : `verifier-clic-clavier.mjs`.<br>⚠ **Les contrastes non plus n'etaient pas la ou on croyait** : sept paires sous le seuil WCAG, **toutes dans le theme clair sauf une**, et les pires etaient les trois pastilles d'etat (« attention » a 2,84 pour 4,5 requis) — celles qu'on lit d'un coup d'oeil sans les lire. Corrigees sur decision de Maxime en **deplacant leur clarte** et non en choisissant des couleurs : teinte et saturation gardees, pas de 1 %, arret au premier passage. Le turquoise bouge de quatre unites. Garde-fou : `verifier-contrastes.mjs`, plafond a **zero**, invariant.<br>Enfin le focus : le back-office n'avait **ni lien d'evitement ni deplacement du focus au changement d'ecran** — soixante tabulations pour lire trois ecrans, la colonne de gauche comptant trente entrees. La boutique publique avait les deux depuis toujours. **Fait.**<br><br>⚠ **CE QU'IL FAUT RETENIR DE CE LOT** : les TROIS chiffres qui le decrivaient — `alt`, `lang`, `tabIndex` — etaient vrais et trompeurs, chacun comptant une chose pour une autre. Les trois vrais defauts (boutons anonymes, lignes inatteignables, pastilles illisibles) n'etaient dans aucun des trois. Une tache mal mesuree ne coute pas du temps : elle donne l'impression d'avoir couvert le sujet quand on a corrige ce qu'elle nommait. | **fait** — `c2`, 31/08 |
| **T10** | **Dix-huit tâches planifiées à démarrer**, une par une | **4 sur 22 tournent** (`securite:delegations:expirer`, `autorisation:escalades:expirer`, `boutique:liberer-paniers-expires`, `personnel:recalculer-fenetres-badges`). ⚠ **`personnel:qualifications:verifier` est à NE PAS planifier en l'état** : elle n'écrit rien, elle **imprime**. La planifier la ferait tourner dans les journaux d'un conteneur que personne ne lit — « la tâche tourne » pendant que l'information n'atteint personne. Il lui faut d'abord une **destination**. ⚠ Et son en-tête affirmait « aucun écran ne l'affiche », ce qui est **faux depuis que `Personnel.jsx` rend le badge** : corrigé le 31/08. ⚠ `social:collect-metrics` appelle des API tierces — effet au dehors, pas à planifier sans arbitrage. Chacune des autres demande de vérifier `safeOnFirstRun` et de la voir mordre Chacune demande de vérifier `safeOnFirstRun` et de la voir mordre **et épargner** | *(libre)* |
| **T18** | **La boutique en ligne est en boucle fermée** — remboursement et souscription | ⚠ L'exploitant peut **accepter et refuser** des demandes de remboursement qui **ne peuvent pas naître** : `POST /boutique/demandes-remboursement` n'est appelée par personne, et `grep remboursement frontend/src/public/` ne rend rien. Et l'écran lui affirme « un client qui demande un remboursement apparaît ici ». Second manque du même parcours : `/boutique/abonnements/souscrire` n'est appelée nulle part — **aucun abonnement ne se vend en ligne**, alors que la vente au guichet existe depuis le 29/08. Mesuré par `b8` | **fait** — `c2`, 31/08. Le client demande depuis ses commandes ; l abonnement se souscrit depuis la fiche produit, avec mandat. ⚠ Deux restes : aucun contrôle de délai de rétractation côté serveur, et le client ne peut pas LISTER ses demandes (collection réservée à l exploitant) — donc l écran ne peut pas afficher « demande en cours » après rechargement, et il le dit |

---

## 3 quater. Construit, et sans aucun écran

⚠ **Ces quatre lots existent côté serveur et personne ne peut les atteindre.** Mesuré le 31/08 :
routes présentes au routeur, **zéro appel du frontal**. C'est du travail déjà payé qui ne sert à
rien tant qu'aucun écran ne l'ouvre — la forme la plus coûteuse d'inachèvement, parce qu'elle ne se
voit pas.

⚠ **DEUX SESSIONS ONT ÉCRIT L'ÉCRAN DE FUSION LE MÊME JOUR, SANS SE VOIR** (`c2` et le worktree
`claude-A`). Les branches étaient indépendantes : ni l'une ni l'autre n'a « repris » le travail de
l'autre, elles l'ont fait deux fois. Résolu à la fusion en gardant **la version de `c2`**, sur un
critère mesurable et non sur la paternité : son point d'entrée est meilleur — on clique
« Fusionner » sur la ligne du doublon qu'on regarde, au lieu de choisir les deux fiches à partir de
rien. Le doublon a coûté une demi-journée à quelqu'un.

⚠ **ET CE FICHIER LUI-MÊME EN A PORTÉ LA TRACE PENDANT DES HEURES.** Le 01/09, cette section
existait **en double** et contenait **sept lignes de marqueurs de conflit non résolus**
(`<<<<<<< HEAD`, `=======`, `>>>>>>> origin/main`) — commitées, poussées, et lues par tout le
monde. Un `git merge` sans conflit signalé n'est pas un fichier correct, et c'est la cinquième
occurrence en deux jours après les clés en double de `client.js`, l'import dupliqué, le double
montage et `mappingsComptables`. Le Markdown n'a pas de compilateur : rien ne l'a dit.

⚠ **Le nom de session n'identifie personne durablement.** Le worktree `claude-A` s'est appelé
`allaccess-89` puis `allaccess-37` dans la même journée. Les lignes ci-dessous nomment le
**worktree**, qui ne change pas.

| # | lot | routes servies, appels du frontal | état |
|---|---|---|---|
| **T25** | **Fusion de clients** — fusionner, prévisualiser, défusionner | `/api/crm/fusions` ×3 · **0 appel**. Tout déploiement réel accumule des doublons, et le serveur sait déjà prévisualiser puis défusionner — c est un mécanisme complet sans porte | **fait** — `c2`, 31/08. Prévisualisation champ par champ avant toute écriture, arbitrage, motif au journal. ⚠ **Le journal des fusions est livré avec** : sans lui la défusion serait inatteignable, et on aurait donné le pouvoir d écraser deux fiches sans celui de revenir |
| **T26** | **Trésorerie** — comptes bancaires, import de relevés, rapprochement | `/api/bank_accounts`, `/api/bank_statement_imports`, `/api/bank_statement_lines` · **0 appel** |
| **T27** | **Personnel** — créneaux de travail, sortie d'un salarié, badge perdu | `/api/creneau_travails` · **0 appel** ; `/api/badge_staffs` · 1 appel seulement | **fait** — `claude-A`, 31/08. Onglet « Planning » **avant** le Roster : le créneau dit ce qu'il faut couvrir, le roster dit si ça l'est. ⚠ **Un créneau est un BESOIN, pas une affectation** — rattacher un salarié est un second geste, servi par une autre route. ⚠ **Suspendre un employé suspend ses badges en cascade**, donc la confirmation le dit avant ; la réactivation, elle, ne demande rien — un geste réversible qui demande « êtes-vous sûr ? » apprend à cliquer oui sans lire. « Perte ou vol » est un **troisième** geste sur un badge, pas un synonyme de suspendre ou révoquer |
| **T28** | **Comptabilité** — lettrage groupé **et saisie manuelle** | **fait** — `c2`, 31/08. Onglet « Lettrage » : les lignes non soldées, le solde de la sélection affiché en permanence. ⚠ **Le serveur n exige PAS l équilibre** — mesuré, et c est défendable (un lettrage partiel solde un règlement en plusieurs fois), donc l écran montre l écart sans jamais bloquer. ⚠ Et si la liste des lettrages existants ne se charge pas, l écran s arrête au lieu de proposer de tout lettrer : sans elle on ne distingue plus le soldé du dû. **Seconde moitié faite** — `c2`, 31/08 : onglet « Saisie manuelle ». ⚠ **Ici l'équilibre EST imposé** (422 du serveur) : l'inverse du lettrage, mesuré et non déduit de l'écran voisin. Le taux de TVA est obligatoire sur chaque ligne, les comptes inactifs ne sont pas proposés, et la période est vérifiée avant le clic. ⚠ J'ai failli ajouter un verrou qui existait : chercher `Cloturee` ne trouve rien, c'est `DirectLedgerEntryBuilder::estOuverte()` qui refuse |
| **T29** | **Comptabilité — verser une régie** : `POST /compta/regies/{id}/versements` · **0 appel** | ⚠ **Ce n'est pas un écran manquant, c'est une CLÔTURE BLOQUÉE.** `ClotureHandler` refuse la clôture quand une régie dépasse son plafond d'encaisse sans versement — vu en vrai dans la sortie des tests. `ClotureComptable.jsx` affiche le refus tel quel (« versement requis ») et **aucun écran ne verse** : l'onglet Régie ne fait que lister les bordereaux. Le champ « versement » de `SessionCaisse` est la clôture Z d'une caisse, autre opération — vérifié. Témoin positif pris avant de conclure à l'absence. **Fait** — 31/08 : l'onglet Régie verse au lieu de seulement lister. Il nomme la conséquence (« la clôture est refusée tant que… ») et le minimum qui en sort (`solde - plafond`), anticipe les deux refus du serveur (montant nul → 422, montant > encaisse → 409), et annonce que l'écriture comptable est générée automatiquement — sans quoi on la saisirait une seconde fois dans l'onglet voisin | `c2` |
| **T30** | **Comptabilité — les deux dernières impasses** : `POST /compta/ventes/{id}/marquer-impayee-regie` · **0 appel**, et tout le référentiel **PayFiP invisible** (`/api/bordereau_pay_fi_ps` · 0 appel) | L'onglet Régie **lit** les ventes impayées sans permettre d'en marquer une. Et un bordereau PayFiP bloqué en `en_attente` n'est visible de nulle part : ni liste, ni détail, ni statut. ⚠ **Je ne construirai PAS de bouton « Rejouer »** : `TraiterRetourPayFipHandler::rejouer()` n'incrémente qu'un compteur de tentatives — il ne redemande rien à la DGFiP et ne change aucun statut. Un bouton promettrait un rejeu qui n'a pas lieu, ce qui est pire que pas de bouton. Le vrai rejeu dépend de la signature DGFiP, **bloqueur externe E-7 déjà consigné**. ⚠ Et la recherche de vente se fera par **numéro exact** (seul filtre déclaré) : il n'y a pas de filtre sur `resteAPayer`, et D48 dit pourquoi filtrer en mémoire ferait conclure à tort qu'une vente n'existe pas. **Fait** — 31/08. Le marquage cherche la vente par numéro exact (celui du ticket) et ne déclare jamais qu'une vente n'est PAS marquée : trouvée dans la liste chargée ⇒ déjà marquée, mais l'absence ne prouve rien, la liste est paginée. Les bordereaux PayFiP sont visibles, **sans bouton « Rejouer »** ; l'écran dit à la place de rapprocher à la main depuis l'espace DGFiP. ⚠ Et il distingue « rapprochée en compta » de « payée » : ce module ne modifie jamais la vente, dont le statut payé reste porté par M2 — les confondre ferait clore des dossiers ouverts | `c2` |
| **T34** | **Garde-fou n°39 — un module neuf doit être atteignable par un rôle existant** | ⚠ **Ligne de base mesurée par `b8` : 36 modules, 12 couverts par un rôle réel, 24 sans aucun.** Les plus gros sont le cœur de métier : `finance` (13 droits), `vente` (10), `compta` (10), `facturation` (9), `caisse` (9). **Il ne peut donc pas naître bloquant** — il lui faut une ligne de base gelée, un cliquet comme celui de couverture de périmètre, où aucun 25ᵉ n'est admis. ⚠ **Et le témoin durable n'est pas « les 12 qui passent aujourd'hui »** : celui-là expire dès qu'on corrige un module, et déclare mort un outil sain — `b8` en a fait les frais deux fois le 31/08. Le témoin qui tient est **« le script sait classer les deux catégories »**, pas « telle catégorie contient tel module ». Cause racine en T31 | *(libre)* |
| **T31** | **Cloisonnement de `VenteImpayeeRegie`, `BordereauPayFiP` et `LettrageEcriture`** | Signalé par `b8` sur deux des trois ; la troisième est sortie en vérifiant. Aucune des trois n'était cloisonnée par les quatre mécanismes ; la seule barrière était `compta.lire`, couverte par six comptes. ⚠ **Le docblock déclarait l'omission délibérée et son motif était faux** : `Vente::$etablissement` est une relation directe, donc `venteOrigine` — un `Uuid` nu — mène à l'établissement par une sous-requête ; et `LigneEcriture::$ecriture` mène au `profilExploitant` que quinze entités empruntent déjà. **Fait** — 31/08, par `EXISTS` autonome (une jointure libre est perdue en silence par `FilterEagerLoadingExtension`). Filet vu attraper : cloisonnement retiré une minute, les deux `assertNotContains` tombent et les témoins positifs tiennent. Dette de couverture 21 → 18 | `c2` |
| **T32** | **Garde-fou n°37 — clés définies deux fois dans le même objet** | `{ a: 1, a: 2 }` est du JavaScript légal : la dernière gagne, rien ne le signale. C'est ce que produit une **fusion Git sans conflit** — quatre occurrences en deux jours sur `client.js`, rapportées par trois sessions. ⚠ **Ma première version a été jetée** : elle signalait la même clé « redéfinie » sur des lignes consécutives, du bruit qui a l'air d'un résultat. La version posée ne parse rien et s'appuie sur l'indentation à deux espaces — étroit et sûr plutôt que large et faux, et elle annonce ce qu'elle a réellement lu. **Fait** — 31/08 | `c2` |
⚠ **T27 est passé devant T26 pour une raison mesurée et non par préférence :** `/api/employes`
rendait **0 employé sur les deux établissements**. Or une note de frais exige un salarié
(`employee`, `JoinColumn(nullable: false)`), et un badge de service aussi. L'écran des notes de
frais livré en `a719568` était donc **inutilisable tant que T27 n'était pas fait** — il l'annonce
lui-même et renvoie vers Personnel. Un lot qui débloque un lot déjà livré passe devant.

⚠ **Trois pièges de routage sur la fusion, mesurés le 31/08 et consignés dans `client.js`**, parce
qu'ils ont coûté du temps aux deux sessions : `GET /api/crm/fusions` rend **405** et non 404 (la
collection vit sous `/api/journal_fusions`) ; la prévisualisation prend des **UUID** quand la
fusion prend des **IRI** ; et `qs()` ajoute lui-même les crochets d'un tableau, donc écrire
`'sources[]'` produit `sources[][]=…` que le serveur ne lit pas.

⚠ **Et aucun garde-fou ne voit cette dernière famille.** Le n°33 prouve qu'une route existe et
qu'aucun appel n'est orphelin ; il ne compose jamais l'URL, donc il est muet sur les paramètres —
celui en trop, celui qui manque, le tableau doublement crocheté, l'IRI envoyée là où le serveur
lit un UUID. C'est ce qui a laissé passer `sources[][]` jusqu'au premier clic d'un utilisateur.

### T33 — Vente d'abonnement : quatre lots, dans cet ordre

⚠ **ARBITRAGE DE MAXIME, 01/09 : « IL NE DOIT PAS Y AVOIR DE PRIX LIBRE. »** Confirmé directement,
pas seulement relayé. C'est donc **le guichet qui s'aligne sur l'en ligne**, et pas l'inverse : le
prix se résout depuis la grille tarifaire du produit, des deux côtés.

Ce que ça change, lot par lot :

| lot | avant | après l'arbitrage |
|---|---|---|
| **T33-2** *(fait)* | `montantCentimes` **saisi** dans le corps de la requête, exigé strictement positif | Le champ posé sur `AbonnementFitness` **garde tout son sens** : il devient le montant **résolu et mémorisé**, non le montant saisi. ⚠ Mais `SouscrireAbonnementProcessor:72` l'exige encore du corps — **c'est cette exigence qui est à retirer**, au profit d'une résolution de tarif |
| **T33-3** | « copier le chemin en ligne » | Le chemin en ligne résout le prix depuis le type de tarif et **refuse le produit s'il n'en a pas** (`SouscriptionAbonnementEnLigneHandler:132`). Le guichet doit faire pareil, donc échouer explicitement sur un produit sans tarif au lieu d'accepter n'importe quel montant |

⚠ **LA RÈGLE DE PRÉSÉANCE NE CHANGE PAS** : l'échéance fait foi, l'abonnement porte le montant
courant. L'arbitrage déplace la SOURCE du prix (grille au lieu de saisie), pas l'autorité entre
l'abonnement et l'échéance.

⚠ **ET IL RÈGLE LA TROISIÈME SOURCE PAR LE HAUT.** Puisque le prix vient de la grille tarifaire,
`Formule` n'a **pas** besoin d'un prix — la proposition « faire de la formule la source du défaut »
tombe d'elle-même. La formule reste périodicité, engagement, droits, services. Le prix vit dans la
grille, où il est déjà historisé.

⚠ **CE QUI RESTE OUVERT ET N'EST PAS TRANCHÉ : le prorata.** Sans prix libre, une première échéance
réduite ne peut plus être **saisie** — il faut la **calculer**. C'est une règle commerciale à écrire
(au prorata de quoi, arrondi comment, à partir de quelle date), pas un champ à remplir.
⚠ Un calcul existe et est éprouvé — `App\Subscription\Service\ProrationCalculator`, en centimes
entiers, journée d'entrée due en entier — mais il vit dans la facturation **de la plateforme
Fluvia**, pas des adhérents. L'arithmétique est neutre, l'emplacement ne l'est pas : l'extraire dans
un endroit partagé, ou le réutiliser en dépendance croisée, se décide. **Le dupliquer est la seule
option clairement mauvaise** — trois lignes qui décident d'un prélèvement, et deux copies
divergeront sur la borne de février avant que quiconque s'en aperçoive.

Mesuré par `allaccess-b8`, arbitré avec `c2`, inscrit ici par le worktree `claude-A` **sans le
prendre** — un lot qui ne vit que dans un fil de messages meurt avec la session qui l'a lu.

| # | lot | ce qui a été mesuré |
|---|---|---|
| **T33-1** | **Mandat SEPA partagé** : `AbonnementFitness.mandatSepa` passe de `OneToOne` à `ManyToOne`, **et** la révocation devient conditionnelle dans `DemanderResiliationHandler::executerEffet()` | ⚠ **LES DEUX DANS LE MÊME LOT, ET LES SÉPARER EST LE PIÈGE.** L'unicité ne protégeait pas le mandat, elle protégeait **la révocation** : `executerEffet()` fait `$abonnement->getMandatSepa()?->setStatut(Revoque)`, ce qui est correct tant qu'un mandat n'appartient qu'à un abonnement. En `ManyToOne` seul, résilier le premier révoque le mandat dont le second a encore besoin — **ses prélèvements s'arrêtent sans erreur et sans message**, l'adhérent garde son accès puisque son abonnement est actif, et personne ne le voit avant le rapprochement bancaire. `b8` a vérifié les autres appels : `SportEcheanceSepaSource` lit sans supposer l'unicité, rien ne remonte du mandat vers un abonnement. **Premier parce que c'est le seul des quatre qui devient plus cher avec le temps** : une migration de données le jour où des RUM sont déjà communiquées à une banque ne se réécrit pas d'un `UPDATE` | **FAIT** — worktree `claude-A`, 01/09. `ManyToOne` + révocation conditionnelle **dans le même commit**, migration `Version20260901040000`. ⚠ **`Impaye` et `Pause` comptent comme vivants** : un abonnement impayé est exactement celui dont on veut continuer à prélever, et révoquer son mandat effacerait le moyen de recouvrer. Seul `Resilie` libère le mandat. ⚠ **L'ordre des deux instructions SQL n'est pas interchangeable** — `mandat_sepa_id` porte une clé étrangère et MariaDB exige un index qui la couvre : retirer l'unique en premier rend « Cannot drop index: needed in a foreign key constraint », vérifié sur une table d'essai. ⚠ **`b8` avait cité trois appelants de `getMandatSepa()`, il y en a quatre** — `ReengagementHandler:74` écrit aussi le mandat, mais il en crée toujours un neuf (IBAN redemandé), donc il ne partage pas. Test `testResilierUnAbonnementNeRevoquePasLeMandatQuUnAutreEmploie` **vu échouer** avec la révocation inconditionnelle restaurée une minute : seul lui tombe, `testCa12` reste vert — la preuve qu'il ne pouvait pas l'attraper. 94 tests Sport+Sepa verts, 39 garde-fous.
| **T33-2** | **`montantCentimes` sur `AbonnementFitness`** (`NOT NULL` + `DEFAULT` au mapping) **et prorata d'entrée** | ⚠ **LA DESCRIPTION DE CE LOT ÉTAIT FAUSSE, et je la corrige plutôt que de la laisser.** Elle disait « aucun montant sur l'abonnement, le prix est relu du tarif produit à chaque échéance, donc un montant libre est inexprimable ». Faux sur les deux moitiés : `SouscrireAbonnementProcessor:72` **exige** un `montantCentimes` strictement positif du corps, et aucune lecture de tarif n'existe dans ce chemin — `GenerateurEcheancierHandler` l'estampille sur chaque échéance et ne le relit jamais. Le montant libre existait déjà. Ce qui manquait : la **mémoire** (l'abonnement ne le gardait pas) et le **prorata** (le générateur posait le même montant sur toutes les échéances). ⚠ **RÈGLE DE PRÉSÉANCE, décidée et non déduite : l'ÉCHÉANCE fait foi** — elle est datée, émise et opposable ; l'abonnement ne porte que le montant COURANT, et l'écrire ne réécrit aucune échéance. Sans cette règle, deux sources pour le même prix divergeraient au premier changement de tarif. ⚠ **Le prorata est un MONTANT fourni, jamais un calcul** : au prorata de quoi, arrondi comment, à partir de quand sont des décisions commerciales — une règle inventée s'appliquerait en silence partout. ⚠ **Zéro est une valeur** (mois offert), donc `!== null` et jamais `?:`. **FAIT** — worktree `claude-A`, 01/09, migration `Version20260901050000` avec rattrapage depuis la **dernière** échéance (la première peut être un prorata). Cinq tests **vus échouer** sur deux sabotages distincts. 99 tests Sport+Sepa verts, 39 garde-fous. ⚠ **`Formule` ne porte AUCUN prix** — « la formule propose le défaut » reste à faire et exige de lui en ajouter un, ce qui serait une TROISIÈME source : à décider, pas à déduire. ⚠ **`Formule.renouvellement` (`auto`, `prix: fixe`) n'est lu par PERSONNE** — mesuré : zéro appelant. Le renouvellement automatique est une promesse creuse |
| **T33-3** | **Encaissement au guichet**, en copiant `Boutique/Service/SouscriptionAbonnementEnLigneHandler` | ⚠ **LA DIVERGENCE EST L'INVERSE DE L'INTUITION.** Le chemin EN LIGNE construit `Vente` + `LigneVente` + `Paiement` et appelle `ValiderVenteService` ; le GUICHET (`Sport/Service/SouscriptionAbonnementHandler`) fait mandat + échéancier et **rien d'autre** : ni vente, ni paiement. **Un abonnement vendu au guichet aujourd'hui ne laisse aucune trace de vente.** « Même méthode des deux côtés » ne veut donc pas dire aligner l'en ligne sur le guichet — c'est le guichet qui rattrape. Sans cette mesure on fait spontanément l'inverse | ⚠ **IL Y A DEUX PORTES, PAS UNE — mesuré par `allaccess-b8` le 01/09.** `Sport/Service/ReengagementHandler` crée un **mandat neuf** et appelle `GenerateurEcheancierHandler` — donc douze prélèvements à venir — sans créer ni vente, ni ligne, ni paiement, et sans jamais valider. **Même forme exacte que la souscription au guichet**, et sur les adhérents qui REVIENNENT. Raccorder la souscription sans raccorder le réengagement rouvrirait le trou que la première vient de fermer. ⚠ **Et les deux portes partagent les QUATRE divergences** : prix libre (`ReengagerProcessor:42` reçoit `montantCentimes` comme le guichet), facette SEPA jamais lue (`RG-M3-17`, exigée en ligne seulement), aucune résolution de tarif, aucune trace de vente. **Ce n'est pas « le guichet est en retard », c'est « les règles vivent dans le handler en ligne et nulle part ailleurs ».** Le raccordement doit donc EXTRAIRE les règles là où les deux portes les traversent — leur donner deux copies de la même correction fait apparaître la cinquième divergence au prochain ajout, exactement comme les quatre premières. ⚠ **Critère de balayage employé, et sa moitié qui compte** : un service qui instancie une entité porteuse de montant sans jamais mentionner `Vente`, `LigneVente` ni `ValiderVenteService` — 22 fichiers sur 1166, dont 20 légitimes (une écriture comptable, un avoir, une caution rendue ne sont pas des ventes). **Le témoin qui valide le détecteur est celui qu'il ÉPARGNE** : `SouscriptionAbonnementEnLigneHandler` n'est pas signalé.
| **T33-4** | **`ContratAbonnement`** — signature au même moment que le mandat | **Aucun `ContratAbonnement` dans tout le dépôt** : ni entité, ni génération, ni signature. « Mandat et contrat au même moment » n'a qu'une moitié. Dernier parce que c'est un document à concevoir, pas un champ |

⚠ **Ne prends pas une refonte du modèle pour acquise sur la recherche adhérent/payeur.**
`Beneficiaire` et `Client` sont deux types parce qu'ils répondent à deux questions différentes —
qui entre, et qui paie — et un enfant inscrit par ses parents est le cas qui justifie la
séparation. Ce qui dépayse n'est probablement pas la séparation mais le fait de la faire porter
**à la saisie** : un écran qui cherche dans les deux et propose « créer le bénéficiaire à partir
de ce client » règle ça sans rien fusionner.

| **T33** | **Fiche produit — le bandeau du haut** (relevé par `b8`, arbitré par Maxime le 01/09) | Sa plainte : « c'est moche et pas pratique, on a l'impression de faire deux fois ». ⚠ **Ce n'est pas un doublon de code** — `b8` a vérifié qu'il n'y en a aucun — **c'est un doublon d'attention** : deux des trois tuiles (`Tarif`, `Vendu`) affichaient des valeurs qu'on modifie dans les sections plus bas, et le code le disait lui-même (« ajoutez une grille dans Tarifs, **plus bas** »). **Fait** — 01/09 : trois tuiles hautes → une ligne de sous-titre, valeurs cliquables vers leur section. ⚠ **L'état reste au badge** : `statutProduit()` le passe déjà en orange quand un produit publié n'a aucun tarif — le remettre dans le bandeau aurait déplacé le doublon au lieu de le supprimer. ⚠ **Stock reste gris et sans lien** : aucune section de la fiche ne le porte, un faux raccourci coûte plus qu'une valeur sans raccourci | `c2` |

| **T35** | **Fiche produit modifiable dans ses onglets** (arbitré par Maxime le 01/09, écran sous les yeux) | ⚠ **Ce n'était pas une modale** : `if (edition) return` **remplaçait la fiche entière** par un formulaire à plat — onglets et ligne compacte disparus. D'où « je n'ai pas du tout ce qu'il se passe quand on clique sur modifier ». **Fait** : les dix champs rejoignent l'onglet où ils se **lisent**, une seule barre d'enregistrement qui dit **combien de modifications et dans quels onglets**, et un onglet **Caisse** neuf. Les blocs sont **déplacés, pas réécrits** — ils portent des phrases qui valent plus que le code. ⚠ **Quatre défauts de moi trouvés en relisant** : trois champs oubliés en route (plus modifiables nulle part, build vert) ; une **zone morte temporelle** (`p` lu 150 lignes avant sa déclaration — ni le build ni le n°40 ne le voient, la portée est un lieu, la zone morte un moment) ; et surtout un effet qui ne suivait que l'identifiant, donc **le brouillon serait resté sur des valeurs vides et le premier enregistrement les aurait écrites** | `c2` |
| **T36** | **Un brouillon ne pouvait sortir de la liste qu'en étant mis en vente** | `actionsStatut('brouillon')` n'offrait que « Publier ». ⚠ **Et le serveur l'a toujours permis** : `TransitionProduitHandler::archiver()` n'exige aucun statut de départ. Ce n'était pas une règle métier, c'était une entrée manquante dans un tableau de l'interface — troisième cas de la nuit où le serveur sait faire ce que l'écran n'offre pas. **Fait** — 01/09, avec un texte de confirmation distinct : celui d'un produit publié annonce « il sortira de la vente », ce qui est faux d'un brouillon | `c2` |

### Reste ouvert sur la fiche produit

| # | ce qui reste | mesuré par |
|---|---|---|
| **T33-a** | **Le déséquilibre 2 / 6.** Vitrine porte deux rubriques, Configuration six, et mélange trois natures sans le dire : ce qui se vend (Tarifs, Diffusion), ce qui donne accès (Zones, Options), ce qui comptabilise. Les regrouper par nature ferait chercher moins | `b8` |
| **T33-b** | **La description ne s'affiche nulle part.** En tête de l'onglet Vitrine — donc le premier champ qu'on remplit — et la boutique publique ne la lit pas. L'écran le dit honnêtement, mais c'est du travail pour personne. Soit on la branche, soit on la sort de la Vitrine : à mesurer avant de proposer | `b8` |
| **T33-d** | **Composer les onglets de la fiche au lieu de les fixer.** Le vocabulaire existe depuis T34 : `controle_acces`, `comptabilite`, `stock`, `agenda`. ⚠ **Avant de brancher, accorder les capacités à tous les établissements existants** — sinon on retire un module qui marchait. Et ⚠ **« daté / récurrent » n'est pas une propriété du produit** : la date vient toujours d'un autre objet qui pointe vers lui (exposition, allocation de quota). L'agenda peut donc *montrer*, pas encore *créer*. **Fait** — 01/09, déployé. Six onglets possibles : Présentation et Vente toujours, puis Agenda, Accès, Stock et Comptabilité selon les capacités. **Absent, jamais grisé.** ⚠ **La migration d'octroi est passée AVANT le frontal** (`Version20260901030000`, vérifiée en base : 13 établissements, 13 octrois pour `comptabilite` et `stock`). ⚠ Et un **repli** évite l'écran blanc : changer d'établissement en étant sur « Comptabilité » laissait la vue sur un onglet disparu, et aucun bloc ne rendait rien. ⚠ **Effet de bord mesuré et voulu** : seuls 3 des 13 établissements portent `controle_acces`, donc 10 perdent les sections d'accès de la fiche — or le menu de gauche leur cachait déjà Supervision, Badges et Topologie. La fiche leur proposait de configurer des zones qu'aucun écran ne leur permettait d'atteindre : c'est une incohérence corrigée, pas créée | `c2` |
| **T33-e** | **Cinq champs modifiables côté serveur, atteignables par aucun écran.** Mesuré : le serveur accepte **13** champs en écriture, l'écran en édite **10**. Restent `code` (généré — **mon avis : le laisser en lecture**, il identifie le produit partout), `stock` (⚠ **deux sources pour la même quantité** si on l'édite ici *et* dans le module Stock — je n'y réglerais que dédié/pool), `formule` et `carte` (paramètres d'abonnement et de carte multi-entrées), et `champsPerso` | `c2` |
| **T33-f** | ⚠ **`champsPerso` est le mécanisme du produit daté, et je m'étais trompé en disant qu'il n'existait pas.** `champsPerso.timedEntry` + `champsPerso.ressourceId` relient un produit à une ressource de réservation, et **la boutique en ligne s'en sert déjà** pour vendre des créneaux ; `champsPerso.visuelUrl` porte le visuel. Ce qui manque n'est pas le modèle mais **l'écran** : un produit daté ne peut naître aujourd'hui que par les fixtures ou à la main. ⚠ `visuelUrl` est une URL libre **sans validation** — le brancher sur les photos déjà téléversées vaudrait mieux qu'un champ à coller | `c2` |
| **T33-c** | **La vendabilité n'a qu'un seul critère connu du frontal** : `sansTarifConnu`. Rien sur une zone d'accès manquante, une option obligatoire non configurée, une date échue. ⚠ **C'est ce qui a fait écarter le « résumé d'état »** : une pastille verte « Prêt à vendre » affirmerait une complétude que le code n'a pas. Énumérer et vérifier les vrais blocages rendrait ce résumé possible — et il serait alors meilleur que la ligne | `c2` |

### Signalements de `allaccess-b8`, 31/08 — inscrits pour que la décision existe

⚠ Aucun n'est pris. Ils sont ici parce qu'un signalement qui ne vit que dans un fil de messages
disparaît avec la session qui l'a reçu.

| # | ce qui a été mesuré | ce que j'ai vérifié en plus | urgence réelle |
|---|---|---|---|
| **S1** | `Crm/Command/AppliquerConservationCommand` sélectionne sur `dateCreation` — **l'ancienneté de l'inscription, pas l'inactivité**. Une règle à 36 mois anonymiserait d'abord les clients les plus fidèles, irréversiblement. Le bon champ existe : `Client::dateDerniereVisite` | ⚠ **Le correctif de b8 ne suffit pas.** `dateDerniereVisite` n'est écrite que par `EnrichissementClientSubscriber`, sur une **vente validée** — et **rien dans `Sepa` ni `Subscription` ne produit de vente validée** (mesuré). Donc un adhérent prélevé chaque mois, qui vient tous les jours, a sa « dernière visite » figée au jour de sa souscription. Il faut au minimum protéger aussi tout client portant un `AbonnementFitness` dont le statut n'est pas `resilie` — dont **`impaye`** : anonymiser un débiteur effacerait la créance. `Acces/Entity/Passage` ne porte pas de client, donc la porte n'est pas un signal exploitable en l'état | **latente** : zéro règle posée, et la tâche planifiée ne tourne pas. Deux raisons indépendantes. Mais elle s'exécute en une passe le jour où Maxime pose sa première règle RGPD |
| **S2** | Modèle de conservation trop pauvre : `RegleConservation` porte une durée et **aucun déclencheur** (codé en dur dans la commande). `categorieDonnee` est une chaîne libre comparée en dur à `'identite'` — une règle posée avec une autre valeur est **inerte en silence**. Le patron existe côté `Dms` : échéance stockée par enregistrement, base légale portée par la règle | non vérifié par moi | après S1 |
| **S3** | **14 écritures publiques dans `Boutique/`, un seul limiteur** (`TentativeIdentificationLimiter`, sur l'identification). Créer un panier et y ajouter des lignes **consomme du stock** pendant 15 min, sans frein : l'inventaire d'un exploitant se gèle en continu, anonymement. Et `CreationCompteHandler` est un **oracle d'énumération** (409 explicite, anonyme, sans cadence) : on déduit qui fréquente quelle piscine | non vérifié par moi | **réelle mais bornée** : `boutique:liberer-paniers-expires` tourne depuis le 31/08, fenêtre ~20 min |
| **S4** | Sans vitrine dans l'URL, la boutique publie **la liste des exploitants** (`vitrinesPubliques`) à tout visiteur | non vérifié par moi | **disparaît avec D104** (une boutique par sous-domaine). ⚠ Ne pas supprimer la **saisie manuelle d'identifiant** en repli, qui reste légitime |

Relevés par `allaccess-89`, qui les tenait de `34`. Deux autres de la même liste sont **faits et
poussés depuis** : le porte-monnaie virtuel et les notes de frais.

---

## 3 bis. Après l'API — les quatre axes de la feuille de route

⚠ **Ces quatre lots consomment l'API. Les commencer avant T1 produirait quatre couplages privés au
lieu d'une surface publique** (D102) — et rendrait la place de marché impossible à ouvrir sans tout
reprendre.

| # | lot | ce qu'il exige d'abord |
|---|---|---|
| **T11** | **Appli mobile adhérent, en marque blanche** — une appli commune qui prend les couleurs du club, plus une publication dédiée vendue en option (D105) | T1, et T15 pour l'identité visuelle. ⚠ Les deux versions doivent rester **identiques fonctionnellement** : le jour où la commune devient la parente pauvre, on maintient autant d'applis qu'on a de clients |
| **T12** | **Agrégateurs — et pas seulement fitness** | T1. C'est là que le multi-activités devient un avantage : une plateforme qui agrège piscines, patinoires et musées n'a pas d'équivalent |
| **T13** | **Balances et machines connectées** | T1, et le même port que le contrôle d'accès : un pilote qui **déclare ce qu'il sait faire** et échoue explicitement sur le reste (D17) |
| **T14** | **Assistant IA** | T1 et les trois autres. Il a besoin de données à lire et d'actions à déclencher — le construire en premier n'aurait rien à quoi se brancher |

---

## 3 ter. Le produit vu du dehors

| # | lot | pourquoi |
|---|---|---|
| **T15** | **Refonte graphique aux couleurs de Fluvia** | Les écrans portent aujourd'hui une identité par défaut. ⚠ À faire **avant** T11 : une appli en marque blanche décline une identité — s'il n'y en a pas, elle décline le vide |
| **T16** | **Site vitrine** sur `fluvia-app.com` | Aucune vitrine n'existe. Hôte séparé du back-office (D103) : elle porte des traceurs, il porte des sessions |
| **T17** | **Accueil d'un nouveau client (onboarding)** | ⚠ **Ce n'est PAS T2.** T2 reprend les données d'un client ; T17 est tout le chemin de la signature à une installation qui marche : créer le locataire, semer les référentiels, poser les types de produits et leurs comptes, le premier utilisateur, la formation. **T2 en est une étape.** Les confondre les ferait faire deux fois |

---

## 3 quinquies. Mesuré en passant, pas corrigé — à prendre par qui tient la zone

| ce qui a été mesuré | comment le revoir | pourquoi ça compte |
|---|---|---|
| **Aucun `trusted_hosts` déclaré.** L'`Host` vient de l'appelant. La résolution par hôte (D104) n'est pas concernée — elle échoue fermée — mais **tout ce qui fabrique une URL depuis la requête** l'est : liens de courriel, redirections | `grep -rn trusted config/packages/` rend zéro | Un lien de réinitialisation de mot de passe pointant vers un hôte choisi par l'appelant |
| **La validation s'exécute AVANT les processeurs**, et deux sessions s'y sont cassées le même jour. Chez moi : `EstablishmentStampProcessor` pose le slug d'une vitrine *après* la validation — un établissement nommé « Pro » aurait traversé l'`Assert` sans être vu. Chez `8e` : un `Assert\NotNull` sur un champ posé par un processeur refusait la requête avant qu'il puisse le compléter (création de région impossible, 422, sur un écran qui dit « créez-en une avant votre premier établissement ») | — | Le correctif est juste, invisible, et **vert nulle part**. Garde-fou n°34 à écrire : toute propriété écrite par un processeur ET porteuse d'une contrainte de non-vacuité |
| **`/tmp` est partagé entre les sessions.** J'ai écrasé mon propre message de commit avec celui d'une autre session, en écrivant dans `/tmp/msg2.txt` | — | Préfixe tes fichiers de travail par ton nom : `/tmp/<session>-…` |

---

## 4. Bloqué dehors — ne l'attends pas, prends autre chose

Sept blocages ne dépendent pas de nous (`BLOQUEURS-EXTERNES.md`). Les plus lourds :

    E-2  SEPA réel          contrat bancaire et ICS          Maxime
    E-3  encaissement carte  choix d'un prestataire           Maxime
    E-4  matériel d'accès    spécification IT Cotation        fournisseur
    E-7  rappels PayFiP      schéma de signature DGFiP        DGFiP

⚠ **Consigne, et elle a coûté cher :** un blocage externe se consigne et **on change de module**.
Aucune session n'attend. Une attente non écrite se transforme en travail refait par quelqu'un
d'autre.

---

## 5. Interdictions en vigueur

⚠ **`reservation:no-show:basculer` NE DÉMARRE PAS** (D95). Mesuré : 6 réservations, 0 présence
confirmée, aucun écran n'écrit le drapeau. La lancer facturerait une absence à des gens venus.
**Condition de levée : la première moitié est remplie depuis le 31/08, la seconde ne l'est pas.**

    un écran appelle `/emarger`          ✓ fait — la liste des inscrits d'un créneau, dans Réservation
    une présence confirmée existe en base  ⚠ À MESURER, ce n'est pas un écran mais un fait

⚠ **Ne pas lire la première coche comme une levée.** Tant que personne n'a réellement émargé, tout
créneau passé bascule encore en absence facturée — l'écran ne change rien tant qu'on ne s'en sert
pas. Et le second chemin prévu, `SourcePresence::PassageAcces`, n'est produit par personne : la
chaîne `Passage → DroitAcces → reservationRef → Reservation` existe en entier, il manque un écouteur.
Un contrôle d'accès qui remonterait la présence rendrait la condition vraie toute seule.

⚠ **Ne jamais ajouter une tâche à `infra/ordonnanceur.sh` sans vérifier `safeOnFirstRun`.** L'option
`--only` **contourne** cette garde : elle considère qu'un appel nommé est supervisé.

⚠ **`vente:cloture:journee` scelle.** Un premier passage sur trois semaines d'arriéré produirait
vingt et un arrêtés irréversibles.

⚠ **LA GARDE MFA SUR L'AFFECTATION EST SUSPENDUE, ET SON PARCOURS EXISTE DÉSORMAIS.**
`AffectationProcessor::MFA_EXIGE_POUR_ROLE_A_PRIVILEGES = false` depuis le 31/08 — décision de
Maxime, « lever maintenant, construire ensuite ». Sans elle, on ne pouvait nommer **aucun**
administrateur, chez aucun client : 6 rôles à privilèges, 0 compte avec MFA actif, et aucun écran
pour l'activer.

Le « ensuite » est fait : activation avec QR, secret et codes de récupération ; confirmation ;
second facteur à la connexion ; désactivation ; réinitialisation par un administrateur.

**Ce qui reste, et ce n'est pas à une session de le décider seule :** remettre la constante à `true`
rebloque la nomination d'administrateurs tant que chacun n'a pas activé son MFA — et **aucun humain
n'a encore parcouru ces écrans**. La question est posée à Maxime ; ne pas rétablir sans sa réponse,
et ne pas reconstruire les écrans, qui existent.

---

## 6. Les six règles qui coûtent le plus quand on les oublie

1. **Un zéro se soupçonne.** Avant de conclure d'une absence, exige un témoin positif : montre que
   ta mesure sait trouver quelque chose. Quatre zéros faux en une heure le 31/08, dont un parce que
   le répertoire cherché n'existait pas.
2. **Un contrôle de syntaxe n'est pas un contrôle de justesse.** `php -l` a dit « OK » sur deux
   fichiers dont un script avait supprimé la ligne essentielle.
3. **Écris le cas qui doit PASSER.** Un contrôle trop large est invisible à ses propres tests de
   refus : il les fait passer *mieux*. C'est le cas légitime qui distingue une garde d'un blocage.
4. **Casse ton filet une minute.** Un test qui n'a jamais échoué rend un vert qui ressemble à tous
   les autres.
5. **Avant de chercher POURQUOI un test échoue, établis À QUI il appartient.** `git stash`, relance :
   trente secondes contre une nuit.
6. **Aucun antislash, aucun `$`, aucun accent grave ne passe par le shell.** Outil d'édition puis
   `scp`. Dix-sept occurrences pour moi, dont trois le 31/08.

---

## 7. Ce qui tourne désormais tout seul

    sauvegarde de la base   quotidienne, rétention 14 j, vérifiée par témoin
                            restauration : infra/verifier-restauration.sh
    ordonnanceur            2 tâches sur 22, sous profil « ordonnanceur »
    33 garde-fous           bin/garde-fous.sh · hooks/pre-commit · hooks/pre-receive

⚠ **Un garde-fou neuf doit être câblé dans les TROIS listes**, sinon le commit qui l'ajoute est
refusé — c'est voulu. Idem pour les contrôles frontaux (`frontend/scripts/verifier-*.mjs`).
