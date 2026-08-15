# Tâches — Back-office & Droits : compléments M8 (`L7`)

- **Plan source :** specs/L7-backoffice/plan-backoffice.md

| # | Tâche | Fichiers | Dépend de | Test associé | État |
|---|---|---|---|---|---|
| T1 | Enums `StatutUtilisateur`, `StatutDelegation` (§1.7) | `app/src/Securite/Enum/StatutUtilisateur.php`, `StatutDelegation.php` | — | Unit (valeurs) | ⬜ |
| T2 | Extension `Utilisateur` (§1.1) : `statut`, `jetonInvitation`/`Expire`, `dernierAcces`, `mfaActif`, `mfaSecret`, `mfaCodesRecuperation`, `tokenVersion` ; `isActif()`/`setActif()` recâblés sur `statut` (compat fixtures) | `app/src/Securite/Entity/Utilisateur.php` | T1 | Unit — `setActif(true/false)` ⇔ `statut` | ⬜ |
| T3 | Extension `Role` (§1.2) : `estModele`, `roleModeleOrigine` (self-FK) | `app/src/Securite/Entity/Role.php` | — | Unit | ⬜ |
| T4 | Entité `DelegationDroit` (§1.4) : champs, contrainte applicative `dateFin > dateDebut` obligatoire | `app/src/Securite/Entity/DelegationDroit.php` | T1 | Unit — `dateFin` absente/invalide ⇒ erreur validation | ⬜ |
| T5 | Entité `JetonReinitialisation` (§1.5) — pas de `#[ApiResource]` | `app/src/Securite/Entity/JetonReinitialisation.php` | — | Unit | ⬜ |
| T6 | Extension `EntreeAudit` (§1.6) : `valeurAvant`, `valeurApres` | `app/src/Audit/Entity/EntreeAudit.php` | — | Unit (getters/setters) | ⬜ |
| T7 | `App\Securite\Crypto\ChiffreurSecret` (libsodium, `MFA_ENCRYPTION_KEY`) (§2.3) | `app/src/Securite/Crypto/ChiffreurSecret.php`, `app/.env` (nouvelle var) | — | Unit — chiffrer/déchiffrer round-trip | ⬜ |
| T8 | Ajout dépendances composer `symfony/mailer`, `spomky-labs/otphp` (§8) | `app/composer.json` | — | `composer install` sans erreur | ⬜ |
| T9 | `App\Securite\Service\GenerateurTotp` (wrapper `otphp` : génère secret, URI `otpauth://`, vérifie code) + `App\Securite\Service\GenerateurCodesRecuperation` (8 codes, hash sha256) (§2.3) | `app/src/Securite/Service/GenerateurTotp.php`, `GenerateurCodesRecuperation.php` | T7, T8 | Unit — code valide accepté, code expiré/invalide refusé | ⬜ |
| T10 | `App\Securite\Security\JwtClaimsAbonnee` (claim `tokenVersion` à l'émission) + `VerificateurJwtActifListener` (contrôle `tokenVersion`/`statut`/claim `mfaEnAttente` à chaque requête authentifiée) (§2.2) | `app/src/Securite/Security/JwtClaimsAbonnee.php`, `VerificateurJwtActifListener.php`, `app/config/services.yaml` | T2 | API — CA-3 (jeton pré-suspension refusé) | ⬜ |
| T11 | `App\Securite\Security\GestionnaireSuccesConnexionMfa` (décore le success handler lexik, émet jeton complet ou jeton pré-auth `mfaEnAttente`) + câblage `security.yaml` (`success_handler`) (§2.3) | `app/src/Securite/Security/GestionnaireSuccesConnexionMfa.php`, `app/config/packages/security.yaml`, `app/config/services.yaml` | T9, T10 | API — connexion sans MFA inchangée (non-régression CA-2 socle) | ⬜ |
| T12 | Contrôleur `POST /auth/mfa-verifier` (vérifie TOTP/code de récupération, émet le jeton complet, journalise, réutilise verrouillage `EcouteurConnexion`) (§2.3) | `app/src/Securite/Controller/VerificationMfaController.php` | T9, T10, T11 | API — CA-5 | ⬜ |
| T13 | Endpoints MFA self-service : `POST /utilisateurs/{id}/mfa/activer`, `/confirmer`, `/desactiver` (garde rôle à privilèges), `/reinitialiser` (admin) (§2.3, §2.1) | `app/src/Securite/State/MfaActivationProcessor.php`, `MfaConfirmationProcessor.php`, `MfaDesactivationProcessor.php`, `MfaReinitialisationProcessor.php`, attributs `#[ApiResource]` sur `Utilisateur.php` | T7, T9, T2 | API — CA-5, cas limite codes épuisés | ⬜ |
| T14 | `App\Securite\Service\RoleAPrivileges::estAPrivileges(Role): bool` (module `securite` présent) (§2.1) | `app/src/Securite/Service/RoleAPrivileges.php` | — | Unit | ⬜ |
| T15 | `App\Securite\Service\GardeDernierAdministrateur` (verrou pessimiste, §2.6) | `app/src/Securite/Service/GardeDernierAdministrateur.php` | — | Unit — dernier admin détecté correctement | ⬜ |
| T16 | `App\Securite\Service\VerificateurPlafondDroits` (§2.7) | `app/src/Securite/Service/VerificateurPlafondDroits.php` | — | Unit — sous-ensemble/hors périmètre ⇒ refus | ⬜ |
| T17 | `AffectationProcessor` (Post : plafond T16 + garde MFA T14 ; Delete : garde dernier admin T15) + `#[Post(processor:...)]`/`#[Delete(processor:...)]` sur `Affectation` | `app/src/Securite/State/AffectationProcessor.php`, `app/src/Securite/Entity/Affectation.php` | T14, T15, T16 | API — CA-4, CA-10, CA-11 | ⬜ |
| T18 | `RoleProcessor` (Patch/Delete : garde dernier admin T15) + câblage sur `Role` | `app/src/Securite/State/RoleProcessor.php`, `app/src/Securite/Entity/Role.php` | T15 | API — CA-11 (variante rôle) | ⬜ |
| T19 | `SuspensionUtilisateurProcessor` (garde dernier admin T15, `tokenVersion++`) + endpoints `POST /utilisateurs/{id}/suspendre`/`reactiver`/`reinviter` | `app/src/Securite/State/SuspensionUtilisateurProcessor.php`, `ReinvitationProcessor.php`, `app/src/Securite/Entity/Utilisateur.php` | T15, T10 | API — CA-3, CA-11 | ⬜ |
| T20 | `UtilisateurProcessor` étendu : invitation auto à la création (jeton, `statut=invite`, envoi mail) (§2.1) | `app/src/Securite/State/UtilisateurProcessor.php` | T2, T22 (mailer) | API — CA-1 | ⬜ |
| T21 | Contrôleur public `POST /utilisateurs/activation` (§2.1) | `app/src/Securite/Controller/ActivationController.php` | T20 | API — CA-2 | ⬜ |
| T22 | `App\Securite\Notification\{InvitationMailer,ReinitialisationMailer}` + config `MAILER_DSN`/`FRONT_BASE_URL` (§8) | `app/src/Securite/Notification/InvitationMailer.php`, `ReinitialisationMailer.php`, `app/.env` | T8 | Unit — mail généré (transport `null://null` en test) | ⬜ |
| T23 | Contrôleurs publics `POST /mot-de-passe/oublie` et `POST /mot-de-passe/reinitialiser` (§2.4) | `app/src/Securite/Controller/DemandeReinitialisationController.php`, `ReinitialisationMotDePasseController.php` | T5, T22, T10 | API — CA-6 | ⬜ |
| T24 | Extension `CalculateurDroits::codesEffectifs()` — union des `DelegationDroit` actives non expirées (§2.5) | `app/src/Securite/Service/CalculateurDroits.php` | T4 | Unit — non-régression + délégation incluse | ⬜ |
| T25 | `DelegationDroitProcessor` (Post : `dateFin` obligatoire, plafond T16) + ressource `#[ApiResource]` `DelegationDroit` (Get/GetCollection/Post) + `PerimetreDelegationExtension` | `app/src/Securite/Entity/DelegationDroit.php`, `app/src/Securite/State/DelegationDroitProcessor.php`, `app/src/Securite/Doctrine/PerimetreDelegationExtension.php` | T4, T16, T24 | API — CA-7 | ⬜ |
| T26 | Endpoint `POST /delegations/{id}/revoquer` (§2.5) | `app/src/Securite/State/RevocationDelegationProcessor.php` | T25 | API — CA-9 | ⬜ |
| T27 | Commande `securite:delegations:expirer` (§2.5) | `app/src/Securite/Command/ExpirerDelegationsCommand.php` | T4 | Unit + API — CA-8 | ⬜ |
| T28 | `DuplicationRoleProcessor` (`POST /roles/{id}/dupliquer`) (§2.8) | `app/src/Securite/State/DuplicationRoleProcessor.php`, `app/src/Securite/Entity/Role.php` | T3 | API — CA-12 | ⬜ |
| T29 | `ApercuDroitsRoleProvider` (`GET /roles/{id}/apercu-droits`) (§2.9) | `app/src/Securite/State/ApercuDroitsRoleProvider.php`, `app/src/Securite/Entity/Role.php` | T3 | API — CA-13 | ⬜ |
| T30 | `App\Audit\Service\InstantaneEntiteBuilder` (snapshot scalaire, exclusion champs sensibles) + intégration `AuditWriteSubscriber` (avant/après création/modification/suppression) (§2.10) | `app/src/Audit/Service/InstantaneEntiteBuilder.php`, `app/src/Audit/Doctrine/AuditWriteSubscriber.php` | T6 | Unit + API — CA-14 | ⬜ |
| T31 | Filtres `EntreeAudit` (`SearchFilter` auteur/action/cibleType/etablissement, `DateFilter` dateHeure) + permissions `securite.lire`/`securite.exporter` sur `security:` | `app/src/Audit/Entity/EntreeAudit.php` | T6 | API — CA-15 (filtres) | ⬜ |
| T32 | Contrôleur `GET /audit/export` (CSV, mêmes filtres) (§2.10) | `app/src/Securite/Controller/ExportAuditController.php` | T31 | API — CA-15 (export) | ⬜ |
| T33 | Migration structurelle (schéma + backfill `statut`/`DROP actif`, §5.1) | `app/migrations/VersionYYYYMMDDHHMMSS_l7_schema.php` | T2, T3, T4, T5, T6 | Migration rejouable (up/down) | ⬜ |
| T34 | Migration de données — permissions `securite.lire`/`securite.exporter` (§5.2) | `app/migrations/VersionYYYYMMDDHHMMSS_l7_permissions.php` | T33 | API — 403 sans permission | ⬜ |
| T35 | Migration de données — rôles-modèles vides (Caissier, Responsable de site, Contrôleur, Comptable) (§5.3) | `app/migrations/VersionYYYYMMDDHHMMSS_l7_roles_modeles.php` | T33 | Unit — 4 rôles `estModele=true` créés | ⬜ |
| T36 | Fixtures L7 (utilisateur invité, utilisateur MFA actif, délégation active/expirée, rôle à privilèges) pour les tests | `app/src/Securite/DataFixtures/L7Fixtures.php` | T33–T35 | — | ⬜ |
| T37 | Base de test `SecuriteApiTestCase` (patron `CrmApiTestCase`) | `app/tests/Securite/SecuriteApiTestCase.php` | T36 | — | ⬜ |
| T38 | Suite de tests fonctionnels complète (§6 du plan, CA-1 à CA-15 + non-régression + cas limites) | `app/tests/Securite/**` | T1–T37 | voir §6 plan-backoffice.md | ⬜ |
| T39 | Non-régression socle : suite `SocleTest`/`CrmApiTestCase` existante rejouée après T2 (retrait colonne `actif`) et T10/T11 (nouveau success handler) | `app/tests/SocleTest.php`, `app/tests/Crm/**` (existants) | T2, T10, T11 | 0 régression sur suites existantes | ⬜ |

État : ⬜ à faire · 🟦 en cours · ✅ fait · ⛔ bloqué
