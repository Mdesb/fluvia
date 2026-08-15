# Spec — Comptabilité & Régie (`M6` / lot `L4`)

- **Lot / module :** L4 · M6 Compta & Régie (module **bi-régime**)
- **Stories couvertes :** US-L4-01 à US-L4-10 (backlog L4, scope actuel = **profil régie directe**)
- **Règles de gestion :** RG-M6-01 à RG-M6-10 (cahier, panel `p-m6`) ; alias backlog par story :
  RG-COMPTA-01 (US-L4-01), RG-REGIE-02 (US-L4-02), RG-PAYFIP-03 (US-L4-03), RG-COMPTA-04 (US-L4-04),
  RG-PCA-05 (US-L4-05), RG-TVA-06 (US-L4-06), RG-EXPORT-07 (US-L4-07), RG-EREPORT-08 (US-L4-08),
  RG-NF525-09 (US-L4-09), RG-CLOTURE-10 (US-L4-10)
- **Statut :** brouillon — **plusieurs points ⚠ À VALIDER PAR EXPERT (comptable public / fiscaliste) avant figement**

## 1. Objectif
Produire une comptabilité **juste et conforme au régime de l'exploitant** — régie directe (comptabilité
publique M57/M4), délégataire/DSP ou groupe privé (comptabilité privée PCG) — à partir d'un **socle
fonctionnel unique** : un seul moteur d'écritures, de PCA (produits constatés d'avance, compte 487) et
de TVA multi-taux, dont les **sorties** (plan de comptes, régie de recettes, exports légaux, reporting
tiers) sont **commutées automatiquement** par le **profil d'exploitant**, sans ressaisie ni duplication
de paramétrage.

## 2. Périmètre
- **Inclus :**
  - Profil exploitant (régie directe / DSP / groupe privé) et plan de comptes de rattachement
    (produits, TVA) commutant tout le module — US-L4-01, RG-M6-01.
  - Journal & écritures générées automatiquement par les ventes/encaissements de M2, lettrage,
    contrôle, piste d'audit — US-L4-04, RG-M6-04.
  - Assistant PCA (compte 487) : étalement prorata temporis (abonnement) ou reconnaissance à la
    consommation (carte, via le module Accès) — US-L4-05, RG-M6-02/03.
  - États de TVA multi-taux et ventilation des paniers mixtes ligne à ligne — US-L4-06, RG-M6-04/05.
  - Exports comptables **commutés** par profil : PES V2/Hélios + états de régie (public) ; FEC légal,
    CIEL, EBP, Sage, Cegid (privé) — US-L4-07, RG-M6-06 (écran M6-05).
  - Régie de recettes (modes autorisés, plafond d'encaisse, versement, justificatifs) et articulation
    PayFiP (encaissement en ligne DGFiP) — US-L4-02/03, RG-M6-10, RG-REGIE-02, RG-PAYFIP-03.
  - e-reporting agrégé au SIREN (jour × taux TVA) et Chorus Pro pour les factures B2G, marquage
    « ImpayeRegie » anti-double-comptabilisation — US-L4-08, RG-M6-07/08/09.
  - Certification NF525 (préparation) : inaltérabilité, chaînage, archivage des données de recette —
    US-L4-09, RG-M6-06.
  - Clôture de période : contrôles pré-clôture, gel des écritures, état récapitulatif — US-L4-10,
    RG-CLOTURE-10.
  - Reporting délégant (RAD) et calcul des redevances DSP (compte d'exploitation, CA/fréquentation
    ventilés, formule contractuelle) — décrit par le cahier (module M6, écran M6-08) pour le profil DSP.
  - Référentiel des **moyens de paiement** (défini ici, **consommé** par M2 qui le filtre selon l'acte
    de régie — RG-M2-02 dans `spec-vente.md`).
- **Exclu (pour l'instant), que M6 *référence* seulement :**
  - La vente et l'encaissement au guichet/en ligne (montants, panier, moyens réglés) → **M2** (L2,
    `spec-vente.md`) ; M6 **reçoit** les écritures qu'elle génère, ne redéfinit pas le processus de vente.
  - Le comptage/contrôle d'accès et la jauge FMI (sécurité/présence simultanée) → module **Accès**
    (L3, `spec-acces.md`) ; M6 **consomme** les passages comme fait générateur de la reprise PCA « à la
    consommation » mais ne redéfinit ni la FMI ni le cumul de fréquentation brut.
  - Le référentiel produits, la catégorie comptable obligatoire, la règle PCA portée par le produit
    → **M1** (L1, `spec-offre.md`, RG-M1-05/08) ; M6 **exécute** la règle que M1 porte.
  - Le reporting de pilotage / tableaux de bord décisionnels multi-niveaux (site → région → direction
    générale), y compris la consolidation d'agrégats de profils mixtes → **M7** ; M6 **fournit la
    donnée source** (écritures, CA, TVA, RAD) mais ne construit pas les tableaux de bord.
  - La paie et la comptabilité fournisseurs générale.
  - La télédéclaration fiscale directe (dépôt EDI-TVA) : M6 produit la **base** de déclaration, pas le
    dépôt lui-même.
  - L'UI (front) ; l'authentification, les rôles/permissions et le journal d'audit → **socle L0**
    (`spec-socle.md`), réutilisés et non redéfinis ici.

## 3. Acteurs & droits
Les permissions réutilisent le modèle du socle (`RG-SOCLE-02/03/04/05`) : couple `module × action` sur
le module **`compta`**, portées par l'**établissement actif** ; l'UI **masque** ce qui n'est pas
autorisé (`RG-SOCLE-04`). Source : cahier `p-m6` §2 (Acteurs & droits).

| Acteur | Peut | Ne peut pas | Permission (module × action) |
|---|---|---|---|
| **Comptable / Régisseur** | Consulter le journal, lettrer, valider les écritures, générer les états de TVA et les exports, gérer la régie (encaisse, versement, justificatifs), clôturer une période (Comptable seul, RG-CLOTURE-10) | Modifier le profil exploitant ou le plan de comptes ; rouvrir un exercice clôturé ; supprimer une écriture validée | `compta × lire`, `compta × lettrer`, `compta × valider`, `compta × exporter`, `compta × cloturer`, `caisse × versement` |
| **Administrateur** | Configurer le profil exploitant, le plan de comptes, les comptes de rattachement et taux TVA, planifier les exports | Encaisser en régie sans acte de nomination ; altérer une écriture déjà transmise (PES/FEC) | `compta × gerer` (surensemble), `securite × gerer` (socle, délégation) |
| **Autorité délégante (DSP)** | Recevoir le RAD, consulter le compte d'exploitation, le CA/fréquentation et le détail des redevances | Accéder au journal détaillé ni aux écritures ; modifier une donnée comptable | `compta × lire_rad` |
| **Comptable public DGFiP** | Recevoir les flux PES V2/Hélios et les états de régie ; réconcilier les recettes (hors périmètre applicatif : destinataire externe) | Intervenir dans le paramétrage ni l'exploitation applicative | — (destinataire externe des exports, sans compte applicatif) |
| **Direction régionale / Direction générale** | Consulter les exports et états consolidés de leur périmètre (région, groupe) sans le détail des écritures | Modifier une écriture ; accéder au journal détaillé d'un établissement hors périmètre | `compta × lire_consolide` ⚠ HYPOTHÈSE |

- ⚠ HYPOTHÈSE — La remontée à 3 niveaux (site → région → direction générale) est mentionnée pour le
  pilotage (M7) ; le cahier M6 ne détaille pas de permission dédiée à la consultation consolidée en
  compta. `compta × lire_consolide` est une **proposition** à arbitrer avec M7/M8 : distincte du
  journal détaillé (réservé Comptable/Administrateur de l'établissement), elle ne donne accès qu'aux
  **agrégats déjà exportés/validés** (RG-M6-01, périmètre consolidation §4.11).
- ⚠ HYPOTHÈSE — Noms des permissions `compta × …` : dérivés du tableau Acteurs & droits du cahier
  M6-§2 selon le modèle `module × action` du socle, non nommés littéralement dans les sources ; à
  figer avec **M8** (cf. même hypothèse en L1/L2).

## 4. Comportements & règles
Chaque comportement trace une **RG-M6** (source : `cahier-detaille.html`, panel `p-m6`) et/ou une
**US-L4** (source : `backlog.html`, panel `p-l4`). Les points relevant d'un arbitrage
comptable-public/fiscal sont marqués `⚠ À VALIDER PAR EXPERT` (décision actée : « M6 = confirmation
expert » — cf. onglet ★ Décisions).

### 4.1 Profil exploitant — commutation unique (US-L4-01, RG-M6-01)
- **RG-M6-01** — Le **profil d'exploitant** (Régie directe / DSP / Groupe privé) **commute** le plan
  de comptes, le mode régie, les formats d'export et le reporting tiers ; **aucun de ces éléments
  n'est saisi indépendamment du profil**. Changer de profil **recharge automatiquement** le plan de
  comptes et les formats disponibles, sans ressaisie.
- **RG-COMPTA-01 (US-L4-01)** — Le **référentiel comptable** attaché au profil est **M57** ou **M4
  SPIC** en régie directe, **PCG** en DSP/groupe privé. Il est **sélectionné au niveau de l'exploitant**
  et **verrouillé après la première clôture** (RG-CLOTURE-10). La **qualification SPIC/SPA** est
  **paramétrable par équipement** (par Espace du socle) et conditionne M4 vs M57 pour les écritures
  qui s'y rattachent (décision actée « paramétrable par équipement ; classement à valider avec le
  comptable public »).
- **Mapping obligatoire** — Chaque famille de produits (axe comptable de la Catégorie, M1 RG-M1-05)
  est **mappée** à un compte de produit du référentiel actif et à un taux de TVA. **Un mapping
  incomplet bloque la génération d'écritures** pour les ventes concernées (US-L4-01).
- **Piste d'audit** — Toute modification du plan de comptes est **horodatée et tracée** (réutilise
  `RG-SOCLE-07`).
