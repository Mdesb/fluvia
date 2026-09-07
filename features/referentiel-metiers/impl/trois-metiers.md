# Bowling, escalade, parcs de loisirs — à créer dans l'écran

Ces trois métiers sont des **lignes en base**, pas du code. C'est tout l'objet du lot : `TradeFallback`
ne grandit jamais, et le garde-fou n° 55 refuse une entrée qui n'aurait pas son cas dans `Metier`.
Ils se créent donc dans **Site vitrine → Métiers**, une fois ce lot déployé.

Ce fichier contient exactement ce qu'il y a à saisir. **Les mots sont une proposition** : corrigez-les
dans l'écran, c'est fait pour ça — rien ici n'est figé dans le produit.

⚠ **Le code doit être saisi à l'identique.** L'écran de démonstration de chaque métier est la seule
partie qui reste en code, et il est retrouvé **par le code du métier**. Un `parcs_de_loisirs` au lieu
de `parcs-de-loisirs` donnerait une page correcte mais sans son écran, sans erreur et sans que rien
ne le signale.

---

## 1. Bowlings

| Champ | Valeur |
|---|---|
| Code | `bowling` |
| Nom | Bowlings |
| Titre de recherche | Logiciel de gestion pour bowling |
| Rang | 60 |
| Adresse | *(laisser vide — le code sert)* |

**Chapô**

> Des pistes qui se vendent à la partie autant qu'à l'heure, des chaussures qui sortent et qui
> reviennent, un bar qui pèse souvent la moitié du chiffre. Un bowling se tient sur trois comptoirs à
> la fois, et l'affluence arrive d'un coup.

**Activités à cocher** — Réservation de créneaux · Location de matériel · Vente de produits ·
Abonnements

**Ce que la page affichera** — 10 modules : Boutique en ligne, Casiers, Comptabilité, Gestion des
no-show, Location de matériel, Porte-monnaie virtuel, Prélèvement SEPA, Recouvrement des impayés,
Réservation de créneaux, Suivi de stock.

> ⚠ **« Restauration » a été retirée de cette proposition le 07/09**, après mesure du module et sur
> votre décision de le reprendre plus tard. Ce n'est pas seulement qu'il lui manque un écran : une
> addition **ne peut pas être soldée** aujourd'hui — le branchement à la caisse n'existe pas, et le
> code refuse explicitement de déclarer réglée une table qui ne l'est pas. Le détail est dans
> `features/restauration-en-salle/specs/`.
>
> Cochez-la le jour où le module sera fini : la page passera à 11 modules, sans autre geste.

> **Pourquoi « Abonnements » sur un bowling.** Ce sont les ligues : un engagement à la saison, encaissé
> par échéances. C'est aussi ce qui allume le porte-monnaie virtuel, qui correspond bien à la carte
> rechargeable des habitués. Si vos bowlings ne fonctionnent pas comme ça, décochez : les modules
> suivent immédiatement.

**Texte de page** (`Site vitrine → Pages de métiers`, bloc `metier.bowling.body`)

```html
<h2>L'affluence arrive d'un coup</h2>
<p>Un vendredi soir, un anniversaire à 18 h, une ligue à 20 h 30 : les pistes se remplissent par
blocs et se libèrent par blocs. Réserver à la partie, réserver à l'heure, ou les deux selon le
créneau — c'est la même piste, vendue de deux façons, et le planning doit tenir les deux sans qu'on
recopie quoi que ce soit.</p>

<h2>Ce qui sort du comptoir revient, ou pas</h2>
<p>Les chaussures sont le premier poste de perte d'un bowling. Elles se prêtent, se facturent quand
c'est le cas, et se rendent — et vous voyez à tout moment ce qui est sorti, ce qui n'est pas revenu,
et ce qu'il reste en taille 43.</p>

<h2>Ce qui se vend au comptoir n'est pas un à-côté</h2>
<p>La boutique se tient dans le même outil que les pistes, pas dans une caisse séparée qu'on
rapproche le lundi. Ce qui est facturé sur la piste 3 se retrouve sur la même note que la partie, et
la même comptabilité.</p>
```

> ⚠ Ce paragraphe parlait du bar. Il a été réécrit le 07/09 : annoncer la restauration dans le texte
> de vente alors que le module ne sait pas encore solder une addition aurait été une promesse que la
> démonstration démentirait.

---

## 2. Salles d'escalade

| Champ | Valeur |
|---|---|
| Code | `escalade` |
| Nom | Salles d'escalade |
| Titre de recherche | Logiciel de gestion pour salle d'escalade |
| Rang | 70 |
| Adresse | *(laisser vide)* |

**Chapô**

> Des abonnés qui viennent quand ils veulent, des chaussons et des baudriers qui tournent toute la
> journée, des cours encadrés par des brevetés. Une salle d'escalade vit de sa fréquentation libre,
> et se pilote sur ce qu'elle prête autant que sur ce qu'elle vend.

**Activités à cocher** — Billetterie et entrées · Abonnements · Cours et encadrement ·
Location de matériel

**Ce que la page affichera** — 10 modules, autant que la piscine : Casiers, Comptabilité, Contrôle
d'accès, Encadrants qualifiés, Location de matériel, Porte-monnaie virtuel, Prélèvement SEPA,
Recouvrement des impayés, Réservation de créneaux, Suivi de stock. Aucun n'est en construction.

