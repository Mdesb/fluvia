# Plan — chaine-encaissement

**Spec :** `features/chaine-encaissement/specs/spec-chaine-encaissement.md` (CP-1 validée le 08/09 par Maxime)
**Branche :** `feature/chaine-encaissement`
**Base :** `origin/main` @ `0c298ce6`

---

## 1. Décisions

**D-a — Aucun second moteur d'écritures, aucun second émetteur de factures.**
G-1 passe par `EmettreFactureDirecteHandler::emettre()` (`app/src/Facturation/Service/EmettreFactureDirecteHandler.php:52`),
qui numérote, écrit l'écriture au journal `FAC` et scelle NF525 dans une transaction unique.
G-2 passe par `DirectLedgerEntryBuilder::construire()` (`app/src/Compta/Service/DirectLedgerEntryBuilder.php:45`),
qui existe précisément pour ça et **ne flush pas** — la transaction reste à l'appelant.
Le module de facturation interdit explicitement de réimplémenter ces étapes ; son propre commentaire
le dit (`SubscriptionInvoicer`, en-tête de classe).

**D-b — Le patron d'émission est copié de `SubscriptionInvoicer`, pas réinventé.**
`app/src/Subscription/Service/SubscriptionInvoicer.php:76-127` fait déjà exactement le geste de G-1
pour la facturation SaaS. On en reprend les trois mécaniques, y compris celles qui ne se voient pas :
1. **réserver l'unicité AVANT d'émettre** (ligne de registre + contrainte unique en base), en
   rattrapant `UniqueConstraintViolationException` — « arrivé deuxième, la facture du gagnant fait foi » ;
2. **retirer la réservation si l'émission échoue**, pour qu'un échec technique ne se lise pas ensuite
   comme « déjà facturé » ;
3. **dériver les dates de la PÉRIODE, jamais de l'heure d'exécution** — une reprise après incident
   doit produire la même date d'échéance.

**D-c — Le taux de TVA voyage dans le DTO existant, on n'ajoute pas un port parallèle.**
`EcheanceSepaDue` (`app/src/Sepa/Dto/EcheanceSepaDue.php`) est déjà le seul objet que les trois
verticales rendent au module SEPA, et `CompositeEcheanceSepaSource` les agrège déjà toutes
(`app/src/Sepa/Service/CompositeEcheanceSepaSource.php:27`). On lui ajoute **un champ optionnel en
fin de constructeur** (`?string $tauxTvaValeur = null`), rétro-compatible pour les trois appelants
existants. Créer un second port d'énumération dupliquerait la requête et ferait diverger les deux
listes au premier correctif — principe 5 (pas de convention parallèle).

**D-d — Une ligne d'écriture sans TVA emprunte un taux, comme le fait déjà la ligne 411.**
`LigneEcriture.tauxTva` est `nullable: false` en base (`app/src/Compta/Entity/LigneEcriture.php:45`).
Une écriture d'encaissement (débit trésorerie / crédit 411) n'a pourtant aucune TVA.
`EmettreFactureDirecteHandler:151` résout déjà ce cas en donnant à la ligne client `$premierTaux`,
le taux de la première ligne du document. L'écriture d'encaissement **reprend le taux porté par la
ligne 411 qu'elle solde** (`Facture::getLigneEcritureClient()->getTauxTva()`) : même convention, et le
taux vient de la pièce soldée plutôt que d'un choix arbitraire.

**D-e — Nommage anglais pour tout fichier neuf** (D5). Les fichiers créés portent des noms anglais
(`PaymentLedgerPoster`, `InstallmentInvoice`, `InstallmentInvoicer`, `InvoiceDueInstallmentsCommand`)
même là où le lexique du garde-fou est muet. Les **noms de commandes CLI** restent dans la forme des
voisines (`sepa:echeances:facturer`, à côté de `sepa:preavis:annoncer`).