- ⚠ À VALIDER PAR EXPERT — **Qualification SPIC vs SPA** : le classement de chaque équipement en SPIC
  (M4) ou SPA (M57) doit être confirmé équipement par équipement par le **comptable public** avant
  activation en production (décision actée, cahier §8).

### 4.2 Journal & écritures (US-L4-04, RG-M6-04, écran M6-02)
- **RG-M6-04** — Chaque **ligne d'écriture** porte **son propre taux de TVA et ses axes analytiques**
  (site / activité / financeur) ; **aucune ligne ne peut être enregistrée sans taux ni rattachement**.
- **RG-COMPTA-04 (US-L4-04)** — **Toute vente validée** (M2) produit **automatiquement** une
  **écriture équilibrée** (débit encaissement / crédit produit + TVA), horodatée. Les écritures sont
  **regroupées par journal** (ventes, encaissements, régie, PCA/OD, extourne) et par période.
- **Inaltérabilité** — **Aucune écriture n'est modifiable après génération** ; toute correction passe
  **exclusivement** par une **écriture d'extourne** tracée (contre-passation, cohérent avec RG-M2-07
  côté vente).
- **Lettrage & contrôle** — Lettrage des encaissements (rapprochement recette / mode de paiement /
  versement) ; contrôle détectant écritures déséquilibrées, non lettrées ou hors période.
- **Piste d'audit NF525** — Toute écriture est **traçable jusqu'à la pièce de vente d'origine** (ticket
  M2, chaînage NF525).
- **Consultation & export** — Filtrables par date, journal et compte.

### 4.3 Assistant PCA — compte 487 (US-L4-05, RG-M6-02/03, écran M6-03)
- **RG-M6-02** — Toute vente **encaissée d'avance** **crédite le compte 487** ; le **revenu n'est
  reconnu qu'au fur et à mesure de la prestation** (jamais à l'encaissement).
- **RG-M6-03** — La reconnaissance se fait **au prorata temporis** pour un **abonnement**, et **au
  passage** pour une **carte multi-entrées**, via les passages remontés par le **module Accès** (L3).
- **Nature du produit / méthode de reconnaissance** — Chaque produit porte une nature (« à étaler » /
  « à la consommation ») et une méthode héritées de la règle PCA portée par M1 (RG-M1-08) : prorata
  temporis (abonnement, dotation/reprise mensuelle sur la période de service) ou au passage (carte,
  décompte des entrées consommées).
