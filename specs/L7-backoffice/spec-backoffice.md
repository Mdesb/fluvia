# Spec — Back-office & Droits : compléments M8 (`L7`)

- **Lot / module :** L7 · M8 Admin & Droits
- **Stories couvertes :** US-L7-01, US-L7-02, US-L7-03, US-L7-04, US-L7-05, US-L7-06, US-L7-07, US-L7-08, US-L7-09
- **Règles de gestion :** RG-M8-01 à RG-M8-09 · rappels RG-SOCLE-01 à 07 (L0, déjà livrées)
- **Statut :** brouillon

## 0. Comment lire cette spec

Le **modèle de droits** (Groupe/Région/Établissement/Espace, Utilisateur, Rôle, Permission
`module × action`, Affectation utilisateur↔rôle↔établissement, `PermissionVoter`,
`ContexteEtablissement`, journal d'audit append-only) est **déjà implémenté et testé dans le
socle L0** (`specs/L0-socle/spec-socle.md`, `app/src/Securite/*`, `app/src/Audit/*`,
`app/src/Organisation/*`). Cette spec ne le redéfinit pas.

Chaque exigence ci-dessous est étiquetée :
- **[DÉJÀ SOCLE]** — existe et fonctionne, rappelé pour mémoire/traçabilité RG, rien à coder.
- **[BACK-END L7]** — à construire dans ce lot (API, entités, services, migrations, tests).
- **[FRONT-END]** — comportement d'interface, hors périmètre de ce build API ; listé pour que
  le lot front sache sur quels endpoints s'appuyer.

## 1. Objectif

Compléter le socle de droits L0 avec les briques de gouvernance qui manquent encore pour que le
back-office M8 soit opérable en production : cycle de vie complet des comptes (invitation,
statut, dernier accès), authentification renforcée (MFA + mot de passe oublié — US-L0-03
différée), délégation temporaire de droits à échéance automatique, garde-fous de gouvernance
(dernier administrateur, plafond des droits attribuables), utilitaires de gestion des rôles
(duplication, rôles-modèles) et un journal d'audit réellement exploitable (avant/après, filtres,
export). Le rendu adaptatif de l'interface (masquage de menus, bascule caisse↔admin) est un sujet
**FRONT-END** qui consomme ces API mais n'est pas construit ici.

## 2. Périmètre

- **Inclus (back-end L7) :**
  - Cycle de vie utilisateur : statut `Invité / Actif / Suspendu`, invitation par jeton à durée
    limitée, activation, suspension à effet immédiat, date de dernier accès.
  - MFA : activation/désactivation, secret, vérification à la connexion, politique « obligatoire
    pour les rôles à privilèges » (RG-M8-06), réinitialisation par un administrateur en cas de
    perte.
  - Mot de passe oublié : demande, jeton à usage unique et durée limitée, réinitialisation
    (US-L0-03, différée du socle, rattrapée ici).
  - Délégation temporaire de droits : création avec date de fin **obligatoire**, révocation
    automatique à échéance, révocation anticipée manuelle, visibilité sur la fiche utilisateur.
  - Garde-fou « dernier administrateur » (RG-M8-07) : blocage de la suppression/désactivation de
    la dernière affectation « administrateur » d'un périmètre.
  - Plafond d'attribution des droits (RG-M8-09) : un administrateur d'établissement ne peut
    attribuer/déléguer que des droits ≤ aux siens, strictement dans son périmètre.
  - Rôles : duplication d'un rôle existant, rôles-modèles réutilisables, option de propagation
    d'une modification de modèle aux utilisateurs déjà rattachés.
  - Endpoint d'aperçu des droits résultants d'un rôle sur un établissement donné, pour un
    utilisateur autre que soi-même (US-L7-05, backend).
  - Journal d'audit : ajout des valeurs **avant/après** (manquantes aujourd'hui), filtres
    serveur (auteur, action, type de cible, établissement, période), export.
