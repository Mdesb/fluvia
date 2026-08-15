# Plan technique — Back-office & Droits : compléments M8 (`L7`)

- **Spec source :** specs/L7-backoffice/spec-backoffice.md
- **Stack :** Symfony 7 · API Platform · Doctrine/MariaDB
- **Couvre :** US-L7-03, US-L7-04, US-L7-05, US-L7-07, US-L7-08, US-L7-09 (back-end) · RG-M8-01,
  05, 06, 07, 09 · CA-1 à CA-15 de la spec
- **Périmètre :** UNIQUEMENT les 9 items **[BACK-END L7]** de `spec-backoffice.md` §2. Le socle L0
  (`Utilisateur`/`Role`/`Permission`/`Affectation`, `PermissionVoter`, `ContexteEtablissement`,
  `CalculateurDroits`, `EntreeAudit`/`AuditWriteSubscriber`, `PerimetreEtablissementExtension`) est
  **étendu**, jamais dupliqué. Le rendu **FRONT-END** (bascule caisse/admin, masquage menus, matrice
  de droits, vue « aperçu rôle ») est hors périmètre : ce plan ne livre que les API qu'il consomme.

> **Réutilisation socle L0 (rappel, non redéfini ici)** — `app/src/Securite/Entity/{Utilisateur,
> Role,Permission,Affectation}.php`, `app/src/Securite/Security/{PermissionVoter,
> VerificateurUtilisateur,EcouteurConnexion}.php`, `app/src/Securite/Service/{CalculateurDroits,
> ContexteEtablissement}.php`, `app/src/Securite/Doctrine/PerimetreEtablissementExtension.php`,
> `app/src/Securite/Controller/MeController.php`, `app/src/Audit/Entity/EntreeAudit.php`,
> `app/src/Audit/Doctrine/AuditWriteSubscriber.php`, `app/config/packages/security.yaml`
> (firewalls `auth`/`api`, JWT `lexik`).

---

## 1. Entités & schéma

Namespace : `App\Securite\Entity\*` (+ `App\Securite\Enum\*` pour les enums). `id` = UUID
(`Symfony\Component\Uid\Uuid`, type Doctrine `uuid`). `declare(strict_types=1)` partout.

### 1.1 `Utilisateur` — extensions

