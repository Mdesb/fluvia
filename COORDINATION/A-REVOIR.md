# À revoir avec Maxime — liste ouverte

**Statut : demandé le 30/08 au soir, rien n'est commencé.** Ses mots : « n'oublie pas qu'il reste une
partie graphique, qu'il faut qu'on revoie les modules affaires, campagnes, projet, la facturation
enfin plein de choses ».

Ce document existe pour que la liste ne se perde pas entre deux sessions, et pour que la revue parte
de mesures plutôt que d'impressions — comme celle des typologies. **Aucune de ces lignes n'est un
chantier attribué :** ce sont des sujets que Maxime veut rouvrir avec nous.

---

## Ce que la mesure dit de chacun — rafraichi le 02/09 au soir

Comptés sur `app/src` et `app/tests`. Le nombre d'écrans est approximatif — il compte les fichiers du
frontal qui nomment le module, ce qui ramasse aussi les simples mentions.

| module | entités | tests | écrans~ | écart depuis le 30/08 |
|---|---|---|---|---|
| **Compta** | 26 | 41 | 18 | +2 entités, +7 tests |
| **Vente** | 7 | 34 | 16 | — |
| **Facturation** | 9 | 27 | 5 | +9 tests (Factur-X, devise, pays) |
| **Crm** (affaires) | 13 | 23 | 4 | +2 tests |
| **Sport** | 10 | 18 | 8 | +6 tests (annulation d'échéance) |
| **Offre** | 17 | 17 | 9 | — |
| **Marketing** (campagnes) | 11 | 6 | 2 | — |
| **Project** (projet) | 2 | **0** | 2 | — |
| **Legal** | 2 | **0** | 1 | — |

⚠ **`Project` ET `Legal` N'ONT TOUJOURS AUCUN TEST**, et j'ai failli écrire le contraire.

Une recherche par NOM de fichier (`find -ipath '*Project*'`) rend six résultats — mais ce sont six
`Projection*` appartenant à Finance, Reservation et Acces. Le « test Legal » qu'elle trouve est
`Compta/Api/LegalVatRateTest.php`, qui teste `LegalVatRate` et n'a rien à voir avec le module Legal.

**La mesure qui tranche est la recherche par NAMESPACE**, parce qu'un test appartient au module dont
il importe les classes, pas à celui dont le nom lui ressemble :

    grep -rln 'App..Project..' app/tests --include=*Test.php   → 0
    grep -rln 'App..Legal..'   app/tests --include=*Test.php   → 0

Ça ne dit pas que le code est faux. Ça dit qu'**aucun filet ne le tient**, et que la revue de ces deux
modules ne pourra s'appuyer sur rien.

---

## La partie graphique

Distincte du chantier d'espacement, qui est en cours et cadré (cliquet n°27, conversion au fil des
écrans par `allaccess-34`). Ce que Maxime nomme ici n'a pas encore été précisé — charte, cohérence
visuelle, ergonomie, ou refonte d'écrans particuliers.

⚠ **Ne pas confondre les deux.** Le chantier d'espacement est une convention technique qui empêche
la dérive ; il ne produit aucun changement visible pour l'exploitant. Une « partie graphique » au
sens de Maxime est probablement autre chose, et personne ne l'a encore fait dire.

**Première question à lui poser** quand la revue s'ouvre : de quoi parle-t-il exactement ? Les trois
lectures possibles — apparence, cohérence, ergonomie — mènent à trois chantiers sans rapport.

---

## Ce que la revue devra établir, module par module

La même méthode que pour les neuf typologies, parce qu'elle a bien fonctionné :

1. **Ce que le code fait aujourd'hui**, mesuré — pas déduit d'un commentaire ni d'un nom de fichier.
2. **Ce qui est déclaré et que rien ne lit** — la famille des « 200 menteurs », qui a produit cinq
   trouvailles rien que le 30/08.
3. **Les questions qui n'ont qu'une réponse métier**, posées en choix multiple avec ce que coûte
   chaque option.

⚠ **Et ce qui a le plus payé le 30/08 : chercher par CAS D'USAGE, pas par nom.** Deux fois dans la
journée, une recherche par le nom que j'aurais choisi a rendu zéro sur un mécanisme qui existait —
« complément » là où le code disait « associé », `Complementary` là où les helpers étaient en
français. Une recherche muette se lit comme une absence.

---

## Ce qui bloque déjà, et qu'il faut dire avant la revue

- **Le prestataire de courriel** (`D82`) — rien ne part, et l'ordonnanceur attend derrière (`D83`).
  Touche directement Marketing (campagnes) et Facturation (relances).
