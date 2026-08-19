# Noyau commun (Platform Core) — v0

Services que **tout module peut supposer présents**. Un module ne réimplémente jamais ces briques ;
il les consomme. La colonne « Chez nous » pointe vers l'existant billetterie à réutiliser/généraliser.

| Brique | Rôle | Chez nous (billetterie) | Équivalents observés |
|---|---|---|---|
| **Identité** | Utilisateurs, auth (session/JWT), 2FA, révocation globale | `App\Securite` (Utilisateur, login) | Vespera `sessionVersion` ; OFS `AuthContext` |
| **Organisations / Tenant** | Arbre de tenants typés (groupe→région→établissement→espace) | `App\Etablissement` + Contexte | Vespera Org→Creator ; OFS club |
| **Cloisonnement** | Périmètre **dérivé serveur**, jamais d'un id client ; échec fermé | `ContexteEtablissement` + extensions Doctrine `Perimetre*` | Vespera `getScope()` ; OFS `WHERE club_id` + garde-fou CI |
| **Permissions** | RBAC **module × action** (`is_granted('PERM','module.action')`) | `PermissionVoter`, `CalculateurDroits` | OFS 11 rôles × 71 perms |
| **Audit** | Journal append-only inaltérable | subscribers d'audit | Vespera `/activite` ; OFS journal |
| **Billing & Features** | Capacités activables par tenant **+ features activables dans un module** | Profil de capacités par établissement | SmartFlow `hasModule`+`hasFeature` |
| **Communication** | Email / SMS / WhatsApp / push + templates | `symfony/mailer` (à étendre) | SmartFlow « Communication Core » |
| **i18n / Traduction** | Clés de traduction + catalogues par langue (FR défaut) ; **agent de traduction** (IA) remplit les catalogues | tout libellé visible | OFS `ui_string_translations.json` ; Vespera FR/ES |
| **Automation / Scheduler** | Déclencheurs, tâches différées, CRON | à créer (VPS permet le CRON) | SmartFlow « Automation » ; OFS sweeps CRON |
| **Bus d'événements** | Publier / distribuer les événements métier | **à créer** (`App\Platform\Event`) | SmartFlow « Event Engine » |
| **Registre de modules** | Découverte + activation des modules via manifeste | **à créer** (`App\Platform\Module`) | SmartFlow « Module Registry » |

## Les invariants non négociables (tenus par tout module)
1. **Périmètre serveur** — jamais fabriqué depuis un id client (voir DECISIONS D3).
2. **Échec fermé** — 403/404 plutôt qu'un filtre silencieux ; aucun repli « premier trouvé ».
3. **Écriture bornée** — les updates/deletes portent le périmètre **dans la requête** (pas de TOCTOU).
4. **Chiffrement au repos** des secrets (IBAN, jetons OAuth) — clé dédiée par usage.
5. **Dégradation propre** — une intégration externe non configurée = no-op explicite, jamais de crash.
6. **Notifications best-effort** — un échec e-mail/push ne casse jamais l'action métier.
7. **Nommage anglais** (D5) — tout identifiant technique (entité, table, colonne, enum, champ API,
   permission, événement) est en **anglais** ; les libellés utilisateur passent par des clés i18n
   (jamais de chaîne en dur). Garde-fou CI à venir.

## Modèle Tenant (à généraliser — ⚠️ à trancher)
Aujourd'hui : `Groupe → Région → Établissement → Espace`. Pour accueillir Vespera (Org→Creator) et
OFS (club), on garde cet arbre et on **type les nœuds** ; un module se rattache au niveau qui a du
sens. À valider quand on re-loge le premier domaine externe.
