# Spec — l'encaisse d'une régie suit la clôture Z

Arbitrages de Maxime, 08/09 : **déclencheur = la clôture Z**, **rattachement = le point de vente**.

Zone sensible : argent, NF525, comptabilité publique.

## 1. Le défaut, mesuré

`RegieHandler::enregistrerEncaissement()` est la **seule** méthode qui incrémente
`RegieRecettes::soldeEncaisseCentimes`. Relevé sur `app/src/` : elle n'a **aucun appelant en
production**. Le champ n'est pas non plus dans le groupe `regie:write`, donc l'API ne peut pas
l'écrire.

Conséquence en chaîne, chaque maillon vérifié dans le code :

| Maillon | État avant ce lot |
|---|---|
| L'encaisse d'une régie part de 0 | et ne peut que **descendre** (versement) |
| `RegieRecettes::depassePlafond()` | ne peut jamais être vrai |
| `ClotureGuard::pointsBloquants()` | ne bloque **jamais** une clôture pour ce motif |
| `VersementRegie` (écran) | n'a **jamais** rien à verser |

En base de préprod : la seule régie existante portait `solde_encaisse_centimes = 0` pour un plafond
de 5 000 €, et **0 bordereau** n'avait jamais été émis.

⚠ **Les tests étaient verts, pour une raison qui n'a rien à voir.** `RegieTest.php:27` appelle
`enregistrerEncaissement()` lui-même ; `ClotureTest.php:27` et `SimulerClotureTest.php:90` écrivent
`setSoldeEncaisseCentimes()` directement. Ils prouvent que le mécanisme sait compter. Aucun ne
prouve que quelque chose l'alimente.

## 2. Le déclencheur

**À la clôture Z.** Les espèces réellement comptées, moins le fond reporté qui reste dans le tiroir,
entrent dans l'encaisse de la régie.

Écarté : *à chaque encaissement* ferait porter à l'encaisse le **théorique**, qu'un écart de caisse
découvert le soir rendrait faux ; *le champ `versement` de la Z* figerait un sens sur un champ qui
n'en a aucun d'écrit, et qui peut vouloir dire « remise en banque » chez un exploitant privé.

## 3. Le rattachement : sur le guichet

Une régie pend au `ProfilExploitant`, donc à l'établissement. Résoudre par ce chemin marche tant
qu'il n'y a qu'une régie et devient une **devinette** dès qu'il y en a deux — une collectivité qui
tient piscine et patinoire sur le même profil. L'argent de l'une entrerait dans l'encaisse de
l'autre, et le symptôme serait un plafond qui déborde là où personne n'a encaissé.

`PointDeVente.regie`, `ManyToOne` nullable, `ON DELETE SET NULL`. La session porte son point de
vente, le point de vente porte sa régie : la question ne se pose plus. `ManyToOne` et non
`ManyToMany` — un guichet n'encaisse que pour une régie, et la contrainte est structurelle plutôt
qu'écrite.

`null` est le **cas normal** : un exploitant privé ou un délégataire n'a pas de régie.

## 4. Ce qui était déjà acquis, et qu'il ne fallait pas refaire

- **L'idempotence.** `CloturerSessionProcessor` refuse d'emblée une session déjà close
  (`ConflictHttpException`, RG-M2-06). `RouvrirCaisseProcessor` rouvre la **caisse** — le tiroir —
  pour une session NEUVE, jamais la session close. Aucun verrou supplémentaire ; en ajouter un
  masquerait celui qui existe.
- **La sortie.** `enregistrerVersement()` décrémente l'encaisse, émet le bordereau et génère son
  écriture scellée. Rien à y toucher.

## 5. ⚠ Le piège qui aurait cassé la caisse

`enregistrerEncaissement()` **lève une 422** sur un montant `<= 0`. Appelée sans garde depuis la Z,
**toute session sans espèces — ou dont le fond reporté égale les espèces comptées — aurait fait
échouer la clôture de caisse**. Une caisse qu'on ne peut plus fermer le soir est un incident
d'exploitation bien plus grave que le défaut corrigé.

L'appel est conditionné en amont. Ce n'est pas un cas d'erreur : une session sans espèces n'a rien à
remettre au régisseur.

## 6. Règle

À la clôture Z, après le scellement NF525 et après la fermeture de session, **avant le flush final** —
`enregistrerEncaissement()` flushe lui-même, et à cet endroit ce flush écrit tout d'un coup : clôture
Z, alerte d'écart, session fermée, solde de régie. **Une seule transaction**, ce qui est le défaut
correct pour de l'argent.

1. `session.pointDeVente.regie` — `null` → **rien**, sans erreur.
2. Montant = `espèces comptées − fond reporté`, tous deux déjà calculés par le processor.
   `<= 0` → **rien**.
3. `enregistrerEncaissement()`, puis une ligne d'audit `compta.regie_encaissement` : un solde de
   régie qui bouge sans trace est inacceptable pour de l'argent public.

**Le contenu scellé ne change pas.** `etatDeRegie` garde exactement sa forme : le sceau couvre la
caisse telle qu'elle a été comptée, le mouvement de régie en est une conséquence. Changer la charge
scellée changerait ce que valent les sceaux déjà posés.

## 7. Un guichet oublié se voit là où quelqu'un regarde

Une régie qu'aucun guichet n'alimente reste à zéro — l'état d'avant ce lot, mais avec une régie
déclarée qui **donne l'impression que tout est en place**.

