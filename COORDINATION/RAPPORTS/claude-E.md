# Rapports de `claude-E`

> **Écrit par `claude-E` seul.** claude-A le lit, ne l'écrit jamais.
> Une ligne par battement, la plus récente **en bas**.

| Heure | Fait | En cours | Bloqué par |
|---|---|---|---|
| 12:20 | Prise de poste claude-E, aligné sur `vps/main` (`30e600c`). Lu FLOTTE, D22, D24, D27, TASKS. `RevenueRecovery`/`SmartFlow` absents ; `Recouvrement` mature. | **Je prends RR-0 et SF-0.** Démarre la spec SDD **SF-0** (chemin critique D27 : le no-show « restitué avec report » émet un événement que Smart Flow doit consommer). | — |

## Points à l'intégrateur (claude-A)

- **SF-0 est marquée `claude-B` dans `TASKS.md`** (assignation antérieure au découpage flotte) : SmartFlow est le périmètre claude-E d'après FLOTTE. Je la reprends — merci de reporter au carnet.
- **RR-1 et SF-1 (émission des déclencheurs) sont hors de mon périmètre** : RR-1 touche `Boutique/Facturation/Crm`, SF-1 touche `Reservation/Acces`. Ce sont des **préalables** (D22) portés par l'intégrateur / les propriétaires de ces modules. **RR-2 et SF-2 en dépendent** — je peux spécifier et écrire les moteurs, mais ils resteront des coquilles inertes tant que les événements ne sont pas émis (précédent `ProjectionAccesReservation`, D22). Je spécifie les événements attendus (nom de contrat, tenant, payload) dans mes specs pour que l'émission côté modules sources soit cadrée.
- **Périmètre `specs/` :** ma ligne FLOTTE ne liste que `app/src/{RevenueRecovery,SmartFlow}/**`, mais RR-0/SF-0 écrivent dans `specs/revenue-recovery/**` et `specs/smart-flow/**`. Je considère les specs de mes deux modules comme miennes (comme claude-D/I ont `specs/…` dans leur périmètre). Dis-moi si tu vois les choses autrement.
