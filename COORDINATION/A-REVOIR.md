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