- **Rapprochement** — Le **solde du 487** est **rapprochable à tout instant** avec le **reste-à-servir /
  reste-à-consommer** (US-L4-05).
- ⚠ À VALIDER PAR EXPERT (décision actée : « implémenter, activable selon avis ») — **PCA en
  comptabilité publique** : l'admissibilité du mécanisme du compte 487 en M57/M4 et son schéma
  d'écritures exact doivent être confirmés par le **comptable public** avant activation en régie
  directe ; le module l'implémente et l'active de façon **paramétrable**.
- ⚠ HYPOTHÈSE — La **méthode de reconnaissance** (prorata vs consommation) est décrite comme
  « paramétrable par type de produit » (US-L4-05) sans grille de correspondance exhaustive
  type-de-produit ↔ méthode ; à confirmer avec le métier au-delà des deux cas cités (abonnement, carte).
- ⚠ HYPOTHÈSE (héritée de L1 §7) — Le traitement du **solde de 487 résiduel à l'expiration** d'une
  carte/d'un abonnement non entièrement consommé n'est pas spécifié (perte reconnue en produit ?
  reprise en charge ? ) — à arbitrer.

### 4.4 TVA multi-taux & paniers mixtes (US-L4-06, RG-M6-04/05, écran M6-04)
- **RG-M6-05** — Les **paniers mixtes** ventilent la TVA **ligne à ligne par taux** ; **aucun taux
  moyen n'est appliqué** à la vente globale.
- **RG-TVA-06 (US-L4-06)** — Un **taux de TVA est affecté par article** (famille de produit, via le
  mapping §4.1) : 20 % (piscine/sport), 10 % (culturel/musée), autres taux applicables dont un
  éventuel **taux réduit paramétrable**.
- **États de TVA** — Base HT, TVA collectée et TTC **par taux et par période** ; rapprochement avec le
  journal (§4.2) et le CA. **Cohérence contrôlée** : total TTC panier = somme des lignes ventilées ;
  **aucun montant** n'est rattaché à un taux « indéterminé ».
- ⚠ À VALIDER PAR EXPERT (décision actée : « moteur paramétrable ; appliquer après avis fiscaliste ») —
  **Taux réduit TVA 2025** : l'application d'un taux réduit aux cours et accès sportifs doit être
  **confirmée par un fiscaliste** avant paramétrage des familles concernées.

### 4.5 Régie de recettes & PayFiP (US-L4-02/03, RG-M6-10, RG-REGIE-02, RG-PAYFIP-03, écran M6-06)
- **RG-M6-10** — Un encaissement en régie **n'accepte que les modes de paiement prévus par l'acte de
  régie** et **respecte le plafond d'encaisse**.
- **RG-REGIE-02 (US-L4-02)** — La régie est **paramétrée** (modes de paiement autorisés — numéraire,
  CB, chèque, PayFiP —, plafond d'encaisse déclenchant une alerte, périodicité de versement,
  justificatifs) conformément à l'**acte de nomination**/arrêté de création de régie.
  - Un **mode non autorisé** est **refusé à l'encaissement en caisse** (filtre appliqué côté M2,
    `RG-M2-02` de `spec-vente.md` — M6 en est le **référentiel source**).
  - Le **dépassement du plafond** déclenche une **alerte bloquante** tant qu'aucun versement n'est
    enregistré.
  - Suivi du **fonds de caisse** et du **montant à verser** au comptable public ; **génération d'un
    bordereau de versement** daté avec **pièces justificatives** rattachées.
- **RG-PAYFIP-03 (US-L4-03)** — L'encaissement en ligne s'effectue via **PayFiP** (solution DGFiP) :
  - **Redirection** avec **référence de transaction** stockée sur la vente.
  - **Retour de paiement** (OK / échec / annulé) traité et **statut de la vente mis à jour**.
  - **Rapprochement automatique** des encaissements PayFiP avec les ventes correspondantes.
  - **Journalisation** des transactions pour contrôle et **rejeu** en cas de retour manquant.
- ⚠ HYPOTHÈSE — Le **protocole technique exact** d'échange avec PayFiP (API, formats de retour,
  délai de rejeu, gestion des retours dupliqués) n'est pas détaillé dans les sources ; à préciser au
  plan technique.

### 4.6 Exports comptables commutés (US-L4-07, RG-M6-06 [périmètre exports], écran M6-05)
- **RG-EXPORT-07 (US-L4-07)** — Le **format d'export** proposé est **commuté par le profil
  exploitant** (§4.1) :
  - **Public** (régie) : flux **PES V2 / Hélios** (transmission au Trésor) + **états de régie**
    (encaissements par mode, versements, restes).
  - **Privé** (DSP/groupe) : **FEC** (format légal, 18 champs) + formats éditeurs **CIEL, EBP, Sage,
    Cegid**.
  - Export **borné à l'exercice**, portant **uniquement les écritures validées**.
  - **Planification** possible (envoi automatique récurrent) vers un **destinataire** (comptable
    public, délégant, expert-comptable).
  - Un export **en échec de contrôle** (déséquilibre, écritures non validées) est **bloqué**, avec la
    **liste des anomalies**.
  - Changer de profil **masque les formats non pertinents**.
- ⚠ À VALIDER PAR EXPERT — **Articulation titres de recettes / PES V2** : en comptabilité publique, la
  recette encaissée en régie appelle en principe une régularisation par **titre de recette** émis par
  l'ordonnateur avant recouvrement par le comptable public. Le mode d'articulation exact entre les
  écritures générées par M6, le flux **PES V2** et l'émission de **titres de recette** (régularisation
  a posteriori des encaissements de régie) **n'est pas tranché** dans les sources et doit être validé
  avec le **comptable public**. Hypothèse proposée : un champ **paramétrable**
  « génère un titre de régularisation » sur l'export PES, activable une fois l'arbitrage rendu.

### 4.7 e-reporting & Chorus Pro (US-L4-08, RG-M6-07/08/09, écran M6-07)
- **RG-M6-08** — La billetterie **B2C** alimente un **e-reporting agrégé jour × taux de TVA**, **sans
  émission de facture B2B** individuelle.
