# Spec — restauration en salle

**État : REPORTÉE par Maxime le 07/09/2026, avant CP‑2.** Ce fichier n'est pas un travail
abandonné : c'est la mesure qui a fait reporter, écrite pour que personne n'ait à la refaire.

**Ce qui a fait reporter, en une phrase :** il ne manque pas un écran à ce module, il manque le
branchement à la caisse — et ce branchement est de l'argent et du NF525.

---

## 1. Comment on en est arrivé là

Le site vitrine affiche « Restauration » dans les modules suggérés du bowling et des parcs de
loisirs, avec sa propre mention « module en construction — le serveur existe, il n'a pas encore
d'écran ». Maxime a d'abord choisi de l'annoncer **et de finir le module**. La mesure ci-dessous a
montré que « finir » était bien plus gros que « ajouter un écran », et il a reporté.

## 2. Ce qui existe, mesuré le 07/09

`src/Dining/` — 1 570 lignes, du travail soigné :

| Pièce | État |
|---|---|
| `DiningOrder`, `DiningOrderLine` | entités écrites, migration `Version20260901023900` posée |
| 6 processeurs (ouvrir, saisir, envoyer, annuler, clôturer, solder) | écrits |
| `DiningScopeGuard`, `DiningScopeExtension` | cloisonnement écrit **et testé** |
| `DiningBill`, `KitchenDispatch`, `CourseRef` | domaine pur, 19 tests unitaires |
| Manifeste, 4 permissions (`read`, `write`, `fire`, `void`) | déclarés |
| `#[ApiResource]` | **absent, et c'est une décision de son auteur** |
| `src/Dining/Entity` dans `mapping.paths` | **absent** — sans lui les routes n'existent pas (C9) |
| Tests HTTP | aucun |
| Écran | aucun |

L'auteur a laissé sa question dans le docbloc de `DiningOrder` :

> « Exposer viendra dans le même lot que l'écran. Question posée à l'intégrateur : comment une
> session serveur livre-t-elle une API avant l'écran qui la consomme ? »

La réponse est « les deux ensemble », et elle reste valable. Ce n'est pas ce qui bloque.

## 3. ⚠ CE QUI BLOQUE VRAIMENT — et qu'on ne voit pas de l'extérieur

### 3.1 Aucune addition ne peut être soldée

`SettleOrderProcessor` refuse toute addition dont le total n'est pas `0.00` :

> « Tant que l'encaissement n'est pas branché, on refuse plutôt que de déclarer soldée une addition
> qui ne l'est pas : une créance qui disparaît d'un clic ne se retrouve pas. »

Et `DiningBill::total()` est bien **ce que le client doit**, annulations déduites. Une table réelle a
donc toujours un total non nul : **le sixième geste est structurellement impossible aujourd'hui.**

Le refus est juste et il faut le garder. Le docbloc dit pourquoi : mélanger encaissement et
restauration ferait de `App\Dining` un second moyen de paiement, hors de la chaîne qui scelle les
écritures NF525.

Conséquence : livrer « le service complet » demande de brancher `App\Vente` et `App\Caisse` —
argent, NF525, scellement. `CLAUDE.md` classe explicitement cette zone comme sensible.

### 3.2 Il n'y a aucune TVA, nulle part

`DiningOrderLine` porte `label`, `quantity`, `unitAmount`, un service, un statut. **Ni taux, ni
compte, ni référence produit.** `DiningBill` additionne des montants et ne connaît aucune ventilation.

En restauration ce n'est pas un détail : 10 % sur place, 20 % sur l'alcool. Une addition sans
ventilation ne produit pas de document comptable.

⚠ **C'est ce qui rend la décision D‑1 de Maxime (relier à un catalogue de produits) plus juste
qu'elle n'en avait l'air** : c'est le `Produit` qui porte le compte et le taux. Le précédent est déjà
écrit dans `StockFixtures` — un mug de boutique tombait sur le compte de la billetterie, et personne
ne l'a vu **avant l'export FEC**.

La ligne devra **figer** taux et compte au moment de la saisie, pas les relire plus tard : une
addition imprimée ne change pas quand le prix change demain. C'est une migration.

## 4. Ce qui est bon à savoir pour le jour où on reprend

- **Le prix ne se recalcule pas ici.** `App\Vente\Service\PriceQuoter` est le service canonique, celui
  qu'appelle déjà la caisse et le point de tarif `GET /produits/{id}/tarif`. Une seconde
  implémentation reproduirait un défaut là où plus personne ne verrait la divergence — c'est
  exactement ce qui est arrivé une fois, « 1 × Test 10,00 € » pour un total de 15,00 €.
- **La carte est un jeu de `Produit`** d'un `TypeProduit` dédié, avec ses défauts comptables. Le
  mécanisme existe et est déjà utilisé par la boutique.
- **Le garde-fou `bin/garde-fou-modules-non-servables.php` refusera la poussée** dès que `dining`
  gagnera une ressource API : il fige la mesure du 04/09 sur laquelle repose la décision « non
  facturable ». C'est voulu — il dit « la mesure est périmée, refais-la », pas « vends-le ».
- **`peutServir()` devra être repris dans le même lot**, sinon le module marcherait sans être
  facturé, et rien ne le signalerait.

## 5. Ce qui a été décidé, et qui reste valable

- **D‑1 — la carte** : relier à un catalogue de produits, pas de saisie libre. *(Confirmé, et §3.2
  montre que c'est ce qui rend l'addition comptable.)*
- **D‑3 — le périmètre** : le service complet. *(Impossible tant que §3.1 tient.)*
- **D‑2 — la facturation** : à trancher à la livraison, pas d'avance.

## 6. Ce que ce lot laisse ouvert ailleurs

**`stay` est gratuit alors qu'il rend service.** Le garde-fou le dit dans son propre en-tête : il a un
écran depuis le 05/09 (`pages/Sejours.jsx`, câblé avec ses quatre permissions), et la mesure qui l'a
classé non facturable date du 04/09. C'est du chiffre d'affaires qui ne rentre pas, et c'est le même
arbitrage §8.1 que D‑2. **Indépendant de la restauration** — ça ne demande pas ce lot.
