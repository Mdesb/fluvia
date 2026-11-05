---
titre: "Bornes d'accès et fonctionnement hors connexion : ce qui est prêt, ce qui ne l'est pas"
categorie: acces-controle
publicCible: agent
portee: global
moduleLie: acces
statut: publie
resume: "Fluvia sait servir une liste locale à une borne et reprendre ses passages hors connexion ; le pilote des bornes ITBOX n'est pas encore branché."
motsCles: [itbox, borne, terminal, mode degrade, hors ligne, synchronisation, liste locale]
---
## En bref

Le **serveur** Fluvia est prêt à travailler avec des bornes qui continuent de contrôler pendant une
coupure réseau. En revanche, **le pilote des bornes ITBOX** (et des lecteurs SmartAccess) **n'est pas
encore branché** : aujourd'hui, aucune borne physique ITBOX ne télécharge la liste des droits ni ne
remonte ses passages vers Fluvia. Le mode dégradé décrit ci-dessous n'existe donc, sur un site, que
le jour où le matériel est intégré.

## Ce qui est prêt côté Fluvia

Une borne (un « terminal d'accès ») s'enrôle dans l'onglet **Terminaux** de l'écran Accès, avec la
référence de son concentrateur. Elle reçoit un jeton qui ne lui donne accès qu'aux portes de ce
concentrateur. Avec ce jeton, elle peut :

- **télécharger une liste locale** des droits valides et des supports révoqués, d'abord en entier
  puis par mises à jour successives (seuls les changements depuis la dernière version) ;
- **demander à Fluvia** si un support peut passer, quand le réseau est là ;
- **remonter en lot** les passages qu'elle a décidés seule pendant une coupure. Fluvia les rejoue dans
  l'ordre chronologique, ignore un passage déjà reçu (pas de double décompte), signale un écart
  d'horloge de plus de 5 minutes, et marque en conflit un passage fait avec un support bloqué entre
  temps.

La liste locale indique aussi, pour un **titre de groupe**, combien de personnes un passage fait
entrer : une borne coupée du réseau accorde donc le bon nombre de passages.

L'onglet **Terminaux** affiche, pour chaque borne enrôlée, l'heure de son dernier appel et s'il a
réussi.

## Une base commune pour tous les matériels

Le jour où un pilote de borne est branché, il s'appuie sur une base déjà écrite et testée dans
Fluvia :

- **reconnaissance des badges RFID existants** : un même badge est reconnu, que le lecteur rende son
  numéro en hexadécimal, en hexadécimal inversé ou en décimal. Les badges déjà distribués par le site
  n'ont pas à être réencodés ;
- **décision de passage** : la même règle que le contrôle en ligne (révocation, zones, horaires,
  anti-retour, crédit, jauge, titre de groupe) ;
- **décision hors connexion** sur la liste locale, et remontée en lot au retour du réseau ;
- **encodage au comptoir** : lire le badge posé sur l'encodeur et, si le poste le sait, y écrire
  l'autorisation avant de l'appairer.

Cette base est éprouvée avec une borne et un encodeur **simulés**, jamais encore avec un matériel
réel.

## Ce qui n'est pas encore livré

- **Le pilote ITBOX / SmartAccess.** Le protocole d'échange avec ce matériel doit être fourni par
  l'intégrateur (IT Cotation). Tant qu'il ne l'est pas, Fluvia ne commande l'ouverture d'aucune porte
  physique : la commande d'ouverture passe par un simulateur.
- **Le basculement automatique d'une borne en mode dégradé** dépend du logiciel embarqué dans la
  borne, pas de Fluvia.
- **Le poste d'encodage du comptoir** : le modèle d'encodeur n'est pas encore connu. En attendant, un
  badge s'appaire depuis l'onglet **Badges** en saisissant son numéro (ou en le lisant avec un
  lecteur USB qui le tape dans le champ).

## En attendant, sur un site

- **Contrôler un billet** à la main : l'agent saisit ou scanne le code, Fluvia dit s'il est valide.
- **Ouverture manuelle tracée** : l'agent ouvre avec un motif, le passage est horodaté et attribué.
- **Comptage non nominatif** (bébé, accompagnant, exonéré) : le passage compte dans la fréquentation.

## À savoir pour le jour où une borne sera branchée

Une borne qui travaille sur sa liste locale ne connaît un blocage qu'à sa **synchronisation
suivante**. Un support bloqué entre deux synchronisations peut donc encore passer sur cette borne :
le passage remonte alors **en conflit**, et c'est là qu'il faut regarder en cas de litige.
