# Spec — ACC-1 : échec explicite sur opération de pilote non déclarée + restitution des capacités (`D17`)

- **Lot / module :** L3 · Accès (`App\Acces`) — suite directe d'ACC-0 (`PiloteAcces`/`AccessDriverCapabilities`, déjà livré)
- **Stories couvertes :** aucune `US-Lx-nn` dédiée au backlog (`backlog.html`) — ACC-1 découle de la
  décision d'architecture **D17** (`COORDINATION/DECISIONS.md`), qui en énonce littéralement l'exigence
  dans le docblock d'`AccessDriverCapabilities` (lignes 23-25) : *« une opération non déclarée échoue
  explicitement (ACC-1), elle n'est jamais ignorée en silence »*. S'appuie sur/étend :
  **US-L3-06** (Supervision temps réel, écran A-03, `Réf : Écran « Supervision live »`) pour le volet
  restitution, et **US-L3-09** (Support perdu/volé : blocage et propagation) pour le call site mort de
  `pousserListeRevocation` (cf. §4).
- **Règles de gestion :** RG-ACC-05 (bascule online/offline), RG-ACC-07 (révocation/liste embarquée) —
  non redéfinies — + `RG-ACC1-01` à `RG-ACC1-10` (nouvelles, ce lot).
- **Statut :** brouillon

