# Deux définitions concurrentes de « visible », et le catalogue suit la plus large

*Constaté le 27/08/2026 en préproduction, sur un écran réel, par `claude-A`.*

## Le fait

L'utilisatrice **Administratrice Socle**, établissement actif **Piscine A**, voit dans son catalogue
**un seul produit** : `PRD-790F9143` (« Test »), qui appartient à **Patinoire B**. Elle l'a vendu à la
caisse de Piscine A — vente `S-123A6F63-00002-T00009`, encaissée et scellée.

Dans le même temps, **tous les produits du socle sont invisibles** (`PRD-ENTREE01`, `PRD-PLACE01`,
`PRD-CARTE01`… — quatorze produits sans établissement).

## La cause

Deux composants répondent à « ce produit est-il visible ici ? », et ils ne répondent pas la même chose.

| Composant | Périmètre appliqué |
|---|---|
| `PerimetreProduitExtension` (collections `Produit`) | les établissements où l'utilisateur a **une affectation** — toutes, quel que soit l'établissement actif |
| `OptionsDisponiblesProvider` | l'**établissement actif**, celui de l'en-tête `X-Etablissement` |

La première ignore `X-Etablissement`. Une administratrice affectée à deux sites voit donc les produits
des deux, en permanence, sans qu'aucun écran ne l'indique.

Et parce que la règle passe par `innerJoin('produit.etablissements')`, **un produit sans établissement
n'est visible de personne** : le socle disparaît entièrement au lieu d'être partagé.

## Pourquoi ce n'est pas qu'un défaut d'affichage

- **La caisse vend ce qu'elle affiche.** Un produit d'un autre site encaissé sur un point de vente
  entre dans sa chaîne de hachage NF525, qui est tenue **par point de vente**.
- **Le seul contrôle correct de la chaîne est celui qui a l'air cassé.** `OptionsDisponiblesProvider`
  vérifie explicitement l'appartenance et répond 404 ; c'est son refus qui a révélé la fuite. Le
  brancher sur la fiche produit a fait apparaître une bannière rouge — la tentation immédiate était de
  la faire taire.

> **Un contrôle absent laisse passer ; un contrôle présent mais plus strict que ses voisins a l'air
> d'être le fautif.**

## Ce qui n'est pas tranché

Aligner `PerimetreProduitExtension` sur l'établissement actif est le sens attendu — l'en-tête
`X-Etablissement` n'existe que pour dire « je travaille ici en ce moment ». Mais :

1. cela **retire** des produits à des écrans de pilotage multi-sites qui les affichent peut-être
   légitimement ;
2. `D51` distingue « socle + ajout local » de « entièrement cloisonné », et **le produit n'est classé
   ni dans l'un ni dans l'autre** : la jointure interne le traite comme cloisonné tout en le filtrant
   par affectation. Aucun des deux patrons.

**Arbitrage attendu de Maxime**, parce que la réponse change ce que voient les exploitants, pas
seulement ce que fait le code.