- **Rappelé [DÉJÀ SOCLE], non repris ici :** modèle Groupe/Région/Établissement/Espace ;
  Utilisateur/Role/Permission/Affectation (CRUD) ; `PermissionVoter` (403 serveur) ;
  `ContexteEtablissement` (en-tête `X-Etablissement`) ; `CalculateurDroits` (union des droits
  effectifs) ; endpoint `GET /me` (profil + droits effectifs de l'utilisateur courant) ; journal
  d'audit append-only (création automatique sur les entités sensibles) ; verrouillage de compte
  après échecs de connexion ; matrice `Permission` déjà exposée en lecture (`GET /permissions`)
  comme référentiel « module × action ».
- **Exclu (hors de ce build API) :**
  - Toute l'**UI back-office** : shell de navigation, sélecteur de mode admin/caisse, masquage de
    menus/actions dans le DOM, écran « Vue utilisateur » d'aperçu de rôle → **FRONT-END**.
  - M8-03 « Entités & établissements » avancé (arbre visuel, profil exploitant, points de
    vente/caisses) : le socle organisationnel (Groupe/Région/Établissement/Espace) est déjà en
    place (L0), et `PointDeVente`/`Caisse` existent déjà (L2, `app/src/Caisse/Entity/*`). Le
    champ **profil d'exploitant** (Régie/DSP/Groupe privé, RG cahier M8-03) n'existe pas encore
    sur `Etablissement` — ⚠ HYPOTHÈSE : reporté à une itération ultérieure de L7 ou à L4/M6 (déjà
    porteur de `App\Compta\Entity\ProfilExploitant`, cf. `AuditWriteSubscriber`), à trancher car
    non listé dans les US-L7 du backlog actuel.
  - M8-04 « Paramètres généraux & matériel » (TPE, imprimantes, seuils, moyens de paiement) :
    couvert par L2/M2 (Caisse/Vente) et hors des 9 US du backlog L7 — non repris ici.
  - M8-06 « Sécurité & conformité » côté RGPD (registre des traitements, consentements, purges,
    demandes de droits) : déjà couvert par L5/M4 CRM (`Consentement`, `DemandeRGPD`,
    `RegleConservation`, `JournalFusion` dans `app/src/Crm/Entity`). La supervision transverse
    (tableau de bord sauvegardes/réversibilité globale, RG-M8-08) n'est **pas** un livrable de ce
    lot ; ⚠ HYPOTHÈSE : traité comme sujet d'exploitation/infra, pas d'endpoint applicatif requis
    par les 9 US du backlog L7.
  - Héritage hiérarchique strict des rôles le long de l'arbre Groupe→Région→Établissement→Espace
    (RG-M8-02, « hérités par niveau d'entité, surchargeables ») : le socle L0 attribue les rôles
    **directement par Établissement** (`Affectation.etablissement` non-nul), sans mécanisme de
    propagation automatique depuis un niveau parent. ⚠ HYPOTHÈSE : non repris dans ce lot (aucune
    US du backlog L7 ne le demande explicitement) ; signalé en cas limite / point ouvert (§7) car
    c'est un écart formel avec RG-M8-02 tel qu'écrit dans le cahier détaillé.
  - Intégration technique SSO (SAML/OIDC) réelle : `Utilisateur.SSO` n'existe pas encore côté
    entité ; ⚠ HYPOTHÈSE hors MVP (aucune US ne la demande), le champ MFA « délégable à l'IdP »
    reste une politique à modéliser sans connecteur IdP réel.

## 3. Acteurs & droits

| Acteur | Peut | Permission (module × action) |
|---|---|---|
| Administrateur (groupe) | Tout gérer sur son périmètre : utilisateurs, rôles, délégations, MFA d'autrui, journal d'audit complet de son périmètre | `securite.gerer` |
| Administrateur d'établissement | Gérer utilisateurs/rôles/délégations **de son périmètre**, uniquement des droits ≤ aux siens (RG-M8-09) | `securite.gerer` restreint à l'établissement |
| Responsable sécurité | Consulter/exporter le journal d'audit ; superviser MFA | `securite.lire`, `securite.exporter` *(⚠ HYPOTHÈSE : action `exporter` à ajouter au référentiel `Permission`, absente aujourd'hui — module `securite` n'a pour l'instant que `gerer`)* |
| Utilisateur standard | Gérer son propre MFA, demander une réinitialisation de mot de passe, consulter ses délégations reçues | `IS_AUTHENTICATED_FULLY` (pas de permission métier requise sur soi-même) |
| Système (tâche planifiée) | Révoquer automatiquement les délégations expirées | interne, pas d'API publique |

## 4. Comportements & règles

### 4.1 Cycle de vie utilisateur & invitation — **[BACK-END L7]**
- **RG-M8-01/US-L7-03** — `Utilisateur` porte un **statut** `invite | actif | suspendu` (au lieu
  du seul booléen `actif` actuel). Un utilisateur créé par un administrateur est `invite` tant
  qu'il n'a pas activé son compte.
- L'invitation génère un **jeton à durée limitée** (⚠ HYPOTHÈSE : 72 h par défaut, paramétrable)
  envoyé par e-mail ; l'activation (choix du mot de passe) consomme le jeton et passe le statut à
  `actif`.
- La **suspension** (`statut = suspendu`) révoque immédiatement toute session active de
  l'utilisateur (invalidation des jetons JWT déjà émis) sans supprimer son historique d'audit.
- Le **dernier accès** (`dernierAcces`) est mis à jour à chaque connexion réussie (déjà tracée en
  audit côté `EcouteurConnexion` — [DÉJÀ SOCLE] pour l'écriture d'audit, [BACK-END L7] pour la
  persistance du champ dédié consultable en liste, RG-M8 / écran M8-01).
- Un rôle **à privilèges** (administrateur, administrateur d'établissement, responsable sécurité
  — RG-M8-06) ne peut être **activé** sur une affectation tant que l'utilisateur n'a pas le MFA
  configuré : la création/modification d'une `Affectation` vers un tel rôle est refusée (422) si
  `Utilisateur.mfaActif = false`.

### 4.2 MFA — **[BACK-END L7]** (US-L0-03 différée, US-L7-03)
- **RG-M8-06** — Le MFA est **obligatoire** pour tout rôle à privilèges. La solution impose le
  MFA même en cas de SSO d'entreprise pour ces rôles (décision actée : « MFA délégable à l'IdP,
  mais imposé par la solution pour les rôles à privilèges »).