- **La recharge de carte** (`D80`) — en attente du débrief cartes.
- **La carte cadeau** (`D70`, `D78`) — entièrement décidée, pas construite ; priorisée après l'outil
  de scan.

---

## Ce qui attend un arbitrage — relevé du 05–06/09

Quatre points sortis de la revue des modules d'analyse et de trésorerie. Aucun n'est un chantier :
ce sont des choix qui ne m'appartiennent pas, et pour chacun **le statu quo a déjà un coût**, écrit
ici pour qu'il ne soit pas subi par défaut.

### 1. Cinq commandes à autoriser, ou non, dans `TACHES_AUTORISEES`

Aucune n'est dans `infra/ordonnanceur.sh` (mesuré le 06/09). D36 a établi qu'une commande
périodique entre au dépôt avec sa planification ; D109/D110, que cette liste est une décision
versionnée. Elles ne pèsent pas la même chose :

| commande | ce qu'il se passe tant qu'elle n'y est pas |
|---|---|
| `finance:treasury:verifier-seuils` | **aucune alerte de trésorerie n'existera jamais** — la fonction est entièrement inerte, 0 ligne en base |
| `reporting:agreger` | **les mesures cessent d'être produites** — dernière mesure générée le **04/09 à 21:49** (`genereLe`, mesuré le 07/09) ; depuis, l'Explorateur rend « sans mesure » pour chaque jour écoulé, et la consolidation région/groupe est vide de bout en bout |
| `reporting:executer-rapports` | un rapport planifié **ne part pas** ; l'écran le déduit des dates plutôt que de l'affirmer |
| `finance:treasury:suggerer-rapprochements` | dégradé : l'onglet « Suggérées » reste vide, le rapprochement à la demande fonctionne |
| `finance:treasury:detecter-ecarts` | dégradé : l'écran des écarts reste juste (il calcule en direct), seule la notification manque |

> ⚠ **Ce point se dégrade pendant qu'il attend.** La version du 06/09 chiffrait « 3 jours sans
> mesure sur 14 ». Un compte de jours ne vieillit pas en devenant imprécis : il devient faux, et
> rien ne le signale. Il est remplacé ici par une **date de dernière mesure** et un **rythme** —
> le retard croît d'un jour par jour tant que la commande n'est pas autorisée.
>
> Vérifié le 07/09 : la commande existe bien (`AgregerMesuresCommand`, idempotente, trois passes
> site → région → groupe) et n'apparaît pas dans `TACHES_AUTORISEES`. Rien à écrire, seulement à
> autoriser.


**Les deux premières changent ce qu'un dirigeant voit**, pas seulement ce qu'il reçoit : sans
`reporting:agreger`, tout l'étage consolidé du module d'analyse reste à « non mesuré ».

> **Complété le 15/09 — ce point a deux moitiés, et une seule était écrite.**
>
> **a) Autoriser la tâche ne répare pas le passé.** `reporting:agreger` accepte `--depuis` et
> `--jusqu-a`, **par défaut aujourd'hui**, et `ScheduleCatalog` l'inscrit **sans option**. L'ajouter
> à `TACHES_AUTORISEES` empêche donc les trous à venir, et laisse le trou existant en place —
> définitivement, puisque rien d'autre ne le comble.
>
> **b) Le rattrapage est possible et sans risque.** Un jour passé est recalculable : l'agrégateur
> passe la période à ses projections (`caEncaisse`, `frequentationCumulee`), et l'écriture est un
> **upsert idempotent** via `Mesure.cleAgregation`. La commande peut donc être rejouée :
>
>     reporting:agreger --depuis=2026-09-05 --jusqu-a=<aujourd'hui>
>
> **c) ⚠ Et il y a de la vraie donnée à récupérer.** Mesuré le 15/09 sur la préproduction :
>
> | | |
> |---|---|
> | ventes depuis le 05/09, **invisibles** du module | **40**, sur 8 jours et 2 sites |
> | passages d'accès sur la même fenêtre | 0 |
> | *témoin* : ventes dans la fenêtre déjà agrégée (25/08 → 04/09) | 28 |
>
> Le module d'analyse est donc aveugle à **plus de ventes qu'il n'en montre**. Et le témoin des 28
> prouve que l'agrégation lit bien cette source quand elle tourne — ce n'est pas une hypothèse.
>
> ⚠ **J'ai failli conclure l'inverse.** Ma première lecture ne regardait que `acces_passage`, qui
> s'arrête au 02/09 : j'en avais déduit qu'un rattrapage remplirait le trou de faux zéros, et donc
> qu'il valait mieux ne rien faire. Les ventes démentent. Une conclusion tirée d'**une** des deux
> sources de l'indicateur valait exactement le contraire de la bonne.
>
> **Réserve, à écrire si le rattrapage est lancé** : la fréquentation sortira à **0** pour tout jour
> après le 02/09, faute de passages en base. C'est arithmétiquement juste et ça ne dit rien de la
> fréquentation réelle — c'est le générateur de données de la préproduction qui s'est arrêté, pas
> les visiteurs. Un `0` de fréquentation sur ces jours ne doit pas se lire comme un fait métier.


