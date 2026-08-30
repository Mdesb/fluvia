# Les neuf typologies de produits — état mesuré avant le débrief

**Statut : relevé, aucune décision prise.** Maxime a donné la liste le 30/08 : « on va devoir
détailler chacun avec leurs options, leur fonctionnement etc ». Ce document dit ce que le code fait
*aujourd'hui*, pour que le débrief parte du réel et pas d'une intention.

Tout est mesuré sur la préproduction et sur `main` au 30/08. Rien n'est déduit d'un commentaire.

---

## Trois constats qui commandent tout le reste

### 1 ⚠ La liste de neuf n'est pas homogène : huit natures et une relation

« Produit complémentaire » n'est pas une nature de produit. Un cadenas *est* un produit boutique ;
« complémentaire » décrit son **rôle dans un lien**, pas ce qu'il est. Le même cadenas peut être
vendu seul au comptoir et proposé en complément d'une entrée.

C'est pourquoi il est modélisé en lien (`ComplementaryProduct`) et non en type — et le référentiel
le confirme à sa façon : les huit autres sont (ou seront) des `TypeProduit`, celui-là ne peut pas
l'être. **Les huit autres se répondent entre elles ; celle-ci les traverse toutes.**

### 2 ⚠ Le type de produit ne change rien à l'écran

La docstring de `Produit` affirme : *« Le type pilote les onglets/facettes visibles »* (RG-M1-02).

Mesuré : `facettes` n'apparaît **nulle part** dans `frontend/src`. Les sections de `ProduitFiche.jsx`
sont conditionnées par la vue (vitrine/config), par les droits et par la présence de données —
jamais par le type. Un produit boutique affiche les mêmes onglets qu'un abonnement.

La règle décrit une intention. Le code servi ne l'applique pas.

### 3 ✓ Le type pilotait une **destruction silencieuse** côté serveur — **corrigé le 30/08**

`ResolveurFacettes::purgerOrphelins()` est appelé par `ProduitProcessor` à **chaque**
enregistrement : si le type ne déclare pas la facette, `formule`, `carte` ou `stock` sont détachés.

