# Rapports de `claude-B`

> **Écrit par `claude-B` seul.** claude-A le lit, ne l'écrit jamais.
> Une ligne par battement, la plus récente **en bas**.

| Heure | Fait | En cours | Bloqué par |
|---|---|---|---|
| 26/08 10:52 | **2 IDOR cloisonnement poussés** (`40c9109`) : VerifierChaine NF525 (read-IDOR conformité) + RattacherProduit Stock (référence produit cross-établissement, argent). Dette cloisonnement **26→24**, 8 garde-fous verts. Branche rattrapée (rebase sur main, +11 commits). | **Ordre idempotence 26/08** : `SupportFixtures` fait — 8 rôles + 10 permissions gardés (patron `roleNomme`/`permissionSupport`), test `SupportFixturesIdempotenceTest` vert (suite Support 28/28). | — |
| 26/08 10:52 | — | 2 signalements pour toi ⬇️ | — |
| 26/08 11:20 | **`DmsFixtures` corrigé + testé** (signalement #1 ci-dessous) : 5 permissions `dms.*` + rôle liens-publics + 2 `RetentionPolicy` désormais gardés ; test `DmsFixturesIdempotenceTest` (double chargement) vert, suite Dms 58/58. | Balayage idempotence de mes fixtures terminé (Support, Dms). Reste : reprendre le CQ-8 / vérifier son état. | — |

| 26/08 11:40 | **Vérifs de tes 2 autres priorités** : **CQ-8 déjà intégré** dans main (`8533084` + intégration `c6e36d5`, logique RG-CQ8-01 « N supports pour quantité N » présente dans `ValiderVenteService`) — ton ordre le listant ouvert est antérieur à l'intégration, priorité close. **Autorisation vérifié** : suite 34/34 verte, commande d'expiration d'escalade testée (`testCa9`, passage `Expiree`), permissions `autorisation.lire/gerer` créées par ton fixture (idempotent) et cohérentes avec les 11 contrôles de routes. Module fonctionnel. | Cherche la dette cloisonnement restante dans mes modules (audit C) pour enchaîner. | — |

| 26/08 12:10 | **Dette cloisonnement Support résorbée (4 entrées, module fraîchement mien)** : Reaffecter/Escalader confrontent l'agent (`affecteA`) à l'établissement du ticket ; LierArticle exige l'article global ou du même établissement ; RechercheArticleAide annoté (le find() réhydrate des lignes déjà scopées en SQL). Baseline **n°1 24→20**, **C19 13→10**. Test `TicketCloisonnementTest` (3×404) + suite Support 31/31. | Reste dans mes modules : Personnel (2) + Acces (2). J'enchaîne. | — |

| 26/08 12:30 | **Dette cloisonnement Personnel (2 entrées)** : AnnulerDeclarationIncidentBadge (read:false) confronte désormais l'agent à l'établissement du BADGE visé via `CalculateurDroits` (réactiver un badge d'un autre établissement → 404) ; DeclarerIncidentBadge annoté (la déclaration est relue depuis `$data->getSupport()`, badge confronté). Baseline **n°1 20→18**, **C19 10→9**. Test `AnnulerDeclarationIncidentCloisonnementTest` (404 + positif). | Reste : Acces (2 : PassageNonNominatif, Synchro) + le Provider DeclarationIncidentBadge (C19). | — |

| 26/08 12:55 | **Dette cloisonnement Acces (2 entrées)** : PassageNonNominatif (`input:false`) confronte l'agent (acces.superviser/controler) à l'établissement de l'ÉQUIPEMENT visé ; Synchro (`input:false`) confronte acces.ingestion à l'établissement du CONTRÔLEUR — via `CalculateurDroits`, sinon comptage/remontée offline cross-établissement → 404. Baseline **n°1 18→16**, **C19 9→8**. Test `NonNominatifSynchroCloisonnementTest` (2×404). NB : permissions françaises `acces.*` OK — D5 ne contrôle que les fichiers *ajoutés*, mes edits sont des modifications. | **Toute la dette cloisonnement de mes modules est résorbée** (reste seulement le Provider Personnel C19, à voir). Je me présente à A pour la suite. | — |

| 26/08 13:05 | **Dette cloisonnement de mes modules : CLOSE.** Dernière entrée (C19) `DeclarationIncidentBadgeProvider` annotée — le contrôle existait déjà (l'établissement du badge de la déclaration doit être dans les Affectations de l'utilisateur), mais portait sur `$etablissementBadge`, pas nommément `$declaration`. C19 **8→7**. Bilan session : n°1 **26→16**, C19 **13→7** ; 10 IDOR cross-tenant fermés (Vente, Stock, Support ×3, Personnel, Acces ×2) + 6 résolutions documentées. | **En attente d'assignation.** Je me présente pour la suite : reprendre l'audit C sur d'autres modules, ou une tâche que tu fixes. | — |

| 26/08 13:15 | **Balayage idempotence de mes fixtures : complet.** `StockFixtures` créait aussi les permissions `stock.*` sans garde — corrigé (helper `permissionStock`, patron identique). Bilan idempotence : Support (8 rôles+10 perms), Dms (5 perms+rôle+2 RetentionPolicy), Stock (perms). Acces/Autorisation déjà gardés (findOneBy-ou-crée). Suite Stock verte. | En attente d'assignation A. | — |

## Signalements à claude-A — 26/08

**1. `DmsFixtures` n'est PAS propre (ton ordre du 26/08 dit « Dms propre, rien à y faire »).**
`app/src/Dms/DataFixtures/DmsFixtures.php` crée **cinq permissions `dms.*`** (lignes 37-41) et le
**rôle `ROLE_LIENS_PUBLICS`** (ligne 58) par `new` **sans garde** ; seul `Administrateur groupe` est
réutilisé par `findOneBy`. Un rechargement casserait donc sur `dms.read` en premier. Comme Dms est
désormais chez moi, je l'ai corrigé sur le même patron (mécanique, aucun comportement changé) —
**fait + testé** (double chargement vert). Reviens vers moi si tu avais une raison de l'exclure
(rôle `estModele` géré ailleurs ?) et je révise.

**2. `Utilisateur.email` porte aussi une unicité globale — la garde Rôle+Permission ne suffit pas à un
vrai rechargement.** J'ai gardé Rôle + Permission comme demandé (et comme le font `PersonnelFixtures`
et `L11Fixtures`, ta référence), mais `creerUtilisateur` reste non gardé partout. Un **chargement
complet** unique passe (emails distincts entre fixtures) ; un **rechargement** sur une base peuplée
échouera au premier utilisateur. Si l'objectif inclut la régénération préprod sans purge préalable,
il faut décider à l'échelle de la flotte si `creerUtilisateur` doit devenir idempotent lui aussi.
Décision de périmètre → à toi, je ne tranche pas.

| 01/09 — | **Chantier Trésorerie FIN-4 « Alertes de trésorerie proactives » : LIVRÉ ET POUSSÉ** (`1b28bf8e`, branche claude-B). Entité `TreasuryCashAlert` (API lecture seule, `finance.read`) + enum `CashAlertStatus`, anti-répétition **garantie base** (colonne générée virtuelle + index unique : au plus 1 alerte `open`/établissement, MariaDB), commande planifiée `finance:treasury:verifier-seuils` (classe `CheckThresholdsCommand`, une transaction par établissement), service `ThresholdBreachProjectionCalculator`, événement `treasury.threshold_breached` au catalogue → notification **Warning** écran finance, 2 migrations **additives** (`Version20260901090000` ALTER TreasurySettings, `Version20260901090100` CREATE table). 15 tests seuils (203 assertions) + cloisonnement inter-établissements, tous verts. **Tous les garde-fous du dépôt verts au push.** | Artefact récap livré à l'utilisateur. Points de revue non bloquants pour toi ⬇️. En attente d'assignation. | — |

## Signalements à claude-A — 01/09 (chantier alertes trésorerie, non bloquants)

**1. Migrations à jouer à l'intégration.** Deux migrations **additives** attendent
`doctrine:migrations:migrate` sur la base de dev partagée : `Version20260901090000`
(colonnes `cash_alert_threshold_cents` nullable + `cash_alert_horizon_days` défaut 30 sur
TreasurySettings) et `Version20260901090100` (table `finance_treasury_cash_alert` avec la colonne
générée `open_establishment_id` + index unique). Aucune donnée existante touchée. Le schéma de test
est construit depuis les métadonnées ORM (`SchemaDuHarnais`) + un listener qui ajoute la colonne
générée, donc les tests ne dépendent pas de la migration ; la prod/préprod si.

**2. Gravité et périmètre de la notification — à confirmer en revue (déjà annoté dans le code).**
J'ai posé `treasury.threshold_breached` en **Warning** / écran+droit `finance/read`. La règle voisine
`treasury.discrepancy_detected` est en **Critical** / `compta/lire`. Choix délibéré (un franchissement
*projeté* laisse des marges ; un écart *constaté* non), mais les deux règles trésorerie divergent sur
le couple module/droit. Si la flotte a tranché « tout Trésorerie sous `compta/lire` », je réaligne en
une ligne. Commentaire laissé au-dessus de la règle (`NotificationRule.php`).

**3. Deux commandes-sœurs absentes du `ScheduleCatalog` (bug préexistant, hors mon chantier).**
En ajoutant `finance:treasury:verifier-seuils` au catalogue de planification, j'ai constaté que
`finance:treasury:detecter-ecarts` et `...suggerer-rapprochements` (commandes déjà en place) n'y
figurent pas : elles existent mais ne sont planifiées nulle part. À qui revient leur périmètre de
décider s'il faut les y inscrire. Je ne touche pas au travail d'un autre sans ton feu vert.

**4. Numérotation RG-TRE/US-TRE qui se recouvre entre les 3 specs d'évolution trésorerie.**
Les specs `spec-treasury-{business-reconciliation,cash-alerts,auto-reconciliation}.md` (proposées,
poussées) réutilisent des plages RG-TRE/US-TRE qui se chevauchent. Sans importance tant qu'une seule
est implémentée (les alertes), mais à renuméroter proprement avant d'attaquer les deux autres, pour
éviter des références ambiguës. Les directions « rapprochement par flux métier » et « rapprochement
automatique » restent spécifiées et **attendent l'arbitrage de l'utilisateur** (la première est
bloquée sur des décisions PayFiP/M2 + comptabilisation contrepartie SEPA).
