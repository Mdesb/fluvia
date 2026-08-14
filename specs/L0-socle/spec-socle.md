# Spec — Socle : multi-entités, identité, droits, audit (`US-L0-02/04/05/06/08`)

- **Lot / module :** L0 · Socle technique
- **Stories couvertes :** US-L0-02 (connexion), US-L0-04 (multi-entités), US-L0-05 (établissement actif), US-L0-06 (rôles & permissions), US-L0-08 (audit)
- **Règles de gestion :** RG-M8-* (droits, UI adaptative), exigences non-fonctionnelles (RGPD/France)
- **Statut :** validée

## 1. Objectif
Poser le noyau organisationnel et de sécurité : une hiérarchie d'entités à laquelle tout se rattache,
des utilisateurs authentifiés, des droits fins par établissement, et une piste d'audit — fondations
de tous les modules métier.

## 2. Périmètre
- **Inclus :** modèle Groupe/Région/Établissement/Espace ; Utilisateur + connexion par jeton ;
  Rôle + Permission (module × action) ; Affectation (utilisateur↔rôle↔établissement) ;
  contexte d'établissement actif ; journal d'audit des actions sensibles.
- **Exclu (pour l'instant) :** MFA et mot de passe oublié (US-L0-03, itération suivante) ;
  UI (front) ; SSO/FranceConnect (V1).

## 3. Acteurs & droits
| Acteur | Peut | Permission |
|---|---|---|
| Administrateur groupe | tout gérer sur son périmètre | `securite × gerer`, `organisation × gerer` |
| Administrateur établissement | gérer utilisateurs/rôles de son établissement | idem, restreint à l'établissement |
| Utilisateur (agent) | accéder aux modules selon ses rôles | selon permissions affectées |
| Lecture seule | consulter | `* × lire` |

## 4. Comportements & règles
- **RG-SOCLE-01** — Toute entité métier est rattachée à un **Établissement** (et éventuellement un Espace).
  La hiérarchie Groupe→Région→Établissement→Espace a une intégrité référentielle stricte.
- **RG-SOCLE-02** — Une **Permission** est le couple `module × action` (ex. `offre × creer`).
- **RG-SOCLE-03** — Un **Rôle** agrège des permissions. Une **Affectation** lie un Utilisateur à un
  Rôle **pour un Établissement donné** ; un utilisateur peut donc avoir des rôles différents par établissement.
- **RG-SOCLE-04** — Les droits effectifs d'un utilisateur = union des permissions de ses affectations
  sur l'établissement actif. En cas de conflit, la règle **la plus restrictive** l'emporte (décision actée).
- **RG-SOCLE-05** — L'**établissement actif** cadre toutes les lectures/écritures : un utilisateur ne
  voit et ne modifie que les données des établissements où il a une affectation.
- **RG-SOCLE-06** — Le mot de passe est **haché** (algorithme fort, jamais en clair). Compte
  **verrouillé** temporairement après N échecs (défaut 5).
- **RG-SOCLE-07** — Le **journal d'audit** enregistre les actions sensibles (création/modif/suppression,
  connexion, changement de droits) : auteur, horodatage, action, entité, établissement. **Non modifiable**
  via l'API (append-only).

## 5. Objets de données
| Objet | Champ | Type | Contraintes |
|---|---|---|---|
| Groupe | id, nom | uuid, string | nom requis |
| Region | id, nom, groupe | uuid, string, FK | rattachée à un Groupe |
| Etablissement | id, nom, region, actif | uuid, string, FK, bool | rattaché à une Région |
| Espace | id, nom, etablissement, type | uuid, string, FK, string | rattaché à un Établissement |
| Utilisateur | id, email, motDePasse(hash), nom, actif, roles[] | uuid, string, string, string, bool | email unique |
| Role | id, nom, permissions[] | uuid, string, set | nom unique par groupe |
| Permission | id, module, action | uuid, string, string | (module,action) unique |
| Affectation | id, utilisateur, role, etablissement | uuid, FK×3 | unique (util,role,étab) |
| EntreeAudit | id, auteur, dateHeure, action, cibleType, cibleId, etablissement | uuid, … | append-only |

## 6. Critères d'acceptation
- **CA-1** — *Étant donné* la hiérarchie, *quand* je crée une Région sans Groupe *alors* l'API refuse (422).
- **CA-2** — *Quand* je me connecte avec de bons identifiants *alors* je reçois un jeton ; mauvais → 401.
- **CA-3** — *Après* 5 échecs *alors* le compte est verrouillé temporairement.
- **CA-4** — *Étant donné* un utilisateur affecté à l'Établissement A seulement, *quand* il liste les
  établissements *alors* B n'apparaît pas.
- **CA-5** — *Quand* un utilisateur sans permission `organisation × gerer` tente de créer un
  Établissement *alors* 403.
- **CA-6** — *Quand* une action sensible a lieu *alors* une EntreeAudit est créée ; *quand* on tente de
  la modifier via l'API *alors* l'opération n'existe pas (405/404).

## 7. Cas limites
- Utilisateur sans aucune affectation → aucune donnée métier visible, mais peut se connecter.
- Suppression d'un Établissement rattaché à des données → refusée (intégrité).
- Deux rôles avec permissions contradictoires sur le même établissement → plus restrictive gagne.

## 8. Dépendances
- Socle technique (Docker, Symfony, API Platform, MariaDB) — fait.
- Dépend de : `symfony/security-bundle`, JWT (`lexik/jwt-authentication-bundle`).
