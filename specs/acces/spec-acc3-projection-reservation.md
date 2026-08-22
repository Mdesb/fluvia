# Spec — ACC-3 : projection réelle « une réservation ouvre un accès » (`RG-M5-12`, `RG-ACC-01`)

- **Lot / module :** L3 · Accès — extension consommée par L5 · Réservation (`App\Reservation`)
- **Stories couvertes :** US-RES-12 (`spec-reservation.md` §4.9), US-L3-02 (appairage, réutilisé sans
  modification)
- **Règles de gestion :** RG-M5-12 (déclenchement), RG-ACC-01/02/05/06/07 (validation générique L3,
  non redéfinie), + `RG-ACC3-01` à `RG-ACC3-07` (nouvelles, ce lot)
- **Statut :** brouillon

## Recommandation ACC-0 (à lire en premier)

**ACC-3 est réalisable maintenant, indépendamment d'ACC-0. Aucun blocage.**

D17 (`COORDINATION/DECISIONS.md`) porte sur `App\Acces\Port\PiloteAcces` — les quatre opérations
`ouvrir`, `recevoirEvenement`, `heartbeat`, `pousserListeRevocation`, c'est-à-dire **comment un
pilote commande physiquement un contrôleur/équipement**. ACC-3 ne touche à aucune de ces quatre
opérations : projeter un `DroitAcces` depuis une `Reservation` est une écriture de données pure
(fenêtre, statut, établissement), consommée en aval par :
1. `App\Acces\Service\ValidationPassageHandler::valider()` — qui n'appelle `$this->pilote->ouvrir()`
   qu'**après** avoir validé le droit (ligne 269), donc plusieurs étapes après la projection ;
2. `App\Acces\State\SnapshotTerminalProvider` — qui sérialise le droit pour la borne, sans jamais
   consulter `PiloteAcces` ;
3. `App\Acces\Service\AppairageHandler::appairer()` — qui lie un `Support` à un `DroitAcces`, sans
   `PiloteAcces` non plus.

La preuve la plus directe que ce découplage existe déjà en production : **`App\Personnel\Service\
EmissionBadgeStaffHandler`** construit un `DroitAcces` **directement** (hors `ProjectionDroitInterface`,
avec `TypeDroitAcces::Personnel`) puis l'appaire via `AppairageHandler::appairer()` — exactement le
chemin qu'ACC-3 doit répliquer pour `TypeDroitAcces` côté réservation, et ce code est marchand
aujourd'hui, sans qu'ACC-0 existe. Le port `PiloteAcces`/ses capacités ne sont sollicités qu'au moment
du franchissement physique (scan au tourniquet), jamais à la projection.

**Périmètre exact réalisable maintenant** (détaillé en §2/§4) : remplacer le no-op de
`ProjectionAccesReservationHandler` par une construction/mise à jour réelle d'un `DroitAcces`, plus la
révocation symétrique à l'annulation/no-show. Rien dans ce périmètre ne lit ni n'attend une capacité
déclarée par un pilote.

## 1. Objectif
Qu'une réservation confirmée sur une ressource qui ouvre un accès (`Ressource.ouvreAcces = true`)
produise un `DroitAcces` réellement exploitable par le moteur générique L3 (marges, anti-passback,
hors-ligne) — et que ce droit soit automatiquement invalidé si la réservation est annulée ou bascule
en no-show — au lieu du no-op documenté actuel qui ne fait que journaliser l'intention.

