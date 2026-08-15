# Spec — Verticale Salle de sport / Fitness (`Sport` / lot post-MVP, hors ordre L0→L7)

- **Lot / module :** Verticale **Sport / Fitness** (V2, au-dessus du socle **M1–M8** déjà spécifié) —
  priorité produit : **anti-impayés couplé à l'accès**.
- **Stories couvertes :** **US-SPORT-01 à US-SPORT-11** — ⚠ **HYPOTHÈSE** : la verticale salle de sport
  est absente du `backlog.html` (aucun panel `p-l*` ne la couvre ; les lots documentés vont de L0 à L7
  et s'arrêtent à la piscine/back-office). Ces user stories sont **définies par cet agent** à partir du
  panel `p-sport` du cahier détaillé (§1 « Positionnement », §2 « Fonctions & écrans spécifiques »)
  et des décisions actées ★ « 🏋️ Salle de sport ». **À faire valider et numéroter officiellement** dans
  le backlog avant développement.
- **Règles de gestion :** RG-SPORT-01 à RG-SPORT-07 (cahier `cahier-detaille.html`, panel `p-sport`,
  §3) + décisions actées ★ « 🏋️ Salle de sport » (6 points, dont 2 marqués ✱ modifié par rapport à la
  recommandation initiale) + règles socle réutilisées : RG-ACC-01/02/04/05/07 (`spec-acces.md`),
  RG-M1-03/08/12 (`spec-offre.md`), RG-M2-04/07 (`spec-vente.md`), RG-M4-02/03 (`spec-crm.md`),
  RG-M6-02/03/04/10 (`spec-compta.md`), RG-SOCLE-01 à 07 (`spec-socle.md`).
- **Statut :** brouillon — ⚠ plusieurs points d'articulation inter-modules (SEPA récurrent, mandat) à
  confirmer avant figement (cf. §8 et récapitulatif final).

## 1. Objectif
Permettre à un club fitness de vendre un **abonnement récurrent engageant** (mensuel ou hebdomadaire,
prélevé par SEPA), de faire vivre son cycle complet (pause, résiliation, réengagement), et de coupler
**en continu** ce contrat au **droit d'accès physique** (module Accès, L3) : tant que l'adhérent paie,
il entre — dès qu'un impayé est confirmé, le badge est refusé automatiquement, sans intervention
humaine, avec un chemin de résolution en 1 clic qui restaure l'accès aussitôt l'encaissement confirmé.
C'est ce **couplage statut de paiement ↔ droit d'accès**, fonctionnant y compris **24/7 sans personnel
et hors-ligne**, qui constitue le différenciateur métier de la verticale (cahier §1, §2 « Moteur
anti-impayés — critique »).

## 2. Périmètre
- **Inclus :**
  - **Souscription d'un abonnement fitness récurrent** : périodicité (mensuel/hebdomadaire), formule
    (illimité / cours inclus), engagement paramétrable (ex. 12 mois), mandat SEPA à la souscription —
    US-SPORT-01, cahier §2 « Abonnement récurrent & cycle de vie ».
  - **Pause / suspension** de l'abonnement : gel de l'échéancier, report de la fin d'engagement —
    US-SPORT-02, RG-SPORT-05.
  - **Résiliation** avec préavis paramétrable, motif, date d'effet, articulation avec l'engagement en
    cours — US-SPORT-03, RG-SPORT-06/07.
  - **Réengagement** d'un ancien adhérent résilié, toujours via un **nouveau mandat SEPA** (décision
    actée) — US-SPORT-04.
  - **Moteur anti-impayés** : détection du rejet SEPA → représentation(s) paramétrables → recouvrement
    → **refus de badge** (moment paramétrable) — US-SPORT-05/06, RG-SPORT-01/02.
  - **Résolution de l'impayé en 1 clic** (paiement CB dans l'app membre) et **restauration automatique**
    de l'accès dès l'encaissement confirmé — US-SPORT-07, RG-SPORT-03.
  - **Couplage statut de paiement ↔ droit d'accès du module Accès (L3)** : le contrôleur d'accès
    vérifie le statut en **local/hors-ligne**, sans dépendre de la disponibilité du serveur —
    US-SPORT-08, RG-SPORT-04.
  - **Accès nocturne 24/7 sans personnel sécurisé** : vidéo, bouton SOS, détection de présence
    isolée, limite d'occupation — US-SPORT-09, décision actée.
  - **Écran de gestion des mandats SEPA** côté club (création/signature, RUM, statut, révocation) —
    US-SPORT-10.
  - **Tableau de bord impayés** : file des rejets par statut, badges refusés en cours, taux de
    résolution en self-service — US-SPORT-11.
- **Exclu (pour l'instant), que la verticale *référence* seulement :**
  - Le **moteur de catalogue/formule/engagement générique** (types de produit, grille tarifaire,
    facette `sepa` bool + jour de prélèvement, quota de services inclus) → **M1** (`spec-offre.md`,
    RG-M1-03/08/12). La verticale **instancie** une Formule fitness au-dessus de M1, elle ne
    redéfinit pas le moteur de catalogue.
  - Le **mécanisme générique de contrôle d'accès** (topologie espace/contrôleur/équipement, appairage
    support↔droit, validation au tourniquet, jauge FMI, hors-ligne/synchro/liste de révocation,
    journal des passages) → **L3 Accès** (`spec-acces.md`, RG-ACC-01 à 07). La verticale **concrétise**
    ces mécanismes pour l'accès 24/7 fitness (statut d'abonnement comme condition du `DroitAccès`),
    elle ne les redéfinit pas.
  - L'**exécution du prélèvement bancaire lui-même** (SEPA Direct Debit, remise à la banque, retour
    normalisé des rejets), l'**écriture comptable** de l'encaissement/impayé et la **reconnaissance
    PCA** (compte 487, abonnement = prorata temporis) → **M6** (`spec-compta.md`, RG-M6-02/03/04).
    ⚠ voir §8 — le périmètre M6 actuellement spécifié (10 US, « profil régie ») ne couvre **pas** de
    moteur de collecte SEPA récurrente privée ; la verticale porte donc ici les objets du **cycle
    métier** (échéance, rejet, représentation) en attendant que M6 les exécute techniquement.
  - Le **mandat SEPA en tant qu'objet bancaire** (RIB, RUM, signature) référencé par M1 (facette
    `sepa` de la Formule) et par M4 (rattaché au payeur de la famille) → la verticale **réutilise** cet
    objet, en **précise l'usage fitness** (1 mandat = 1 abonnement) sans le redéfinir intégralement
    (⚠ HYPOTHÈSE, cf. §8).
  - La **fiche client/famille, payeur ≠ bénéficiaire, PMV** → **M4/CRM** (`spec-crm.md`,
    RG-M4-02/03). La verticale **consomme** l'adhérent (bénéficiaire) et son payeur, ne les redéfinit
    pas.
  - Le **planning des cours collectifs, la réservation, la liste d'attente** → **M5 Planning &
    Réservation** (référencé par le cahier §1, non encore spécifié dans ce dépôt — cf. même hypothèse
    que `spec-piscine.md`). La verticale ne modélise pas le calendrier.
  - L'**app membre** en tant que **produit front** (suivi de perf, gamification, historique, badge
    dématérialisé, coaching) : la verticale spécifie le **comportement observable** que l'app doit
    exposer pour l'anti-impayés (résolution 1 clic) et l'accès (badge dématérialisé consommé par L3),
    pas l'ergonomie ni les écrans eux-mêmes.
  - L'**UI (front)** ; l'**authentification, les rôles/permissions, le journal d'audit** → **socle L0**
    (`spec-socle.md`), réutilisés et non redéfinis ici.

