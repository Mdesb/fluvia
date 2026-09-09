# Spec — chaine-encaissement

**Statut :** en revue <!-- brouillon → en revue → validée (CP-1) -->
**Auteur :** allaccess-76
**Date :** 2026-09-08

## Contexte & problème

Maxime a demandé si, en marquant un impayé « Réglé » dans Recouvrement, les enregistrements se font
pour la comptabilité, et si l'on retrouve les modes de règlement sur la fiche de la facture. La
mesure a répondu non aux deux, et a montré que le défaut est bien plus large que le recouvrement.

Tous les faits ci-dessous sont **VERIFIED**, mesurés le 08/09 sur le code **réellement déployé**
(conteneur `billetterie-preprod-php-1`, `/app/src`), sur le **build servi** (`/var/www/smartaccess/assets/`)
et sur la base `billetterie_preprod`. Les commandes qui les produisent sont données pour qu'un autre
puisse les refaire.

### F-1 — « Réglé » n'écrit rien de comptable, et le canal est en dur

`POST /api/recouvrement/incidents/{id}/resoudre` est déclarée `input: false` : **aucun corps n'est
accepté**, donc ni moyen, ni date, ni référence. `ResolutionImpayeHandler::resoudre()` pose
`CanalResolutionImpaye::App1Clic` **en dur** — l'enum porte `virement`, `caisse` et `autre`, qu'aucun
chemin ne peut produire. La colonne « Par quel canal » de l'écran affiche donc toujours la même valeur.

### F-2 — L'encaissement CB est un bouchon qui réussit toujours

`app/config/services.yaml` câble `EncaissementImmediatStubAdapter`, dont `confirmerPaiement()` renvoie
`confirme: true` sans condition. « Réglé » ne débite personne et **ne peut jamais échouer**, alors que
le code appelle `initierPaiement()` puis `confirmerPaiement()` comme s'il parlait à un PSP.

### F-3 — Un impayé n'a aucune pièce derrière lui

`IncidentImpaye` ne porte **aucune référence de facture**. Sa chaîne d'origine s'arrête à
`LigneRemiseSepa.referenceOrigine`, une chaîne opaque de 64 caractères. Le champ
`IncidentImpaye.referenceEcheanceOrigine` **existe et est déjà rempli** par
`DeclarerRejetSepaProcessor` (ligne 156) — mais rien ne sait le résoudre.

### F-4 — Le seul type de redevable réel n'est branché sur rien

L'unique incident de la préprod porte `type_redevable = 'crm.client'`, ce que produit **tout** rejet
SEPA (`DeclarerRejetSepaProcessor::TYPE_REDEVABLE_CLIENT`). `RedevableRegistry` ne connaît qu'un seul
port, `sport.abonnement_fitness`. Un incident réel ne déclenche donc **aucun** des deux abonnés de
`IncidentImpayeResoluEvent` — pas même la file d'attente SEPA de Sport.

    docker exec billetterie-preprod-php-1 php bin/console dbal:run-sql \
      'SELECT type_redevable, statut, canal_resolution FROM recouvrement_incident_impaye'

### F-5 — Le journal des encaissements existe et n'a jamais reçu une seule écriture

`NatureOperation::Encaissements` existe et résout vers le journal `ENC` (« Journal des encaissements »),
semé par `AccountingChartSeeder`. **Aucun appelant.** Les 8 fichiers qui créent une `EcritureComptable`
sont tous dans `Compta` et `Facturation` ; aucun n'est atteignable depuis un encaissement.

Mesuré : `FA-2026-00001` porte au grand livre `411000 Redevables` **débit 360,00**, `706100` crédit
300,00, `4457100` crédit 60,00 (journal FAC). Ses **deux virements** de 200,00 et 160,00 sont bien
enregistrés dans `facturation_reglement`, et la ligne 411 est lettrée. Mais **0 écriture au journal ENC** :
pas de débit `512`/`531`, pas de crédit `411`. La créance reste au bilan, la trésorerie n'y entre jamais.

    docker exec billetterie-preprod-php-1 php bin/console dbal:run-sql \
      "SELECT (SELECT COUNT(*) FROM compta_ecriture_comptable) ecritures,
              (SELECT COUNT(*) FROM facturation_reglement) reglements,
              (SELECT COUNT(*) FROM compta_ecriture_comptable e
                 JOIN compta_journal j ON j.id=e.journal_id WHERE j.code='ENC') enc"
    -- ecritures=1  reglements=2  enc=0