- **RG-M6-07** — **En régie**, il n'existe qu'**un seul flux e-reporting agrégé au SIREN** de la
  collectivité (pas de flux par établissement/espace).
- **RG-M6-09** — Une recette **de régie** est **marquée « ImpayeRegie »** dans le flux e-reporting pour
  **éviter toute double comptabilisation** (la recette est déjà transmise via PES/régie, donc exclue ou
  signalée dans l'agrégat e-reporting).
- **RG-EREPORT-08 (US-L4-08)** — L'agrégation porte sur les **transactions B2C par période**, au
  niveau du **SIREN de l'exploitant**, **ventilée par taux de TVA**. L'**état d'envoi** est **tracé**
  (préparé / transmis / rejeté).
- **Chorus Pro (B2G)** — Les **factures B2G** (écoles, collectivités) sont émises **via Chorus Pro**,
  en parallèle du flux e-reporting agrégé B2C (pas de double émission pour une même recette).
- ⚠ HYPOTHÈSE — Le canal technique précis de dépôt de l'agrégat e-reporting (**PDP** — Plateforme de
  Dématérialisation Partenaire — dans le cadre de la réforme de la facturation électronique) n'est pas
  nommé dans le cahier ; à préciser (choix de PDP, format d'échange) au plan technique, en cohérence
  avec le calendrier réglementaire en vigueur au moment de l'implémentation.

### 4.8 Reporting délégant (RAD) & redevances DSP (écran M6-08, profil DSP)
- Pour le profil **DSP**, le module produit le **RAD** (rapport annuel du délégataire) : **compte
  d'exploitation** de l'équipement délégué, **CA et fréquentation ventilés** par activité/période/site,
  et le **calcul automatique des reversements/redevances** selon la **formule du contrat de DSP**.
- **Export dédié au délégant** : le RAD **n'expose pas le journal comptable détaillé** (cf. §3, droits
  Autorité délégante).
- **Traçabilité** — Le montant de redevance calculé est **reproductible**, traçable jusqu'aux CA et
  fréquentation qui l'alimentent.
- **Distinction FMI / fréquentation** — La fréquentation ventilée du RAD est le **cumul de passages**
  (module Accès), **jamais la jauge FMI** (présence simultanée, sécurité incendie) : les deux notions
  ne sont **pas interchangeables** (cf. `spec-acces.md`, RG-ACC-04, « FMI ≠ cumul »). Le RAD **agrège
  le cumul**, pas un instantané de jauge.