**D-f — Toute entité neuve rattachable entre dans la liste blanche de son module.**
`InstallmentInvoice` porte un `etablissement` : elle entre dans
`PerimetreFacturationExtension::RESOURCES_ETABLISSEMENT_DIRECT`
(`app/src/Facturation/Doctrine/PerimetreFacturationExtension.php:40`), faute de quoi le garde-fou
n°35 mord — et cet oubli a déjà été commis **trois fois** (`CardRejection`, `DailyClosure`,
`OperationScellee`).

**D-g — Migrations écrites à la main**, jamais `migrations:diff` (CLAUDE.md) : il ratisserait la
dérive des autres sessions. SQL demandé par `doctrine:schema:update --dump-sql`, recopié à la main,
puis `verifier-derive-schema.sh` pour prouver l'écart nul. Colonnes neuves **nullables ou à défaut** :
`deploy-preprod.sh` migre avant de redémarrer FPM, le schéma est donc neuf face à du code ancien
pendant quelques secondes.

---

## 2. Étapes

### Phase A — Le socle comptable (G-2, G-3)

#### Étape 1 — `MoyenPaiement` porte son compte de trésorerie
*Couvre G-3.*

- `app/src/Compta/Entity/MoyenPaiement.php` : `ManyToOne` vers `CompteComptable`,
  `JoinColumn(nullable: true, onDelete: 'RESTRICT')`, groupes `moyen:read` / `moyen:write`.
  Nullable **par nécessité** (D-g) : les 12 moyens existants en préprod n'en ont pas.
- `app/migrations/VersionAAAAMMJJHHMMSS.php` écrite à la main : `ADD COLUMN compte_tresorerie_id BINARY(16) NULL` + FK.
- **Fait quand** : `doctrine:schema:validate` ne signale aucune dérive **nouvelle** (⚠ il est déjà
  rouge pour `ArticleStock` — comparer à la ligne de base, pas à zéro), et
  `./bin/garde-fous.sh` reste vert.

#### Étape 2 — `PaymentLedgerPoster` : l'écriture au journal `ENC`
*Couvre G-2, G-3.*

- Neuf : `app/src/Compta/Service/PaymentLedgerPoster.php`.
- Signature : `poster(ProfilExploitant $profil, MoyenPaiement $moyen, int $montantCentimes, \DateTimeImmutable $date, CompteComptable $compteClient, TauxTva $taux, string $libelle, ?Uuid $counterpartyId, ?string $counterpartyLabel): EcritureComptable`
- Corps : deux `DirectLedgerEntryLine` (débit `moyen.compteTresorerie`, crédit `compteClient`), journal
  résolu par `RegimeComptableResolver::pour($profil)->journalPour($profil, NatureOperation::Encaissements)`
  — même chemin que `RegieHandler:61-66` — qui rend `ENC` et **n'a aujourd'hui aucun appelant** (F-5) ;
  période par `ResolveurComptesFacturation::periodePour()` (`app/src/Facturation/Service/ResolveurComptesFacturation.php:66`).
- **Refus explicites, jamais de silence** : moyen sans `compteTresorerie` →
  `UnprocessableEntityHttpException` nommant le **code du moyen** ; période close → le
  `ConflictHttpException` que `DirectLedgerEntryBuilder:53` lève déjà, laissé remonter.
- **Fait quand** : les tests de l'étape passent (voir §3).

#### Étape 3 — Le règlement d'une facture écrit son encaissement et lettre en groupe
*Couvre G-2.*

- `app/src/Facturation/Service/ReglementFactureHandler.php` : après le `flush()` du règlement,
  appeler `PaymentLedgerPoster::poster()`, puis remplacer `lettrage->lettrer($ligneClient, $auteur)`
  par `lettrerGroupe([$ligneClient, $ligneEncaissement411], $auteur)`
  (`app/src/Compta/Service/LettrageHandler.php:59`, qui exige ≥2 lignes et Σdébit = Σcrédit).