### F-6 — Un prélèvement SEPA qui RÉUSSIT ne produit rien non plus

Aucune facture, aucune écriture. **Tout le chiffre d'affaires des abonnements est absent de la
comptabilité** ; l'impayé n'en est qu'un symptôme visible. `App\Sport\Compta\MouvementComptableSepaQueueAdapter`
le dit dans son propre en-tête : « persiste un `MouvementComptableSepa` en file d'attente append-only,
**sans écrire dans `App\Compta\Entity\EcritureComptable`** […] le raccordement GL réel reste à faire ».
Cette file compte **0 ligne** en préprod, puisqu'elle ne se déclenche que pour `sport.abonnement_fitness`
(cf. F-4).

### F-7 — Aucun écran ne montre les règlements d'une facture

L'API les expose pourtant : `facture:read` sérialise `reglements`, `montantRegle` et `soldeDu`, et la
sous-ressource `GET /factures/{id}/reglements` existe. Dans le **build servi**, `montantRegle`
n'apparaît dans **aucun** chunk, et le chunk `Facturation` ne lit jamais `reglements` (témoin positif
dans le même fichier : `soldeDu` y sort 2 fois). Une facture payée montre un solde à 0 — jamais par
quel moyen ni quand.

### F-8 — La fiche client ne mène à aucune facture

`frontend/src/pages/Clients.jsx` ne mentionne jamais `factures` (témoin positif dans le même
fichier : `facturation.gerer` sort 2 fois, `factures` sort 0). On y trouve « Établir un devis », rien
d'autre côté facturation.

### F-9 — Le document rendu porte une mention d'acquittement amputée

`FactureRenduProvider` **déduit** correctement « Facture acquittée » du solde des règlements (correctif
récent, commentaire « DÉDUITE, PAS LUE »). Mais `acquitteeLe` et `acquitteeMoyen` restent **lus dans
les colonnes stockées**, que `ReglementFactureHandler` n'écrit jamais — seuls `AvoirFactureHandler` et
`EmissionFactureJustificativeHandler` les posent. Mesuré : `FA-2026-00001` est `payee` avec 2 règlements
par virement, et `mention_acquittee=0`, `acquittee_le=NULL`, `acquittee_moyen=NULL`. Le document affiche
donc « Facture acquittée » **sans date ni moyen**.

### F-10 — Le patron à suivre existe déjà dans le dépôt

`App\Subscription\Service\SubscriptionInvoicer` fait **exactement** ce que G-1 demande, pour la
facturation SaaS de Fluvia : il compose une `Facture` et la confie à `EmettreFactureDirecteHandler`,
« qui numérote, écrit l'écriture comptable et scelle au sens NF525 — le tout dans une transaction
unique, avec un verrou contre les émissions concurrentes ». Il réserve l'unicité `(abonnement, mois)`
**avant** l'émission. Ce lot ne doit pas inventer un second moteur d'écritures : il doit refaire ce
geste-là pour les échéances des adhérents.

### Arbitrages déjà rendus par Maxime (08/09)

- **Portée C** : traçabilité **+** écriture d'encaissement **+** rattachement de l'impayé à sa pièce.
- **La pièce = option 1** : *une facture par échéance*, plutôt qu'une créance générique ou une facture
  émise seulement en cas de rejet.
- **U-1 tranché** : le taux de TVA vient du **`Produit` de la formule**. Quand il manque, on **refuse
  d'émettre** plutôt que d'inventer un taux.
- **U-2 tranché** : la facture est émise **à la date d'échéance, avant toute tentative de
  prélèvement** — la facturation est découplée de l'encaissement, comme chez Stripe Billing,
  Chargebee, Zuora ou Recurly. Une remise n'émet donc rien : elle **tente d'encaisser des factures
  qui existent déjà**. Un adhérent en retard de trois mois a trois factures, chacune datée de son
  mois, émises en leur temps — jamais une liasse le jour de la remise.

## Objectifs (Goals)

