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

### 3. Une politique de mot de passe, ou pas

Le dépôt n'en a **aucune** — création d'utilisateur comprise : n'importe quelle chaîne non vide est
acceptée. J'ai posé une longueur minimale de 12 caractères sur les **deux seuls flux** que je
touchais (activation d'une invitation, réinitialisation), parce que l'écran l'annonçait déjà et
qu'un formulaire qui affiche une règle que le serveur ignore annonce le trou au lieu de le fermer.

L'étendre ailleurs demande deux réponses qui ne sont pas techniques : **quel seuil**, et **que fait-on
des comptes existants** — les laisser tels quels, ou exiger un changement à la prochaine connexion.

### 4. Les fichiers d'export s'accumulent sans fin

Chaque export écrit un fichier dans `var/reporting/exports/` et garde sa référence. `Export`
n'expose pas de `Delete`, donc rien ne les retire jamais — et supprimer la ligne en base **ne
supprime pas le fichier** (constaté : cinq orphelins laissés par mes propres sondes, retirés à la
main).

Ce n'est pas urgent — il n'y a aujourd'hui aucun export — mais la question se pose avant les
premiers usages réguliers : **combien de temps garde-t-on un export**, et qui purge ?

### 5. Un créneau validé peut perdre son encadrant, et rien ne le voit

Trouvé en triant les appels que `mesurer-ecart.mjs` déclare orphelins — définis dans un client,
appelés par aucun écran. Trois lectures, et elles concordent :

| ce qui a été lu | ce que ça dit |
|---|---|
| `DELETE /api/affectation_encadrants/{id}` | existe, gardé par la seule permission `piscine.configurer` — aucun processeur, aucune règle métier |
| `ValiderCreneauBassinHandler` | exige une qualification qui couvre le type requis **au moment de valider** |
| tout le dépôt | **aucun autre fichier** ne relit cette couverture ensuite |

Retirer l'affectation d'un créneau **déjà validé** le laisse donc `valide` sans encadrant qualifié,
et plus rien ne le détecte. Ce n'est pas atteignable depuis un écran aujourd'hui : aucun n'appelle
`retirerAffectationEncadrant`. **Le bouton manquant est ce qui protège l'invariant — par accident.**

Trois issues, et elles ne se valent pas :

- **Refuser la suppression sur un créneau validé** (409 nommé). Conservateur, mais bloque un
  remplacement légitime d'encadrant si le modèle ne permet pas de dévalider un créneau.
- **Dévalider le créneau en même temps** — cohérent, mais c'est une décision métier : un créneau
  publié qui redevient brouillon a peut-être des conséquences en aval.
- **Laisser l'API ouverte et poser le bouton avec la même règle que l'affectation** (brouillon
  seulement, comme le bouton « affecter » qui existe déjà). Ferme l'écran, laisse l'API ouverte.

Je n'ai pas tranché : choisir demande de connaître le métier, pas le code.
