# État du style et des saisies — mesure du 29/08

Demandé par Maxime : « fais un point sur la CSS, peut-être avec les autres sessions, il y avait des
choses qui n'étaient pas finies — par exemple le drag and drop ou encore des saisies de textes sans
mise en forme ».

Mesuré sur `frontend/src`, pas estimé. Ce document dit ce qui EST ; les sessions d'écran diront ce
qui est en cours.

---

## 1. Ses deux observations sont exactes, et plus nettes qu'il ne le pense

| | |
|---|---|
| Occurrences de glisser-déposer (`onDrop`, `onDragOver`, `dataTransfer`) | **0** |
| Éditeurs de texte enrichi | **0** |
| `<textarea>` en saisie brute | **19**, répartis sur 12 écrans |

⚠ **Les dix résultats qui ressemblaient à un éditeur sont de faux positifs** : ce sont des appels
`api.creerEditorPlan` du module éditeur, et des commentaires français contenant « éditeur ». Aucune
bibliothèque de saisie enrichie n'est installée, aucun `contentEditable` n'existe.

Les écrans où l'absence se voit le plus : **Mentions légales**, **Campagnes**, **Social** (une
publication sans gras ni lien), **Support** et **Messagerie**. Sur ces cinq-là, un texte brut n'est
pas une simplification, c'est un manque.

---

## 2. Ce qui est déjà en place, et qui est bon

Le style n'est pas à l'abandon — c'est même l'inverse de ce qu'on trouve d'ordinaire :

| | |
|---|---|
| Feuille de style unique (`styles.css`) | 896 lignes, **264 classes** |
| Usages de `className` | **4 161** |
| Variables CSS déclarées | 22 |
| Couleurs écrites en dur dans les composants | **5** |

Cinq couleurs codées en dur sur 106 fichiers : la couleur et la typographie sont **réellement
centralisées**. Et un garde-fou refuse déjà toute classe utilisée sans être déclarée dans la feuille.

---

## 3. Le vrai manque : la mise en page n'est pas dans le système

1 201 styles en ligne, répartis sur **88 fichiers sur 106**. Et quand on regarde ce qu'ils font :

| propriété | occurrences |
|---|---|
| `flex` | 334 |
| `gap` | 326 |
| `display` | 293 |
| `marginTop` | 275 |
| `margin` | 131 |
| `padding` | 116 |
| `marginBottom` | 111 |
| `fontSize` | 62 |
| `textAlign` | 44 |
| `color` | 36 |

⚠ **La lecture est nette : le système couvre la couleur et la typographie, pas l'espacement.** Environ
1 500 déclarations de mise en page sont écrites à la main, écran par écran.

Deux conséquences concrètes, et ce sont celles que Maxime voit sans les nommer :

- **Les écrans ne s'alignent pas entre eux.** Chaque page réinvente ses marges et ses écarts ; à
  quelques pixels près, rien ne tombe au même endroit d'un écran à l'autre.
- **Aucun réglage global n'est possible.** Passer l'application en mode compact, changer la densité
  pour une caisse tactile ou agrandir les espacements pour un écran mural demanderait de toucher
  1 500 endroits.

Ce n'est pas une dette qu'il faut solder d'un coup. C'est une convention à poser — une échelle
d'espacement en variables, quelques classes de disposition — puis à appliquer au fil des écrans
qu'on touche de toute façon.

---

## 4. Ce que je propose, dans cet ordre

1. **Une échelle d'espacement** en variables CSS (`--espace-1` à `--espace-6`) et trois ou quatre
   classes de disposition (`pile`, `rangee`, `grille`). Petit, et ça arrête l'hémorragie.
2. **Le glisser-déposer**, demandé nommément. Un seul composant de dépôt, réutilisé partout où l'on
   téléverse — le téléversement serveur existe déjà.
3. **La saisie enrichie**, sur les cinq écrans où elle manque vraiment. ⚠ Et jamais en stockant du
   HTML libre : du HTML saisi par un opérateur et rendu tel quel est une injection de script à
   retardement. Contenu structuré, rendu contrôlé — la même règle que celle posée pour le CMS.
4. **Ne pas réécrire les 1 200 styles en ligne.** On les remplace sur les écrans qu'on rouvre pour
   autre chose ; une réécriture massive coûterait une semaine et casserait des écrans qui marchent.

---

## 5. Ce que ce document ne dit pas

Il mesure le dépôt, il ne juge pas le rendu. Les sessions d'écran voient l'application ; moi je vois
des fichiers. Si l'une d'elles a des chantiers de style en cours, c'est son état qui fait foi, pas
ce comptage.