- **G-1 — Une échéance d'abonnement émet sa facture à sa date d'échéance, avant tout prélèvement.**
  Une tâche planifiée émet, pour chaque échéance arrivée à terme, une `Facture` numérotée et scellée
  NF525 avec son écriture au journal `FAC` (411 / produit / TVA collectée), **via
  `EmettreFactureDirecteHandler`** — jamais un second moteur. Unicité `(échéance)` réservée **avant**
  l'émission, sur le patron de `SubscriptionInvoice` (F-10) : un ordonnanceur qui repasse, une
  relance manuelle ou deux exploitants qui cliquent ne produisent jamais un second document.
  Le destinataire est le payeur de l'abonnement (`DestinataireFacturation` de type `Particulier`,
  `clientRef` renseigné). **La remise SEPA n'émet plus rien** : elle tente d'encaisser des factures
  qui existent déjà.

- **G-1bis — Le taux de TVA vient du produit, ou l'émission refuse.** Le taux est celui du `Produit`
  dont la formule est une facette. ⚠ `Produit.tauxTva` est une **valeur décimale** (`"20.00"`), pas
  une référence, alors que `LigneFacture` exige une **entité** `TauxTva` du profil : la résolution
  n'est pas unique (deux taux peuvent porter la même valeur pour des catégories ou des territoires
  différents, et la catégorie est portée par le taux, pas par la ligne). Quand le produit ne porte
  aucun taux, **ou** quand la résolution est ambiguë, l'émission **refuse en nommant la formule** —
  elle n'invente jamais un taux. Un taux faux part dans une facture scellée qui ne se corrige plus.

- **G-2 — Tout encaissement produit une écriture au journal `ENC`.** Débit du compte de trésorerie du
  moyen de paiement, crédit du compte client `411`, à la date d'encaissement. Vaut pour les trois
  chemins : règlement d'une facture, collecte SEPA réussie, résolution d'un impayé. Le lettrage
  devient **groupé** (`LettrageHandler::lettrerGroupe`, qui existe déjà) : la ligne 411 de la facture
  et la ligne 411 de l'encaissement se lettrent l'une contre l'autre, au lieu de lettrer un débit seul.

- **G-3 — Un moyen de paiement porte son compte de trésorerie.** `MoyenPaiement` gagne un
  `compteTresorerie`. Sans lui, l'encaissement **échoue en nommant le moyen non configuré** — jamais
  d'écriture au petit bonheur, jamais de silence.

- **G-4 — « Réglé » cesse d'être un clic muet.** L'opération de résolution accepte un corps :
  `canal`, `moyenPaiement`, `dateEncaissement`, `reference`. Le canal n'est plus en dur (F-1), et
  l'écran demande ces valeurs avant d'agir.

- **G-5 — Un impayé référence sa facture, et le régler la solde.** L'incident porte la facture émise
  en G-1, résolue depuis `referenceEcheanceOrigine` (F-3, le champ est déjà rempli). Le résoudre
  écrit un `ReglementFacture` sur elle avec le moyen et la référence saisis en G-4, ce qui déclenche
  G-2 par le chemin normal. Le lien reste **1:1** : un rejet SEPA porte sur une ligne de remise, donc
  sur une échéance, donc sur exactement une facture, soldée en entier.

- **G-6 — L'encaissement CB non branché refuse au lieu de confirmer.** L'adaptateur par défaut
  **échoue** avec un message qui dit qu'aucun PSP n'est raccordé (F-2). Un bouchon qui réussit produit
  des écrans qui mentent ; un bouchon qui échoue produit des écrans honnêtes sans qu'on ait à y penser.

- **G-7 — La facture montre ses règlements.** À l'écran : la liste (date, moyen, référence, montant),
  le montant réglé et le solde dû (F-7). Sur le document rendu : la mention d'acquittement porte sa
  **date** et son **moyen**, déduits des règlements comme la mention elle-même l'est déjà (F-9).

- **G-8 — La fiche client liste ses factures.** Numéro, date, total, solde, statut ; accès au document
  et à l'enregistrement d'un règlement, sans repasser par l'écran Facturation ni retaper un nom (F-8).

## Hors périmètre

- **Brancher un vrai PSP.** G-6 fait échouer proprement ; il ne raccorde ni Stripe ni personne. Le
  port `EncaissementImmediatInterface` reste en place pour le jour où un PSP sera choisi.
- **Factur-X / EN 16931** — c'est T7, indépendant.
- **La relance / le dunning** — `App\RevenueRecovery` existe et clôt déjà ses dossiers sur
  `payment.succeeded`.
