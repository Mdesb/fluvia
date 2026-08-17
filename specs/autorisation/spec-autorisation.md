
# Spec — Autorisations graduées des opérations sensibles (`App\Autorisation`)

- **Lot / module :** Socle transverse (extension de M8 Admin & Droits) — mobilisé par M2 Vente,
  M6 Compta/Régie, M5 Planning/Réservation, Piscine/Patinoire (Caution)
- **Stories couvertes :** `US-AUTZ-01` à `US-AUTZ-09` — ⚠ **HYPOTHÈSE : ces stories n'existent pas
  dans `backlog.html`.** Elles sont créées par cette spec pour couvrir une intention client exprimée
  hors backlog (cf. brief). À faire valider/inscrire au backlog avant planification.
- **Règles de gestion :** `RG-AUTZ-01` à `RG-AUTZ-13` — ⚠ **HYPOTHÈSE : nouvelles règles, absentes
  de `cahier-detaille.html`.** Elles s'ancrent sur et **étendent** des règles/décisions existantes
  qui restent inchangées et font foi :
  - `RG-M8-01` (permission = couple module × action, jamais seule),
  - `RG-M8-04`/M8-04 « Seuils » (*« Plafonds de vente, seuils de remise et d'autorisation par
    rôle »* — champ non obligatoire, jamais détaillé au niveau règle : cette spec le formalise),
  - `RG-M8-05` (traçabilité avant/après des actions sensibles dans le journal d'audit inaltérable),
  - `RG-M8-06` (MFA obligatoire pour les rôles à privilèges),
  - `RG-M8-09` (plafond d'**attribution** de droits par un administrateur — **notion distincte**,
    non confondue, voir §0),
  - `RG-M2-07` (contre-passation : annulation/remboursement produisent un `Avoir` tracé, jamais de
    suppression de ligne),
  - Décision actée M2 *« Fond insuffisant / gros retrait »* → *« mouvement de caisse dédié (motif)
    + alerte régisseur au-delà d'un seuil »* : précédent direct dans le code
    (`MouvementCaisse::alerteRegisseur`, `PointDeVente::seuilAlerteRetrait`) mais **non bloquant**
    (simple indicateur) — cette spec introduit un mécanisme **bloquant** (escalade avec
    approbation), différent et complémentaire.
  - Décision actée M3 *« Remboursement en ligne »* → *« aucun remboursement automatique, tout passe
    par une demande explicite »* : cohérent avec l'esprit « pas de dérogation automatique » retenu
    ici pour l'escalade (§4.6).
- **Statut :** brouillon

## 0. Comment lire cette spec / ne pas confondre avec l'existant

Le socle **L0/M8** porte déjà un système de droits **binaires** : `Permission` (module × action),
`Role` (agrège des permissions), `Affectation` (utilisateur ↔ rôle ↔ établissement),
`DelegationDroit` (délégation temporaire d'un rôle entier, date de fin obligatoire),
`PermissionVoter`/`CalculateurDroits` (`is_granted('PERM', 'module.action')`),
`VerificateurPlafondDroits` (RG-M8-09 : un administrateur ne peut **attribuer** que des droits ≤
aux siens). **Rien de tout cela n'est modifié par cette spec.**

Ce que `VerificateurPlafondDroits` (RG-M8-09) plafonne, c'est la **capacité d'un administrateur à
distribuer des permissions à un tiers**. Ce que la présente spec introduit est différent : une
**gradation de l'usage** d'une permission déjà accordée (« j'ai le droit `vente.annuler`, mais
seulement jusqu'à 50 € et sur mes propres ventes »). Les deux mécanismes coexistent sans se
recouvrir.

`App\Autorisation` est une **couche de décision qui s'intercale avant l'exécution** d'une opération
sensible déjà protégée par un droit binaire (`is_granted('PERM', …)` reste la première porte,
inchangée) — elle ne remplace jamais le contrôle binaire, elle le **complète**.

## 1. Objectif

Permettre de graduer, par rôle et/ou par utilisateur, l'exercice d'un droit binaire déjà accordé
sur une opération sensible (annulation de vente, remboursement, émission d'avoir, et au-delà) selon
un **plafond montant** et un **périmètre de cible** (propre session vs établissement vs global), et
imposer, au-delà de la limite, une **escalade tracée** vers un superviseur habilité — sans jamais
casser le comportement binaire actuel là où aucune limite n'est configurée.

## 2. Périmètre

### Inclus
- Catalogue paramétrable d'**opérations sensibles** identifiées par une clé stable (§5,
  `OperationSensible`), rattachées chacune à une permission `module.action` existante.
- Entité `LimiteAutorisation` : plafond montant, périmètre de cible, exigence d'escalade, portée
  par rôle et/ou par utilisateur et/ou par établissement — paramétrable en back-office (module
  `autorisation`), **jamais codée en dur**.
- Service central de décision `ServiceAutorisation` : avant exécution d'une opération sensible,
  répond **AUTORISÉ** / **REFUSÉ** / **ESCALADE_REQUISE**, à partir (a) du droit binaire existant,
  (b) du périmètre de la cible, (c) du montant vs plafond.
- Mécanisme d'**escalade superviseur** : `DemandeEscalade` créée quand la décision est
  ESCALADE_REQUISE, `ApprobationEscalade` (ou rejet) par un utilisateur habilité, rejeu de
  l'opération d'origine une fois approuvée.
- Intégration v1 sur les opérations déjà câblées : annulation de vente (`AnnulerVenteProcessor`),
  remboursement de vente (`RembourserVenteProcessor`) — toutes deux via `ContrePassationHandler`,
  qui produit l'`Avoir` (RG-M2-07). **Émission d'avoir** n'est pas une action API distincte
  aujourd'hui : elle est la conséquence systématique d'une annulation/remboursement — ⚠ HYPOTHÈSE
  : couverte par les deux opérations ci-dessus, pas de troisième clé `vente.emettre_avoir` en v1
  (voir §4.1).
- Traçabilité : chaque franchissement de limite (refus, escalade demandée, escalade approuvée ou
  rejetée) journalisé dans `App\Audit\Entity\EntreeAudit` existant (append-only), via
  `App\Audit\Service\JournalAudit`, réutilisés tels quels.
- Rétrocompatibilité stricte : `LimiteAutorisation` absente pour un couple (opération, rôle ou
  utilisateur, établissement) ⇒ décision = comportement binaire actuel (`PermissionVoter` seul).
- Catalogue **extensible** (déclaratif) pour des opérations sensibles non encore intégrées
  techniquement en v1 : retenue de caution (`App\Caution\Service\GestionCaution::retenirImmediat`/
  `forcer`), détaxe, remise exceptionnelle de ligne (`RemiseType`), réouverture de session/caisse
  sécurisée (`RouvrirCaisseProcessor`) — décrites au catalogue mais **non câblées** en v1 (§4.1,
  §8).

### Exclu (pour l'instant)
- Toute modification du modèle de droits binaire L0/M8 (`Permission`, `Role`, `Affectation`,
  `DelegationDroit`, `PermissionVoter`, `CalculateurDroits`, `VerificateurPlafondDroits`) : réutilisés
  à l'identique.
- Le câblage technique de l'escalade sur les opérations « extensibles » listées ci-dessus (caution,
  détaxe, remise, réouverture) : seul le catalogue les référence ; l'intégration dans leurs
  handlers respectifs est un lot ultérieur.
- L'UI (écran superviseur, bandeau « autorisation requise », saisie de code) : décrite en
  comportement observable côté API uniquement (§6), pas de maquette ici.
- Le mode dégradé / hors-ligne pour l'escalade (constitution §4.6, mode dégradé caisse/contrôle
  d'accès) : ⚠ HYPOTHÈSE, hors périmètre v1 — signalé en cas limite (§7).
- Le cumul de montants entre plusieurs opérations distinctes dans une fenêtre de temps (cumul
  journalier) : ⚠ HYPOTHÈSE, non retenu en v1 (§4.9, §7) faute d'exigence chiffrée du client ou du
  cahier.
- La notion « ventes du jour » évoquée par le client comme variante possible du périmètre « propre
  session » : ⚠ HYPOTHÈSE, non retenue en v1 (§4.5) au profit de la session de caisse, déjà bornée
  et observable dans le code (`SessionCaisse`) ; documentée comme extension possible du même enum.

## 3. Acteurs & droits

| Acteur | Peut | Permission (module × action) |
|---|---|---|
| Caissier / opérateur | Déclencher une opération sensible dans les limites de son droit binaire existant (`vente.annuler`, `vente.rembourser`, …) ; si hors plafond ou hors périmètre : recevoir une décision REFUSÉ ou ESCALADE_REQUISE et, dans ce dernier cas, initier une demande d'escalade | permission de l'opération elle-même (inchangée), pas de nouvelle permission requise pour *subir* la gradation |
| Superviseur / régisseur | Approuver ou rejeter une demande d'escalade dans son périmètre (établissement actif) ; consulter les demandes en attente | `autorisation.approuver` *(nouvelle action, module `autorisation`)* |
| Administrateur (groupe / établissement) | Configurer les `LimiteAutorisation` par rôle et/ou utilisateur et/ou établissement, gérer le catalogue `OperationSensible` | `autorisation.gerer` *(nouvelle action)* — soumise au même plafond d'attribution RG-M8-09 que toute permission (un administrateur d'établissement ne configure que dans son périmètre) |
| Responsable sécurité | Consulter les demandes d'escalade et les limites configurées (lecture seule) | `autorisation.lire` *(nouvelle action)* — miroir du couple `securite.gerer`/`securite.lire` déjà en place pour l'audit |
| Système (tâche planifiée) | Expirer automatiquement les demandes d'escalade non traitées au-delà du délai (§4.7) | interne, pas d'API publique — même patron que `securite:delegations:expirer` |

⚠ HYPOTHÈSE : trois nouvelles actions (`gerer`, `approuver`, `lire`) sur un nouveau module
`autorisation`, à ajouter au référentiel `Permission` existant (même mécanique que l'ajout
`securite.exporter` proposé en L7, non encore tranché). Aucune permission existante n'est modifiée.

## 4. Comportements & règles

### 4.1 Catalogue des opérations sensibles — `OperationSensible`
- **RG-AUTZ-01** — Une opération sensible est identifiée par une **clé stable** (ex.
  `vente.annuler`, `vente.rembourser`), rattachée à la permission `module.action` binaire qui la
  protège déjà. Le catalogue est **paramétrable** (table, pas un `enum` PHP figé), pour permettre
  l'ajout ultérieur d'opérations sans déploiement de code (constitution §4 : « aucune logique
  métier codée en dur »).
- ⚠ HYPOTHÈSE — **Liste exacte v1** (câblée techniquement, décision de portée assumée faute de
  liste exhaustive dans le cahier) :
  1. `vente.annuler` (`AnnulerVenteProcessor` → `ContrePassationHandler::annuler`)
  2. `vente.rembourser` (`RembourserVenteProcessor` → `ContrePassationHandler::rembourser`)

  **Catalogue déclaré mais non câblé v1** (entrées du référentiel `OperationSensible` créées pour
  que l'admin puisse déjà les configurer, sans effet observable tant que le handler correspondant
  n'appelle pas `ServiceAutorisation`) :
  3. `caution.retenue` (`GestionCaution::retenirImmediat`/`forcer`)
  4. `compta.detaxe` — aucun handler identifié dans le code actuel ; à confirmer au lot M6.
  5. `vente.remise_exceptionnelle` — `RemiseType` existe au niveau ligne, mais aucune notion de
     « remise exceptionnelle » (au-delà d'un seuil standard) n'est câblée aujourd'hui.
  6. `caisse.reouverture_session` (`RouvrirCaisseProcessor`, déjà protégé par un code régisseur en
     dur dans le corps de la requête — voir §4.6 pour la distinction avec l'escalade tracée).

### 4.2 `LimiteAutorisation` — configuration
- **RG-AUTZ-02** — Une `LimiteAutorisation` porte : l'opération (référence `OperationSensible`),
  **soit** un rôle **soit** un utilisateur (jamais les deux, jamais aucun — cible exclusive, même
  esprit que `DelegationDroit.beneficiaire`), un établissement (portée du même esprit que
  `Affectation.etablissement` — pas de propagation hiérarchique automatique, cohérent avec l'écart
  déjà documenté en L7 §RG-M8-02), un `plafondMontant` (nullable = illimité), un `perimetre`
  (enum, §4.5), un booléen `escaladeAuDela`.
- **RG-AUTZ-03** — **Priorité de résolution** quand plusieurs `LimiteAutorisation` s'appliquent à
  un même utilisateur/opération/établissement (cas : limite de rôle + limite utilisateur, ou
  plusieurs rôles affectés) : une limite **spécifique à l'utilisateur** prévaut sur toute limite de
  rôle ; entre plusieurs limites de rôle, **la plus restrictive l'emporte** (plafond le plus bas,
  périmètre le plus étroit) — même principe que la décision actée M8 *« Conflit de rôles hérités →
  la règle la plus restrictive l'emporte »*, appliqué par analogie faute de règle dédiée écrite.
- Seul un titulaire de `autorisation.gerer` peut créer/modifier/supprimer une `LimiteAutorisation`,
  et seulement dans son propre périmètre d'établissement (même garde que `VerificateurPlafondDroits`
  pour l'attribution de droits — ⚠ HYPOTHÈSE : on applique par cohérence la même contrainte « un
  administrateur d'établissement ne configure pas de limite plus permissive que ses propres droits
  », sans qu'aucune RG explicite du cahier ne le formalise pour ce nouveau module).

### 4.3 Décision — `ServiceAutorisation::evaluer(...)`
- **RG-AUTZ-04** — Avant toute exécution d'une opération sensible câblée, le service évalue, dans
  cet ordre :
  1. **Droit binaire** : `is_granted('PERM', <module.action de l'opération>)` — si absent →
     **REFUSÉ** (comportement 403 identique à aujourd'hui, aucun changement).
  2. **Périmètre de la cible** (§4.5) : si la cible (ex. la `Vente`) est hors du périmètre autorisé
     pour cet utilisateur → **REFUSÉ**, quel que soit le montant.
  3. **Montant vs plafond** (résolu selon RG-AUTZ-03) :
     - pas de `LimiteAutorisation` applicable → **AUTORISÉ** (RG-AUTZ-09, rétrocompatibilité) ;
     - montant ≤ plafond (ou plafond `null` = illimité) → **AUTORISÉ** ;
     - montant > plafond et `escaladeAuDela = true` → **ESCALADE_REQUISE** ;
     - montant > plafond et `escaladeAuDela = false` → **REFUSÉ** (dépassement bloquant sans
       recours possible — ⚠ HYPOTHÈSE : comportement volontairement strict par défaut, aucune
       dérogation implicite, cohérent avec la décision actée M3 « aucun remboursement automatique
       »).
- Le montant comparé est celui de l'opération (montant de l'`Avoir` à émettre pour annulation ou
  remboursement — total de la vente pour une annulation complète, montant demandé pour un
  remboursement partiel).
- La borne est **inclusive** : montant strictement égal au plafond → AUTORISÉ (cas limite §7).

### 4.4 Rétrocompatibilité binaire
- **RG-AUTZ-09** — Si **aucune** `LimiteAutorisation` n'est configurée pour le couple (opération,
  utilisateur ou l'un de ses rôles, établissement actif), la décision équivaut strictement au
  contrôle binaire actuel (`is_granted('PERM', …)`) : aucune régression, aucune escalade, aucun
  changement de comportement observable pour les clients qui ne configurent rien.

### 4.5 Périmètre de cible — `PerimetreAutorisation`
- **RG-AUTZ-05** — Enum à trois valeurs v1 :
  - `propre_session` : la cible (ex. `Vente.getSession()`) doit être rattachée à une session de
    caisse (`SessionCaisse`) dont l'utilisateur courant est l'**opérateur** ou le **régisseur**
    (`SessionCaisse::getOperateur()`/`getRegisseur()`) ;
  - `propre_etablissement` : la cible doit appartenir au même établissement que le contexte actif
    (`X-Etablissement`), sans restriction de session — un caissier peut alors agir sur les ventes
    d'autres agents de son établissement ;
  - `global` : aucune restriction de propriétaire (reste néanmoins borné à l'établissement actif
    par le cloisonnement multi-entités déjà en place au niveau des extensions Doctrine, ex.
    `PerimetreVenteExtension` — ce cloisonnement socle n'est pas modifié).
- ⚠ HYPOTHÈSE — la formulation client *« sa propre caisse/session uniquement (ou ventes du jour) »*
  laisse deux granularités possibles. V1 retient exclusivement **`propre_session`** (bornée à la
  session de caisse ouverte, notion déjà présente et observable dans le code) plutôt qu'une
  variante « ventes du jour toutes sessions confondues », qui n'a pas d'équivalent entité
  aujourd'hui (une session peut être ouverte plusieurs jours en théorie, rien ne borne
  « aujourd'hui » indépendamment de la session). Une valeur `propre_jour` pourra être ajoutée à
  l'enum sans changement de structure si le besoin se confirme — point ouvert (§7).
- Si `LimiteAutorisation.perimetre` n'est pas configuré (aucune limite) → aucun contrôle de
  périmètre au-delà du cloisonnement établissement déjà existant (RG-AUTZ-09).

### 4.6 Flux d'escalade superviseur
- **RG-AUTZ-06** — Quand `ServiceAutorisation` répond ESCALADE_REQUISE, l'opération d'origine
  **n'est pas exécutée**. La réponse observable est un **403** accompagné d'un corps décrivant la
  demande créée : `{ "decision": "escalade_requise", "demandeEscalade": "<uuid>", "operation":
  "vente.annuler", "plafond": "50.00", "montant": "120.00" }`. Une `DemandeEscalade` est persistée
  en statut `EnAttente` (§5).
- Un superviseur habilité (`autorisation.approuver`, sur l'établissement de la demande) consulte
  les demandes en attente et **approuve** ou **rejette** via une action dédiée (observable :
  `POST /demandes-escalade/{id}/approuver` ou `/rejeter`, corps `{ "motif"?: "…" }`). L'approbation
  enregistre qui (le superviseur authentifié), quand, quelle opération, quel montant — champs
  natifs de `DemandeEscalade` (§5), pas de nouveau mécanisme de traçabilité inventé.
- Une fois **approuvée**, l'opération d'origine doit être **rejouée** par le caissier (nouvelle
  requête sur l'endpoint métier d'origine, ex. `POST /ventes/{id}/annuler`, portant une référence à
  la `DemandeEscalade` approuvée) ; `ServiceAutorisation` constate l'approbation valide pour ce
  couple (opération, cible, montant, auteur) et retourne **AUTORISÉ** sans re-comparer au plafond
  (le superviseur a statué). Toute divergence (montant différent, cible différente) entre la
  demande approuvée et la requête rejouée est refusée.
- Une demande **rejetée** ne peut plus être rejouée : l'opération reste bloquée, une nouvelle
  demande devra être créée si le caissier retente.
- **RG-AUTZ-13 (séparation des tâches)** — Un utilisateur ne peut pas approuver sa propre demande
  d'escalade, même s'il détient par ailleurs `autorisation.approuver` (garde-fou minimal,
  analogue en esprit à RG-M8-07 « dernier administrateur » : éviter qu'un contrôle gradué soit
  contournable par l'auteur lui-même).
- ⚠ **HYPOTHÈSE — mode d'escalade retenu : approbation applicative en ligne, tracée nominativement**
  (le superviseur s'authentifie avec ses propres identifiants — session déjà ouverte ou re-saisie
  de mot de passe côté écran superviseur — et approuve explicitement), **plutôt qu'un simple « code
  PIN superviseur » saisi sur le poste caissier**. Justification : le code trouvé dans le code
  existant (`RouvrirCaisseProcessor`, champ `codeRegisseur`) ne fait qu'une **vérification de
  présence** (`$code === ''`) sans validation ni identification du superviseur qui l'a fourni — ce
  patron ne satisferait pas l'exigence explicite du client *« l'approbation est enregistrée : qui,
  quand, quelle opération, quel montant »*. Un mode « code PIN » reste une option d'UX
  **compatible** avec le contrat ci-dessus si le poste superviseur exige une ré-authentification
  (mot de passe ou TOTP) équivalente à un code — à trancher au plan technique. **Point ouvert.**

### 4.7 Expiration d'une demande d'escalade
- **RG-AUTZ-07** — Une `DemandeEscalade` non traitée expire automatiquement après un délai
  paramétrable ; ⚠ HYPOTHÈSE : défaut **15 minutes**, par analogie avec le seul délai
  d'expiration déjà acté ailleurs dans le cahier (réservation panier boutique, décision M3
  « Abandon de panier → stock réservé 15 min »). Statut → `Expiree`. Une tâche planifiée du même
  patron que `securite:delegations:expirer` effectue cette transition ; double garde défensive côté
  lecture (`estEnAttenteMaintenant()`) comme pour `DelegationDroit::estActiveMaintenant()`.

### 4.8 Traçabilité
- **RG-AUTZ-08** — Chaque décision REFUSÉ (pour dépassement de plafond ou hors périmètre — pas pour
  simple absence du droit binaire, déjà tracée ailleurs si applicable) et chaque étape du cycle de
  vie d'une `DemandeEscalade` (création, approbation, rejet, expiration) génère une `EntreeAudit`
  via `App\Audit\Service\JournalAudit` (append-only, RG-M8-05) avec valeurs avant/après (ex.
  `valeurApres = {"statut": "approuvee", "superviseur": "…"}`). Aucun nouveau mécanisme d'audit
  n'est créé : réutilisation stricte de l'existant.

### 4.9 Cumul (non retenu v1)
- ⚠ HYPOTHÈSE — **RG-AUTZ-11 (non retenue v1)** : un cumul journalier (somme des montants d'une
  même opération par le même auteur sur une période) n'est **pas** implémenté en v1, faute
  d'exigence chiffrée dans le cahier ou le brief. Chaque opération est jugée **isolément** contre le
  plafond. Risque documenté en cas limite §7 (fractionnement). Le modèle `LimiteAutorisation`
  laisse la place à un champ futur `cumulJournalierMax` (nullable, non exploité v1) pour ne pas
  fermer la porte sans migration destructrice.

### 4.10 Cohérence avec la délégation temporaire de droits (L7)
- **RG-AUTZ-12** — Si un bénéficiaire reçoit un droit binaire par `DelegationDroit` (rôle
  temporaire), les `LimiteAutorisation` attachées à ce rôle s'appliquent à lui pendant la fenêtre
  active de la délégation, au même titre qu'à un titulaire permanent du rôle — cohérent avec
  `CalculateurDroits` qui inclut déjà les délégations actives dans les codes effectifs. Aucune
  limite spécifique « délégation » n'est modélisée séparément en v1.

## 5. Objets de données

| Objet | Champ | Type | Contraintes | Notes |
|---|---|---|---|---|
| `OperationSensible` | code | string (PK) | unique, ex. `vente.annuler` | catalogue paramétrable, RG-AUTZ-01 |
| `OperationSensible` | libelle | string | requis | affichage back-office |
| `OperationSensible` | moduleAction | string | requis, format `module.action` | référence la `Permission` binaire existante ; pas de FK stricte vers `Permission` pour rester paramétrable indépendamment de l'ordre de création |
| `OperationSensible` | active | bool | défaut `true` | permet de désactiver une entrée du catalogue sans la supprimer (historique) |
| `LimiteAutorisation` | id | uuid (PK) | | RG-AUTZ-02 |
| `LimiteAutorisation` | operation | référence `OperationSensible` | requis (FK code) | |
| `LimiteAutorisation` | role | référence `Role`, nullable | exclusif avec `utilisateur` | RG-AUTZ-02 |
| `LimiteAutorisation` | utilisateur | référence `Utilisateur`, nullable | exclusif avec `role` | RG-AUTZ-02 |
| `LimiteAutorisation` | etablissement | référence `Etablissement` | requis | pas d'héritage hiérarchique automatique (même écart assumé que RG-M8-02 en L7) |
| `LimiteAutorisation` | plafondMontant | decimal(10,2), nullable | `null` = illimité | comparaison inclusive (`≤`) |
| `LimiteAutorisation` | perimetre | enum `PerimetreAutorisation` | `propre_session \| propre_etablissement \| global` | RG-AUTZ-05 |
| `LimiteAutorisation` | escaladeAuDela | bool | défaut `false` | si `false` et dépassement → REFUSÉ définitif (RG-AUTZ-04) |
| `LimiteAutorisation` | cumulJournalierMax | decimal(10,2), nullable | non exploité v1 | RG-AUTZ-11, réservé |
| `LimiteAutorisation` | dateCreation / auteur | datetime / référence `Utilisateur` | | traçabilité de configuration, tracée à l'audit comme tout changement de droits (RG-M8-05) |
| `DemandeEscalade` | id | uuid (PK) | | RG-AUTZ-06 |
| `DemandeEscalade` | operation | référence `OperationSensible` | requis | |
| `DemandeEscalade` | cibleType | string | requis | ex. `Vente` |
| `DemandeEscalade` | cibleId | string (uuid) | requis | ex. id de la `Vente` |
| `DemandeEscalade` | montant | decimal(10,2) | requis | montant de l'opération évaluée |
| `DemandeEscalade` | auteur | référence `Utilisateur` | requis | le caissier demandeur |
| `DemandeEscalade` | etablissement | référence `Etablissement` | requis | borne les superviseurs éligibles |
| `DemandeEscalade` | statut | enum `StatutEscalade` | `en_attente \| approuvee \| rejetee \| expiree` | RG-AUTZ-06/07 |
| `DemandeEscalade` | dateDemande | datetime | | |
| `DemandeEscalade` | dateExpiration | datetime | calculée à la création (RG-AUTZ-07) | |
| `DemandeEscalade` | superviseur | référence `Utilisateur`, nullable | renseigné au traitement | ne peut être == `auteur` (RG-AUTZ-13) |
| `DemandeEscalade` | dateTraitement | datetime, nullable | | |
| `DemandeEscalade` | motifRejet | string, nullable | requis si `rejetee` | |
| `DemandeEscalade` | jeton | uuid, unique | généré à la création | référence à fournir lors du rejeu de l'opération d'origine |
| *(non persisté)* `Decision` | resultat | enum | `autorise \| refuse \| escalade_requise` | valeur de retour de `ServiceAutorisation::evaluer()` |
| *(non persisté)* `Decision` | motif | string | | message explicite (ex. « hors périmètre : vente hors de votre session ») |
| *(non persisté)* `Decision` | limiteAppliquee | référence `LimiteAutorisation`, nullable | | pour audit/debug |

Réutilisés tels quels (aucune modification) : `Utilisateur`, `Role`, `Permission`, `Affectation`,
`DelegationDroit`, `Etablissement`, `EntreeAudit`, `JournalAudit`.

## 6. Critères d'acceptation

- **CA-1 (US-AUTZ-01)** — *Étant donné* un caissier avec le droit `vente.annuler` et une
  `LimiteAutorisation` (rôle Caissier, opération `vente.annuler`, plafond 100 €, périmètre
  `propre_session`, `escaladeAuDela=true`), *quand* il annule une vente de 80 € de sa propre
  session, *alors* la décision est **AUTORISÉ**, l'annulation s'exécute normalement (`Avoir` créé,
  RG-M2-07), aucune `DemandeEscalade` n'est créée.

- **CA-2 (US-AUTZ-02)** — *Étant donné* la même limite (plafond 100 €), *quand* le même caissier
  tente d'annuler une vente de 250 € de sa propre session, *alors* la décision est
  **ESCALADE_REQUISE**, l'annulation **n'est pas exécutée**, une `DemandeEscalade` est créée en
  statut `en_attente` (montant 250 €, opération `vente.annuler`, auteur = caissier), et la réponse
  API renvoie 403 avec l'identifiant de la demande.

- **CA-3 (US-AUTZ-03)** — *Étant donné* la `DemandeEscalade` créée en CA-2, *quand* un superviseur
  habilité (`autorisation.approuver`, même établissement, différent du caissier) l'approuve,
  *alors* le statut passe à `approuvee`, une `EntreeAudit` trace qui/quand/quel montant, et *quand*
  le caissier rejoue `POST /ventes/{id}/annuler` en référençant la demande approuvée, *alors*
  l'annulation s'exécute (`Avoir` créé) sans nouvelle comparaison au plafond.

- **CA-4 (US-AUTZ-04)** — *Étant donné* la même `DemandeEscalade`, *quand* le superviseur la
  **rejette** avec un motif, *alors* le statut passe à `rejetee`, l'opération d'origine reste
  bloquée, et une nouvelle tentative du caissier sur la même vente crée une **nouvelle** demande
  (la précédente n'est pas rejouable).

- **CA-5 (US-AUTZ-05)** — *Étant donné* une `LimiteAutorisation` de périmètre `propre_session`,
  *quand* un caissier tente d'annuler une vente rattachée à la session de caisse d'un **autre**
  opérateur (même établissement, montant sous le plafond), *alors* la décision est **REFUSÉ** (403,
  motif « hors périmètre »), même si le montant est sous le plafond et le droit binaire présent.

- **CA-6 (US-AUTZ-06, rétrocompatibilité)** — *Étant donné* un utilisateur avec le droit
  `vente.rembourser` et **aucune** `LimiteAutorisation` configurée pour lui ni pour aucun de ses
  rôles sur l'établissement actif, *quand* il rembourse une vente de n'importe quel montant,
  *alors* la décision est **AUTORISÉ** dans les mêmes conditions qu'aujourd'hui (comportement
  binaire inchangé, RG-AUTZ-09) — aucune régression, aucune `DemandeEscalade`.

- **CA-7 (US-AUTZ-07)** — *Étant donné* une `LimiteAutorisation` avec `escaladeAuDela=false` et un
  plafond de 100 €, *quand* un caissier tente une opération de 150 €, *alors* la décision est
  **REFUSÉ** de façon définitive (pas de `DemandeEscalade` créée, message explicite indiquant
  qu'aucune escalade n'est possible pour cette configuration).

- **CA-8 (US-AUTZ-08, remboursement partiel cumulé)** — *Étant donné* un plafond de 100 € sans
  cumul journalier configuré (RG-AUTZ-11), *quand* un caissier effectue deux remboursements
  partiels successifs de 60 € chacun sur la même vente (total 120 €), *alors* chaque remboursement
  est évalué **isolément** et **AUTORISÉ** (60 € ≤ 100 €) — ⚠ comportement documenté comme
  limitation connue v1, pas un bug (§7).

- **CA-9 (US-AUTZ-09, superviseur absent)** — *Étant donné* un établissement où aucun utilisateur
  n'a la permission `autorisation.approuver`, *quand* une `DemandeEscalade` y est créée, *alors*
  elle reste `en_attente` jusqu'à expiration (RG-AUTZ-07), puis passe automatiquement à `expiree` ;
  l'opération d'origine reste bloquée pendant toute la durée ; aucune dérogation automatique n'a
  lieu.

## 7. Cas limites

- **Montant égal au plafond** : `montant == plafond` → **AUTORISÉ** (comparaison inclusive, §4.3).
- **Fractionnement pour contourner le plafond** (remboursements partiels répétés) : non détecté en
  v1, faute de cumul journalier (RG-AUTZ-11). Point ouvert signalé au client : à arbitrer
  (cumul par jour ? par vente ? fenêtre glissante ?) avant un lot ultérieur.
- **Superviseur absent ou hors ligne** : la demande reste bloquante jusqu'à expiration ; pas de
  remontée automatique vers un niveau hiérarchique supérieur (ex. région) en v1 — ⚠ HYPOTHÈSE,
  aucune notion de « supervision multi-niveaux en cascade » n'est demandée explicitement.
- **Délégation temporaire active** : un bénéficiaire de `DelegationDroit` hérite des
  `LimiteAutorisation` attachées au rôle délégué pendant la fenêtre active (RG-AUTZ-12) ; à
  l'expiration de la délégation, toute `DemandeEscalade` encore `en_attente` créée par ce
  bénéficiaire n'est **pas** automatiquement annulée (le superviseur peut encore l'approuver tant
  qu'elle n'a pas expiré elle-même) — point ouvert mineur, à confirmer.
- **Auto-approbation** : un utilisateur ne peut pas traiter sa propre demande, même habilité
  (RG-AUTZ-13) — tentative refusée (403, message explicite).
- **Cible modifiée entre la demande et le rejeu** : si la vente a changé de statut entre la création
  de la `DemandeEscalade` et son approbation (ex. déjà annulée par un autre canal), le rejeu échoue
  via le contrôle métier déjà en place (`ContrePassationHandler::exigerValidee`,
  `ConflictHttpException`) — la demande reste marquée `approuvee` mais non rejouable ; tracé.
- **Montant du rejeu différent du montant approuvé** : refusé, nouvelle demande requise (§4.6).
- **Mode dégradé / hors-ligne** (caisse ou contrôle d'accès fonctionnant sans réseau, constitution
  §4.6) : le mécanisme d'escalade suppose un aller-retour serveur pour créer/approuver la demande.
  ⚠ HYPOTHÈSE : hors-ligne, l'opération sensible reste **bloquée en ESCALADE_REQUISE** jusqu'au
  retour réseau (pas d'approbation locale hors-ligne en v1) — à confirmer, écart potentiel avec le
  principe « la caisse fonctionne hors-ligne » si les opérations sensibles concernées sont
  fréquentes en hors-ligne.
- **Établissement de la limite vs établissement actif** : une `LimiteAutorisation` est toujours
  résolue sur l'établissement actif (`X-Etablissement`), cohérent avec `CalculateurDroits` — un
  utilisateur multi-établissements a des limites potentiellement différentes selon le contexte actif
  (même logique que les droits binaires aujourd'hui).
- **Catalogue non câblé** (caution, détaxe, remise exceptionnelle, réouverture de session) :
  configurer une `LimiteAutorisation` sur une de ces opérations n'a **aucun effet observable** tant
  que le handler correspondant n'appelle pas `ServiceAutorisation` — pas une erreur, juste une
  extensibilité déclarée en avance ; à documenter clairement en back-office pour ne pas induire en
  erreur l'administrateur qui la configurerait en pensant qu'elle est déjà active.

## 8. Dépendances

- Dépend de :
  - **L0 socle** — `Permission`, `Role`, `Affectation`, `DelegationDroit`, `Utilisateur`,
    `PermissionVoter`, `CalculateurDroits`, `ContexteEtablissement` (`app/src/Securite/*`).
  - **Audit (socle)** — `EntreeAudit`, `JournalAudit`, `AuditWriteSubscriber` (`app/src/Audit/*`) —
    réutilisés sans modification, RG-M8-05.
  - **M2 Vente & Caisse** — `AnnulerVenteProcessor`, `RembourserVenteProcessor`,
    `ContrePassationHandler`, `Avoir`, `SessionCaisse` (pour le périmètre `propre_session`),
    `MouvementCaisse`/`PointDeVente.seuilAlerteRetrait` (précédent non bloquant, distinct).
  - **L7 back-office & droits** — `VerificateurPlafondDroits` (RG-M8-09, notion distincte mais
    contrainte analogue appliquée à la configuration des `LimiteAutorisation`, §4.2), garde
    « dernier administrateur » (RG-M8-07, même esprit de garde-fou pour RG-AUTZ-13).
  - Extensibilité future (non câblée v1) : **Caution** (`GestionCaution`), **Compta & Régie**
    (détaxe), **Planning & Réservation** (réouverture de session/caisse).
- Bloque / est un prérequis pour : toute story future qui voudrait câbler l'escalade sur une
  opération sensible additionnelle du catalogue extensible (§4.1).

## Points ouverts (récapitulatif)

1. Liste exacte des opérations sensibles v1 vs catalogue déclaratif non câblé (§4.1) — à valider
   avec le client.
2. Mode d'escalade retenu : approbation applicative nominative (retenue) vs code PIN superviseur
   (existant en précédent faible sur `RouvrirCaisseProcessor`, jugé insuffisant en traçabilité) —
   §4.6, à trancher avant le plan technique.
3. Cumul journalier / fenêtre glissante non retenu v1 — risque de fractionnement documenté (§7,
   §4.9), à arbitrer.
4. Granularité du périmètre « propre session » vs « ventes du jour » — v1 retient la session de
   caisse (§4.5), extension `propre_jour` possible sans refonte.
5. Comportement en mode dégradé/hors-ligne pour l'escalade (§7) — non tranché.
6. Nouvelles actions `autorisation.gerer` / `.approuver` / `.lire` à ajouter au référentiel
   `Permission` — cohérence à vérifier avec l'ajout similaire `securite.exporter` déjà en attente
   côté L7.