## 1. Objectif
Que la plateforme ne puisse **jamais** croire avoir exécuté une opération de contrôle d'accès
(ouvrir, remonter un passage, prendre le pouls d'un contrôleur, pousser une révocation) qu'un pilote ne
sait en réalité pas faire. Deux volets indissociables :
1. **Échec explicite** — une opération appelée alors que `capabilities()` ne la déclare pas lève une
   exception dédiée, tracée à l'audit, jamais un no-op ni un retour silencieusement ignoré.
2. **Restitution** — l'exploitant voit, dans l'interface, ce que le contrôle d'accès de son
   établissement sait réellement faire (révocation immédiate/différée/impossible, encodage, remontée des
   passages) — parce que D17 le nomme explicitement *« une promesse commerciale, pas un détail
   technique »*.

Le défaut que ce lot corrige est nommé sans détour dans D17 : *« on croit avoir révoqué un accès, la
porte s'ouvre quand même, et la découverte se fait sur incident »*. C'est un sujet de sûreté du contrôle
d'accès, pas d'ergonomie.

## 2. Périmètre
- **Inclus :**
  - Le mécanisme qui transforme un appel à une opération non couverte par `capabilities()` en échec
    explicite, pour les quatre opérations de `PiloteAcces` : `ouvrir`, `recevoirEvenement`, `heartbeat`,
    `pousserListeRevocation`.
  - L'exception dédiée et sa capture/traçabilité (audit `App\Audit`).
  - La décision de conception « où poser la garde » (décorateur vs call-site), argumentée et tranchée.
  - La ressource de lecture qui restitue les capacités à l'exploitant, cloisonnée par établissement (D3).
  - Les clés i18n `acces.capability.*` (pas de libellé en dur), en cohérence avec
    `COORDINATION/CONTRACT/i18n-traduction.md`.
  - L'opportunité (ou non) d'un événement de domaine `access.capability_gap_detected`.
- **Exclu (pour l'instant) :**
  - **ACC-2** (port d'encodage — écrire un droit sur un médium) : hors périmètre, seul le **mapping**
    `encoder → encodes()` est esquissé ici pour anticiper la cohérence du mécanisme (§4, RG-ACC1-02),
    sans qu'aucune opération d'encodage n'existe encore dans le code.
  - **L'intégration matérielle réelle Itbox/SmartAccess** : `ItboxAdapter`/`SmartAccessAdapter` restent
    des squelettes `unspecified()` qui lèvent déjà (§3). Bloqueur externe **E-4**
    (`COORDINATION/BLOQUEURS-EXTERNES.md`), D19 — on ne développe pas en l'attendant, et ce lot ne le
    lève pas : il rend le squelette existant **conforme au contrat** (échec explicite + traçable), pas
    fonctionnel.
  - **Un registre multi-pilotes par contrôleur/établissement.** Aujourd'hui `PiloteAcces` est un
    **alias DI unique pour toute la plateforme** (`app/config/services.yaml:57`,
    `App\Acces\Port\PiloteAcces: '@App\Acces\Adapter\SimulateurAccesAdapter'`) : un seul pilote sert
    tous les établissements et tous les contrôleurs. Ce lot restitue donc **les capacités du pilote
    actif de la plateforme**, pas des capacités qui varieraient par `Controleur`/`Equipement` — cf.
    décision ouverte D-3 (§9) sur la granularité future.
  - Le déclenchement effectif de `pousserListeRevocation` (aujourd'hui non appelé, §4) : ce lot protège
    l'opération pour le jour où elle sera câblée, il ne câble pas `BlocageSupportHandler` dessus.

## 3. État actuel (code réel)

**Ce qui existe déjà (ACC-0, livré) et que ce lot ne refait pas :**
- `App\Acces\Port\PiloteAcces` (`app/src/Acces/Port/PiloteAcces.php`) expose les 4 opérations +
  `capabilities(): AccessDriverCapabilities`.
- `App\Acces\Port\AccessDriverCapabilities` porte les 4 axes (`DecisionPoint`, `RevocationCapability`,
  `CredentialEncoding`, `PassageReporting`) + les helpers `revokesImmediately()`,
  `acceptsRevocationList()`, `encodes()`, `reportsState()`, et `unspecified()` (pessimiste).
- `ItboxAdapter::capabilities()` et `SmartAccessAdapter::capabilities()` retournent déjà
  `AccessDriverCapabilities::unspecified()` — et **les quatre méthodes de chacun lèvent déjà** une
  `\RuntimeException` avec un message explicite (« protocole non cadré (à confirmer avec IT Cotation) »),
  **indépendamment** de ce que dit `capabilities()`. Autrement dit : pour ces deux adaptateurs,
  l'échec explicite existe déjà, mais il est **codé en dur méthode par méthode**, pas **dérivé** de la
  déclaration de capacités — rien ne garantit qu'un futur troisième champ d'`ItboxAdapter` (ex. un
  adaptateur partiellement cadré, qui saurait `ouvrir` mais pas `pousserListeRevocation`) resterait
  cohérent sans qu'un développeur pense à répercuter le `throw` à la main dans chaque méthode. C'est
  précisément le risque que la garde centralisée de ce lot élimine (§5, RG-ACC1-01/03).
- `SimulateurAccesAdapter` déclare des capacités réelles (`Server`, `Immediate`, `None`, `RealTime`) et
  implémente les 4 opérations sans lever — comportement correct, rien à corriger ici.

**Ce que fait le code AUJOURD'HUI face à une opération non déclarée — les trois modes de défaillance
distincts trouvés en lisant les call sites :**

1. **`ValidationPassageHandler::valider()`, ligne 269** —
   `$this->pilote->ouvrir($equipement, new OuvertureContexte());` — **le retour `ResultatCommande` est
   entièrement ignoré**, y compris son booléen `succes`. Avec le Simulateur (`ResultatCommande::ok(...)`
   toujours), aucun symptôme aujourd'hui. Mais si un futur pilote retournait
   `ResultatCommande::echec('...')` au lieu de lever (une commande matérielle qui échoue proprement,
   sans exception, est un cas légitime — timeout réseau, contrôleur qui répond « refusé »), **le
   `Passage` est déjà persisté en `Valide` avant cet appel** (ligne ~250-263, dans la transaction) : la
   plateforme enregistrerait un passage accepté alors que la commande d'ouverture matérielle a
   explicitement échoué. C'est un **silence sur échec fonctionnel**, distinct du silence sur capacité
   non déclarée, mais du même ordre de gravité — à documenter en cas limite (§8) même s'il n'est pas
   l'objet premier de ce lot.
2. **`OuvertureManuelleHandler::ouvrir()`, ligne 58** — même patron : le `ResultatCommande` de
   `$this->pilote->ouvrir(...)` est ignoré, et le `Passage` (résultat `Valide`, `codeMotif =
   OuvertureManuelle`) est déjà persisté juste avant (lignes 55-56). Avec `ItboxAdapter`/
   `SmartAccessAdapter` actifs, `ouvrir()` **lève** (pas de silence — l'exception remonte, non
   catchée, en 500) ; mais rien n'empêche un futur adaptateur de renvoyer un échec sans lever, avec le
   même effet de bord qu'au point 1.
3. **`EtatReseauHandler::pulser()`, ligne 28** — `$etat = $this->pilote->heartbeat($controleur);` —
   **aucun `try/catch`**. Avec `ItboxAdapter`/`SmartAccessAdapter`, l'exception `\RuntimeException`
   remonte telle quelle jusqu'à l'appelant HTTP (probable 500 non qualifié, message technique brut
   exposé, aucune entrée d'audit, aucune indication à l'exploitant que « ce contrôleur ne sait pas
   remonter son état »). `verifierExpiration()` (même fichier, lignes 36-52), à l'inverse, ne touche
   jamais `PiloteAcces` — c'est un recalcul purement local sur l'ancienneté déjà stockée, non concerné.
4. **`pousserListeRevocation` — code mort côté appelant.** Recherche exhaustive
   (`grep pousserListeRevocation app/src`) : la méthode n'est référencée **que** dans le port et les
   3 adaptateurs — **aucun call site applicatif ne l'invoque**. `BlocageSupportHandler::bloquer()`
   (`app/src/Acces/Service/BlocageSupportHandler.php`, méthode privée `propagerRevocation()`, lignes
   69-86) construit et persiste des entités `ListeRevocation` versionnées par contrôleur (source de
   vérité en base, consommée par les bornes hors-ligne à la synchronisation), **sans jamais appeler**
   `$pilote->pousserListeRevocation()`. La propagation actuelle est donc **entièrement en pull**
   (le contrôleur/la borne relit la liste à la synchro, `RG-ACC-07`) ; le **push** que le port promet
   n'est câblé nulle part. Conséquence pour ce lot : la garde d'échec explicite sur
   `pousserListeRevocation` protège une opération **actuellement inatteignable en production** — elle
   sécurise le jour où un pilote « push-capable » (topologie `Server`/temps réel) sera câblé dessus, pas
   un chemin exploité aujourd'hui. À signaler explicitement plutôt qu'à laisser croire que le risque est
   déjà couvert en pratique (§9, risques).

**Synthèse du défaut D17 tel qu'il existe réellement dans le code, au 2026-08-23 :** aucun silence pur
n'existe *aujourd'hui* sur les 3 opérations effectivement appelées (`ouvrir` ×2, `heartbeat` ×1) — les
deux squelettes lèvent. Le risque est **latent, pas actif** : (a) rien ne **dérive** ce `throw` de
`capabilities()`, donc rien ne garantit la cohérence le jour où un adaptateur réel déclare des capacités
partielles plutôt que `unspecified()` global ; (b) le retour `ResultatCommande::echec()` — le canal
« échec propre, sans exception » que le DTO existe pour porter — est ignoré aux deux call sites
`ouvrir()`, un silence différent mais réel ; (c) `heartbeat()` n'a aucune capture, donc aucune traçabilité
et un 500 brut si jamais un pilote non-`unspecified()` mais ne déclarant pas `reportsState()` était
introduit. Ce lot ferme les trois.

## 4. Acteurs & droits
| Acteur | Peut | Permission (module × action) |
|---|---|---|
| Système (tout call site `PiloteAcces`) | Voit son appel échouer explicitement si la capacité requise n'est pas déclarée ; l'échec est tracé | — |
| Agent d'exploitation / support (consultation) | Consulte le journal d'audit pour diagnostiquer une tentative bloquée | `securite.gerer` ou `securite.lire` (existant, `EntreeAudit`) |
| Exploitant / administrateur d'établissement | Consulte les capacités déclarées du pilote actif de son établissement (révoque immédiat/différé/impossible ? encode ? remonte les passages ?) | **nouvelle** `acces.superviser` (réutilisée — déjà portée par `GET /acces/supervision`, US-L3-06) ou `acces.lire` (à trancher §9) |

Aucune nouvelle permission d'écriture. La restitution est strictement en lecture.

## 5. Comportements & règles

### Volet 1 — échec explicite

- **RG-ACC1-01 — Mapping opération → capacité requise.** Chaque opération de `PiloteAcces` est associée
  à exactement une condition de capacité, évaluée sur le résultat de `capabilities()` du pilote appelé :

  | Opération | Condition requise (`AccessDriverCapabilities`) | Vrai aujourd'hui pour |
  |---|---|---|
  | `ouvrir()` | `decisionPoint !== null` — **toujours tenable** : quel que soit `DecisionPoint`, un pilote qui existe sait par construction commander une ouverture (c'est son rôle minimal). Pas de garde de capacité sur `ouvrir()` — cf. ⚠ HYPOTHÈSE ci-dessous. | Tous |
  | `recevoirEvenement()` | `passageReporting !== PassageReporting::None` (i.e. `reportsState()`) | Simulateur uniquement |
  | `heartbeat()` | `passageReporting !== PassageReporting::None` (i.e. `reportsState()`) | Simulateur uniquement |
  | `pousserListeRevocation()` | `revocation !== RevocationCapability::Impossible` (i.e. `acceptsRevocationList()`) | Simulateur uniquement |
  | *(hors périmètre, ACC-2)* `encoder()` — n'existe pas encore | `encoding === CredentialEncoding::Writes` (i.e. `encodes()`) | Aucun |

  ⚠ HYPOTHÈSE : `ouvrir()` n'a pas de garde de capacité dédiée, parce qu'`AccessDriverCapabilities` n'a
  aucun axe qui exprime « ce pilote ne sait pas ouvrir » — les 4 axes déclarés par D17 couvrent décision,
  révocation, encodage, remontée, pas l'ouverture elle-même (jugée universelle : un pilote qui ne sait
  pas ouvrir n'est pas un pilote d'accès). Les squelettes `ItboxAdapter`/`SmartAccessAdapter` lèvent sur
  `ouvrir()` non pas par manque de capacité déclarée mais par **protocole non cadré** (E-4) — un autre
  motif d'échec, hors du mécanisme de garde par capacité de ce lot (§7, cas limite). Si un axe
  `OpeningCapability` (analogue aux 3 autres) s'avérait nécessaire plus tard (ex. un pilote purement
  lecteur d'événements, sans capacité de commande), il s'ajouterait à `AccessDriverCapabilities` en
  cohérence avec D17 — non introduit ici faute de besoin identifié dans le code actuel.

- **RG-ACC1-02 — Échec explicite, jamais un no-op.** Quand la condition de RG-ACC1-01 n'est pas
  satisfaite, l'opération **lève** une exception dédiée `CapaciteNonDeclareeException` (ou nom anglais
  retenu, cf. D5 et §9) **avant** toute exécution de la logique métier de l'adaptateur — jamais un retour
  silencieux, jamais un `ResultatCommande::echec()` masqué en `succes = true`, jamais une valeur par
  défaut construite pour « faire passer » l'appelant. L'exception porte : l'opération demandée
  (`heartbeat`, `pousserListeRevocation`, `recevoirEvenement`), le nom de l'adaptateur concerné (classe),
  et la capacité manquante (valeur d'enum). Cette exception se distingue de l'exception métier «
  protocole non cadré » d'`ItboxAdapter`/`SmartAccessAdapter` (§3) : celle-ci reste légitime et n'est pas
  remplacée — les deux causes (capacité non déclarée / protocole non cadré) peuvent coexister chez un
  même adaptateur.

- **RG-ACC1-03 — Où poser la garde : décorateur, centralisé.** La garde est portée par un
  **décorateur** `CapabilityGuardedPiloteAcces implements PiloteAcces` qui enveloppe l'adaptateur réel
  (composition, pas héritage), vérifie la condition RG-ACC1-01 avant de déléguer, et **c'est lui qui est
  câblé sur l'alias DI `App\Acces\Port\PiloteAcces`** (`app/config/services.yaml:57`) — l'adaptateur réel
  (`SimulateurAccesAdapter`/`ItboxAdapter`/`SmartAccessAdapter`) devient un service interne, injecté
  *dans* le décorateur. Argumentation, cf. §9 décision D-1 (recommandation tranchée) : centralise le
  mapping opération→capacité en un seul endroit testable, ne peut pas être « oublié » à un futur 4ᵉ call
  site, et ne modifie aucun des 3 adaptateurs existants ni leurs call sites (`ValidationPassageHandler`,
  `OuvertureManuelleHandler`, `EtatReseauHandler` continuent d'injecter `PiloteAcces` sans savoir qu'un
  décorateur existe).

- **RG-ACC1-04 — Traçabilité obligatoire (audit).** Toute exception `CapaciteNonDeclareeException`
  levée par le décorateur déclenche, **avant** de la laisser remonter, une écriture dans
  `App\Audit\Service\JournalAudit` (même patron que `CloturerSessionProcessor` §194-200) :
  action `access.capability_gap_detected` (ou équivalent retenu, D5), cible = le `Controleur` concerné
  (`cibleType = 'Controleur'`, `cibleId` = son id) quand l'opération en porte un
  (`heartbeat`/`pousserListeRevocation`), établissement dérivé de l'entité cible — jamais du contexte
  HTTP (D3/D8, cf. RG-ACC1-06). Le journal reste consultable même si l'exception a fait échouer la
  requête HTTP appelante (le décorateur doit persister l'entrée d'audit **avant** de lever, pas dans un
  `finally` qui pourrait être annulé par un rollback de la transaction appelante — ⚠ HYPOTHÈSE à trancher
  au plan : `JournalAudit::enregistrer()` ne flush pas lui-même, cf. son docblock — le décorateur doit
  soit flush immédiatement l'entrée d'audit dans sa propre unité de travail, soit s'assurer qu'elle
  survit à un rollback amont).

- **RG-ACC1-05 — Non-régression sur les 3 call sites existants.** `ValidationPassageHandler::ouvrir()`,
  `OuvertureManuelleHandler::ouvrir()`, `EtatReseauHandler::heartbeat()` continuent de fonctionner
  **sans modification** avec le Simulateur (capacités réelles, jamais d'échec). Avec
  `ItboxAdapter`/`SmartAccessAdapter` actifs (non le cas par défaut, §3 `services.yaml`), le
  comportement observable reste « échec », mais désormais **de nature homogène**
  (`CapaciteNonDeclareeException` pour `heartbeat`, exception « protocole non cadré » pour `ouvrir` faute
  d'axe de capacité dédié — RG-ACC1-01) et **tracé** pour `heartbeat` (nouveauté, §3 point 3), là où
  aujourd'hui il n'y a aucune trace.

### Volet 2 — restitution à l'exploitant

- **RG-ACC1-06 — Cloisonnement (D3/D8).** Les capacités exposées sont dérivées du pilote actif de la
  plateforme (§2, limite architecturale actuelle : un seul alias DI) mais la **lecture** est bornée à
  l'établissement de la session serveur exactement comme `SupervisionProvider`
  (`ContexteEtablissement::etablissementActif()`, jamais un identifiant fourni par le client). Un
  exploitant de l'établissement B ne doit voir ni les contrôleurs de A, ni — si RG-ACC1-10 (granularité
  par contrôleur) est retenue plus tard — les capacités d'un pilote propre à A.

- **RG-ACC1-07 — Ce qui est restitué.** Pour chaque contrôleur visible par l'établissement actif (même
  périmètre que `SupervisionProvider::provide()`, `Controleur` filtré par `etablissement`), la ressource
  expose au minimum :
  - `revocation` : `immediate` | `deferred` | `impossible` (jamais un libellé en dur — clé i18n
    `acces.capability.revocation.<valeur>`) ;
  - `revokesImmediately` (bool, dérivé) — pour l'affichage direct « révocation immédiate : oui/non » sans
    recalcul côté front ;
  - `decisionPoint` : `server` | `controller` | `credential` (clé i18n `acces.capability.decision_point.<valeur>`) ;
  - `encodes` (bool) ;
  - `passageReporting` : `real_time` | `on_sync` | `none` (clé i18n `acces.capability.passage_reporting.<valeur>`).
  Aucun champ technique brut (nom de classe d'adaptateur, message d'exception) n'est exposé à ce niveau —
  ce sont des données d'exploitation opérationnelle (`EntreeAudit`), pas de restitution commerciale.

- **RG-ACC1-08 — Libellés via i18n, jamais en dur.** Toute traduction affichée à l'exploitant
  (« révocation immédiate », « ce site ne sait pas révoquer à distance », etc.) passe par une clé
  `acces.capability.*` résolue côté i18n (`COORDINATION/CONTRACT/i18n-traduction.md`), pas une chaîne
  française codée dans le back ni dans le front. ⚠ HYPOTHÈSE : le service `App\I18n` de résolution
  runtime est encore *« à implémenter »* (statut explicite du contrat i18n) — ce lot **déclare les clés**
  (source anglaise + entrée FR dans les catalogues committés existants s'il y en a déjà, sinon nouveau
  fichier) mais ne peut pas dépendre d'un service qui n'existe pas encore. En attendant, le back expose
  les **codes d'enum stables** (`revocation`, `decisionPoint`, etc.) et un front consommant l'API résout
  lui-même le libellé via un dictionnaire local — le jour où `App\I18n` existe, seule la couche de
  résolution change, pas le contrat API.

- **RG-ACC1-09 — Échec fermé sur l'absence de pilote déclaré.** Si `capabilities()` retourne
  `AccessDriverCapabilities::unspecified()` (cas `ItboxAdapter`/`SmartAccessAdapter` aujourd'hui), la
  restitution affiche explicitement « révocation impossible » / « aucune remontée » — **jamais** un état
  vide, `null` non qualifié, ou un texte optimiste par défaut. C'est la même logique qu'au commentaire de
  `RevocationCapability::Impossible` (*« Ne jamais afficher "accès révoqué" dans ce cas — ce serait un
  mensonge à l'exploitant »*) étendue à toute la restitution : au moindre doute, on affiche le pire état
  déclaré, jamais un silence qui laisserait supposer une capacité non vérifiée.

## 6. Objets de données

| Objet | Champ | Type | Contraintes | Notes |
|---|---|---|---|---|
| `CapaciteNonDeclareeException` (nouveau, `App\Acces\Exception` ou `App\Acces\Port`) | `operation` | string | requis | ex. `heartbeat`, `pousserListeRevocation` |
| | `adapterClass` | string | requis | `::class` de l'adaptateur réel décoré |
| | `missingCapability` | string | requis | valeur de l'enum concerné, ex. `RevocationCapability::Impossible->value` |
| `CapabilityGuardedPiloteAcces` (nouveau, `App\Acces\Adapter` ou `App\Acces\Port`) | — | service, `implements PiloteAcces` | décore l'adaptateur réel (RG-ACC1-03) | pas d'entité Doctrine |
| `EntreeAudit` (existant, `App\Audit\Entity`) | `action` | string | `access.capability_gap_detected` (RG-ACC1-04) | pattern existant `caisse.alerte_ecart` |
| | `cibleType` | string | `Controleur` | quand pertinent |
| | `cibleId` | string? | id du `Controleur` | `null` si opération non liée à un contrôleur |
| | `etablissement` | uuid? | dérivé de `Controleur.etablissement` | jamais du contexte HTTP (RG-ACC1-06) |
| `AccesCapacites` (nouvelle ApiResource, non-Doctrine — nom à trancher §9) | `id` | string | ex. id du `Controleur` ou `'live'` si vue globale | pattern `Supervision` (`app/src/Acces/ApiResource/Supervision.php`) |
| | `revocation` | string (enum) | `immediate`\|`deferred`\|`impossible` | RG-ACC1-07 |
| | `revokesImmediately` | bool | dérivé | RG-ACC1-07 |
| | `decisionPoint` | string (enum) | `server`\|`controller`\|`credential` | RG-ACC1-07 |
| | `encodes` | bool | | RG-ACC1-07 |
| | `passageReporting` | string (enum) | `real_time`\|`on_sync`\|`none` | RG-ACC1-07 |
| **Événement (proposé, décision ouverte §9)** `access.capability_gap_detected` | `subject` | `{type: 'Controleur', id}` | | conforme enveloppe `DomainEvent` |
| | `payload.operation` | string | `heartbeat`\|`pousserListeRevocation`\|`recevoirEvenement` | pas de secret (RG-PLAT-04) |
| | `payload.missingCapability` | string | | |

## 7. Critères d'acceptation

- **CA-1 (RG-ACC1-01/02/03)** — *Étant donné* un pilote dont `capabilities().revocation ===
  RevocationCapability::Impossible`, *quand* `pousserListeRevocation()` est appelé sur ce pilote (via le
  décorateur), *alors* une `CapaciteNonDeclareeException` est levée **avant** toute exécution de
  l'adaptateur réel — jamais un `ResultatCommande::echec()` silencieux, jamais un retour `ok` implicite.
- **CA-2 (RG-ACC1-04)** — *Étant donné* le même scénario que CA-1, *quand* l'exception est levée,
  *alors* une `EntreeAudit` d'action `access.capability_gap_detected` est persistée, avec
  `cibleType = 'Controleur'`, `cibleId` renseigné, et `etablissement` = celui du contrôleur — consultable
  ensuite via `GET /audit/entrees` par un rôle `securite.lire`/`securite.gerer`, **même si** la requête
  HTTP d'origine a échoué en 4xx/5xx.
- **CA-3 (RG-ACC1-01, `heartbeat`)** — *Étant donné* `ItboxAdapter` ou `SmartAccessAdapter` actif comme
  pilote (au lieu du Simulateur), *quand* `EtatReseauHandler::pulser()` est invoqué, *alors* l'appel
  échoue explicitement (exception propagée, capturable), **et** une entrée d'audit est écrite — à la
  différence du comportement actuel (§3 point 3 : exception non tracée).
- **CA-4 (non-régression, Simulateur)** — *Étant donné* le Simulateur comme pilote actif (comportement
  par défaut, `app/config/services.yaml:57`), *quand* `ouvrir()`, `heartbeat()`,
  `pousserListeRevocation()` sont appelés par les call sites existants, *alors* aucun comportement
  n'est modifié : mêmes résultats qu'avant l'introduction du décorateur (le Simulateur déclare des
  capacités réelles pour les 4 axes utilisés).
- **CA-5 (RG-ACC1-06/07, restitution)** — *Étant donné* un exploitant habilité de l'établissement A,
  *quand* il consulte la ressource de restitution des capacités, *alors* il voit, pour chaque contrôleur
  de A, si la révocation est immédiate/différée/impossible, si l'encodage est supporté, et comment les
  passages remontent — sans configuration manuelle, dérivé de `capabilities()` du pilote actif.
- **CA-6 (RG-ACC1-06, cloisonnement A/B)** — *Étant donné* deux établissements A et B, *quand* un
  exploitant scopé sur B appelle la ressource de restitution, *alors* il ne voit aucun contrôleur de A
  (même filtrage que `GET /acces/supervision`, `SupervisionProvider`).
- **CA-7 (RG-ACC1-08, i18n)** — *Étant donné* la ressource de restitution, *quand* le front l'affiche,
  *alors* aucun libellé n'est une chaîne française codée en dur dans la réponse API : seuls des codes
  d'enum stables (`impossible`, `deferred`, `server`…) sont renvoyés, résolus en libellé côté
  présentation via des clés `acces.capability.*`.
- **CA-8 (RG-ACC1-09, échec fermé)** — *Étant donné* un pilote `unspecified()` (`ItboxAdapter`/
  `SmartAccessAdapter` aujourd'hui), *quand* la restitution est consultée, *alors* elle affiche
  explicitement `revocation = impossible`, `passageReporting = none`, `encodes = false` — jamais une
  absence de données ni un état par défaut optimiste.
- **CA-9 (RG-ACC1-02, distinction des causes)** — *Étant donné* `ouvrir()` appelé sur `ItboxAdapter`,
  *quand* l'appel a lieu, *alors* l'exception levée reste celle, déjà existante, de « protocole non
  cadré » (§3) — **pas** une `CapaciteNonDeclareeException`, faute d'axe de capacité dédié à l'ouverture
  (RG-ACC1-01, ⚠ HYPOTHÈSE) — non-régression du message actuel.

## 8. Cas limites

- **`ResultatCommande::echec()` ignoré aux call sites `ouvrir()` (§3, points 1-2).** Ce n'est pas un
  défaut de capacité non déclarée (l'opération *est* déclarée, elle échoue *fonctionnellement*), donc
  **hors du mécanisme de garde par capacité** de ce lot au sens strict — mais c'est le même défaut de
  fond (« la plateforme croit avoir réussi »), sur le canal que `ResultatCommande` existe justement pour
  porter. ⚠ HYPOTHÈSE : à signaler comme **risque connexe** à traiter (même lot ou lot séparé, à trancher
  au plan) — un `Passage` persisté `Valide` avant que la commande matérielle échoue silencieusement est
  une incohérence entre ce que la base dit et ce que la porte a réellement fait, du même ordre de gravité
  que le défaut D17 mais sur un mécanisme différent (retour de fonction ignoré, pas capacité non
  déclarée).
- **Pilote qui déclare une capacité mais échoue quand même à l'exécution** (ex. panne réseau ponctuelle
  d'un pilote `Server`/temps réel). Hors périmètre de ce lot : `capabilities()` est une **déclaration
  structurelle** (ce que la topologie permet), pas un état de santé instantané (ça, c'est `heartbeat()`
  et `EtatReseauHandler`). Le décorateur ne doit pas confondre les deux — une capacité déclarée qui
  échoue à l'exécution reste une erreur d'exécution normale (réseau, timeout), pas une
  `CapaciteNonDeclareeException`.
- **`pousserListeRevocation` jamais appelé en production (§3 point 4).** Le CA-1 (test unitaire du
  décorateur) reste valide et nécessaire même si aucun call site production n'existe encore — c'est un
  test de contrat sur le décorateur, pas un test d'intégration bout-en-bout. Ne pas conclure de l'absence
  de call site que la garde est superflue : elle protège le prochain call site, pas le comportement actuel.
- **Un futur pilote qui déclare des capacités partielles** (ex. `revocation = Deferred` mais
  `passageReporting = None` — une unité de traitement qui accepte une liste de révocation à la synchro
  mais ne remonte jamais rien) : le décorateur doit évaluer **chaque opération indépendamment** contre
  son axe propre — ne jamais déduire qu'un pilote « globalement capable » l'est sur les 4 axes.
  `unspecified()` (tout à `Impossible`/`None`) est le seul cas testé aujourd'hui (Itbox/SmartAccess) ;
  ce cas partiel doit être couvert par un test dédié (adaptateur de test à capacités mixtes).

## 9. Décisions ouvertes (à trancher au plan)

- **D-1 — Décorateur vs vérifications par call site : recommandation tranchée pour le décorateur.**
  *Pour le décorateur* : un seul endroit connaît le mapping opération→capacité (RG-ACC1-01), testable
  isolément (un test par opération × pilote `unspecified()`/partiel/complet, sans dépendre des 3 handlers
  applicatifs), et **structurellement impossible à oublier** pour un futur 4ᵉ ou 5ᵉ call site (ex. un
  futur `EncodeurHandler` pour ACC-2) — il suffit qu'il consomme `PiloteAcces` via injection standard pour
  hériter de la garde, sans rien coder de spécifique. *Contre, et pourquoi ce n'est pas retenu* : les
  vérifications par call site permettraient des messages d'erreur contextualisés (« impossible d'ouvrir
  *cet équipement précis* ») — mais rien n'empêche le décorateur de recevoir le contexte en paramètre
  (l'`Equipement`/`Controleur` concerné est déjà passé à chaque opération) et de l'inclure dans
  l'exception (RG-ACC1-02, `CapaciteNonDeclareeException` porte déjà `adapterClass`+`operation`+
  `missingCapability` — ajouter l'id de la cible est immédiat). Le seul vrai risque du décorateur est un
  couplage additionnel dans `services.yaml` (un service de plus à comprendre) — jugé largement inférieur
  au risque de dispersion (trois `if` copiés-collés dans 3 handlers aujourd'hui, N demain, avec
  divergence quasi certaine à terme). **Recommandation : décorateur.**
- **D-2 — Événement de domaine `access.capability_gap_detected` : utile, mais à ne pas sur-construire.**
  Le catalogue (`COORDINATION/CONTRACT/catalogue-evenements.md`) référence déjà `access.denied` /
  `access.recorded` sans qu'aucun ne soit actuellement publié depuis `App\Acces` (aucun
  `EventBusInterface`/`SymfonyEventBus` utilisé dans ce module aujourd'hui, vérifié). Publier
  `access.capability_gap_detected` donnerait à Supervision/Reporting un signal temps réel («
  tentative bloquée, là, maintenant ») en plus de l'audit (consultation a posteriori) — cohérent avec
  D2 (découplage par événements) si un futur abonné existe (ex. alerte support). ⚠ HYPOTHÈSE : ce lot
  **propose** l'ajout au catalogue et l'émission via `App\Platform\Event\SymfonyEventBus` (le décorateur
  publierait après avoir écrit l'audit, dans la même unité de travail, en respectant RG-PLAT-04 —
  payload = `operation`, `missingCapability`, pas de donnée sensible) mais **ne le rend pas bloquant** :
  l'audit seul (RG-ACC1-04) satisfait déjà la traçabilité minimale exigée par D17. À confirmer au plan
  selon qu'un abonné réel est identifié (sinon, événement publié dans le vide — pas interdit, mais pas
  gratuit non plus : discipline de catalogue à respecter, D2).
- **D-3 — Granularité de la restitution : pilote global (aujourd'hui) vs par contrôleur (architecture
  cible de D17).** D17 anticipe explicitement des topologies mixtes (« lecteur IP qui est sa propre unité
  de traitement, serrure autonome sur pile… ») coexistant sur un même établissement, donc à terme des
  capacités **différentes par contrôleur**, pas un seul pilote global. Aujourd'hui, `services.yaml`
  n'admet qu'un unique alias `PiloteAcces` pour toute la plateforme (§2, §3) : il n'existe **aucun
  mécanisme de sélection de pilote par `Controleur`**. Deux options : **(a)** ce lot restitue les
  capacités du pilote unique actif, affichées de façon identique pour tous les contrôleurs de
  l'établissement (simple, correct aujourd'hui, mais deviendra faux le jour où un 2ᵉ adaptateur coexiste)
  — **recommandé pour ce lot**, en documentant explicitement la limite (RG-ACC1-07/objet `AccesCapacites`
  conçu pour accepter un id de `Controleur` dès maintenant, même si la valeur renvoyée est aujourd'hui
  identique pour tous) ; **(b)** introduire dès ce lot un registre pilote-par-contrôleur — rejeté ici,
  hors périmètre (aucun besoin métier actuel ne le justifie, et ACC-0 n'a pas construit ce registre).
  Le champ `Controleur.itboxRef` (référence logique au concentrateur, cf. docblock de l'entité) est le
  candidat naturel de clé de sélection le jour où (b) sera nécessaire — à ne pas casser.
- **D-4 — Nom exact de l'exception et de la ressource de restitution (D5).** Proposé :
  `CapaciteNonDeclareeException` (cohérent avec `PassageRefuseException`, existant, français — le module
  `App\Acces` reste historiquement francophone hors ajouts D17) **ou** `UndeclaredCapabilityException`
  (D5 strict, anglais, cohérent avec `AccessDriverCapabilities`/`RevocationCapability` déjà en anglais
  dans ce même module depuis ACC-0). Même tension pour la ressource : `AccesCapacites` (français,
  cohérent avec `Controleur`/`Equipement`/`Supervision`) vs `AccessCapabilities` (anglais, cohérent avec
  le port lui-même). ⚠ HYPOTHÈSE : recommandation **anglais** pour les deux, par cohérence directe avec
  le code ACC-0 immédiatement adjacent (`AccessDriverCapabilities`, `RevocationCapability`,
  `CredentialEncoding`, `PassageReporting` sont déjà en anglais dans ce module) — contrairement à
  `TypeDroitAcces` (ACC-3) qui étend un enum **déjà** majoritairement français, ici le point d'ancrage
  immédiat est déjà anglais. Non tranché définitivement, laissé au plan.
- **D-5 — Permission de lecture de la restitution.** RG-ACC1-06/CA-5 proposent de réutiliser
  `acces.superviser` (déjà utilisée par `GET /acces/supervision`, cohérent — même écran A-03
  potentiellement) plutôt que créer une permission dédiée. ⚠ HYPOTHÈSE : si la restitution des capacités
  doit être visible par un rôle qui n'a pas `acces.superviser` (ex. un rôle commercial/contractuel qui a
  besoin de savoir « ce site révoque-t-il immédiatement » sans avoir accès à la supervision temps réel
  des jauges/incidents), `acces.lire` (déjà utilisé pour `Controleur`/`Equipement` en lecture) serait le
  choix alternatif. À trancher au plan selon le rôle exact qui consulte cet écran.

## 10. Dépendances
- **Dépend de** `D17` (`COORDINATION/DECISIONS.md`) et d'ACC-0 (livré) : `App\Acces\Port\PiloteAcces`,
  `App\Acces\Port\AccessDriverCapabilities`, les 4 enums (`DecisionPoint`, `RevocationCapability`,
  `CredentialEncoding`, `PassageReporting`), les 3 adaptateurs.
- **Dépend de** `App\Audit\Service\JournalAudit` (existant, RG-SOCLE-07) pour la traçabilité (RG-ACC1-04).
- **Dépend potentiellement de** `App\Platform\Event\SymfonyEventBus`/`DomainEvent` (existant, D7/D7-bis)
  si D-2 est retenue.
- **Dépend de** `App\Securite\Service\ContexteEtablissement` (existant) pour le cloisonnement de la
  restitution, même patron que `SupervisionProvider`.
- **Référencé par (attendu)** : tout futur adaptateur réel (post E-4/D19) devra passer par le décorateur
  sans modification de celui-ci — c'est le test de non-régression de la conception (D-1).
- **Bloqueur externe non levé** : E-4 (protocole ITBOX/SmartAccess non cadré, `COORDINATION/
  BLOQUEURS-EXTERNES.md`, D19) — ce lot ne dépend pas de sa levée et ne la précipite pas.
- **Risque principal** — le décorateur, s'il est mal placé dans la chaîne DI (ex. si un handler
  continue d'injecter l'adaptateur concret au lieu du port `PiloteAcces`), contournerait silencieusement
  la garde. À vérifier explicitement au plan : les 3 handlers (`ValidationPassageHandler`,
  `OuvertureManuelleHandler`, `EtatReseauHandler`) injectent déjà `PiloteAcces` (interface), jamais une
  classe d'adaptateur concrète (vérifié §4 du présent document) — le risque est donc faible mais le test
  de garde (RG-ACC1-05/CA-4) doit explicitement couvrir ce point.
- **Risque secondaire** — le cas limite `ResultatCommande::echec()` ignoré (§8) reste ouvert après ce
  lot si non traité explicitement ; à statuer au plan (même lot ou ticket séparé).