### 2. `AxeAnalytique` sert-il encore à quelque chose ?

Six axes en base, alimentés par les fixtures, **consommés par rien** : ni écran, ni moteur
d'agrégation. Je n'ai délibérément pas construit son écran — un formulaire de configuration
laisserait définir des axes d'analyse qui ne changent aucune analyse, c'est-à-dire exactement la
promesse vide que le reste de ce travail consiste à retirer.

Trois issues, et la question est en amont du frontal : **le moteur doit-il les lire** (alors le
travail est dans l'agrégation, pas dans un formulaire), **le référentiel doit-il rester** en
attente d'un usage, ou **la ressource doit-elle disparaître** ?

### 3. ~~Une politique de mot de passe, ou pas~~ — **tranche le 06/09, rien a revoir**

Ecrit comme une question ouverte le 06/09, et resolu le meme jour par `claude-A` pendant que je
l'ecrivais. La ligne est gardee plutot qu'effacee, pour que la revue sache qu'elle n'a pas a
s'en occuper.

`App\Securite\Service\PasswordPolicy` (audit du 06/09, constat 7) porte la regle en un seul
endroit, et **quatre portes l'appellent** — les deux flux que j'avais durcis, plus deux qui
n'avaient aucune regle : la creation de compte boutique (qui acceptait « aaa », verifie en
preproduction, HTTP 201) et la creation par un administrateur (qui acceptait « a »).

Les deux questions que je posais ont leur reponse dans le code : **douze caracteres**, adresse
e-mail refusee, pas de classes imposees — et **rien n'est demande aux comptes existants**, un
hachage ne se relisant pas. Les « aaa » volontaires de la preproduction continuent d'ouvrir ;
seuls les nouveaux mots de passe passent par la regle.

> ⚠ Ma fiche disait « le depot n'en a **aucune** ». C'etait vrai a l'ecriture et faux quelques
> heures plus tard. Une phrase qui decrit un defaut devient un mensonge le jour ou on le
> corrige — elle se rectifie la ou elle a ete ecrite.

### 4. Les fichiers d'export s'accumulent sans fin

Chaque export écrit un fichier dans `var/reporting/exports/` et garde sa référence. `Export`
n'expose pas de `Delete`, donc rien ne les retire jamais — et supprimer la ligne en base **ne
supprime pas le fichier** (constaté : cinq orphelins laissés par mes propres sondes, retirés à la
main).

Ce n'est pas urgent — il n'y a aujourd'hui aucun export — mais la question se pose avant les
premiers usages réguliers : **combien de temps garde-t-on un export**, et qui purge ?

> **Complété le 15/09 — ce point a un précédent dans le dépôt, il n'est plus une question ouverte.**
>
> Le même problème a déjà été tranché une fois, pour la GED :
>
> | | |
> |---|---|
> | commande | `dms:purge-expired-documents` (`app/src/Dms/Command/PurgeDocumentsCommand.php`) |
> | règle | 30 jours de grâce après `deletedAt`, et jamais si une rétention est active |
> | décidé par | arbitrage **D18 pt.6**, RG-DMS-15 |
> | déclarée | dans `ScheduleCatalog` |
>
> Côté exports d'analyse : **aucune commande**. Les fichiers atterrissent dans
> `var/reporting/exports/` (`StockageExportLocal`) et **rien ne les retire** — cherché dans tout le
> dépôt, pas seulement dans le module. Aujourd'hui le coût est nul : 0 fichier, 0 ligne dans
> `report_export`, 0 rapport planifié. Il devient réel dès que `reporting:executer-rapports` est
> autorisée, à raison d'un fichier par destinataire et par exécution.
>
> La décision se réduit donc à **deux mots** : adopter la forme de la GED (une commande, 30 jours,
> au catalogue puis dans la liste blanche), ou déclarer les exports éphémères et ne rien stocker.
>
> ⚠ **Et le précédent ne tourne pas non plus.** `dms:purge-expired-documents` est déclarée au
> catalogue et **absente de `TACHES_AUTORISEES`** : un document marqué supprimé n'est jamais détruit
> physiquement. Mesuré le 15/09 — 1 document supprimé le 06/09, **pas encore** purgeable ; il le
> devient le **06/10**. C'est daté, ce n'est pas un défaut actuel. Ça appartient au point n°1 de ce
> relevé, dont la liste passe donc de cinq commandes à six, et celle-ci a une dimension données
> personnelles : « supprimé » y veut dire « marqué », pas « effacé ».