On ne le détecte pas à la clôture Z : ce serait une requête à chaque Z de chaque guichet du produit,
pour une ligne d'audit que personne ne lit. La fiche de la régie, dans Paramètres, liste les guichets
qui l'alimentent et **dit en clair quand il n'y en a aucun** — là où quelqu'un regarde déjà la régie.

⚠ Cette fiche distingue « aucun guichet » de « je n'ai pas pu lire les guichets ». La première est
une accusation de mauvais paramétrage ; la porter sur une lecture ratée enverrait l'exploitant
corriger une configuration saine.

## 8. Cloisonnement : deux lignes, et la première n'est pas celle que j'attendais

J'avais écrit la contrainte `RevenueOfficeWithinTenant` en attendant qu'elle refuse un rattachement
croisé par un 422. **Mesuré : l'API rend un 400 « Item not found ».**
`AccountingScopeExtension` cloisonne les lectures de `RegieRecettes` par l'établissement actif : la
régie de B n'existe pas pour une requête portant l'en-tête de A, API Platform ne résout pas l'IRI, et
rien n'atteint le validateur.

La contrainte n'est donc **jamais atteinte par l'API**. Elle reste, pour les chemins qui ne passent
pas par l'extension — fixture, commande, futur processor sur mesure ; `Providers sur mesure et
cloisonnement` a déjà montré qu'une extension Doctrine ne protège pas ce qui ne passe pas par elle.

⚠ Mais elle est alors **du code que rien ne fait refuser**. Elle a donc son propre témoin, hors HTTP,
accompagné d'un **témoin négatif** : un validateur qui refuserait *tout* rattachement passerait le
test de refus et casserait le cas normal en silence.

## 9. Critères d'acceptation — `tests/Caisse/Api/RegieEncaisseClotureZTest.php`

| # | Cas | Attendu | État |
|---|---|---|---|
| CA-1 | Z, comptées > reportées, guichet rattaché | encaisse += (comptées − reportées) | vert |
| CA-2 | Z sans espèces | **clôture 200**, encaisse inchangée | vert |
| CA-3 | comptées == reportées | clôture 200, encaisse inchangée | vert |
| CA-5 | guichet sans régie (privé, DSP) | clôture 200, aucune erreur | vert |
| CA-7 | Z au-dessus du plafond | `ClotureGuard` refuse ensuite la clôture comptable | vert |
| CA-8 | seconde clôture de la même session | 409, encaisse **non** ré-incrémentée | vert |
| — | régie d'un autre établissement, par l'API | refusée (400, cloisonnement) | vert |
| — | contrainte hors API + témoin négatif | refuse le croisé, épargne le normal | vert |

*CA-4 (établissement sans profil) est le même chemin que CA-5 — le guichet n'a pas de régie — et
n'ajoute pas de témoin. CA-6 (deux régies) a disparu avec le rattachement au guichet : la question
ne se pose plus.*

⚠ **CA-7 est le seul qui prouve le lot.** Les autres prouvent qu'on n'a rien cassé ; seul CA-7
parcourt la chaîne entière — caisse → encaisse → plafond dépassé → clôture comptable refusée.

⚠ **Le témoin de CA-2 ne ré-épelle pas l'implémentation.** Il n'affirme pas « la garde existe », il
affirme que la clôture **répond 200** — le symptôme que l'exploitant subirait, pas le code qui
l'évite.

## 10. Ce qui a été vérifié, et par quel chemin

- **Le filet attrape.** Mécanisme neutralisé une minute : CA-1, CA-7 et CA-8 tombent — les trois qui
  affirment qu'un montant a bougé. CA-2, CA-3 et CA-5 restent verts, ce qui est correct : ils
  affirment que rien ne bouge.
- **Non-régression** : `tests/Caisse` + `tests/Compta` 144 verts, `tests/Vente` 112 verts.
- **Garde-fous** : 54 sur 54.
- **Migration** : le schéma rebâti par les 192 migrations ne laisse, sur `caisse_point_de_vente`, que
  deux écarts au mapping — un `COMMENT '(DC2Type:uuid)'` et un nom d'index. **Les deux sont du bruit
  partagé** : 6 des 7 `CHANGE ... BINARY(16)` portent sur des tables non touchées par ce lot, et les
  75 renommages d'index sont la dérive que `bin/verifier-derive-schema.sh` documente lui-même.

⚠ `bin/verifier-derive-schema.sh` **n'a pas pu conclure** : il lance `bin/console` sans monter
`docker/php/conf.d/zz-memory.ini` et meurt à 128 Mo. C'est un défaut du script, pas de la migration ;
la mesure ci-dessus a été refaite à la main avec `-d memory_limit=1G`.

## 11. Ce que ce lot ne fait pas, et l'assume

- **`modesAutorises` reste non appliqué.** Faire refuser un moyen de paiement hors acte de régie est
  un chantier côté caisse. L'écran de déclaration le dit déjà en toutes lettres.
- **Rien ne rattrape l'existant.** Les sessions déjà closes ne sont pas rejouées : leur encaisse est
  perdue pour la régie. Une reprise demanderait de savoir ce qui a été physiquement versé depuis —
  une information qui n'est pas dans le logiciel.
- **Le champ `versement` de la `ClotureZ` n'est pas touché**, et il peut désormais diverger du
  mouvement de régie. Ambiguïté préexistante : ce champ n'a de sens écrit nulle part.