- Activation du MFA : génération d'un secret TOTP et de codes de récupération à usage unique,
  affichés une seule fois à l'activation.
- Vérification du MFA : à la connexion, un second facteur est exigé si `mfaActif = true` avant
  émission du jeton JWT complet ; un jeton intermédiaire (« pré-authentifié ») porte l'attente du
  second facteur. ⚠ HYPOTHÈSE : mécanisme à deux étapes (jeton court `mfa_en_attente` puis jeton
  final), à confirmer avec l'équipe technique lors du plan.
- Un administrateur peut **réinitialiser le MFA d'un tiers** (perte de l'appareil) : action
  sensible, tracée à l'audit avec avant/après (`mfaActif`).
- Codes de récupération épuisés → l'utilisateur ne peut plus se connecter seul ; seule la
  réinitialisation administrateur débloque la situation (cas limite, §7).

### 4.3 Mot de passe oublié — **[BACK-END L7]** (US-L0-03 différée)
- Demande de réinitialisation : un jeton à usage unique et durée limitée (⚠ HYPOTHÈSE : 1 h) est
  émis et envoyé par e-mail à l'adresse du compte, que le compte existe ou non (pas de fuite
  d'information sur l'existence d'un e-mail).
- La réinitialisation consomme le jeton, exige un nouveau mot de passe conforme à la politique de
  robustesse déjà en vigueur (hachage RG-SOCLE-06 [DÉJÀ SOCLE]), et réinitialise
  `tentativesEchouees`/`verrouilleJusqua`.
- Un jeton déjà utilisé ou expiré est refusé (410/422) sans détail exploitable.

