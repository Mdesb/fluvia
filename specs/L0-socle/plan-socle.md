# Plan technique — Socle noyau (`US-L0-02/04/05/06/08`)

- **Spec source :** specs/L0-socle/spec-socle.md
- **Stack :** Symfony 7 · API Platform · Doctrine/MariaDB · lexik/jwt-authentication-bundle

## 1. Entités & schéma (UUID en id partout)
- `App\Organisation\Entity\{Groupe, Region, Etablissement, Espace}` — hiérarchie 1-N descendante,
  `Region.groupe` / `Etablissement.region` / `Espace.etablissement` en `nullable:false` (RG-SOCLE-01).
- `App\Securite\Entity\{Utilisateur, Role, Permission, Affectation}` :
  - `Utilisateur implements UserInterface, PasswordAuthenticatedUserInterface` — email unique,
    `motDePasse` hashé, `actif`, `tentativesEchouees`, `verrouilleJusqua`.
  - `Permission(module, action)` unique ; `Role` ManyToMany `Permission`.
  - `Affectation(utilisateur, role, etablissement)` unique — porte la dimension multi-entités (RG-SOCLE-03).
- `App\Audit\Entity\EntreeAudit` — append-only (RG-SOCLE-07).

Mapping Doctrine : scanner tout `src/` (attributs) — cf. edit `config/packages/doctrine.yaml`.

## 2. API (API Platform)
| Ressource | Opérations | security: |
|---|---|---|
| Groupe/Region/Etablissement/Espace | GET coll/item, POST, PATCH, DELETE | `is_granted('PERM','organisation.gerer')` (écriture) ; lecture filtrée par établissement actif |
| Utilisateur | GET, POST, PATCH | `securite.gerer` ; `/me` accessible à soi |
| Role/Permission/Affectation | CRUD | `securite.gerer` |
| EntreeAudit | GET seulement (coll/item) | `securite.gerer` — **pas** de POST/PATCH/DELETE |

- Auth : `POST /auth` (login_check JWT) → jeton ; `GET /me` (profil + droits effectifs).
- Groupes de sérialisation `:read` / `:write` ; UUID exposé, jamais le hash.

## 3. Sécurité & droits
- `security.yaml` : provider Doctrine sur `Utilisateur` (email), hasher auto, firewall `api` en JWT,
  firewall `auth` (login_check). `access_control` de base.
- **Voter** `PermissionVoter` : attribut `PERM`, sujet = `"module.action"` ; vérifie l'union des
  permissions des affectations de l'utilisateur sur l'établissement actif (RG-SOCLE-04/05).
- Contexte établissement actif : en-tête `X-Etablissement` ou champ jeton → service `ContexteEtablissement`.

## 4. Migrations
- Une migration initiale créant toutes les tables + index d'unicité (email, (module,action), affectation).

## 5. Tests (PHPUnit + ApiTestCase)
| Test | Type | Couvre |
|---|---|---|
| RegionSansGroupe → 422 | API | CA-1 |
| Login ok/ko + verrouillage | API | CA-2, CA-3 |
| Cloisonnement établissement | API | CA-4 |
| 403 sans permission | API | CA-5 |
| Audit non modifiable | API | CA-6 |

## 6. Tâches
Voir tasks-socle.md.

## 7. Risques / à valider
- Stratégie établissement actif (en-tête vs multi-tenant DB) → en-tête pour le MVP, réévaluer en charge.
- JWT vs sessions : JWT retenu (API-first, apps mobiles/bornes à venir).
