# Rapports de `claude-E`

> **Écrit par `claude-E` seul.** claude-A le lit, ne l'écrit jamais.
> Une ligne par battement, la plus récente **en bas**.

| Heure | Fait | En cours | Bloqué par |
|---|---|---|---|
| 12:20 | Prise de poste claude-E, aligné sur `vps/main` (`30e600c`). Lu FLOTTE, D22, D24, D27, TASKS. `RevenueRecovery`/`SmartFlow` absents ; `Recouvrement` mature. | Je prends **RR-0** et **SF-0**. | — |
| 12:30 | Specs SDD **SF-0** (Smart Flow) et **RR-0** (Revenue Recovery) lancées en parallèle → `specs/smart-flow/` et `specs/revenue-recovery/`. Push OK sur `claude-E-desktop`, **7 garde-fous verts**. | Rédaction des deux specs (contrat d'abord, D2). Ensuite : plans techniques (sdd-architecte). | Émission des déclencheurs RR-1/SF-1 (hors périmètre) — voir ci-dessous. |

## ⚠ Point n°1 pour claude-A — d'où intégrer mon travail (branche)

Je tourne depuis une **session desktop**, pas depuis le worktree VPS `/home/debian/wt/claude-E`. La branche
canonique `claude-E` étant **déjà extraite** dans ce worktree, le dépôt refuse que j'y pousse
(`refusing to update checked out branch`). Sur décision de Maxime, je pousse donc sur **`claude-E-desktop`**
(garde-fous verts). **Intègre mon travail depuis `claude-E-desktop`, pas depuis `claude-E`.** Le worktree
VPS `claude-E` paraît dormant (aucune session live n'y pousse) ; si tu veux que je repasse sur la branche
canonique, libère-la côté VPS et dis-le moi ici.

## Point n°2 — dépendances préalables (RR-1 / SF-1), hors de mon périmètre

- **RR-1** (émettre `cart.abandoned`, `invoice.overdue`, `quote.expired`, `customer.inactive`) touche
  `Boutique/Facturation/Crm/Devis`. **SF-1** (émettre `booking.cancelled`, `booking.no_show`,
  `access.recorded`) touche `Reservation/Acces`. Ce sont des **préalables** (D22), portés par
  l'intégrateur / les propriétaires. **RR-2 et SF-2 en dépendent** : je peux spécifier et écrire les
  moteurs, mais ils resteront des coquilles inertes tant que ces événements ne sont pas émis (précédent
  `ProjectionAccesReservation`). Mes specs documentent, pour chaque événement attendu, le nom de contrat,
  le tenant (D6) et le payload, pour cadrer l'émission côté modules sources.
- **D27 / no-show :** `booking.reschedule_requested` (CQ-5) a **zéro consommateur** aujourd'hui — c'est
  exactement le trou que SF-2 doit combler pour que le report par défaut ne soit pas une promesse vide.

## Point n°3 — questions à trancher (détaillées dans les specs à venir)

- **RR-0 :** étendre `Recouvrement` ou créer `RevenueRecovery` neuf ? Recommandation argumentée dans la
  spec ; si le choix engage l'architecture, je te la pose avant d'implémenter.
- **Périmètre `specs/` :** ma ligne FLOTTE ne liste que `app/src/{RevenueRecovery,SmartFlow}/**`. Je
  considère `specs/revenue-recovery/**` et `specs/smart-flow/**` comme miens (les specs de mes modules).
  Corrige-moi si tu vois autrement.
- **SF-0** est marquée `claude-B` dans `TASKS.md` (assignation antérieure au découpage flotte) : c'est le
  périmètre claude-E. Je la reprends — merci de reporter au carnet (règle 6, je ne touche pas `TASKS.md`).
