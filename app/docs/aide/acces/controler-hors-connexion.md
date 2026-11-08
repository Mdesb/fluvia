---
titre: "Contrôler les billets pendant une coupure réseau"
categorie: acces-controle
publicCible: agent
portee: global
moduleLie: acces
statut: publie
resume: "L'écran Composter continue de valider les billets sans réseau, sur une liste locale, et remonte les contrôles au retour."
motsCles: [hors connexion, hors ligne, coupure, composter, controle manuel, liste locale]
---
## Préparer le poste

Ouvrez l'écran **Composter** une fois avec le réseau : il télécharge la **liste locale** des titres
valides de l'établissement. Il la met à jour à chaque ouverture en ligne.

## Pendant la coupure

L'écran affiche **« Hors connexion »** et la **date de la liste** utilisée. Scannez comme
d'habitude :

- **Valide** : le titre figure dans la liste et n'a pas encore servi. Il est consommé sur le poste
  (une entrée unique ne repasse pas, une carte est décomptée).
- **Déjà contrôlé** : il a déjà servi, avec l'heure du premier passage. À vous de décider.
- **Inconnu de la liste locale** : le titre a été vendu **après** la liste, il est bloqué, ou il
  vient d'un autre site. Vérifiez le ticket du client (un ticket de caisse du jour, par exemple)
  avant de décider.

## Au retour du réseau

L'écran remonte d'abord les contrôles faits pendant la coupure, puis télécharge une liste neuve. Si
un titre a été admis hors connexion alors qu'il avait déjà servi ailleurs, le passage est enregistré
**en conflit** : il est visible dans la supervision, personne n'est « dé-entré ».

## Données gardées sur le poste

La liste locale ne contient **aucun code de billet** : seulement une empreinte chiffrée de chaque
code, qui ne permet pas d'en imprimer un. Elle ne contient ni nom ni donnée de client. Les codes
scannés **pendant la coupure** sont gardés sur le poste jusqu'à leur remontée, puis effacés.

Les bornes et portiques (ITBOX, SmartAccess) ne sont pas concernés par cette fiche.
