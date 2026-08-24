# Clés de vocabulaire des verticales

> Périmètre `claude-I`. Application de **D15** aux cinq verticales existantes.
> Statut : **catalogue posé**, résolveur non posé (voir « Ce qui manque » en fin de document).

## Le problème, en une phrase

Un « créneau » est un *rendez-vous* chez le coiffeur et une *réservation de terrain* au padel : même
concept, mots différents. Aujourd'hui le mot est en dur — dans les `shortName` d'API, dans les noms
d'entités, dans les messages d'erreur — et c'est ce qui rend le logiciel illisible pour un métier qui
n'est pas celui pour lequel on l'a écrit.

**La règle qui en découle :** le concept est unique et technique (anglais, D5) ; le mot est une clé
i18n que la verticale surcharge. Aucune verticale n'ajoute de concept pour renommer un concept.

## Le catalogue

Douze clés couvrent le vocabulaire visible des cinq verticales. Les clés sont en anglais (D5), les
valeurs sont le libellé français par défaut.

| Clé | Concept noyau | Défaut (FR) | Piscine | Padel | Patinoire | Sport | Musée |
|---|---|---|---|---|---|---|---|
| `vocabulary.resource` | `Reservation\Ressource` | Ressource | Bassin | Terrain | Zone de glace | Salle | Salle |
| `vocabulary.resource_unit` | sous-unité réservable | Unité | Ligne d'eau | Terrain | Piste | Poste | Salle |
| `vocabulary.slot` | `Reservation\Creneau` | Créneau | Créneau public | Réservation de terrain | Séance de glace | Cours | Créneau de visite |
| `vocabulary.booking` | `Reservation\Reservation` | Réservation | Réservation | Partie | Session | Inscription | Réservation |
| `vocabulary.participant` | `Reservation\ParticipantReservation` | Participant | Baigneur | Joueur | Patineur | Adhérent | Visiteur |
| `vocabulary.staff` | encadrant / intervenant | Encadrant | Maître-nageur | Juge-arbitre | Encadrant glace | Coach | Guide |
| `vocabulary.group` | groupe de participants | Groupe | Groupe scolaire | Poule | Groupe | Cours collectif | Groupe scolaire |
| `vocabulary.entry` | `Acces` — un passage | Entrée | Entrée | Accès terrain | Entrée | Passage | Billet |
| `vocabulary.multi_entry_card` | `Offre\CarteMultiEntrees` | Carte multi-entrées | Carte d'entrées | Carte de parties | Carte de séances | Carte de séances | Pass annuel |
| `vocabulary.capacity` | jauge d'une ressource | Jauge | Jauge POSS | Occupation | Jauge de zone | Effectif | Jauge de salle |
| `vocabulary.rental` | location d'un matériel | Location | — | Location de matériel | Location de patins | — | Audioguide |
| `vocabulary.deposit` | `Caution` | Caution | Caution casier | Caution matériel | Caution patins | — | — |

Un tiret signifie **clé non surchargée** : la verticale n'expose pas le concept, et le défaut
s'applique si un module transverse l'affiche quand même. Ce n'est pas un trou à combler.

## Ce qui n'est pas une clé de vocabulaire

Trois familles ressortent de l'inventaire et **restent techniques**, sans quoi le catalogue enfle sans
rien rendre lisible :

1. **Les états** (`StatutLocationPatins`, `StatutVisiteGuidee`, `StatutCaution`…). Un statut est une
   valeur de domaine, pas un mot de métier : « réservé » se dit « réservé » partout. Ils relèveront de
   l'i18n ordinaire, pas de la surcharge par verticale.
2. **Les objets propres à un métier** (`Affutage`, `RelaisEclairage`, `PartenaireOTA`, `Poss`). Ils
   n'ont pas d'équivalent ailleurs : il n'y a rien à traduire d'une verticale à l'autre. Leur libellé
   est une clé i18n simple.
3. **Les messages d'erreur.** Ils citent une règle de gestion (`RG-SOCLE-07`, `US-L6-05`) ; le mot de
   métier y est accessoire, et les faire varier par verticale rendrait le support impossible.

## Ce qui manque pour que ce catalogue serve

**Il n'y a aucune infrastructure i18n dans le dépôt** — pas de `app/translations`, aucun service de
traduction dans `app/src`. D5 la suppose posée ; elle ne l'est pas. Le catalogue ci-dessus est donc
exact et inerte tant qu'il n'a pas de résolveur.