### 4.4 Délégation temporaire de droits — **[BACK-END L7]** (US-L7-07, décision actée)
- **Décision actée (M8) et US-L7-07** — la **date de fin est obligatoire** ; toute création de
  délégation sans date de fin est refusée (422).
- La délégation porte sur **un rôle (ou un sous-ensemble de permissions) et un établissement**,
  au bénéfice d'un utilisateur, pour une période bornée `[dateDebut, dateFin]`.
- À l'échéance (`dateFin` atteinte), la délégation est **révoquée automatiquement**, sans
  intervention manuelle — nécessite une tâche planifiée (§8, dépendances).
- Le déléguant (ou un administrateur) peut **révoquer par anticipation** à tout moment ; la
  révocation est tracée (auteur, date, motif optionnel).
- La délégation active apparaît sur la fiche de l'utilisateur bénéficiaire (endpoint de lecture),
  y compris son statut (`active | revoquee | expiree`) et le déléguant.
- Tant qu'active, une délégation **augmente les droits effectifs** de l'utilisateur sur
  l'établissement concerné : `CalculateurDroits` [DÉJÀ SOCLE pour le mécanisme d'union] doit être
  étendu [BACK-END L7] pour inclure les codes de permission des délégations actives, au même
  titre que les `Affectation`.
- **RG-M8-09** appliquée à la délégation : un délégant ne peut déléguer que des droits qu'il
  possède lui-même sur l'établissement concerné (pas d'élévation de privilège par la délégation).

### 4.5 Conflit de rôles — **[DÉJÀ SOCLE]**, rappel
- **RG-SOCLE-04 / US-L7-08** — décision actée : « la règle la plus restrictive l'emporte ».
  Implémentée aujourd'hui comme **union additive sans mécanisme de refus explicite**
  (`CalculateurDroits`, commentaire du code) : le modèle ne connaît que des permissions
  accordées, jamais de permissions retirées, donc il n'existe pas aujourd'hui de cas concret où
  deux rôles « divergent » au sens d'un conflit accord/refus. Rien à coder dans ce lot ; ⚠
  HYPOTHÈSE : si un futur besoin de « rôle bloquant » apparaît (deny explicite), il faudra une
  évolution du modèle de permission, hors périmètre L7.

### 4.6 Dernier administrateur — **[BACK-END L7]** (RG-M8-07)
- Est considéré « administrateur d'un périmètre » un utilisateur portant, sur ce périmètre
  (établissement), une affectation à un rôle incluant `securite.gerer`.
- Toute opération qui ferait disparaître le **dernier** administrateur d'un périmètre est
  **refusée (422)** avec message explicite :
  - suppression de la dernière `Affectation` « administrateur » sur cet établissement ;
  - suspension (`statut = suspendu`) de l'utilisateur si c'est le seul administrateur restant sur
    au moins un établissement ;
  - suppression/vidage d'un `Role` « administrateur » qui retirerait `securite.gerer` alors qu'il
    est la seule source de ce droit pour l'unique administrateur d'un établissement.
- Il faut d'abord désigner/affecter un remplaçant avant de retirer le dernier administrateur
  (décision actée dans le cahier détaillé, §8).

### 4.7 Plafond d'attribution des droits — **[BACK-END L7]** (RG-M8-09)
- Un administrateur d'établissement ne peut créer/modifier une `Affectation` ou une
  `DelegationDroit` que si l'ensemble des permissions du rôle attribué est **inclus** dans ses
  propres droits effectifs sur cet établissement, et que l'établissement cible est dans son
  **périmètre** (lui-même ou en dessous, une fois le périmètre défini par ses affectations).
- Toute tentative d'attribuer un droit qu'il ne possède pas lui-même, ou hors de son périmètre,
  est refusée (403).
- Un administrateur « groupe » (portant `securite.gerer` sans restriction de périmètre reconnue)
  n'est pas concerné par cette limite.

