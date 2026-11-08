---
titre: "Encaisser une vente au guichet"
categorie: caisse-vente
publicCible: agent
portee: global
moduleLie: vente
statut: publie
resume: "Ouvrir une session de caisse, ajouter des articles au panier, encaisser et clôturer."
motsCles: [caisse, encaissement, vente, session caisse, cloture]
---
## Ouvrir sa session de caisse

Avant toute vente, ouvrez une **session de caisse** : renseignez le fond de caisse de départ. Une
session reste ouverte jusqu'à sa clôture (par vous ou par un responsable habilité).

### Votre code agent

Chaque agent peut avoir un **code agent** personnel de 4 à 8 chiffres. Il prouve que c'est bien vous
qui êtes au guichet, même sur un poste partagé.

- **Le définir ou le changer** : sur l'écran d'ouverture de caisse, cliquez sur « Définir mon code
  agent ». Saisissez le code choisi et le mot de passe de votre compte.
- **Quand il est demandé** : à l'ouverture, si le point de vente l'exige (option « Code agent exigé
  à l'ouverture », dans Paramètres › Caisse et moyens de paiement). La session indique alors que
  votre code a été vérifié. Le journal d'audit garde la trace de chaque ouverture : qui a ouvert, et si le code a été vérifié.
- **Après cinq erreurs**, le code est bloqué, même si vous saisissez ensuite le bon. Pour le
  débloquer, redéfinissez-le avec votre mot de passe, ou demandez à un administrateur de l'effacer
  (Paramètres › Utilisateurs › « Effacer le code agent »).

Votre code n'est jamais affiché ni conservé en clair : personne, pas même un administrateur, ne
peut le lire.

### Rouvrir une caisse sécurisée

Après la clôture Z, la caisse est **sécurisée**. Pour la rouvrir, le **code régisseur** est exigé :
c'est le code agent personnel du régisseur de la session. Un code faux est refusé, et chaque refus
est inscrit au journal d'audit.

## Encaisser une vente

1. Ajoutez les produits au panier (recherche, scan de code, catalogue par catégorie).
2. Le cas échéant, rattachez la vente à un client existant ou laissez-la anonyme.
3. Choisissez le ou les moyens de paiement (espèces, carte, chèque, porte-monnaie virtuel...) — un
   paiement peut être **partagé** entre plusieurs moyens.
4. Validez : le **ticket de caisse** est généré et peut être imprimé ou envoyé par e-mail.

## Annuler / rembourser

Une vente déjà encaissée ne se supprime jamais : une correction passe par un **avoir** (annulation
totale ou partielle), qui reste tracé dans l'historique comptable.

## Clôturer la caisse

En fin de service, lancez la **clôture Z** : elle fige les mouvements de la session et produit le
récapitulatif des encaissements par moyen de paiement, transmis à la comptabilité/régie.