### 5. Un créneau validé peut perdre son encadrant — **reformulé le 07/09**

Écrit le 06/09, et déjà à moitié dépassé : `feat(piscine): renouveler un diplôme, retirer une
affectation (#12)` a câblé le bouton de retrait pendant que je l'examinais. Ma phrase disait
« aucun écran ne l'appelle — le bouton manquant protège l'invariant par accident ». C'est faux
depuis ce jour-là.

**Ce qui reste vrai, et qui est le vrai sujet.** Trois lectures, refaites sur `origin/main` :

| ce qui a été lu | ce que ça dit |
|---|---|
| l'écran Piscine | le retrait n'est atteignable **que sur un créneau brouillon** — la modale d'affectation ne s'ouvre pas autrement. C'est correct, et c'est du pair |
| `DELETE /api/affectation_encadrants/{id}` | toujours **aucun processeur** : la seule permission `piscine.configurer` |
| `ValiderCreneauBassinHandler` | exige une qualification couvrante **au moment de valider**, et rien ne la relit ensuite |

L'invariant n'est donc plus fermé par accident : **il est fermé par l'écran, et par lui seul**. Un
appel direct à l'API retire encore l'encadrant d'un créneau validé, qui reste `valide` sans
couverture, sans que rien ne le détecte.

La question n'a pas changé de nature, seulement d'ampleur — elle ne porte plus sur un geste
manquant mais sur une garde manquante :

- **Refuser la suppression quand le créneau est validé** (409 nommé). Aligne le serveur sur ce que
  l'écran fait déjà ; bloque un remplacement d'encadrant si le modèle ne permet pas de dévalider.
- **Dévalider le créneau en même temps** — cohérent, mais un créneau publié qui redevient brouillon
  a peut-être des conséquences en aval.
- **Laisser tel quel** et l'assumer : la règle vit dans l'écran, l'API est un outil d'intégration.

> ⚠ Ma phrase du 06/09 est rectifiée ici plutôt qu'effacée. C'est la deuxième fiche de ce relevé
> qui vieillit en un jour — les autres se relisent avec la même méfiance.

### 7. Un site sans instrument rend « 0 », certifié complet — mesuré le 15/09

Le rattrapage de `reporting:agreger` (arbitrage n°1, tranché et exécuté le 15/09) a écrit 2 068
mesures de plus. En vérifiant **ce qu'il a écrit** plutôt que qu'il avait tourné :

| ce qui a été mesuré | résultat |
|---|---|
| sites **sans** aucun contrôleur d'accès | **7 sites × 11 jours** — `FREQUENTATION_CUMULEE` = `0.00`, statut **`complet`** |
| sites **avec** contrôleur (périmé ou hors ligne) | 5 sites × 11 jours — statut `partiel`, site nommé dans `sitesManquants` |

Les sept comprennent **Musée C** et **Patinoire B** : dans la base, un musée porte onze jours de
fréquentation nulle *certifiée complète*.

> ⚠ **RECTIFICATION DU 15/09, DANS LA DEMI-HEURE : CE ZÉRO N'EST ATTEIGNABLE PAR AUCUN RÔLE
> AUJOURD'HUI.** J'ai d'abord écrit la phrase ci-dessus seule, et elle sur-affirmait — vraie de la
> *donnée*, fausse de ce qu'un utilisateur voit. Mesuré ensuite :
>
> | entité | statut | atteignable par un rôle reporting |
> |---|---|---|
> | Site A1 / A2 / B1 Reporting | `partiel` | oui — et **correctement signalés** |
> | Groupe Démo Reporting, Région A/B Reporting | `partiel` | oui — **correctement signalés** |
> | GI-ONE FITNESS, Groupe Démo Support, Groupe Second Loisirs, Région B | `complet` à 0 | **non** |
>
> Les trois sites que le reporting atteint ont tous un contrôleur ; leurs mesures sont donc
> `partiel`, et le signal fonctionne. Les entités qui portent le zéro certifié sont hors du
> périmètre de tous les rôles reporting.
>
> **Le point reste entier, mais il est LATENT** : il se réveille le jour où un rôle reporting
> couvre un site non instrumenté — un musée, une patinoire, une salle de sport sans tourniquet.
> C'est-à-dire au premier client de ce type. « Atteignable » et « cassé » sont deux choses
> différentes, et je l'avais oublié sur ma propre trouvaille.

