# Produit complémentaire — et le mécanisme décoratif qu'il remplace

**Statut : modèle et règle de vente posés (`e8e95a7`), écran à repointer, ancien champ à retirer.**
Première des neuf typologies listées par Maxime le 30/08. Modèle validé par lui : « ça me va ».

---

## ⚠ RECTIFICATION — j'ai écrit une phrase fausse dans le message de commit

Le commit `65e833e` dit : *« "produit complémentaire" était la seule où il n'y avait RIEN — aucune
occurrence »*.

**C'est faux.** Il existait `Produit::$produitsAssocies` : un `ManyToMany` vers `Produit`, une table
`off_produit_associe`, le champ exposé en `produit:read` et `produit:write`, et un écran complet dans
`ProduitFiche.jsx` — cases à cocher, section de lecture, texte d'aide. Ce texte d'aide dit :

> « Ce qu'on propose avec : le cadenas avec l'entrée piscine, l'audioguide avec la visite. »

C'est-à-dire **exactement l'exemple que j'ai employé** dans ma propre migration (« le casier avec
l'entrée, le bonnet avec le cours »).

**Pourquoi je ne l'ai pas vu :** j'ai cherché `complement` / `complementaire` / `Complementary`. Le
code dit `associe`. Un synonyme suffit à rendre une recherche muette, et une recherche muette se lit
comme une absence. *On ne cherche pas un concept par son nom, on le cherche par son cas d'usage.*

---

## Ce qui est vrai, en revanche : rien ne le lit

Mesuré, avec témoin positif du grep (`getGrilles` → 13 occurrences, l'instrument fonctionne) :

    occurrences PHP de produitsAssocies / off_produit_associe   6
    dont situées hors de Offre/Entity/Produit.php               0
    lignes dans off_produit_associe (préprod)                   0

Les six occurrences sont la déclaration, la ligne de `JoinTable`, l'initialisation au constructeur,
le getter et son `add`. **Aucun appelant, nulle part.** La caisse ne propose jamais rien ; le
`ValiderVenteService` ne le regarde pas ; aucun test ne le touche.

⚠ **Et il y a un `add` sans `remove`.** C'est le premier des trois patrons relevés dans « les 200
menteurs » : une collection exposée en écriture sans remover, où l'écran croit décocher et où rien
ne se retire. Non éprouvé au runtime — le champ est destiné à disparaître, je ne dépense pas un test
sur lui — mais le patron est celui-là, et il est consigné ici plutôt que perdu.

**Bilan : un écran qui enregistre une liste que personne ne lit.** L'exploitant coche le cadenas en
face de l'entrée piscine, l'enregistre, et la caisse ne le proposera jamais. La promesse est faite à
l'écran, elle n'est tenue nulle part.

---

## `ComplementaryProduct` n'est pas un doublon : c'est la classe d'association du même lien

Un `ManyToMany` nu ne peut porter aucun attribut. Or le lien en a besoin de deux, et le premier est
la raison d'être de tout l'objet :

| | `produitsAssocies` | `ComplementaryProduct` |
|---|---|---|
| dire « obligatoire » | impossible | `mode` |
| quantité proposée par défaut | impossible | `default_quantity` |
| unicité de la paire | non contrainte | `uniq_complementary_product_pair` |
| retrait à la suppression du produit | non déclaré | `ON DELETE CASCADE` des deux côtés |
| retrait d'un lien | pas de remover | entité supprimable |
| lu par la vente | non | `RequiredComplementGuard` |

Enrichir un `ManyToMany` en classe d'association est la seule manière d'y attacher un attribut. Le
travail est donc le bon ; c'est son récit qui était faux.

**Il ne peut pas y avoir deux mécanismes.** *Une règle recopiée diverge au premier correctif* : le
jour où l'un gagne un attribut, l'autre ment. Et ici l'un des deux a zéro ligne et zéro lecteur — il
n'existe aucun futur où `produitsAssocies` l'emporte. Ce n'est pas un arbitrage produit, c'est une
conséquence.

---

## Les trois modes, et pourquoi pas un booléen

    facultatif (optional)     proposé décoché
    suggéré (suggested)       proposé coché, retirable
    obligatoire (required)    la vente ne se valide pas sans lui

Maxime a validé le modèle sans trancher « obligatoire bloque-t-il, ou est-il seulement coché
d'avance ? ». Les deux existent dans la vraie vie. Un booléen `obligatoire` qui se décoche serait un
nom qui ment ; trois valeurs disent chacune ce qu'elles font.

⚠ **Les deux erreurs ne se rattrapent pas de la même façon.** Un `suggested` posé à tort se décoche
en caisse. Un `required` posé à tort **arrête une file d'attente**, et l'agent n'a aucun moyen de
passer outre. `required` est donc réservé à ce qu'une règle *extérieure* impose — un règlement
intérieur, une obligation d'hygiène — jamais à une préférence commerciale.

---

## Ce qui reste à faire, et par qui

| | qui | pourquoi pas déjà fait |
|---|---|---|
| Écran : repointer `ProduitFiche.jsx` vers `ComplementaryProduct`, avec le choix du mode | **frontend** | pas ma voie |
| API : exposer la ressource | claude-A, **après** l'écran | le cliquet d'écart est à 685/685 ; une opération qu'aucun écran n'appelle ferait échouer le push de tout le monde |
| Retirer `produitsAssocies` du mapping et de l'API, `DROP TABLE off_produit_associe` | claude-A, **après** l'écran | ⚠ le retirer maintenant casserait un écran vivant qui écrit encore ce champ. *« Poussé » n'est pas « visible »*, et son symétrique : retiré côté serveur ≠ retiré côté écran. Zéro ligne en base : rien à reprendre au passage. |
| Le complément propose-t-il son propre billet ? | **Maxime** | dépend du paramétrage QR — voir `specs/acces/SPEC-BILLET-QR.md` |

⚠ **L'ordre compte et il n'est pas symétrique.** L'écran d'abord, le retrait ensuite : entre les
deux, les deux mécanismes coexistent sans se contredire, parce que l'ancien n'a aucun lecteur. Dans
l'autre ordre, il y a une fenêtre où l'écran écrit dans le vide.

---

## Ce que la garde vérifie, et ce qu'elle ne vérifie pas

Elle vérifie la **présence** du complément obligatoire dans la vente, pas la quantité.
`default_quantity` est une commodité de caisse : une famille de quatre prend quatre casiers, un
client seul en prend un. Exiger la correspondance transformerait un défaut de saisie en règle et
ferait refuser des ventes légitimes.

Elle passe **avant la transaction** : un refus n'a rien commencé à écrire, plutôt que de découvrir le
manquant au milieu d'un décrément de stock. Et le message **nomme** le complément manquant — un 422
muet laisse l'agent devant un client sans savoir quoi ajouter.
