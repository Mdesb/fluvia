# Besoins métier (dictés par le client — 8 ans d'expérience terrain)

> Recueil des attentes réelles du terrain, verticale par verticale. Source : le client (expert métier). Alimente directement le cahier des charges.
> Statut : en cours de recueil. Chaque point est marqué **[SOCLE]** (réutilisable) ou **[VERTICAL]** (spécifique métier).
> Date : 2026-08-13.

---

## 1. PISCINE / CENTRE AQUATIQUE

### 1.1 Ce que vend une piscine (catalogue type)

| Produit | Détail | Nature |
|---------|--------|--------|
| **Abonnements mensuels (prélèvement SEPA)** | À **niveaux / formules** avec droits inclus différents (voir 1.2) | [SOCLE] moteur abo + [VERTICAL] droits d'accès |
| **Entrées unitaires** | Plusieurs tarifs (adulte, réduit, enfant, social…) | [SOCLE] tarification |
| **Cartes multi-entrées (« carte de 10 »)** | Avec **opérations commerciales** : selon promo, **10 payées = 12 données**, etc. | [SOCLE] carnet + promo |
| **Cours d'aquagym à l'unité** | « Aquagym » = famille large : **aquabike, aquazumba…** (nombreuses variantes). **Liés à un planning** (séance à date/heure, capacité) | [VERTICAL/SOCLE] séances planifiées |

### 1.2 🔑 Abonnements à NIVEAUX / FORMULES (concept central)
Un abonnement n'est pas qu'un droit d'accès : c'est un **bundle = droit d'accès + services inclus (quotas)**.
- **Ex. Abonnement « Gold »** = **accès illimité** à la piscine **+** *N* réservations/semaine de cours d'aquagym incluses.
- **Ex. Abonnement « Classique »** = **accès piscine seul** (pas de cours inclus).

→ Implication modèle : une **formule d'abonnement** porte :
1. un **type d'accès** (illimité / quota de passages / horaires) ,
2. des **droits sur les activités/cours** (nombre de réservations incluses par période — ex. « 1 aquagym/semaine »),
3. un **prix récurrent** (mensuel, SEPA),
4. des **règles** (engagement, renouvellement, pause).
> C'est **plus riche** que ce que fait l'ancien AWOO (qui gère l'abo + le renouvellement, mais la notion de « quotas d'activités inclus par formule » est à concevoir). Proche du modèle **fitness** (paliers d'adhésion) → à mutualiser dans le socle.

### 1.3 🔑 Contrôle du nombre de passages (accès)
- **Comptage des passages essentiel** → **le scan au tripode est central** (chaque entrée = un passage décompté).
- **⚠️ Cas critique — les personnes sans support scannable** : un **bébé de 3 ans** ne scanne pas de QR code. Il faut donc gérer :
  - les **accompagnants / jeunes enfants** qui entrent **sans billet scanné** (gratuité non nominative, passage groupé, comptage manuel au tripode ?),
  - tout en gardant un **comptage de fréquentation juste** (FMI) incluant ces entrées non scannées.
  → Implication : le contrôle d'accès ne peut pas supposer « 1 personne = 1 QR ». Il faut un **mode « passage sans scan »** (bouton agent / comptage) et une distinction **entrées nominatives (scannées) vs entrées comptées**.

### 1.4 🔑 Reporting & analyse multi-niveaux
- **Comptabilité globale** (chiffre consolidé).
- **Plusieurs niveaux d'analyse** via une **arborescence** : les produits sont **répartis en catégories** (arbre) qui permet des analyses croisées.
- Deux axes d'analyse distincts au moins :
  - **Analyses marketing** (par catégorie commerciale de produit),
  - **Analyses comptables** (par catégorie comptable).
  → Implication : un produit doit pouvoir être **classé sur plusieurs axes analytiques** (marketing ≠ comptable), pas une seule catégorie. (L'AWOO a « rayonnage caisse » + « support » — base à généraliser en **plan analytique multi-axes**.)

### 1.5 🔑 Comptabilité — Produits Constatés d'Avance (PCA)
- Gestion des **PCA (produits constatés d'avance)** : quand on vend un abonnement mensuel ou une carte 10 entrées, **le chiffre d'affaires ne se reconnaît pas d'un coup à la vente** mais **s'étale sur la période / au fil de la consommation**.
- Besoin : **anticiper les PCA** avec **génération automatique des fichiers** et **calcul automatique** (répartition du CA sur les périodes / à la consommation des entrées).
  → Implication : moteur de **reconnaissance de revenu (revenue recognition)** — étaler le produit d'un abonnement sur sa durée, décompter une carte 10 au fur et à mesure des passages, produire les écritures/fichiers de PCA automatiquement. Fonction **comptable avancée** (rare chez les SaaS concurrents) → **différenciateur potentiel** côté régie/compta. (L'AWOO a « début d'activation » à la création/au 1er paiement — c'est un embryon ; le PCA va plus loin.)

### Questions ouvertes (piscine) — à clarifier avec le client
- [ ] **Cours inclus dans l'abonnement** : quota par **semaine** glissante ou semaine calendaire ? report des non-utilisés ? sur *toutes* les activités ou une liste précise ?
- [ ] **Bébé 3 ans / accompagnants** : passage **gratuit nominatif** (billet 0€ scanné) ou **simple comptage** non nominatif au tripode ? Y a-t-il un **âge/quota** (ex. gratuit -4 ans, 1 accompagnant/bébé) ?
- [ ] **Carte 10 = 12** : le bonus est-il un **stock de passages** (12 compostages) ou un **avoir/€** ? valable combien de temps ?
- [ ] **PCA** : reconnaissance du revenu **au prorata temporel** (abo mensuel) **et/ou à la consommation** (carte 10) ? Format de fichier attendu (export vers quel logiciel compta / trésor public) ?
- [ ] **Arborescence analytique** : combien d'axes (marketing, comptable, autre) ? profondeur ? un produit sur 1 valeur par axe ou plusieurs ?

---

## 2. PATINOIRE _(à recueillir)_
## 3. SALLE DE SPORT _(à recueillir)_
## 4. MUSÉE _(à recueillir)_