## 2. Périmètre
- **Inclus :**
  - Construction/mise à jour réelle d'un `App\Acces\Entity\DroitAcces` à la confirmation d'une
    Réservation dont la Ressource porte `ouvreAcces = true` (remplace le no-op de
    `ProjectionAccesReservationHandler::projeterSiApplicable()`).
  - Renseignement de `ProjectionAccesReservation.droitAccesRef` (aujourd'hui toujours `null`).
  - Révocation (`DroitAcces.statutProjection = Devalide`) quand la Réservation quitte l'état
    « occupe la place » (annulation libre, annulation tardive facturée, no-show).
  - Idempotence de la projection (rejeu/double appel sans doublon ni violation de contrainte).
  - Cloisonnement par établissement (dérivé de `Reservation.etablissement`, jamais d'un en-tête client).
  - Un nouveau cas `TypeDroitAcces` dédié aux droits issus d'une réservation (nommage à trancher,
    cf. §4, tension D5).
- **Exclu (pour l'instant), référencé mais non redéfini :**
  - **ACC-0** (déclaration de capacités d'un `PiloteAcces`, échec explicite sur opération non
    déclarée) — sans lien fonctionnel avec ce lot (cf. recommandation ci-dessus).
  - **ACC-1** (le comportement d'échec explicite d'une opération de pilote non déclarée) — hors
    périmètre, aucune opération `PiloteAcces` n'est appelée par ce lot.
  - **ACC-2** (encodage d'un droit sur un médium physique) — ce lot ne crée, n'écrit et n'appaire
    **aucun** `Support`. L'appairage (lier une carte/QR physique au `DroitAcces` projeté) reste un
    acte séparé, via le mécanisme générique déjà existant `POST /acces/appairages` (paramètre
    `droit`, cf. `App\Acces\State\AppairageProcessor` lignes 63-75) — inchangé par ce lot.
  - Le paramétrage de la **tolérance d'entrée** (marge avance/retard) au niveau Ressource/Établissement
    — `ProjectionAccesReservation.margeAvanceMinutes/margeRetardMinutes` existent déjà en base mais ne
    sont alimentées par **aucun** code actuel (cf. §8, dépendance non bloquante).
  - Le câblage d'un bus d'événements (`booking.created`/`booking.cancelled` du catalogue
    `COORDINATION/CONTRACT/catalogue-evenements.md`) — **n'existe nulle part dans le code
    aujourd'hui** (vérifié : aucune occurrence de ces noms d'événement, aucun `EventBusInterface`
    dans `app/src`). Ce lot reste sur le patron actuel — appel PHP synchrone direct depuis les
    processors de réservation — cohérent avec l'existant, pas de régression d'architecture à
    inventer ici.

## 3. Acteurs & droits
| Acteur | Peut | Permission (module × action) |
|---|---|---|
| Système (déclenché par la confirmation/l'annulation d'une réservation) | Projeter/dévalider un `DroitAcces` en side-effect, sans action utilisateur dédiée | — (aucun nouvel endpoint, aucune nouvelle permission) |
| Agent guichet/accueil | Appairer un support physique au droit projeté (mécanisme déjà existant) | `acces.appairer` (existant, inchangé) |
| Agent/gestionnaire | Consulter le `DroitAcces`/`Passage` résultant | `acces.lire` (existant, inchangé) |

Ce lot ne crée aucune nouvelle permission ni aucun nouvel endpoint API : il ajoute uniquement du
comportement côté service (`ProjectionAccesReservationHandler`) et une valeur d'énumération.

## 4. État actuel (ce que fait le no-op, code réel)

- `app/src/Reservation/Entity/ProjectionAccesReservation.php` — `droitAccesRef` (ligne 49-51) est un
  `?Uuid` nullable, **jamais renseigné** par aucun code aujourd'hui. Le docblock (lignes 18-24) documente
  explicitement le no-op : *« la projection réelle vers `App\Acces\Entity\DroitAcces` nécessite une
  extension du port `ProjectionDroitInterface` côté L3 […], hors périmètre de ce lot — no-op documenté
  (log) en attendant »*.
- `app/src/Reservation/Service/ProjectionAccesReservationHandler.php` — `projeterSiApplicable()`
  (lignes 27-50) crée une `ProjectionAccesReservation` (fenêtre = créneau début/fin, établissement),
  **laisse `droitAccesRef` à `null`** (ligne 40) et journalise `reservation.projection_acces.no_op`
  (lignes 44-47) au lieu de produire un `DroitAcces`.
- Trois points d'appel identiques, tous immédiatement après la persistance/confirmation de la
  Réservation :
  - `app/src/Reservation/State/ReserverProcessor.php:125`
  - `app/src/Padel/State/ReserverTerrainProcessor.php:164`
  - `app/src/Boutique/Service/ConfirmerCommandeHandler.php:229`
- **Aucune révocation à l'annulation aujourd'hui.** `AnnulerReservationProcessor.php`,
  `AnnulerCreneauProcessor.php` et `DeclencherFacturationNoShowHandler.php` (utilisé par
  `BasculerNoShowCommand.php`) changent `Reservation.statut` mais ne touchent ni
  `ProjectionAccesReservation` ni `DroitAcces` — un droit projeté resterait `Valide` même après
  annulation avant l'heure du créneau, ce qui laisserait un accès ouvert non mérité.
- `App\Acces\Port\ProjectionDroitInterface::projeter(Uuid $billetSupportRef, Etablissement)` et son
  implémentation `App\Acces\Projection\StubProjectionDroit` sont **structurellement scopés à M2**
  (ils lisent `BilletSupport`/`Produit`) — une `Reservation` n'a pas de `BilletSupport` associé, ce
  port n'est donc **pas** le bon point d'extension pour ce lot (cf. §5).
- **Précédent direct à répliquer** — `app/src/Personnel/Service/EmissionBadgeStaffHandler.php`
  (lignes 79-97) construit un `DroitAcces` **directement** (`sourceType = TypeDroitAcces::Personnel`,
  hors `ProjectionDroitInterface`) puis l'appaire via `App\Acces\Service\AppairageHandler::appairer()`.
  Le docblock de `TypeDroitAcces` (lignes 8-16) documente déjà ce patron comme « extension additive
  coordonnée […] aucun impact sur le moteur ».
- **Précédent pour la révocation** — `app/src/Recouvrement/Service/PropagationAccesHandler.php`
  (lignes 43-52) et `app/src/Sport/Service/PropagationAccesFitnessHandler.php` écrivent directement
  `DroitAcces->setStatutProjection(Valide|Devalide)` depuis un module tiers, sans modifier aucun
  fichier `App\Acces\*` — le docblock revendique explicitement *« aucun fichier App\Acces\* n'est
  modifié […] exactement comme M2/Sport »*.

## 5. Comportements & règles

- **RG-ACC3-01 — Déclenchement (création).** La projection réelle a lieu si et seulement si
  `Creneau.ressource.ouvreAcces = true` (inchangé, RG-M5-12) et que la Réservation est dans un état
  qui « occupe la place » (`StatutReservation::occupePlace()`, aujourd'hui uniquement `Confirmee` au
  moment de l'appel — `Honoree` survient plus tard et ne redéclenche pas de projection). Les trois
  points d'appel existants (`ReserverProcessor`, `ReserverTerrainProcessor`, `ConfirmerCommandeHandler`)
  restent les points d'entrée ; aucun nouveau call site de **création** n'est nécessaire.
- **RG-ACC3-02 — Contenu du `DroitAcces` projeté.**
  - `sourceType` = nouveau cas `TypeDroitAcces` dédié (cf. tension de nommage ci-dessous).
  - `fenetreDebut` / `fenetreFin` = `Creneau.debut` / `Creneau.fin`, éventuellement élargies par
    `ProjectionAccesReservation.margeAvanceMinutes` / `margeRetardMinutes` si renseignées (report vers
    `DroitAcces.margeAvanceDefaut` / `margeRetardDefaut`, consommés par
    `App\Acces\Service\ResolveurMarges::estDansMarges()`, inchangé). ⚠ HYPOTHÈSE : ces marges restent
    `null` tant qu'aucun paramétrage Ressource/Établissement ne les alimente (aucun code existant ne
    les renseigne aujourd'hui, cf. §2 exclusions) — la fenêtre est alors stricte, sans tolérance.
  - `creditRestant` = `null` (une réservation n'est pas une carte à quota : pas de décompte au
    passage, comme un `billet`/`abonnement` simple — `ValidationPassageHandler` ne décompte que
    `TypeDroitAcces::CarteQuota`, étape 7, inchangé).
  - `produitRef` = `null` (aucun `Produit` M1 associé à une réservation).
  - `etablissement` = `Reservation.etablissement` (jamais un en-tête client, cf. RG-ACC3-06).
  - `statutProjection` = `Valide`, `synchroniseLe` = horodatage de la projection.
- **RG-ACC3-03 — Construction directe, hors `ProjectionDroitInterface`.** Comme pour
  `TypeDroitAcces::Personnel`, la projection d'un droit issu d'une réservation **ne passe pas** par
  `ProjectionDroitInterface::projeter()` (dont la signature `billetSupportRef` est structurellement
  M2) : `ProjectionAccesReservationHandler` construit/retrouve le `DroitAcces` directement. ⚠
  HYPOTHÈSE (non bloquante, à trancher en plan) : ajouter un champ symétrique `reservationRef: ?Uuid`
  sur `DroitAcces` (miroir de `billetSupportRef`/`produitRef`) faciliterait le diagnostic côté Acces
  (« quelle réservation a produit ce droit ? ») et nécessiterait une migration (colonne nullable) ;
  ce n'est **pas indispensable** au fonctionnement — l'upsert peut s'appuyer uniquement sur
  `ProjectionAccesReservation.droitAccesRef`, déjà présent, côté Réservation.
- **RG-ACC3-04 — Idempotence.** `projeterSiApplicable()` doit être sûr à rejouer pour une même
  `Reservation` : si une `ProjectionAccesReservation` existe déjà (contrainte unique
  `uniq_projection_acces_reservation` sur `reservation_id`, déjà en base), la ré-invocation **met à
  jour** la projection existante (et le `DroitAcces` référencé par `droitAccesRef`, notamment la
  fenêtre si le créneau a changé) au lieu d'en créer une seconde — même patron find-or-create que
  `StubProjectionDroit::projeter()` (`findOneBy(['billetSupportRef' => …])`, lignes 32-33).
- **RG-ACC3-05 — Révocation symétrique.** Toute transition de `Reservation.statut` **hors** de
  `occupePlace()` (`AnnuleeLibre`, `AnnuleeTardiveFacturee`, `NoShowFacture`) doit, si une
  `ProjectionAccesReservation`/`DroitAcces` existe, positionner `DroitAcces.statutProjection =
  Devalide` (même mécanisme que `PropagationAccesHandler`, écriture directe depuis
  `App\Reservation`, sans modifier `App\Acces\*`). Points d'intégration identifiés (3, symétriques
  aux 3 points de création) :
  - `AnnulerReservationProcessor.php` — branche libre (`setStatut(AnnuleeLibre)`, ligne 63) **et**
    branche tardive (via `DeclencherFacturationNoShowHandler::declencher()`, appelé ligne 66).
  - `AnnulerCreneauProcessor.php` — `setStatut(AnnuleeLibre)` en boucle (ligne 39).
  - `BasculerNoShowCommand.php` — branche no-show, via `DeclencherFacturationNoShowHandler::declencher()`
    (appelé ligne 77).
  Une transition vers `Honoree` (présence confirmée) ne dévalide **pas** explicitement : la fenêtre
  est de toute façon échue à ce stade et `ResolveurMarges` refuse déjà tout passage postérieur — ⚠
  HYPOTHÈSE : dévalider quand même par cohérence/traçabilité n'est pas interdit, laissé au plan.
- **RG-ACC3-06 — Cloisonnement (D3/D8).** `DroitAcces.etablissement` dérive strictement de
  `Reservation.etablissement` — lui-même dérivé serveur à la création de la Réservation (jamais du
  contexte HTTP). Un droit projeté sur l'établissement A n'est ni listé, ni lisible (`GetCollection`/
  `Get`), ni appairable par un agent scopé sur B — hérité tel quel du filtrage d'entité déjà en place
  sur `DroitAcces`/`Appairage` (non modifié par ce lot).
- **RG-ACC3-07 — Hors-ligne : garanti transitivement, non redéveloppé.** Ce lot ne crée ni n'appaire
  de `Support` : un `DroitAcces` seul, sans `Appairage` actif, n'apparaît jamais dans
  `SnapshotTerminalProvider` (qui itère sur `Support.versionMaj`, cf. lignes 68-98) et n'est donc pas
  exploitable par un contrôleur hors-ligne tant qu'il n'a pas été appairé. « Exploitable hors-ligne »
  (objectif de ce lot) signifie : **une fois appairé** via le mécanisme générique existant (`POST
  /acces/appairages` avec `droit=<iri du DroitAcces projeté>`), le droit se comporte exactement comme
  un droit M2 ou un badge staff — sans aucune modification du moteur hors-ligne, de l'anti-passback ou
  du snapshot.
- **Tension de nommage (D5, à trancher au plan)** — les cas existants de `TypeDroitAcces` sont en
  français (`Billet`, `Abonnement`, `CarteQuota`, `Personnel`) ; D5 impose l'anglais pour tout
  **nouvel** identifiant technique. Deux options pour le nouveau cas :
  - **Option A (recommandée, respecte D5)** — `TypeDroitAcces::Booking = 'booking'`.
  - **Option B (cohérence locale avec l'existant)** — `TypeDroitAcces::Reservation = 'reservation'`.
  Aucune migration de schéma n'est requise dans les deux cas (`source_type` déjà `VARCHAR(24)`, même
  remarque que pour `Personnel`). Recommandation : Option A, la primauté de D5 sur la cohérence
  locale d'un enum déjà mixte est plus soutenable à moyen terme (le module `App\Acces` migrera vers
  l'anglais de façon incrémentale, D5) — mais c'est un arbitrage de nommage, pas un point bloquant,
  laissé au plan/à l'implémenteur.

## 6. Objets de données

| Objet | Champ | Type | Contraintes | Notes |
|---|---|---|---|---|
| `DroitAcces` (existant, `App\Acces\Entity\DroitAcces`) | `sourceType` | enum `TypeDroitAcces` | requis | nouveau cas `Booking`/`Reservation` (RG-ACC3-02, tension §5) |
| | `fenetreDebut`, `fenetreFin` | datetime? | requis pour ce cas d'usage | = créneau ± marges (RG-ACC3-02) |
| | `creditRestant` | int? | `null` pour ce cas | pas de décompte (RG-ACC3-02) |
| | `produitRef` | uuid? | `null` pour ce cas | pas de `Produit` M1 |
| | `reservationRef` | uuid? | **nouveau champ, optionnel** | ⚠ HYPOTHÈSE non bloquante (RG-ACC3-03), migration si retenu |
| | `statutProjection` | enum `StatutProjectionDroit` | `Valide` → `Devalide` sur annulation | RG-ACC3-05 |
| | `etablissement` | ref `Etablissement` | requis, non nullable | = `Reservation.etablissement` (RG-ACC3-06) |
| `TypeDroitAcces` (existant, enum) | `Booking` \| `Reservation` | cas d'enum string | **nouvelle valeur**, `VARCHAR(24)` déjà en place | tension de nommage §5 |
| `ProjectionAccesReservation` (existant, `App\Reservation\Entity`) | `droitAccesRef` | uuid? | **désormais renseigné** | pointe vers `DroitAcces.id`, pas de FK Doctrine directe (cloisonnement inter-module, même patron que `billetSupportRef`) |
| | `fenetreDebut`, `fenetreFin` | datetime | déjà alimentés | inchangé |
| | `margeAvanceMinutes`, `margeRetardMinutes` | int? | jamais alimentés aujourd'hui | ⚠ HYPOTHÈSE, hors périmètre (§2) |

## 7. Critères d'acceptation

- **CA-1 (RG-ACC3-01/02)** — *Étant donné* une Réservation confirmée sur une Ressource
  `ouvreAcces = true`, *quand* la confirmation a lieu, *alors* `ProjectionAccesReservation.droitAccesRef`
  est non nul et référence un `DroitAcces` avec `statutProjection = Valide`, `fenetreDebut`/`fenetreFin`
  égales à celles du créneau, et `etablissement` = celui de la réservation.
- **CA-2 (RG-ACC3-07, non-régression du générique)** — *Étant donné* un `DroitAcces` projeté par CA-1,
  *quand* un agent l'appaire à un support physique via `POST /acces/appairages` (paramètre `droit`),
  *alors* un passage présenté dans la fenêtre est **accepté** par `ValidationPassageHandler`, et un
  passage hors fenêtre est **refusé** (`CodeMotifRefus::HorsMarge`) — sans aucune modification du
  moteur de validation.
- **CA-3 (RG-ACC3-05, annulation libre)** — *Étant donné* une réservation avec droit projeté (CA-1),
  *quand* elle est annulée dans le délai franc (`AnnulerReservationProcessor`, branche libre),
  *alors* `DroitAcces.statutProjection` passe à `Devalide` et un passage tenté ensuite est refusé
  (`CodeMotifRefus::DroitInvalide`, « Droit dévalidé »).
- **CA-4 (RG-ACC3-05, annulation tardive / no-show)** — *Étant donné* une réservation avec droit
  projeté, *quand* elle bascule en `AnnuleeTardiveFacturee` (agent, hors délai franc) ou en
  `NoShowFacture` (`BasculerNoShowCommand`), *alors* `DroitAcces.statutProjection` passe également à
  `Devalide`.
- **CA-5 (RG-ACC3-06, cloisonnement)** — *Étant donné* deux établissements A et B, *quand* une
  réservation confirmée sur A projette un `DroitAcces`, *alors* un agent scopé sur B ne peut ni le
  lire (`GET /acces/droit_acces/{id}`), ni l'utiliser comme cible d'un appairage.
- **CA-6 (RG-ACC3-04, idempotence)** — *Étant donné* une réservation déjà confirmée avec projection
  existante, *quand* `projeterSiApplicable()` est appelé une seconde fois pour la même réservation
  (rejeu défensif), *alors* aucune seconde `ProjectionAccesReservation` n'est créée (contrainte unique
  respectée) et le `DroitAcces` existant est mis à jour, pas dupliqué.
- **CA-7 (non-régression)** — *Étant donné* une Ressource `ouvreAcces = false`, *quand* une réservation
  y est confirmée, *alors* aucune `ProjectionAccesReservation` ni aucun `DroitAcces` ne sont créés
  (comportement actuel inchangé, couvert par `ProjectionAccesTest::testAucuneProjectionSiRessourceNouvrePasAcces`).
- **CA-8 (RG-ACC3-07, hors-ligne)** — *Étant donné* un `DroitAcces` projeté (CA-1) et appairé (CA-2),
  *quand* le contrôleur est hors-ligne, *alors* le passage est validé localement à partir de la liste
  de révocation embarquée (mécanisme générique `RG-ACC-05`, non modifié) — la révocation d'une
  réservation (CA-3/CA-4) se propage au prochain rafraîchissement du snapshot comme toute autre
  révocation `DroitAcces`.

## 8. Cas limites

- **Réservation confirmée puis créneau reporté** (report automatique, RG-M5-07/11) — la fenêtre du
  `DroitAcces` doit suivre le nouveau créneau. ⚠ HYPOTHÈSE : aucun call site actuel ne re-déclenche
  `projeterSiApplicable()` après un report ; à ajouter au plan si le report automatique modifie le
  créneau d'une réservation déjà confirmée avec droit projeté (sinon la fenêtre reste celle d'origine
  — incohérence potentielle, hors périmètre strict de ce lot mais à signaler).