- ⚠ `ReglementFactureHandler` reçoit aujourd'hui un `moyen` qui est une **chaîne** (`string $moyen`) ;
  il faut le résoudre vers l'entité `MoyenPaiement` par son `code`, via
  `ReferentielReglementDoctrineAdapter`. Refuser si le code est inconnu.
- ⚠ **Le lettrage n'a lieu qu'au solde complet** (comportement actuel, CA-5) : un règlement partiel
  écrit son encaissement mais ne lettre pas encore. Ne pas changer cette règle ici.
- **Fait quand** : régler une facture en deux fois produit **deux** écritures `ENC` et **un** lettrage
  groupé au second.

### Phase B — La facture d'échéance (G-1, G-1bis)

#### Étape 4 — L'échéance transporte son taux de TVA
*Couvre G-1bis.*

- `app/src/Sepa/Dto/EcheanceSepaDue.php` : ajouter `public readonly ?string $tauxTvaValeur = null`
  **en dernier paramètre** (rétro-compatible pour les 3 sources existantes).
- `app/src/Sport/Sepa/SportEcheanceSepaSource.php` : le remplir depuis
  `abonnement → formule → Produit.tauxTva`. ⚠ `Formule` n'a **pas** de côté inverse vers `Produit`
  (`Produit.formule` est un `OneToOne` propriétaire, `app/src/Offre/Entity/Produit.php:246`) : la
  résolution se fait par requête sur `Produit.formule = :formule`, jointe dans la requête existante
  pour ne pas faire N+1.
- Les deux autres sources (`ReservationEcheanceSepaSource`, `CardFallbackDebtSource`) laissent `null` —
  elles seront donc refusées à l'émission (G-1bis), ce qui est le comportement voulu et **dit**.
- **Fait quand** : le DTO se construit sans le champ (3 appelants inchangés) et avec.

#### Étape 5 — Le registre d'unicité `InstallmentInvoice`
*Couvre G-1.*

