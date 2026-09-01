# Spec — Rapprochement bancaire quasi-automatique (`App\Finance\Treasury`, évolution de `FIN-4`)

- **Lot / module :** évolution du module `finance` existant, brique `App\Finance\Treasury`
  (`specs/finance/plan-treasury.md`, `specs/finance/spec-treasury.md`). N'introduit aucun nouveau
  module, aucune nouvelle capacité — étend `App\Finance\FinanceModule` une 4ᵉ fois (§7, même
  coordination que les 3 extensions précédentes, `plan-treasury.md` §0.11/§7 point 4).
- **Stories couvertes :** **US-TRE-11** (rapprochement en lot haute confiance), **US-TRE-12**
  (apprentissage des correspondances) — ⚠ **HORS BACKLOG**, comme `US-TRE-01` à `10` (`spec-treasury.md`
  en-tête) : cette évolution prolonge la même numérotation non issue de `backlog.html`, à faire
  valider/chiffrer avant développement.
- **Règles de gestion :** **RG-TRE-10 à RG-TRE-15** (nouvelles — comblent la réserve `RG-TRE-10` à `13`
  déjà annoncée par l'en-tête de `spec-treasury.md` et l'étendent jusqu'à `15`). Règles **réutilisées,
  non redéfinies** : `RG-TRE-01` à `09` (`spec-treasury.md` §4, en particulier **RG-TRE-03** — la
  requête de candidats et sa tolérance de montant nulle, inchangée à l'octet près par cette évolution),
  `RG-SOCLE-01` à `07`.
- **Statut :** proposée.

## 1. Objectif

Faire gagner du temps au Trésorier/Comptable sur les lignes de relevé bancaire qui **ne font aucun
doute**, en lui proposant de les rapprocher **d'un coup** plutôt qu'une par une — tout en gardant un
**regard humain obligatoire** avant que quoi que ce soit ne soit lettré (irréversible, NF525) — et faire
en sorte que l'outil **retienne** ses choix de rapprochement pour aller plus vite la fois suivante sur
les mêmes libellés bancaires récurrents, sans jamais décider à sa place.

## 2. Périmètre

### Inclus
- **Rapprochement en lot haute confiance** : identification, revue et confirmation groupée des lignes
  de relevé dont l'appariement est **sans ambiguïté** (US-TRE-11, RG-TRE-10/11/12).
- **Apprentissage** : mémorisation d'une correspondance confirmée (libellé bancaire ↔ libellé de ligne
  d'écriture) pour l'établissement, utilisée pour **enrichir le classement** des candidats d'une future
  ligne au libellé identique — jamais pour sélectionner seule (US-TRE-12, RG-TRE-13/14/15).
- Nouvelle entité `ReconciliationRule` et son API de consultation/correction/oubli.
- Extension de `ReconciliationCandidate` (champ `learnedMatch`) et de
  `BankReconciliationSuggestionCalculator::candidats()` (lecture seule, aucune écriture nouvelle).

### Exclu (pour l'instant)
- **Toute automatisation qui lettrerait sans revue humaine** — reste explicitement hors périmètre,
  cohérent avec `spec-treasury.md` §2 (« le rapprochement automatique à 100 % sans validation humaine »).
  Cette évolution accélère la revue, elle ne la supprime jamais.
- **Le cas groupé (`lettrerGroupe()`, 2+ lignes d'écriture pour une ligne de relevé)** — reste traité
  ligne par ligne (RG-TRE-04, `spec-treasury.md` §4.3), n'entre pas dans le lot haute confiance de cette
  évolution ni dans l'apprentissage (§4.5, ⚠ HYPOTHÈSE simplicité v1).
- **Toute modification de `BankReconciliationSuggestionCalculator`'s critère de sélection des candidats**
  (requête montant/date, RG-TRE-03) — inchangée : cette évolution **enrichit le tri et le marquage** des
  candidats déjà renvoyés, elle n'en ajoute, n'en retire et n'en filtre aucun.
- **Le rapprochement manuel ligne-par-ligne existant** (`GET …/suggestions`, `POST …/reconcile` sur une
  seule ligne, `POST …/ignore`) — **strictement inchangé**, cette évolution est additive (§7 anti-
  régression).
- **Import OFX/CAMT.053, devises étrangères, généralisation de l'apprentissage au cas groupé** — hors
  périmètre v1, cohérent avec `spec-treasury.md` §7/§9.

## 3. Acteurs & droits

| Acteur | Peut | Ne peut pas | Permission |
|---|---|---|---|
| **Trésorier / Comptable** | Consulter le lot haute confiance d'un compte, confirmer tout ou partie du lot après revue, consulter les règles apprises, corriger (désactiver) ou oublier (supprimer) une règle | Confirmer un lot sans avoir consulté la revue (l'API ne l'y autorise techniquement pas, §4.2) | `finance.read`, `finance.treasury_reconcile` |
| **Direction / Responsable financier** | Consulter le lot haute confiance et les règles apprises (lecture seule) | Confirmer un rapprochement | `finance.read` |
| **Administrateur** | *(aucun réglage propre à cette évolution — pas de nouveau paramètre)* | — | — |
| **Système** *(la confirmation elle-même, jamais un acteur autonome)* | Enregistrer/renforcer une règle apprise **en conséquence directe** d'une confirmation humaine | Créer une règle sans confirmation humaine préalable, décider seul d'un rapprochement | *(pas de permission — déclenché en interne par `BankReconciliationHandler::confirmer()`, jamais par un endpoint dédié)* |

⚠ HYPOTHÈSE — aucune permission nouvelle : la confirmation en lot et la gestion des règles apprises
réutilisent `finance.treasury_reconcile` (même geste métier que la confirmation ligne-par-ligne
existante, §0.11 du plan : « Treasury ne s'appuie pas sur `App\Autorisation` »). Une permission dédiée
(ex. `finance.treasury_manage_rule`) pourrait être introduite plus tard si le métier souhaite séparer
« qui rapproche » de « qui édite la mémoire d'apprentissage » — non retenue v1, à arbitrer avec M8 comme
tous les modules déjà livrés.

## 4. Comportements & règles

### 4.1 Rapprochement en lot — définition de la haute confiance (US-TRE-11)

- **RG-TRE-10** — Une `BankStatementLine` de statut `unmatched` ou `suggested`, sur un `BankAccount`
  donné, est éligible au rapprochement en lot **haute confiance** si et seulement si
  `BankReconciliationSuggestionCalculator::candidats()` (RG-TRE-03, **inchangée**) renvoie **exactement
  un** candidat pour cette ligne **au moment de la revue** (recalcul **live**, jamais une simple lecture
  du dernier statut posé par la commande planifiée `finance:treasury:suggerer-rapprochements`).
  - L'exigence « montant exact au centime » n'est **pas** un second filtre ajouté par cette évolution :
    elle est déjà structurellement garantie par `candidats()`, dont la requête compare `debitCentimes`/
    `creditCentimes` par **égalité stricte** (`l.debitCentimes = :montant`), jamais par un intervalle de
    tolérance. « Haute confiance » se réduit donc, en pratique, à **une seule condition observable** :
    le nombre de candidats renvoyés vaut exactement 1. Cette réduction est documentée explicitement pour
    qu'un futur agent ne réintroduise pas un second contrôle de montant redondant, ou pire, une
    tolérance qui n'existe nulle part ailleurs dans le module.
  - Pourquoi ce critère est **volontairement strict** (aucun score de similarité, aucun seuil de
    confiance flou) : le `textScore` de `candidats()` sert **exclusivement au tri**, jamais à départager
    une ambiguïté réelle (RG-TRE-03) — l'appliquer ici reviendrait à lettrer automatiquement une ligne
    sur la base d'une ressemblance textuelle, un pari sur de l'argent réel, pour un public
    d'exploitants **non-comptables** qui ne relira pas chaque lettrage a posteriori avec l'œil d'un
    expert-comptable. Un lettrage est **quasi irréversible** en pratique (NF525, chaînage) — le seul
    filtre qui ne peut objectivement jamais se tromper est l'absence totale d'alternative : montant
    identique au centime **et** une seule écriture scellée non déjà lettrée qui y correspond dans la
    fenêtre de date. Toute ligne avec 0 ou 2+ candidats **n'est jamais éligible**, quel que soit son
    statut persisté.

### 4.2 Revue et confirmation du lot — jamais d'auto-application (US-TRE-11)

- **RG-TRE-11** — La confirmation d'un lot **exige** un corps de requête listant explicitement les
  paires `(statementLineId, ledgerLineId)` que l'utilisateur a validées après avoir consulté
  l'endpoint de revue. **Aucun** endpoint de ce lot n'accepte un raccourci implicite (« tout confirmer »,
  `applyAll: true`, ou toute variante qui appliquerait le lot sans énumérer les paires) : même un geste
  « tout sélectionner » côté interface produit, côté API, la liste explicite des paires affichées à
  l'instant de la revue. L'endpoint de revue (`GET`) est **sans effet de bord** : il ne modifie jamais
  le statut d'une ligne, il ne fait que recalculer et lister (RG-TRE-10) — consulter la revue plusieurs
  fois, ou ne jamais confirmer, est **toujours sans conséquence**.

### 4.3 Échec partiel et idempotence du lot (US-TRE-11)

- **RG-TRE-12** — La confirmation d'un lot traite chaque paire **indépendamment**, dans l'ordre reçu :
  - Chaque paire est revérifiée **au moment de la confirmation** (pas seulement au moment de la revue
    précédente) : la ligne de relevé n'est pas déjà `reconciled`, la ligne d'écriture référencée
    appartient au **même** `CompteComptable` que `bankAccount.ledgerAccount` (même contrôle D8 que
    `ConfirmReconciliationProcessor` existant, §7 point 3 du plan) et **candidats() renvoie encore
    exactement cette ligne comme candidat unique** (RG-TRE-10 revérifiée, pas seulement supposée
    valable depuis la revue).
  - L'échec d'une paire (ligne déjà rapprochée entre-temps, candidat devenu non unique parce qu'une
    autre écriture est apparue depuis la revue, identifiant hors périmètre, ligne d'écriture déjà
    lettrée par ailleurs) **n'interrompt ni n'annule** le traitement des autres paires de la même
    requête. Chaque paire réussie appelle `BankReconciliationHandler::confirmer()` **sans aucune
    modification** de cette méthode (une seule ligne d'écriture passée à chaque appel, verrous
    pessimistes et garde de non-double-lettrage déjà en place, §0.6 du plan — réutilisés tels quels,
    pas de transaction globale englobant le lot entier).
  - La réponse est **toujours `200 OK`** (sauf corps de requête globalement malformé — `matches` absent,
    vide, ou mal typé → `422`) et distingue `succeeded[]` (liste des `statementLineId` effectivement
    rapprochés) de `failed[]` (liste de `{ statementLineId, reason }`, `reason` ∈
    `already_reconciled | no_longer_unique | amount_mismatch | ledger_line_not_found |
    statement_line_not_found | wrong_bank_account`).
  - **Idempotence** : rejouer exactement le même lot après un succès partiel (ex. après une coupure
    réseau côté client) est **sans danger** — les paires déjà traitées réapparaissent en `failed` avec
    le motif `already_reconciled` (aucun second lettrage, `ConflictHttpException` déjà levée par
    `BankReconciliationHandler::confirmer()` capturée et convertie en entrée `failed`), les paires
    encore non traitées sont retraitées normalement. L'état final converge, quel que soit le nombre de
    tentatives.

### 4.4 Apprentissage — mémorisation à la confirmation (US-TRE-12)

- **RG-TRE-13** — Chaque confirmation réussie d'un rapprochement **mono-ligne** (le cas courant §0.6 du
  plan, `LettrageHandler::lettrer()` — qu'elle vienne du rapprochement ligne-par-ligne **existant et
  inchangé**, ou du lot haute confiance §4.2/§4.3 de cette évolution) déclenche, dans la **même**
  transaction que le lettrage, l'enregistrement ou le renforcement d'une correspondance apprise
  (`ReconciliationRule`) :
  - **Clé d'apprentissage** : le libellé bancaire **normalisé** de la `BankStatementLine`
    (`labelPattern` — voir algorithme de normalisation §5), scopé à l'`establishment` du `BankAccount`
    et, en option, au `BankAccount` lui-même (une règle peut s'appliquer à un seul compte ou à tous les
    comptes de l'établissement partageant ce libellé — priorité à la règle la plus spécifique en cas de
    coexistence des deux, §5).
  - **Valeur apprise** : le libellé **normalisé** de la `LigneEcriture`/`EcritureComptable` confirmée
    (`learnedLedgerLabel`) — pas un compte comptable : dans ce module, tous les candidats d'une même
    ligne de relevé partagent **déjà** le même compte comptable par construction
    (`compteComptable = bankAccount.ledgerAccount`, requête de `candidats()`, §0.7 du plan) — un compte
    comptable appris n'aurait donc **aucun pouvoir discriminant** entre plusieurs candidats de la même
    ligne. Ce qui varie, et qu'il est donc utile de retenir, c'est **quel libellé de ligne d'écriture**
    ce libellé bancaire désigne habituellement (⚠ HYPOTHÈSE — adaptation assumée du schéma suggéré par
    la mission, « compte comptable / motif », à la réalité du calculateur existant, documentée
    explicitement plutôt que reprise littéralement sans vérification).
  - **Renforcement/auto-correction (RG-TRE-15)** — si une règle existe déjà pour cette clé et que le
    libellé confirmé cette fois est **identique** au libellé déjà mémorisé, `matchCount` est incrémenté
    et `lastMatchedAt` mis à jour (renforcement). S'il **diffère**, le libellé mémorisé est
    **remplacé** par le nouveau et `matchCount` réinitialisé à 1 — le choix humain le plus récent
    l'emporte toujours, sans action de correction dédiée.
  - Si le libellé de la ligne d'écriture confirmée est **vide**, aucune règle n'est créée ni mise à jour
    (rien à apprendre, cas limite §7).
  - Le cas rare **groupé** (`lettrerGroupe()`, 2+ lignes d'écriture pour une ligne de relevé) **n'alimente
    pas** l'apprentissage (hors périmètre v1, §2 « Exclu »).

### 4.5 Apprentissage — enrichissement du calculateur, jamais une imposition (US-TRE-12)

- **RG-TRE-13 (suite)** — `BankReconciliationSuggestionCalculator::candidats()` consulte, après avoir
  calculé la liste de candidats **inchangée** (RG-TRE-03), la ou les `ReconciliationRule` actives
  applicables (établissement du compte bancaire, compte bancaire spécifique en priorité sur une règle
  d'établissement) dont `labelPattern` égale le libellé normalisé de la `BankStatementLine` en cours :
  - Si **exactement un** candidat de la liste déjà calculée a un libellé de ligne d'écriture normalisé
    **égal** à `learnedLedgerLabel` de la règle trouvée, ce candidat est marqué `learnedMatch = true`
    (nouveau champ, §5) et **replacé en tête** de la liste retournée (même logique de tri que le
    `textScore` existant — ce champ vient s'ajouter, il ne le remplace pas).
  - Si **zéro ou plusieurs** candidats correspondent au libellé appris, **aucun** n'est marqué : la
    règle ne désambiguïse rien dans ce cas, elle ne devine jamais (même prudence que le `textScore`,
    RG-TRE-03).
  - La mémoire **n'ajoute, ne retire et ne filtre jamais** aucun candidat de la liste produite par la
    requête montant/date de RG-TRE-03 — elle réordonne et marque uniquement.
  - **La mémoire n'affecte jamais RG-TRE-10** : le calcul de « haute confiance » (nombre brut de
    candidats) est **totalement indépendant** de `learnedMatch` — une ligne à 2 candidats reste
    ambiguë et hors du lot haute confiance même si l'un des deux porte `learnedMatch = true`. Cette
    indépendance est une règle à part entière, pas un détail d'implémentation : elle garantit qu'aucune
    évolution future de l'apprentissage ne puisse, même involontairement, faire glisser une ligne
    ambiguë dans le lot appliqué sans revue supplémentaire.

### 4.6 Correction et oubli d'une règle apprise (US-TRE-12)

- **RG-TRE-15** — Au-delà de l'auto-correction silencieuse (§4.4), un utilisateur habilité
  (`finance.treasury_reconcile`) peut :
  - **désactiver** une règle (`active = false`, réversible) — elle cesse immédiatement d'enrichir
    `candidats()` sans être supprimée (historique conservé) ;
  - **oublier définitivement** une règle (suppression) — sans effet rétroactif sur les lignes déjà
    rapprochées historiquement (aucune donnée de lettrage ne référence `ReconciliationRule`, lien
    purement consultatif/heuristique).

### 4.7 Cloisonnement des règles apprises (US-TRE-12)

- **RG-TRE-14** — Une `ReconciliationRule` porte un `establishment` **obligatoire** et n'est **jamais**
  consultée, renforcée ni corrigée en dehors de ce périmètre. La recherche d'une règle applicable est
  filtrée **explicitement** par l'établissement du `BankAccount` de la ligne en cours, **indépendamment**
  de `PerimetreFinanceExtension` (celle-ci ne s'applique qu'aux opérations API Platform standard ; le
  calculateur et la commande planifiée `finance:treasury:suggerer-rapprochements` consomment
  `EntityManager` directement et doivent reproduire le filtre — même exigence déjà documentée pour
  `BankReconciliationSuggestionCalculator` vis-à-vis du compte comptable, §0.7 du plan). Deux
  établissements, même avec des libellés bancaires bancairement identiques (ex. deux clients de la même
  banque recevant des virements du même émetteur), **ne partagent jamais** une règle apprise.

## 5. Objets de données

| Objet | Champ | Type | Contraintes | Notes |
|---|---|---|---|---|
| **`ReconciliationRule`** *(nouvelle entité, `App\Finance\Treasury\Entity`)* | id | uuid | PK | — |
| | establishment | ref Etablissement | requis | cloisonnement, RG-TRE-14 — jamais partagé |
| | bankAccount | ref BankAccount ? | optionnel | portée : `null` = s'applique à tous les comptes de l'établissement partageant le libellé ; renseigné = portée restreinte à ce compte, priorité sur une règle d'établissement lors de la recherche |
| | labelPattern | string(255) | requis | libellé bancaire **normalisé** (§ algorithme ci-dessous) — clé d'apprentissage, RG-TRE-13 |
| | learnedLedgerLabel | string(255) | requis, non vide | libellé **normalisé** de la ligne d'écriture confirmée — valeur apprise |
| | ledgerAccount | ref CompteComptable ? | optionnel, informatif | = `bankAccount.ledgerAccount` au moment de l'apprentissage — **redondant aujourd'hui** (tous les candidats d'une ligne partagent déjà ce compte, §4.4), conservé pour traçabilité/évolution future, jamais lu par le calculateur |
| | matchCount | int | défaut 1, ≥ 1 | nombre de confirmations ayant renforcé cette règle sans contradiction, RG-TRE-15 |
| | lastMatchedAt | datetime | requis | dernière confirmation ayant créé/renforcé/corrigé la règle |
| | active | bool | défaut `true` | `false` = désactivée (§4.6), n'enrichit plus `candidats()` |
| | createdAt, createdBy | datetime, ref Utilisateur | requis | auteur de la confirmation qui a créé la règle |
| | contrainte | — | `UNIQUE(establishment, bankAccount, labelPattern)` | une seule règle active par clé — la mise à jour se fait par écriture sur la ligne existante (§4.4), jamais par doublon |
| *(non persisté)* `ReconciliationCandidate` *(champ ajouté)* | learnedMatch | bool | défaut `false` | RG-TRE-13, marqué par le calculateur, jamais par le client |
| *(non persisté)* `HighConfidenceMatch` *(nouveau DTO, `App\Finance\Treasury\Dto`)* | statementLineId, operationDate, label, reference, amountCents, candidate | uuid, date, string, string?, int, `ReconciliationCandidate` | — | ligne de la revue du lot (§4.2), une entrée par `BankStatementLine` éligible |
| *(non persisté)* `BatchReconciliationResult` *(nouveau DTO)* | succeeded, failed | `list<uuid>`, `list<{statementLineId: uuid, reason: string}>` | — | contrat de réponse §4.3 |

**Algorithme de normalisation d'un libellé** (`labelPattern`/`learnedLedgerLabel`, déterministe,
appliqué identiquement des deux côtés) : `trim` → majuscules (`mb_strtoupper`) → suppression des accents
(translittération) → réduction des espaces multiples à un seul → suppression de tout caractère hors
`[A-Z0-9 ]`. ⚠ HYPOTHÈSE — pas d'extraction de motif au-delà de cette normalisation littérale (pas de
suppression des numéros de référence/dates variables intégrés dans un libellé bancaire) : deux
occurrences d'un même émetteur dont le libellé embarque un numéro de virement différent à chaque fois ne
seront **jamais** reconnues comme la même clé, et la règle ne se déclenchera donc jamais pour cet
émetteur — limitation connue, documentée en cas limite (§7), non retenue pour une v1 volontairement
simple (principe « le plus simple possible »).

## 6. API (API Platform)

| Ressource / route | Opération | `security:` | Processor/Provider | Notes |
|---|---|---|---|---|
| — | `GET /finance/treasury/bank-accounts/{id}/reconciliation-batch` | `finance.read` | `HighConfidenceReconciliationProvider` (nouveau) — recalcule **live** via `BankReconciliationSuggestionCalculator` (RG-TRE-10) | JSON `list<HighConfidenceMatch>` — même convention non-`ApiResource` que `GET …/position`\|`…/payment-schedule` (plan §2) ; **aucun effet de bord** (RG-TRE-11) |
| — | `POST /finance/treasury/bank-accounts/{id}/reconcile-batch` | `finance.treasury_reconcile` | `BatchReconciliationProcessor` (nouveau) — corps `{ matches: [{ statementLineId: uuid, ledgerLineId: uuid }, …] }`, délègue chaque paire à `BankReconciliationHandler::confirmer()` **inchangé**, un appel par paire | JSON `BatchReconciliationResult` — `200` toujours (sauf corps malformé → `422`), RG-TRE-12 |
| `ReconciliationRule` | `GetCollection`, `Get` | `finance.read` | — (filtré `PerimetreFinanceExtension`, `establishment` direct comme `BankAccount`) | `#[ApiFilter(SearchFilter)]` : `bankAccount` exact, `active` exact |
| `ReconciliationRule` | `PATCH /finance/treasury/reconciliation-rules/{id}` | `finance.treasury_reconcile` | `ReconciliationRuleProcessor` (nouveau) — seul `active` est modifiable par ce endpoint (désactivation/réactivation, §4.6) | pas de modification de `labelPattern`/`learnedLedgerLabel` via l'API (auto-correction uniquement à la confirmation, RG-TRE-15) |
| `ReconciliationRule` | `DELETE /finance/treasury/reconciliation-rules/{id}` | `finance.treasury_reconcile` | — (suppression standard) | « oubli » définitif, §4.6, sans effet sur les lignes déjà rapprochées |

**Non exposé par ce lot** : pas de `POST` client sur `ReconciliationRule` (une règle ne se crée **que**
par la confirmation d'un rapprochement, jamais manuellement — évite un contournement de l'apprentissage
qui enseignerait une correspondance jamais réellement confirmée) ; pas de modification de
`ConfirmReconciliationProcessor`/`GET …/suggestions`/`POST …/ignore` existants (§7 anti-régression).

`ReconciliationCandidate` (JSON, `GET …/suggestions` **et** dans `HighConfidenceMatch.candidate`) gagne
le champ `learnedMatch: bool` (§5) — ajout de champ, aucune rupture de contrat pour les consommateurs
existants qui ignorent un champ inconnu.

## 7. Sécurité / cloisonnement

- **Cloisonnement établissement** — `ReconciliationRule` ajouté à `PerimetreFinanceExtension::CHAINES`
  avec un tableau vide (`establishment` direct, même patron que `BankAccount`/`TreasurySettings`,
  `plan-treasury.md` §0.2 point 2) pour les opérations API Platform (`GetCollection`/`Get`/`Patch`/
  `Delete`). **Défense en profondeur explicite** (RG-TRE-14) côté calculateur/commande, qui n'empruntent
  pas ce chemin (EntityManager direct) — filtre `establishment = :etablissement` ajouté à la requête de
  recherche de règle, avec `:etablissement` = `bankAccount.establishment.id` de la ligne en cours, jamais
  déduit du contexte HTTP courant (D6, même exigence que le reste du module).
- **`matches[].ledgerLineId` du corps du lot** — identifiant client brut, même défense qu'existant
  (`ConfirmReconciliationProcessor`, D8 §0.2 point 3) : chaque ligne d'écriture référencée doit
  appartenir au **même** `CompteComptable` que `bankAccount.ledgerAccount`, `404` sinon (motif
  `ledger_line_not_found`) — vérifié **par paire**, une paire hors périmètre n'affecte pas les autres
  (RG-TRE-12).
- **`matches[].statementLineId` du corps du lot** — doit appartenir au **même** `BankAccount` que celui
  de l'URL, `404` sinon (motif `statement_line_not_found` ou `wrong_bank_account`) — même principe,
  empêche qu'un lot confirmé sur le compte A rapproche silencieusement une ligne du compte B.
- **Aucune permission supplémentaire introduite** (§3) — surface de droits inchangée par cette
  évolution, décision explicite documentée plutôt qu'un oubli.
- **`ReconciliationRule` ne porte aucune donnée sensible** (pas d'IBAN, pas de montant, seulement des
  libellés normalisés et un identifiant de compte comptable) — aucun risque de fuite comparable à celui
  déjà traité pour `ibanCipher` (§0.3 du plan), pas de groupe de sérialisation à exclure spécifiquement.

## 8. Risques / à valider

1. **Adaptation du schéma d'apprentissage** (§4.4, RG-TRE-13) — la mission suggérait « clé = libellé
   normalisé (+ compte) ; valeur = compte comptable / motif », mais le calculateur existant contraint
   déjà tous les candidats d'une ligne au **même** compte comptable : un compte comptable appris n'a
   donc aucun pouvoir discriminant ici. Ce plan retient le **libellé de ligne d'écriture normalisé**
   comme valeur apprise à la place — lecture la plus cohérente avec le code réel, à confirmer avec le
   demandeur avant merge (même type de divergence documentée que `plan-treasury.md` §7 point 7,
   `matchedLedgerEntry` → `matchedLedgerLine`).
2. **Normalisation de libellé sans extraction de motif variable** (§5) — un émetteur dont le libellé
   change à chaque virement (référence/numéro de facture embarqué) ne bénéficiera jamais de
   l'apprentissage. Simplification assumée v1 (« le plus simple possible » — principe directeur du
   projet), mais à confirmer que ce n'est pas le cas d'usage principal réellement visé par la demande.
   Une évolution future pourrait tokeniser/masquer les séquences numériques avant normalisation.
3. **Portée `bankAccount` optionnelle de `ReconciliationRule`** (§5) — la priorité « règle de compte
   spécifique avant règle d'établissement » en cas de coexistence des deux est une décision de ce plan,
   non explicitement demandée ; à confirmer que c'est le comportement attendu plutôt qu'un refus de
   coexistence (une seule règle active par libellé, quelle que soit sa portée).
4. **Cas rare groupé exclu de l'apprentissage** (§4.4/§2) — cohérent avec la prudence générale de cette
   évolution, mais signalé comme un choix, pas une impossibilité technique : une future itération
   pourrait apprendre du libellé le plus représentatif du groupe si le besoin apparaît.
5. **Pas de nouvelle permission** (§3/§7) — réutilisation de `finance.treasury_reconcile` pour la
   gestion des règles apprises ; à arbitrer avec M8 si une séparation des responsabilités (rapprocher vs
   éditer la mémoire) s'avère nécessaire en pratique.
6. **`HighConfidenceReconciliationProvider` recalcule `candidats()` pour potentiellement toutes les
   lignes `unmatched`/`suggested` d'un compte à chaque appel** (§4.1/§6) — même coût qu'un appel répété à
   `GET …/suggestions` ligne par ligne, pas de nouvelle complexité algorithmique, mais un compte avec un
   grand volume de lignes non rapprochées pourrait rendre cet endpoint sensiblement plus lent qu'un
   `GET` habituel — non mesuré, à surveiller en usage réel plutôt qu'à optimiser par anticipation
   (cohérent avec « le plus simple possible »).

## 9. Tests

- **CA-1 (RG-TRE-10)** — *Étant donné* une ligne de relevé de 500,00 € avec **une seule** écriture
  scellée à 500,00 € dans la fenêtre, *alors* elle apparaît dans `GET …/reconciliation-batch` ; *étant
  donné* la même ligne avec **deux** écritures scellées à 500,00 €, *alors* elle **n'apparaît pas**.
- **CA-2 (RG-TRE-10)** — *Étant donné* une ligne à 500,01 € et une écriture scellée à 500,00 €, *alors*
  elle **n'apparaît jamais** dans le lot haute confiance (tolérance nulle, héritée de RG-TRE-03
  inchangée).
- **CA-3 (RG-TRE-11)** — *Quand* `GET …/reconciliation-batch` est appelé, *alors* aucun statut de ligne
  n'est modifié (rejouer le `GET` plusieurs fois ne change rien) ; *quand* `POST …/reconcile-batch` est
  appelé avec un corps sans `matches` ou `matches: []`, *alors* c'est refusé (`422`), rien n'est
  rapproché.
- **CA-4 (RG-TRE-12)** — *Étant donné* un lot de 3 paires dont la 2ᵉ référence une ligne déjà rapprochée
  entre-temps, *quand* le lot est confirmé, *alors* la réponse est `200`, `succeeded` contient les
  paires 1 et 3, `failed` contient la paire 2 avec `reason = already_reconciled`.
- **CA-5 (RG-TRE-12)** — *Étant donné* un lot déjà confirmé avec succès, *quand* il est **rejoué à
  l'identique**, *alors* la réponse est `200`, `succeeded` est vide, `failed` liste toutes les paires en
  `already_reconciled` — aucun second lettrage, aucune exception non gérée.
- **CA-6 (RG-TRE-13/15)** — *Étant donné* une confirmation mono-ligne dont le libellé bancaire normalisé
  n'a jamais été vu, *alors* une `ReconciliationRule` est créée (`matchCount = 1`) ; *étant donné* une
  seconde confirmation du **même** libellé bancaire vers un libellé de ligne d'écriture **identique**,
  *alors* `matchCount` passe à 2 ; *étant donné* une troisième confirmation du même libellé bancaire vers
  un libellé de ligne d'écriture **différent**, *alors* `learnedLedgerLabel` est **remplacé** et
  `matchCount` retombe à 1.
- **CA-7 (RG-TRE-13, enrichissement)** — *Étant donné* une règle apprise pour un libellé bancaire, et une
  nouvelle ligne de relevé partageant ce libellé avec **deux** candidats de même montant/date dont un
  seul porte le libellé de ligne d'écriture appris, *quand* `candidats()` est appelé, *alors* ce candidat
  est renvoyé avec `learnedMatch = true` et **en tête** de liste, l'autre candidat reste présent
  (**jamais filtré**) ; *étant donné* qu'aucun des deux candidats ne correspond au libellé appris,
  *alors* aucun n'est marqué `learnedMatch`.
- **CA-8 (RG-TRE-13, indépendance de RG-TRE-10)** — *Étant donné* la situation de CA-7 (2 candidats, un
  `learnedMatch`), *alors* cette ligne **n'apparaît pas** dans `GET …/reconciliation-batch` (toujours 2
  candidats bruts, `learnedMatch` n'en réduit jamais le nombre).
- **CA-9 (RG-TRE-14)** — *Étant donné* deux établissements distincts ayant chacun confirmé une ligne au
  libellé bancaire strictement identique vers des libellés de ligne d'écriture différents, *alors*
  chacun conserve **sa propre** `ReconciliationRule`, sans collision ni écrasement croisé ; *étant donné*
  un utilisateur de l'établissement A, *alors* `GET /finance/treasury/reconciliation-rules` ne renvoie
  **jamais** la règle de l'établissement B.
- **CA-10 (RG-TRE-15)** — *Quand* une règle est désactivée (`PATCH … { active: false }`), *alors* elle
  cesse immédiatement d'enrichir `candidats()` (plus de `learnedMatch`) mais reste consultable ; *quand*
  elle est supprimée (`DELETE`), *alors* les lignes déjà rapprochées historiquement grâce à elle
  **restent inchangées** (aucune référence à `ReconciliationRule` dans le lettrage).
- **Anti-régression (§2 « Exclu »)** — rejeu complet de la suite de tests existante du module
  (`BankReconciliationHandlerTest`, `BankReconciliationSuggestionCalculatorTest`,
  `ConfirmReconciliationTest`, `BankStatementImportTest`, …) **sans aucune modification** : cette
  évolution est strictement additive, aucun test existant ne doit changer de comportement attendu.