- ⚠ HYPOTHÈSE — **Aucune US-L4 du backlog actuel ne couvre explicitement le RAD/redevances** : la
  section « Lot L4 · M6 Compta & Régie » du backlog est **scopée « profil régie » (10 US, 63 pts)** ;
  le RAD/redevances (profil DSP) est décrit au niveau du **module M6** dans le cahier mais **ne
  possède pas de story dédiée** à ce jour. Le comportement ci-dessus est donc **spécifié à partir du
  cahier seul** (écran M6-08, objets `RAD`/`Redevance`, §7 « critères d'acceptation module ») et
  **devra être formalisé en story(ies) backlog** avant développement (ex. « US-L4-11 RAD & redevances
  DSP »). Idem pour le profil **groupe privé** (consolidation, §4.11).

### 4.9 Certification NF525 (US-L4-09, RG-M6-06, écran Sécurisation caisse)
- **RG-M6-06** — Le logiciel doit être **certifié NF525 (ISCA)** : inaltérabilité, sécurisation,
  conservation et archivage des données de recette. Décision actée : viser la **certification NF525
  complète** (couvre tous les cas, y compris les régies).
- **Chaînage inaltérable** — Les transactions sont chaînées (signature/empreinte) ; toute **rupture**
  est **détectable**. Réutilise et **prolonge** le chaînage défini côté caisse (`spec-vente.md`
  §4.8, objet ChaînageNF525) au niveau des **écritures comptables** elles-mêmes.
- **Journalisation** — Les événements (ouverture/clôture caisse, corrections) sont **horodatés et non
  modifiables** (append-only, réutilise `RG-SOCLE-07`).
- **Archivage** — Archivage périodique avec **restitution sur demande de contrôle**.
- **Préparation certification** — Un **jeu d'archives de test** est généré et **vérifiable**.
- ⚠ À VALIDER PAR EXPERT (déjà signalé en L2 §4.8/§222) — **Procédé cryptographique** (algorithme de
  signature, forme du chaînage/hash), **périmètre de certification** (auto-attestation éditeur vs
  certificat LNE/INFOCERT) et **conservation légale** (durée, format d'export fiscal, archivage
  probant) ne sont pas fixés dans les sources ; M6 en est le **propriétaire fonctionnel** (au-delà de
  M2 qui l'amorce côté caisse) et doit trancher ces points avec un **référent conformité NF525** avant
  implémentation.
- ⚠ À VALIDER PAR EXPERT — **Périmètre NF525 pour les régies** : l'étendue exacte de l'obligation
  NF525 sur le champ des régies de recettes (vs uniquement la caisse privée) reste à préciser avec le
  **conseil juridique**, malgré la décision de viser la certification complète.

### 4.10 Clôture de période (US-L4-10, RG-CLOTURE-10)
- **RG-CLOTURE-10 (US-L4-10)** — La **clôture** :
  - N'aboutit qu'après **contrôles pré-clôture bloquants** : journal équilibré, versements de régie
    soldés, PCA à jour.
  - **Fige** définitivement les écritures de la période : **aucune écriture antérieure n'est
    modifiable** après clôture ; les corrections ne sont possibles **que sur une période ouverte**
    (via extourne, §4.2).
  - Produit un **état de clôture récapitulatif** (produits, TVA, encaissements, PCA).
  - Est **réservée au rôle Comptable** et **tracée dans la piste d'audit**.
- **Cycle de vie exercice** — `Exercice ouvert → Écritures saisies → Exports transmis → Exercice
  clôturé` (cahier §6).
- **Cycle de vie écriture** — `Provisoire → Contrôlée/lettrée → Validée → Exportée (PES/FEC)` (cahier §6).
- **Verrouillage du référentiel comptable** — Le référentiel M57/M4/PCG (§4.1) devient **non
  modifiable** après la **première clôture** de l'exploitant.

### 4.11 Consolidation groupe & remontée multi-niveaux (profil groupe privé)
- Pour le profil **Groupe privé**, le référentiel comptable est le **PCG** (comme DSP) et chaque
  écriture porte des **axes analytiques** (site / activité / financeur, RG-M6-04) permettant une
  **consolidation** ultérieure des agrégats.
- **Remontée à 3 niveaux** — Les entités du socle (Groupe › Région › Établissement, `RG-SOCLE-01`)
  portent naturellement une lecture **site → région → direction générale** des exports et états
  produits par M6 (cf. §3, `compta × lire_consolide`).
- ⚠ HYPOTHÈSE — Le cahier M6 **ne détaille pas** de moteur de **consolidation multi-établissements**
  (élimination des flux intragroupe, agrégation multi-devises, etc.) : seule la décision actée M7
  (« Profils d'exploitant mixtes : consolider les agrégats communs avec un indicateur de comparabilité ;
  isoler les spécificités ») évoque le sujet, **côté reporting M7**, pas côté M6. La consolidation
  comptable groupe proprement dite (au sens PCG, comptes consolidés) est donc **hors périmètre
  détaillé de M6** tel que documenté ; M6 se limite à **porter les axes analytiques** nécessaires à
  cette consolidation en aval (M7 ou outil comptable tiers). À confirmer avec le métier / un
  expert-comptable de groupe si une consolidation légale (liasse) est attendue du périmètre M6.
- ⚠ HYPOTHÈSE — Comme pour le RAD (§4.8), **aucune US-L4 du backlog** ne couvre le profil groupe /
  la consolidation ; comportement documenté à partir du seul cahier, à formaliser en story(ies) avant
  développement.

## 5. Objets de données
Les types PHP sont indicatifs (spec = comportement observable). Tout objet est rattaché à un
**Établissement** via le socle (`RG-SOCLE-01`) ; identifiants = **UUID** (constitution §3). Les objets
M1 (Produit, Categorie axe comptable) et M2 (Vente, Paiement, ClotureZ) sont **référencés, non
redéfinis** (`spec-offre.md`, `spec-vente.md`).

| Objet | Champ | Type | Contraintes | Notes |
|---|---|---|---|---|
| **ProfilExploitant** | id | uuid | PK | RG-M6-01 |
| | type | enum {régie_directe, dsp, groupe_privé} | requis | pilote tout le module |
| | référentielComptable | enum {M57, M4_SPIC, PCG} | requis, verrouillé après 1ʳᵉ clôture | RG-COMPTA-01 |
| | siren | string | requis (e-reporting agrégé) | RG-M6-07/08 |
| **QualificationEquipement** | id, espace | uuid, ref Espace (socle) | 1 par Espace éligible | décision actée « par équipement » |
| | qualification | enum {SPIC, SPA} | requis si régie directe | ⚠ à valider comptable public |
| **CompteComptable** | id, numéro, libellé | uuid, string, string | numéro unique par référentiel | import/édition (US-L4-01) |
| | sens | enum {débit, crédit} | requis | — |
| | actif | bool | défaut = true | désactivable, non supprimable si utilisé |
| **MappingComptable** | id, catégorie(axe comptable, M1) | uuid, ref Categorie | requis, unique | RG-M1-05 côté source |
| | compteProduit | ref CompteComptable | requis | mapping incomplet bloque écritures |
| | tauxTva | ref TauxTva | requis | RG-TVA-06 |
| **Journal** | id, code, libellé | uuid, string, string | code unique | ventes / encaissements / régie / PCA-OD / extourne |
| **EcritureComptable** | id | uuid | PK, append-only | RG-M6-04 |
| | journal | ref Journal | requis | RG-COMPTA-04 |
| | date, période | date, ref PeriodeComptable | requis | bornée à l'exercice |
| | statut | enum {provisoire, contrôlée, validée, exportée} | défaut = provisoire | cycle de vie §4.10 |
| | venteOrigine | ref Vente (M2)? | requis si générée par une vente | traçabilité NF525 |
| | pièceExtourneDe | ref EcritureComptable? | requis si extourne | seule voie de correction |
| **LigneEcriture** | id, écriture | uuid, ref EcritureComptable | PK | — |
| | compte | ref CompteComptable | requis | RG-M6-04 |
| | débit, crédit | decimal ≥ 0 (exclusifs) | équilibre écriture = Σdébit = Σcrédit | RG-COMPTA-04 |
| | tauxTva | ref TauxTva | requis | RG-M6-04 |
| | axesAnalytiques | {site, activité, financeur} | optionnels sauf si profil groupe | §4.11 |
| **TauxTva** | id, taux, libellé | uuid, decimal, string | ex. 20 %, 10 %, taux réduit | RG-TVA-06, ⚠ taux réduit à valider fiscaliste |
| **EtalementPca** (compte 487) | id, produit(M1) | uuid, ref Produit | requis | RG-M6-02/03 |
| | nature | enum {à_étaler, à_la_consommation} | requis | RG-PCA-05 |
| | méthode | enum {prorata_temporis, au_passage} | requis, paramétrable par type produit | ⚠ HYPOTHÈSE grille exhaustive |
| | périodeService | dateDébut, dateFin | requis si prorata | abonnement |
| | compteReport | ref CompteComptable (487) | requis | crédité à l'encaissement |
| | montantReporté, resteÀServir | decimal ≥ 0 | resteÀServir décroît à chaque reprise | rapprochable à tout instant |
| **MouvementPca** | id, étalement, date | uuid, ref EtalementPca, date | append-only | dotation (encaissement) / reprise (période ou passage Accès) |
| | montant, écritureLiée | decimal, ref EcritureComptable | — | trace vers §4.2 |
| **RegieRecettes** | id, exploitant | uuid, ref ProfilExploitant | 1 par régie déclarée | RG-REGIE-02 |
| | modesAutorisés | set MoyenPaiement | requis, ⊆ acte de régie | RG-M6-10 |
| | plafondEncaisse | decimal > 0 | déclenche alerte au dépassement | RG-M6-10 |
| | soldeEncaisse | decimal | maj temps réel | bloque tant que non versé au-delà du plafond |
| **MoyenPaiement** | code, libellé | uuid, string, string | référentiel **M6** (source), consommé par M2 | RG-M2-02 (`spec-vente.md`) |
| | autoriseRendu | bool | true pour espèces uniquement | cohérent RG-M2-05 |
| **BordereauVersement** | id, régie | uuid, ref RegieRecettes | PK | RG-REGIE-02 |
| | date, montant | date, decimal > 0 | requis | remise au comptable public |
| | justificatifs[] | fichier[] | optionnel | pièces à l'appui |
| **BordereauPayFiP** | id | uuid | PK | RG-PAYFIP-03 |
| | référenceTransaction | string | requis | stockée sur la Vente (M2) |
| | statutRetour | enum {ok, échec, annulé, en_attente} | requis | maj statut Vente |
| | venteRapprochée | ref Vente (M2)? | rapprochement auto | rejeu si retour manquant |
| **ExportComptable** | id, format | uuid, enum {PES_V2_Helios, EtatRegie, FEC, CIEL, EBP, Sage, Cegid} | commuté par ProfilExploitant | RG-EXPORT-07 |
| | période | dateDébut, dateFin | bornée exercice, écritures validées uniquement | — |
| | planification | bool + fréquence? | optionnel | envoi auto récurrent |
| | destinataire | string | optionnel | comptable public / délégant / expert-comptable |
| | statut | enum {généré, bloqué_anomalies, transmis} | bloqué si contrôle en échec | liste d'anomalies associée |
| **DeclarationEReporting** | id, période | uuid, dateDébut, dateFin | agrégat jour × taux | RG-EREPORT-08 |
| | siren | string | requis | RG-M6-07 |
| | agrégatParTaux[] | (taux, baseHT, tva) | requis | RG-M6-08 |
| | statutEnvoi | enum {préparé, transmis, rejeté} | tracé | RG-EREPORT-08 |
| **VenteImpayeeRegie** (marquage) | id, vente(M2) | uuid, ref Vente | requis | RG-M6-09 |
| | motif, dateMarquage | texte, date | requis | exclusion/signalement dans l'agrégat |
| **FactureB2G** | id, client(M4) | uuid, ref | requis | Chorus Pro |
| | numéroEngagement, serviceExécutant | string | requis Chorus Pro | ⚠ HYPOTHÈSE format exact non détaillé |
| **RAD** | id, exploitant(DSP), exercice | uuid, ref, ref PeriodeComptable | 1 par exercice | écran M6-08, profil DSP |
| | compteExploitation | document structuré | — | produits/charges de l'équipement délégué |
| | caFréquentationVentilés | (activité, période, site, CA, fréquentation-cumul) | jamais la jauge FMI | §4.8 |
| **Redevance** | id, rad | uuid, ref RAD | PK | RG-M6 (écran M6-08) |
| | formuleContractuelle, assiette, montant | texte, decimal, decimal | reproductible, traçable | — |
| **PeriodeComptable** | id, exploitant, dateDébut, dateFin | uuid, ref, date, date | contiguës, sans trou | cycle de vie §4.10 |
| | statut | enum {ouverte, clôturée} | clôture irréversible | RG-CLOTURE-10 |
| | étatClôture | document archivé | produits, TVA, encaissements, PCA | RG-CLOTURE-10 |

## 6. Critères d'acceptation
- **CA-1 (US-L4-01, RG-M6-01, RG-COMPTA-01)** — *Étant donné* un exploitant sans profil défini, *quand*
  l'Administrateur sélectionne « Régie directe » et le référentiel M57, *alors* le plan de comptes M57,
  la régie de recettes et les exports publics (PES/Hélios, états de régie) deviennent disponibles sans
  ressaisie, et les formats privés (FEC, éditeurs) sont masqués.
- **CA-2 (US-L4-01, RG-M6-01)** — *Étant donné* une famille de produits vendable sans compte de
  rattachement ni taux TVA, *quand* une vente de cette famille est validée par M2, *alors* la
  génération d'écriture est **bloquée** et signalée (mapping incomplet).
- **CA-3 (US-L4-01, RG-COMPTA-01)** — *Étant donné* un exploitant ayant déjà clôturé une période,
  *quand* on tente de changer le référentiel comptable (M57 → M4), *alors* l'opération est **refusée**
  (verrouillé après 1ʳᵉ clôture).
- **CA-4 (US-L4-02, RG-REGIE-02, RG-M6-10)** — *Étant donné* une régie limitée à {numéraire, CB,
  PayFiP}, *quand* un agent tente d'encaisser en chèque au guichet (M2), *alors* le moyen est **refusé
  à l'encaissement** ; *quand* l'encaisse dépasse le plafond paramétré, *alors* une **alerte bloquante**
  s'affiche tant qu'aucun versement n'est enregistré.
- **CA-5 (US-L4-02)** — *Étant donné* une encaisse à verser, *quand* le régisseur enregistre un
  versement, *alors* un **bordereau daté** est généré avec les **justificatifs** rattachés et le solde
  d'encaisse décroît d'autant.
- **CA-6 (US-L4-03, RG-PAYFIP-03)** — *Étant donné* une vente en ligne payée via PayFiP, *quand* le
  retour DGFiP indique « OK », *alors* la référence de transaction est stockée sur la vente, le statut
  passe à payé, et le paiement est **rapproché automatiquement** ; *quand* le retour est manquant,
  *alors* la transaction est **rejouable** depuis le journal PayFiP.
- **CA-7 (US-L4-04, RG-M6-04, RG-COMPTA-04)** — *Étant donné* une vente validée par M2, *quand*
  l'écriture est générée, *alors* elle est **équilibrée** (Σdébit = Σcrédit), chaque ligne porte un
  **taux de TVA** et ses **axes analytiques**, et l'écriture est classée dans le bon **journal** et la
  bonne **période** ; *quand* on tente de la modifier après génération, *alors* c'est **impossible**
  (seule une **extourne** est possible).
- **CA-8 (US-L4-05, RG-M6-02/03, RG-PCA-05)** — *Étant donné* un abonnement de 12 mois encaissé
  d'avance, *quand* la vente est validée, *alors* le montant **crédite le 487** ; *quand* un mois de
  service s'écoule, *alors* une **reprise au prorata temporis** est générée vers le compte de produit ;
  *étant donné* une carte multi-entrées, *quand* un passage est remonté par le module Accès, *alors*
  une **reprise à la consommation** est générée ; *à tout instant*, le **solde du 487** correspond au
  **reste-à-servir**.
- **CA-9 (US-L4-06, RG-M6-05, RG-TVA-06)** — *Étant donné* un panier mixte (article à 20 % + article à
  10 %), *quand* la vente est encaissée, *alors* la TVA est **ventilée ligne à ligne** (aucun taux
  moyen), le **total TTC** égale la somme des lignes ventilées, et l'**état de TVA** de la période
  restitue base HT et TVA collectée **par taux**, sans montant à taux « indéterminé ».
- **CA-10 (US-L4-07, RG-EXPORT-07)** — *Étant donné* un profil « Régie directe », *quand* le Comptable
  génère un export sur une période, *alors* seuls les formats **PES V2/Hélios** et **états de régie**
  sont proposés (FEC/éditeurs masqués) ; *étant donné* des écritures déséquilibrées sur la période,
  *quand* l'export est lancé, *alors* il est **bloqué** avec la **liste des anomalies**.
- **CA-11 (US-L4-07, RG-EXPORT-07)** — *Étant donné* un profil « DSP », *quand* le Comptable génère un
  export FEC, *alors* le fichier respecte le **format légal 18 champs**, ne porte que des **écritures
  validées**, et le contrôle d'équilibre passe avant toute transmission.
- **CA-12 (US-L4-08, RG-M6-07/08/09, RG-EREPORT-08)** — *Étant donné* une période close, *quand*
  l'agrégat e-reporting est préparé, *alors* il est **unique**, **agrégé au SIREN** de l'exploitant,
  **ventilé jour × taux TVA**, et **exclut/signale** les ventes marquées **« ImpayeRegie »** (recettes
  déjà régularisées via la régie/PES) pour éviter toute double comptabilisation ; le **statut**
  (préparé/transmis/rejeté) est **tracé**.
- **CA-13 (US-L4-09, RG-M6-06)** — *Étant donné* une suite d'écritures validées, *quand* on consulte le
  journal, *alors* chacune porte une **signature chaînée** à la précédente ; *quand* une **rupture de
  chaîne** est simulée, *alors* elle est **détectée** et signalée ; *quand* un jeu d'archives de test
  est généré, *alors* il est **vérifiable** de bout en bout.
- **CA-14 (US-L4-10, RG-CLOTURE-10)** — *Étant donné* une période avec un journal déséquilibré ou des
  versements de régie non soldés, *quand* le Comptable lance la clôture, *alors* elle est **refusée**
  et liste les points bloquants ; *étant donné* une période conforme, *quand* la clôture est validée,
  *alors* **aucune écriture antérieure** n'est plus modifiable, un **état récapitulatif** (produits,
  TVA, encaissements, PCA) est édité, et l'action est **tracée** dans la piste d'audit.
- **CA-15 (écran M6-08, profil DSP, ⚠ hors US-L4 actuelle)** — *Étant donné* un exercice clos en profil
  DSP, *quand* le RAD est généré, *alors* il restitue le **compte d'exploitation**, le **CA et la
  fréquentation cumulée** (pas la jauge FMI) **ventilés** par activité/période/site, et le **montant de
  redevance calculé** est **reproductible** et traçable jusqu'à ces données ; l'autorité délégante n'a
  **pas accès** au journal détaillé.

## 7. Cas limites
- **Mapping comptable incomplet** — Bloque la génération d'écriture pour la famille de produits
  concernée ; la vente M2 elle-même reste possible mais l'écriture reste en attente/anomalie (RG-M6-01,
  CA-2). ⚠ HYPOTHÈSE — le comportement exact de la vente pendant que l'écriture est bloquée (vente
  quand même acceptée en caisse, régularisation comptable différée ?) n'est pas détaillé.