- **Reservation confirmée sans jamais être appairée** — le `DroitAcces` existe, `statutProjection =
  Valide`, mais n'apparaît dans aucun snapshot terminal (RG-ACC3-07) : aucun passage n'est possible
  tant qu'aucun agent n'a appairé un support. Comportement voulu, pas un bug.
- **Annulation d'une réservation jamais projetée** (Ressource `ouvreAcces = false`) — la révocation
  (RG-ACC3-05) est un no-op silencieux (aucune `ProjectionAccesReservation` à retrouver), pas une
  erreur.
- **Double appairage après révocation** — un `DroitAcces` dévalidé (`Devalide`) reste techniquement
  appairable (`AppairageHandler::appairer()` ne vérifie pas `statutProjection`, seulement
  `Support.statut`) mais tout passage sera refusé par `ValidationPassageHandler` (étape 3, « Droit
  dévalidé ») — cohérent avec le comportement M2 existant, pas une régression introduite ici.
- **Reservation gratuite (`ModeDecompteReservation::Gratuit`/`QuotaFormule`)** — la projection d'accès
  ne dépend pas du mode de décompte financier (`ouvreAcces` seul déclenche), donc une réservation
  gratuite avec accès ouvre un droit exactement comme une réservation payante — cohérent avec
  RG-M5-12 qui ne conditionne pas la projection au paiement.

