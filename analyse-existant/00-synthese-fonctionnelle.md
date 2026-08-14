# Analyse fonctionnelle de l'ancien logiciel (AWOO)

> Document de travail — reconstruit **par l'usage** (exploration navigateur), pas par le code.
> Objectif : cartographier les fonctionnalités réelles pour alimenter le cahier des charges du nouveau logiciel.
> Éditeur : **IT Cotation** — Auteur métier : le client (8 ans d'expérience billetterie / contrôle d'accès).
> Date de début d'analyse : 2026-08-12

---

## Périmètre métier

Logiciel de **billetterie + contrôle d'accès** pour : **piscines, patinoires, salles de sport, musées**.

## Architecture de l'ancien logiciel (3 briques séparées)

| Brique | URL | Rôle |
|--------|-----|------|
| **Club Manager** | clubmanager.awoo.fr | Administration : création de l'offre, base clients, compta, TVA, droits utilisateurs |
| **Caisse** | caisse.awoo.fr | Vente rapide (produits de l'offre) + un peu de gestion |
| **Boutique en ligne** | marketplace.awoo.fr | Vente internet de l'offre + paramétrages |

## Vision pour le NOUVEAU logiciel (intentions du client — à retravailler en spec)

- **Fusion Administration + Caisse** en un seul outil : bascule de mode **ou** UI ultra-lisible **adaptée aux droits** de l'utilisateur.
- **Périphériques externes** : imprimante (tickets), TPE (paiement), lecteur RFID.
- **Boutique en ligne** : achat sur internet depuis l'offre.
- **Application mobile client final** (achat, billets, QR).
- **Application administrateur / staff** : rôles selon droits — ex. un prof/agent qui **valide ses entrées**.
- **Module de bornes** (self-service).
- **Vente → génère un QR code → lu par matériel physique** (tripode / tourniquet d'accès).

### Stack cible retenue
- **Backend** : PHP 8.4 (dernière version)
- **Base de données** : MariaDB

### 🧱 Stratégie de construction (décision client — 2026-08-12)
Construire d'abord un **SOCLE STANDARD réutilisable** (indépendant du métier), puis empiler les **MODULES VERTICAUX** spécifiques.

**Socle standard (cœur de plateforme, réutilisable partout) :**
- **Offre / Catalogue** — création de produits, tarification (grille tarifs × saisons), stock, multi-sites, multilingue.
- **Vente en ligne (Boutique / Marketplace)** — parcours d'achat, panier, paiement, espace client.
- **CRM** — clients (physique/morale), familles, réseau, PMV, historique commandes/consommations.
- **Marketing** — e-marketing (campagnes), promotions, témoignages, listes/segments.
- **Gestion / Back-office** — commandes, devis, encaissements, comptabilité (journaux, exports CIEL/EBP, Trésor Public, chèques, SEPA), reporting/stats.
- **Socle transverse** — utilisateurs / rôles / droits, multi-entités (réseau), périphériques.

**Modules verticaux (par-dessus le socle) :**
- **🎯 Contrôle d'accès** — compostage, QR, RFID (appairage), bornes, tripodes/tourniquets, marges de validation, journaux d'accès.
- **Spécificités piscines** (entrées, cartes multi-entrées, casiers ?), **patinoires** (location patins, séances), **salles de sport** (abonnements récurrents, cours, adhésions), **musées** (billets datés, visites guidées, expositions, boutique).

> Le cahier des charges sera donc structuré **socle d'abord**, puis **verticaux**. Le dev commencera par le socle.

---

# 1. Club Manager (Administration)

**Menu principal (colonne gauche) :** Offre · Vendre · Gérer · Clients · Compta · Listes V1 · Paramètres · Aide
**Barre supérieure :** menu (grille) · accueil · calendrier · panier (articles / total) · recherche
**Note :** bandeau « Abonnement expiré le 31/12/16 » (environnement de démo/archive).

## Tableau de bord (Accueil)
- Graphique « Somme des commandes à J-60 »
- « CA de l'année » (Total HT)
- « Dernières commandes » (Nom, Total, Date, Produits)
- « Annonces »
- « Derniers témoignages » (Note, Produit, Date d'activation, Nom)

<!-- Sections à explorer et compléter ci-dessous -->

## Offre → « Mon Catalogue Produit »

Liste paginée (6 pages) de tous les produits. **Actions :** Création · Brouillon · Filtres · Imprimer · Excel.
**Colonnes :** Image · Nom · Disponibilité · Type · Tarif · Support · Site.
Légende : 🗒️ « Produit avec grille tarifaire » · `¤` « Stock non déterminable ».

### 🔑 Taxonomie des TYPES de produit (concept central)
Observés dans le catalogue de démo :
- **Boutique** — article marchandise (stock physique, ex. livre, assiette, affiche)
- **Billet daté** — entrée à date/horaire précis (avec tranches horaires)
- **Billet daté sans tranches** — entrée à date sans créneau horaire
- **Billet non daté** — entrée valable librement (open)
- **Visite guidée** — prestation encadrée
- **Adhésion** — adhésion à l'organisme (durée)
- **Abonnement** — accès récurrent / multi-visites (ex. « Abonnement 3 ans »)
- **Module Groupe** — prestation pour groupes
- **Entrainement** — séance (orienté sport)
- **Location longue** — location de matériel/espace sur durée
- **Bon d'échange** — voucher / bon cadeau échangeable

### « Support » = univers / catégorie métier
Culture · Tourisme · Activités Aquatiques · Restauration · Nature · (Valeur non définie)

### « Site » = établissement (MULTI-SITES confirmé)
Sites de la structure démo : **DCP · Musée de Belle Ville · SB Diffusion · test**. Un produit est rattaché à un site.

### Filtres du catalogue
Période (préréglages Cette année → Ce jour + Personnaliser) · Nom · **Type** (les 22 types) · **État** (Brouillon / En vente / Tous / Archives) · **Site** · Âge · **Ressource/Support** (Pratique, Activités Aquatiques, Boutique, Culture, Nature, Restauration, Tourisme). Actions catalogue : Imprimer · Excel (export). Vue **Brouillon** dédiée (produits non publiés).

### Notions de disponibilité / stock
Nombre simple · `X / Y` (dispo/total) · `¤` (non déterminable) · `?` (inconnu) · « Calcul en cours… » · valeurs négatives (survente possible).

### Notion de « grille tarifaire »
Certains produits ont une grille de tarifs multiples (icône dédiée) — plein tarif, réduit, etc. → **à explorer dans la fiche produit.**

### 🔑 Écran « Choix du type pour votre nouveau produit » (Offre → Création)
Toute création commence par le **choix d'un type**. Le système propose **22 types** de produits, classés par **5 facettes** :

**Facettes de classification :**
| Facette | Valeurs | Signification |
|---------|---------|---------------|
| **Stock** | Avec / Sans | Le produit gère-t-il un stock ? |
| **Consommateur** | Avec / Sans | Rattaché à un bénéficiaire nominatif ? |
| **Visibilité max** | Guichet / Locale / Réseau | Canal de vente maximal autorisé |
| **Carnet** | Avec / Sans | Vendu par carnet / pass multi-entrées ? |
| **Billet** | Avec / Sans | Génère-t-il un billet (→ QR / contrôle d'accès) ? |

**Les 22 types de produits (avec promesse fonctionnelle) :**
1. **Adhésion** — adhésions & adhérents ; débloque des réductions réservées aux adhérents
2. **Billet daté** — billetterie en ligne + guichet, événement à date/tranches
3. **Billet daté sans tranches** — idem sans créneaux horaires
4. **Billet non daté** — billet lié à un stock, sans date
5. **Billetterie équipement** — accès à des équipements
6. **Bon d'échange** — voucher / bon (comités d'entreprise)
7. **Boutique** — marchandise avec stock, hors planning
8. **Congrès** — événement 1..n jours, inscriptions en ligne + guichet
9. **Cours particulier** — cours particuliers en ligne + guichet
10. **Entrainement** — activités à l'année (sport)
11. **Formation** — formations sur 1..n créneaux / journées, gestion des inscrits
12. **Location courte** — billetterie liée à des créneaux courts
13. **Location courte locale** — idem, canal local
14. **Location longue** — location à créneaux longs
15. **Location longue locale** — idem, canal local
16. **Module Groupe** — produit rattaché au module de gestion des Groupes
17. **Recharge** — recharge de billets (créditer des compostages / étendre une durée)
18. **Séance / Sortie** — séances/sorties sur créneaux liés à une dispo (+ option billetterie)
19. **Séjour** — séjour 1..n jours, inscriptions en ligne + guichet
20. **Service** — proche Formation (créneaux/journées)
21. **Stage** — activités type stage sur 1..n créneaux
22. **Visite guidée** — visites sur créneaux liés à une dispo (+ option billetterie)

> **Observation clé** : le modèle produit est **très générique** — piscine (Entrainement, Billetterie équipement, Recharge/compostage), patinoire (Séance/Sortie, Location matériel), musée (Billet daté, Visite guidée, Boutique), salle de sport (Adhésion, Abonnement, Cours). Un même moteur couvre les 4 verticales via ces types + facettes.

### 🔑 Fiche produit — édition WYSIWYG (« cliquez sur la zone à modifier »)
Blocs de contenu éditables : Description · Photos et vidéos · Liens et pièces jointes · Options et promotions · Ressources · Produits associés · Conseils · Instructions de vente · Régie commerciale. Header : durée de validité (« jusqu'à 1 an »), Prix affiché (boutique en ligne), Catégorie. Boutons **Langues** (multilingue) · **Options** · **Note**.

### 🔑 Modal « Options » d'un produit — LE modèle de configuration
Onglets : **Général · Vente · Ecommerce · Documents · Champs personnalisés · Stock · Comptabilité · Étiquettes**.

**Général :** Couleur (code visuel du produit).

**Vente :**
- Seuil de participants minimum · Nb max dans le panier
- Cases : *Vendre sur la borne* · *Consommateur requis* · *Vendre uniquement au guichet ou en produit associé* · *Rendre le paiement optionnel en Caisse* · *Payeur obligatoire en Caisse* · *Inclure dans la vente par mandat*
- **Rayonnage caisse** (arborescence de rayons pour la caisse : `/Racine/Produits Boutique/Alimentaire`, `/Objets`, `/Racine/Droits d'entrée`, `/Groupes`, `/Expositions`, `/Visites Guidées`)
- Code barre (+ « exploité dans la caisse ») · **Code article** (réf. produit, module **MonStock**) · Numéro d'exploitation
- **Début d'activation du billet** : À la création de la commande / Au premier paiement sur la facture
- **🎯 Options de compostage (contrôle d'accès)** : *Marge d'avance autorisée* (min) et *Marge de retard autorisée* (min) pour valider/composter le billet autour de l'heure de l'activité. Valeur par défaut définie au niveau structure.

> Le **« compostage »** = validation/scan du billet au point de contrôle = **le contrôle d'accès**. C'est ici qu'on paramètre la fenêtre temporelle de validité au passage.

**Ecommerce (vente en ligne) :** Quantité min par commande (+ condition d'application) · Ouverture des réservations (date) · Début de vente de chaque séance (X jours/heures avant) · Arrêt anticipé de vente (X jours/heures avant).

**Stock :** *Stock dédié au produit* (avec Disponibilité) **ou** *Stock partagé* (mutualisé entre produits — ex. « Chambre », « Stock de Vélo », « Visites découverte »). → notion de **pools de stock partagés**.

**Comptabilité :** Code comptable (export compta).

**Documents (onglet — 🎯 central pour billettique/accès) :**
- Document généré : **Carnet** et/ou **Billet**.
- **Nombre de compostages** autorisés par billet → détermine les billets **multi-entrées** (ex. « carte 10 entrées » = 10 compostages).
- **Permettre l'appairage RFID** (badge, bracelet, carte).
- **Sous-réseau** habilité à composter ces billets (fédération du contrôle d'accès entre entités).
- **La caisse composte automatiquement les billets vendus** (validation à la vente).
- Modèle **Billet format A4 pleine page** (image personnalisable, 750×550).
- **Formulaires / contrats associés** : Contrat de location, **Autorisation parentale**, Contrat de location Spot Nautique/vélo, Acceptation des CGV → documents à signer rattachés au produit.
- **Import des références de billet** (.txt/.csv) → billets pré-imprimés / stock externe de références.

**Champs personnalisés :** champs sur-mesure demandés à l'achat (par produit).

**Services :** (onglet sur abonnements/adhésions) services inclus. *(à détailler)*

**🎯 Renouvellement (abonnements récurrents) :** renouvellement **automatique** (à l'expiration → nouvelle commande + **débit auto du payeur**) · jour de début de période (ex. le 17 du mois) · durée (Mois/Jour) · **prix fixe** ou suivant l'évolution du prix · **nombre de renouvellements** (vide = sans engagement jusqu'à résiliation) · mail de notification. → **moteur d'abonnement / prélèvement récurrent** (salles de sport, adhésions annuelles).

**Étiquettes :** étiquettes d'impression (code-barres boutique). *(à détailler)*

### 🔑 Modèle de TARIFICATION (modal « Prix » d'un produit)
Deux modes au choix :
- **Prix dédié** — un prix unique pour le produit.
- **Grille tarifaire** — matrice **Saisons × Types de tarifs** :
  - **« Choix des saisons »** → périodes tarifaires (le prix varie selon la saison/période).
  - **« Choix des tarifs »** → catégories de tarifs nommées, réutilisables (ex. **Adulte, Accompagnant, Visiteur, Étudiant, Réduit…**).
  - Exemple réel (Abonnement Sparkoh) : Adulte **12€** · Accompagnant/Visiteur **5€** · Tarif Étudiant **7€**.
- **Saisons** et **Types de tarifs** sont des **référentiels centraux** (gérés dans Paramètres → Offre) sélectionnés par produit.

Le bloc **« Options et promotions »** (séparé) gère les **promotions** (règles, exclusivité — cf. « sélection automatique des promotions »).

### Fiche produit — barre d'actions complète
**Options · Forum · Aperçu · Espaces · Note · Publié / Non archivé · Langues** + métadonnées (créé par / modifié par / catégorie). Depuis le quick-view catalogue : **Modifier · Dupliquer · Ressources · Stock · Participants · Ajout panier · Vendre**.
- **Participants** = liste des inscrits/bénéficiaires sur le produit (billets vendus).
- **Publié / Non archivé** = cycle de vie du produit (brouillon → publié → archivé).
- **Langues** = fiche produit multilingue.

## Vendre (guichet dans l'admin)
Sous-onglets : **Catalogue · Panier · Groupes**.
**Planning** central des séances/activités : *Vue hebdomadaire* / *Vue liste*, navigation par semaine, une colonne par jour, créneaux (ex. « Concert Tom 08:00-18:00 »). Actions : Filtres · Imprimer · Options · Aide · « Activités complètes incluses ».
→ Vente au guichet adossée au planning ; **Panier** partagé avec la barre du haut (« 0 article(s) / 0,00 € »).
- **Groupes** → « Planning groupe par client » (sous-onglets **Produits · Listes**, filtre « Module Groupe ») : gestion des **réservations de groupes** sur un planning dédié, organisé par client, adossé aux **Devis** (cf. Gérer). Produits de type « Module Groupe ».
- Vue **Catalogue** (vente à partir de la grille produits) et **Panier** (récap avant encaissement).

## Gérer (hub de gestion)
Vue par défaut : **Commandes** (liste filtrable, ex. « aujourd'hui »).
Barre de sous-modules :
- **Devis** — « Devis simple » (Date, Client, Prix, Utilisateur, Structure, Numéro `6xxxxxxx`), **convertible en Commande**. Cœur du flux **groupes / B2B** (ex. centre de loisirs 600€).
- **Listes → « Listes des exports »** (suite de reporting opérationnel) : Disponibilités · Emails envoyés · **Inscriptions** · **Pointage** (présence sur période) · **Pointage billetterie** (commandes à billets valides) · Discussions avec Ressources · **Réservations web abandonnées** (paniers en ligne non aboutis) · **Contrôle des commandes caisse** (paniers validés en attente de génération) · **Historique des clôtures de caisse** · Liste des produits · **Liste d'attente** (activités pleines) · Formulaires externes · **Billetterie externe** (billets importés) · Renouvellement · **Billets – ajustements manuels** (date + nb de compostages) · **🎯 Compostages quotidiens** (journal d'accès par jour) · **Historique des recharges**.
- **Stats** — **Statistiques financières** + **Statistiques de fréquentation** (fréquentation/affluence).
- **C&P → « Demandes ClickAndPay »** — envoi de **liens de paiement à distance** (le client règle en ligne un devis / une commande). Filtres, export, colonnes.
- **Stocks** — suivi des **variations de stock** (colonnes : Initiale · Ajout · Retrait · Achat · Annulation · Finale) + Résumé, par période, export Excel.
- **Vouchers** — « Gestion des vouchers » : bons échangeables suivis par **date de création / d'utilisation**, produit voucher, **produits accessibles**, client, référence, commande. Imprimer/annuler la sélection, export Excel. (↔ type produit « Bon d'échange »).
- **🎯 Compostage → « AwooScan »** — interface de scan/validation (contrôle d'accès) : zone caméra (lecture QR), saisie manuelle du n° de billet (format `24T000000` = année + `T` + numéro), bouton Rechercher, « Dernier scan ». C'est **l'app de contrôle d'accès** en version web/mobile.
- **🎯 RFID → « Liste d'appairage/désappairage »** — on **appaire une carte RFID physique à un billet**. Colonnes : Payeur, Consommateur, Produit (ex. **« Carte 10 entrées »**), N° appairage (RFID), N° Billet, statut *Appairé/Non appairé*, Début/Fin validité, N° Commande. → gestion des **supports RFID multi-entrées** (le classique « carte 10 entrées » piscine/patinoire). Actions : Filtres, Imprimer, Excel, Actions, Colonnes, Listes ; filtre « tickets obsolètes/annulés ».
- **Ressources** — gestion des **intervenants/encadrants** (RH) : **Barèmes · Catégories Professionnelles · Profils · Rémunérations · Discussions**.
- **Rapport** — **comptes-rendus d'activité** : pour chaque séance planifiée (Site, Ressource, Activité, dates, Utilisateur) un **formulaire à saisir** (débrief/rapport post-séance par l'encadrant). Filtres : État, Planification, Nom du produit. ↔ vision « prof/agent qui suit ses séances ».
- **Espaces → « Aperçu des sites »** — **supervision temps réel des points d'accès** par site : ouvrir / **Tout fermer** / Mode par défaut, état par espace (ex. Musée « Standard », pastille verte = ouvert), recherche Ticket. → pilotage opérationnel des accès (portiques/salles).

## Clients (CRM)
**Recherche Client** multi-critères : Nom, Prénom, Email, Ville, Code Postal, Type, Consommation, Réseau, Famille.
Création **Personne physique** ou **Personne morale** (entreprise/collectivité).
Sous-modules : **Fusion** (dédoublonnage) · **Témoignages** (avis produits) · **E-marketing** (campagnes) · **Listes** (exports) · **Répartition**.
Notions transverses : **Réseau** (regroupement d'établissements ?), **Famille** (foyer / groupe de personnes), **Consommateur** (bénéficiaire distinct du payeur — cf. billets nominatifs).
**🔑 Fiche client (vue 360°) :**
- Identité + civilité + date de naissance + **N° client** + coordonnées (adresse, téléphones, email) ; boutons **Imprimer** et **RFID** (supports rattachés au client).
- **🎯 Portes-monnaie virtuels (PMV)** : solde prépayé rechargeable avec **échéance** (ex. « 13,00 € à utiliser avant le 05/02/2026 »). C'est le PMV utilisé pour les remboursements et le crédit prépayé (piscine/patinoire).
- **Commandes** — historique filtrable (période, inclure annulées/payées) avec lignes détaillées (produit, séance, **type de tarif**), montant, solde.
- **🎯 Types de tarifs sociaux observés** : Tarif réduit, Tarif enfant <6 ans, Tarif Groupe, **Gratuité RSA** → tarification sociale (secteur public).
- **Consommations** — usage réel des prestations (distinct des commandes).
- **Notes** libres.
- **Adhésions** — active, origine, du/au, notes.
- **Renouvellements** — suivi par statut : En attente · Pause · Valide · Annulé · **Échec de paiement** · Échec de finalisation de paiement.
- **Inscriptions/activités** — statut, date planifiée, date effective, commande.

**Sous-modules Clients :** **Fusion** (dédoublonnage), **Témoignages** (avis produits), **E-marketing** (campagnes email/SMS), **Listes** (exports/segments), **Répartition** (répartition/affectation clients — ex. par réseau). *(explorables en détail au besoin)*

## Compta (comptabilité / encaissements)
Vue **Encaissements** (graphe sommes encaissées). Sous-modules détaillés :
- **Journaux** — **journaux comptables** exportables vers logiciels compta : Type (ventes / encaissements / banque V2), **Sortie = CIEL / EBP**, découpage Mensuel / Trimestre. (Intégrations compta FR.)
- **Trésor P. (Trésor Public / régie)** — exports : **Ventes par produits** · **Répartition des paiements** (par vendeur / caisse / site / produit / moyen de paiement) · **Modes de paiement**. (Notion « facturation v2 ».)
- **Chèques** — **remise de chèques** : sélection des paiements chèque, **bordereau de remise**, historique des remises.
- **Mandats — SEPA** : remise de **mandats SEPA** (prélèvement), **jeu de prélèvement** (batch), bordereau, historique. → **prélèvement SEPA** pour abonnements.
- **Listes** — exports comptables.
> **Orientation secteur public forte** : régie de recettes, Trésor Public, **paiement par mandat** (cf. option produit « Inclure dans la vente par mandat »). Essentiel pour établissements municipaux (piscines, patinoires, musées).

## Listes V1
Versions **héritées (V1)** des exports (avant « facturation v2 »), regroupées en **Gérer · Comptabilité · Trésor public**. Conservées pour compatibilité/historique. → dette technique à ne pas reconduire telle quelle dans le nouveau logiciel (unifier les exports).

## Paramètres
7 catégories : **AwoO · Offre · Vendre · Comptabilité · Plannings · Espaces · Comptes**.

### Paramètres → AwoO (réglages généraux structure) — extraits marquants
- **Général** : paiement en mode avancé (multi-paiements avant enregistrement) ; afficher encadrants/ressources dans le Marketplace ; délai min avant démarrage d'activité ; **ajustement auto du prix des tranches horaires selon la durée** ; **envoi auto de SMS aux listes d'attente** (place libérée) ; gestion fine des mails de commande (Commande, Facture, Billet, Carnet) ; **mail partenaire aux sites de production**.
- **Facturation** : assistant de facturation en fin de commande ; **titres personnalisables de tous les documents générés** → taxonomie riche : Devis, Commande, Bon de Commande, Bon de Réservation, Inscription en ligne, Synthèse de commande, **Facture, Facture d'acompte, Facture d'avoir, Facture proforma**, Justificatif d'achat/acompte/avoir, notes permanentes.
- **MarketPlace** : téléphone mobile obligatoire, **couleurs (branding)**.
- **🎯 Mandat SEPA** : préfixes MndtId / EndToEndId / InstrId → **prélèvement SEPA**.
- **🎯 Modules → Billetterie** : *« Activer les QR codes optimisés pour la billetterie »* — **compatibles uniquement avec le validateur de billet et l'application AwooScan** ; marge d'avance / de retard (min) pour la présentation/validation d'un billet.
- **Promotions** : sélection automatique des promotions (exclusives / à règle).
- **Annulation par le client** depuis son espace perso : produits gratuits / payants, **remboursement automatique dans le PMV** (moyen de paiement d'origine).
- **🎯 Partage du fichier client par Réseau / sous-réseau** : mutualisation des fiches clients, commandes et documents entre entités d'un même réseau (ex. Office de Tourisme). → **architecture multi-entités / réseau**.

### 🎯 Paramètres → Comptes (utilisateurs & droits)
Tuiles : **Droits · Utilisateurs**.
- **Droits → Rôles** — « Gestion du rôle des utilisateurs ». **5 rôles prédéfinis** : **Administrateur · Agent d'accueil · Comptable · Ressource · Agent de caisse**. Chaque utilisateur reçoit un/plusieurs rôles (mention « Ancien rôle X » = héritage d'une migration V1→V2). → **socle du modèle de permissions**, base directe pour l'**UI adaptative aux droits** de la vision cible.
- **Utilisateurs** — liste/gestion des comptes utilisateurs (staff).
> ⚠️ Le modèle de droits actuel est **par rôle global** (5 rôles). Pour le nouveau logiciel, prévoir un modèle plus fin (permissions par module/action, multi-établissements) est une piste — à trancher en spec.

### Paramètres → Offre (référentiels centraux de l'offre)
Tuiles : **Tarif - Types · Tarif - Saisons · Étiquettes produits · Supports locaux · Promotions · Stocks partagés · Champs perso · Étiquettes**.
- **🎯 Tarif - Types** — référentiel des **catégories de tarifs** (**52** dans la démo) : Adulte, Enfant -12, Accompagnant/Visiteur, Étudiant, Famille, Groupe, **Tarif réduit, Gratuité RSA, PMR, Demandeur d'emploi, Scolaire / Non scolaire, Établissement scolaire, Association, Entreprise**… Chaque type : nom, description, **visibilité (Guichet\Internet / Guichet seul)**, ordre d'affichage, **multilingue**. → réutilisés dans les grilles tarifaires produit.
- **Tarif - Saisons** — périodes/saisons tarifaires (axe temporel des grilles).
- **Supports locaux** — référentiel des **« supports » / univers** (Culture, Tourisme, Activités Aquatiques, Restauration, Nature…).
- **Stocks partagés** — pools de stock mutualisés entre produits.
- **Promotions** — règles de promotion réutilisables.
- **Champs perso / Étiquettes / Étiquettes produits** — champs sur-mesure et modèles d'étiquettes.

### Autres catégories Paramètres (à explorer : #11–#13)
### Paramètres → Vendre
Tuiles : **Caisse · Rayonnage · Types de documents · Caisse Turbo · Tri en ligne · Produits annexes · MarketPlace · Acomptes**.
- **Caisse (« Paramètres de caisse »)** : refuser commande sans payeur · code postal obligatoire · **mode vente rapide par défaut** · règles de **facturation** (paiement complet obligatoire ou partiel → justificatif d'achat non acquitté / si livré…) · **Impression** : ticket auto (seuil de prix), duplicata, **billets auto**, fermeture auto popup, **🎯 ouverture auto de la popup d'appairage RFID à la validation du paiement** · **pied de page ticket** personnalisé.
- **Rayonnage** : arborescence des rayons caisse (cf. option produit « Rayonnage caisse »).
- **Types de documents** : modèles de documents (ticket, billet, facture…).
- **Caisse Turbo** : mode caisse ultra-rapide (raccourcis/grille optimisée).
- **Produits annexes** : ventes additionnelles / cross-sell en caisse.
- **MarketPlace** : réglages boutique en ligne.
- **Acomptes** : gestion des acomptes / paiements partiels.
> ⚠️ **Périphériques (imprimante / TPE / RFID)** : configurés surtout au niveau **« point de vente »** (choix de l'appareil à la connexion caisse, pour l'impression automatique). Le **référentiel des moyens de paiement (PMV)** est à confirmer (probablement Paramètres → Comptabilité).
### Paramètres → Comptabilité
Tuiles : **Comptes TVA · Comptes produits · Gestion paiements · Ventilation · Clôturer · Taux de TVA**.
- **🎯 Gestion paiements** — référentiel des **moyens de paiement** : Espèces · **CB AwoO** (TPE intégré) · Carte bancaire · Chèque · Virement · **Chèques Culture / Loisirs / Vacances** (titres sociaux) · Avoir · Voucher · **Porte-monnaie virtuel (PMV)** · **Mandat SEPA / Paiement SEPA** · + 10 « Autre » personnalisables. Chaque moyen : libellé, **compte comptable**, visibilité. Section dédiée **PMV** (types de porte-monnaie).
- **Taux de TVA** — référentiel des taux de TVA.
- **Comptes TVA / Comptes produits** — plan comptable (rattachement produits/TVA).
- **Ventilation** — règles de ventilation comptable.
- **Clôturer** — clôture de période comptable.
- **Comptes** (Paramètres → Comptes) : utilisateurs / rôles / droits (voir #13, lecture seule).

### Paramètres → Plannings
**Périodes** (périodes d'ouverture / saisons de planning) + **Plannings** (modèles de planning et créneaux récurrents).

### 🎯 Paramètres → Espaces (matériel de contrôle d'accès)
Tuiles : **Équipements · Contrôleurs · Espaces**. C'est la **configuration du contrôle d'accès physique** :
- **Espaces** — zones d'accès (ex. « Musée », un bassin, une salle) rattachées à un site.
- **Contrôleurs d'accès** — points d'accès logiques : Nom, **Espace** rattaché, **Actif** (oui/non), Désignation, **Équipements associés**.
- **Équipements** — matériel physique (tripodes / tourniquets / lecteurs / valideurs) associé aux contrôleurs.
> Modèle : **Site → Espace (zone) → Contrôleur (point d'accès logique) → Équipement (tripode/lecteur physique)**. C'est ici que se branche le matériel (QR/AwooScan, RFID, tripode) évoqué dans la vision cible.

---

# Synthèse Club Manager & implications (socle vs verticaux)

**Le Club Manager = ~90 % du SOCLE STANDARD visé.** Répartition des modules observés :

| Brique | Modules AWOO correspondants | Socle / Vertical |
|--------|------------------------------|------------------|
| **Offre & Tarification** | Catalogue, 22 types produits, grille tarifaire (tarifs × saisons), stocks (dédiés/partagés), multi-sites, multilingue | **Socle** |
| **Vente / Guichet** | Vendre (planning, panier, groupes), Caisse (POS), Devis, ClickAndPay | **Socle** |
| **Vente en ligne** | MarketPlace (boutique), espace client, réservations | **Socle** |
| **CRM** | Clients (physique/morale), familles, réseau, PMV, fiche 360°, fusion | **Socle** |
| **Marketing** | E-marketing, promotions, témoignages, listes d'attente + SMS | **Socle** |
| **Gestion / Back-office** | Commandes, stocks, ressources (RH encadrants), rapports, stats | **Socle** |
| **Comptabilité / Régie** | Encaissements, journaux (CIEL/EBP), Trésor Public, chèques, SEPA, TVA, moyens de paiement | **Socle** |
| **Administration** | Utilisateurs, rôles/droits (5 rôles), multi-entités/réseau, paramètres | **Socle** |
| **🎯 Contrôle d'accès** | Compostage (AwooScan), RFID (appairage), Espaces/Contrôleurs/Équipements, marges de validation, QR optimisés | **Vertical** |
| **Spécificités métier** | Cartes multi-entrées (piscine), recharges, séances/entrainements, abonnements récurrents, visites guidées, billets datés | **Vertical** |

**Points forts à conserver :** modèle produit générique très puissant (22 types + facettes), tarification riche (tarifs sociaux, saisons), gestion secteur public (mandat/SEPA/Trésor Public), contrôle d'accès intégré (QR + RFID + tripodes).

**Points faibles / dette à corriger dans le nouveau :** 3 apps séparées (à fusionner Admin+Caisse), UI datée et peu lisible, navigation lourde (onglets qui perdent la barre), exports « V1 » hérités en doublon, données de démo incohérentes (survente, doublons), droits trop grossiers (5 rôles globaux).

---

# 2. Caisse (POS — caisse.awoo.fr)

Application dédiée de **vente rapide au guichet**, séparée du Club Manager (à fusionner dans le nouveau logiciel).

## Session de caisse
Écran **« Sélection de caisse »** au démarrage :
- Choix d'un **Point de vente** (ordinateur/tablette) → conditionne l'**impression automatique** sur l'imprimante de l'appareil.
- Choix d'une **caisse** parmi une liste, avec états : **🟢 Ouverte · 🟡 En cours de fermeture · 🔒 Sécurisée**, nom + qui l'a ouverte + date/heure.
- Ou **création d'une caisse** : **Montant à l'ouverture (fond de caisse)**, nom, **code de sécurité**.
- Actions : Se déconnecter · Créer. → notion de **session de caisse** (ouverture/fermeture/sécurisation, fond de caisse, code).

## Écran de vente
- **Barre haute** : site, horloge, compteurs **Tickets / Vente € / Encaissé €**, plein écran, utilisateur + caisse active.
- **Panier/ticket** à gauche avec **Total** ; lignes supprimables.
- **Recherches** : client · article · ticket · commande · **scan QR**.
- **Onglets de rayons** : ⭐ Favoris · récents · Produits Boutique · **Droits d'entrée** · Groupes · … (= « rayonnage caisse »).
- **Filtre** par Site (DCP, Musée…) et Domaine ; option « Masquer les autres producteurs » (réseau).
- **Grille produits** avec **fourchettes de prix** (grilles tarifaires) ; **blocage si stock épuisé** (« Ce produit n'a plus de stock »).
- Actions ticket : Ajouter un client · **Click & Pay** · Paiement avancé · Ajout de note · Annulation du ticket · **Mode vente** (rapide) · **Billet long**.

## Flux de vente (clic par clic)
**Produit → Quantité** (numpad, dispo, note) **→ Promotions et options** (promo groupe %, **promotion libre** en %/€) **→ ligne au ticket → Paiement**.

## Paiement (« Paiements avancés »)
**Paiement multiple / scindé** : saisir un montant, choisir un moyen, répéter jusqu'à **Reste à payer = 0**. Moyens : **Espèces · Carte bancaire · Virement · Chèques Cultures · Chèques Loisirs · Avoir · Chèque Vacances · Paiement différé · Régularisation solde · Chèque bancaire** (Banque + N°). Puis génération ticket/billet (impression auto selon réglages) + **popup d'appairage RFID** si activée.

> **Implication nouveau logiciel** : fusionner cette Caisse avec l'admin (Club Manager) en une seule app à **bascule de mode / UI adaptative aux droits**. Conserver : session de caisse, paiement scindé, rayons, scan QR, blocage stock. Intégrer nativement **TPE** (au-delà du « Carte bancaire » manuel) et périphériques.

---

# 3. Boutique en ligne (Marketplace — marketplace.awoo.fr)

**Plateforme de vente en ligne mutualisée / multi-producteurs** (réseau), **personnalisée par entité** (logo, branding, couleurs — cf. Paramètres). L'instance de démo = **EFV Valentin (LOOS)**, un **centre aquatique** — verticale piscine (produits : *Baptême Plongée Adulte*, *Bonnet bain*, **AQUABIKE 10 tickets**, Aquabike…). Marque « Powered By AwoO ».

## Vitrine / catalogue en ligne
- Barre : logo entité · **Filtrer** · **Panier (n Article)** · **Tri** (Aucun tri / Nouveautés / Moins chers / Plus chers / Âge) · FAQ · langue · **Se connecter** (espace client).
- Liste de produits : image, titre, description courte, **…plus d'info**, **localisation**, badge **Promos**.
- Bandeau cookies (statistiques de visite).

## Fiche produit en ligne
- Prix de base · galerie photos/vidéos · description · **produits associés** · **carte** (lieu).
- **« Cliquez ici pour réserver »** → affiche **disponibilité (Reste : n)** + prix + bouton **Réserver**.

## Ajout au panier
Modal : prix unitaire, disponibilité, **Quantité / Nb de personnes**, prix (hors promotions/avantages). Options : *Ajouter et réserver maintenant* / *…et rechercher un autre produit* / *…et revenir à la fiche* / Annuler.

## Tunnel de commande (multi-étapes)
**Étape 1 — Identification** : **« Déjà client ? »** (connexion email + mot de passe, mot de passe oublié) **ou « Nouveau client ? »** (**Société/association O/N** = B2B/B2C, civilité, nom, prénom, **date de naissance** « pour éviter les homonymes », email, mot de passe) · récap article (réf, supprimer du panier) · **consentement RGPD obligatoire** (« J'accepte ces conditions »).
_(Étapes suivantes non parcourues — création de compte / soumission de données perso hors périmètre d'action : **destinataires/bénéficiaires par article** puis **paiement en ligne**.)_

## Espace client (« Se connecter »)
Compte client en ligne (`/CustomerAuth/Connect`) : suivi des commandes, billets/QR, **annulation depuis l'espace perso** (cf. option AwoO), PMV, adhésions/renouvellements (cf. fiche client 360°).

> **Implications nouveau logiciel** : conserver la **vitrine multi-entités brandée**, le tunnel guidé, le compte client, la réservation avec disponibilité et bénéficiaires. Moderniser l'UX (mobile-first), et surtout **ajouter l'app mobile client final** (billets + QR + wallet) et l'**app staff/admin** de la vision cible, aujourd'hui absentes.

---

# 3. Boutique en ligne (Marketplace)
_(à explorer)_