- **Changement de profil exploitant après clôture** — Le référentiel comptable est **verrouillé** ;
  seul un profil jamais clôturé peut encore changer de référentiel (§4.1, CA-3).
- **Mode de paiement retiré de l'acte de régie en cours d'exercice** — Un mode auparavant autorisé
  devient refusé au prochain encaissement ; ⚠ HYPOTHÈSE — le sort des paiements déjà encaissés dans ce
  mode n'est pas traité (pas de rétroactivité attendue, à confirmer).
- **Dépassement du plafond d'encaisse sans versement possible immédiatement** — Alerte bloquante
  maintenue ; ⚠ HYPOTHÈSE — la conduite exacte (blocage total de nouveaux encaissements en régie ou
  simple alerte visuelle non bloquante pour la vente) n'est pas explicitée au-delà de « alerte
  bloquante » (RG-M6-10).
- **Retour PayFiP manquant** — La transaction reste en attente et est **rejouable** (RG-PAYFIP-03) ;
  ⚠ HYPOTHÈSE — délai de rejeu automatique et procédure en cas d'échec répété non précisés.
- **Extourne d'une écriture déjà exportée (PES/FEC transmis)** — ⚠ HYPOTHÈSE — le cahier prévoit
  l'extourne comme seule voie de correction (RG-COMPTA-04) mais ne précise pas si une écriture déjà
  **transmise** à un tiers (Trésor, expert-comptable) déclenche une re-transmission automatique de
  l'extourne ou une procédure manuelle de régularisation externe.
