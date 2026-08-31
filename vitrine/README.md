# `vitrine/` — le site de l'éditeur

Le site qui **vend la plateforme**. Pages publiques, lues par des gens qui ne sont pas encore
clients.

## À ne pas confondre

| | |
|---|---|
| **`vitrine/`** (ici) | Le site de l'**éditeur**. Vend la plateforme. |
| `frontend/src/public/pages/Vitrine.jsx` | La **boutique en ligne d'un établissement client**, servie sous `/vitrine`. Entité `Boutique\Entity\Vitrine`, table `bou_vitrine`. |

Les deux s'appellent « vitrine » et n'ont aucun rapport. Signalé à l'intégrateur ; en attendant, la
distinction est ici.

## Pourquoi un site statique, et pas une page de plus dans `frontend/`

**Le référencement.** La spec range « site vitrine et référencement » ensemble. Une page de vente
doit être lisible par un robot sans exécuter de JavaScript ; le dépôt n'a pas de rendu serveur, et en
ajouter un pour cette page serait disproportionné.

**Le poids.** `frontend/` a **un seul point d'entrée et un seul bundle** — `Root.jsx` arbitre entre
l'application d'administration et la boutique publique. Tout ce qu'on y ajoute grossit le bundle que
charge **aussi le caissier**, aujourd'hui ~365 ko (~105 ko compressés). Les images et polices d'une
page de vente le dégraderaient pour des gens qui ne la verront jamais.

## Les prix ne sont pas écrits dans la page

`tarifs.js` lit le catalogue réel (`/editor/plans`, `/editor/plan-options`). Un tarif de page qui
diverge du tarif facturé est un écart que le prospect relève avant nous.

Si la lecture échoue, la section bascule sur un message qui le dit franchement. **Aucune valeur de
repli n'est affichée** : un prix inventé sur une page publique est un engagement qu'on ne tient pas.

## Servir

Trois fichiers statiques, aucune chaîne de construction. `index.html` a besoin que l'API soit
joignable sur la même origine ; sinon, définir `window.API_BASE` avant `tarifs.js`.

## Ce qui n'y figure jamais

Aucun nom d'établissement client, aucun volume, aucune référence commerciale, aucun code technique à
l'écran. Le catalogue renvoie les codes de capacités parce que le tunnel en a besoin ; la page
n'affiche que leur libellé.