- Neuf : `app/src/Facturation/Entity/InstallmentInvoice.php` — `etablissement`, `originReference`
  (string 64, la `referenceOrigine` de l'échéance), `invoiceId`, `issuedAt`, `totalCents`.
- `UniqueConstraint` sur `(origin_reference)` — c'est **la** garantie anti-doublon, pas le `findOneBy`
  qui la précède (patron `SubscriptionInvoice`, `app/src/Subscription/Entity/SubscriptionInvoice.php:31`).
- Migration manuelle (D-g) + **inscription dans la liste blanche de l'extension Doctrine de
  `Facturation`** (D-f).
- **Fait quand** : garde-fous verts, dont le n°35 (entité rattachable hors liste).

#### Étape 6 — `InstallmentInvoicer` : composer, réserver, émettre
*Couvre G-1, G-1bis.*

- Neuf : `app/src/Facturation/Service/InstallmentInvoicer.php`, calqué sur
  `SubscriptionInvoicer::facturerLeMois()` (D-b).
- Destinataire : `MandatSepa → client`, via `FactureDirecteBuilder::appliquerDestinataire()`
  (`app/src/Facturation/Service/FactureDirecteBuilder.php:30`), type `Particulier`, `clientRef` posé.
- Ligne unique : libellé de l'échéance, montant, taux résolu de `tauxTvaValeur` vers une entité
  `TauxTva` **du profil**.
- ⚠ **Le point le plus délicat du lot.** `Produit.tauxTva` est une valeur décimale (`"20.00"`,
  `app/src/Offre/Entity/Produit.php:232`) alors que `LigneFacture` exige une **entité** `TauxTva`.
  La résolution cherche les `TauxTva` du profil dont la valeur égale la décimale : **zéro résultat ou
  plus d'un → refus nommant la formule**, jamais un choix par défaut. Deux taux peuvent légitimement
  porter 20,00 (catégories ou territoires différents), et la catégorie est portée par le taux, pas
  par la ligne.
- `dateEcheance` dérivée de la date d'échéance + `getDelaiPaiementDefautJours()`, **pas** de `now()` (D-b.3).
- **Fait quand** : les tests de l'étape passent.

#### Étape 7 — La commande planifiée, et ses trois câblages
*Couvre G-1.*

- Neuf : `app/src/Facturation/Command/InvoiceDueInstallmentsCommand.php`, commande
  `sepa:echeances:facturer`.
- Boucle : pour chaque établissement, `CompositeEcheanceSepaSource::echeancesDues($etab, $today)`,
  puis `InstallmentInvoicer` par échéance. **Un refus (G-1bis) n'arrête pas la boucle** : il est
  compté et nommé dans le rapport de sortie.
- ⚠ **Date de prise d'effet obligatoire** (spec, hors périmètre) : la commande n'émet **rien** pour
  une échéance antérieure à un paramètre de démarrage. Sans ça, le premier passage émettrait d'un
  coup une facture scellée par échéance passée — irréversible.
- ⚠ **Mode à blanc** (`--simuler`) qui compte sans écrire. ⚠ Il doit sortir **après** les mêmes gardes
  que le mode réel, pas avant : un mode à blanc qui court-circuite ce qu'il simule annonce des
  résultats que le mode réel ne produira pas.
- **Trois câblages, pas un** (spec §Contraintes) : la commande, `ScheduleCatalog`
  (`app/src/Platform/Scheduling/ScheduleCatalog.php`), et `TACHES_AUTORISEES`
  (`infra/ordonnanceur.sh:128`). Il en manque un et `platform:scheduler:run --only=…` sort **sans
  rien faire, code 0**.
- **Fait quand** : vue **mordre** (une échéance due est facturée) **et épargner** (une échéance non
  due, et une déjà facturée, ne le sont pas) — avant toute entrée dans `TACHES_AUTORISEES`, comme
  l'exige l'en-tête de `infra/ordonnanceur.sh:46`.

### Phase C — Le recouvrement (G-4, G-5, G-6)

#### Étape 8 — « Réglé » accepte un corps
*Couvre G-4.*

- `app/src/Recouvrement/Entity/IncidentImpaye.php:41` : l'opération `/resoudre` passe de
  `input: false` à un DTO d'entrée `ResolveIncidentInput` (neuf, `app/src/Recouvrement/Dto/`) :
  `canal` (enum `CanalResolutionImpaye`, requis), `moyenPaiement` (code, requis),
  `dateEncaissement` (défaut : aujourd'hui), `reference` (requise si `MoyenPaiement.exigeReference`).
- `ResolutionImpayeHandler::resoudre()` : `setCanalResolution($input->canal)` au lieu de la constante
  `App1Clic` en dur.
- ⚠ **La validation doit s'exécuter AVANT le processeur** (garde-fou n°34) — contrainte sur le DTO,
  pas un contrôle dans le processeur.
- **Fait quand** : un test par valeur de l'enum ; les trois qui échouaient (`virement`, `caisse`,
  `autre`) passent.

#### Étape 9 — Le bouchon CB refuse au lieu de confirmer
*Couvre G-6.*

- `app/src/Recouvrement/Adapter/EncaissementImmediatStubAdapter.php` : `confirmerPaiement()` rend
  `confirme: false`, et `ResolutionImpayeHandler` propage un message nommant **l'absence de PSP
  raccordé** plutôt que « encaissement non confirmé ».
- `ResolutionImpayeHandler::resoudre()` n'appelle le port **que** si `canal === App1Clic` : les
  canaux de constat (virement, caisse, autre) ne parlent à aucun PSP.
- **Fait quand** : témoin positif (canal `virement` passe sans toucher le port) **et** témoin négatif
  (canal `app_1_clic` refuse en nommant le PSP). Le refus seul ne prouverait rien.

#### Étape 10 — L'impayé retrouve sa facture et la solde
*Couvre G-5.*

- `IncidentImpaye` : `ManyToOne` nullable vers `Facture`, posé à la détection depuis
  `referenceEcheanceOrigine` (déjà rempli, `DeclarerRejetSepaProcessor:156`) via une lecture de
  `InstallmentInvoice.originReference`. Migration manuelle.
- `ResolutionImpayeHandler` : si la facture existe, appeler `ReglementFactureHandler::enregistrer()`
  avec le moyen/référence/date de l'étape 8 — ce qui déclenche l'écriture `ENC` de l'étape 3 **par le
  chemin normal**, sans second appel.
- ⚠ **Si la facture n'existe pas** (les incidents nés avant ce lot : l'unique incident de la préprod
  est dans ce cas), enregistrer la résolution et poster l'écriture `ENC` avec le client en
  contrepartie — **et le dire à l'écran**, ne pas le cacher.