- **PCA en comptabilité publique désactivée** — Tant que l'avis du comptable public n'a pas validé le
  mécanisme du 487 en M57/M4, l'exploitant régie directe peut être configuré **sans PCA active** (vente
  reconnue en produit dès l'encaissement, hypothèse dégradée) — ⚠ À VALIDER PAR EXPERT (§4.3).
- **Panier mixte avec une ligne sans taux de TVA valorisé** — Rejeté à la source (RG-M6-04 : « aucune
  ligne sans taux ») ; empêche la validation de l'écriture correspondante.
- **Recette de régie non marquée « ImpayeRegie » par erreur** — Risque de double comptabilisation dans
  l'e-reporting ; ⚠ HYPOTHÈSE — aucun contrôle croisé automatique (régie ↔ e-reporting) n'est décrit
  dans les sources au-delà du marquage lui-même ; un contrôle de cohérence périodique serait à
  envisager.
- **RAD/Redevances et consolidation groupe sans story backlog** — Comportements spécifiés uniquement à
  partir du cahier (module M6), **non couverts par une US-L4** ; à formaliser en stories dédiées avant
  développement (§4.8, §4.11).
- **Confusion FMI / fréquentation cumulée** — Toute alimentation du RAD ou des exports par la **jauge
  FMI** (présence simultanée, sécurité) au lieu du **cumul de passages** serait une erreur fonctionnelle
  (`spec-acces.md` RG-ACC-04) ; le module M6 ne doit consommer que le cumul.