Prouvé par `tests/Offre/Api/FacettePurgeSilencieuseTest.php` (deux tests, vérifiés en cassant la
purge : les deux virent au rouge, donc ils mesurent bien ce qu'ils prétendent) :

| ce qu'on fait | ce que le serveur répond | ce qui est enregistré |
|---|---|---|
| PATCH d'un stock sur une entrée unitaire | **200** | rien |
| PATCH de la **couleur de caisse** sur une entrée qui a déjà un stock | **200** | la couleur, et le stock **disparaît** |

Le second est le coûteux : **l'exploitant modifie une couleur et perd une jauge.** À l'écran, la
cause et l'effet n'ont aucun rapport. Et comme rien n'affiche les facettes, rien ne prévient.

Victimes en préproduction aujourd'hui — type `entree_unitaire`, facettes `["billet","consommateur"]`,
donc sans `stock` :

    PRD-PLACE01     « Place limitée (stock 1) »
    PRD-CADENAS01   « Cadenas vestiaire (rupture) »

~~Les deux perdront leur stock à la première modification.~~ **Plus depuis `D69`** : ils ne risquent
plus rien, mais leur donnée contredit toujours leur type et reste à statuer — le cadenas est
vraisemblablement un produit boutique, la place limitée un événement.

## ✓ Corrigé le 30/08, et **c'est la réponse de Maxime qui l'a rendu possible**

Le désaccord était de fond, pas technique : « produit simple » est défini *sans stock*, ce qui donne
raison aux facettes, mais « Place limitée » est un besoin réel. **La question n'était pas « faut-il
purger », c'était « où vit une jauge ».**

Maxime a tranché (`D68`) : **la capacité vit sur l'événement**, parce que plein tarif, réduit et
scolaire doivent décompter le même compteur. Un stock sur une entrée unitaire est donc une erreur de
modèle, pas un besoin à accueillir — et le refuser ne rend plus rien inexprimable.

`ProduitProcessor` refuse désormais en **422** (`D69`), avec un message qui nomme le type *et* la
destination. Et il **ne détruit jamais l'existant** : on compare à l'instantané Doctrine et on ne
refuse que ce qui vient d'être écrit. Un refus sans discernement aurait bloqué le produit — pire que
le défaut d'origine, qui ne détruisait qu'une donnée.

    stock écrit sur une entrée unitaire     → 422, et le message dit où le poser
    couleur modifiée sur un produit portant
    un stock hérité                          → 200, la couleur passe, le stock RESTE
    stock écrit sur un produit boutique      → 200, enregistré

`app/tests/Offre/Api/FacetteContradictoireTest.php` tient la règle. ⚠ Vérifié en remettant l'ancien
comportement une minute : les deux premiers virent au rouge, **le troisième reste vert** — c'est lui
qui prouve que les deux autres ne mesurent pas simplement « tout refuser ».

La purge subsiste pour le seul endroit où elle a du sens : la **conversion assistée de type**, où
l'exploitant a demandé le changement et où l'écran lui annonce ce qu'il perd.

---

## Le référentiel connaît quatre types sur neuf

| Maxime | `off_type_produit` | facettes déclarées | module porteur |
|---|---|---|---|
| produit simple | `entree_unitaire` | `billet`, `consommateur` | Offre |
| produit carte | `carte` | `carnet`, `consommateur` | Offre |
| produit boutique | `boutique_stock` | `stock`, `consommateur` | Stock, Boutique |
| produit renouvelable | `abonnement` | `formule`, `acces` | Subscription |
| carte cadeau / PMV | **absent** | — | Crm (partiel) |
| produit récurrent | **absent** | — | Reservation (partiel) |
| réservation créneau | **absent** | — | Reservation, SmartFlow |
| services | **absent** | — | ⚠ à définir |
| produit complémentaire | **sans objet** — c'est un lien | — | Offre (`ComplementaryProduct`) |

Facettes reconnues par le code : `stock`, `consommateur`, `visibilite`, `carnet`, `billet`,
`formule`, `acces`. Sept facettes, quatre types.

---

## Typologie par typologie

### 1. Produit simple — `entree_unitaire`

Maxime : « sans stock, génère un billet ». Le référentiel est d'accord : pas de facette `stock`.

⚠ **« Génère un billet » est vrai ; « le billet ouvre une porte » ne l'est pas.** La vente crée bien
un `Vente\Entity\BilletSupport` avec un code unique signé. Mais **la vente ne projette aucun droit
d'accès** : `ProjectionDroitInterface::projeter()` n'a qu'un seul appelant, `AppairageProcessor`,
c'est-à-dire l'écran d'appairage manuel. Cherché dans tout `app/src/Vente` : aucune occurrence.

La machinerie existe et est câblée sur une implémentation qui fonctionne — ce n'est pas un port
mort. Elle n'est simplement jamais déclenchée par la vente. Détail et mesure croisée dans
`specs/acces/SPEC-BILLET-QR.md`, en attente de ton paramétrage QR.

**Question :** une entrée à jauge (« 200 places ce dimanche ») est-elle un produit simple avec un
quota, ou un créneau ?

### 2. Produit carte — `carte`

`CarteMultiEntrees`, la facette `carnet`, `RechargeValidityMode` et son test. La projection d'accès
sait produire un droit `carte_quota` avec `creditRestant` = compostages restants, et
`CardExpiryCalculator` applique la validité **à la première projection seulement** — jamais à une
re-projection, pour ne pas réinitialiser la validité d'une carte déjà rechargée.

**Question :** la recharge est-elle une vente du **même** produit, ou un produit distinct ? Les deux
se défendent, et le choix décide de la ligne comptable et de la TVA.

### 3. Produit boutique — `boutique_stock`

La plus complète des neuf. Module `Stock` : valorisation, mouvements, inaltérabilité (les mouvements
ne se réécrivent pas), sortie automatique à la vente. Module `Boutique` : session client, cloisonnement
dédié, adaptateur de paiement.

⚠ **Mesuré : sur 4 produits `boutique_stock` en préprod, un seul porte un stock.** Trois articles de
marchandise sans stock du tout. Soit les données de démonstration sont incomplètes, soit le stock
n'est pas obligatoire sur ce type — et rien ne l'exige.

### 4. Produit renouvelable — `abonnement`

Module `Subscription` au complet : `SubscriptionFunnel`, `SubscriptionActivator`,
`SubscriptionInvoicer`, `ProvisioningService`, `ProvisionOnSubscriptionActivated`, et une commande
`FacturerAbonnementsCommand`.

⚠ **Et cette commande ne s'exécute jamais.** Aucune tâche planifiée ne tourne sur cette machine — ni
cron, ni timer systemd, ni conteneur worker (le déploiement l'affiche à chaque passage). **Aucun
abonnement ne se refacture tout seul.** La mécanique est écrite, le déclencheur n'existe pas.

**Question :** « renouvelable » et « abonnement » sont-ils le même objet ? Un carnet qui se
reconduit tacitement n'est pas un abonnement mensuel.

### 5. Carte cadeau / PMV — le trou est net

Le porte-monnaie virtuel existe côté CRM et il est **réellement câblé** (`PorteMonnaieVirtuelAdapter`,
pas le stub — vérifié dans `services.yaml`) : solde, mouvements, recharge, expiration, débit à la
vente, recrédit sur annulation.

⚠ **Mais le port n'a aucun crédit à la vente.** Ses trois verbes sont `solde`, `debiter`,
`recrediter` — et `recrediter` est le remboursement d'une vente annulée, pas l'achat d'un avoir.
**On peut payer avec un PMV ; on ne peut pas en vendre un.**

Et « carte cadeau » n'apparaît nulle part, ni dans `app/src` ni dans `frontend/src`.

**Question :** carte cadeau et PMV sont-ils la même chose chez toi ? Une carte cadeau se transmet à
un tiers, un PMV est attaché à un client — c'est la différence qui décide du modèle.

### 6. Produit récurrent — la matière existe, ailleurs

Rien dans le référentiel. Mais le module `Reservation` porte `Recurrence`, `MotifRecurrence`,
`RegleConflitRecurrence`, `ModifierOccurrenceProcessor`, `CreerCreneauProcessor`.

**Question :** l'aquagym de tous les lundis est-elle un **produit** récurrent, ou un **créneau**
récurrent qu'on vend ? Le client achète-t-il « le cours du lundi 14 h » ou « le trimestre » ?

### 7. Produit complémentaire — fait

Modèle et règle de vente posés (`e8e95a7`), trois modes (facultatif / suggéré / obligatoire), garde
avant transaction qui nomme le manquant. Écran à repointer, ancien champ `produitsAssocies` à
retirer ensuite. Détail dans `SPEC-PRODUIT-COMPLEMENTAIRE.md`.

### 8. Réservation créneau — le module le plus fourni, hors du référentiel

Treize entités : `Activite`, `Creneau`, `Ressource`, `DisponibiliteRessource`,
`IndisponibiliteRessource`, `ListeAttente`, `Emargement`, `FacturationNoShow`, `RegleAnnulation`,
`ParticipantReservation`, `ProjectionAccesReservation`, `Recurrence`, `Reservation`. Plus `SmartFlow`
pour la liste d'attente, la libération de créneau et la replanification.

**Question :** un créneau se vend-il **comme un produit** (avec sa grille tarifaire, sa TVA, sa
catégorie comptable), ou la réservation est-elle un objet à part que la vente référence ?

### 9. Services — le mot existe, l'objet n'est pas celui-là

`Offre\Entity\ServiceInclus` existe, mais c'est autre chose : un service **inclus dans une formule**,
à quota décompté en semaine calendaire (lundi→dimanche, sans report). Il n'est pas vendable seul.

**Question :** par « services », entends-tu une prestation vendue à l'unité (un massage, un coaching,
une location d'une heure) ? Si oui, rien n'existe et c'est la neuvième à construire de zéro.

---

## Ce que Maxime a tranché le 30/08 — treize décisions

Toutes au journal `COORDINATION/DECISIONS.md`, avec leur raison et leur coût de mise en œuvre.

| | |
|---|---|
| `D68` | La capacité vit sur l'événement, jamais sur le produit |
| `D69` | Une écriture qui contredit le type est refusée ; ce qui existe n'est jamais détruit |
| `D70` | Carte cadeau et porte-monnaie sont deux objets distincts |
| `D71` | Le créneau est un type de produit, et l'agenda échange dans les deux sens |
| `D72` | Événement et créneau restent deux objets, mais partagent le mécanisme de capacité |
| `D73` | Un service se vend à l'unité et s'inclut dans une formule ; son lien au planning est optionnel |
| `D74` | Une carte désigne ce que son crédit ouvre — une zone d'accès, ou une activité à réserver |
| `D75` | Une carte de réservation décompte à la réservation, et rend l'entrée si l'annulation est à temps |
| `D76` | Séance à l'unité et forfait coexistent, et décomptent la même capacité |
| `D77` | L'inscription d'office au forfait est un paramètre de l'activité |
| `D78` | Une carte cadeau s'émet en code ou en support physique selon le canal, un seul solde derrière |
| `D79` | Un service est le même objet, inclus ou vendu |
| `D80` | **En attente** : la recharge d'une carte — le débrief cartes de Maxime |

## Les points de vigilance qui découlent de ces choix

Chacun est la contrepartie d'une décision, énoncée **avant** le choix et retenue avec lui.

**Un compteur, pas deux** (`D68`, `D72`, `D76`). Trois décisions imposent la même contrainte sous
trois formes : plusieurs tarifs sur un événement, événement et créneau séparés, forfait et vente à
l'unité qui coexistent. Dans les trois cas, le décompte de places s'écrit **une seule fois**. Deux
compteurs mettraient plus de monde dans le bassin qu'il n'en contient, et ça ne se voit qu'au bord de
l'eau.

⚠ **Une carte aquagym ouvrirait un tourniquet** (`D74`, à corriger). `StubProjectionDroit` produit un
droit `carte_quota` **sans distinguer ce que la carte ouvre**. Le paramètre à ajouter à la carte doit
être **lu par la projection**, pas seulement stocké — sinon une carte de réservation projetée telle
quelle laisserait entrer directement.

**L'avoir est l'objet, pas le code** (`D78`). Deux chemins d'émission pour un seul solde : le code et
la carte sont des *supports*. Un modèle où le code serait l'avoir rendrait impossible de le remplacer
après une perte.

**Deux écrans écrivent sur la même séance** (`D71`). L'agenda dans les deux sens suppose une seule
source de vérité, avec deux écrans qui écrivent dedans — jamais deux modèles qui se synchronisent.

## Ce qui reste ouvert

**Une seule question de produit :** la **recharge d'une carte** (`D80`), que Maxime prefere trancher
avec son debrief cartes. Rien de ce qui se construit ne prejuge de la reponse -- la recharge sera
soit un tarif de plus dans la grille du meme produit, soit un produit distinct, et les deux se posent
sur le modele de carte sans le modifier. C'est le cas ou attendre ne coute rien, signale comme tel
pour qu'on ne le confonde pas avec un blocage.

**Deux produits de preprod a statuer :** `PRD-PLACE01` et `PRD-CADENAS01` portent un stock que leur
type ne declare pas. Ils ne risquent plus rien depuis `D69`, mais leur donnee contredit toujours leur
type -- le cadenas est vraisemblablement un produit boutique, la place limitee un evenement. Ce n'est
pas un nettoyage a faire d'autorite : c'est le premier cas concret ou appliquer `D68`.

**Un constat de mesure encore ouvert :** le type de produit ne change rien a l'ecran (constat 2). Les
facettes ne sont lues nulle part dans le front. Ce n'est pas urgent tant que le serveur refuse les
saisies contradictoires -- mais tant que ce sera vrai, l'exploitant verra des onglets qui ne le
concernent pas, et decouvrira au 422 que ce champ n'etait pas pour lui.

**Et deux faits qui n'attendent qu'une priorite :** aucune tache planifiee ne tourne (donc aucune
facturation d'abonnement ne part), et la vente ne projette aucun droit d'acces (donc un billet vendu
n'ouvre rien -- la projection existe, elle n'est jamais appelee par la vente).
