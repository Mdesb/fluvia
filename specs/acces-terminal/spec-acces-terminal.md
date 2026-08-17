# Spec — API Terminal d'accès & mode dégradé (`acces-terminal`, transverse `L3`)

- **Lot / module :** transverse au socle et à **L3 · Contrôle d'accès** — face « côté borne » du
  contrôle d'accès. Module technique `acces` (mêmes permissions), sous-domaine `terminal`.
- **Stories couvertes :** **US-TERM-01 à US-TERM-09** — ⚠ **définies par ce document, hors
  `backlog.html`** (aucune US-Lx ne couvre le contrat d'API borne ↔ backend ; seul le *comportement
  métier* qu'il expose est couvert par `US-L3-03/07/08/09/10`). À faire valider/ajouter au backlog.
- **Règles de gestion :** réutilise **RG-ACC-01, 02, 05, 06, 07** (source `cahier-detaille.html`,
  panel `p-acces`, déjà actées dans `specs/L3-acces/spec-acces.md`). Aucune RG nouvelle n'est créée
  ici (le contrat d'API terminal n'est pas dans le cahier) : tout ce qui est spécifique à ce document
  est tracé **⚠ HYPOTHÈSE** ou **décision proposée** (non actée, à valider avec IT Cotation).
- **Statut :** brouillon

## 1. Objectif
Spécifier le **contrat d'API** entre les bornes/tourniquets **IT Cotation** (concentrateur ITBOX,
tourniquets/tripodes iDTRONIC, lecteurs QR/RFID pilotés par SmartAccess) et notre backend, pour que
toute borne puisse : **(1)** faire valider un passage **en ligne** et afficher une réponse humaine
immédiate, **(2)** télécharger un **snapshot local** lui permettant de valider **seule** en cas de
coupure secteur/réseau, et **(3)** **remonter** au retour du réseau les passages effectués hors-ligne
pour que le backend les **réconcilie** (journalisation, décompte, détection de conflits) — sans jamais
exposer la clé de signature des supports à la borne. Ce document ne réécrit pas le moteur de décision
existant (`App\Acces\Service\ValidationPassageHandler`) : il **l'habille** d'un contrat d'échange
authentifié, normalisé et documenté pour le matériel.