| Champ | Type Doctrine | Null | Contrainte | Notes |
|---|---|---|---|---|
| `statut` | `string(12)` enum `StatutUtilisateur` {`invite`,`actif`,`suspendu`} | non | défaut `invite` | RG-M8-01. **Remplace la colonne persistée `actif`** (migration : backfill `statut` depuis l'ancienne valeur `actif` avant `DROP COLUMN actif`, §5). |
| `jetonInvitation` | `string(255)` **haché** (sha256) | oui | unique si non nul | consommé à l'activation (CA-2) |
| `jetonInvitationExpire` | `datetime_immutable` | oui | — | 72 h par défaut (⚠ HYPOTHÈSE spec, paramètre `securite.duree_invitation_heures`) |
| `dernierAcces` | `datetime_immutable` | oui | mis à jour à chaque connexion réussie (2ᵉ facteur inclus) | RG-M8-01, écran M8-01 |
| `mfaActif` | `boolean` | non | défaut `false` | RG-M8-06 |
| `mfaSecret` | `string(255)` | oui | **chiffré au repos** (libsodium, §2.3), jamais exposé en lecture API | secret TOTP en clair nécessaire à la vérification, donc chiffrement réversible (pas un hash) |
| `mfaCodesRecuperation` | `json` (liste de hash sha256) | oui | chaque hash consommé une seule fois | générés à l'activation, jamais réexposés en clair après coup |
| `tokenVersion` | `integer` | non | défaut `0` | **stratégie d'invalidation JWT** (§2.2) : incrémenté à la suspension et à la réinitialisation de mot de passe |
| `clientLie` | *(inchangé, socle/CRM)* | — | — | — |

- **Accesseur de compatibilité** `isActif()`/`setActif(bool)` **conservés** mais **non persistés** :
  `isActif()` retourne `statut === StatutUtilisateur::Actif` ; `setActif(true)` ⇒
  `setStatut(Actif)`, `setActif(false)` ⇒ `setStatut(Suspendu)`. Décision explicite pour ne
  **rien casser** côté `VerificateurUtilisateur`, `MeController`, fixtures existantes
  (`SocleFixtures`, `CrmFixtures` appellent `setActif(true)`) — zéro fichier de fixtures à modifier.

### 1.2 `Role` — extensions

| Champ | Type Doctrine | Null | Contrainte | Notes |
|---|---|---|---|---|
| `estModele` | `boolean` | non | défaut `false` | cahier M8-02, US-L7-04 |
| `roleModeleOrigine` | self `ManyToOne` (`Role`) | oui | `ON DELETE SET NULL` | tracé à **chaque duplication** (pas seulement depuis un modèle), pour permettre une évolution future de propagation (§2.8) ; devient orphelin proprement si l'origine est supprimée (cas limite spec §7) |

### 1.3 `Permission` — référentiel, ajout de données (pas de nouveau champ)

- Deux nouvelles lignes `module='securite'` : `action='lire'`, `action='exporter'` — distinguent
  consultation/export (Responsable sécurité) de la gestion complète (`securite.gerer`), §4 spec.
  Migration de données (§5), pas de changement de schéma sur `Permission`.

### 1.4 `DelegationDroit` *(nouveau)*

| Champ | Type Doctrine | Null | Contrainte | Notes |
|---|---|---|---|---|
| `id` | `uuid` | non | PK | — |
| `delegant` | `ManyToOne Utilisateur` | non | FK | qui délègue |
| `beneficiaire` | `ManyToOne Utilisateur` | non | FK | qui reçoit |
| `role` | `ManyToOne Role` | non | FK | le sous-ensemble de permissions délégué = celui du rôle (⚠ HYPOTHÈSE : pas de sélection fine « sous-ensemble de permissions » distincte du rôle en MVP — délègue toujours un `Role` entier, plus simple et testable ; cf. Risques) |
| `etablissement` | `ManyToOne Etablissement` | non | FK | périmètre de la délégation |
| `dateDebut` | `datetime_immutable` | non | — | — |
| `dateFin` | `datetime_immutable` | non | **obligatoire, `> dateDebut`** (Assert + contrainte applicative) | RG-M8, décision actée, CA-7 |
| `statut` | `string(12)` enum `StatutDelegation` {`active`,`revoquee`,`expiree`} | non | défaut `active` | — |
| `motifRevocation` | `string(255)` | oui | — | révocation anticipée (CA-9) |
| `revoquePar` | `ManyToOne Utilisateur` | oui | FK | requis si `statut=revoquee` |
| `dateRevocation` | `datetime_immutable` | oui | requis si `statut ∈ {revoquee,expiree}` | — |
| `dateCreation` | `datetime_immutable` | non | — | — |

Table `sec_delegation_droit` ; **index** `(beneficiaire_id, etablissement_id, statut)` (lecture
`CalculateurDroits`, chaque requête HTTP) ; index `(statut, date_fin)` (commande d'expiration, §5).
Ajoutée à `AuditWriteSubscriber::CLASSES_SURVEILLEES` (RG-M8-05).

### 1.5 `JetonReinitialisation` *(nouveau)*

| Champ | Type Doctrine | Null | Contrainte | Notes |
|---|---|---|---|---|
| `id` | `uuid` | non | PK | — |
| `utilisateur` | `ManyToOne Utilisateur` | non | FK | — |
| `jeton` | `string(255)` **haché** (sha256) | non | unique | jamais stocké en clair |
| `dateExpiration` | `datetime_immutable` | non | — | 1 h par défaut (⚠ HYPOTHÈSE spec, paramètre `securite.duree_reinitialisation_heures`) |
| `utilise` | `boolean` | non | défaut `false` | usage unique (CA-6) |
| `dateCreation` | `datetime_immutable` | non | — | — |

Table `sec_jeton_reinitialisation`. **Non ajoutée** à `CLASSES_SURVEILLEES` (donnée sensible mais
transitoire, pas une action métier au sens RG-M8-05 — cf. spec §8) ; l'action métier auditée est
`mot_de_passe.reinitialise` sur la cible `Utilisateur` (via `JournalAudit::enregistrer` explicite
dans le handler, pas via le subscriber générique). Pas de ressource `#[ApiResource]` : entité
interne, jamais exposée en CRUD (créée/consommée uniquement par les contrôleurs dédiés, §3).

### 1.6 `EntreeAudit` — extensions

| Champ | Type Doctrine | Null | Notes |
|---|---|---|---|
| `valeurAvant` | `json` (nullable) | oui | snapshot des champs scalaires **avant** modification (`null` en création) |
| `valeurApres` | `json` (nullable) | oui | snapshot des champs scalaires **après** modification (`null` en suppression) |

Champs exclus du snapshot (jamais en clair dans l'audit, quel que soit `avant`/`apres`) :
`Utilisateur::motDePasse`, `mfaSecret`, `mfaCodesRecuperation`, `jetonInvitation` — liste portée
par une constante `AuditWriteSubscriber::CHAMPS_SENSIBLES` (map classe → champs exclus), cf. §2.10.

### 1.7 Enums (`App\Securite\Enum\*`)

`StatutUtilisateur` {`invite`,`actif`,`suspendu`}, `StatutDelegation`
{`active`,`revoquee`,`expiree`}.

---

## 2. Décisions structurantes

### 2.1 Cycle de vie utilisateur & invitation (RG-M8-01, US-L7-03)

- `POST /api/utilisateurs` (opération **existante**, `UtilisateurProcessor` étendu) : si aucun mot
  de passe n'est fourni en écriture (cas standard d'un admin créant un compte), le processor génère
  un `jetonInvitation` haché + `jetonInvitationExpire = now + 72h`, force `statut = invite`, et
  déclenche l'envoi d'un e-mail (`InvitationMailer`, §2.4/§8) contenant un lien front avec le jeton
  en clair (jamais renvoyé par l'API — seul le hash est persisté). Si un mot de passe **est**
  fourni (fixtures/tests/migration de données existants), le comportement legacy est conservé
  (`statut = actif` directement) — pas de régression sur les fixtures socle/CRM.
- `POST /api/utilisateurs/activation` (contrôleur public, `App\Securite\Controller
  \ActivationController`, même patron que `MeController`) : `{jeton, motDePasse}` → jeton
  inconnu/expiré/déjà consommé ⇒ 410/422 sans détail exploitable ; sinon hash du mot de passe,
  `statut = actif`, `jetonInvitation = null`, `jetonInvitationExpire = null` (CA-1, CA-2).
- `POST /api/utilisateurs/{id}/reinviter` (opération API Platform custom, `securite.gerer`) :
  régénère un jeton si l'ancien a expiré (cas limite spec §7).
- `POST /api/utilisateurs/{id}/suspendre` / `POST /api/utilisateurs/{id}/reactiver` (custom,
  `securite.gerer`, processor `App\Securite\State\SuspensionUtilisateurProcessor`) : suspension ⇒
  `statut = suspendu` **et `tokenVersion++`** (invalidation JWT, §2.2) après vérification du
  garde-fou « dernier administrateur » (§2.6, CA-11) ; réactivation ⇒ `statut = actif`, pas
  d'incrément de `tokenVersion` (aucune session à invalider en ré-autorisant).
- `dernierAcces` : `EcouteurConnexion::onSucces` étendu pour appeler `$utilisateur->setDernierAcces(new
  \DateTimeImmutable())` avant flush (déjà dans la même transaction que le reset des tentatives).
- **Garde MFA sur affectation à un rôle à privilèges (CA-4)** : « rôle à privilèges » = tout `Role`
  dont au moins une `Permission` a `module === 'securite'` (couvre `gerer`/`lire`/`exporter` —
  Administrateur, Administrateur d'établissement, Responsable sécurité, RG-M8-06). Le nouveau
  `AffectationProcessor` (§4) refuse (422) la création/modification d'une `Affectation` vers un tel
  rôle si `beneficiaire.mfaActif === false`. Corollaire (durcissement non explicitement demandé
  mais nécessaire pour ne pas re-créer la faille en sens inverse) : `POST
  /utilisateurs/{id}/mfa/desactiver` refuse (422) si l'utilisateur détient encore une `Affectation`
  active vers un rôle à privilèges.

### 2.2 Invalidation de session/JWT à la suspension — `tokenVersion` (§8 spec, arbitrage imposé)

Le JWT reste **stateless** (pas de liste de révocation, pas de session serveur) :

1. `App\Securite\Security\JwtClaimsAbonnee` (écoute `lexik_jwt_authentication.on_jwt_created`,
   `Lexik\Bundle\JWTAuthenticationBundle\Event\JWTCreatedEvent`) ajoute au payload le claim
   `tokenVersion` = valeur courante de `Utilisateur::tokenVersion` au moment de l'émission.
2. `App\Securite\Security\VerificateurJwtActifListener` (écoute
   `lexik_jwt_authentication.on_jwt_authenticated`,
   `Lexik\Bundle\JWTAuthenticationBundle\Event\JWTAuthenticatedEvent`, déclenché **à chaque requête
   authentifiée** sur le firewall `api`) compare `payload['tokenVersion']` à
   `$user->getTokenVersion()` **et** vérifie `$user->getStatut() === Actif` ; en cas de désaccord,
   invalide le token courant (`$event->getToken()` retiré du contexte de sécurité via une
   exception `AuthenticationException` capturée par le firewall ⇒ 401). Un jeton émis avant la
   suspension échoue donc dès la requête suivante (CA-3), sans registre de révocation à
   maintenir/purger.
3. `tokenVersion` est également incrémenté à la réinitialisation de mot de passe (§2.4) — bonne
   pratique standard, déconnecte les sessions existantes après un changement de mot de passe.

Ce même listener sert de point d'entrée unique pour la restriction du jeton « pré-authentifié »
MFA (§2.3, claim `mfaEnAttente`).

### 2.3 MFA — TOTP (RG-M8-06, US-L7-03)

- **Librairie recommandée : `spomky-labs/otphp`** (RFC 6238, ~aucune dépendance, pas de bundle
  Symfony imposé) plutôt que `scheb/2fa-*` : ce dernier est un bundle complet avec son propre flux
  de connexion/formulaires, redondant et en partie incompatible avec le firewall JWT stateless
  déjà en place (`json_login` + `lexik`) — `otphp` se contente de générer/vérifier des codes,
  laissant l'orchestration du flux à deux étapes (ci-dessous) entièrement dans `App\Securite`.
- **Chiffrement du secret au repos** : `App\Securite\Crypto\ChiffreurSecret` (libsodium,
  `sodium_crypto_secretbox`, clé 32 octets depuis `%env(base64:MFA_ENCRYPTION_KEY)%`) —
  chiffrement réversible (pas un hash) car la vérification TOTP a besoin du secret en clair à
  chaque connexion. Les **codes de récupération**, eux, sont à usage unique et haute entropie ⇒
  stockés en `sha256` (non réversible, suffisant, pas besoin d'un hash lent type bcrypt).
- **Activation en 2 temps** (évite un verrouillage si l'utilisateur mal-scanne le QR) :
  1. `POST /api/utilisateurs/{id}/mfa/activer` (self uniquement, `IS_AUTHENTICATED_FULLY` +
     `object == user`) → génère secret TOTP + 8 codes de récupération, chiffre/hache et **persiste**
     (mais `mfaActif` reste `false`), retourne **une seule fois** le secret en clair (+ URI
     `otpauth://totp/...`) et les codes en clair (CA-5).
  2. `POST /api/utilisateurs/{id}/mfa/confirmer {code}` → vérifie le code TOTP courant ; si valide,
     `mfaActif = true` (avant/après audité, §2.10) ; sinon 422 (secret non activé, à refaire).
  - `POST /api/utilisateurs/{id}/mfa/desactiver {code}` (self, code TOTP ou de récupération requis)
    — refusé si rôle à privilèges actif (§2.1).
  - `POST /api/utilisateurs/{id}/mfa/reinitialiser` (**admin**, `securite.gerer`) : remet
    `mfaActif=false`, purge `mfaSecret`/`mfaCodesRecuperation` — action sensible tracée avant/après
    (§2.10, cas limite « codes épuisés + appareil perdu », spec §7).
- **Connexion à deux étapes** (CA-5) :
  1. `POST /auth` (firewall `auth`, `json_login`, inchangé) authentifie email+mot de passe. Un
     nouveau `success_handler` **décorateur** `App\Securite\Security\GestionnaireSuccesConnexionMfa`
     (implémente `AuthenticationSuccessHandlerInterface`, wrappe
     `lexik_jwt_authentication.handler.authentication_success`) :
     - si `mfaActif === false` : délègue tel quel au handler lexik (jeton complet immédiat,
       comportement socle inchangé, CA-2 socle non régressé) ; met à jour `dernierAcces`.
     - si `mfaActif === true` : **n'émet pas** de jeton complet ; construit un jeton **court**
       (`JWTTokenManagerInterface::createFromPayload($user, ['mfaEnAttente' => true], ttl 300 s)`)
       et répond `200 {mfaRequis: true, jetonPreAuth: "..."}`.
  2. `POST /auth/mfa-verifier` (contrôleur public dans le firewall `api`, `App\Securite\Controller
     \VerificationMfaController`, authentifié via le `jetonPreAuth` en `Authorization: Bearer`)
     `{code}` : `VerificateurJwtActifListener` (§2.2) **bloque déjà** toute autre route tant que le
     claim `mfaEnAttente` est présent (seules `/auth/mfa-verifier` et `/health` restent
     accessibles avec ce jeton — 401 sinon) ; le contrôleur vérifie `code` contre le secret déchiffré
     (TOTP) **ou** un code de récupération non consommé (le marque consommé si utilisé) ; échec ⇒
     401 + comptage sur `tentativesEchouees`/`verrouilleJusqua` réutilisé (`EcouteurConnexion`,
     même politique de verrouillage que le mot de passe) ; succès ⇒ émission du jeton complet
     (`JWTTokenManagerInterface::create($user)`, claim `tokenVersion` ajouté par le listener §2.2),
     `dernierAcces` mis à jour, `connexion.mfa_succes` journalisé.
- MFA « délégable à l'IdP » (décision actée cahier) : aucun connecteur SSO dans ce lot (rappel spec
  §2 exclusions) — le champ `mfaActif` reste la seule source de vérité, imposé quel que soit le
  mode de connexion futur.

### 2.4 Mot de passe oublié (US-L0-03 différée)

- `POST /mot-de-passe/oublie` (contrôleur public, `App\Securite\Controller
  \DemandeReinitialisationController`) `{email}` : répond **toujours** `202` identique, que
  l'e-mail existe ou non (CA-6, pas de fuite). Si l'utilisateur existe, crée un
  `JetonReinitialisation` (jeton haché, `dateExpiration = now + 1h`) et envoie l'e-mail
  (`ReinitialisationMailer`, §8) avec le jeton en clair.
- `POST /mot-de-passe/reinitialiser` (public, `App\Securite\Controller
  \ReinitialisationMotDePasseController`) `{jeton, nouveauMotDePasse}` : jeton inconnu / expiré /
  déjà `utilise` ⇒ 410/422 générique ; sinon hash du nouveau mot de passe, `utilise = true`,
  `tentativesEchouees = 0`, `verrouilleJusqua = null`, **`tokenVersion++`** (déconnecte les
  sessions existantes, §2.2), action `mot_de_passe.reinitialise` journalisée explicitement (avant :
  `{motDePasseHash: "***"}`, après : `{motDePasseHash: "***"}` — jamais le hash réel, juste un
  marqueur, cf. §2.10 champs sensibles).

### 2.5 Délégation temporaire — intégration `CalculateurDroits` (US-L7-07)

`CalculateurDroits::codesEffectifs()` (socle, **étendu sans changer sa signature publique**) :
après l'union des permissions issues des `Affectation`, ajoute l'union des permissions du `Role`
de chaque `DelegationDroit` où `beneficiaire = $utilisateur`, `statut = active`,
`dateDebut <= now <= dateFin`, et (si `$etablissementActif` fourni) `etablissement =
$etablissementActif` — **même symétrie** que la branche existante pour les affectations (si
`$etablissementActif` est `null`, les délégations actives de **tous** les établissements sont
incluses, comme le fait déjà le code pour les affectations). La double vérification `dateFin` (en
plus du statut, déjà mis à `expiree` par la commande planifiée) est une garde défensive contre un
retard d'exécution de la commande. Testé par CA-7/CA-8/CA-9 + un test unitaire dédié
`CalculateurDroitsTest` qui **ne modifie aucun test socle existant** (non-régression explicite).

- **Révocation automatique à échéance (CA-8)** : commande `securite:delegations:expirer`
  (`App\Securite\Command\ExpirerDelegationsCommand`) : `UPDATE sec_delegation_droit SET statut =
  'expiree', date_revocation = NOW() WHERE statut = 'active' AND date_fin < NOW()` — idempotente,
  sans effet de bord si aucune ligne. Ordonnancement : **commande console + cron externe** (choix
  documenté §8/§9, pas de nouvelle dépendance `symfony/scheduler`/worker à faire tourner en
  continu — cohérent avec l'arbitrage « pas d'infra lourde » retenu pour le JWT).
- **Révocation anticipée (CA-9)** : `POST /api/delegations/{id}/revoquer {motif?}`
  (`securite.gerer`, ou `object.getDelegant() == user`) → `statut = revoquee`, `revoquePar`,
  `dateRevocation`, `motifRevocation` — effet immédiat sur `codesEffectifs()` (filtré sur
  `statut=active`, déjà exclu).
- **Plafond (RG-M8-09) appliqué à la création** — §2.7, appliqué aussi bien à `Affectation` qu'à
  `DelegationDroit` via le même service `VerificateurPlafondDroits`.

### 2.6 Garde-fou « dernier administrateur » (RG-M8-07, CA-11)

Service `App\Securite\Service\GardeDernierAdministrateur` :
`estDernierAdministrateur(Utilisateur $u, Etablissement $e): bool` — vrai si l'`Affectation` visée
est la **seule** sur `$e` dont le `Role` contient `securite.gerer`. Appelé, **dans une transaction
avec verrou pessimiste** (`LockMode::PESSIMISTIC_WRITE` sur les lignes `Affectation` concernées de
l'établissement — évite la course « deux suppressions concurrentes », cas limite spec §7), par
trois points :
1. `AffectationProcessor::process()` (Delete) — refuse (422) la suppression de la dernière
   affectation « administrateur » d'un établissement.
2. `SuspensionUtilisateurProcessor` — refuse (422) la suspension si l'utilisateur est le seul
   administrateur d'au **moins un** établissement (parcourt ses `Affectation` portant
   `securite.gerer`).
3. `RoleProcessor::process()` (Patch/Delete) — refuse (422) le retrait de `securite.gerer` d'un
   `Role` (ou sa suppression) si un établissement se retrouverait sans administrateur (parcourt les
   `Affectation` de ce rôle).
Message d'erreur explicite (`422 {"message": "Impossible : dernier administrateur de
l'établissement <nom>. Désignez un remplaçant avant de retirer ce droit."}`).

### 2.7 Plafond d'attribution des droits (RG-M8-09, CA-10)

Service `App\Securite\Service\VerificateurPlafondDroits::verifier(Utilisateur $auteur,
Etablissement $cible, iterable<Permission> $permissionsAAttribuer): void` (lève
`AccessDeniedException` → 403) : les codes des `$permissionsAAttribuer` doivent être un
sous-ensemble de `CalculateurDroits::codesEffectifs($auteur, $cible->getId())`. Appelé par
`AffectationProcessor` (Post) et `DelegationDroitProcessor` (Post).

- ⚠ **Point ouvert explicitement assumé** (à ne pas laisser bloquer ce lot, cf. Risques) :
  l'exemption « administrateur groupe non concerné par le plafond » (texte RG-M8-09) suppose une
  notion d'affectation **au niveau Groupe**, qui n'existe pas dans le socle L0 actuel
  (`Affectation.etablissement` est toujours renseigné, jamais de rattachement Groupe/Région direct
  — c'est le même écart que RG-M8-02 déjà signalé par la spec §2). Ce plan **n'invente pas** de
  mécanisme d'exemption : la règle plafond s'applique **uniformément** à tout auteur, y compris un
  porteur de `securite.gerer` sur plusieurs établissements (il reste comparé établissement par
  établissement, ce qui le couvre déjà largement en pratique puisqu'il détient alors la totalité
  des droits sur chacun). Le CA-10 testé (administrateur d'établissement) est pleinement couvert ;
  l'exemption fine reste un point à trancher avec l'évolution RG-M8-02 (hors lot).

### 2.8 Duplication de rôle & rôles-modèles (US-L7-04)

- `POST /api/roles/{id}/dupliquer {nom}` (`securite.gerer`) — `App\Securite\State
  \DuplicationRoleProcessor` : crée un nouveau `Role` (nouveau nom, `estModele = false`,
  `roleModeleOrigine = role source`), copie **indépendante** de l'ensemble des `Permission`
  (CA-12). Fonctionne aussi bien sur un rôle-modèle que sur un rôle ordinaire.
- `Role.estModele` filtrable (`GetCollection` + `BooleanFilter estModele`) pour que le front liste
  les modèles séparément.
- **Propagation d'une modification de modèle aux dérivés** (cahier M8-02) : **hors scope
  d'implémentation dans ce lot** — le lien `roleModeleOrigine` est posé (traçabilité, coût
  négligeable) pour ne pas fermer la porte, mais aucune logique de propagation interactive
  (« proposer de propager oui/non ») n'est construite ici, faute de spécification suffisamment
  précise du comportement attendu (US-L7-04 ne le détaille pas). Documenté en Risques.
- **Rôles-modèles de référence** livrés par **migration de données** (§5), créés **vides** (aucune
  permission pré-assignée) : `Caissier`, `Responsable de site`, `Contrôleur`, `Comptable` — le
  cahier M8-02 les nomme mais ne fixe pas la composition exacte en permissions module×action
  (référentiel `Permission` propre à chaque module livré au fil des lots) ; composer leur contenu
  précis relèverait d'un arbitrage produit hors périmètre de ce plan technique. Un administrateur
  groupe les complète ensuite via `PATCH /api/roles/{id}` (existant).

### 2.9 Aperçu des droits d'un rôle (US-L7-05, back-end)

`GET /api/roles/{id}/apercu-droits?etablissement={uuid}` (`securite.gerer`, State **Provider**
`App\Securite\State\ApercuDroitsRoleProvider`) : valide l'existence de `Role` et `Etablissement`
(404 sinon), retourne `{roleId, etablissementId, codes: ["module.action", ...]}` = les codes des
`Permission` du rôle (le socle actuel ne restreint pas les permissions d'un rôle par
établissement — cf. RG-M8-02 non implémenté §2.7 — donc le résultat est aujourd'hui équivalent à
`role.permissions` mappé en codes ; le paramètre `etablissement` est **conservé et validé** dès ce
lot pour ne pas casser le contrat d'API si une restriction par établissement est introduite plus
tard). **Lecture seule** (Provider, pas de Processor) : aucune écriture, aucun changement de
`ContexteEtablissement` réel pour l'appelant (CA-13).

### 2.10 Audit avant/après + filtres + export (RG-M8-05, US-L7-09)

- `AuditWriteSubscriber::creerEntree()` étendu : calcule `valeurAvant`/`valeurApres` via un
  nouveau service `App\Audit\Service\InstantaneEntiteBuilder::capturer(object $entite): array` —
  ne lit **que les champs scalaires** de `ClassMetadata::getFieldNames()` (pas les associations,
  évite la sérialisation d'objets liés/récursion), exclut les champs listés dans
  `AuditWriteSubscriber::CHAMPS_SENSIBLES[$classe] ?? []` (§1.6). `creation` ⇒ `avant=null,
  apres=capturer(nouvel état)` ; `suppression` ⇒ `avant=capturer(état avant suppression),
  apres=null` ; `modification` ⇒ `avant`/`apres` reconstruits depuis `$uow->getEntityChangeSet
  ($entity)` (déjà disponible à `onFlush`, ne nécessite pas de requête supplémentaire) — CA-14.
- Filtres API Platform sur `GetCollection` de `EntreeAudit` : `SearchFilter` exact sur `auteur`
  (partiel), `action` (exact), `cibleType` (exact), `etablissement` (exact) + `DateFilter` sur
  `dateHeure` (bornes) — CA-15.
- `GET /api/audit/export` (contrôleur, `App\Securite\Controller\ExportAuditController`,
  `securite.gerer` ou `securite.exporter`) : applique les **mêmes filtres** en query string,
  réutilise le `Repository`/`QueryBuilder` d'API Platform (pas de logique de filtrage dupliquée —
  invoque le même `FilterCollection` via le state provider standard, puis stream un CSV
  (`StreamedResponse`)). ⚠ HYPOTHÈSE reconduite de la spec : export CSV **synchrone**, borné en
  MVP (limite dure de lignes documentée en Risques, pas de traitement asynchrone dans ce lot).
- Nouvelles permissions `securite.lire` (lecture du journal) et `securite.exporter` (export) —
  `security:` de `GetCollection`/`Get` sur `EntreeAudit` devient `is_granted('PERM',
  'securite.gerer') or is_granted('PERM', 'securite.lire')` ; export : `is_granted('PERM',
  'securite.gerer') or is_granted('PERM', 'securite.exporter')`.

---

## 3. API (API Platform)

| Ressource / route | Opération | `security:` | Groupes sérialisation | Notes |
|---|---|---|---|---|
| `Utilisateur` (existant) | `Post` | `securite.gerer` | `utilisateur:write`/`utilisateur:read` | processor étendu : invitation auto (§2.1) |
| `Utilisateur` | `POST /utilisateurs/{id}/suspendre` (custom) | `securite.gerer` | `utilisateur:read` | garde dernier admin (§2.6), `tokenVersion++` |
| `Utilisateur` | `POST /utilisateurs/{id}/reactiver` (custom) | `securite.gerer` | `utilisateur:read` | — |
| `Utilisateur` | `POST /utilisateurs/{id}/reinviter` (custom) | `securite.gerer` | — | nouveau jeton d'invitation |
| — | `POST /utilisateurs/activation` (contrôleur public) | `PUBLIC_ACCESS` | — | CA-1, CA-2 |
| `Utilisateur` | `POST /utilisateurs/{id}/mfa/activer` (custom) | `IS_AUTHENTICATED_FULLY` + `object == user` | `mfa:activation` (secret+codes, une fois) | §2.3 |
| `Utilisateur` | `POST /utilisateurs/{id}/mfa/confirmer` (custom) | idem | — | — |
| `Utilisateur` | `POST /utilisateurs/{id}/mfa/desactiver` (custom) | idem | — | refus si rôle à privilèges actif |
| `Utilisateur` | `POST /utilisateurs/{id}/mfa/reinitialiser` (custom) | `securite.gerer` | — | action tracée avant/après |
| — | `POST /auth/mfa-verifier` (contrôleur, firewall `api`) | jeton pré-auth requis | — | §2.3 |
| — | `POST /mot-de-passe/oublie` (contrôleur public) | `PUBLIC_ACCESS` | — | CA-6, réponse uniforme |
| — | `POST /mot-de-passe/reinitialiser` (contrôleur public) | `PUBLIC_ACCESS` | — | CA-6 |
| `DelegationDroit` *(nouveau)* | `GetCollection`/`Get` | `securite.gerer` / `securite.lire` **ou** `object.getBeneficiaire() == user` | `delegation:read` | filtres `beneficiaire`, `etablissement`, `statut` |
| `DelegationDroit` | `Post` | `securite.gerer` | `delegation:write` | processor : `dateFin` obligatoire (422), plafond (§2.7) |
| `DelegationDroit` | `POST /delegations/{id}/revoquer` (custom) | `securite.gerer` **ou** `object.getDelegant() == user` | — | CA-9 |
| `Role` (existant) | `POST /roles/{id}/dupliquer` (custom) | `securite.gerer` | `role:read` | CA-12 |
| `Role` | `GET /roles/{id}/apercu-droits` (custom Provider) | `securite.gerer` | — | CA-13, lecture seule |
| `Role` (existant, `GetCollection`) | — | `securite.gerer` | + filtre `estModele` (BooleanFilter) | §2.8 |
| `EntreeAudit` (existant) | `GetCollection`/`Get` | `securite.gerer` ou `securite.lire` | `audit:read` (+ `valeurAvant`/`valeurApres`) | filtres `auteur`, `action`, `cibleType`, `etablissement`, `dateHeure` (`DateFilter`) |
| — | `GET /audit/export` (contrôleur) | `securite.gerer` ou `securite.exporter` | — | CSV, mêmes filtres |

---

## 4. Sécurité & droits

- **Permissions nouvelles** : `securite.lire`, `securite.exporter` (module `securite` déjà
  existant). Aucun nouveau **module** de permission.
- **Aucun nouveau Voter** : tout reste porté par `PermissionVoter` (attribut `PERM`) du socle,
  inchangé. Les nouvelles règles (plafond, dernier admin, MFA obligatoire, `dateFin` obligatoire)
  sont des **invariants métier** appliqués dans des **State Processors** dédiés (pas des Voters,
  car elles dépendent de l'objet en cours d'écriture, pas seulement du couple module×action) :
  - `App\Securite\State\AffectationProcessor` (Post : plafond §2.7 + garde MFA §2.1 ; Delete :
    garde dernier admin §2.6).
  - `App\Securite\State\RoleProcessor` (Patch/Delete : garde dernier admin §2.6).
  - `App\Securite\State\DelegationDroitProcessor` (Post : `dateFin` obligatoire, plafond §2.7).
  - `App\Securite\State\SuspensionUtilisateurProcessor` (garde dernier admin §2.6, `tokenVersion++`).
  - `App\Securite\State\DuplicationRoleProcessor`, `App\Securite\State\ApercuDroitsRoleProvider`
    (pas d'invariant de garde, juste `securite.gerer`).
- **`ContexteEtablissement`/`PerimetreEtablissementExtension`** : réutilisés tels quels ; une
  nouvelle extension **`App\Securite\Doctrine\PerimetreDelegationExtension`** restreint
  `GetCollection(DelegationDroit)` à « périmètre visible (`securite.gerer`/`lire`) **ou**
  `beneficiaire = utilisateur courant` », même patron que `PerimetreEtablissementExtension`.

---

## 5. Migrations

1. **Migration structurelle** (`app/migrations/VersionYYYYMMDDHHMMSS_l7_schema.php`) :
   - `sec_utilisateur` : ajoute `statut`, `jeton_invitation`, `jeton_invitation_expire`,
     `dernier_acces`, `mfa_actif`, `mfa_secret`, `mfa_codes_recuperation`, `token_version`
     (défaut `0`) ; **backfill** `statut = CASE WHEN actif THEN 'actif' ELSE 'suspendu' END` puis
     `DROP COLUMN actif` (dans le même fichier, schéma + données + schéma, comme
     `VersionM4_parametres_defaut` en L5).
   - `sec_role` : ajoute `est_modele` (défaut `false`), `role_modele_origine_id` (FK nullable,
     self, `ON DELETE SET NULL`).
   - Crée `sec_delegation_droit` (FKs `delegant_id`/`beneficiaire_id`/`revoque_par_id` →
     `sec_utilisateur`, `role_id` → `sec_role`, `etablissement_id` → `org_etablissement` ; index
     `(beneficiaire_id, etablissement_id, statut)`, `(statut, date_fin)`).
   - Crée `sec_jeton_reinitialisation` (FK `utilisateur_id`, unique `jeton`).
   - `audit_entree` : ajoute `valeur_avant` (`json`/`longtext`), `valeur_apres` (`json`/`longtext`),
     nullable.
2. **Migration de données — permissions** : insère `Permission(module='securite', action='lire')`
   et `Permission(module='securite', action='exporter')`.
3. **Migration de données — rôles-modèles** : insère 4 `Role(estModele=true, permissions=[])` :
   `Caissier`, `Responsable de site`, `Contrôleur`, `Comptable` (§2.8, composition volontairement
   vide).
- Rejouables, versionnées Doctrine ; jamais de `schema:update --force` (constitution §7).

---

## 6. Tests (PHPUnit + `ApiTestCase`, `App\Tests\Securite\*`, base `SecuriteApiTestCase` sur le
patron de `CrmApiTestCase`)

| Test | Type | Couvre |
|---|---|---|
| Création par admin ⇒ `statut=invite`, jeton généré (haché en base), jamais renvoyé en clair par l'API | API | CA-1 |
| Activation avec jeton valide ⇒ `statut=actif`, jeton consommé ; jeton expiré/déjà utilisé ⇒ refus | API | CA-2 |
| Suspension ⇒ ancien JWT refusé (401) dès la requête suivante (`tokenVersion`) ; historique audit conservé | API | CA-3 |
| Affectation vers rôle à privilèges sans MFA ⇒ 422 ; avec MFA actif ⇒ OK | API | CA-4, RG-M8-06 |
| Activation MFA (2 temps) : secret+codes affichés une fois ; connexion sans 2ᵉ facteur ⇒ refusée | API | CA-5 |
| Mot de passe oublié : réponse identique email connu/inconnu ; jeton valide ⇒ nouveau mot de passe, jeton consommé ; sessions existantes invalidées (`tokenVersion`) | API | CA-6 |
| Délégation sans `dateFin` ⇒ 422 ; avec `dateFin` ⇒ droits effectifs jusqu'à échéance incluse | API | CA-7 |
| Commande `securite:delegations:expirer` : délégation échue ⇒ `statut=expiree`, disparaît de `codesEffectifs()` | Unit + API | CA-8 |
| Révocation anticipée ⇒ `statut=revoquee` immédiat, droits retirés | API | CA-9 |
| Admin d'établissement sans permission X tente d'affecter/déléguer X ⇒ 403 | API | CA-10, RG-M8-09 |
| Dernier administrateur : suppression de sa dernière affectation admin / suspension ⇒ 422 ; verrou pessimiste sur suppression concurrente | API + Unit (concurrence) | CA-11, RG-M8-07 |
| Duplication de rôle : nouveau `Role` indépendant, mêmes permissions | API | CA-12 |
| Aperçu des droits d'un rôle : codes retournés, aucune écriture/aucun changement de contexte | API | CA-13 |
| Modification d'une entité surveillée ⇒ `EntreeAudit.valeurAvant`/`valeurApres` renseignés (hors champs sensibles) | Unit (`InstantaneEntiteBuilder`) + API | CA-14, RG-M8-05 |
| Filtres audit (auteur/action/cibleType/établissement/période) ; export produit un fichier exploitable respectant les mêmes filtres | API | CA-15 |
| `CalculateurDroits` : délégation active incluse dans l'union, aucune régression sur les tests socle existants (affectations seules) | Unit | §2.5, non-régression |
| MFA : codes de récupération épuisés + appareil perdu ⇒ connexion bloquée jusqu'à réinitialisation admin (tracée avant/après) | API | cas limite spec §7 |
| `setActif(bool)` reste équivalent à `statut` (compat fixtures socle/CRM) | Unit | §2.1 décision compat |

---

## 7. Tâches

Voir `specs/L7-backoffice/tasks-backoffice.md` (ordonnées, T1 → Tn, avec fichiers/dépendances/tests).

---

## 8. Dépendances nouvelles

- **`symfony/mailer`** (composer, absent aujourd'hui) : invitation + mot de passe oublié.
  Transport via `MAILER_DSN` (env, ex. `smtp://...` ou `null://null` en dev/test). Deux services
  légers `App\Securite\Notification\{InvitationMailer,ReinitialisationMailer}` (texte simple,
  lien front construit depuis `FRONT_BASE_URL`, env nouvelle).
- **`spomky-labs/otphp`** (composer) : génération/vérification TOTP (§2.3). Justification
  détaillée §2.3 (préféré à `scheb/2fa-*`, bundle trop englobant pour ce flux custom).
- **Aucune nouvelle dépendance pour l'ordonnancement** : commande console
  `securite:delegations:expirer` invoquée par un cron **externe au code applicatif** (cron du
  conteneur `php` ou du host, à documenter dans `docker-compose.yml`/déploiement — hors périmètre
  de ce dépôt de specs applicatif, cf. Risques). `symfony/scheduler` explicitement écarté pour ce
  lot (éviterait un nouveau processus worker permanent à opérer, cohérent avec l'arbitrage «
  solution simple » du sujet).
- **`ext-sodium`** : déjà inclus dans PHP core ≥ 7.2 (pas de nouvelle extension à installer côté
  image Docker `php`, à vérifier lors de l'implémentation — Risques).

---

## 9. Risques / à valider

1. **Exemption « administrateur groupe » du plafond RG-M8-09 non opérationnalisable dans le socle
   actuel** (§2.7) — le socle L0 n'a pas d'affectation portée au niveau Groupe/Région (même écart
   que RG-M8-02, déjà signalé par la spec). Ce plan applique le plafond **uniformément** ; CA-10
   reste couvert, mais l'exemption littérale du texte RG-M8-09 est un point ouvert à trancher avec
   une éventuelle évolution du modèle d'affectation hiérarchique.
2. **Héritage hiérarchique des rôles (RG-M8-02)** — rappel explicite de l'écart déjà signalé par la
   spec §2 : non traité dans ce lot, aucune US-L7 ne le demande formellement.
3. **Propagation d'une modification de rôle-modèle aux rôles dérivés** (§2.8, cahier M8-02) —
   volontairement **non implémentée** (comportement interactif non spécifié précisément par
   US-L7-04) ; seul le lien de traçabilité `roleModeleOrigine` est posé pour ne pas fermer la
   porte à une itération future.
4. **Délégation portant un `Role` entier, pas un sous-ensemble fin de permissions** (§1.4) — la
   spec évoque « un rôle (ou un sous-ensemble de permissions) » ; ce plan retient l'option la plus
   simple et testable (rôle entier). À confirmer si un besoin de délégation partielle apparaît.
5. **Rôles-modèles livrés vides** (§2.8, §5) — le cahier M8-02 nomme 4 rôles-modèles sans fixer
   leur composition exacte en permissions ; composer un jeu par défaut pertinent nécessiterait un
   arbitrage produit hors périmètre technique de ce plan.
6. **Export d'audit CSV synchrone, sans limite de volumétrie chiffrée** (§2.10) — reconduit de la
   spec ; à revoir si le volume de `EntreeAudit` devient significatif (pagination/traitement
   asynchrone).
7. **Ordonnancement de `securite:delegations:expirer` par cron externe** (§8) — nécessite une
   configuration d'infrastructure (cron conteneur/host) **hors du code applicatif Symfony** ; à
   documenter/valider avec l'équipe infra lors du déploiement (non couvert par les tests
   applicatifs de ce lot, seule la commande elle-même est testée).
8. **`ext-sodium` disponible dans l'image PHP du projet** — à vérifier concrètement dans
   `docker/php/Dockerfile` lors de l'implémentation (normalement inclus par défaut en PHP 8.4, mais
   certaines images minimalistes l'excluent).
9. **NF525/comptabilité publique** — aucun impact identifié de ce lot sur `App\Vente\Nf525`/
   `App\Compta` (aucune entité comptable touchée) ; à confirmer par un expert métier si un doute
   émergeait sur la valeur probante de l'audit avant/après pour d'autres domaines que la sécurité.
