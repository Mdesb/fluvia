---
titre: "Données personnelles : demandes, export, épuration et durée de conservation"
categorie: crm
publicCible: agent
portee: global
moduleLie: crm
statut: publie
resume: "Répondre à une demande d'effacement, exporter les données d'une personne, épurer le fichier client et fixer la durée de conservation."
motsCles: [rgpd, données personnelles, effacement, anonymisation, export, portabilité, épuration, purge, conservation, cnil]
---
## Où se trouve l'écran

Menu **Données personnelles**. Il faut la permission de gérer les demandes RGPD. La durée de
conservation se règle avec la permission de paramétrer le CRM.

Tout ce que fait cet écran porte sur le fichier client de **votre collectivité**, celle de
l'établissement que vous avez choisi en haut de l'écran. Si vous travaillez pour plusieurs
collectivités, changez d'établissement pour passer de l'une à l'autre : une épuration ne touche
jamais deux collectivités à la fois.

## Répondre à une demande d'effacement

1. Cliquez sur **Enregistrer une demande** et choisissez la personne. Le délai légal d'un mois
   commence ce jour-là.
2. Vérifiez l'identité du demandeur.
3. Cliquez sur **Traiter**, cochez la case de confirmation, puis **Anonymiser définitivement**.

La fiche n'est pas supprimée. Elle reste en base **sans identité** : nom, prénom, coordonnées, date
de naissance et référence de l'ancien logiciel sont effacés. Les ventes passées restent rattachées à
cette fiche anonyme, pour que la comptabilité reste juste.

## Exporter les données d'une personne

Pour répondre à une demande d'accès ou de portabilité :

1. Cliquez sur **Exporter les données d'une personne**.
2. Choisissez la personne.
3. Un fichier `donnees-personnelles-….json` est téléchargé.

Le fichier contient l'identité et les coordonnées, les liens familiaux, l'historique des
consentements, le porte-monnaie et ses mouvements, les abonnements, les achats et les demandes RGPD.
C'est un format structuré et lisible par une machine, comme l'exige le droit à la portabilité.
Remettez-le à la personne par un canal sûr. Chaque export est inscrit au journal d'audit.

## Fixer la durée de conservation

Dans **Durée de conservation des fiches clients**, saisissez une durée en mois, puis cliquez sur
**Enregistrer la durée**.

- Une fiche sans visite ni achat depuis cette durée est anonymisée automatiquement chaque nuit.
- La CNIL retient **trois ans (36 mois)** après la fin de la relation commerciale pour un fichier
  client. C'est la valeur proposée. La décision appartient à votre collectivité.
- Tant qu'aucune durée n'est fixée, **rien n'est anonymisé automatiquement**. L'écran l'affiche.

Le premier passage automatique est lancé sous la surveillance de l'éditeur. Les passages suivants
traitent au plus 500 fiches par nuit, et chaque fiche anonymisée est tracée.

## Épurer le fichier client à la main

1. Dans **Épurer le fichier client**, indiquez depuis combien de mois les fiches n'ont eu ni visite
   ni achat. Vous pouvez aussi ne retenir que les fiches créées avant une date.
2. Cliquez sur **Voir l'aperçu**. Rien n'est encore modifié.
3. L'aperçu affiche trois nombres : les fiches inactives, celles qui sont **protégées**, et celles qui
   peuvent être anonymisées. La liste suivante montre ces dernières, toutes cochées.
4. Décochez les fiches à garder.
5. Cliquez sur **Anonymiser les fiches cochées**, cochez la case de confirmation, puis
   **Anonymiser définitivement**.

Une fiche est **protégée**, et n'est jamais anonymisée, si :

- un abonnement est en cours, en pause ou impayé, qu'elle le paie ou qu'il soit à son nom ;
- il reste un solde positif sur son porte-monnaie.

Une fiche redevenue active entre l'aperçu et la confirmation est ignorée. Le message de fin indique
combien de fiches ont été anonymisées et combien ont été ignorées.

L'aperçu liste au plus 500 fiches. S'il y en a davantage, anonymisez ce premier lot, puis relancez
l'aperçu.

## Ce qui ne peut pas être annulé

L'anonymisation est **définitive**, qu'elle vienne d'une demande, d'une épuration ou de la durée de
conservation. Les données effacées ne peuvent pas être retrouvées.

Les anonymisations faites par épuration ou par la durée de conservation n'apparaissent pas dans la
liste des demandes : elles ne sont pas des demandes de personnes. Elles restent tracées, avec leur
date et leur auteur.