**Le mécanisme est sain, et c'est le point.** `etablissementHorsLigne()` lit les contrôleurs ; sans
contrôleur il rend `false`, avec sa raison écrite dans le code :

> « Pas de contrôleur Accès sur ce site (verticale sans contrôle d'accès physique) : ce seul signal
> ne peut pas conclure à un défaut de remontée (**Risque §9.7** plan-reporting.md). »

C'est juste : l'absence de tourniquet n'est pas une panne de remontée. Mais §9.7 nommait un
**risque**, et ce risque n'a jamais été tranché — il a été accepté au niveau de la détection, et
personne n'a regardé ce que l'écran en fait. L'Explorateur affiche donc `0` comme une valeur
mesurée, là où il disait honnêtement « aucune mesure » avant le rattrapage.

**Le levier technique est propre et déjà en place** : `Indicateur.sourceModule`.
`FREQUENTATION_CUMULEE` et les trois `FMI_MAX*` valent `acces` ; `CA` vaut `vente`, `FOND_CAISSE`
vaut `compta`. On sait donc, pour chaque indicateur, de quel module il tire sa source — et si le
site est instrumenté pour ce module.

Trois voies, et il n'y a rien d'autre à décider :

- **Ne pas écrire la mesure** quand le site n'a pas la source de l'indicateur. L'Explorateur
  redirait « aucune mesure », ce qu'il sait déjà faire (il distingue trois états). Le plus honnête,
  et il retire des lignes que quelqu'un pourrait déjà lire.
- **Un troisième état de complétude** — « non instrumenté » — distinct de `partiel`. `partiel`
  signifie « un site n'a pas contribué » et supposerait un défaut passager ; un site sans tourniquet
  n'est pas en panne, il n'est pas équipé. Plus juste, plus de travail.
- **Assumer le zéro** et le documenter comme tel. Alors il faut le dire à l'écran : un `0` de
  fréquentation sur un site non instrumenté n'est pas un fait de fréquentation.

⚠ **Je ne tranche pas, et je ne corrige pas de moi-même** : les trois voies changent ce que le
module *affirme*, pas seulement ce qu'il affiche. La première retire de la donnée déjà écrite.

⚠ **Et c'est moi qui ai recommandé le rattrapage**, dans le point n°1 de ce relevé, avec la réserve
« la fréquentation sortira à 0 pour tout jour après le 02/09 ». Cette réserve était juste sur la
cause et **trop étroite sur la portée** : je l'avais attribuée à l'arrêt du générateur de données de
la préproduction. Elle vaut en réalité pour **tout site non instrumenté, en production comprise**,
et indépendamment de tout générateur.

### 6. Un droit d'accès ne dit pas à qui il appartient — et sans ça, on ne peut pas le rattacher

Mesuré en cherchant à câbler `POST /sport/abonnements/{id}/rattacher-droit-acces`, la route qui lie
un abonnement fitness au badge physique. Elle est complète côté serveur et **aucun écran ne
l'appelle** ; en base, un seul des cinq statuts d'accès porte un droit, et il vient des fixtures.

Le blocage n'est pas la route, c'est la lecture. `GET /api/droit_acces` rend :

    sourceType, billetSupportRef, produitRef, authorisedSpaces,
    statutProjection, etablissement, synchroniseLe

**Aucun nom de porteur.** Sept droits, sept identifiants opaques. Un sélecteur bâti là-dessus
ferait rattacher le badge de quelqu'un d'autre au jugé — sur le mécanisme qui décide qui entre.

⚠ La décision n'est pas seulement technique. Exposer l'identité du porteur sur la liste des droits
d'accès, c'est de la donnée personnelle rendue à un écran d'exploitation. Trois voies :

- **Exposer le porteur** (nom, ou lien vers le bénéficiaire) sur la lecture des droits — le plus
  simple à utiliser, le plus large en données personnelles.
- **Un filtre par bénéficiaire** : l'écran demande « les droits de cette personne » plutôt que de
  lire la liste entière. Rien n'est exposé qu'on ne demande déjà.
- **Rattacher au moment de l'appairage** plutôt que depuis la fiche d'abonnement : le badge est
  physiquement en main, l'ambiguïté n'existe pas.

En attendant, l'onglet « Accès » dit honnêtement « non rattaché » au lieu d'afficher « ouvert »,
mais le geste reste indisponible dans l'application.