- **Fait quand** : les deux chemins sont testés, y compris celui sans facture.

### Phase D — Les écrans (G-4, G-7, G-8)

#### Étape 11 — La fenêtre « Réglé »
*Couvre G-4.*

- `frontend/src/components/ImpayesRecouvrement.jsx:133` : `resoudre()` ouvre une fenêtre au lieu
  d'un `confirmer()`. Champs : canal, moyen (depuis `api.moyensPaiement()`, déjà utilisé par
  `Facturation.jsx:634`), date, référence conditionnée par `exigeReference`.
- Nomme la facture soldée quand il y en a une ; dit qu'il n'y en a pas sinon.
- **Fait quand** : `verifier-boutons-nommes.mjs`, `verifier-clic-clavier.mjs` et
  `verifier-contrastes.mjs` restent verts.

#### Étape 12 — La facture montre ses règlements
*Couvre G-7.*

- `frontend/src/pages/Facturation.jsx` : bloc « Règlements » (date, moyen, référence, montant,
  auteur), `montantRegle` / `soldeDu`. Les champs sont **déjà sérialisés** par `facture:read`
  (`app/src/Facturation/Entity/Facture.php:305,750,762`) — rien à ajouter côté serveur.
- `app/src/Facturation/State/FactureRenduProvider.php` : `acquitteeLe` et `acquitteeMoyen` **déduits**
  des règlements quand les colonnes stockées sont nulles — exactement comme `mentionAcquittee` l'est
  déjà depuis le correctif du 31/08, dont le commentaire (« DÉDUITE, PAS LUE ») porte le raisonnement.
  Date = celle du **dernier** règlement ; moyen = le libellé s'il n'y en a qu'un, « plusieurs moyens »
  sinon. On n'écrit **rien** dans la facture scellée.
- **Fait quand** : mesuré sur le **build servi**, pas construit — le chunk lit `reglements` et
  `montantRegle` (aujourd'hui : 0 chunk sur l'ensemble, F-7).

#### Étape 13 — La fiche client liste ses factures
*Couvre G-8.*

- ⚠ **Le filtre par client N'EXISTE PAS — mesuré.** `Facture` déclare `statut`, `nature`, `origine`,
  `numero`, `venteOrigine` et rien d'autre (`app/src/Facturation/Entity/Facture.php:162-168`). Et le
  client n'est pas porté par la facture : il l'est par `DestinataireFacturation.clientRef`
  (`app/src/Facturation/Entity/DestinataireFacturation.php:70`). Le filtre à **déclarer** est donc
  `'destinataire.clientRef' => 'exact'`.
  ⚠ **Un filtre non déclaré est accepté par l'API et ne filtre RIEN** : appeler
  `api.factures({ clientRef })` sans l'avoir déclaré rendrait les factures de **tous** les clients
  sous l'étiquette d'un seul — un défaut qui a l'air de marcher.