### 4.8 Rôles : duplication & rôles-modèles — **[BACK-END L7]** (US-L7-04, cahier M8-02)
- Un opérateur habilité (`securite.gerer`) peut **dupliquer un rôle existant** : création d'un
  nouveau `Role` avec le même jeu de `Permission`, nom à saisir, sans lien de dépendance avec
  l'original (copie indépendante).
- `Role` porte un indicateur **modèle** (`estModele: bool`) : un rôle-modèle sert de point de
  départ réutilisable (ex. « Caissier », « Responsable de site », « Contrôleur », « Comptable » —
  cahier M8-02) et peut être dupliqué/instancié sans être lui-même directement affecté à des
  utilisateurs. ⚠ HYPOTHÈSE : les rôles-modèles de référence sont livrés en fixtures, pas
  éditables par un simple utilisateur métier (seulement par un administrateur groupe).
- Modifier les permissions d'un rôle-modèle **propose explicitement** de propager ou non le
  changement aux utilisateurs déjà rattachés à des rôles qui en dérivent (cahier M8-02, critère
  d'acceptation). ⚠ HYPOTHÈSE : nécessite de tracer un lien « dérivé de » (`Role.roleModeleOrigine`)
  au moment de la duplication, pour permettre cette propagation ultérieure ; à confirmer en phase
  de plan (complexité non triviale, pourrait être simplifiée en MVP à « dupliquer sans lien de
  suivi », auquel cas la propagation devient hors-scope — point à trancher).
- Toute modification de rôle reste **tracée à l'audit** [DÉJÀ SOCLE via `AuditWriteSubscriber`
  sur `Role`], avec ajout des valeurs avant/après (§4.10).

### 4.9 Aperçu des droits d'un rôle — **[BACK-END L7]** (US-L7-05, back-end seulement)
- Un nouvel endpoint de lecture retourne, pour un `Role` et un `Etablissement` donnés (paramètres
  de requête), la **liste des codes `module.action`** que ce rôle confère sur cet établissement
  — équivalent de `/me` mais pour un rôle simulé plutôt que pour l'utilisateur courant. Réservé
  à `securite.gerer`.
- Cet aperçu est en **lecture seule** : aucune action n'est exécutée, aucun changement de
  contexte réel n'a lieu pour l'appelant.
- Le rendu visuel de l'aperçu (menus simulés) est **FRONT-END** ; ce lot ne fournit que les
  données brutes des droits résultants.

### 4.10 Journal d'audit exploitable — **[BACK-END L7]** (US-L7-09, RG-M8-05)
- **RG-M8-05** — les entrées d'audit doivent porter les **valeurs avant/après** ; le champ
  n'existe pas aujourd'hui sur `EntreeAudit` ([DÉJÀ SOCLE] pour l'écriture automatique
  auteur/date/action/cible, [BACK-END L7] pour l'ajout et le remplissage de `valeurAvant` /
  `valeurApres`).
- L'API de lecture du journal (`GetCollection` déjà exposée [DÉJÀ SOCLE]) doit accepter des
  **filtres serveur** : par utilisateur (`auteur`), type d'action (`action`), type de cible
  (`cibleType`), établissement, et période (`dateHeure` entre deux bornes) — [BACK-END L7],
  ajout de filtres API Platform (`SearchFilter`, `DateFilter`).
- Un **export** du journal filtré est disponible (format et périmètre à confirmer) — [BACK-END
  L7]. ⚠ HYPOTHÈSE : export CSV synchrone pour un volume raisonnable en MVP ; un export
  asynchrone/paginé sera nécessaire si le volume devient important (non chiffré dans ce lot).
