# Reprise initiale d'un client — spécification

- **Décidé par Maxime le 31/08.** Trois arbitrages, portés en D97/D98/D99.
- **Périmètre :** ce qu'un nouveau client apporte le jour où il signe.
- **Hors périmètre :** les flux récurrents (relevés bancaires, retours SEPA, allocations OTA) et les
  corrections en masse. Ils viendront après, sur le même patron.

---

## 1. Ce qui est tranché, et ce que ça exclut

| Question | Décision | Ce que ça ferme |
|---|---|---|
| Par quoi commencer | **La reprise initiale** | Les deux autres familles attendent ce patron |
| Un fichier partiellement mauvais | **Tout refuser, en nommant les lignes** | Pas d'import partiel, jamais « la moitié d'un fichier » en base |
| Les ventes historiques | **On ne les importe pas** | L'ancien logiciel garde son historique le temps légal |

### Pourquoi les ventes historiques restent dehors

NF525 scelle les ventes **en chaîne** : chaque opération porte l'empreinte de la précédente. Y
injecter des ventes qu'on n'a pas produites fabrique des écritures scellées fausses — pas une
approximation, un faux au sens où un contrôle l'entend.

⚠ **Ce n'est pas une limite technique qu'on lèvera plus tard.** Un espace « antériorité » hors chaîne
reste possible et n'est pas fermé ; il demanderait de tenir la frontière dans **chaque** écran,
chaque export et chaque clôture — et une frontière tenue à 95 % en comptabilité ne vaut rien. La
question se rouvrira avec un expert-comptable, pas seule.

---

## 2. Un import est un OBJET, pas une action

C'est ce qui rend les trois décisions tenables d'un seul coup. `ImportBatch` porte le fichier, son
verdict, et ce qu'il a créé.

    ImportBatch
      id · type · establishment (estampillé serveur)
      fileName · mimeType · fileSize · contentHash
      content            ← le fichier source CONSERVÉ
      status             pending | rejected | validated | applied | reverted
      rowCount · errors  ← ligne → message, la liste ENTIÈRE
      createdAt · createdBy · appliedAt

Le précédent existe et fonctionne : `BankStatementImport` porte déjà `contentHash`, `content`,
`status` et `errorMessage`. **On généralise ce patron, on n'en invente pas un second.**

### Deux temps, et c'est la décision « tout refuser » qui les impose

    POST /imports                      analyse et VALIDE tout. N'écrit RIEN en base métier.
                                       → status = validated, ou rejected + la liste des lignes

    POST /imports/{id}/appliquer       applique, en UNE transaction.
                                       → status = applied

⚠ **La simulation n'est pas une option à cocher, c'est la première phase.** Un import qui écrit et
valide en même temps ne peut pas « tout refuser » : quand il découvre la ligne 4 217, les 4 216
premières sont déjà là. Séparer les deux temps donne le refus total *et* la prévisualisation, sans
qu'on ait à choisir.

### Le fichier source est conservé

Comme pour les relevés bancaires. Sans lui, un import contesté six mois plus tard ne se rejuge pas :
on n'a que le résultat, pas ce qui l'a produit. `contentHash` rend le doublon détectable — deux
dépôts du même fichier se reconnaissent.

---

## 3. Le vrai problème : dire si cette personne existe déjà

C'est le point où une reprise se gagne ou se perd. « Dupont Jean » existe-t-il déjà en base ? Une
mauvaise réponse **fusionne deux personnes** ou **en duplique une**, et les deux se découvrent des
mois plus tard, par une réclamation.

⚠ **Aucune heuristique sur le nom n'est acceptable.** Ni « nom + prénom », ni « nom + date de
naissance », ni un score de similarité. Ils marchent sur 98 % des lignes, et les 2 % restants sont
précisément les familles nombreuses, les homonymes et les fratries — c'est-à-dire les clients d'une
piscine municipale.

