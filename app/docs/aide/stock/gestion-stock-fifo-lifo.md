---
titre: "Stock boutique : achats, réceptions et valorisation FIFO/LIFO"
categorie: stock
publicCible: agent
portee: global
moduleLie: stock
statut: publie
resume: "Fournisseurs, commandes/réceptions d'achat, mouvements de stock et valorisation FIFO/LIFO."
motsCles: [stock, fournisseur, commande achat, reception, fifo, lifo, inventaire]
---
## Fournisseurs et commandes

Un **fournisseur** est associé à un catalogue d'articles avec des conditions négociées. Une
**commande d'achat** liste les articles commandés ; sa **réception** (totale ou partielle) génère des
**lots de stock** valorisés au coût d'achat.

## Valorisation FIFO/LIFO

Chaque établissement choisit une méthode de valorisation par défaut (**FIFO** — premier entré, premier
sorti — ou **LIFO**) ; chaque **mouvement de stock** (vente, transfert, régularisation) impute la
quantité sur un ou plusieurs lots selon cette méthode, ce qui détermine le coût de revient consommé.

## Transferts et inventaires

Un **transfert** déplace du stock d'un article vers un autre (ex. entre deux points de vente). Un
**inventaire** compare la quantité théorique en système à la quantité réellement comptée ; un écart
jugé significatif doit être validé explicitement avant d'ajuster le stock.

## Alertes de réapprovisionnement

Un article dont le stock disponible descend sous son **seuil minimum** apparaît dans les alertes de
réapprovisionnement, jusqu'à la commande suivante.