- L'accès au journal reste réservé aux rôles autorisés [DÉJÀ SOCLE, `securite.gerer`] ; ⚠
  HYPOTHÈSE : ajouter une permission plus fine `securite.exporter` ou `securite.lire` pour
  distinguer consultation/export de la gestion complète (aujourd'hui un seul niveau `gerer`),
  cohérent avec l'acteur « Responsable sécurité » du cahier M8 qui ne doit pas pouvoir créer de
  rôles métier.

### 4.11 Matrice des droits (lecture) — **[DÉJÀ SOCLE]**, rappel
- Le référentiel des permissions (`GET /permissions`) et la composition des rôles (`GET
  /roles/{id}` avec ses `permissions`) existent déjà et suffisent à construire une matrice
  module × action côté front. Rien à coder côté API pour la simple lecture ; le rendu de la
  matrice (tableau croisé, cases à cocher) est **FRONT-END**.

### 4.12 Bascule caisse ↔ admin & UI adaptative — **[FRONT-END]**, rappel de dépendance
- **US-L7-01, US-L7-02** sont intégralement des comportements d'interface (sélecteur de mode
  persistant, masquage DOM, recalcul du menu au changement de rôle/établissement, 403 contrôlé
  sur accès direct par URL). Le back-end fournit déjà tout ce dont le front a besoin :
  `GET /me` (droits effectifs [DÉJÀ SOCLE]) et le 403 serveur systématique du `PermissionVoter`
  [DÉJÀ SOCLE]. Aucun développement API supplémentaire n'est requis pour ces deux US.

## 5. Objets de données

| Objet | Champ | Type | Contraintes | Notes |
|---|---|---|---|---|
| Utilisateur *(modifié)* | statut | enum(`invite`,`actif`,`suspendu`) | requis, défaut `invite` à la création par admin | remplace/complète le booléen `actif` existant ; ⚠ HYPOTHÈSE : conserver `actif` en dérivé (`actif = statut === 'actif'`) pour compat avec le code existant |
| Utilisateur *(modifié)* | jetonInvitation | string, nullable | unique si non nul, haché | consommé à l'activation |
| Utilisateur *(modifié)* | jetonInvitationExpire | datetime, nullable | | |
| Utilisateur *(modifié)* | dernierAcces | datetime, nullable | mis à jour à chaque connexion réussie | |
| Utilisateur *(modifié)* | mfaActif | bool | défaut `false` | |
| Utilisateur *(modifié)* | mfaSecret | string, nullable, chiffré au repos | jamais exposé en lecture API | ⚠ HYPOTHÈSE : chiffrement applicatif (pas juste hash, car TOTP a besoin du secret en clair pour vérifier) |
| Utilisateur *(modifié)* | mfaCodesRecuperation | liste de hash, nullable | usage unique chacun | |
| JetonReinitialisation *(nouveau)* | id, utilisateur, jeton(hash), dateExpiration, utilise(bool) | uuid, FK, string, datetime, bool | jeton unique, usage unique | mot de passe oublié |
| DelegationDroit *(nouveau)* | id, delegant, beneficiaire, role, etablissement, dateDebut, dateFin, statut, revoquePar, dateRevocation | uuid, FK×3, FK, datetime, datetime, enum(`active`,`revoquee`,`expiree`), FK nullable, datetime nullable | `dateFin` **obligatoire** et > `dateDebut` | RG-M8 décision actée |
| Role *(modifié)* | estModele | bool | défaut `false` | rôles-modèles réutilisables (cahier M8-02) |
| Role *(modifié)* | roleModeleOrigine | Role, nullable (self-FK) | | traçabilité duplication/dérivation, ⚠ HYPOTHÈSE à confirmer en plan |
| EntreeAudit *(modifié)* | valeurAvant | json/text, nullable | | RG-M8-05 |
| EntreeAudit *(modifié)* | valeurApres | json/text, nullable | | RG-M8-05 |
| Permission *(référentiel, éventuel ajout)* | action `exporter` | string | ⚠ HYPOTHÈSE, à trancher | pour distinguer lecture/export d'audit de la gestion complète (`securite.gerer`) |

## 6. Critères d'acceptation

