# DECISIONS — journal append-only des décisions transverses

N'édite jamais une entrée passée ; ajoute une nouvelle entrée en bas. Format : date · décision · raison.

---

### 2026-08-19 · D1 — Convergence en socle unique
Le core **Symfony 7 / API Platform / Doctrine / MariaDB** de la billetterie devient **LA coquille**
de la plateforme. Finance, Smart Flow, Revenue Recovery et, progressivement, les domaines OFS et
Vespera deviennent des **modules activables**.
**Raison :** c'est le projet le plus « plateforme » (capacités activables + RBAC module×action +
API-first déjà en place). Retrofit **incrémental**, jamais big-bang.

### 2026-08-19 · D2 — Contract-first
Aucun module ne se construit avant que son **manifeste** et ses **événements** soient posés dans
`CONTRACT/`. Les modules communiquent **par événements**, jamais par appel direct module→module.
**Raison :** c'est ce qui rend le travail parallèle (plusieurs Claude) sûr et le cœur agnostique.

### 2026-08-19 · D3 — Invariant n°1 : cloisonnement à périmètre serveur
Le périmètre (tenant/établissement) est **toujours dérivé de la session serveur**, jamais d'un id
fourni par le client. Toute résolution d'entité par id client doit revérifier l'appartenance au
périmètre. Échec **fermé** (403/404), jamais de repli silencieux.
**Raison :** les 3 projets l'ont adopté indépendamment ; 5 violations réelles (IDOR cross-tenant)
viennent d'être trouvées et corrigées en revue de cohérence (Personnel, Stock, Terminal).

### 2026-08-19 · D4 — Suite Finance sur le core billetterie
Factures (client + fournisseur), Comptabilité (plan comptable, FEC), Trésorerie, Notes de frais
se construisent sur le core billetterie (là où vivent déjà `Facturation` + Compta/Régie + `SEPA`).
**OCR** est un **service transverse partagé**, pas un module métier (alimente Factures fourn. + Notes de frais).

### 2026-08-19 · D5 — Anglais pour tout le technique, i18n pour l'affichage
**Tous les identifiants techniques sont en anglais** (standard international) : entités, tables,
colonnes, propriétés, valeurs d'enum, champs d'API/DTO, **codes de permission** (`finance.read`),
**noms d'événements** (`payment.failed`). Les libellés visibles par l'utilisateur ne sont **jamais**
en dur : ce sont des **clés de traduction** résolues par la couche i18n (français par défaut, langues
extensibles). Un **agent de traduction** (IA) auto-remplit les catalogues de langues.
**Raison :** standard de l'industrie + interop propre entre les 3 projets ; sépare le technique
(anglais, stable) de la présentation (traduite).
**Portée / retrofit :** tout nouveau code est en anglais dès maintenant. L'existant billetterie
(français : `Etablissement`, `Facturation`…) sera migré **incrémentalement** vers l'anglais dans le
cadre du retrofit total (renommages + migrations, avec la couche i18n qui garantit que l'UI reste
française pendant toute la transition). Un garde-fou CI vérifiera l'absence d'identifiant non-anglais
dans les nouvelles migrations/entités.