### La règle : chaque ligne porte la référence de l'ancien système

    externalRef    la clé du client dans SON logiciel précédent
                   obligatoire · unique par (établissement, type)

Elle fait deux choses d'un coup :

1. **Le rapprochement devient exact.** Pas de devinette : ou bien cette référence est déjà connue, ou
   bien c'est une création.
2. **L'idempotence devient ligne à ligne.** Rejouer un fichier corrigé ne duplique pas ce qui était
   déjà entré — même garantie que la clé d'idempotence du rejeu hors ligne, et pour la même raison.

⚠ **Ça déplace une charge sur le client**, et il faut le dire franchement : son extraction doit porter
ses identifiants. Tout logiciel en a ; peu les exportent spontanément. C'est un aller-retour de plus
à la reprise, contre une classe entière d'erreurs qui ne se rattrapent pas.

---

## 4. Ce qui se reprend

Par ordre de dépendance — chacun suppose le précédent.

| Type | Contenu | Remarque |
|---|---|---|
| `customers` | personnes et organismes | la racine, tout s'y rattache |
| `products` | catalogue | avec ses catégories comptables |
| `tariffs` | grilles tarifaires | ⚠ un produit publié sans prix est refusé (D91) |
| `subscribers` | abonnements en cours | échéance, formule, mode de règlement |
| `card_credits` | **crédits restants sur les cartes** | le plus sensible : c'est de l'argent déjà payé |
| `staff` | personnel et qualifications | |

### ⚠ Les crédits de cartes méritent d'être traités à part

Un crédit restant est une **dette envers le client** : il a payé dix entrées, il en a consommé
quatre, on lui en doit six. Une erreur ici ne se voit pas à la reprise — elle se voit au guichet,
devant la personne, six semaines plus tard.

Deux exigences propres à ce type :

- Le total repris doit être **rapproché d'un total annoncé par le client** avant application. Un
  écart, même d'une unité, refuse le lot. On ne devine pas une dette.
- Chaque crédit repris porte son `externalRef` et son `ImportBatch`, pour qu'une contestation
  remonte au fichier d'origine.

---

## 5. Trois règles qui ne se négocient pas

**L'établissement est estampillé au serveur (D41).** Une colonne du fichier ne désigne jamais où
écrire. ⚠ Un fichier qui choisit son établissement est une porte ouverte chez le voisin — et le
symptôme serait une ligne **en trop** chez quelqu'un d'autre, que personne ne remonte jamais à un
import.

**Rien n'entre hors d'une transaction.** L'application d'un lot réussit entièrement ou ne fait rien.

**Chaque ligne créée porte son lot.** Un `importBatchRef` — un `?Uuid` nu, jamais une relation
Doctrine (D2). C'est ce qui rend l'annulation possible.

### L'annulation

    POST /imports/{id}/annuler    → supprime exactement ce que ce lot a créé

Elle **refuse** dès qu'une ligne du lot a été employée depuis : une vente sur un client repris, un
passage sur un crédit repris. On ne défait pas ce qui a déjà servi ; on corrige par un second import.

C'est ce qui rend la reprise essayable : un exploitant qui sait qu'il peut revenir en arrière ose
lancer. Celui qui ne le sait pas repousse, et ressaisit à la main.

---

## 6. Ce qui reste ouvert

| Point | Qui décide |
|---|---|
| Format d'échange : CSV seul, ou XLSX aussi | Maxime — le CSV suffit techniquement, l'XLSX est ce que les clients ont |
| Un espace « antériorité » pour l'historique de vente | expert-comptable, puis Maxime |
| Écran de reprise, ou ligne de commande d'abord | selon qui accueille les premiers clients |
| Reprise des documents (contrats, certificats médicaux) | dépend du module DMS, non mesuré ici |

⚠ **Rien de ce document n'est construit à ce jour.** Il n'existe qu'un import dans tout le dépôt —
les relevés bancaires — et aucun import de données client. C'est une spécification, pas un état.