Le résolveur est du **noyau**, donc hors de mon périmètre (`app/src/Platform` ou `app/src/Fonctionnalite`,
propriété `claude-A`). Ce qu'il doit savoir faire, et rien de plus :

- résoudre une clé pour l'établissement courant, en tenant compte des activités qu'il **compose**
  (D15) — un camping avec une piscine et un bowling a deux surcharges possibles pour `vocabulary.slot` ;
- retomber sur le défaut français quand la verticale ne surcharge pas la clé ;
- **ne jamais écraser une surcharge saisie par le client à la mise à jour du paquet** (D15).

Le troisième point est le seul qui soit irréversible : un exploitant qui a renommé « baigneur » en
« usager » ne doit pas le reperdre à la montée de version.

## Arbitrage demandé

Le point de collision entre ce catalogue et l'existant est le `shortName` d'API : `PadelReservation`,
`MuseeVisiteGuidee`, `CreneauBassin` portent aujourd'hui le mot de métier **dans le contrat d'API**.
Les renommer casse les clients ; les garder fige le vocabulaire là où il devait devenir variable.

Ma lecture : on **ne renomme pas** les `shortName`. Ils sont techniques et anglais-compatibles ; le
mot de métier vit dans la clé, à l'affichage. Mais c'est un choix de contrat (D2), donc il revient à
`claude-A`. Demandé dans `RAPPORTS/claude-I.md` au battement du 24/08 18:26.

---

## Vérification : chaque clé a-t-elle un point d'affichage réel ?

Un catalogue de vocabulaire qui nomme des concepts que personne n'affiche est du décor. J'ai donc
confronté les douze clés au `frontend/` réel. Le résultat n'est pas celui que j'attendais, et il change
l'ordre des travaux.

### Quatre clés sont vivantes, et toutes les quatre au même endroit

`frontend/src/pages/Reservation.jsx` affiche aujourd'hui, en dur :

| Libellé affiché | Clé correspondante | Ce que verrait un padeliste |
|---|---|---|
| Ressource · Ressources | `vocabulary.resource` | Terrain · Terrains |
| Réservation | `vocabulary.booking` | Partie |
| Capacité | `vocabulary.capacity` | Occupation |
| Accès | `vocabulary.entry` | Accès terrain |

**C'est le meilleur rapport travail/effet de tout mon périmètre** : quatre libellés, un seul fichier,
et l'écran de réservation cesse d'être écrit pour un métier générique que personne n'exerce.

### Les huit autres n'ont aucun point d'affichage

`participant`, `staff`, `group`, `slot`, `resource_unit`, `rental`, `deposit`, `multi_entry_card` :
zéro occurrence dans le `frontend/`. Ce n'est pas que le catalogue soit faux — c'est que **les écrans
des verticales n'existent pas encore**. `Musee.jsx` fait 46 lignes, `Padel.jsx` 47, `Patinoire.jsx` et
`Piscine.jsx` 69 : ce sont des souches. Les écrans réels du produit sont génériques — `Caisse` (618
lignes), `Parametres` (768), `Catalogue` (478), `Reservation` (266).

**L'enseignement compte plus que le décompte.** Le vocabulaire de métier ne se joue pas dans des écrans
par verticale — il se joue **dans les écrans génériques**, qui sont les seuls que l'exploitant utilise
vraiment. Une verticale ne devait déjà plus être un module de code (D15) ; elle ne doit pas non plus
devenir un jeu d'écrans parallèles. C'est cohérent avec D13 : le moins d'écrans possible.

Les huit clés restent au catalogue : elles sont exactes, elles sont simplement en avance sur les
écrans. Je ne les retire pas, et je ne prétends pas qu'elles sont livrées.

### Un trou de propriété à signaler

**`frontend/` n'appartient à personne.** Ni `FLOTTE.md`, ni `OWNERS.md` ne l'attribuent — les neuf
périmètres sont tous en `app/src/**`, `specs/**`, `bin/`, `hooks/`, `vitrine/`. La substitution des
quatre libellés vivants est donc à la fois le travail le plus rentable du chantier et le seul que
personne n'ait le droit de faire.

Signalé à `claude-A`. Je ne touche pas `frontend/` sans attribution explicite (règle 2), même si le
correctif tient en quatre lignes — c'est exactement le cas où `claude-C` avait raison de refuser.