## 3. Acteurs & droits
Les permissions réutilisent le modèle du socle (`RG-SOCLE-02/03/04/05`) : couple `module × action` sur
le module **`sport`**, portées par l'**établissement actif** ; l'UI **masque** ce qui n'est pas
autorisé (`RG-SOCLE-04`). Source : cahier `p-sport` §1/§2 (aucun tableau « Acteurs & droits » dédié
dans le cahier pour cette verticale — le découpage ci-dessous est **dérivé** du texte fonctionnel).

| Acteur | Peut | Ne peut pas | Permission (module × action) |
|---|---|---|---|
| **Gestionnaire de club / Agent d'accueil** | Souscrire un abonnement, valider/refuser une demande de pause ou de résiliation, déclarer un motif légitime justifié, forcer une réouverture de badge (motif requis), consulter le tableau de bord impayés | Modifier la politique anti-impayés (paramètres) ; révoquer un mandat sans motif tracé | `sport × gerer_abonnement`, `sport × piloter_impayes`, `sport × forcer_acces`, `sport × lire` |
| **Adhérent (app membre)** | Consulter son abonnement/échéancier, demander une pause, demander une résiliation, résoudre un impayé en 1 clic (CB), consulter son statut d'accès, déclencher le bouton SOS en salle | Modifier la politique de l'établissement ; accéder aux données d'un autre adhérent ; forcer une ouverture de badge | `sport × lire_soi`, `sport × resoudre_impaye_soi`, `sport × pause_demander_soi`, `sport × resilier_demander_soi` |
| **Administrateur** | Paramétrer la **politique anti-impayés** (nombre de représentations, moment du refus de badge, délais de recouvrement/suspension), paramétrer l'**engagement/préavis** par défaut, configurer l'**accès nocturne** (vidéo, SOS, limite d'occupation) | Modifier une écriture comptable déjà générée (M6) | `sport × parametrer`, `sport × configurer_nocturne`, `sport × gerer_abonnement` (surensemble) |
| **Comptable / Régisseur** (réutilise `compta` de L4) | Consulter l'échéancier SEPA, les rejets, les représentations, rapprocher les mouvements avec M6 | Modifier le statut d'un abonnement ou déclencher un refus de badge manuellement | `compta × lire`, `sport × lire` |
| **Système** | Détecter un rejet, programmer/exécuter une représentation selon le calendrier, basculer un dossier en recouvrement, propager le statut d'accès au module L3, restaurer l'accès à l'encaissement confirmé, calculer les reports de pause/engagement | Décider hors des règles paramétrées (aucune dérogation automatique) | *(acteur technique — pas de permission humaine)* |

- ⚠ HYPOTHÈSE — Les noms de permissions `sport × …` ne sont **pas nommés littéralement** dans les
  sources (le cahier ne fournit pas de tableau « Acteurs & droits » pour la verticale sport, à la
  différence des modules M1-M8 et du module Accès) ; découpage dérivé du texte fonctionnel §1/§2 du
  panel `p-sport`, **à arbitrer avec M8** comme pour les autres modules.
- ⚠ HYPOTHÈSE — Le rôle « Adhérent » réutilise vraisemblablement le même compte que le **Client final**
  de M4/M3 (espace personnel) ; la verticale ne crée pas un second système d'identité, elle **ajoute**
  des permissions `sport × …_soi` à ce compte existant.

## 4. Comportements & règles
Chaque comportement trace une **RG-SPORT** (source : `cahier-detaille.html`, panel `p-sport`) et/ou une
**décision actée** ★ « 🏋️ Salle de sport » (source : `cahier-detaille.html`, panel `p-decisions`, qui
**fait foi** et n'est pas re-tranchée, constitution §6). Les **US-SPORT** sont des **hypothèses** de
découpage (cf. en-tête).

### 4.1 Abonnement récurrent & cycle de vie (US-SPORT-01, cahier §1/§2)
- Un **AbonnementFitness** instancie une **Formule** d'abonnement du socle M1 (`RG-M1-03`, périodicité,
  droits d'accès, services inclus) en fixant : **périodicité** `mensuel` ou `hebdomadaire`, **formule**
  `illimité` ou `cours inclus` (services décomptés en semaine calendaire, `RG-M1-12`), **engagement**
  paramétrable (ex. 12 mois) avec **date de fin d'engagement**, et un **mandat SEPA** signé à la
  souscription (cahier §2, carte « Abonnement récurrent »).
- **Champs clés** exposés au club et à l'adhérent : statut, date de souscription, fin d'engagement,
  préavis, prochaine échéance (cahier §2).
- L'abonnement porte un **échéancier** (dates + montants prévus des prélèvements), généré à la
  souscription selon la périodicité et projeté jusqu'à la fin d'engagement (au minimum).
- **Bénéficiaire ≠ payeur** — L'abonnement est porté par le **bénéficiaire** (adhérent), le mandat SEPA
  et le paiement sont rattachés au **payeur** de la famille (réutilise `RG-M4-02`, `spec-crm.md`) ; un
  adhérent peut être son propre payeur.
- ⚠ HYPOTHÈSE — Le cahier ne précise pas si la **souscription** exige un droit `sport × gerer_abonnement`
  côté club exclusivement, ou si une **souscription en ligne autonome** (app membre, sans agent) est
  possible dès ce lot ; par cohérence avec la décision M3 « achat invité autorisé, compte imposé pour
  l'abonnement/SEPA » (cahier ★ M3), la spec retient que la souscription **peut** être initiée en ligne
  par l'adhérent (compte obligatoire, signature du mandat en ligne), à **confirmer**.

### 4.2 Pause / suspension (US-SPORT-02, RG-SPORT-05)
- **RG-SPORT-05** — La **pause/suspension gèle l'échéancier SEPA** et **reporte la date de fin
  d'engagement** de la durée de la pause ; **aucun prélèvement n'est émis pendant la pause**.
- **Décision actée** — Une pause est **bloquée** tant qu'un **impayé n'est pas régularisé** (« Pause
  pendant un impayé : bloquée tant que l'impayé n'est pas régularisé »). Un abonnement au statut
  `impayé` (§4.5) ne peut donc **jamais** entrer en pause tant que l'`IncidentPrelevement` associé n'est
  pas au statut `résolu`.
- La pause a une **date de début** et soit une **durée**, soit une **date de fin** ; à son terme,
  l'échéancier reprend automatiquement et la **fin d'engagement** est recalculée = fin d'engagement
  initiale + durée de la pause.
- Le **droit d'accès** pendant la pause : ⚠ HYPOTHÈSE — le cahier ne précise pas explicitement si un
  abonnement en pause conserve un accès (ex. accès ponctuel payant) ou est **inactif** au sens de
  l'accès (RG-SPORT-04) ; la spec retient par défaut qu'une pause **suspend l'accès** au même titre
  qu'un impayé (statut `pause` traité comme `inactif` par le module Accès), **à confirmer** avec le
  métier (une salle pourrait vouloir autoriser un accès résiduel payant à l'entrée pendant une pause).

### 4.3 Résiliation (US-SPORT-03, RG-SPORT-06/07)
- **RG-SPORT-06** — La résiliation respecte un **préavis paramétrable** ; la **date d'effet** est
  calculée à partir de la **date de la demande + préavis**, et le **mandat SEPA est révoqué à cette
  date** (pas avant : les prélèvements du préavis restent dus).
- **RG-SPORT-07** — Une résiliation demandée **avant la fin d'engagement** applique la **règle
  d'engagement configurée**, **sans possibilité de contournement automatique**.
- **Décision actée** — « Résiliation en engagement : **bloquée jusqu'à l'échéance**, sauf **motif
  légitime justifié**. » Cette décision **précise** RG-SPORT-07 : par défaut la résiliation en cours
  d'engagement est **refusée** (le contrat continue jusqu'à la fin d'engagement) ; une **dérogation**
  n'est possible que sur **motif légitime** (ex. déménagement, certificat médical) **justifié** par une
  pièce et **validé par un rôle habilité** (Gestionnaire de club / Administrateur).
  - ⚠ HYPOTHÈSE — La **liste des motifs légitimes recevables** et le **format du justificatif**
    (upload, contrôle) ne sont pas fournis par les sources ; la spec retient un **motif texte libre +
    pièce jointe optionnelle**, avec **validation manuelle obligatoire** (pas d'auto-approbation) —
    à cadrer avec le métier avant implémentation (cahier §5 « Qui valide le motif ? » reste ouvert au
    niveau du **détail procédural**, la décision de principe — blocage + dérogation motivée — est en
    revanche actée).
- Un dossier de résiliation porte : demande, motif, date de demande, préavis appliqué, date d'effet,
  statut (`en_préavis`, `effective`, `refusée`).
- Pendant le **préavis**, l'abonnement reste **actif** (accès et prélèvements continuent normalement,
  sauf impayé concurrent) ; à la **date d'effet**, le statut passe à `résilié`, le mandat SEPA est
  **révoqué**, et le droit d'accès devient **inactif** (propagation §4.7).

### 4.4 Réengagement d'un résilié (US-SPORT-04)
- **Décision actée** — « Réengagement d'un résilié : **toujours un nouveau mandat SEPA**. » ✱ *(diffère
  de la recommandation initiale, qui envisageait la réutilisation d'un ancien mandat non révoqué)*.
  Cette décision **tranche définitivement** la question ouverte du cahier §5 (« réutilise-t-on
  l'ancien mandat SEPA si non révoqué, ou en signe-t-on obligatoirement un nouveau ? ») : même si un
  mandat antérieur techniquement non révoqué existait encore, un **nouveau mandat est systématiquement
  requis** au réengagement.
- Le réengagement crée un **nouvel `AbonnementFitness`**, distinct du précédent (traçabilité de
  l'historique), avec un **nouvel engagement** (durée reprise à zéro) et un **nouvel échéancier**.
  L'ancien abonnement résilié reste consultable en historique, non réactivé.
- Cahier §2 : le réengagement est pensé comme une action « **en un clic** » côté club pour un ancien
  adhérent — ce qui suppose la **pré-création** du nouvel abonnement à partir des paramètres de
  l'ancien (formule, périodicité), **mais** avec **signature effective d'un nouveau mandat** avant
  activation (l'accès ne peut être restauré tant que le nouveau mandat n'est pas signé).

### 4.5 Moteur anti-impayés — cycle complet (US-SPORT-05/06, RG-SPORT-01/02) `critique`
Le cœur différenciateur de la verticale (cahier §2 : « de la détection du rejet à la résolution en 1
clic par l'adhérent ») :

```
Rejet SEPA détecté → Représentation SEPA → Recouvrement + refus badge → Résolution app 1 clic
```

- **RG-SPORT-01** — Tout **impayé détecté** déclenche **automatiquement** une **représentation SEPA**
  selon un **calendrier paramétrable**. **Aucun refus d'accès n'est prononcé tant que la représentation
  n'a pas échoué** *(règle de principe posée par le cahier)*.
- **RG-SPORT-02** — Si la représentation SEPA **échoue**, le dossier bascule en **recouvrement** et le
  **badge est refusé** avec une **notification immédiate** à l'adhérent.
- **Décision actée — Échec répété du prélèvement** — « **Représentations paramétrables** + **refus de
  badge** (moment paramétrable, **possible dès le 1ᵉʳ échec**) puis recouvrement/suspension. »
  ✱ *(diffère de la recommandation initiale)*.
  - **Réconciliation RG-SPORT-01 ↔ décision actée** — RG-SPORT-01 pose comme **règle par défaut** que
    le refus de badge n'intervient **qu'après échec de la représentation**. La **décision actée**
    l'**assouplit et la rend paramétrable** : un établissement peut configurer un **refus de badge dès
    le 1ᵉʳ échec** de prélèvement (avant même toute tentative de représentation), si sa politique
    commerciale le souhaite. La spec retient donc un paramètre `PolitiqueAntiImpayes.momentRefusBadge`
    à **3 valeurs possibles** : `après_1ᵉʳ_échec`, `après_représentation_échouée` (comportement par
    défaut cohérent avec RG-SPORT-01), `après_N_représentations_échouées` (N paramétrable). ⚠
    HYPOTHÈSE — la **valeur par défaut** de ce paramètre à l'installation d'un établissement n'est pas
    fixée par les sources ; retenue par défaut = `après_représentation_échouée` (comportement le moins
    agressif, cohérent avec le libellé « par défaut » de RG-SPORT-01), **à confirmer** au paramétrage
    produit.
  - Le **nombre de représentations** avant bascule en recouvrement est **paramétrable** (≥ 0 ; 0 =
    aucune représentation, refus immédiat possible selon le paramètre ci-dessus).
  - Après épuisement des représentations, le dossier bascule en **recouvrement** ; au-delà d'un délai
    paramétrable supplémentaire sans régularisation, une **suspension du contrat** peut être déclenchée
    (cahier §5 : « après combien de représentations infructueuses bascule-t-on définitivement en
    recouvrement et/ou en suspension du contrat ? » — la spec retient un **second seuil paramétrable**
    distinct du refus de badge, `PolitiqueAntiImpayes.delaiAvantSuspensionContrat`, ⚠ HYPOTHÈSE de
    modélisation, valeur non fournie par les sources).
- **Notification** — Chaque étape (rejet détecté, représentation programmée, échec, refus de badge,
  résolution) déclenche une **notification** à l'adhérent (canal app/email/SMS, réutilise les canaux
  M4, `RG-M4-07`).
- **Traçabilité** — Chaque `IncidentPrelevement` et chaque `RepresentationSepa` sont **horodatés et
  tracés** (réutilise `RG-SOCLE-07`).

### 4.6 Résolution en 1 clic & restauration de l'accès (US-SPORT-07, RG-SPORT-03)
- **RG-SPORT-03** — L'adhérent peut **résoudre l'impayé en 1 clic** dans l'app (paiement CB) ; l'**accès
  est restauré automatiquement dès l'encaissement confirmé**.
- Le paiement de régularisation est un **encaissement immédiat** (CB, hors cycle SEPA), distinct du
  prochain prélèvement récurrent qui reprend normalement à l'échéance suivante.
- La **confirmation de l'encaissement** met à jour `IncidentPrelevement.statut = résolu` et
  `canalRésolution = app_1_clic`, ce qui **repasse `AbonnementFitness.statut` à `actif`** — et
  déclenche la propagation vers le module Accès (§4.7).
- ⚠ HYPOTHÈSE — Le **prestataire d'encaissement CB en ligne** pour cette régularisation n'est pas
  nommé par les sources (à la différence du PayFiP décrit côté M6 pour la régie publique, non
  pertinent pour un club fitness privé) ; à préciser au plan technique (PSP carte bancaire classique).

### 4.7 Couplage statut de paiement ↔ droit d'accès (US-SPORT-08, RG-SPORT-04) — cœur du différenciateur
- **RG-SPORT-04** — Le contrôle d'accès 24/7 vérifie le **statut d'abonnement en local** et continue de
  fonctionner **hors-ligne** ; la **décision d'ouverture ne dépend jamais de la disponibilité du
  serveur**.
- **Mécanique de couplage** — L'`AbonnementFitness` **projette** un statut d'accès binaire
  (`actif`/`inactif`) sur le `DroitAccès` du module L3 (`spec-acces.md`, §5) qui porte l'adhérent :
  - `AbonnementFitness.statut = actif` → `DroitAccès.actif = true`.
  - `AbonnementFitness.statut ∈ {impayé (avec badge refusé), pause, résilié}` → `DroitAccès.actif =
    false`, ce qui produit un **refus explicite au tourniquet** (réutilise le mécanisme générique de
    refus de L3, cf. `spec-acces.md` §4.3, avec un **motif spécifique** : « abonnement impayé » /
    « abonnement en pause » / « abonnement résilié »).
  - Tant que le statut est `impayé` mais que le **badge n'est pas encore refusé** (représentation en
    cours, avant échec — §4.5), l'accès **reste actif** (RG-SPORT-01).
- **Local & hors-ligne** — Le statut projeté est **mis en cache** par le contrôleur (réutilise le
  mécanisme générique hors-ligne de L3, `RG-ACC-05` : liste de révocation/statuts embarquée, décision
  online/offline autonome). Un abonnement passant `inactif` pendant que le contrôleur est hors réseau
  continue d'**autoriser** l'accès jusqu'à la **prochaine synchronisation** (comportement générique
  L3), sauf si le support est explicitement **révoqué** (cas perte/vol, `RG-ACC-07`, propagation
  immédiate côté serveur + prochaine synchro côté contrôleur — distinct d'un simple changement de
  statut d'abonnement).
- **Restauration d'accès hors-ligne** — **Décision actée** : « Propagation du nouveau statut à la
  **prochaine synchro** ; **re-badge possible**. » Après régularisation (§4.6), si le contrôleur au
  point d'accès habituel de l'adhérent est resté hors-ligne, le statut restauré ne sera **appliqué
  localement qu'à la prochaine synchronisation** de ce contrôleur ; en attendant, le support reste
  refusé **localement** (cache non rafraîchi) même si le serveur le sait déjà réactivé. Un **nouveau
  passage de badge après la synchro** ("re-badge") est alors accepté normalement — aucune procédure
  manuelle supplémentaire n'est requise côté adhérent.
- ⚠ HYPOTHÈSE — Le **délai maximal** de propagation d'une restauration vers un contrôleur hors-ligne
  n'est pas chiffré par les sources (déjà signalé comme point ouvert générique en L3, `spec-acces.md`
  §7) ; à cadrer techniquement (fréquence de synchro minimale garantie pour les sites 24/7).

### 4.8 Accès nocturne autonome sécurisé (US-SPORT-09)
- **Décision actée — Accès nocturne sans personnel** : « Vidéo + **bouton SOS** + **détection de
  présence isolée** + **limite d'occupation**. »
- **Vidéosurveillance** — Un espace fitness en accès nocturne 24/7 est équipé d'un **enregistrement
  vidéo** actif pendant les plages sans personnel (⚠ HYPOTHÈSE — modalités de conservation/accès aux
  images non détaillées dans les sources, relèvent de la conformité RGPD/vidéoprotection, à cadrer avec
  un référent conformité, cf. `RG-M8-08`/registre RGPD du socle M8).
- **Bouton SOS** — Un dispositif d'alerte est **accessible physiquement dans l'espace** ; son
  déclenchement crée un `EvenementSOS` horodaté et **notifie immédiatement** un canal d'astreinte
  (⚠ HYPOTHÈSE — le destinataire exact de l'alerte — call center, astreinte club, service d'urgence —
  n'est pas précisé par les sources, à définir contractuellement par établissement).
- **Détection de présence isolée** — Un mécanisme (capteur de mouvement/comptage entrées-sorties
  couplé à la jauge FMI du module Accès, `RG-ACC-04`) **détecte** qu'un seul adhérent est présent dans
  l'espace en horaire nocturne, condition à risque justifiant une **vigilance renforcée** (ex.
  affichage supervision, seuil d'alerte différent) — ⚠ HYPOTHÈSE : le comportement exact déclenché par
  cette détection (simple signalement en supervision ? notification proactive à l'adhérent isolé ?
  restriction d'accès à un second adhérent isolé simultané ?) n'est pas détaillé par les sources.
- **Limite d'occupation** — Un **seuil paramétrable** d'occupation nocturne (distinct ou identique au
  seuil FMI diurne, `RG-ACC-04`) est appliqué en horaire nocturne ; au-delà, l'entrée est **bloquée**
  (réutilise le mode `blocage` de la jauge FMI de L3, cf. `spec-acces.md` §4.5).
- **Plages horaires par formule** — cahier §2 : « accès nuit réservé » à certaines formules — un
  `AbonnementFitness` peut ne **pas inclure** l'accès nocturne ; dans ce cas le `DroitAccès` associé
  porte une **fenêtre horaire restreinte** (réutilise `RG-ACC-01`, marges/fenêtre de validité du
  `DroitAccès`, hors nuit).
- ⚠ HYPOTHÈSE — La **responsabilité en cas d'incident** hors horaires encadrés (question ouverte du
  cahier §5) est un sujet **contractuel/assurantiel**, non un comportement observable du logiciel ; hors
  périmètre de cette spec fonctionnelle, à traiter par ailleurs (CGU/CGV, assurance de l'exploitant).

### 4.9 Gestion des mandats SEPA (US-SPORT-10, écran « Gestion des mandats »)
- **Création/signature du mandat** à la souscription (§4.1) ; le mandat porte **RUM, statut, date de
  signature** (cahier §2).
- **Révocation à la résiliation** — le mandat passe à `révoqué` à la **date d'effet** de la résiliation
  (§4.3, RG-SPORT-06), jamais avant.
- **Suivi côté club** — écran listant les mandats par statut (actif/révoqué), avec recherche par
  adhérent/RUM.
- ⚠ HYPOTHÈSE — L'objet `MandatSepaFitness` **duplique fonctionnellement** le concept de mandat SEPA
  déjà évoqué comme référencé (non défini) par `spec-offre.md` (facette `sepa` de la Formule) et par
  `spec-crm.md` (mandat rattaché au payeur, § Dépendances point 3) ; **aucune spec de ce dépôt ne
  définit aujourd'hui le mandat SEPA comme objet de référence unique**. La verticale sport en donne ici
  une définition **opérationnelle minimale** (RUM, IBAN, titulaire, statut) en cohérence avec le cahier
  `p-sport` §4, mais ce **doit être réconcilié** avec M1/M4/M6 en un objet socle partagé avant
  implémentation (cf. récapitulatif final, point 1).

### 4.10 Tableau de bord impayés (US-SPORT-11, écran « Tableau de bord impayés »)
- **Pilotage temps réel** : file des rejets **par statut** (représentation en cours / recouvrement),
  **nombre de badges refusés en cours**, **taux de résolution en self-service** (cahier §2).
- Filtrable par période, statut, et exportable (⚠ HYPOTHÈSE — l'export n'est pas explicitement cité
  pour cet écran par le cahier, mais cohérent avec le standard des écrans de pilotage du socle, ex.
  journal des passages L3 §4.9 ; à confirmer).
- Alimente/rapproche avec le suivi de l'échéancier SEPA exposé au **Comptable** (réutilise `compta ×
  lire`, §3).

## 5. Objets de données
Les types PHP sont indicatifs (spec = comportement observable). Tout objet est rattaché à un
**Établissement** via le socle (`RG-SOCLE-01`) ; identifiants = **UUID** (constitution §3). Les objets
socle référencés (Client/Famille/Beneficiaire M4, Formule/ServiceInclus M1, DroitAccès/Support/
EspaceAccès/JaugeFmi L3, EcritureComptable/MoyenPaiement M6) sont **référencés, non redéfinis**.

| Objet | Champ | Type | Contraintes | Notes |
|---|---|---|---|---|
| **AbonnementFitness** | id | uuid | PK | instancie une Formule M1 (RG-M1-03) |
| | adherent | ref Beneficiaire (M4) | requis | bénéficiaire ≠ payeur (RG-M4-02) |
| | payeur | ref Client (M4) | requis | porte le mandat SEPA |
| | formule | ref Formule (M1) | requis | droits d'accès + services inclus |
| | périodicité | enum {mensuel, hebdomadaire} | requis | cahier §2 |
| | statut | enum {actif, pause, impayé, résilié} | requis | pilote §4.7 |
| | dateSouscription | date | requis | — |
| | dateDébutEngagement, dateFinEngagement | date, date | fin ≥ début | reportée par les pauses (RG-SPORT-05) |
| | préavisRésiliation | duration | paramétrable, hérité de la Formule/établissement | RG-SPORT-06 |
| | prochaineÉchéance | date | dérivé | recalculé après pause/impayé |
| | mandatSepa | ref MandatSepaFitness | requis, 1:1 | révoqué à la résiliation |
| **EcheanceSepa** | id, abonnement | uuid, ref AbonnementFitness | requis | échéancier (dates + montants) |
| | dateProgrammée, montant | date, decimal > 0 | requis | projeté jusqu'à fin d'engagement mini |
| | statut | enum {à_venir, prélevée, rejetée, gelée} | requis | `gelée` pendant une pause (RG-SPORT-05) |
| **PolitiqueAntiImpayes** | id, établissement | uuid, ref Etablissement (socle) | 1 par établissement | paramétrable, §4.5 |
| | nbReprésentationsMax | int ≥ 0 | requis | RG-SPORT-01 |
| | calendrierReprésentation | liste de délais (jours) | requis si nbReprésentationsMax > 0 | — |
| | momentRefusBadge | enum {après_1ᵉʳ_échec, après_représentation_échouée, après_N_représentations_échouées} | requis | décision actée, ⚠ défaut à confirmer |
| | nReprésentationsAvantBadge | int ≥ 0 | requis si `après_N_représentations_échouées` | — |
| | delaiAvantSuspensionContrat | duration | paramétrable | ⚠ HYPOTHÈSE (cf. §4.5) |
| **IncidentPrelevement** (Impayé) | id, abonnement | uuid, ref AbonnementFitness | requis | RG-SPORT-01/02 |
| | échéanceOrigine | ref EcheanceSepa | requis | fait générateur |
| | montant | decimal > 0 | requis | — |
| | dateRejet | date | requis | — |
| | motifBancaire | string | requis | code retour SEPA (ex. AM04 fonds insuffisants) |
| | statut | enum {représentation, recouvrement, résolu} | requis | cycle §4.5 |
| | canalRésolution | enum {app_1_clic, virement, caisse, autre}? | requis si `résolu` | RG-SPORT-03 |
| | relances[] | liste (canal, date, résultat) | append-only | notifications §4.5 |
| **RepresentationSepa** | id, incident | uuid, ref IncidentPrelevement | requis | RG-SPORT-01 |
| | dateProgrammée, dateExécution | date, date? | requis / renseignée à l'exécution | calendrier paramétrable |
| | résultat | enum {en_attente, réussie, échouée} | requis | échec → recouvrement (RG-SPORT-02) |
| **StatutAccesFitness** (projection vers L3) | id, abonnement | uuid, ref AbonnementFitness | 1:1 | §4.7 |
| | droitAccès | ref DroitAccès (L3) | requis | `spec-acces.md` §5 |
| | actif | bool | dérivé du statut abonnement | RG-SPORT-04 |
| | motifInactivité | enum {impayé, pause, résiliation}? | requis si `actif = false` | motif affiché au refus (cf. L3 §4.3) |
| | dateDernièrePropagation | datetime | — | traçabilité synchro hors-ligne (§4.7) |
| **PauseAbonnement** | id, abonnement | uuid, ref AbonnementFitness | requis | US-SPORT-02, RG-SPORT-05 |
| | dateDébut, dateFin | date, date | fin ≥ début | — |
| | motif | string | optionnel | — |
| | statut | enum {active, terminée, refusée} | requis | `refusée` si impayé en cours (décision actée) |
| | reportEngagement | duration | = dateFin − dateDébut | reporte `dateFinEngagement` |
| **Resiliation** | id, abonnement | uuid, ref AbonnementFitness | requis | US-SPORT-03, RG-SPORT-06/07 |
| | dateDemande | date | requis | — |
| | motif | string | requis | — |
| | motifLégitime | bool | défaut = false | conditionne la dérogation à l'engagement |
| | justificatif | fichier? | optionnel | pièce à l'appui |
| | validéPar | ref Utilisateur? | requis si `motifLégitime = true` | rôle habilité |
| | préavisAppliqué | duration | requis | copié de l'abonnement à la demande |
| | dateEffet | date | = dateDemande + préavisAppliqué | RG-SPORT-06 |
| | statut | enum {en_préavis, effective, refusée} | requis | `refusée` = engagement non honoré, sans motif légitime |
| **Reengagement** | id, ancienAbonnement | uuid, ref AbonnementFitness (résilié) | requis | US-SPORT-04 |
| | nouvelAbonnement | ref AbonnementFitness | requis | nouveau cycle complet |
| | nouveauMandat | ref MandatSepaFitness | requis | **toujours nouveau** (décision actée) |
| | dateRéengagement | date | requis | — |
| **MandatSepaFitness** | id | uuid | PK | cahier §4 « MandatSEPA » |
| | rum | string | requis, unique | Référence Unique de Mandat |
| | iban, titulaire | string, string | requis | — |
| | dateSignature | date | requis | — |
| | statut | enum {actif, révoqué} | défaut = actif | révoqué à la résiliation (RG-SPORT-06) |
| | abonnementRattaché | ref AbonnementFitness | requis, 1:1 | rattachement (cahier §4) |
| **ConfigAccesNocturne** | id, espace | uuid, ref EspaceAccès (L3) | 1 par espace éligible 24/7 | US-SPORT-09 |
| | plageHoraireNocturne | {début, fin} | requis | définit « nuit » pour cet espace |
| | vidéoActive | bool | requis | décision actée |
| | boutonSosActif | bool | requis | décision actée |
| | détectionPrésenceIsoléeActive | bool | requis | décision actée |
| | limiteOccupationNocturne | int ≥ 0 | requis | peut différer du seuil FMI diurne (L3) |
| **EvenementSOS** | id, espace | uuid, ref EspaceAccès (L3) | requis | §4.8 |
| | horodatage | datetime | requis | — |
| | déclenchéPar | ref Support (L3)? | optionnel | anonyme possible |
| | statut | enum {ouverte, traitée} | défaut = ouverte | traçabilité incident |
| **AlertePresenceIsolee** | id, espace | uuid, ref EspaceAccès (L3) | requis | §4.8 |
| | horodatage | datetime | requis | dérivé de la jauge FMI nocturne (RG-ACC-04) |
| | nbPersonnesDétectées | int | = 1 (condition de déclenchement) | ⚠ HYPOTHÈSE comportement en aval |

## 6. Critères d'acceptation
- **CA-1 (US-SPORT-01, RG-M1-03)** — *Étant donné* une formule fitness (illimité, engagement 12 mois,
  périodicité mensuelle), *quand* un adhérent souscrit, *alors* un `AbonnementFitness` est créé au
  statut `actif`, un **mandat SEPA** est signé et rattaché, un **échéancier** mensuel est généré jusqu'à
  la fin d'engagement, et le **droit d'accès** correspondant est actif dès la souscription.
- **CA-2 (US-SPORT-02, RG-SPORT-05, décision actée)** — *Étant donné* un abonnement `actif`, *quand*
  l'adhérent demande une pause de 2 mois, *alors* l'échéancier est **gelé** pendant la pause, **aucun
  prélèvement** n'est émis, et la **fin d'engagement est reportée de 2 mois** ; *étant donné* un
  abonnement au statut `impayé`, *quand* une pause est demandée, *alors* elle est **refusée** tant que
  l'impayé n'est pas résolu.
- **CA-3 (US-SPORT-03, RG-SPORT-06/07, décision actée)** — *Étant donné* un abonnement en cours
  d'engagement (8 mois restants), *quand* l'adhérent demande une résiliation **sans motif légitime**,
  *alors* la résiliation est **bloquée jusqu'à l'échéance d'engagement** ; *quand* un **motif légitime
  justifié** est fourni et **validé** par un rôle habilité, *alors* la résiliation est **acceptée**, la
  **date d'effet** = date de demande + **préavis paramétrable**, et le **mandat SEPA** est **révoqué à
  cette date d'effet** (pas avant).
- **CA-4 (US-SPORT-04, décision actée)** — *Étant donné* un abonnement `résilié` (mandat révoqué),
  *quand* l'adhérent se réengage, *alors* un **nouvel `AbonnementFitness`** est créé avec un **nouvel
  engagement**, un **nouvel échéancier**, et un **nouveau mandat SEPA obligatoire** — même si un ancien
  mandat non révoqué existait encore.
- **CA-5 (US-SPORT-05, RG-SPORT-01)** — *Étant donné* un prélèvement rejeté, *quand* le rejet est
  détecté, *alors* un `IncidentPrelevement` est créé (statut `représentation`) et une
  `RepresentationSepa` est **programmée automatiquement** selon le calendrier paramétré ; *tant que* la
  représentation n'a pas échoué et que la politique n'impose pas un refus dès le 1ᵉʳ échec, *alors*
  l'**accès reste actif**.
- **CA-6 (US-SPORT-06, RG-SPORT-02)** — *Étant donné* une représentation SEPA en échec, *quand* le
  résultat est reçu, *alors* l'`IncidentPrelevement` bascule au statut `recouvrement`, le **badge est
  refusé** (`StatutAccesFitness.actif = false`, `motifInactivité = impayé`), et une **notification
  immédiate** est envoyée à l'adhérent.
- **CA-7 (US-SPORT-05/06, décision actée « refus dès le 1ᵉʳ échec »)** — *Étant donné* un établissement
  paramétré `momentRefusBadge = après_1ᵉʳ_échec`, *quand* le tout premier rejet SEPA est détecté (avant
  toute représentation), *alors* le **badge est refusé immédiatement**, en parallèle du déclenchement
  de la première représentation programmée.
- **CA-8 (US-SPORT-07, RG-SPORT-03)** — *Étant donné* un adhérent au badge refusé pour impayé, *quand*
  il régularise en 1 clic (CB) dans l'app, *alors* l'`IncidentPrelevement` passe à `résolu`
  (`canalRésolution = app_1_clic`), l'`AbonnementFitness` repasse à `actif`, et le **droit d'accès est
  restauré automatiquement** dès la confirmation de l'encaissement, **sans intervention d'un agent**.
- **CA-9 (US-SPORT-08, RG-SPORT-04)** — *Étant donné* un contrôleur d'accès **hors réseau**, *quand* un
  adhérent au statut `actif` se présente, *alors* l'accès est autorisé **sans dépendre du serveur**, sur
  la base du **statut mis en cache localement** ; *quand* le statut est `inactif` (impayé confirmé
  avant la coupure), *alors* l'accès est **refusé localement**, sans appel réseau.
- **CA-10 (décision actée « restauration hors-ligne »)** — *Étant donné* une régularisation confirmée
  côté serveur pendant qu'un contrôleur reste hors-ligne, *quand* la connexion revient, *alors* le
  **nouveau statut est propagé à la synchronisation**, et un **nouveau passage de badge** ("re-badge")
  après cette synchro est **accepté** normalement, sans procédure manuelle supplémentaire.
- **CA-11 (US-SPORT-09, décision actée « accès nocturne »)** — *Étant donné* un espace configuré en
  accès nocturne autonome, *quand* la plage nocturne est active, *alors* la **vidéo** enregistre, le
  **bouton SOS** est actif et son déclenchement crée un `EvenementSOS` horodaté et notifié, la
  **détection de présence isolée** signale toute occurrence à un seul adhérent présent, et
  l'**occupation** au-delà de la `limiteOccupationNocturne` **bloque** toute nouvelle entrée.
- **CA-12 (US-SPORT-10)** — *Étant donné* un abonnement résilié à sa date d'effet, *quand* la
  résiliation devient `effective`, *alors* le `MandatSepaFitness` associé passe au statut **révoqué**,
  visible sur l'écran de gestion des mandats.
- **CA-13 (US-SPORT-11)** — *Étant donné* le tableau de bord impayés, *quand* un gestionnaire le
  consulte, *alors* il visualise la **file des rejets par statut** (représentation/recouvrement), le
  **nombre de badges actuellement refusés**, et le **taux de résolution en self-service** (part des
  `IncidentPrelevement` résolus via `canal = app_1_clic`).
- **CA-14 (RG-M1-12, quota cours inclus, formule « cours »)** — *Étant donné* une formule « cours
  inclus » avec quota hebdomadaire, *quand* la semaine calendaire change (lundi), *alors* le quota se
  réinitialise **sans report**, cohérent avec `spec-offre.md` RG-M1-12 (référencé, non redéfini ici).

## 7. Cas limites
- **Impayé détecté pendant une pause déjà active** — ⚠ HYPOTHÈSE : les sources ne couvrent que le sens
  « impayé bloque la pause » (décision actée) ; le cas inverse (un prélèvement gelé pendant une pause
  ne peut, par construction, générer d'impayé) est **cohérent par conception** (RG-SPORT-05 : aucun
  prélèvement émis pendant la pause) — pas de conflit réel, mais à vérifier à l'implémentation
  (garde-fou : aucune `EcheanceSepa` ne doit être générée pour une période gelée).
- **Refus de badge paramétré dès le 1ᵉʳ échec, puis représentation qui réussit finalement** — le badge
  refusé doit-il être **automatiquement réactivé** si la représentation réussit malgré tout après coup ?
  ⚠ HYPOTHÈSE : la spec retient que **oui** (cohérent avec RG-SPORT-03 « restauration automatique dès
  l'encaissement confirmé », quel que soit le canal — représentation réussie ou paiement 1 clic) ; à
  confirmer que la réussite d'une représentation déclenche la **même** restauration que la résolution
  manuelle en app.
- **Motif légitime de résiliation contesté** — Pas de procédure d'appel/contre-validation décrite par
  les sources ; ⚠ HYPOTHÈSE : traité comme une simple décision du rôle habilité (Gestionnaire/
  Administrateur), sans workflow de contestation formalisé dans ce lot.
- **Résiliation demandée hors engagement (période libre)** — Le préavis paramétrable s'applique
  normalement (RG-SPORT-06) ; aucune règle de blocage d'engagement à appliquer (RG-SPORT-07 ne
  s'applique qu'« avant la fin d'engagement »).
- **Recouvrement prolongé sans régularisation** — Bascule potentielle en **suspension du contrat** selon
  `delaiAvantSuspensionContrat` ; ⚠ HYPOTHÈSE — les sources ne précisent pas si la suspension équivaut à
  une **résiliation automatique** ou reste un état intermédiaire réversible distinct ; retenue comme
  **état intermédiaire réversible** (le contrat n'est pas résilié tant qu'aucune demande de résiliation
  n'a été faite), à confirmer avec le métier.
- **Contrôleur définitivement hors-ligne (site isolé, panne longue)** — Le badge refusé localement le
  reste indéfiniment tant que la synchro n'a pas eu lieu (RG-SPORT-04 : la décision ne dépend jamais du
  serveur, y compris en défaveur de l'adhérent régularisé) ; ⚠ le **délai maximal acceptable** de
  propagation n'est pas fixé (point ouvert hérité de L3, `spec-acces.md` §7).
- **Adhérent isolé la nuit déclenchant le SOS par erreur** — ⚠ HYPOTHÈSE : aucune procédure
  d'annulation/désescalade de l'alerte n'est décrite par les sources ; à cadrer (ex. délai de
  confirmation avant notification de l'astreinte).
- **Deux adhérents présents simultanément en nocturne, l'un en impayé badge refusé** — Le refus de
  badge s'applique **indépendamment** de la présence d'autres adhérents ; la détection de présence
  isolée (§4.8) ne concerne que le cas d'**un seul** adhérent présent, pas un contrôle de solvabilité
  additionnel.
- **Réengagement immédiat après résiliation (même jour)** — Autorisé sans délai de carence décrit par
  les sources ; ⚠ HYPOTHÈSE : aucun délai minimal entre résiliation et réengagement n'est fixé.
- **Utilisateur sans affectation sur l'établissement de l'abonnement** — Aucun accès (hérité du socle,
  `RG-SOCLE-05`).

## 8. Dépendances
- **Dépend de : socle L0** (`specs/L0-socle/spec-socle.md`) — hiérarchie Groupe/Région/Établissement/
  Espace (`RG-SOCLE-01`) à laquelle se rattachent `PolitiqueAntiImpayes` et `ConfigAccesNocturne` (par
  établissement/espace) ; permissions `module × action` réutilisées sur le module **`sport`**
  (`RG-SOCLE-02/03/04`) ; cadrage par établissement actif (`RG-SOCLE-05`) ; journal d'audit append-only
  (`RG-SOCLE-07`) sur lequel s'appuient la traçabilité des incidents, résiliations, fusions de mandats.
- **Dépend de : M1 · Offre & Tarification** (L1, `specs/L1-offre/spec-offre.md`) — l'`AbonnementFitness`
  **instancie** une `Formule` (droits d'accès + services inclus à quota, `RG-M1-03/12`) et sa facette
  `sepa` (bool + jour de prélèvement, `spec-offre.md` §5) ; l'**engagement** (`duréeMin`,
  `conditionsPause`, `conditionsRésiliation`) est déjà porté comme champ de la `Formule` — la verticale
  **spécialise** ces conditions pour le fitness (RG-SPORT-05/06/07) sans redéfinir le moteur de
  catalogue.
- **Dépend de : L3 · Contrôle d'accès** (`specs/L3-acces/spec-acces.md`) — le `StatutAccesFitness`
  **projette** l'état de l'abonnement sur le `DroitAccès` (RG-ACC-01), et **hérite intégralement** du
  mécanisme générique **hors-ligne / synchronisation / cache local** (`RG-ACC-05`), de la **jauge FMI**
  pour la limite d'occupation nocturne (`RG-ACC-04`), et du **refus explicite avec motif** au tourniquet
  (`spec-acces.md` §4.3). La verticale **ne redéfinit pas** ces mécanismes, elle en **paramètre l'usage**
  (RG-SPORT-04).
- **Dépend de : M4 · CRM** (`specs/L5-crm/spec-crm.md`) — l'adhérent est un `Beneficiaire` d'une
  `Famille` (`RG-M4-02`, bénéficiaire ≠ payeur) ; le mandat SEPA est rattaché au **payeur**
  (`spec-crm.md` §8, point ouvert « Articulation PMV ↔ mandat SEPA », déjà signalé comme non tranché) ;
  les notifications de la verticale réutilisent les **consentements par canal** (`RG-M4-07`).
- **Dépend de : M6 · Compta & Régie** (`specs/L4-compta/spec-compta.md`) — l'encaissement de
  régularisation (§4.6) génère une **écriture comptable** (`RG-M6-04`) ; l'abonnement encaissé d'avance
  relève de la **logique PCA** au prorata temporis (`RG-M6-02/03`) ; le référentiel `MoyenPaiement`
  (M6, `spec-compta.md` §5) est **consommé** pour l'encaissement CB de résolution. ⚠ Voir récapitulatif
  final — **le moteur d'exécution du prélèvement SEPA récurrent (remise, retour, rejets normalisés)
  n'est décrit par aucune spec de ce dépôt à ce jour** ; M6 est scopé « profil régie » (10 US) et ne
  couvre pas cette mécanique privée. La verticale sport porte donc, à ce stade, les objets du **cycle
  métier** (`EcheanceSepa`, `IncidentPrelevement`, `RepresentationSepa`) en amont d'une **exécution
  technique** qui devra être spécifiée (extension M6 ou service dédié).
- **Référencé, non redéfini :** M5 Planning & Réservation (cours collectifs, non encore spécifié dans
  ce dépôt, cf. même point ouvert que `spec-piscine.md`) ; M3 Boutique & App client (écrans de l'app
  membre : résolution 1 clic, badge dématérialisé, gamification — la verticale spécifie le comportement
  observable attendu, pas l'écran).

---

## Points ouverts / hypothèses (récapitulatif)

### Absence de source officielle (à faire trancher/valider par le produit)
1. **US-SPORT-01 à 11 sont des stories définies par cet agent** — aucune US dédiée « salle de sport »
   n'existe dans `backlog.html` (verticale V2 non encore backloguée). À faire **valider, renuméroter et
   chiffrer** officiellement avant tout développement (§0 en-tête).
2. **Le mandat SEPA n'a pas d'objet de référence unique dans le dépôt** — évoqué comme référencé/non
   défini par `spec-offre.md` (facette Formule) et `spec-crm.md` (rattaché au payeur), et ici redéfini
   opérationnellement (`MandatSepaFitness`) pour les besoins de la verticale. **À réconcilier en un
   objet socle unique** (probablement porté par M4 ou une extension M6) avant implémentation — c'est le
   principal risque de duplication de modèle inter-specs.
3. **Aucun moteur d'exécution SEPA récurrente (remise bancaire, retours normalisés, rejeu des rejets)
   n'est spécifié dans ce dépôt** — `spec-compta.md` (L4) est scopé « profil régie » et ne couvre que
   PayFiP (encaissement public en ligne) ; la verticale sport porte les objets du **cycle métier**
   (échéance/rejet/représentation) mais **pas** l'intégration bancaire technique elle-même. C'est le
   point d'articulation le plus important à clarifier avec M6 avant le plan technique.

### ⚠ HYPOTHÈSE fonctionnelle (comportement retenu par défaut, à confirmer)
4. **Réconciliation RG-SPORT-01 et la décision actée « refus dès le 1ᵉʳ échec »** — paramètre
   `momentRefusBadge` à 3 valeurs, valeur par défaut retenue = `après_représentation_échouée` (§4.5).
5. **Accès pendant une pause** — retenu par défaut comme **suspendu** (comme un impayé), non tranché
   explicitement par les sources (§4.2).
6. **Souscription en ligne autonome** (sans agent) — retenue comme possible par cohérence avec la
   décision M3 « achat invité, compte imposé pour l'abonnement/SEPA », non explicitement tranchée pour
   le fitness (§4.1).
7. **Motifs légitimes de résiliation recevables et format du justificatif** — retenus comme texte libre
   + pièce jointe optionnelle avec validation manuelle obligatoire, procédure exacte non détaillée
   (§4.3).
8. **Second seuil `delaiAvantSuspensionContrat`** (recouvrement prolongé → suspension) — modélisé par
   hypothèse, valeur et statut exact (réversible vs résiliation automatique) non fournis par les
   sources (§4.5, §7).
9. **Prestataire d'encaissement CB pour la résolution 1 clic** — non nommé (à distinguer de PayFiP,
   solution publique DGFiP décrite côté M6, non pertinente pour un club privé) (§4.6).
10. **Délai maximal de propagation d'une restauration d'accès vers un contrôleur hors-ligne** — non
    chiffré, point hérité de L3 (§4.7, §7).
11. **Modalités précises de la sécurité nocturne** (conservation vidéo, destinataire de l'alerte SOS,
    comportement exact déclenché par la détection de présence isolée) — non détaillées par les sources,
    à cadrer avec un référent conformité/sécurité avant implémentation (§4.8).
12. **Responsabilité juridique en cas d'incident nocturne** — hors périmètre fonctionnel de cette spec
    (sujet contractuel/assurantiel), signalé pour mémoire (§4.8).
