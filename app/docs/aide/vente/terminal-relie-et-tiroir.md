---
titre: "Payer par carte sur un terminal relié, et ouvrir le tiroir-caisse"
categorie: caisse-vente
publicCible: agent
portee: global
moduleLie: vente
statut: publie
resume: "Avec un terminal relié, le montant part tout seul au terminal et le résultat revient sans ressaisie. Le tiroir s'ouvre après un paiement en espèces."
motsCles: [terminal de paiement, TPE, carte bancaire, Caisse-AP, sans contact, tiroir-caisse, pont de caisse]
---
## Ce qui change avec un terminal relié

Un terminal relié est branché au poste de caisse par le **pont de caisse**, un petit programme installé
sur le poste. Vous n'avez plus à taper le montant sur le terminal, ni à recopier le résultat.

1. Choisissez **Carte** et validez le montant.
2. Le message « En attente du terminal… » s'affiche. Le montant apparaît **tout seul** sur le
   terminal.
3. Le client présente sa carte (sans contact) ou l'insère et tape son code.
4. Le résultat s'affiche dans la caisse **tout seul** :
   - **accepté** : le règlement est enregistré, la vente se valide comme d'habitude ;
   - **refusé** ou **annulé** : aucun règlement n'est enregistré, et la vente n'est **pas** validée.
     Proposez un autre moyen de paiement.

Si le terminal n'a débité qu'une partie du montant, la caisse le dit : le reste est à régler.

## Abandonner un paiement par carte

Appuyez sur la **touche rouge** du terminal. Le terminal rend « abandon » et la caisse affiche
« Paiement carte annulé ».

Le bouton **Annuler** de la caisse libère la vente sans attendre le terminal. Si le terminal accepte
quand même la carte juste après, l'acceptation est enregistrée : le client a été débité.

## Quand la caisse ne sait pas

Si la liaison avec le terminal est coupée pendant le paiement, la caisse ne peut pas savoir si la carte
a été débitée. Elle vous demande alors de **lire le terminal** et d'indiquer ce qu'il affiche. Ne
réencaissez jamais la carte avant d'avoir fait cette déclaration.

## « Le pont de caisse ne répond pas sur ce poste »

Le pont n'est pas démarré. Redémarrez le poste, puis cliquez **Réessayer**. Si le message revient,
encaissez par un autre moyen et prévenez votre responsable : le pont se réinstalle avec la fiche
« Installer le pont de caisse ».

## Le tiroir-caisse

Quand votre point de vente est réglé pour cela, le tiroir s'ouvre **tout seul** après chaque
encaissement en espèces. S'il ne s'ouvre pas, la caisse affiche « Tiroir-caisse non ouvert » : la vente
est quand même enregistrée, et vous ouvrez le tiroir avec sa clé.

## Pour l'administrateur

Dans **Paramètres › Points de vente**, ouvrez le point de vente :

- **Terminal de paiement par carte** : choisissez « Relié (Caisse-AP) » ;
- **Clé du pont de caisse** : collez la clé affichée par le pont à son installation ;
- **Ouvrir le tiroir-caisse après un encaissement en espèces** : cochez si un tiroir est branché sur
  l'imprimante ticket.

Sans clé, le point de vente ne peut pas être réglé sur « Relié » : la caisse ne pourrait jamais
recevoir le résultat du terminal.