## 9. Dépendances

- **Dépend de** `specs/reservation/spec-reservation.md` (RG-M5-12, CA-15) — règle déjà validée, non
  re-tranchée ici, seulement rendue réellement effective côté Accès.
- **Dépend de** `App\Acces\Entity\DroitAcces`, `App\Acces\Enum\TypeDroitAcces`,
  `App\Acces\Enum\StatutProjectionDroit`, `App\Acces\Service\AppairageHandler`,
  `App\Acces\Service\ResolveurMarges` — tous stables, non affectés par ACC-0.
- **Ne dépend pas** d'ACC-0/ACC-1 (`PiloteAcces`, déclaration de capacités) — justifié en tête de
  document.
- **Risque — concurrence sur la création.** Deux appels quasi simultanés à `projeterSiApplicable()`
  pour la même réservation (retry HTTP applicatif) pourraient violer la contrainte unique
  `uniq_projection_acces_reservation` si le find-or-create n'est pas protégé (verrou/`ON DUPLICATE`
  ou catch-and-reread) — aujourd'hui `persist()+flush()` simple, à traiter explicitement au plan
  (RG-ACC3-04).
- **Risque — champ `DroitAcces.reservationRef` non tranché** (RG-ACC3-03) — absence de lien retour
  Acces → Réservation ; faible impact fonctionnel (diagnostic seulement), migration à décider au plan.
- **Dépendance non bloquante** — `ProjectionAccesReservation.margeAvanceMinutes/margeRetardMinutes`
  ne sont alimentées par aucun code amont (paramétrage US-RES-12 non livré) : la tolérance d'entrée
  restera nulle tant que ce paramétrage n'existe pas, ce n'est pas un défaut introduit par ce lot.
- **Référencé par** : toute verticale qui active `Ressource.ouvreAcces = true` (Padel confirmé via
  `AccesBadgeTest`, potentiellement Sport/Piscine/Musée plus tard) — consomme ce lot sans redéfinition,
  comme documenté dans `spec-reservation.md` §9.