- **Utilisateur sans affectation sur l'établissement de l'exploitant** — Aucun accès (hérité du socle,
  `RG-SOCLE-05`).

## 8. Dépendances
- **Dépend de : socle L0** (`specs/L0-socle/spec-socle.md`) — hiérarchie Groupe/Région/Établissement/
  Espace (`RG-SOCLE-01`) portant ProfilExploitant, QualificationEquipement (par Espace) et Périmètres
  DSP/groupe ; permissions `module × action` réutilisées sur le module **`compta`**
  (`RG-SOCLE-02/03/04`) ; cadrage par établissement actif (`RG-SOCLE-05`) ; journal d'audit append-only
  (`RG-SOCLE-07`) que le chaînage NF525 renforce.
- **Dépend de : M1 · Offre & Tarification** (L1, `specs/L1-offre/spec-offre.md`) — **catégorie
  comptable obligatoire** portée par le produit (RG-M1-05) alimentant le MappingComptable ; **règle
  PCA** portée par le produit (RG-M1-08, étalement/consommation) que M6 **exécute** sans la redéfinir ;
  ⚠ le traitement du solde de compostages/PCA perdu à l'expiration d'un produit reste une hypothèse
  ouverte partagée avec M1 (§4.3).
- **Dépend de : M2 · Vente & Caisse** (L2, `specs/L2-vente/spec-vente.md`) — **source des écritures**
  (chaque Vente validée génère une EcritureComptable, RG-COMPTA-04) ; **source des encaissements**
  (Paiement par MoyenPaiement, filtré par la Régie M6 — RG-M2-02) ; **source de la clôture Z** qui
  alimente l'état de régie (RG-M2-06) ; **source du chaînage NF525** côté caisse que M6 prolonge côté
  écritures (§4.9) ; les **avoirs/contre-passations** (RG-M2-07) sont le pendant côté vente de
  l'extourne comptable (§4.2).
- **Dépend de : module Accès** (L3, `specs/L3-acces/spec-acces.md`) — **fait générateur** de la reprise
  PCA « au passage » pour les cartes multi-entrées (RG-M6-03) ; **fréquentation cumulée** (cumul de
  passages, distincte de la FMI, RG-ACC-04) alimentant le RAD (§4.8).
- **Interagit avec (hors périmètre L4, consommateurs de M6) :**
  - **M7 · Reporting** — consomme les exports, états de TVA, RAD et agrégats consolidés (§4.11) pour
    ses tableaux de bord décisionnels ; M6 ne construit pas ces tableaux.
  - **M8 · Admin & Droits** — arbitrage des noms de permissions `compta × …` (§3).

---

## Points ouverts / récapitulatif

### ⚠ À VALIDER PAR EXPERT (comptable public / fiscaliste — ne pas trancher techniquement sans eux)
1. **Qualification SPIC vs SPA par équipement** (§4.1) — conditionne M4 vs M57 ; à valider équipement
   par équipement avec le comptable public (décision actée, cahier §8).
2. **Taux réduit de TVA 2025** sur cours/accès sportifs (§4.4) — moteur multi-taux paramétrable prêt,
   application à confirmer par un fiscaliste avant activation.
3. **Admissibilité et schéma d'écritures du PCA (compte 487) en comptabilité publique M57/M4** (§4.3)
   — mécanisme implémenté et **activable**, mais son opposabilité en régie directe doit être validée
   par le comptable public.
4. **Périmètre exact de l'obligation NF525 pour les régies de recettes** (§4.9) — décision actée de
   viser la certification complète, mais l'étendue légale reste à confirmer avec le conseil juridique.
5. **Procédé cryptographique et périmètre de certification NF525** (algorithme de signature/chaînage,
   auto-attestation éditeur vs certificat LNE/INFOCERT, durée de conservation légale) (§4.9) — non fixé
   dans les sources, hérité et amplifié depuis L2.
6. **Articulation entre titres de recettes et flux PES V2/Hélios** (§4.6) — mode de régularisation des
   encaissements de régie par émission de titre de recette non tranché ; hypothèse d'un champ
   paramétrable proposée en attendant l'arbitrage du comptable public.

### ⚠ HYPOTHÈSE (fonctionnel, à trancher avec le métier / M7 / M8)
1. Permission `compta × lire_consolide` (remontée site → région → direction générale) — proposée, non
   nommée dans les sources (§3).
2. Noms des permissions `compta × …` en général — à figer avec M8 (§3, comme L1/L2).
3. Grille exhaustive « type de produit → méthode de reconnaissance PCA » au-delà des deux cas cités
   (§4.3).
4. Traitement du solde de 487 résiduel à l'expiration d'un produit non entièrement consommé (§4.3,
   partagé avec L1).
5. Protocole technique détaillé PayFiP (rejeu, doublons) (§4.5).
6. Canal technique de dépôt de l'agrégat e-reporting (PDP) (§4.7).
7. Format exact des factures B2G Chorus Pro (§4.7/objets).
8. **RAD / redevances DSP et consolidation groupe (§4.8, §4.11) ne sont couverts par aucune US-L4** du
   backlog actuel (scope backlog = « profil régie », 10 US/63 pts) ; comportements spécifiés à partir
   du seul cahier (module M6, bi-régime) ; stories dédiées à créer avant développement.
9. Comportement de la vente en caisse (M2) quand l'écriture comptable est bloquée par un mapping
   incomplet (§7, cas limites).
10. Conduite exacte au dépassement du plafond d'encaisse (blocage strict des encaissements vs alerte
    non bloquante) (§7).
11. Contrôle de cohérence automatique entre marquage « ImpayeRegie » et flux e-reporting (§7).
