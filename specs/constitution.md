# Constitution du projet — Logiciel de billetterie & contrôle d'accès

> Document de référence, court et stable. **Tout agent SDD lit cette constitution avant d'agir.**
> Les specs, plans et tâches en découlent ; en cas de conflit, la constitution prime.

## 1. Mission
Éditer un logiciel de **billetterie + contrôle d'accès** pour équipements de loisirs et culture
(piscines, patinoires, salles de sport, centres de padel, musées), destiné aux **collectivités
en régie directe**, aux **délégataires (DSP)** et aux **groupes privés**, hébergé **en France**.

## 2. Règle d'or (principe directeur non négociable)
**L'expérience utilisateur doit être la plus simple possible pour tout le monde.**
Le moteur métier est complexe (tarification, PCA, régie, contrôle d'accès, multi-entités) mais il
doit rester **invisible** pour l'utilisateur. Toute complexité exposée à l'écran est un défaut.

## 3. Socle technique (imposé)
- **PHP 8.4** · `declare(strict_types=1)` partout.
- **Symfony 7** + **API Platform** (API-first : toute capacité passe par l'API, l'UI la consomme).
- **Doctrine ORM** sur **MariaDB 11.4**.
- **Docker Compose** pour le dev (services `php`, `web` nginx, `db`).
- Identifiants d'entités = **UUID** (`symfony/uid`), jamais d'auto-incrément exposé.
- Tests : **PHPUnit** (+ fixtures). Aucune story « done » sans test.

## 4. Principes d'architecture
1. **API-first** — chaque fonctionnalité est une ressource/opération API documentée (OpenAPI générée).
2. **Multi-entités natif** — tout objet métier est rattaché à la hiérarchie
   **Groupe › Région › Établissement › Espace** ; cloisonnement des données par entité.
   **Multi-tenant mutualisé** (décision 15/08) : **une base partagée**, isolation **logique** par la
   hiérarchie (filtres de requête + droits), testée. L'option **base/instance dédiée par client**
   (isolation physique) reste possible **sans réécriture** — même code, `DATABASE_URL`/instance
   différente + outillage de provisioning — pour les clients qui l'exigent (souveraineté, gros comptes, DSP).
3. **Droits fins** — permission = couple `module × action` ; l'UI **masque** ce qui n'est pas autorisé
   (pas seulement désactivé). Un utilisateur peut avoir des rôles différents par établissement.
4. **Aucune logique pays/métier codée en dur** — couche de configuration ; les spécificités
   (verticale, pays fiscal, régime comptable) sont des **modules/paramètres**, pas des `if` éparpillés.
5. **Conformité dès la conception** — **NF525** (inaltérabilité/chaînage), **RGPD** (données en France,
   consentement, effacement), accessibilité **RGAA/WCAG 2.2 AA** pour les surfaces publiques.
6. **Mode dégradé** — le contrôle d'accès et la caisse fonctionnent **hors-ligne** puis resynchronisent.

## 5. Cartographie des modules
Socle : **M1** Offre & Tarification · **M2** Vente & Caisse · **M3** Boutique & App client ·
**M4** CRM · **M5** Planning & Réservation · **M6** Compta & Régie (bi-régime) ·
**M7** Reporting multi-niveaux · **M8** Admin & Droits.
Vertical : **Contrôle d'accès**, puis verticales **Piscine · Patinoire · Sport · Padel · Musée**.

Ordre de construction (lots MVP) : **L0** socle technique → **L1** M1 → **L2** M2 → **L3** accès →
**L4** M6 → **L5** M4 → **L6** Piscine → **L7** back-office & droits.

## 6. Traçabilité (obligatoire)
- Règles de gestion : `RG-<module>-<n>` (ex. `RG-M1-02`). Sources : `cahier-detaille.html`.
- User stories : `US-<lot>-<n>` (ex. `US-L0-04`). Source : `backlog.html`.
- Décisions actées : 56 décisions (onglet ★ Décisions du cahier détaillé) — **font foi**, ne pas re-trancher.
- Tout commit, spec, test référence les `RG`/`US` concernés.

## 7. Conventions de code
- Namespaces par domaine : `App\<Module>\...` (ex. `App\Offre\Entity\Produit`).
- Entités Doctrine = attributs PHP ; noms **métier en français** (Produit, Tarif, Etablissement…).
- Contrôleurs fins ; logique métier dans des services/handlers testables.
- Migrations Doctrine versionnées ; **jamais** de `schema:update --force` hors dev jetable.
- Messages de commit en français, à l'impératif, référençant `US`/`RG`.

## 8. Definition of Done (une story)
1. Code conforme à la spec + critères d'acceptation de la story.
2. Migration(s) fournie(s) et rejouables.
3. Test(s) automatisé(s) couvrant les critères d'acceptation.
4. `GET /health` reste vert ; l'API démarre sans erreur.
5. Spec/plan mis à jour si l'implémentation a divergé.
6. Aucune régression des conventions (strict_types, droits, multi-entités).