*(back-end uniquement — le rendu UI n'est pas testé ici)*

- **CA-1** — *Étant donné* un administrateur créant un utilisateur, *quand* il valide
  l'invitation, *alors* le compte est créé au statut `invite`, un jeton à durée limitée est
  généré et l'API ne renvoie jamais le mot de passe/jeton en clair.
- **CA-2** — *Étant donné* un jeton d'invitation valide, *quand* l'utilisateur l'utilise pour
  définir son mot de passe, *alors* le statut passe à `actif` et le jeton devient inutilisable ;
  *quand* le jeton est expiré ou déjà utilisé, *alors* l'activation est refusée.
- **CA-3** — *Étant donné* un utilisateur `actif`, *quand* un administrateur le passe à
  `suspendu`, *alors* ses sessions actives sont invalidées immédiatement et un nouvel appel
  authentifié avec son ancien jeton échoue (401).
- **CA-4** — *Étant donné* une affectation vers un rôle à privilèges pour un utilisateur sans MFA
  actif, *quand* l'affectation est créée ou activée, *alors* l'API refuse (422) tant que
  `mfaActif = false`.
- **CA-5** — *Quand* un utilisateur active le MFA, *alors* un secret et des codes de récupération
  à usage unique sont générés et communiqués une seule fois ; *quand* il se connecte ensuite sans
  fournir le second facteur valide, *alors* l'authentification échoue.
- **CA-6** — *Quand* un utilisateur demande une réinitialisation de mot de passe pour un e-mail
  inconnu, *alors* la réponse est identique (pas de fuite) à celle d'un e-mail connu ; *quand* le
  jeton reçu est valide et non expiré, *alors* le nouveau mot de passe est appliqué et le jeton
  devient inutilisable.
- **CA-7** — *Quand* un administrateur crée une délégation sans date de fin, *alors* l'API refuse
  (422) ; *quand* il en crée une avec date de fin, *alors* les droits délégués sont effectifs
  jusqu'à cette date incluse.
- **CA-8** — *Étant donné* une délégation active dont `dateFin` est atteinte, *quand* la tâche de
  révocation s'exécute, *alors* le statut passe à `expiree` et les droits délégués disparaissent
  du calcul des droits effectifs du bénéficiaire, sans action manuelle.
- **CA-9** — *Quand* un déléguant révoque une délégation avant échéance, *alors* le statut passe
  immédiatement à `revoquee` et les droits délégués cessent d'être effectifs.
- **CA-10** — *Étant donné* un administrateur d'établissement ne possédant pas la permission
  `X`, *quand* il tente d'affecter ou de déléguer un rôle contenant `X`, *alors* l'API refuse
  (403).
- **CA-11** — *Étant donné* le seul administrateur (`securite.gerer`) d'un établissement, *quand*
  on tente de supprimer sa dernière affectation « administrateur » sur cet établissement ou de le
  suspendre, *alors* l'API refuse (422) avec un message explicite.
- **CA-12** — *Quand* un opérateur habilité duplique un rôle existant, *alors* un nouveau `Role`
  est créé avec le même jeu de permissions et un nom distinct, indépendant de l'original.
- **CA-13** — *Quand* un opérateur habilité interroge l'aperçu des droits d'un rôle donné sur un
  établissement donné, *alors* l'API retourne la liste des codes `module.action` que ce rôle
  confère sur cet établissement, sans exécuter aucune action ni modifier le contexte de
  l'appelant.
- **CA-14** — *Quand* une action sensible couverte par `AuditWriteSubscriber` modifie une entité,
  *alors* l'entrée d'audit générée porte les valeurs avant et après du changement.
- **CA-15** — *Étant donné* des entrées d'audit existantes, *quand* on interroge le journal avec
  des filtres (auteur, action, cibleType, établissement, période), *alors* seules les entrées
  correspondantes sont retournées ; *quand* on demande un export du résultat filtré, *alors* un
  fichier exploitable est produit.

## 7. Cas limites

- Un utilisateur `invite` dont le jeton expire sans activation : reste bloqué au statut `invite`
  jusqu'à ré-invitation (nouveau jeton) par un administrateur.
- Codes de récupération MFA tous consommés et appareil perdu : seul un administrateur peut
  réinitialiser le MFA de l'utilisateur (action tracée, avant/après `mfaActif`).
- Une délégation est créée alors que le déléguant perd ensuite lui-même le droit délégué (rôle
  modifié après coup) : ⚠ HYPOTHÈSE — la délégation déjà active n'est pas révoquée
  rétroactivement (le contrôle RG-M8-09 s'applique à la création, pas en continu) ; à confirmer,
  un contrôle continu serait plus sûr mais plus coûteux.
- Suspension d'un utilisateur qui a des délégations actives **en tant que bénéficiaire** : ⚠
  HYPOTHÈSE — les délégations restent enregistrées mais devenues inopérantes puisque l'utilisateur
  suspendu n'a plus de session ni de droits effectifs ; pas de révocation automatique de la
  délégation elle-même.
- Suppression d'un `Role` référencé par des `Affectation` ou `DelegationDroit` actives : refusée
  (intégrité), cohérent avec le comportement déjà appliqué aux autres entités du socle.
- Duplication d'un rôle-modèle puis suppression du modèle d'origine : le rôle dupliqué doit
  continuer à fonctionner indépendamment ; si un lien `roleModeleOrigine` est conservé (§4.8), il
  devient nul/orphelin sans casser le rôle dupliqué.
- Deux administrateurs suppriment en parallèle chacun l'affectation de l'autre sur le même
  établissement (course) : la vérification « dernier administrateur » doit être faite en
  transaction pour éviter qu'un périmètre se retrouve sans administrateur.
- Export d'un journal d'audit très volumineux : ⚠ HYPOTHÈSE — hors chiffrage précis dans ce lot,
  prévoir une limite/pagination ou un traitement asynchrone si le volume dépasse un seuil
  raisonnable.
- Écart RG-M8-02 (héritage hiérarchique des rôles) : le socle actuel n'affecte les rôles qu'au
  niveau Établissement, jamais Groupe/Région/Espace. Tant que ce n'est pas retranché
  explicitement par une décision produit, c'est un **point ouvert** signalé ici plutôt qu'un
  développement engagé dans ce lot (aucune US du backlog L7 ne le demande formellement).

## 8. Dépendances

- Dépend de **L0 socle** : `Utilisateur`, `Role`, `Permission`, `Affectation`, `PermissionVoter`,
  `ContexteEtablissement`, `CalculateurDroits`, `JournalAudit`/`EntreeAudit`,
  `AuditWriteSubscriber` (`app/src/Securite/*`, `app/src/Audit/*`, `app/src/Organisation/*`).
- Nécessite un **service d'envoi d'e-mail** (invitation, réinitialisation de mot de passe) : ⚠
  HYPOTHÈSE — `symfony/mailer` n'est pas encore une dépendance du projet (absent de
  `app/composer.json`), à ajouter dans le plan technique.
- Nécessite un mécanisme de **tâche planifiée** (cron applicatif, commande Symfony exécutée
  périodiquement, ou `symfony/scheduler`) pour la révocation automatique des délégations
  expirées et l'expiration des jetons d'invitation/réinitialisation : ⚠ HYPOTHÈSE — aucun
  scheduler/messenger n'est présent aujourd'hui dans `composer.json`, à ajouter.
- Nécessite un mécanisme d'**invalidation de session/JWT** à la suspension d'un compte (le socle
  actuel utilise des JWT stateless — invalider un jeton déjà émis suppose une liste de révocation
  ou une vérification systématique du statut utilisateur à chaque requête) : ⚠ HYPOTHÈSE — à
  confirmer en plan technique, la solution la plus simple étant de vérifier `statut === 'actif'`
  à chaque requête authentifiée (déjà partiellement possible via un event listener comparable à
  `EcouteurConnexion`/`VerificateurUtilisateur`).
- Dépend indirectement de **L1/L2/L3/L4/L5** pour la liste `CLASSES_SURVEILLEES` de
  `AuditWriteSubscriber`, déjà tenue à jour au fil des lots précédents [DÉJÀ SOCLE] ; ce lot y
  ajoute `DelegationDroit` et, le cas échéant, `JetonReinitialisation` n'est **pas** à auditer
  lui-même (donnée sensible mais transitoire, pas une action métier au sens RG-M8-05) — ⚠
  HYPOTHÈSE à confirmer.
