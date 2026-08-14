# Spec — Contrôle d'accès (`Accès` / lot `L3`)

- **Lot / module :** L3 · Contrôle d'accès (vertical cœur de valeur)
- **Stories couvertes :** US-L3-01 à US-L3-12
- **Règles de gestion :** RG-ACC-01 à RG-ACC-07 (source `cahier-detaille.html`, panel `p-acces`)
- **Statut :** brouillon

## 1. Objectif
Contrôler l'accès physique aux espaces en pilotant le matériel **IT Cotation** (concentrateur **ITBOX**, tourniquets/tripodes **iDTRONIC**, lecteurs QR/RFID, logiciel **SmartAccess**) : **valider chaque passage** sur la base d'un droit vendu (billet, abonnement, carte), **compter la fréquentation**, **piloter la jauge FMI** (présence simultanée = sécurité ERP), et **garantir la continuité de service hors-ligne** comme pour les personnes sans support scannable — en produisant la **donnée source unique** (passages horodatés) consommée par la compta (M6) et le reporting (M7).

## 2. Périmètre
- **Inclus :**
  - Configuration de la topologie **Espace › Contrôleur › Équipement** : sens, anti-passback, marges horaires, seuil de jauge/FMI — US-L3-01, écran A-01.
  - **Appairage** d'un support (QR / RFID / wallet) à un droit d'accès — US-L3-02, écran A-02, RG-ACC (consomme l'appairage déclenché à la vente M2).
  - **Validation d'un passage** au tourniquet : contrôle droit + marges + anti-passback + décompte du crédit, réponse < 1 s — US-L3-03, RG-ACC-01/02.
  - **Comptage non nominatif** (bébé, accompagnant, exonéré, sans support) — US-L3-04, RG-ACC-03.
  - **Jauge FMI** distincte du cumul : blocage ou alerte au seuil, décrément aux sorties, recalage à l'ouverture/fermeture — US-L3-05, RG-ACC-04.
  - **Supervision temps réel** : jauge, flux de passages, incidents, statut réseau, ouverture manuelle, +1 sans support — US-L3-06, écran A-03.
  - **Contrôle mobile** coupe-file (app staff smartphone/PDA) — écran A-04 (transverse aux US, notamment US-L3-04).
  - **Fonctionnement hors-ligne** avec liste de révocation embarquée + file de passages — US-L3-07, RG-ACC-05/07.
  - **Synchronisation** au retour réseau : rejeu chronologique + recalage FMI + détection de conflits — US-L3-08, RG-ACC-05/06.
  - **Support perdu/volé** : blocage serveur immédiat + propagation à la prochaine synchro — US-L3-09, RG-ACC-07.
  - **Carte épuisée** : refus explicite + proposition de rechargement — US-L3-10, RG-ACC-02.
  - **Journal des passages** horodaté, filtrable, en lecture seule, exportable — US-L3-11, écran A-05, RG-ACC-06.
  - **Sous-réseau / accès fédéré** configurable (reconnaissance mutuelle inter-entités) — US-L3-12.
- **Exclu (pour l'instant), que le module *référence* seulement :**
  - **Définition des droits et produits** (billet, abonnement, formule, carte multi-entrées, nombre de compostages, marges par défaut, sous-réseau du produit) → **M1** (L1, `spec-offre.md`, RG-M1-03/04/13). L3 **consomme** le droit ; ne le crée pas.
  - **Émission du support et appairage à la vente** (popup RFID en caisse, statut d'appairage, dévalidation d'un billet annulé) → **M2** (L2, `spec-vente.md`, RG-M2-04, US-L2-08/09). L3 **prolonge** l'appairage (borne autonome, ré-appairage) et **consomme** les dévalidations émises par M2.
  - **Reconnaissance comptable de la consommation** (PCA, valorisation du passage) → **M6**. L3 **alimente** M6 (RG-ACC-06) sans valoriser.
  - **Reporting, tableaux de bord, analyse de fréquentation** → **M7**. L3 **alimente** M7 sans analyser.
  - **Réservation / décompte des séances et quotas** (services inclus, planning) → **M5** ; L3 **valide** un accès rattaché à un créneau (écran A-04) mais ne gère pas le calendrier.
  - **Fiches usagers / CRM**, identité du porteur → **M4**.
  - **UI (front)** ; **authentification, rôles/permissions, journal d'audit** → **socle L0** (`spec-socle.md`), réutilisés et non redéfinis ici.
  - **Encaissement / rechargement effectif** d'une carte épuisée → **M2** ; L3 **oriente** (caisse / borne / app) sans encaisser.

## 3. Acteurs & droits
Les permissions réutilisent le modèle du socle (`RG-SOCLE-02/03/04/05`) : couple `module × action` sur le module **`acces`**, portées par l'**établissement actif** ; l'UI **masque** ce qui n'est pas autorisé (`RG-SOCLE-04`). Source : cahier §2 (panel `p-acces`).

| Acteur | Peut | Ne peut pas | Permission (module × action) |
|---|---|---|---|
| **Administrateur** | Configurer espaces/contrôleurs/équipements ; définir sens, anti-passback, marges, seuil FMI ; configurer sous-réseaux/fédération ; consulter le journal | Créer des droits/produits (M1) ; valoriser la consommation (M6) | `acces × gerer`, `acces × lire` |
| **Agent d'accueil** | Superviser en temps réel, ouvrir manuellement un contrôleur (motif requis), enregistrer un passage sans support, appairer un support, déclarer un support perdu/volé | Modifier la configuration matérielle des espaces | `acces × superviser`, `acces × appairer`, `acces × ouvrir_manuel`, `acces × bloquer_support` |
| **Agent de contrôle / encadrant** | Contrôler en mobilité (scan smartphone/PDA), coupe-file, passage sans support, validation de séance | Superviser la jauge globale ; ouvrir un tourniquet fixe à distance | `acces × controler` |
| **Système** | Valider automatiquement au tourniquet (droit + marges + anti-passback), décompter le crédit, horodater et rattacher le passage, basculer online/offline, rejouer/recaler à la synchro | Autoriser un passage sans droit valide, sauf comptage non nominatif déclenché par un agent | *(acteur technique — pas de permission humaine)* |
| **Lecture seule** | Consulter le journal des passages, la supervision | Toute action d'écriture / config | `acces × lire` |

- ⚠ HYPOTHÈSE — Les **noms de permissions** (`acces × gerer / superviser / appairer / ouvrir_manuel / controler / bloquer_support / lire`) déclinent le tableau « Acteurs & droits » du cahier §2 selon le modèle `module × action` du socle ; ils ne sont **pas nommés littéralement** dans les sources. Découpage fin à **arbitrer avec M8**.
- ⚠ HYPOTHÈSE — Le rôle **« Client final »** (US-L3-10, message « carte épuisée » + proposition de recharge) n'est pas un acteur authentifié du module : il s'agit du **porteur** face à l'équipement ; le comportement observable est l'affichage au lecteur/borne, non une permission.

## 4. Comportements & règles
Chaque comportement trace une **RG-ACC** (source : `cahier-detaille.html`, panel `p-acces`) et/ou une **US-L3** (source : `backlog.html`, panel `p-l3`). Les **décisions actées** (onglet ★ du cahier) font foi et ne sont pas re-tranchées.

### 4.1 Topologie Espace › Contrôleur › Équipement (US-L3-01, écran A-01)
- **Arbre à trois niveaux** — **Espace** (nœud racine, porte le seuil de jauge/FMI) › **Contrôleur** (rattaché à un **ITBOX**) › **Équipement** (tourniquet iDTRONIC ou lecteur QR/RFID). Chaque équipement est rattaché à un contrôleur, lui-même à un espace ; l'espace est rattaché à un **Établissement/Espace du socle** (`RG-SOCLE-01`).
- **Sens de passage** — Chaque équipement porte un **sens** : `entrée`, `sortie` ou **`bidirectionnel`** (US-L3-01). Le sens **détermine l'impact sur la FMI** (entrée = +1, sortie = −1 ; RG-ACC-04). Un **sens manquant** bloque l'enregistrement.
- **Un ITBOX peut piloter plusieurs contrôleurs** de sens différents (cahier A-01).
- **Anti-passback** — Actif/inactif + **délai paramétrable** ; **défaut ~5 min** (décision actée). Réglé au niveau **espace**, **surchargeable au niveau équipement** (US-L3-01). Voir §4.3.
- **Marges d'avance / de retard** — Tolérance en minutes autour du créneau du droit (cahier A-01). ⚠ HYPOTHÈSE — combinaison des marges **portées par le droit/produit (M1)** et des marges configurées sur l'**équipement** : les sources posent les deux niveaux sans préciser lequel prime ; **règle de combinaison à arbitrer avec M1** (hypothèse retenue : l'équipement borne une tolérance locale, le droit porte la fenêtre de validité métier ; la marge effective = intersection).
- **Seuil jauge / FMI** — Entier requis, défini **par espace**, **indépendant du cumul de passages** (cahier A-01, RG-ACC-04). Le **mode au dépassement** (blocage strict vs simple alerte) est **paramétrable par espace** au niveau du seuil (**décision actée**, cahier §8).
- **Cohérence bloquante** — Une topologie incohérente (équipement orphelin, sens manquant, contrôleur sans ITBOX) est **refusée à l'enregistrement** (US-L3-01).

### 4.2 Appairage support ↔ droit (US-L3-02, écran A-02)
- **RG-ACC (appairage)** — L'appairage relie un **Support** (identifiant unique QR / RFID / wallet, lu par le lecteur) à un **DroitAccès** issu de M1/M2, et enregistre le **type de support** et le **mode** (`caisse` / `autonome` = borne libre-service).
- **Unicité** — Un même support ne peut être appairé qu'à **un seul droit actif à la fois** (US-L3-02). Un support **déjà appairé et actif** ne peut être ré-affecté **sans révocation préalable** (cahier A-02).
- **Refus** — Un support **déjà appairé** ou **bloqué (blacklisté)** est refusé avec un **message explicite** (US-L3-02, RG-ACC-07).
- **Statut support** — `actif` / `bloqué` ; passe à `bloqué` en cas de perte/vol (§4.7).
- **Continuité avec M2** — L'appairage initial est le plus souvent **déclenché à la vente** (M2, RG-M2-04, US-L2-08) ; L3 prend le relais pour l'**appairage en borne autonome** et le **ré-appairage** après révocation. ⚠ HYPOTHÈSE — la **conduite en cas d'échec d'appairage** (ré-essai, support de secours, ticket seul) n'est pas spécifiée (déjà signalée L2 §4.6) → **à préciser conjointement M2/L3**.

### 4.3 Validation d'un passage au tourniquet (US-L3-03, RG-ACC-01/02)
- **RG-ACC-01 — Validation** — Un passage valide **vérifie l'existence et la validité du droit** (billet, abonnement, carte), **applique les marges d'avance/retard** et l'**anti-passback** **avant** d'autoriser le franchissement. Trois issues possibles : **Validé / Refusé / Compté** (§4.4).
- **RG-ACC-02 — Décompte du crédit** — Pour une **carte à quota** ou une **carte 10** (stock de compostages M1, RG-M1-04/13), un passage validé **décompte automatiquement une unité** ; à **quota nul**, tout nouveau passage est **refusé** (→ §4.8). Le décompte est **atomique** au moment de l'acceptation (US-L3-03).
- **Anti-passback** — Un **re-scan du même support avant le délai** configuré est **refusé** (cahier A-01, décision actée délai ~5 min surchargeable). Empêche le repassage/prêt immédiat d'un support.
- **Bon droit → bon tourniquet, bon sens, bonnes marges** — Le franchissement n'est autorisé que sur l'équipement et dans le sens/les marges configurés (cahier §7).
- **Performance** — La réponse au contrôleur est rendue en **moins d'une seconde** en conditions nominales (US-L3-03).
- **Horodatage & motivation** — Chaque décision (accepté/refusé) est **horodatée et motivée** et alimente le journal (§4.9) et la jauge (§4.5).

### 4.4 Comptage non nominatif — personne sans support (US-L3-04, RG-ACC-03)
- **RG-ACC-03** — Un **bébé** ou un **accompagnant gratuit** passe via **comptage non nominatif** (bouton agent « +1 » en supervision, ou coupe-file mobile), **sans QR** : le passage est **compté** mais **non rattaché à un droit**.
- **Impact** — Un passage non nominatif **incrémente la fréquentation et la jauge FMI** (présence physique réelle) **sans décompter de crédit** (US-L3-04 ; **décision actée** piscine « bébés & accompagnants comptés dans la FMI »).
- **Motif tracé** — Le **motif** (accompagnant, bébé, exonéré) est **saisi et tracé** ; le passage apparaît **distinctement** au journal et en supervision (US-L3-04).

### 4.5 Jauge FMI — présence simultanée ≠ cumul (US-L3-05, RG-ACC-04)
- **RG-ACC-04 — FMI ≠ cumul** — La **FMI** mesure la **présence simultanée** (seuil de sécurité ERP) ; elle se distingue **strictement** du **cumul de passages**. **Entrée = +1, sortie = −1** ; le cumul journalier, lui, ne fait qu'augmenter.
- **Deux compteurs exposés** — Jauge (présents) et cumul (passages du jour) sont **exposés comme deux compteurs distincts** (cahier §7).
- **Blocage ou alerte au seuil** — À l'atteinte du seuil, le **mode configuré par espace** s'applique : **blocage des entrées** (refus) **ou** **alerte sans blocage** (US-L3-05, décision actée).
- **Décrément & recalage** — La jauge **décrémente à chaque sortie** et **se recale à l'ouverture/fermeture du site** (US-L3-05) et après une synchro (§4.6, RG-ACC : recalage FMI).
- ⚠ HYPOTHÈSE — Le **recalage à l'ouverture/fermeture** (remise à zéro quotidienne ? report d'une présence résiduelle ?) n'est pas détaillé ; hypothèse retenue : remise à zéro à l'ouverture, la présence physique repartant de 0. **À confirmer** (impact sécurité ERP).

### 4.6 Hors-ligne + resynchronisation (US-L3-07/08, RG-ACC-05)
- **RG-ACC-05 — Fonctionnement hors-ligne** — Un contrôleur **privé de réseau valide en local** à partir de sa **liste de révocation embarquée**, puis **synchronise ses passages** au retour du réseau. Le basculement **online/offline** est **automatique** et **signalé en supervision** (US-L3-07, écran A-03).
- **Liste de révocation embarquée** — Le contrôleur **embarque et applique** une liste de révocation **à jour** ; un support **révoqué embarqué** est **refusé même sans réseau** (US-L3-07, RG-ACC-07).
- **File de passages** — Les passages hors-ligne sont **validés localement et mémorisés** pour rejeu (US-L3-07).
- **Rejeu chronologique** — À la reconnexion, les passages différés sont **rejoués dans l'ordre horodaté d'origine** (US-L3-08 ; **décision actée** « rejeu chronologique ») ; les **gros lots** sont traités **par paquets** (décision actée).
- **Recalage FMI & crédits** — À l'issue du rejeu, les **crédits** et la **jauge FMI** sont **recalés sur l'état réel** (US-L3-08, décision actée « recalage de la FMI sur l'état réel »).
- **Détection de conflits** — Les conflits (**double décompte**, **révocation postérieure** au passage hors-ligne) sont **détectés et tracés** (US-L3-08). ⚠ HYPOTHÈSE — la **conduite de résolution** d'un conflit (ex. un passage hors-ligne validé sur un support révoqué entre-temps : annulation ? simple alerte ? blocage à la prochaine venue ?) et le **délai maximal de propagation** acceptable vers un contrôleur hors-ligne ne sont pas tranchés (cahier §8) → **à préciser au plan technique**.

### 4.7 Support perdu / volé — blocage & propagation (US-L3-09, RG-ACC-07)
- **RG-ACC-07 — Support bloqué** — Un support déclaré **perdu ou volé** passe au statut **bloqué** et **figure en liste de révocation** ; **tout scan est refusé**, y compris **hors-ligne**.
- **Blocage serveur immédiat** — Le blocage serveur est **immédiat** et refuse le support à **tout passage online** (US-L3-09 ; **décision actée** « blocage serveur immédiat »).
- **Propagation à la prochaine synchro** — La révocation est **ajoutée à la liste embarquée** et **propagée à la prochaine synchro** des contrôleurs (US-L3-09, décision actée « liste de révocation »).
- **Traçabilité & réversibilité** — La déclaration (**motif, agent, horodatage**) est **tracée** et **réversible** par un rôle habilité (US-L3-09).

### 4.8 Carte épuisée — refus & rechargement (US-L3-10, RG-ACC-02)
- **Refus explicite** — Un **crédit à zéro** provoque un **refus explicite « carte épuisée »** (US-L3-10, RG-ACC-02).
- **Proposition de rechargement** — Une **proposition de recharge** est présentée (**orientation caisse / borne / app**), **sans décompte** (US-L3-10 ; **décision actée** « refus + proposition de rechargement borne/caisse/app »). Le **rechargement effectif** relève de M2 (hors périmètre L3).
- **Trace** — L'événement est **journalisé** comme **refus pour crédit insuffisant** (US-L3-10).

### 4.9 Journal des passages (US-L3-11, écran A-05, RG-ACC-06)
- **RG-ACC-06 — Traçabilité source** — Chaque passage est **horodaté** (précision seconde) et **rattaché** (espace, contrôleur, support, droit) ; il constitue la **source unique** du comptage, de la **consommation comptable (M6)** et du **reporting (M7)**.
- **Contenu** — Chaque passage (**accepté, refusé, non nominatif, manuel**) est journalisé avec **horodatage, espace/contrôleur/équipement, support (vide si sans support), droit (vide si non nominatif), résultat (Validé/Refusé/Compté) et motif** (écran A-05).
- **Filtrage & export** — Le journal est **filtrable** par période, espace, équipement, type d'événement, et est **exportable** (US-L3-11).
- **Lecture seule** — Les entrées sont en **lecture seule** (US-L3-11) ; elles s'appuient sur le **journal d'audit append-only du socle** (`RG-SOCLE-07`) pour l'inaltérabilité.
- **Sans doublon hors-ligne** — Un passage enregistré hors-ligne apparaît au journal **après synchronisation, sans doublon** (cahier A-05, §4.6).

### 4.10 Supervision temps réel & contrôle mobile (US-L3-06, écrans A-03/A-04)
- **Vue live (A-03)** — La **jauge FMI par espace** (présence vs seuil, alerte visuelle watch/crit), le **flux des passages** (cumul du jour, distinct de la FMI), les **incidents** (refus, équipement en défaut, contrôleur hors service, seuil atteint) et le **statut réseau** (en ligne / hors-ligne / synchro en cours) **s'actualisent en direct** (US-L3-06).
- **Ouverture manuelle** — Un **franchissement forcé** est possible, **tracé** (agent, **motif requis**, horodatage) et attribué à l'agent (US-L3-06, cahier A-03).
- **Passage sans support** — Bouton **« +1 »** de comptage non nominatif (§4.4).
- **Contrôle mobile (A-04)** — Un encadrant contrôle en **mobilité** (scan QR/RFID smartphone/PDA, **coupe-file** hors tourniquet fixe, passage sans support, **validation de séance** rattachée à un créneau/activité M5). Un passage validé en mobilité **alimente la même jauge et le même journal** qu'un tourniquet fixe (cahier A-04).
- **Robustesse d'affichage** — La bascule hors-ligne d'un contrôleur est **signalée sans dégrader** l'affichage des autres (cahier A-03).

### 4.11 Sous-réseau & accès fédéré (US-L3-12)
- **Sous-réseau** — Un **sous-réseau** regroupe **plusieurs espaces/contrôleurs** partageant des **règles d'accès** communes (US-L3-12).
- **Fédération configurable** — La **fédération** est **activable/désactivable** et **précise les droits éligibles** ; le **franchissement inter-entités** repose sur des **accords de reconnaissance mutuelle** (**décision actée** « sous-réseau / accès fédéré configurable »). Le sous-réseau du produit est **paramétré en amont par M1** (facette « sous-réseau », RG-M1 ; cf. `spec-offre.md` « sous-réseau » replié en Avancé).
- **Application des règles** — Un **passage fédéré** applique la **jauge FMI** et l'**anti-passback** du **sous-réseau concerné** (US-L3-12).
- ⚠ HYPOTHÈSE — **FMI de sous-réseau vs FMI d'espace** : RG-ACC-04 et l'écran A-01 posent la FMI **par espace** ; US-L3-12 évoque une jauge/anti-passback **« du sous-réseau »**. L'articulation (FMI agrégée du sous-réseau **en plus** de la FMI d'espace ? anti-passback partagé entre entités ?) n'est pas tranchée → **à cadrer avec M1 et la sécurité ERP**.

## 5. Objets de données
Les types PHP sont indicatifs (spec = comportement observable). Tout objet est rattaché à un **Établissement/Espace** via le socle (`RG-SOCLE-01`) ; identifiants = **UUID** (constitution §3). Les objets M1 (Produit, Formule, CarteMultiEntrées, marges/compostages) et M2 (Billet/Support émis, statut d'appairage) sont **référencés, non redéfinis**.

| Objet | Champ | Type | Contraintes | Notes |
|---|---|---|---|---|
| **EspaceAccès** | id | uuid | PK | rattaché à Établissement/Espace socle (RG-SOCLE-01) |
| | nom | string | requis | cahier §4 |
| | seuilFmi | int ≥ 0 | requis | seuil par espace (RG-ACC-04, A-01) |
| | modeSeuil | enum {blocage, alerte} | défaut paramétré | décision actée (par espace) |
| | présenceCourante | int ≥ 0 | dérivé (entrées − sorties) | jauge FMI (RG-ACC-04) |
| | antiPassbackDélai | duration | défaut ~5 min | réglé espace, surchargeable équipement (US-L3-01) |
| **Contrôleur** | id, nom | uuid, string | requis | RG-ACC-05 |
| | itbox | ref ITBOX | requis | concentrateur IT Cotation |
| | espace | ref EspaceAccès | requis | 1 espace |
| | état | enum {en_ligne, hors_ligne, hors_service} | défaut = en_ligne | cycle §4.6, cahier §6 |
| **Équipement** | id | uuid | PK | 1 Contrôleur |
| | type | enum {tourniquet, tripode, lecteur} | requis | iDTRONIC / QR / RFID |
| | sens | enum {entrée, sortie, bidirectionnel} | requis | impact FMI ±1 (RG-ACC-04, US-L3-01) |
| | antiPassback | {actif:bool, délai:duration?} | surcharge de l'espace | A-01, décision actée |
| | margeAvance, margeRetard | int (min) | ≥ 0 | tolérance créneau (A-01) ⚠ combinaison M1 |
| **DroitAccès** | id | uuid | PK | **projection** d'un droit vendu M1/M2 |
| | source | ref (Billet/Abonnement/Carte M1-M2) | requis | non redéfini ici (RG-M1-03/04) |
| | type | enum {billet, abonnement, carte_quota} | — | pilote le décompte |
| | fenêtreValidité | {début, fin} | — | marges appliquées (RG-ACC-01) |
| | créditRestant | int ≥ 0 | pour carte/quota | décrémenté au passage (RG-ACC-02) |
| | sousRéseau | ref SousRéseau? | optionnel | hérité du produit M1 |
| **Support** | id, identifiant | uuid, string | identifiant unique lu par lecteur | QR/RFID/wallet (A-02) |
| | type | enum {QR, RFID, wallet} | requis | RFID = bracelet étanche piscine |
| | statut | enum {actif, bloqué} | défaut = actif | bloqué si perte/vol (RG-ACC-07) |
| **Appairage** | id, date | uuid, datetime | requis | 1 Support ↔ 1 DroitAccès |
| | mode | enum {caisse, autonome} | requis | autonome = borne (A-02) |
| | actif | bool | 1 seul actif par support | unicité (US-L3-02) |
| **Passage** | id | uuid | PK | source unique (RG-ACC-06) |
| | horodatage | datetime | requis, précision seconde | A-05 |
| | espace, contrôleur, équipement | ref | requis | point de franchissement |
| | support | ref Support? | vide si sans support | RG-ACC-03 |
| | droit | ref DroitAccès? | vide si non nominatif | RG-ACC-03 |
| | sens | enum {entrée, sortie} | requis | impact FMI |
| | résultat | enum {validé, refusé, compté} | requis | §4.3/4.4, cahier §6 |
| | motif | string? | requis si refusé/manuel/non nominatif | US-L3-04/10, A-03 |
| | origineHorsLigne | bool | true si validé hors réseau | rejeu §4.6 |
| | idempotenceClé | string | unique | évite le doublon à la synchro (A-05) |
| **JaugeFmi** | espace | ref EspaceAccès | 1-1 | RG-ACC-04 |
| | seuil, valeurCourante | int | — | présents = entrées − sorties |
| | mode | enum {blocage, alerte} | par espace | décision actée |
| **ListeRévocation** | id | uuid | PK | RG-ACC-07 |
| | supports[] | ref Support[] (bloqués) | — | embarquée par contrôleur (US-L3-07) |
| | version / horodatage | int / datetime | — | propagée à la synchro (US-L3-09) |
| **FilePassagesHorsLigne** | contrôleur | ref Contrôleur | — | passages différés (US-L3-07) |
| | passages[] | ref Passage[] | ordre horodaté | rejeu chronologique (US-L3-08) |
| **SousRéseau / Fédération** | id, libellé | uuid, string | — | US-L3-12 |
| | espaces[] / contrôleurs[] | ref[] | ≥ 1 | périmètre partagé |
| | actif | bool | activable/désactivable | décision actée |
| | droitsÉligibles[] | ref[] | — | reconnaissance mutuelle |
| **DéclarationPerteVol** | id, support | uuid, ref | requis | US-L3-09 |
| | motif, agent, horodatage | string, ref Utilisateur, datetime | requis | tracée, réversible (RG-ACC-07) |

## 6. Critères d'acceptation
- **CA-1 (US-L3-01, RG-ACC-01)** — *Étant donné* la topologie, *quand* l'admin crée un équipement, *alors* il porte un **sens** (entrée/sortie/bidirectionnel) et est rattaché à un **contrôleur** puis à un **espace** ; l'anti-passback est **paramétrable au niveau espace et surchargeable au niveau équipement** ; marges et seuil FMI sont saisis et persistés ; *quand* la topologie est incohérente (équipement orphelin, sens manquant), *alors* l'enregistrement est **bloqué**.
- **CA-2 (US-L3-02)** — *Étant donné* un support et un droit (A-02), *quand* l'agent les appaire, *alors* le lien et le type de support sont enregistrés ; *quand* le support est **déjà appairé à un droit actif** ou **blacklisté**, *alors* l'appairage est **refusé avec message explicite** ; un support ne peut être appairé qu'à **un seul droit actif** à la fois.
- **CA-3 (US-L3-03, RG-ACC-01/02)** — *Étant donné* un passage, *quand* le système l'évalue, *alors* il n'est **accepté que si** le droit est valide, **dans les marges** et **sans violation d'anti-passback** ; le crédit est **décompté atomiquement** à l'acceptation ; chaque décision (accepté/refusé) est **horodatée et motivée** ; la réponse est rendue en **moins d'une seconde** en conditions nominales.
- **CA-4 (US-L3-03)** — *Étant donné* un re-scan du **même support avant le délai anti-passback**, *alors* le passage est **refusé**.
- **CA-5 (US-L3-04, RG-ACC-03)** — *Étant donné* une personne **sans support** (bébé/accompagnant), *quand* l'agent enregistre un **+1 non nominatif**, *alors* la **fréquentation et la jauge FMI s'incrémentent sans décompter de crédit**, le **motif** est saisi/tracé, et le passage apparaît **distinctement** au journal et en supervision.
- **CA-6 (US-L3-05, RG-ACC-04)** — *Étant donné* une jauge FMI, *quand* on la consulte, *alors* elle compte les **présents (entrées − sorties)** **indépendamment du cumul** ; *quand* le seuil est atteint, *alors* le **mode configuré par espace** s'applique (**blocage** des entrées **ou alerte** sans blocage) ; la jauge **décrémente à chaque sortie** et **se recale à l'ouverture/fermeture**.
- **CA-7 (US-L3-06)** — *Étant donné* la supervision, *quand* des passages/incidents surviennent, *alors* **jauge FMI, flux et incidents s'actualisent en direct** ; une **ouverture manuelle** est possible, **tracée** (agent, motif, horodatage) ; un **incident** (refus, matériel hors-ligne, seuil atteint) est **signalé visuellement**.
- **CA-8 (US-L3-07, RG-ACC-05/07)** — *Étant donné* une coupure réseau, *quand* un support se présente, *alors* le contrôleur **valide en local** à partir de sa **liste de révocation embarquée** et **mémorise le passage pour rejeu** ; un **support révoqué embarqué est refusé même sans réseau** ; le **basculement online/offline est automatique et signalé** en supervision.
- **CA-9 (US-L3-08, RG-ACC-05)** — *Étant donné* des passages hors-ligne, *quand* le réseau revient, *alors* ils sont **rejoués dans l'ordre horodaté** (par paquets pour les gros lots), les **crédits et la jauge FMI sont recalés** sur l'état réel, et les **conflits** (double décompte, révocation postérieure) sont **détectés et tracés**.
- **CA-10 (US-L3-09, RG-ACC-07)** — *Étant donné* un support déclaré **perdu/volé**, *alors* le **blocage serveur est immédiat** (refus à tout passage online), la révocation est **ajoutée à la liste embarquée et propagée à la prochaine synchro**, et la déclaration (motif, agent, horodatage) est **tracée et réversible** par un rôle habilité ; le support est refusé **y compris hors-ligne**.
- **CA-11 (US-L3-10, RG-ACC-02)** — *Étant donné* une carte à **crédit nul**, *quand* le porteur se présente, *alors* le passage est **refusé « carte épuisée »**, une **proposition de recharge** (caisse/borne/app) est présentée **sans décompte**, et l'événement est **journalisé comme refus pour crédit insuffisant**.
- **CA-12 (US-L3-11, RG-ACC-06)** — *Étant donné* le journal, *quand* on le consulte, *alors* **chaque passage** (accepté, refusé, non nominatif, manuel) est **horodaté et rattaché** (espace/contrôleur/équipement, support, droit, résultat, motif) ; il est **filtrable** (période, espace, équipement, type) et **exportable** ; les entrées sont en **lecture seule** ; un passage hors-ligne y apparaît **après synchro, sans doublon**.
- **CA-13 (US-L3-12)** — *Étant donné* un **sous-réseau** regroupant plusieurs espaces/contrôleurs, *quand* la **fédération est activée** avec ses **droits éligibles**, *alors* un support autorisé **franchit les contrôleurs fédérés** ; un **passage fédéré applique la jauge FMI et l'anti-passback du sous-réseau** ; *quand* la fédération est **désactivée**, *alors* le franchissement inter-entités est **refusé**.
- **CA-14 (RG-ACC-06, socle)** — *Étant donné* un passage validé, *alors* il **alimente une source unique** exploitée par M6 (compta) et M7 (reporting) ; *quand* on tente de **modifier/supprimer** une entrée du journal, *alors* c'est **impossible** (lecture seule, append-only `RG-SOCLE-07`).

## 7. Cas limites
- **Re-scan immédiat (anti-passback)** — Refusé avant le délai configuré (défaut ~5 min), surchargeable par espace/équipement (US-L3-01, décision actée).
- **Support perdu/volé hors-ligne** — Refusé grâce à la liste de révocation embarquée (RG-ACC-07) ; ⚠ **délai maximal de propagation** vers un contrôleur hors-ligne **non tranché** (cahier §8).
- **Carte épuisée** — Refus sec « carte épuisée » + proposition de recharge, sans décompte (US-L3-10, décision actée).
- **Personne sans support** — Comptée en FMI sans crédit ni droit (RG-ACC-03, décision actée piscine).
- **Dépassement FMI** — Blocage strict **ou** alerte selon le mode paramétré **par espace** (décision actée) ; en piscine, règle « une sortie = une entrée » + pré-alerte à X % (décision actée verticale, appliquée en aval).
- **Passage hors-ligne sur support révoqué entre-temps** — Conflit **détecté et tracé** au rejeu (US-L3-08) ; ⚠ **conduite de résolution non spécifiée** (annulation a posteriori ? blocage à la prochaine venue ?).
- **Double décompte au rejeu** — Détecté via clé d'idempotence par passage ; ⚠ mécanique de réconciliation crédit/FMI **à préciser au plan technique** (parallèle L2 §4.9).
- **Longue coupure / gros lot différé** — Rejeu **par paquets** (décision actée) ; ⚠ **volumétrie maximale** d'un lot **non fixée** (cahier §8).
- **Bascule hors-ligne d'un contrôleur** — Signalée sans dégrader l'affichage des autres (cahier A-03).
- **Recalage FMI à l'ouverture/fermeture** — ⚠ remise à zéro quotidienne vs report de présence résiduelle **à confirmer** (impact sécurité ERP).
- **Marges droit (M1) vs marges équipement** — ⚠ règle de combinaison **à arbitrer avec M1** (§4.1).
- **FMI de sous-réseau vs FMI d'espace** — ⚠ articulation **non tranchée** (§4.11, US-L3-12).
- **Billet annulé après impression (M2)** — Le support est **dévalidé côté Accès** ; L3 **consomme** la dévalidation émise par M2 (RG-M2-07, US-L2-09) : un support dévalidé rejoint la logique de refus.
- **Échec d'appairage** — ⚠ conduite (ré-essai / support de secours / ticket seul) **non spécifiée**, à préciser M2/L3 (§4.2, hérité L2 §4.6).
- **Utilisateur/agent sans affectation sur l'établissement de l'espace** — Aucun accès aux données/config (socle, `RG-SOCLE-05`).

## 8. Dépendances
- **Dépend de : socle L0** (`specs/L0-socle/spec-socle.md`) — hiérarchie Groupe/Région/Établissement/Espace (`RG-SOCLE-01`) à laquelle se rattachent espaces d'accès, contrôleurs et équipements ; permissions `module × action` réutilisées sur le module **`acces`** (`RG-SOCLE-02/03/04`) ; cadrage par **établissement actif** (`RG-SOCLE-05`) ; identité des agents (`RG-SOCLE-06`) ; **journal d'audit append-only** (`RG-SOCLE-07`) sur lequel s'appuie le journal des passages en lecture seule.
- **Dépend de : M1 · Offre & Tarification** (L1, `specs/L1-offre/spec-offre.md`) — **définit les droits d'accès** portés par les produits : formule à droits d'accès (RG-M1-03), **carte multi-entrées = stock de compostages** (RG-M1-04/13), nombre de compostages et **marges par défaut**, facette **sous-réseau**. L3 **consomme et décompte** ces droits ; ne les redéfinit pas.
- **Dépend de : M2 · Vente & Caisse** (L2, `specs/L2-vente/spec-vente.md`) — **émission du support** et **appairage à la vente** (RG-M2-04, US-L2-08), **BilletSupport** (identifiant QR/RFID/wallet, statut d'appairage), **dévalidation** d'un billet lors d'une annulation après impression (RG-M2-07, US-L2-09). L3 **prolonge** l'appairage (borne autonome, ré-appairage) et **consomme** les dévalidations.
- **Alimente (hors périmètre L3) :**
  - **M6 · Compta & Régie** — reçoit les **passages** comme base de la **reconnaissance à la consommation** (PCA) ; L3 ne valorise pas (RG-ACC-06).
  - **M7 · Reporting** — exploite les **passages** (fréquentation cumulée) et la **FMI** ; L3 n'analyse pas (RG-ACC-06).
  - **M5 · Planning & Réservation** — validation d'un accès **rattaché à un créneau/activité** (écran A-04) ; décompte réel des séances/quotas côté M5.
- **Interagit avec le matériel IT Cotation :** concentrateur **ITBOX**, tourniquets/tripodes **iDTRONIC**, lecteurs **QR/RFID**, logiciel **SmartAccess**. ⚠ **Protocoles à confirmer** : **OSDP** (lecteurs) et/ou **API** (ITBOX/SmartAccess) — voir points ouverts.

---

## Points ouverts / hypothèses (récapitulatif)
1. **⚠ PROTOCOLES MATÉRIEL iDTRONIC / ITBOX / SmartAccess — À CONFIRMER (priorité haute)** : le mode d'intégration (**OSDP** pour les lecteurs, **API** REST/temps réel de l'ITBOX/SmartAccess), le **contrat d'échange** (commande d'ouverture, remontée d'événement, heartbeat/état contrôleur), la **latence garantie** (< 1 s exigée, US-L3-03) et le **format de la liste de révocation embarquée** ne sont pas spécifiés dans les sources. À cadrer avec **IT Cotation** (fournisseur unique du matériel) avant le plan technique.
2. **⚠ HYPOTHÈSE — Noms des permissions** `acces × gerer / superviser / appairer / ouvrir_manuel / controler / bloquer_support / lire` : dérivés du tableau Acteurs & droits selon le modèle socle, à **figer avec M8** (§3).
3. **⚠ HYPOTHÈSE — Combinaison des marges** portées par le **droit/produit (M1)** et par l'**équipement (A-01)** : lequel prime, comment se combinent-elles (intersection retenue par défaut) — **à arbitrer avec M1** (§4.1).
4. **⚠ HYPOTHÈSE — Anti-passback : niveau de surcharge** : décision actée « surchargeable par **espace** » vs US-L3-01 « paramétrable espace **+ surchargeable équipement** ». La spec retient la **hiérarchie défaut système (~5 min) → espace → équipement** (le plus spécifique gagne) ; **à confirmer** (§4.1).
5. **⚠ HYPOTHÈSE — Recalage FMI à l'ouverture/fermeture** : remise à zéro quotidienne vs report d'une présence résiduelle non détaillé ; **impact sécurité ERP** — à confirmer (§4.5).
6. **⚠ HYPOTHÈSE — Résolution des conflits de resynchro** : conduite face à un passage hors-ligne validé sur un support **révoqué entre-temps** ou à un **double décompte**, **délai maximal de propagation** d'une révocation, **volumétrie maximale** d'un lot différé — non tranchés (cahier §8) ; à préciser au plan technique (§4.6, §7).
7. **⚠ HYPOTHÈSE — FMI de sous-réseau vs FMI d'espace** : RG-ACC-04/A-01 posent la FMI par **espace** ; US-L3-12 évoque une jauge/anti-passback « du **sous-réseau** ». Articulation (agrégation, partage inter-entités) à cadrer avec **M1** et la **sécurité ERP** (§4.11).
8. **⚠ HYPOTHÈSE — Échec d'appairage** : conduite (ré-essai / support de secours / ticket seul) non spécifiée, à préciser conjointement **M2/L3** (§4.2, hérité de L2 §4.6).
9. **⚠ HYPOTHÈSE — DroitAccès comme projection** : la spec modélise `DroitAccès` comme une **projection locale** (avec cache hors-ligne du crédit et de la fenêtre de validité) du droit vendu M1/M2, source de vérité restant côté M1/M2 ; réconciliation du **créditRestant** entre le décompte au tourniquet et le stock de compostages M1 **à préciser** (§5).