## 2. Périmètre
- **Inclus :**
  - **Authentification/enrôlement d'une borne** (objet `Terminal` + `JetonTerminal`) et sa **portée**
    (établissement + portes/équipements).
  - **Validation en ligne d'un passage** via le moteur `ValidationPassageHandler` existant (RG-ACC-01/02) :
    contrat de requête/réponse, **message d'affichage normalisé** (codes + libellés), **infos
    d'affichage** (nom du porteur, n° de billet, compostages restants, validité d'abonnement).
  - **Snapshot local incrémental** (delta depuis une version/horodatage) et **snapshot complet**
    (bootstrap) : contenu, volumétrie, fraîcheur.
  - **Remontée du lot de passages hors-ligne** : idempotence, rejeu, **réconciliation** (décompte
    différé, FMI, conflits), **double-consommation** entre plusieurs bornes, **skew horloge**.
  - **Révocation d'une borne** (jeton compromis, matériel remplacé) — propagation immédiate côté API,
    effet sur les prochains appels (le snapshot déjà téléchargé reste local jusqu'à expiration/TTL).
  - Catalogue des **codes de message d'affichage** (succès et refus), sans logique métier côté borne.
- **Exclu (pour l'instant), référencé sans être redéfini :**
  - **Moteur de décision d'un passage** (marges, anti-passback, jauge FMI, sous-réseau/fédération) →
    `specs/L3-acces/spec-acces.md` §4.3-4.11, RG-ACC-01 à 07. Ce document **consomme** ce moteur.
  - **Modèle Support / DroitAcces / Appairage / BilletSupport / carte à quota (compostages)** → L3 et
    M2 (`spec-vente.md`). Réutilisés tels quels (§5).
  - **Format de signature du code de support** (HMAC) → `App\Vente\Service\GenerateurCodeSupport`
    (M2). Ce document impose seulement la **contrainte d'usage** côté borne (§4.1).
  - **Protocole physique/matériel bas niveau** (OSDP lecteur ↔ ITBOX, pilotage moteur du tourniquet,
    firmware) : hors périmètre logiciel, reste à IT Cotation. Ce document spécifie le contrat **IP/API
    entre l'ITBOX (ou le boîtier SmartAccess) et notre backend**, pas l'électronique du tourniquet.
  - **Parc matériel / inventaire des périphériques** (TPE, imprimantes, ITBOX rattachés à une caisse,
    cahier §M8-04) → **M8** (L7, non encore construit). Ce document **anticipe** un objet `Terminal`
    minimal nécessaire au contrôle d'accès ; convergence avec l'inventaire M8 à opérer au plan M8/L7
    (⚠ point ouvert, voir §8).
  - **UI de supervision** (écran A-03, statut réseau) → `spec-acces.md` §4.10, inchangée ; ce document
    lui fournit la donnée (état du `Terminal`, dernier appel).

## 3. Acteurs & droits
| Acteur | Peut | Ne peut pas | Permission (module × action) |
|---|---|---|---|
| **Terminal (borne/ITBOX authentifié)** | Envoyer un passage en ligne ; télécharger un snapshot (complet ou delta) restreint à sa portée ; remonter un lot de passages hors-ligne ; émettre un heartbeat | Agir hors de sa portée (établissement/portes non enrôlées) ; consulter/modifier autre chose que sa propre portée d'accès ; recalculer une signature (ne détient pas la clé HMAC) | `acces × ingestion` (déjà en usage côté code, `POST /acces/passages`), `acces × snapshot` *(nouveau, ⚠ à créer)* |
| **Administrateur (humain, socle M8/L3)** | Enrôler une borne (créer `Terminal` + premier `JetonTerminal`), consulter/révoquer un jeton, consulter l'état des bornes | Se substituer à une borne pour valider un passage en son nom sans passer par le contrat d'API | `acces × gerer` (réutilisée, `spec-acces.md` §3) |
| **Agent d'accueil / supervision** | Consulter l'état réseau/dernier appel d'une borne (écran A-03) | Enrôler/révoquer une borne | `acces × superviser` (réutilisée) |
| **Système (backend)** | Authentifier chaque requête entrante, exécuter `ValidationPassageHandler`, produire le snapshot, réconcilier un lot | Faire confiance à une signature ou un statut affirmé par la borne sans re-vérification serveur | *(acteur technique)* |

- **Décision structurante (à tracer, non actée par une source) — la borne comme acteur technique
  authentifié distinct d'un `Utilisateur` humain.** Le socle L0 authentifie aujourd'hui des humains
  (`Utilisateur` + JWT, `spec-socle.md` RG-SOCLE-02/06). Aucune source (cahier/backlog) ne décrit
  d'identité machine. Ce document introduit `Terminal`/`JetonTerminal` comme un **second type
  d'identité authentifiée**, distinct du modèle `Utilisateur`, mais réutilisant le **même modèle de
  permission `module × action`** (`RG-SOCLE-02`) pour rester cohérent avec le reste du socle.
- ⚠ **HYPOTHÈSE** — Les permissions `acces × ingestion` et `acces × snapshot` : `acces.ingestion` est
  **déjà utilisée dans le code** (`Passage::operations`, `SynchronisationAcces::operations`) mais
  n'apparaît pas dans le tableau Acteurs & droits de `spec-acces.md` — cette spec la **documente**
  officiellement comme la permission portée par un `Terminal` (pas par un agent humain).
  `acces.snapshot` est **nouvelle**, à créer/valider avec M8 (répertoire des permissions).

## 4. Comportements & règles

### 4.1 Enrôlement & authentification d'une borne (US-TERM-01, US-TERM-09)
- **Enrôlement** — Un administrateur habilité (`acces × gerer`) crée un `Terminal` : nom, **établissement**,
  **portée** (liste d'`Équipement`/portes, ou l'ensemble des équipements d'un `Contrôleur`/ITBOX — voir
  décision ci-dessous). La création génère un premier `JetonTerminal` (secret affiché **une seule fois**,
  jamais restitué en clair ensuite — même patron que la génération d'un mot de passe temporaire côté
  socle).
- **Décision proposée — granularité de la portée = l'ITBOX (le concentrateur), pas la porte unitaire.**
  Le cahier indique qu'**« un même ITBOX peut piloter plusieurs contrôleurs de sens différents »**
  (`spec-acces.md` §4.1, cahier A-01). Cette spec retient donc qu'**un `Terminal` correspond à un
  concentrateur ITBOX physique** et couvre nativement **tous les `Contrôleur`/`Équipement` partageant
  son `itboxRef`** au sein d'un même établissement — évite un jeton par tourniquet, cohérent avec le
  matériel réel. ⚠ **À confirmer avec IT Cotation** (§8, point ouvert « périmètre porte↔espace ») :
  alternative rejetée par défaut = un jeton par `Contrôleur`, plus fin mais plus lourd à opérer.
- **Authentification de chaque appel** — Chaque requête entrante (`/terminal/passages`,
  `/terminal/snapshot`, `/terminal/passages/lot`) porte le `JetonTerminal` (en-tête `Authorization:
  Bearer <secret>` ou équivalent — ⚠ format exact à cadrer avec IT Cotation, §8). Le backend résout le
  `Terminal`, vérifie qu'il est **actif** (non révoqué, non expiré) et que l'`equipementId`/les
  `equipementId` référencés dans la requête appartiennent à sa **portée** ; sinon **401** (jeton
  invalide/révoqué) ou **403** (hors portée).
- **Révocation** — Une révocation de `JetonTerminal` (perte, remplacement matériel, compromission) est
  **immédiate côté API** : tout appel ultérieur avec ce jeton est refusé (401). Un `Terminal` révoqué ne
  peut plus obtenir de nouveau snapshot ni faire valider un passage en ligne. **Cas limite explicite** :
  une borne **déjà en mode dégradé** au moment de la révocation continue de valider sur son **snapshot
  local déjà téléchargé** jusqu'à expiration du TTL (§4.2) — la révocation ne peut pas « couper le
  courant » à distance ; elle empêche seulement les **futurs** appels. Un support individuellement
  compromis reste couvert par le canal existant (liste de révocation embarquée, RG-ACC-07,
  `spec-acces.md` §4.7), indépendant de la révocation du terminal.
- **Rotation** — Un nouveau `JetonTerminal` peut être émis pour un `Terminal` existant (rotation planifiée) ;
  l'ancien est révoqué. ⚠ **HYPOTHÈSE** — période de grâce (chevauchement ancien/nouveau jeton) **non
  tranchée**, à cadrer avec IT Cotation selon la procédure de déploiement matériel.
- **Pas de clé de signature côté borne** — Un `Terminal` ne reçoit **jamais** la clé HMAC
  (`SUPPORT_HMAC_KEY`, `App\Vente\Service\GenerateurCodeSupport`). En ligne, il transmet le **code brut
  scanné** ; le backend seul vérifie la signature (`estCodeSigne()`/`verifier()`, déjà implémenté dans
  `ValidationPassageHandler` étape 1bis, `CodeMotifRefus::SignatureInvalide`). Hors ligne, la borne ne
  recalcule **jamais** de signature : elle compare l'identifiant scanné à la **liste snapshot**
  téléchargée (comparaison exacte de chaîne, pas de cryptographie côté borne) — §4.2.

### 4.2 Validation en ligne d'un passage (US-TERM-02 ; réutilise RG-ACC-01/02/06)
- **Endpoint** `POST /terminal/passages` (facade authentifiée-terminal du contrat existant
  `POST /acces/passages`/`ValidationPassageHandler` — même moteur, ne pas dupliquer la logique de
  décision). Requête minimale : `{ equipementId, identifiantSupport?, sens?, horodatageBorne,
  cleIdempotence }`. `identifiantSupport` est **le code brut lu** (QR/RFID), jamais transformé par la borne.
- **Résolution de portée** — `equipementId` doit appartenir à la portée du `Terminal` authentifié
  (§4.1) ; sinon refus **avant** exécution du moteur (pas de fuite d'information sur un équipement
  hors périmètre).
- **Réponse — deux couches distinctes, pour que la borne affiche sans logique métier :**
  1. **Résultat technique** (déjà produit par le moteur) : `resultat` (`valide`/`refuse`/`compte`),
     `codeMotif` (`CodeMotifRefus`, réutilisé tel quel — RG-ACC-01/02/07), `horodatageServeur`.
  2. **Message d'affichage normalisé** — `{ codeMessage, libelle }` où `codeMessage` est un **code
     stable du catalogue `MessageAffichage`** (§5) et `libelle` un texte prêt à afficher, **déjà
     traduit/formaté côté backend**. La borne **affiche `libelle` tel quel** (ou fait son propre
     rendu localisé à partir de `codeMessage`, selon capacité de l'équipement) — elle n'implémente
     **aucune règle** de correspondance motif→texte.
  3. **Infos d'affichage** (`affichage`) — objet optionnel contenant : `nomPorteur` (si connu, sinon
     absent — cf. RGPD/minimisation, ⚠ voir cas limite ci-dessous), `numeroBillet`, `typeSupport`,
     `compostagesRestants` (si `DroitAcces.sourceType = carte_quota`), `validiteAbonnement`
     (`{valide: bool, debut, fin}` si `abonnement`). Ces champs sont **dérivés strictement** de
     `DroitAcces`/`Support` déjà résolus par le moteur — aucune nouvelle donnée métier n'est créée ici.
- **Exemple — passage accepté :**
  `{ "resultat": "valide", "codeMotif": null, "message": { "codeMessage": "BONNE_SEANCE",
  "libelle": "Bonne séance !" }, "affichage": { "nomPorteur": "J. Dupont", "compostagesRestants": 6 } }`
- **Exemple — refus signature invalide :**
  `{ "resultat": "refuse", "codeMotif": "signature_invalide", "message": { "codeMessage":
  "CODE_INVALIDE", "libelle": "Code illisible, veuillez réessayer" }, "affichage": null }` — **aucune**
  distinction affichée entre « support inconnu » et « signature invalide » (déjà une règle du moteur,
  §RG-ACC-07, évite la fuite d'info) : le `codeMotif` technique reste distinct en base pour le journal,
  mais le **libellé affiché** peut être volontairement générique — ⚠ **HYPOTHÈSE** : la spec retient un
  libellé neutre par défaut pour ces deux motifs (`codeMotif` = `signature_invalide` ou premier segment
  de `droit_invalide` correspondant à « support inconnu ») ; **à valider avec IT Cotation/UX borne**.
- **Exemple — refus carte épuisée :**
  `{ "resultat": "refuse", "codeMotif": "credit_epuise", "message": { "codeMessage": "CARTE_EPUISEE",
  "libelle": "Carte épuisée — rechargez à la caisse, à la borne ou dans l'application" }, "affichage":
  { "nomPorteur": "J. Dupont", "compostagesRestants": 0 } }` — reprend `propositionRecharge`
  (déjà produit par `PassageIngestionProcessor`, US-L3-10) dans le libellé.
- **Performance** — Répond en **moins d'une seconde** en conditions nominales (RG-ACC-01, US-L3-03,
  déjà garanti par le moteur ; aucune latence additionnelle propre au contrat terminal — résolution du
  jeton/portée doit rester en mémoire/cache, pas d'appel réseau tiers).
- **Ouverture physique** — Sur `resultat = valide`, le backend pilote l'ouverture via `PiloteAcces`
  (déjà existant, `spec-acces.md` — protocole matériel non cadré, voir dépendances §8). Ce document ne
  redéfinit pas ce canal ; il documente uniquement que la **réponse HTTP** à `/terminal/passages` est
  distincte de l'éventuelle commande d'ouverture bas niveau.

### 4.3 Snapshot local — mode dégradé (US-TERM-03, US-TERM-04, US-TERM-05 ; réutilise RG-ACC-05/07)
- **But** — Permettre à une borne **privée de réseau** de valider seule, à partir d'une copie locale
  des données nécessaires (§ contexte métier ci-dessus, repris comme base normative de ce document).
- **Snapshot complet (bootstrap)** — `GET /terminal/snapshot` (sans paramètre `depuis`) renvoie
  l'**intégralité** des `EntreeSnapshotSupport` (§5) pour la portée du `Terminal` : tout support
  **actif et appairé à un droit valide** dont le droit est éligible aux portes de la borne (même
  établissement, sous-réseau compatible le cas échéant). Utilisé au premier démarrage d'une borne ou
  après une trop longue coupure (snapshot périmé, §7).
- **Snapshot incrémental (delta)** — `GET /terminal/snapshot?depuis=<curseur>` renvoie uniquement les
  `EntreeSnapshotSupport` **modifiées ou révoquées** depuis `<curseur>` (version monotone croissante,
  pas une simple date — évite les pertes en cas d'horodatages égaux/skew, cf. `ListeRevocation.version`
  déjà existant pour la liste de révocation). La réponse porte son **propre curseur** (`versionCourante`)
  que la borne conserve et renverra au prochain appel. Une entrée **révoquée** (support bloqué,
  appairage dévalidé, droit expiré) est renvoyée avec un indicateur `revoque = true` (tombstone) plutôt
  qu'omise, pour que la borne **purge** sa copie locale.
- **Fréquence d'interrogation** — ⚠ **HYPOTHÈSE (point ouvert prioritaire, §8)** — aucune source ne fixe
  la cadence. Proposition : **interrogation périodique courte en fonctionnement normal** (ex. toutes les
  30–60 s, configurable par établissement) pour rester proche du temps réel, complétée par un
  **snapshot complet de sécurité** à fréquence plus basse (ex. quotidien) pour rattraper toute dérive
  du delta. **À valider avec IT Cotation** (contrainte réseau/volumétrie du matériel).
- **TTL du snapshot / péremption** — ⚠ **HYPOTHÈSE (point ouvert prioritaire, §8)** — aucune source ne
  fixe la durée de validité d'un snapshot en cas de coupure prolongée. Proposition : chaque entrée porte
  un **horodatage de fraîcheur** ; passé un **TTL configurable par établissement** (ex. 24 h) sans
  rafraîchissement réseau, la borne **doit** soit (a) continuer à valider en dégradant l'information («
  snapshot périmé » affiché en supervision dès reconnexion, cf. cas limite §7), soit (b) basculer en
  mode « comptage seul sans décision » selon un paramètre à définir. **Retenu par défaut pour cette
  spec : option (a)** — continuité de service prioritaire (cohérent avec le principe « mode dégradé »
  de la constitution §4.6) — **à confirmer avec IT Cotation**.
- **Volumétrie — établissement à gros volume** — Le delta borne la charge au strict nécessaire (§ci-dessus).
  Pour le snapshot complet, la réponse est **paginée/chunkée** (curseur de pagination, taille de page
  configurable) — même esprit que le rejeu « par paquets » déjà acté pour la resynchro (`spec-acces.md`
  §4.6, décision actée). ⚠ **HYPOTHÈSE** — taille de page par défaut et nombre max d'entrées par borne
  **non fixés**, à dimensionner selon la capacité mémoire du matériel IT Cotation (§8).
- **Contenu volontairement minimal côté sécurité** — Le snapshot ne contient **jamais** la clé HMAC ni
  de quoi la reconstituer ; il contient l'**identifiant brut** du support (le code déjà scanné en
  caisse/vente) tel que produit par `GenerateurCodeSupport`, à comparer par égalité stricte. Un
  identifiant supprimé du snapshot (parce que révoqué/expiré) et absent de la liste locale est traité
  par la borne comme **inconnu** → refus, symétrique du comportement en ligne.
- **Validation autonome côté borne (comportement attendu, non implémenté par notre backend)** — Cette
  spec **documente le contrat de données** consommé par la borne pour reproduire *localement* une
  partie du moteur `ValidationPassageHandler` (existence du support, crédit restant > 0, fenêtre de
  validité, droits d'accès par porte, fenêtres horaires). L'**anti-passback strict** et la **jauge FMI
  exacte** en présence d'autres bornes **ne peuvent pas être garantis en local** (pas de vue globale
  temps réel) : ⚠ **HYPOTHÈSE** — la borne applique un anti-passback **local** (mémoire de ses propres
  scans récents) en mode dégradé, moins fiable que le mode en ligne ; **assumé et documenté** comme
  limite du mode dégradé (parallèle à la règle déjà actée `spec-acces.md` §4.6 « recalage FMI après
  synchro » — la jauge n'est donc **pas fiable pendant la coupure**, seulement recalée après coup).

### 4.4 Remontée post-coupure & réconciliation (US-TERM-06, US-TERM-07, US-TERM-08 ; réutilise RG-ACC-05/06/07)
- **Endpoint** `POST /terminal/passages/lot` — généralisation, au niveau `Terminal` (donc potentiellement
  plusieurs `Contrôleur`/`Équipement` d'un même ITBOX), du contrat déjà implémenté au niveau `Contrôleur`
  (`POST /acces/synchro`, `App\Acces\State\SynchroProcessor`/`SynchroPassageHandler` — moteur réutilisé
  sans réécriture). Corps : `{ terminal, lot: [ { identifiantSupport?, equipementId, sens?,
  horodatageBorne, cleIdempotence, resultatLocal?, codeMotifLocal? } ] }`. `resultatLocal`/`codeMotifLocal`
  sont **informatifs** (ce que la borne a décidé hors-ligne, pour audit/comparaison) — le backend
  **réévalue chaque passage en autorité** via `ValidationPassageHandler`, il ne se contente jamais de
  rejouer la décision locale telle quelle.
- **Idempotence** — Chaque `PassageHorsLigne` porte une `cleIdempotence` unique ; un **rejeu réseau**
  (retransmission du même lot après timeout) est **sans effet** : une clé déjà connue renvoie le même
  résultat qu'à la première réception, marquée `doublon` dans la réponse (déjà implémenté :
  `SynchroPassageHandler::synchroniser()`, tableau `doublons`). **CA dédié §6.**
- **Rejeu chronologique** — Les passages du lot sont **triés par `horodatageBorne`** avant rejeu (déjà
  implémenté), pour que le moteur applique crédits/FMI/anti-passback dans le **même ordre** que sur le
  terrain (US-L3-08, décision actée).
- **Réponse par passage** — `{ cleIdempotence, statut: accepte|rejete|doublon, codeMotif?, enConflit }`
  — chaque entrée du lot reçoit un statut individuel (pas de statut global du lot), pour que la borne
  (ou l'agent en supervision) sache précisément ce qui a été journalisé.
- **Horloge borne décalée (skew)** — Le backend fait foi (`horodatageServeur` = réception, utilisé pour
  tout calcul relatif au « maintenant » serveur), mais **conserve** `horodatageBorne` tel que transmis
  (champ dédié sur `Passage`/`PassageHorsLigne`, distinct de `horodatage` qui reste la valeur d'origine
  utilisée pour le rejeu chronologique — cohérent avec l'existant, `Passage.horodatage` est déjà
  l'horodatage d'origine transmis par l'événement). ⚠ **HYPOTHÈSE** — un **écart significatif**
  (ex. > 5 min, seuil à confirmer) entre `horodatageBorne` et `horodatageServeur` à réception est
  **signalé** (indicateur `ecartHorlogeSuspect` sur le lot/la borne, visible en supervision A-03) sans
  bloquer la réconciliation — l'écart ne doit **jamais** empêcher la journalisation d'un passage réel.
- **Double-consommation hors-ligne (deux bornes, même carte 10)** — Pendant une coupure, deux bornes
  isolées peuvent chacune valider **localement** un passage sur le même `DroitAcces` de type
  `carte_quota` alors que le crédit local affichait encore « > 0 » sur les deux copies snapshot. Au
  retour réseau, les deux lots arrivent et sont rejoués **chronologiquement** : le premier passage
  décompte normalement (`creditRestant` 3→2 par ex.) ; le second, rejoué avec le même moteur atomique
  (`UPDATE ... WHERE credit_restant > 0`), **ne trouve plus de crédit disponible**.
  - **Décision proposée (réconciliation gracieuse, à valider — reprend le principe demandé par IT
    Cotation « dépassement possible, compostages négatifs bornés + marquage litige », même esprit que
    le mode dégradé déjà acté ailleurs dans le projet) :** un passage hors-ligne **déjà survenu
    physiquement** (la personne est réellement passée, le tourniquet a tourné) **n'est jamais annulé
    rétroactivement**. Le rejeu **accepte** ce second passage (comme le fait déjà
    `SynchroPassageHandler` pour le cas « révocation postérieure », via
    `ignorerRevocationSiPosterieure`) mais :
    1. le crédit peut passer **négatif**, **borné** à un plancher configurable (⚠ HYPOTHÈSE : valeur du
       plancher **non fixée**, ex. `-1` ou `-nb_bornes_actives_pendant_la_coupure` — à trancher) ;
    2. le passage est marqué **`enConflit = true`** (champ déjà présent sur `Passage`, réutilisé) avec un
       **motif dédié** (⚠ nouveau `CodeMotifRefus`/statut à ajouter, ex. `credit_epuise_hors_ligne_litige`,
       *hors périmètre code de ce document — juste tracé ici comme besoin*) ;
    3. l'événement alimente un **litige** consultable (`JournalReconciliation`, §5) pour qu'un agent
       régularise (rechargement compensatoire, contact du porteur) — **aucune décision automatique
       d'encaissement** (hors périmètre L3, cf. `spec-acces.md` §2 exclusions M2).
  - ⚠ **HYPOTHÈSE — ceci diverge du comportement actuellement codé** dans
    `ValidationPassageHandler`/`SynchroPassageHandler`, qui **refuse purement et simplement**
    (`PassageRefuseException(CreditEpuise)`) un rejeu à crédit épuisé, y compris hors-ligne
    (`ignorerRevocationSiPosterieure` ne couvre que la révocation de support, pas l'épuisement de
    crédit). **Écart à trancher avec IT Cotation** avant tout plan technique : soit le comportement
    actuel (refus a posteriori, litige simplement « passage refusé au rejeu ») est **conservé** (moins
    fidèle à « la personne est réellement passée »), soit le comportement décrit ci-dessus (accepter +
    négatif borné + litige) est **implémenté** (nécessite une évolution du moteur, hors périmètre de
    cette spec de contrat d'API).
- **Volumétrie d'un lot différé** — Rejeu **par paquets** pour les gros lots (décision déjà actée en L3,
  `spec-acces.md` §4.6) ; taille de paquet **non fixée** (même point ouvert qu'en L3, §8).

### 4.5 Catalogue des messages d'affichage (US-TERM-02)
- Le backend expose un **catalogue fermé** de `codeMessage` → `libelle` par défaut (personnalisable par
  établissement, ⚠ HYPOTHÈSE — la personnalisation du libellé par établissement/langue n'est **pas
  demandée explicitement** par le contexte métier fourni ; retenue comme extension naturelle mais **non
  actée**). La borne n'a **jamais** à interpréter `codeMotif` pour en déduire un texte : elle consomme
  `message.libelle` directement, ou `message.codeMessage` si elle gère ses propres traductions/écrans.
- Table de correspondance (résultat → `codeMessage`), reprise du `CodeMotifRefus` existant (§5 pour le
  détail) :

| `resultat` | `codeMotif` (existant) | `codeMessage` (nouveau, ce document) | Libellé par défaut |
|---|---|---|---|
| valide | *(aucun)* | `BONNE_SEANCE` | « Bonne séance ! » |
| compte (non nominatif) | *(aucun)* | `PASSAGE_COMPTE` | « Passage enregistré » |
| refusé | `hors_marge` | `HORS_MARGE` | « Hors horaires autorisés » |
| refusé | `anti_passback` | `DEJA_PASSE` | « Déjà passé, veuillez patienter » |
| refusé | `credit_epuise` | `CARTE_EPUISEE` | « Carte épuisée — rechargez à la caisse, à la borne ou dans l'application » |
| refusé | `support_bloque` | `SUPPORT_BLOQUE` | « Support bloqué, présentez-vous à l'accueil » |
| refusé | `seuil_fmi` | `JAUGE_ATTEINTE` | « Capacité maximale atteinte » |
| refusé | `droit_invalide` | `DROIT_INVALIDE` | « Accès non valide » |
| refusé | `sens_interdit` | `SENS_INTERDIT` | « Sens non autorisé ici » |
| refusé | `federation_inactive` | `FEDERATION_INACTIVE` | « Accès non autorisé sur ce site » |
| refusé | `signature_invalide` | `CODE_INVALIDE` | « Code illisible, veuillez réessayer » |
- ⚠ **HYPOTHÈSE** — les libellés ci-dessus sont des **propositions** (non actées) ; à valider avec le
  métier/UX borne (ton, longueur maximale affichable sur l'écran du matériel IT Cotation — contrainte
  physique non documentée, §8).

## 5. Objets de données
Types indicatifs (spec = comportement observable). `Support`, `DroitAcces`, `Appairage`, `Passage`,
`Contrôleur`, `Équipement`, `ListeRevocation`, `EspaceAcces` sont **définis dans `spec-acces.md` §5 et
réutilisés sans redéfinition** ; `BilletSupport`/`GenerateurCodeSupport` sont définis côté M2/Vente.
Identifiants = UUID (constitution §3).

| Objet | Champ | Type | Contraintes | Notes |
|---|---|---|---|---|
| **Terminal** *(nouveau)* | id | uuid | PK | identité machine authentifiée (§3, §4.1) |
| | nom | string | requis | libellé opérationnel (ex. « ITBOX Entrée Sud ») |
| | itboxRef | string | requis, aligné `Controleur.itboxRef` | portée = tous les `Contrôleur`/`Équipement` partageant cette réf. (décision proposée §4.1) |
| | etablissement | ref Établissement | requis | cadrage socle (`RG-SOCLE-01/05`) |
| | statut | enum {actif, revoque, en_attente} | défaut = en_attente | passe à `actif` à la 1ʳᵉ authentification réussie |
| | dernierAppel | datetime? | — | dernier appel réussi (tout endpoint), alimente supervision A-03 |
| | dernierSnapshotVersion | int? | — | curseur delta le plus récent servi à ce terminal |
| **JetonTerminal** *(nouveau)* | id | uuid | PK | 1 `Terminal` peut avoir plusieurs jetons dans le temps (rotation) |
| | terminal | ref Terminal | requis | — |
| | secretHash | string | requis, jamais en clair après émission | même patron que hachage mot de passe (`RG-SOCLE-06`) |
| | dateEmission | datetime | requis | — |
| | dateExpiration | datetime? | optionnel | ⚠ politique d'expiration non tranchée (§8) |
| | statut | enum {actif, revoque} | défaut = actif | révocation immédiate (§4.1) |
| | revoqueLe, revoquePar | datetime?, ref Utilisateur? | — | traçabilité (parallèle `DeclarationPerteVol`) |
| **EntreeSnapshotSupport** *(nouveau, vue projetée — pas une entité de persistance propre : dérivée de `Support`+`Appairage`+`DroitAcces`)* | identifiant | string | = `Support.identifiant` (code brut) | jamais re-signé/transformé |
| | statut | enum {actif, revoque} | — | `revoque` = tombstone (support bloqué, appairage dévalidé, droit expiré) |
| | nomPorteur | string? | optionnel | affichage seul, ⚠ RGPD à borner (cas limite §7) |
| | numeroBillet | string? | — | affichage |
| | typeSupport | enum {QR, RFID, wallet} | = `Support.type` | — |
| | typeDroit | enum {billet, abonnement, carte_quota, personnel} | = `DroitAcces.sourceType` | pilote la validation locale |
| | compostagesRestants | int? | pour `carte_quota` | = `DroitAcces.creditRestant` au moment du snapshot |
| | validiteDebut, validiteFin | datetime?, datetime? | — | = `DroitAcces.fenetreDebut/Fin` |
| | portesEligibles | list<uuid> | — | `Équipement.id` couverts par ce droit (portée du terminal ∩ droit) |
| | fenetresHoraires | list<{debut,fin}>? | optionnel | ⚠ non détaillé par les sources (marges déjà gérées via validiteDebut/Fin + marges équipement, `spec-acces.md` §4.1) |
| | sousReseauId | uuid? | optionnel | = `DroitAcces.sousReseau` (US-L3-12) |
| | versionMaj | int | requis | curseur delta (§4.3) |
| **LotPassagesHorsLigne** *(nouveau, DTO d'échange — pas persisté tel quel)* | terminal | ref Terminal | requis | émetteur du lot |
| | passages | list\<PassageHorsLigne\> | — | ordonné par la borne, retrié serveur par `horodatageBorne` |
| **PassageHorsLigne** *(nouveau, DTO)* | identifiantSupport | string? | vide si non nominatif | — |
| | equipementId | uuid | requis, dans la portée du `Terminal` | — |
| | sens | enum {entree, sortie}? | — | déductible si équipement non bidirectionnel |
| | horodatageBorne | datetime | requis | conservé tel quel (skew, §4.4) |
| | cleIdempotence | uuid | requis, unique | anti-doublon (réutilise `Passage.cleIdempotence`) |
| | resultatLocal | enum {valide, refuse}? | informatif | décision prise par la borne hors-ligne, non contraignante |
| | codeMotifLocal | string? | informatif | motif local le cas échéant |
| **JournalReconciliation** *(nouveau, optionnel)* | id | uuid | PK | trace un **litige** de réconciliation (double-consommation §4.4) |
| | passage | ref Passage | requis | passage concerné (`enConflit = true`) |
| | droit | ref DroitAcces | requis | droit affecté (crédit négatif) |
| | ecart | int | ex. −1 | ampleur du dépassement |
| | statut | enum {ouvert, regularise, ignore} | défaut = ouvert | traitement par un agent (hors périmètre L3, cf. M2) |
| | horodatage | datetime | requis | — |
| **MessageAffichage** *(catalogue, nouveau)* | codeMessage | string | PK | catalogue fermé (§4.5) |
| | libelle | string | requis | texte par défaut, ⚠ personnalisation par établissement non actée |

## 6. Critères d'acceptation
- **CA-1 (US-TERM-01)** — *Étant donné* un `Terminal` non enrôlé, *quand* il appelle un endpoint
  terminal avec un jeton inconnu ou absent, *alors* la réponse est **401** ; *quand* un administrateur
  l'enrôle, *alors* un `JetonTerminal` est émis (secret affiché **une seule fois**) et le `Terminal`
  passe **actif**.
- **CA-2 (US-TERM-02, RG-ACC-01)** — *Étant donné* un support valide, en fenêtre, sans anti-passback,
  *quand* la borne poste `POST /terminal/passages`, *alors* la réponse porte `resultat = valide`,
  `message.codeMessage = BONNE_SEANCE` et les **infos d'affichage** disponibles (nom, compostages
  restants le cas échéant) — en **moins d'une seconde**.
- **CA-3 (US-TERM-02, RG-ACC-07)** — *Étant donné* un code dont le **format est signé mais la
  signature HMAC invalide** (forgé/altéré), *quand* la borne le transmet, *alors* la réponse porte
  `resultat = refuse`, `codeMotif = signature_invalide`, `message.codeMessage = CODE_INVALIDE`, **sans**
  que la borne n'ait eu à vérifier quoi que ce soit elle-même (elle ne détient pas la clé).
- **CA-4 (US-TERM-02, RG-ACC-02)** — *Étant donné* un `DroitAcces` de type `carte_quota` à
  `creditRestant = 0`, *quand* le porteur se présente en ligne, *alors* la réponse porte
  `codeMotif = credit_epuise`, `message.codeMessage = CARTE_EPUISEE` avec un libellé incluant la
  proposition de rechargement (caisse/borne/app), sans décompte.
- **CA-5 (US-TERM-03/04)** — *Étant donné* un `Terminal` actif sans `depuis`, *quand* il appelle
  `GET /terminal/snapshot`, *alors* il reçoit l'**ensemble** des `EntreeSnapshotSupport` de sa portée
  et un `versionCourante` ; *quand* il rappelle avec `depuis = versionCourante` sans changement
  entre-temps, *alors* il reçoit une liste **vide** (delta correct, pas de sur-transmission).
- **CA-6 (US-TERM-03)** — *Étant donné* un support **bloqué** (perte/vol, RG-ACC-07) après un premier
  snapshot, *quand* le `Terminal` rappelle `GET /terminal/snapshot?depuis=<curseur précédent>`,
  *alors* l'entrée correspondante est retournée avec `revoque = true` (tombstone), permettant à la
  borne de purger sa copie locale — cohérent avec le refus déjà garanti même hors-ligne (RG-ACC-07).
- **CA-7 (US-TERM-06)** — *Étant donné* un lot de passages hors-ligne valides, *quand* la borne poste
  `POST /terminal/passages/lot` au retour du réseau, *alors* chaque passage **décrémente** le crédit
  concerné et alimente le journal (RG-ACC-06) avec `origineHorsLigne = true` ; *quand* le **même lot**
  est reposté (rejeu réseau), *alors* chaque entrée est renvoyée avec `statut = doublon` **sans**
  second décompte ni second passage journalisé (idempotence par `cleIdempotence`).
  - **Traduction concrète** : ce comportement est **déjà couvert par le code existant**
    (`SynchroPassageHandler::synchroniser()`, `PassageIngestionProcessor`) — cette CA formalise le
    contrat côté terminal, ne réclame pas de nouveau moteur.
- **CA-8 (US-TERM-08)** — *Étant donné* deux bornes isolées ayant chacune validé localement un passage
  sur le **même** `DroitAcces` `carte_quota` à `creditRestant = 1` pendant une coupure, *quand* les
  deux lots sont réconciliés au retour du réseau (rejeu chronologique), *alors* le **premier** passage
  (par `horodatageBorne`) décompte normalement (`creditRestant` 1→0) et le **second** est **accepté
  malgré le dépassement** selon la politique de réconciliation gracieuse décrite §4.4 — *alors*
  `creditRestant` est **négatif borné**, le passage est marqué `enConflit = true` et une entrée
  `JournalReconciliation` (statut `ouvert`) est créée. ⚠ **CA conditionnelle à la décision proposée
  §4.4** (écart avec le comportement actuellement codé qui refuse purement le second passage — à
  trancher avant implémentation).
- **CA-9 (US-TERM-07)** — *Étant donné* un lot hors-ligne réconcilié, *alors* la **jauge FMI** de
  l'espace concerné est **recalée** en fin de rejeu sur l'état réel (réutilise `RecalageFmiHandler`,
  US-L3-08) — cohérent avec `spec-acces.md` CA-9.
- **CA-10 (§4.4, skew horloge)** — *Étant donné* un `PassageHorsLigne` dont `horodatageBorne` diffère de
  plus de 5 minutes (⚠ seuil à confirmer) de l'heure serveur à réception, *alors* le passage est **tout
  de même journalisé** (jamais bloqué pour ce seul motif) et un indicateur `ecartHorlogeSuspect` est
  exposé en supervision.
- **CA-11 (§4.1, borne inconnue/révoquée)** — *Étant donné* un `JetonTerminal` révoqué, *quand* la
  borne tente un appel (validation, snapshot ou remontée), *alors* la réponse est **401** et **aucune**
  donnée n'est transmise, **quel que soit** l'endpoint.

## 7. Cas limites
- **Snapshot périmé (coupure > TTL)** — Comportement retenu par défaut : continuité de service (option
  a, §4.3) — la borne continue de valider sur données périmées, un indicateur « snapshot périmé depuis
  X » remonte en supervision dès la reconnexion. ⚠ TTL exact et politique alternative **non tranchés**.
- **Code legacy non signé (RFID historique/démonstration)** — `estCodeSigne()` renvoie `false` : le
  code est traité comme un identifiant **non signé**, résolu directement en base/snapshot par égalité
  exacte, **sans** exiger de signature (rétrocompatibilité totale, déjà actée en L3 §4.3). Le snapshot
  contient ces identifiants au même titre que les codes signés.
- **Borne inconnue** — Jeton absent/mal formé → 401 générique, pas de distinction « jeton inexistant »
  vs « jeton expiré » dans la réponse (évite la fuite d'information, même logique que
  `signature_invalide` vs support inconnu, §4.2).
- **Borne révoquée en cours de coupure** — Continue de valider sur son dernier snapshot jusqu'à TTL
  (§4.1) ; au retour réseau, sa **remontée de lot est toujours acceptée** (les passages ont eu lieu
  réellement) mais **aucun nouveau snapshot** ne lui sera plus servi — ⚠ à confirmer : faut-il bloquer
  aussi la remontée d'un terminal révoqué (risque de couper la traçabilité de passages réels) ? Cette
  spec retient **non** (la remontée reste acceptée) par défaut ; **à valider avec IT Cotation**.
- **Double-consommation au-delà de deux bornes** — Le plancher borné (§4.4) suppose un nombre limité de
  bornes isolées simultanément ; ⚠ dimensionnement du plancher **non fixé** (§8).
- **Nom du porteur en affichage (RGPD/minimisation)** — ⚠ **HYPOTHÈSE** — l'affichage du nom complet du
  porteur sur un écran public (borne en libre-service) peut être **excessif au regard de la
  minimisation RGPD** (constitution §4.5) ; à arbitrer (ex. prénom + initiale, ou nom complet seulement
  sur contrôle mobile agent, jamais sur tourniquet public) — **non tranché par le contexte métier
  fourni**, à confirmer avec IT Cotation/DPO.
- **Volumétrie snapshot / lot** — Tailles de page et de paquet **non fixées** (§4.3, §4.4), à
  dimensionner selon la capacité mémoire réelle du matériel ITBOX/iDTRONIC.
- **Format d'échange non cadré** — Cette spec suppose un contrat **HTTP/JSON** (cohérent avec
  l'API-first de la constitution §4.1 et le code existant `POST /acces/passages`,
  `POST /acces/synchro`) mais **aucune source ne confirme** que le matériel IT Cotation supporte ce
  format nativement (vs un protocole propriétaire ITBOX/SmartAccess) — **point ouvert prioritaire**,
  identique au point ouvert n°1 de `spec-acces.md` (§8).

## 8. Dépendances
- **Dépend de : `specs/L3-acces/spec-acces.md`** — moteur `ValidationPassageHandler` (RG-ACC-01/02),
  liste de révocation embarquée (RG-ACC-05/07), rejeu chronologique et recalage FMI (US-L3-08),
  catalogue `CodeMotifRefus`, modèle `Support`/`DroitAcces`/`Appairage`/`Passage`/`Contrôleur`/`Équipement`
  — **réutilisés sans redéfinition**. Ce document **prolonge** ces objets par le contrat d'API terminal,
  il ne les remplace pas.
- **Dépend de : M2 · Vente & Caisse** (`spec-vente.md`) — `App\Vente\Service\GenerateurCodeSupport`
  (format et vérification du code signé, clé HMAC jamais exposée) ; `BilletSupport.nbCompostages`
  (source du compteur de compostages projeté dans `DroitAcces.creditRestant`/`EntreeSnapshotSupport`).
- **Dépend de : socle L0** (`spec-socle.md`) — modèle de permission `module × action` (RG-SOCLE-02),
  rattachement établissement (RG-SOCLE-01/05) réutilisés pour `Terminal` ; le hachage du secret
  (`JetonTerminal.secretHash`) suit le même principe que `RG-SOCLE-06`.
- **Converge avec (à cadrer, non construit) : M8 · Admin & Droits** (L7, parc matériel §M8-04 du
  cahier) — l'inventaire des périphériques (TPE, imprimantes, ITBOX) y sera probablement porté ;
  `Terminal` tel que défini ici est un **objet minimal anticipé** pour ne pas bloquer L3, à
  **réconcilier** avec l'inventaire M8 quand L7 sera construit (⚠ point ouvert).
- **Alimente (hors périmètre) :** M6 (compta, RG-ACC-06 via le journal `Passage` inchangé), M7
  (reporting, idem) — aucun changement de contrat pour ces modules, le contrat terminal ne fait que
  peupler `Passage` par un chemin authentifié différent (terminal vs agent humain).
- **Interagit avec le matériel IT Cotation** : concentrateur ITBOX, tourniquets/tripodes iDTRONIC,
  lecteurs QR/RFID, logiciel SmartAccess — protocole physique/bas niveau hors périmètre (§2).

---

## Points ouverts / hypothèses (récapitulatif — à trancher avec IT Cotation en priorité)
1. **⚠ Format d'échange** — HTTP/JSON supposé (cohérent avec l'API-first du socle et le code existant),
   **non confirmé** par IT Cotation pour le matériel ITBOX/SmartAccess (§7, §8 — même point ouvert n°1
   que `spec-acces.md`).
2. **⚠ Fréquence de snapshot & TTL de péremption** — cadence d'interrogation du delta (proposition
   30–60 s + snapshot complet quotidien) et durée de validité d'un snapshot en coupure prolongée
   (proposition 24 h, continuité de service par défaut) — **propositions non actées** (§4.3).
3. **⚠ Authentification borne** — format exact du jeton (Bearer statique vs JWT à courte durée de vie),
   granularité de portée retenue par défaut = **l'ITBOX** (pas la porte unitaire) — **à confirmer**
   (§4.1, décision proposée).
4. **⚠ Politique de dépassement des compostages (double-consommation)** — la spec propose une
   réconciliation gracieuse (crédit négatif borné + litige tracé) qui **diverge du comportement
   actuellement codé** (refus pur au rejeu) : écart à trancher avant toute évolution du moteur (§4.4,
   CA-8).
5. **⚠ Volumétrie** — taille de page du snapshot complet, taille de paquet du lot hors-ligne, plancher
   du crédit négatif — non chiffrés (§4.3, §4.4, §7).
6. **⚠ Affichage du nom du porteur** — risque de sur-exposition RGPD sur un écran public, arbitrage
   nom complet / initiale / réservé au contrôle mobile agent — non tranché (§7).
7. **⚠ Convergence avec M8 (parc matériel, L7 non construit)** — `Terminal` est un objet minimal
   anticipé pour L3 ; à réconcilier avec l'inventaire périphériques M8 quand ce lot sera construit (§8).
8. **⚠ Personnalisation du catalogue de messages** — par établissement/langue : extension non actée,
   proposée par cohérence produit (§4.5).