- `frontend/src/pages/Clients.jsx` : bloc « Factures » (numéro, date, total TTC, solde, statut).
- État vide nommé, pas un tableau muet.
- **Fait quand** : la fiche d'un client à 1 facture affiche 1 ligne ; celle d'un client sans facture
  affiche l'état vide ; et un test prouve qu'un client ne voit **pas** les factures d'un autre.

---

## 3. Tests

| Étape | Tests |
|---|---|
| 1 | Schéma : pas de dérive nouvelle par rapport à la ligne de base. |
| 2 | `PaymentLedgerPoster` : écriture équilibrée au journal `ENC` ; **refus** si moyen sans compte (message nommant le code) ; **passage** si moyen configuré ; refus si période close. |
| 3 | Règlement total → 1 écriture `ENC` + lettrage groupé, les 2 lignes 411 partageant le `reconciliationCode` ; règlement partiel → écriture, **pas** de lettrage ; moyen inconnu → refus. |
| 4 | DTO construit avec et sans le champ ; `SportEcheanceSepaSource` rend le taux du produit ; pas de N+1 (compte de requêtes). |
| 5 | Insertion concurrente sur la même `originReference` → une seule ligne, `UniqueConstraintViolationException` sur l'autre. |
| 6 | Émission nominale ; **idempotence** (2ᵉ appel rend la même facture) ; échec d'émission → réservation retirée, mois rejouable ; taux absent → refus nommant la formule ; taux ambigu (2 `TauxTva` à 20,00) → refus. |
| 7 | Mord (échéance due facturée) **et** épargne (non due, déjà facturée) ; date de prise d'effet respectée ; `--simuler` n'écrit rien **et** rend le même compte que le mode réel ; la tâche répond à `platform:scheduler:run --status`. |
| 8 | Un test par canal de l'enum ; référence obligatoire quand `exigeReference` ; validation exécutée avant le processeur. |
| 9 | Témoin positif (virement passe, port non appelé) **et** négatif (CB refuse en nommant le PSP). |
| 10 | Impayé avec facture → `ReglementFacture` créé, facture `payee`, écriture `ENC` présente ; impayé **sans** facture → pas de `ReglementFacture`, écriture `ENC` quand même, message explicite. |
| 11–13 | Garde-fous frontaux (boutons nommés, clic/clavier, contrastes, écart client/serveur) ; cloisonnement des factures par client. |

**Suite complète** : `./infra/test-stack.sh up allaccess76` puis `run allaccess76` — dans le
**worktree**, jamais le clone de déploiement.

---

## 4. Couverture Goal → Étape

| Objectif | Étapes |
|---|---|
| **G-1** — facture émise à la date d'échéance | 5, 6, 7 |
| **G-1bis** — taux du produit, ou refus | 4, 6 |
| **G-2** — écriture au journal `ENC` + lettrage groupé | 2, 3, 10 |
| **G-3** — compte de trésorerie par moyen | 1, 2 |
| **G-4** — canal / moyen / date / référence | 8, 11 |
| **G-5** — l'impayé référence sa facture et la solde | 10 |
| **G-6** — le bouchon CB refuse | 9 |
| **G-7** — la facture montre ses règlements | 12 |
| **G-8** — la fiche client liste ses factures | 13 |

Aucune étape ne couvre un objectif absent de la spec. Les 9 objectifs sont couverts.

---

## 5. Ordre de livraison et points de non-retour

Les phases sont **livrables séparément** et dans cet ordre — A, puis B, puis C, puis D — chaque phase
laissant le dépôt vert et cohérent.

⚠ **Le seul point de non-retour du lot est l'étape 7.** Tout le reste se corrige ; une facture scellée,
non. Le premier passage réel de la commande doit être fait **à blanc**, lu, puis autorisé — et la date
de prise d'effet posée **avant** que la tâche entre dans `TACHES_AUTORISEES`.
