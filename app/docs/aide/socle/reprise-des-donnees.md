---
titre: "Reprendre les données d'un ancien logiciel : clients, abonnements en cours, soldes de cartes"
categorie: socle
publicCible: agent
portee: global
moduleLie: import
statut: publie
resume: "Importer par fichier CSV les clients, les abonnements en cours et les soldes de cartes, avec contrôle, application et annulation."
motsCles: [import, reprise, migration, csv, abonnement, carte, solde, crédit, ancien logiciel]
---
## Le principe

Menu **Reprise initiale**. Une reprise se fait en deux temps, sur l'établissement choisi en haut de
l'écran :

1. **Valider un fichier CSV.** Fluvia contrôle chaque ligne et n'écrit rien. Si une seule ligne est
   fausse, le fichier entier est refusé, et le détail liste toutes les lignes à corriger, avec leur
   numéro.
2. **Appliquer.** Les données sont créées en une seule fois.

Une reprise appliquée peut être **annulée**, tant que rien de ce qu'elle a créé n'a servi : un
passage au tourniquet ou un crédit entamé bloque l'annulation. Dans ce cas, corrigez par un second
fichier.

Chaque ligne porte la référence de l'ancien logiciel (`externalRef`). Si vous redéposez un fichier
corrigé, les titres déjà repris ne sont pas dupliqués.

Le fichier est un CSV, séparateur point-virgule, avec une ligne d'en-tête. Les dates s'écrivent
`AAAA-MM-JJ` ou `JJ/MM/AAAA`.

## Avant de reprendre des abonnements ou des cartes

- Le **produit Fluvia** qui correspond à chaque abonnement ou à chaque carte doit exister dans
  l'établissement. Dans le fichier, on le désigne par son **code**.
- Ce produit doit **ouvrir au moins une zone d'accès** de l'établissement. Sinon la ligne est refusée,
  car la carte reprise serait refusée au tourniquet.
- Les **numéros de carte** doivent être nouveaux pour Fluvia. Un numéro déjà connu est refusé.

## Abonnements en cours

Choisissez **Abonnements en cours**. Colonnes :

| Colonne | Contenu |
|---|---|
| `externalRef` | Référence de l'abonnement dans l'ancien logiciel (obligatoire) |
| `supportIdentifiant` | Numéro de la carte que l'abonné présente (obligatoire) |
| `produit` | Code du produit Fluvia correspondant (obligatoire) |
| `dateFin` | Date jusqu'à laquelle l'abonnement est payé (obligatoire) |
| `dateDebut` | Facultatif ; à défaut, le jour de la reprise |
| `typeSupport` | `RFID` ou `QR` ; à défaut, `RFID` |

Un abonnement dont la date de fin est passée est refusé : seuls les abonnements en cours se
reprennent. Après application, la carte ouvre le tourniquet jusqu'à la date de fin incluse.

Les abonnements payés par **prélèvement SEPA** ne se reprennent pas par ce fichier : ils demandent un
mandat signé et un échéancier.

## Soldes de cartes

Choisissez **Soldes de cartes**, puis saisissez le **total d'entrées restantes annoncé** par l'ancien
logiciel ou par son éditeur. Colonnes :

| Colonne | Contenu |
|---|---|
| `externalRef` | Référence de la carte dans l'ancien logiciel (obligatoire) |
| `supportIdentifiant` | Numéro de la carte (obligatoire) |
| `produit` | Code d'un produit « carte à entrées » de Fluvia (obligatoire) |
| `creditRestant` | Nombre d'entrées restantes, entier supérieur à zéro (obligatoire) |
| `dateFin` | Date de fin de validité, facultative |
| `typeSupport` | `RFID` ou `QR` ; à défaut, `RFID` |

Un solde est de l'argent déjà payé par le client. Si la somme des entrées du fichier diffère du total
annoncé, même d'une seule entrée, **le fichier est refusé** : soit l'export est incomplet, soit le
total annoncé est faux. Une carte échue ou à zéro entrée est refusée.

Après application, la carte ouvre le tourniquet, et chaque passage décompte une entrée.

## Ce que la reprise ne fait pas encore

- Elle ne rattache pas une carte reprise à une fiche client. La carte fonctionne comme une carte au
  porteur. La référence de l'ancien logiciel est conservée pour faire ce rattachement plus tard.
- Elle ne reprend ni les ventes passées, ni les produits, ni les tarifs, ni le personnel.