- **La conversion de devise** — reste ouverte (T8).
- **Le rattrapage rétroactif.** Les échéances déjà collectées ne se voient pas fabriquer une facture
  après coup, et les écritures manquantes ne sont pas reconstituées. Reprise éventuelle = lot séparé.
  ⚠ **Et c'est un danger actif, pas seulement une omission.** La tâche de G-1 cherche les échéances
  arrivées à terme : à son **premier** passage, tout l'arriéré y est. Sans garde, elle émettrait d'un
  coup une facture scellée par échéance passée — irréversible, chacune ne se corrigeant que par un
  avoir. La tâche doit donc porter une **date de prise d'effet** au-delà de laquelle seulement elle
  émet, et son premier passage doit être observé en mode sans écriture avant d'être autorisé.
- **Les impayés déjà ouverts sans pièce.** L'unique incident de la préprod n'a pas de facture
  d'origine : G-4 l'enregistre (canal, moyen, date, référence) et G-2 écrit son écriture avec le
  client en contrepartie, mais **aucun `ReglementFacture`** n'est fabriqué pour lui. L'écran doit le
  dire, pas le cacher.

## Parcours utilisateur / UX

**Marquer un impayé réglé.** Le bouton « Réglé » ouvre une fenêtre au lieu d'agir tout de suite :
« Comment cet impayé a-t-il été réglé ? » — canal (virement / caisse / autre, et CB si un PSP est
raccordé), moyen de paiement (liste du référentiel de l'établissement), date d'encaissement (défaut :
aujourd'hui), référence (obligatoire si le moyen l'exige, cf. `MoyenPaiement.exigeReference`). La
fenêtre nomme la facture soldée quand il y en a une, et dit qu'il n'y en a pas quand c'est le cas.
Après validation : « Impayé réglé, l'accès est rouvert » + le numéro de la facture soldée.
**Cas d'erreur** : moyen sans compte de trésorerie configuré → refus nommant le moyen, avec le chemin
pour le configurer ; période comptable close → refus qui le dit.

**Fiche client.** Un bloc « Factures » : numéro, date, total TTC, solde dû, statut. Un clic ouvre le
document ; un bouton enregistre un règlement pour les habilités. **État vide** : « aucune facture pour
ce client », pas un tableau vide muet.

**Fiche facture.** Sous les totaux, un bloc « Règlements » : date, moyen, référence, montant, auteur ;
puis « Réglé : X sur Y » et le solde. **État vide** : « aucun règlement enregistré ».

## Contraintes & décisions techniques connues

- **Aucun second moteur d'écritures.** G-1 passe par `EmettreFactureDirecteHandler`, G-2 par
  `DirectLedgerEntryBuilder` (qui existe précisément pour ça et ne flush pas — la transaction reste à
  l'appelant). `LettrageHandler::lettrerGroupe()` existe déjà et exige Σdébit = Σcrédit.
- **Migrations écrites à la main** (CLAUDE.md), jamais un `migrations:diff` brut : il ratisserait la
  dérive des autres sessions. Demander le SQL par `doctrine:schema:update --dump-sql`, puis vérifier
  l'absence de dérive.
- **Le schéma doit tenir avec les deux versions du code** : `deploy-preprod.sh` migre avant de
  redémarrer FPM. Les colonnes neuves sont donc nullables ou à défaut.
- **Nommage anglais pour tout fichier neuf** (D5), et le garde-fou correspondant a un lexique partiel :
  la règle vaut même quand il ne dit rien.
- **Cloisonnement** : le périmètre vient de la session serveur, jamais d'un identifiant du corps.
  Toute entité neuve rattachable doit entrer dans la liste blanche de l'extension Doctrine de son
  module — le garde-fou n°35 ne pardonne pas cet oubli, et il a déjà été commis trois fois.
- **Ligne de base** : `./bin/garde-fous.sh` était **vert avant ce lot** (48 OK, exit 0) — avec
  **1 garde-fou NON EXÉCUTÉ** (« Manifeste vs catalogue », outil absent de la machine), qui n'est donc
  pas un vert et ne doit pas être compté comme tel.
- **La tâche planifiée de G-1 se câble à TROIS endroits, pas un.** (1) la commande elle-même ;
  (2) `ScheduleCatalog`, faute de quoi `platform:scheduler:run --only=…` sort **sans rien faire et
  code 0** — le garde-fou des tâches fantômes existe précisément parce que ce silence a déjà trompé
  tout le monde le 06/09 ; (3) `TACHES_AUTORISEES` dans `infra/ordonnanceur.sh`. ⚠ Et **le conteneur
  `billetterie-preprod-scheduler-1` doit redémarrer** : la liste est lue au démarrage, donc l'ajouter
  au fichier ne change rien tant qu'il tourne, pendant que le journal continue d'afficher un succès
  par tâche de l'ancienne liste.
- **Une tâche entre dans la liste APRÈS avoir été vue mordre ET épargner** (règle écrite en tête de
  `infra/ordonnanceur.sh`) : il faut donc l'avoir vue émettre une facture pour une échéance due, et
  n'en émettre aucune pour une échéance non due ou déjà facturée.

## Points UNVERIFIED — levés le 08/09 par Maxime

- [x] **U-1 — Quel taux de TVA porte une échéance d'abonnement ?** Deux sources existaient et se
  contredisaient : `Produit.tauxTva` (la formule est une facette d'un `Produit`) contre
  `ParametreFacturationEtablissement.tauxTvaAbonnement`, déjà lu par `SubscriptionInvoicer`… qui
  facture l'**exploitant** pour Fluvia, pas ses adhérents — le réutiliser aurait fait porter un seul
  réglage à deux impôts sans rapport. **Tranché : le taux du `Produit`, et refus d'émettre plutôt
  qu'un taux inventé** (G-1bis). `tauxTvaAbonnement` reste réservé à la facturation Fluvia.

- [x] **U-2 — Quand la facture est-elle émise ?** Mes trois propositions initiales supposaient toutes
  qu'elle naissait au moment de la remise ; Maxime a demandé ce que font les gros acteurs, et la
  réponse a corrigé la question. **Tranché : à la date d'échéance, avant tout prélèvement** — la
  facturation est découplée de l'encaissement (G-1). Effet de bord favorable : la période comptable
  est encore ouverte au moment d'écrire, donc le refus de `DirectLedgerEntryBuilder` sur période close
  ne se produit pas, et la numérotation NF525 reste chronologique sans effort.

## Critères d'acceptation

- **G-1** : après le passage de la tâche, chaque échéance arrivée à terme porte **une** facture
  numérotée, scellée, avec son écriture au journal `FAC` équilibrée. La relancer n'en crée **aucune**
  de plus (témoin d'idempotence). Elle doit être vue **mordre** (une échéance due est facturée) **et
  épargner** (une échéance non encore due, et une déjà facturée, ne le sont pas) — un détecteur ne se
  prouve pas par ce qu'il attrape, mais par ce qu'il laisse passer. Et la génération de remise
  qui suit n'émet **rien** : elle encaisse contre les factures existantes.
- **G-1bis** : un produit sans taux de TVA fait **refuser** l'émission, avec un message nommant la
  formule ; un produit dont le taux résout vers un `TauxTva` unique du profil **passe**. Les deux cas
  sont testés — celui qui refuse ne prouve rien seul.
- **G-2** : après encaissement, une écriture au journal `ENC` existe, équilibrée, débit sur le compte
  de trésorerie du moyen et crédit sur `411` ; les deux lignes `411` (facture et encaissement)
  portent le **même** `reconciliationCode`. Le compte `411` du client revient à zéro.
- **G-3** : un encaissement par un moyen sans `compteTresorerie` est **refusé**, et le message nomme
  le moyen. Un test le vérifie en **le voyant refuser**, et un second vérifie qu'un moyen configuré
  **passe** — un contrôle trop large ne se démasque que par le cas qu'il doit autoriser.
- **G-4** : `POST …/resoudre` avec un corps portant `canal: "virement"` produit un incident dont
  `canal_resolution` vaut `virement`. Un test par canal de l'enum ; celui qui échouait avant (tous
  sauf `app_1_clic`) doit passer.
- **G-5** : résoudre un impayé né d'une échéance facturée crée un `ReglementFacture` sur **cette**
  facture, du bon montant, et la facture passe à `payee`. Résoudre un impayé **sans** pièce
  n'en crée aucun et l'écran le dit.
- **G-6** : l'adaptateur par défaut fait échouer la résolution par CB avec un message nommant
  l'absence de PSP. Témoin négatif : les autres canaux ne l'appellent pas et passent.
- **G-7** : sur le build **servi** (pas seulement construit), le chunk de la facture lit `reglements`
  et `montantRegle` ; le document rendu d'une facture soldée par 2 virements affiche la mention
  d'acquittement **avec** sa date et son moyen.
- **G-8** : la fiche d'un client ayant 1 facture affiche 1 ligne avec son solde ; celle d'un client
  sans facture affiche l'état vide nommé, pas un tableau muet.
