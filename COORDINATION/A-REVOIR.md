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
| `reporting:agreger` | **les mesures cessent d'être produites** — l'Explorateur affiche déjà 3 jours « sans mesure » sur 14, et la consolidation région/groupe est vide de bout en bout |
| `reporting:executer-rapports` | un rapport planifié **ne part pas** ; l'écran le déduit des dates plutôt que de l'affirmer |
| `finance:treasury:suggerer-rapprochements` | dégradé : l'onglet « Suggérées » reste vide, le rapprochement à la demande fonctionne |
| `finance:treasury:detecter-ecarts` | dégradé : l'écran des écarts reste juste (il calcule en direct), seule la notification manque |

**Les deux premières changent ce qu'un dirigeant voit**, pas seulement ce qu'il reçoit : sans
`reporting:agreger`, tout l'étage consolidé du module d'analyse reste à « non mesuré ».

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
