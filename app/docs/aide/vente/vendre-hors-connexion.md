---
titre: "Vendre pendant une coupure réseau"
categorie: caisse-vente
publicCible: agent
portee: global
moduleLie: vente
statut: publie
resume: "Continuer à encaisser sans réseau, remettre un ticket provisoire, et laisser la caisse remonter les ventes au retour du réseau."
motsCles: [hors connexion, hors ligne, coupure, panne internet, ticket provisoire, synchronisation, remontée]
---
## Ce qu'il faut avant la coupure

La vente hors connexion utilise ce que le poste a gardé **la dernière fois que la caisse s'est
ouverte avec le réseau** :

- une **session de caisse ouverte** (on ne peut pas ouvrir une session sans réseau) ;
- le **catalogue** de l'établissement, daté à l'écran.

Ouvrez donc la caisse normalement en début de journée : c'est ce qui prépare le poste.

## Quand le réseau tombe

1. Un bandeau orange **« Hors connexion »** apparaît en haut de la caisse.
2. Cliquez sur **Vendre hors connexion**. La caisse ne bascule pas d'elle-même : une coupure de
   quelques secondes ne doit pas interrompre une vente en cours.
3. Ajoutez les produits au panier, choisissez **espèces** ou **chèque**, puis **Encaisser**.
4. Un **ticket provisoire** s'imprime, numéroté `HL-XXXX-00001`, `HL-XXXX-00002`… Remettez-le au
   client : c'est sa preuve d'achat jusqu'à l'émission du ticket définitif.

Ce qui **ne se vend pas** hors connexion : la carte bancaire (le terminal passe par le serveur),
les abonnements (mandat et contrat) et les titres nominatifs (il faut choisir le bénéficiaire
parmi les clients).

## Quand le réseau revient

La caisse **remonte toute seule** les ventes en attente, dans l'ordre où elles ont été faites. Le
bandeau dit combien ont été remontées. Chaque vente reçoit alors son numéro définitif et est
**scellée** comme une vente ordinaire (NF525), **à l'heure où elle a été faite** au comptoir.

Une vente n'est jamais enregistrée deux fois, même si la remontée est relancée : chaque vente porte
une clé unique tirée au moment de l'encaissement.

Cliquez sur **Revenir à la caisse** pour reprendre la vente normale.

## Si une vente est refusée à la remontée

Le bandeau rouge liste les ventes que le serveur n'a pas pu enregistrer, avec la raison (un prix qui
a changé depuis le catalogue gardé, un produit retiré…). Elles **restent sur le poste**, et ne
bloquent pas les autres. Un responsable examine la raison : si elle a été levée (produit republié,
par exemple), **Renvoyer** la remonte. Sinon, l'encaissement correspondant est à régulariser : il
est dans le tiroir mais pas dans la journée.

## La clôture (Z)

Le bouton **Clôturer la journée (Z)** reste grisé tant que des ventes hors connexion de la session
attendent sur ce poste : après le Z, elles ne pourraient plus entrer dans cette journée. Remontez-les
d'abord.

## Données gardées sur le poste

Le poste garde, dans le stockage du navigateur (IndexedDB), uniquement ce qu'il faut pour vendre :
produits, tarifs et prix du catalogue, et les ventes en attente (produits, montants, moyen de
paiement, numéro provisoire). **Aucune donnée de client** n'y est écrite : une vente hors connexion
est anonyme. Chaque vente est effacée du poste dès que le serveur en a accusé réception.

N'effacez pas les données du navigateur et n'utilisez pas la navigation privée sur un poste de
caisse : les ventes en attente seraient perdues. En navigation privée, la vente hors connexion est
désactivée.
