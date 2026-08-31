# À revoir avec Maxime — liste ouverte

**Statut : demandé le 30/08 au soir, rien n'est commencé.** Ses mots : « n'oublie pas qu'il reste une
partie graphique, qu'il faut qu'on revoie les modules affaires, campagnes, projet, la facturation
enfin plein de choses ».

Ce document existe pour que la liste ne se perde pas entre deux sessions, et pour que la revue parte
de mesures plutôt que d'impressions — comme celle des typologies. **Aucune de ces lignes n'est un
chantier attribué :** ce sont des sujets que Maxime veut rouvrir avec nous.

---

## Ce que la mesure dit de chacun, au 30/08

Comptés sur `app/src`, `app/tests` et `frontend/src`. Le nombre d'écrans est approximatif — il compte
les fichiers du frontal qui nomment le module, ce qui ramasse aussi les mentions.

| module | entités | écrans | tests |
|---|---|---|---|
| **Compta** | 24 | 27 | 34 |
| **Crm** (affaires) | 13 | 8 | 21 |
| **Marketing** (campagnes) | 11 | 4 | 7 |
| **Facturation** | 9 | 10 | 18 |
| **Project** (projet) | 2 | 5 | **0** |

⚠ **`Project` n'a aucun test, et `Legal` non plus.** Ce sont les deux seuls modules du dépôt dans ce
cas — 41 des 43 en ont. Vérifié avec un témoin positif : la recherche trouve bien des tests pour les
autres, et les seules correspondances sur « projet » sont des `Projection*` appartenant à d'autres
modules.

Ça ne dit pas que le code est faux. Ça dit qu'**aucun filet ne le tient**, et que la revue de ce
module ne pourra s'appuyer sur rien.

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