> **Pas de « Réservation de ressource » ici, et c'est voulu.** Une salle de bloc ne réserve pas ses
> murs. La réservation apparaît quand même dans les modules, apportée par les **cours** — qui, eux,
> se réservent. Si vos salles réservent des créneaux de voie, cochez-la en plus.

**Texte de page** (bloc `metier.escalade.body`)

```html
<h2>L'abonnement est le modèle, l'entrée à l'unité est l'exception</h2>
<p>Un grimpeur régulier paie au mois et vient trois fois par semaine sans prévenir. Le prélèvement
qui revient impayé, lui, ne prévient pas non plus : il apparaît dans une liste à traiter, avec le
motif de la banque, et vous décidez de fermer l'accès ou pas — au lieu de découvrir le trou à la
clôture.</p>

<h2>Un encadrant n'est pas interchangeable</h2>
<p>Un cours n'est pas un créneau vide : il suppose quelqu'un qui a le titre pour l'encadrer. La
qualification est portée par la personne, et l'affectation à une séance la vérifie. Le planning ne
propose pas quelqu'un qui n'a pas le brevet.</p>

<h2>Ce que vous prêtez, vous le suivez</h2>
<p>Chaussons, baudriers, systèmes d'assurage : le matériel sort, se facture quand c'est le cas, et
revient. Les casiers suivent la même logique — attribution, caution, restitution — parce que c'est le
même geste au comptoir.</p>
```

---

## 3. Parcs de loisirs

| Champ | Valeur |
|---|---|
| Code | `parcs-de-loisirs` |
| Nom | Parcs de loisirs |
| Titre de recherche | Logiciel de billetterie pour parc de loisirs |
| Rang | 80 |
| Adresse | *(laisser vide)* |

**Chapô**

> Une billetterie horodatée pour lisser l'affluence, des attractions qui se remplissent par créneaux,
> une boutique et une restauration qui pèsent autant que l'entrée. Un parc se remplit à l'heure, pas
> à la journée.

**Activités à cocher** — Billetterie et entrées · Réservation de créneaux · Vente de produits

**Ce que la page affichera** — 6 modules seulement : Boutique en ligne, Comptabilité, Contrôle
d'accès, Gestion des no-show, Réservation de créneaux, Suivi de stock.

> ⚠ **Six, c'est peu à côté des dix des autres** — « Restauration » ayant été retirée (voir le
> bowling). Deux cases y remédieraient honnêtement si elles correspondent à vos parcs :
> « Abonnements » (le pass annuel — trois modules de plus) et « Location de matériel » (les casiers —
> deux de plus). Je ne les ai pas cochées de moi-même : mieux vaut ajouter une case en connaissance de
> cause que retirer un module déjà affiché sur une page publique.

**Texte de page** (bloc `metier.parcs-de-loisirs.body`)

```html
<h2>Lisser l'affluence, pas la subir</h2>
<p>Un billet horodaté n'est pas une contrainte imposée au visiteur : c'est ce qui lui évite la file.
Les créneaux se vendent à l'avance, la jauge se tient par tranche, et ce qui reste libre se voit —
en ligne comme au guichet.</p>

<h2>Un créneau réservé et jamais occupé est une place perdue</h2>
<p>Les absences non annulées sont comptées par client. Vous décidez de la suite : rien, un
avertissement, ou une pénalité. C'est la différence entre un parc plein sur le papier et un parc
plein.</p>

<h2>La boutique est dans le même outil que l'entrée</h2>
<p>Ce qui se vend au guichet et ce qui se vend à la boutique se retrouvent au même endroit, avec le
même encaissement et la même comptabilité. Pas de caisse séparée à rapprocher le lundi matin.</p>
```

---

## Comment ces chiffres ont été obtenus

En semant les huit lignes dans une base de test et en LISANT ce que `MetierCatalog` rend pour chacune
— pas en déduisant depuis la table des activités. La déduction se serait trompée sur un point
invisible : la page **écarte les capacités verticales**, et rien dans la liste des capacités ne le
signale. Témoin de la mesure : la piscine, dans la même passe, garde ses 10 modules et ses
4 spécificités.

## Ce qui est déjà en place pour eux

Leur **écran de démonstration** est déployé avec ce lot : `Bowling du Stade` et ses pistes,
`Bloc & Cie` et ses secteurs, `Parc des Cimes` et ses attractions. Il apparaîtra dès que la ligne
portera le bon code.

## Ce qu'ils n'auront pas, et pourquoi

Pas de section « Ce que Fluvia sait faire pour… ». Cette section n'accepte que des affirmations qui
**nomment une entité du produit** — c'est ce qui empêche d'écrire une promesse que la démonstration
démentirait. Le produit porte `Piscine\Entity\Poss`, `Patinoire\Entity\Affutage` ; il ne porte rien
de spécifique au bowling, à l'escalade ou aux parcs.

Ces trois pages vendent donc ce que le produit fait vraiment pour eux : le socle, ses modules, et
l'écran dans leur vocabulaire. Le jour où une verticale bowling existera dans le produit, la section
s'ajoutera — et elle sera vraie.
