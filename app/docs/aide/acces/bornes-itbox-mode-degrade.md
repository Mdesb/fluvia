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

L'onglet **Terminaux** affiche, pour chaque borne enrôlée, l'heure de son dernier appel et s'il a
réussi.

## Ce qui n'est pas encore livré

- **Le pilote ITBOX / SmartAccess.** Le protocole d'échange avec ce matériel doit être fourni par
  l'intégrateur (IT Cotation). Tant qu'il ne l'est pas, Fluvia ne commande l'ouverture d'aucune porte
  physique : la commande d'ouverture passe par un simulateur.
- **Le basculement automatique d'une borne en mode dégradé** dépend du logiciel embarqué dans la
  borne, pas de Fluvia.

## En attendant, sur un site

- **Contrôler un billet** à la main : l'agent saisit ou scanne le code, Fluvia dit s'il est valide.
- **Ouverture manuelle tracée** : l'agent ouvre avec un motif, le passage est horodaté et attribué.
- **Comptage non nominatif** (bébé, accompagnant, exonéré) : le passage compte dans la fréquentation.

## À savoir pour le jour où une borne sera branchée

Une borne qui travaille sur sa liste locale ne connaît un blocage qu'à sa **synchronisation
suivante**. Un support bloqué entre deux synchronisations peut donc encore passer sur cette borne :
le passage remonte alors **en conflit**, et c'est là qu'il faut regarder en cas de litige.
