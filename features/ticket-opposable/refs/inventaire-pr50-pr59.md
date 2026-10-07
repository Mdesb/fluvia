# Inventaire — #50 et #59 face à `main` (07/10)

> Lecture seule du 07/10, sur `origin/main` `4462a2d8` et la base `billetterie_preprod`. Les deux PR sont fermées au profit de `feature/ticket-opposable` (décision de Maxime du 07/10). **Les branches restent** (`feat/caisse-materiel` `4db0008b`, `feat/caisse-ticket-route` `60a6830b`) : elles servent de base au portage, après le CP-1.

## Ce qu'elles contiennent

Base commune avec `main` : `3f42fc1c` (09/09) ; `main` a 179 commits de plus. Diff utile : 18 fichiers, +1724 / −41 (sources Vente, 2 migrations, 6 fichiers de test). Aucun commentaire ni revue sur les deux PR.

| Commit | Objet |
|---|---|
| `c3dabb8d` | idempotence du règlement (+ migration `Version20260908083000`) |
| `11bba65f` | filet : épingle la sortie du ticket avant l'extraction |
| `40ccbc7f` | `DocumentTicket` : le ticket construit en un seul endroit |
| `04268a4e` | taux de TVA gravé sur la ligne depuis `Produit::$tauxTva` (+ migration `Version20260908094500`) |
| `b414b5d8` | `SaleVatBreakdown` : ventilation par taux, `complete` / `withoutRate` |
| `3178c9a2` | `TicketPdfRenderer` : PDF 80 mm (Dompdf) |
| `237a1780` (#59) | `GET /api/ventes/{id}/ticket.pdf` |
| `00addd98`, `4db0008b`, `60a6830b` | fusions — ne se reprennent pas |

**Portabilité mesurée** : `git merge-tree origin/main origin/feat/caisse-ticket-route` → un seul conflit, `app/migrations/Version20260908094500.php` (add/add). Aucun fichier Vente touché par les PR n'a bougé sur `main` depuis la base (hors `infra/nginx`, sans effet).

## Morceau par morceau

| Morceau | Encore nécessaire ? | Témoin |
|---|---|---|
| Clé d'idempotence sur `Paiement`, unicité (vente, clé), garde de rejeu avant le 1er effet (`c3dabb8d`) | **Oui, à compléter** — G-1…G-5 | `PaiementHandler.php:48-174` sans aucune recherche de règlement existant ; `Paiement` sans `cleIdempotence`. Manques de la PR : aucune sérialisation des appels concurrents (`PaiementProcessor.php:35-36`, ni transaction ni verrou), clé rejouée avec un autre montant acceptée, PMV débité hors transaction (`PorteMonnaieVirtuelAdapter.php:64-67`), rejeu après validation → 409 au lieu du règlement, **front inchangé** : aucune clé envoyée (`Caisse.jsx:764`) et « Réessayez » affiché à 45 s (`client.js:251,657`) |
| Docblock « ce que la clé ne ferme pas » (timeout TPE) | **Oui** — Q-A1 | Seul `TpeMock` est câblé (`services.yaml:138`) ; un timeout ne crée aucun `Paiement` (CA-10) |
| Migration `Version20260908083000` | **Oui, renumérotée** — G-18 | `vente_paiement.cle_idempotence` absente en préprod ; horodatage antérieur à la dernière migration de `main` (`Version20261006105004`) |
| Filet `DocumentTicketTest` (`11bba65f`) | **Oui** | `TicketProcessor` inchangé sur `main` depuis la base |
| `DocumentTicket` (`40ccbc7f`) | **Oui** — G-11 | `app/src/Vente/Service/DocumentTicket.php` absent de `main` |
| Graver le taux sur la ligne par `LineLabelStamper` (`04268a4e`, le mécanisme) | **Oui** — G-7 | `LigneVente` sans taux ; `LineLabelStamper` grave déjà le libellé (D61) |
| …depuis `Produit::$tauxTva` (`04268a4e`, la source) | **Contradictoire** — Q-B1 | Les ventes prennent le taux de la **catégorie comptable** (`RegimeBase.php:47`, `EmissionFactureJustificativeHandler.php:200`), dit aussi par la fiche produit (`ProduitFiche.jsx:1472`, #270) ; `Produit::$tauxTva` sert aux échéances (U-1, 08/09). Préprod : 2 produits sur 21 en portent un, **à 10 % quand leur catégorie dit 20 %** |
| Taux gravé en `decimal(5,2)` seul | **À compléter** — G-7 | Il faut aussi la catégorie EN 16931 et le libellé (`TauxTva::$vatCategory`) ; une clé seule ne fige rien, `TauxTva::$taux` se modifie par `PATCH` |
| Migration `Version20260908094500` | **Contradictoire (nom pris)** — G-18 | Même nom que la migration de #47 (`facturation_parametre.taux_tva_defaut_id`), déjà appliquée en préprod : celle de #50 ne tournerait jamais (garde-fou n°50) |
| `SaleVatBreakdown` — ventilation, aveu `complete` / `withoutRate` (`b414b5d8`) | **Oui pour le principe** — G-8, G-9 | Aucun calcul de ventilation dans `app/src/Vente` |
| …arrondi **par taux** | **Contradictoire** — Q-B2 | Écritures et facture justificative arrondissent **par ligne** (`RegimeBase.php:50`, `EmissionFactureJustificativeHandler.php:204`) : D63-bis, un seul calcul |
| `TicketPdfRenderer` 80 mm (`3178c9a2`) | **Oui, à compléter** — G-12 | Aucun rendu de ticket sur `main` ; Dompdf et `PoliceDeclaree` présents. Manques : vendeur (`ProfilExploitant` porte pourtant raison sociale, SIRET, TVA intracom, adresse), moyens de paiement et rendu, remises, n° d'édition ; date imprimée en **UTC** (PHP en UTC ; préprod : vente du 20/09 23:02 UTC = 21/09 01:02 à Paris) ; la hauteur ignore les libellés qui passent à la ligne (troncature) |
| Test « jamais certifié NF525 » | **Oui** — G-12 | Aucune certification |
| Route `GET /api/ventes/{id}/ticket.pdf` (#59) | **Oui sur le fond, contradictoire sur la forme** — G-13…G-15 | Aucune route PDF sur `main`. Mais : un `GET` qui ne compte rien permet des originaux illimités sans trace (cahier : « duplicata tracés », `spec-vente.md:84`) ; ne pose pas `imprime`, donc une annulation ultérieure laisse les billets valides (`ContrePassationHandler.php:44-50`) ; aucun contrôle du statut (ticket d'une vente en cours) ; cloisonnement réécrit à la main au lieu du périmètre de `GET /api/ventes/{id}` |
| Préfixe `/api` | **Oui** | `billetterie-preprod.conf:135` route toujours `api` |

## Les réserves écrites dans #50, une par une

| Réserve | État au 07/10 | Témoin |
|---|---|---|
| 1. Le PDF n'a pas de route | fermée par #59, à refaire en `POST` | G-14 |
| 2. Identité du vendeur absente | **la donnée existe** | `ProfilExploitant.php:78-148` — Q-C2 |
| 3. Duplicata non numéroté | toujours vrai | `Vente::$imprime` booléen — Q-C1 |
| 4. `Etablissement::$langue` | toujours absent | hors périmètre (français par défaut) |
| 5. Presque aucun produit ne porte de taux | vrai (2/21), mais ce n'est pas la bonne source | Q-B1 |
| `bin/verifier-derive-schema.sh` ne rend plus de verdict | **non mesuré** ; script inchangé depuis le 01/09 | à mesurer avant CP-2 |
