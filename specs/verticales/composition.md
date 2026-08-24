# Les cinq verticales en compositions d'activités

> Périmètre `claude-I`. Application de **D15** aux cinq modules existants.
> Statut : **inventaire posé**. La conversion effective attend le format de paquet (ACT-0).

D15 pose que neuf types d'activité couvrent l'ensemble des métiers, et qu'« une verticale devient un
paquet rédigé, pas un module développé ». Reste à le vérifier sur les cinq verticales qui existent
déjà — 238 fichiers, 81 entités d'API. Ce document est ce contrôle.

## Ce que chaque verticale compose

| Verticale | Activités composées (D15) | Ce qui n'entre dans aucune brique |
|---|---|---|
| **Piscine** | entrée · réservation de ressource · cours/encadrement · location de matériel *(casier)* | **POSS** — jauge réglementaire calculée, avec prorata et seuil |
| **Padel** | réservation de ressource · location de matériel | **Tournoi** — poules, matchs, classement · **éclairage piloté** |
| **Patinoire** | entrée · location de matériel | **Saison éphémère** — fenêtre d'ouverture qui gèle tout le reste · **affûtage** |
| **Sport** | abonnement · entrée | **Sécurité du pratiquant isolé** — SOS, détection de présence, accès nocturne |
| **Musée** | entrée · prestation sur rendez-vous *(visite guidée)* · location de matériel *(audioguide)* · abonnement *(pass annuel)* | **Distribution OTA** — quotas alloués, priorité, reversement |

**Le verdict, sans complaisance : les neuf briques tiennent.** Aucune des cinq verticales n'a réclamé
un dixième type d'activité. Ce qui déborde n'est jamais un type d'activité manquant — c'est une
**règle réellement nouvelle**, exactement le cas où D15 autorise un module de code à subsister.

## Ce qui devient un paquet, ce qui reste du code

| Verticale | Fichiers | Devient paquet rédigé | Reste du code |
|---|---|---|---|
| Piscine | 46 | bassins, lignes d'eau, créneaux publics, casiers, cautions | POSS *(jauge + prorata + seuil)* |
| Padel | 63 | terrains, plages horaires, grille tarifaire, location, caution | tournoi, éclairage |
| Patinoire | 36 | zones, parc de patins, location, caution, retenue | saison éphémère, affûtage, liste d'attente pointure |
| Sport | 46 | abonnement, échéancier, pause, résiliation, réengagement | SOS et présence isolée |
| Musée | 47 | salles, expositions, gratuités, contingents, pass annuel | OTA *(quotas, priorité, reversement)*, visite guidée multilingue |

Grosso modo **deux tiers de chaque verticale sont du paramétrage rédigé**, un tiers est une règle
propre. C'est le résultat qu'on espérait de D15, et il est cohérent d'une verticale à l'autre — ce qui
est plutôt bon signe pour la solidité du découpage.

## Trois points d'attention pour la conversion

**1. Les données de départ doivent porter des identifiants stables et ne pas s'écraser.** D15 le dit
sans ambiguïté et c'est le point non négociable : une montée de version qui réécrit les tarifs du
client est une régression facturée au client. Les cinq `DataFixtures` actuelles ne portent aucun
identifiant externe — elles créent, elles ne réconcilient pas. C'est le vrai travail de conversion,
pas le déplacement de fichiers.

**2. Chaque conversion touche du cloisonnement.** Les cinq modules ont chacun leur
`Perimetre<X>Extension` Doctrine. Un paquet rédigé qui crée des données de départ crée des lignes
rattachées à un établissement : le contrôle de périmètre (D3/D8) doit valoir pour l'installation d'un
paquet comme pour une requête d'API. Suite du module **plus** `tests/Platform` avant chaque poussée.

**3. Le `shortName` d'API porte le mot de métier.** `PadelReservation`, `MuseeVisiteGuidee`,
`CreneauBassin` figent dans le contrat ce que D15 veut rendre variable. Arbitrage demandé à
`claude-A` — ma lecture est qu'on ne les renomme pas *(détail dans [vocabulaire.md](vocabulaire.md))*.

## Ce qui bloque la suite

Le **format de paquet verticale** (manifeste, activités composées, données de départ à identifiants
stables, drapeau de non-écrasement) est `ACT-0`, en `CLAIM` sur `claude-A` depuis le 22/08 et non
livré. L'inventaire ci-dessus ne dépendait pas de lui ; la conversion, si.

Proposition faite à `claude-A` : me déléguer la rédaction du format, en gardant chez lui le retrait de
`Metier` et `PresetVerticale` — les cinq verticales sont le seul banc d'essai réel du format, et un
format écrit sans elles se réécrira.
