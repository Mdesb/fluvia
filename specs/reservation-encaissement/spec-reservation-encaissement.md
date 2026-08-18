# Spec — Réservation payante → facturation/encaissement en caisse (`M5 × M2`, lien transverse)

- **Lot / module :** Lien transverse **M5 Planning & Réservation** (`App\Reservation`) × **M2 Vente &
  Caisse** (`App\Vente`/`App\Caisse`), avec articulation **Facturation** (`App\Facturation`) et
  **PaiementDistance** (`App\PaiementDistance`). Ce n'est **pas** un nouveau module : cette spec
  **formalise et complète** un lien qui existe **déjà partiellement dans le code** (§0).
- **Stories couvertes :** **US-RESAENC-01 à 06** — ⚠ **HYPOTHÈSE** : ces stories **n'existent pas dans
  `backlog.html`** (aucune US ne porte explicitement sur le lien réservation→caisse) ; elles sont
  **définies par cet agent**, à la demande explicite du commanditaire (« la réservation et la
  vente/caisse semblent découplées »). **À faire valider et numéroter officiellement** avant
  développement.
- **Règles de gestion :** **RG-RESAENC-01 à 10** (**nouvelles**, cet agent, cf. §4) qui **réutilisent
  sans les redéfinir** : `RG-M5-01/02/04/09/10` (`specs/reservation/spec-reservation.md`),
  `RG-M2-01/03/06/07/08` (`specs/L2-vente/spec-vente.md`), `RG-FACT-03` (`specs/facturation/spec-facturation.md`).
- **Statut :** brouillon — corrige un **écart identifié entre le comportement voulu et le code actuel**
  (§0 « État des lieux » et RG-RESAENC-09/10 : l'annulation d'une réservation payée ne déclenche **pas**
  aujourd'hui d'avoir/remboursement).

## 0. État des lieux (pourquoi cette spec, pas un nouveau module)
Le code contient **déjà** l'essentiel du mécanisme demandé — cette spec ne réinvente rien, elle
**documente le comportement observable attendu** et **comble les trous** :

- `App\Reservation\State\ReserverProcessor` + `App\Reservation\Service\VenteReservationHandler`
  (`app/src/Reservation/Service/VenteReservationHandler.php`) créent **déjà** une `Vente` M2 réelle,
  rattachée à une `SessionCaisse`, quand une réservation payante n'a pas de quota de formule
  disponible (`RG-M5-02`) — **exactement le patron demandé**, et c'est le **même patron** que celui
  utilisé pour la facturation d'un no-show (`App\Reservation\Facturation\VenteDiffereeAgentStrategie`,
  `DebitPmvStrategie`) : ce module **réutilise cette réutilisation**, il ne la redouble pas.
- Ce qui **manque** ou est **incomplet** dans le code à ce jour (les trous que cette spec comble) :
  1. **Aucun statut de paiement observable** sur la `Reservation` : elle reste `Confirmee` que sa
     `Vente` rattachée soit encaissée (`Validee`) ou pas encore (`EnCours`) — impossible de savoir « à
     payer » vs « payée » sans aller lire la `Vente` (RG-RESAENC-03).
  2. **`AnnulerReservationProcessor` ne touche jamais `venteRattachee`** : annuler une réservation
     **déjà payée** dans le délai franc ne génère **aucun avoir/remboursement** — la recette reste
     encaissée en caisse alors que la place est libérée (RG-RESAENC-09, gap identifié).
  3. Annuler une réservation dont la `Vente` rattachée est **encore `en_cours`** (jamais payée) ne la
     nettoie pas non plus : un panier fantôme peut rester ouvert (RG-RESAENC-10, gap identifié).
  4. La création d'une réservation payante **exige aujourd'hui une session de caisse déjà fournie dans
     la requête** (sinon 422) — aucun chemin de réservation en ligne (sans agent/guichet) n'est câblé
     pour un produit payant (§7 cas limite, dépendance boutique/M3).

## 1. Objectif
Garantir que **réserver un produit/une ressource payant(e) entraîne toujours un montant à encaisser
clairement identifié**, encaissable **au guichet comme une vente normale** (même session de caisse,
même Clôture Z, même régie, mêmes règles NF525) — sans mécanisme de facturation parallèle — et que le
**statut de la réservation reflète fidèlement** si elle est encaissée ou non, y compris à l'annulation.

## 2. Périmètre
- **Inclus :**
  - Calcul du **montant dû** d'une réservation payante (réutilise `RG-M5-02`, tarif de référence M1).
  - Génération d'une **Vente M2 encaissable** rattachée à la réservation, dans une session de caisse
    ouverte (réutilise le patron déjà branché `VenteReservationHandler`).
  - **Statut de paiement observable** de la réservation (« à payer » / « payée » / « sans objet »),
    dérivé de `Reservation.modeDecompte` + `Vente.statut` (RG-RESAENC-03).
  - **Entrée en régie** : comment la recette d'une réservation encaissée est comptée dans la session de
    caisse et la Clôture Z, comme n'importe quelle vente (RG-RESAENC-04), **sans double comptage** si
    une facture justificative est ensuite éditée sur ce ticket (articulation `RG-FACT-03`).
  - **Annulation d'une réservation payée** → avoir/remboursement cohérent (RG-RESAENC-09, gap comblé).
  - **Annulation d'une réservation à payer non encore encaissée** → nettoyage de la vente pendante
    (RG-RESAENC-10, gap comblé).
  - **Produit gratuit** → aucun encaissement (comportement déjà en place, confirmé §4.6).
  - **Articulation** (signalée, non détaillée) avec l'**encaissement en ligne** via `App\PaiementDistance`
    (lien de paiement sur la `Vente` rattachée) — RG-RESAENC-05.
  - **Articulation** (signalée, non détaillée) avec la **facturation à terme** via `App\Facturation`
    (facture directe, cas régie/DSP sans encaissement guichet) — RG-RESAENC-07.
- **Exclu (pour l'instant) :**
  - Le **no-show / l'annulation tardive facturée** (`RG-M5-09`, stratégies `FacturationNoShow`) — **déjà
    spécifié** dans `specs/reservation/spec-reservation.md` §4.7/4.8, **non modifié ici** ; cette spec
    vérifie seulement la **non-régression** (§6, CA-6).
  - Le **détail du paiement à distance/en ligne** (jeton, page publique, relances, expiration) — couvert
    par `App\PaiementDistance`, hors périmètre de détail ici (simple point d'articulation, RG-RESAENC-05).
  - Le **détail de la facturation à terme** (numérotation légale, TVA, Chorus Pro) — couvert par
    `specs/facturation/spec-facturation.md`, simple point d'articulation ici (RG-RESAENC-07/08).
  - La **résolution complète de la grille tarifaire M1** (quotient familial, promotions, cartes
    multi-entrées) sur le montant dû d'une réservation — le code actuel utilise un **montant de
    référence figé** (`Activite.tarifReferenceMontant`), divergence déjà documentée dans
    `VenteReservationHandler` et héritée telle quelle ici (§7 cas limite).
  - Le **paiement partagé entre participants** (`RG-M5-10`, `ParticipantReservation`) au-delà du constat
    que son articulation avec une `Vente` M2 unique n'est **pas connectée** aujourd'hui (§7 cas limite).
  - Toute **nouvelle écriture comptable** — ce module ne fait que garantir qu'une `Vente` standard est
    bien créée/validée ; le reste (journal, PCA, Clôture Z) est **entièrement** `specs/L2-vente/spec-vente.md`
    et `specs/L4-compta/spec-compta.md`, non redéfini.

## 3. Acteurs & droits
Réutilise strictement les permissions déjà déclarées par M5 (`reservation × …`) et M2 (`vente × …`,
`caisse × …`) — **aucune nouvelle permission** n'est introduite par ce lien.

| Acteur | Peut | Permission (module × action) |
|---|---|---|
| **Agent d'accueil / guichet** | Réserver un créneau payant pour un bénéficiaire avec sa session de caisse ouverte (déclenche la Vente rattachée), encaisser cette Vente (paiement + validation), annuler une réservation (dans/hors délai) | `reservation.reserver`, `vente.encaisser`, `reservation.annuler` |
| **Régisseur** | Ouvrir/clôturer la session de caisse qui reçoit la recette des réservations encaissées, consulter l'état de régie | `caisse.ouvrir`, `caisse.cloturer`, `caisse.lire` |
| **Client / Organisateur (en ligne)** | Réserver pour lui-même (place retenue), voir le statut « à payer »/« payée » de sa réservation, régler à distance via un lien de paiement s'il en reçoit un (M2/PaiementDistance) | `reservation.reserver_soi`, `reservation.lire_soi` |
| **Responsable / Administrateur** | Rembourser/annuler une Vente rattachée à une réservation payée annulée (contre-passation), consulter tout historique | `vente.rembourser`, `vente.annuler`, `vente.lire` |
| **Système** | Créer automatiquement la Vente rattachée à la confirmation d'une réservation payante sans quota ; ne **jamais** valider/encaisser seul (aucun encaissement automatique sans agent ni PMV déjà branché, cf. no-show) | *(acteur technique)* |

- ⚠ HYPOTHÈSE — Comme pour M5 (`spec-reservation.md` §3), ces noms de permission ne sont pas nommés
  littéralement dans `cahier-detaille.html` pour ce lien précis ; ils **reprennent** les permissions
  déjà en place côté M5/M2, sans en créer de nouvelle.

## 4. Comportements & règles

### 4.1 Montant dû calculé (RG-RESAENC-01, réutilise RG-M5-02)
- Le **montant dû** d'une réservation est dérivé du **tarif de référence** du créneau/de l'activité
  (`Creneau::tarifReference()` → `Activite.tarifReferenceMontant`, éventuellement `produitTarifReference`
  vers M1) : **0,00 €** si le produit est gratuit, sinon le tarif de référence.
- Ce montant est **calculé une seule fois**, à la réservation, et **figé** sur `Reservation.montantDu`
  (comportement déjà en place) : une évolution ultérieure de la grille tarifaire ne modifie **pas**
  rétroactivement une réservation déjà créée.

### 4.2 Génération d'une Vente encaissable en caisse (RG-RESAENC-02, réutilise le patron no-show)
- Quand le montant dû est **strictement positif** **et** qu'aucun quota de formule n'est disponible
  (`RG-M5-02`), la réservation **génère une `Vente` M2 réelle**, rattachée (`Reservation.venteRattachee`),
  dans la **session de caisse ouverte** du contexte (`App\Reservation\Service\VenteReservationHandler`,
  déjà branché) : une ligne unique, prix **forcé** au montant dû, note libellée (« Réservation … »),
  liée au produit de tarif de référence si connu.
- Ce mécanisme est **le même patron** que celui utilisé pour la facturation d'un no-show
  (`VenteDiffereeAgentStrategie`) : ce module **ne duplique aucune logique**, il **réutilise le même
  point d'entrée** (`VenteReservationHandler::creerVente`) au moment de la réservation elle-même plutôt
  qu'au moment d'un no-show.
- La **réservation reste confirmée** (place retenue, jauge décomptée) dès sa création, **que la Vente
  rattachée soit déjà encaissée ou pas encore** — la confirmation de la place et l'encaissement effectif
  sont **deux évènements distincts** (comportement déjà en place, cohérent avec un flux guichet où
  l'agent peut réserver puis encaisser dans la foulée sans latence perceptible pour l'usager) ; c'est le
  **statut de paiement dérivé** (§4.3), pas le statut de la réservation, qui porte l'information « payée
  ou pas encore ».

### 4.3 Statut de paiement — « à payer » / « payée » / « sans objet » (RG-RESAENC-03) `nouveau`
- Un **statut de paiement observable**, **dérivé** (pas un nouveau champ métier indépendant qui pourrait
  diverger de la vérité caisse) de deux informations déjà portées par les entités existantes :
  - `Reservation.modeDecompte = gratuit` **ou** `quota_formule` → **`sans_objet`** (aucun encaissement
    attendu, cf. §4.6) ;
  - `Reservation.modeDecompte = vente_unite` **et** `venteRattachee.statut = en_cours` → **`à_payer`** ;
  - `Reservation.modeDecompte = vente_unite` **et** `venteRattachee.statut ∈ {validee, avoir_emis}` →
    **`payée`** (le passage à `avoir_emis` reste « payée puis remboursée », cf. RG-RESAENC-09, pas un
    retour à « à payer ») ;
  - `Reservation.modeDecompte = vente_unite` **et** `venteRattachee.statut = annulee` → **`annulée`**
    (cf. RG-RESAENC-10).
- ⚠ HYPOTHÈSE — Ce statut est décrit ici comme un **comportement observable** (ce que l'écran/l'API
  doivent exposer), **pas** comme une prescription d'implémentation : il peut être calculé à la volée
  (méthode dérivée sur `Reservation`, comme `occupePlace()` existe déjà pour `StatutReservation`) ou
  matérialisé en base pour la performance de filtrage — choix laissé au plan technique, à condition que
  la source de vérité reste **toujours** `Vente.statut` (jamais un second état qui pourrait diverger).

### 4.4 Entrée en caisse/régie — aucune écriture parallèle (RG-RESAENC-04)
- L'**encaissement au guichet** d'une réservation suit **exactement** le flux Vente standard, inchangé :
  `POST /ventes/{id}/paiements` (paiement scindé jusqu'à reste dû = 0, `RG-M2-03`) puis
  `POST /ventes/{id}/valider` (scellement NF525, `RG-M2-07`). Dès la validation, la recette est comptée
  dans la **session de caisse courante** et remonte dans la **Clôture Z / état de régie** (`RG-M2-06`)
  **comme n'importe quelle vente** — **aucun mécanisme de comptabilisation parallèle** n'est introduit
  pour une réservation.
- Le **ticket / support** (billet, QR d'accès) peut être émis à la validation de la Vente, selon les
  mêmes règles que toute vente M2 (`spec-vente.md` §4.6) — ce module ne redéfinit pas l'émission de
  support.
- **Facture justificative a posteriori** — une fois la Vente validée, un agent peut demander une
  **Facture justificative** dessus (`App\Facturation`, `EmettreFactureJustificativeProcessor`,
  `RG-FACT-03.1`) exactement comme pour tout ticket M2 : la Facture justificative **ne recrée ni titre
  de recette ni recette supplémentaire** (`RG-FACT-03`, cohérent) — la réservation encaissée en caisse
  reste comptabilisée **une seule fois**, au moment de la Vente.

### 4.5 Encaissement en ligne — articulation signalée (RG-RESAENC-05) `hors détail`
- Quand le règlement au guichet n'est pas possible (réservation en ligne sans agent présent), la
  `Vente` rattachée (déjà créée par le mécanisme §4.2, sous réserve d'une session technique disponible,
  cf. §7 cas limite) peut être réglée **à distance** via `App\PaiementDistance` : une `DemandePaiement`
  de type `typeCible = vente`, `identifiantCible = Reservation.venteRattachee.id` (le type `vente` de
  `CibleDemandePaiement` existe **déjà**, aucune extension de schéma nécessaire) est créée et envoyée au
  client ; le règlement effectif suit le cycle de vie standard de `App\PaiementDistance` (jeton, page
  publique, expiration/relance), **non détaillé ici** (cf. `specs/` du module PaiementDistance s'il en
  existe une, sinon comportement du code en l'état).
- Dès le règlement à distance constaté (mécanisme propre à `PaiementDistance`), la `Vente` rattachée
  doit être **validée** (même effet que §4.4) pour que la réservation passe « payée » — l'**intégration
  exacte** (qui valide la Vente au retour du webhook/callback du prestataire) est un **point ouvert**
  (§7, non câblé à ce jour entre les deux modules).

### 4.6 Produit gratuit — pas d'encaissement (RG-RESAENC-06)
- Si le tarif de référence est **nul ou absent**, la réservation est **confirmée directement**
  (`modeDecompte = gratuit`, `montantDu = 0.00`), **aucune Vente n'est créée**, statut de paiement dérivé
  = **`sans_objet`** — comportement **déjà en place**, confirmé et non modifié par cette spec.

### 4.7 Facturation à terme — alternative signalée, non câblée (RG-RESAENC-07) `hors détail`
- Pour un établissement qui **facture** un usage plutôt que de l'**encaisser au guichet** (ex. régie
  directe facturant une collectivité/un groupe, DSP), l'alternative est une **Facture directe**
  (`App\Facturation`, `origine = vente_a_terme`) plutôt qu'une Vente immédiate — ce chemin **existe**
  côté Facturation (`CreerFactureDirecteProcessor`) mais **n'est pas relié structurellement** à une
  Réservation aujourd'hui (pas de champ `Reservation.factureRattachee`). ⚠ HYPOTHÈSE — hors périmètre du
  patron principal « guichet » de cette spec ; à spécifier séparément si un cas d'usage l'exige
  (ex. réservation de créneaux par une collectivité tierce, facturée mensuellement).

### 4.8 Annulation d'une réservation payée → avoir/remboursement cohérent (RG-RESAENC-09) `critique, gap comblé`
- **Dans le délai franc** (annulation libre, `RG-M5-04`) d'une réservation dont la `Vente` rattachée est
  **déjà validée** (statut de paiement `payée`) : l'annulation **doit** déclencher un **avoir de
  remboursement** sur la Vente rattachée, pour le **montant total** de la réservation, via le mécanisme
  de contre-passation déjà existant (`ContrePassationHandler::rembourser`, `RG-M2-07`) — **la place/le
  quota est libérée en même temps** (comportement déjà en place, RG-M5-04), et la Vente passe à
  `avoir_emis`. **C'est le principal écart identifié entre le comportement observé du code (§0) et le
  comportement attendu** : à ce jour, `AnnulerReservationProcessor` ne déclenche **aucun** remboursement.
- **Hors délai franc** (annulation tardive) : le comportement **ne change pas** — `RG-M5-09` s'applique
  telle quelle (bascule en `annulée_tardive_facturée`, `FacturationNoShow` créée selon la
  `RegleAnnulation` résolue) ; **aucun remboursement automatique** de la Vente déjà encaissée n'est dû
  dans ce cas (l'exploitant garde la recette au titre de l'annulation tardive) — cohérent avec le
  principe déjà acté « passé le délai, le quota/la place est perdu » (`spec-reservation.md` §4.7).

### 4.9 Annulation d'une réservation « à payer » non encore encaissée (RG-RESAENC-10) `gap comblé`
- Si la `Vente` rattachée est **encore `en_cours`** (jamais validée, aucun règlement enregistré) au
  moment de l'annulation : **aucun avoir n'est nécessaire** (rien n'a été encaissé), mais la Vente
  pendante **doit être nettoyée** (vidée/annulée) pour ne **pas** laisser un panier fantôme visible côté
  caisse — sans quoi un agent pourrait par erreur l'encaisser après coup pour une place qui n'existe
  plus. ⚠ HYPOTHÈSE — le mécanisme exact (vider les lignes via `ViderPanierProcessor` puis laisser la
  Vente orpheline `en_cours` à 0,00 €, vs. un état `annulée` explicite) n'est **pas tranché** par les
  sources ; retenu par défaut : marquer la Vente `annulée` (même sémantique que `AnnulerVenteProcessor`,
  sans générer d'Avoir puisqu'aucun règlement n'existait — `ContrePassationHandler::annuler` exige
  aujourd'hui une Vente déjà `validee`, donc **pas directement réutilisable en l'état** pour une Vente
  `en_cours` : point à trancher au plan technique, cf. §7).

### 4.10 No-show / annulation tardive — non-régression (réutilise RG-M5-09) `inchangé`
- Ce lien **ne modifie en rien** le mécanisme de no-show/annulation tardive déjà spécifié
  (`spec-reservation.md` §4.7/4.8, `RG-M5-09`, stratégies `VenteDiffereeAgentStrategie`/`DebitPmvStrategie`/
  `PrelevementDiffereStrategie`/`FactureAEncaisserStrategie`) : une réservation qui bascule en no-show ou
  annulation tardive facturée continue de suivre **exactement** ce mécanisme, que sa Vente initiale
  (si `vente_unite`) ait été payée ou non. Le seul point de vigilance (non un changement de comportement) :
  si la réservation était déjà **payée** puis bascule en no-show *(cas rare : réservation payée mais
  bénéficiaire absent malgré tout)*, **aucun remboursement ni double facturation** n'a lieu — la recette
  initiale reste acquise, et `RG-M5-09` ne s'applique **pas** une seconde fois sur une place déjà réglée
  intégralement (⚠ HYPOTHÈSE de non-cumul, cohérente avec le point ouvert déjà noté dans
  `spec-reservation.md` §7 pour la solidarité de l'organisateur).

## 5. Objets de données
Aucune nouvelle entité persistée n'est introduite par cette spec (principe de réutilisation maximale) ;
les objets ci-dessous sont **déjà définis** ailleurs et **réutilisés tels quels**, sauf mention contraire.

| Objet | Champ | Type | Contraintes | Notes |
|---|---|---|---|---|
| **Reservation** *(existant, `App\Reservation\Entity\Reservation`, `spec-reservation.md` §5)* | modeDecompte | enum {quota_formule, vente_unite, gratuit} | requis | RG-M5-02, inchangé |
| | montantDu | decimal ≥ 0 | requis | RG-RESAENC-01 |
| | venteRattachee | ref Vente (M2)? | requis si `vente_unite` | RG-RESAENC-02, déjà en place |
| | statutPaiement *(dérivé, non nécessairement persisté)* | enum {sans_objet, à_payer, payée, annulée} | **calculé**, jamais source de vérité indépendante | RG-RESAENC-03, **nouveau comportement observable** |
| **Vente** *(existant, `App\Vente\Entity\Vente`, `spec-vente.md` §5)* | statut | enum {en_cours, validee, annulee, avoir_emis} | requis | RG-M2-01/07, inchangé — source de vérité du paiement |
| | session | ref SessionCaisse | requis | RG-M2-01, garantit l'entrée en régie |
| | lignes[] | LigneVente[] | 1 ligne pour une réservation | prix forcé au montant dû (§4.2) |
| **SessionCaisse** *(existant, `App\Caisse\Entity\SessionCaisse`)* | — | — | — | Aucun changement ; réutilisée telle quelle pour recevoir la Vente |
| **Avoir** *(existant, `App\Vente\Entity\Avoir`)* | venteOrigine | ref Vente | requis | RG-RESAENC-09, émis à l'annulation d'une réservation payée dans le délai franc |
| | nature | string {annulation, remboursement} | requis | `remboursement` pour ce cas (§4.8) |
| **FacturationNoShow** *(existant, `App\Reservation\Entity\FacturationNoShow`, `spec-reservation.md` §5)* | — | — | — | Inchangé (§4.10), non couvert par ce lien |
| **Facture** *(existant, `App\Facturation\Entity\Facture`)* | origine | enum {ticket_encaisse, vente_a_terme} | — | `ticket_encaisse` pour une facture justificative a posteriori d'une réservation payée (§4.4) ; `vente_a_terme` pour l'alternative facturation à terme (§4.7, non câblée) |
| **DemandePaiement** *(existant, `App\PaiementDistance\Entity\DemandePaiement`)* | typeCible | enum {devis, vente, libre} | — | `vente` réutilisé tel quel pour régler une `venteRattachee` à distance (RG-RESAENC-05) |
| | identifiantCible | uuid? | requis si `typeCible = vente` | = `Reservation.venteRattachee.id` |

## 6. Critères d'acceptation
- **CA-1 (US-RESAENC-01, RG-RESAENC-01/02, RG-M5-02)** — *Étant donné* un créneau **payant** sans quota
  de formule disponible pour le bénéficiaire, et une session de caisse ouverte, *quand* la réservation
  est créée, *alors* `Reservation.modeDecompte = vente_unite`, `montantDu` = tarif de référence, une
  `Vente` M2 est créée et **rattachée** (`venteRattachee`), avec une ligne unique au montant dû, statut
  `Vente = en_cours`, et le **statut de paiement dérivé** de la réservation = **`à_payer`**.
- **CA-2 (RG-RESAENC-03/04)** — *Étant donné* une réservation « à payer » (CA-1), *quand* l'agent
  encaisse la Vente rattachée au guichet (`POST /ventes/{id}/paiements` jusqu'à reste dû = 0, puis
  `POST /ventes/{id}/valider`), *alors* `Vente.statut = validee` (scellée NF525), le **statut de paiement
  dérivé** de la réservation passe à **`payée`**, et la recette est comptée dans la **session de caisse
  courante** (visible dans son cumul, remontera dans la prochaine Clôture Z, `RG-M2-06`).
- **CA-3 (US-RESAENC-03, RG-RESAENC-06)** — *Étant donné* un créneau **gratuit** (tarif de référence nul),
  *quand* la réservation est créée, *alors* `modeDecompte = gratuit`, `montantDu = 0.00`, **aucune Vente**
  n'est créée, statut de paiement dérivé = **`sans_objet`**.
- **CA-4 (US-RESAENC-04, RG-RESAENC-09)** — *Étant donné* une réservation **payée** (Vente rattachée
  `validee`), *quand* le bénéficiaire (ou un agent) l'annule **dans le délai franc**, *alors* un **Avoir
  de remboursement** est émis sur la Vente rattachée pour le **montant total**, la Vente passe à
  `avoir_emis`, la place/le quota est **libérée**, et la réservation passe à `annulée_libre`.
- **CA-5 (RG-RESAENC-09)** — *Étant donné* une réservation **payée**, *quand* elle est annulée **hors
  délai franc** (annulation tardive), *alors* **aucun remboursement** n'est déclenché (comportement
  `RG-M5-09` inchangé) : la recette initiale reste acquise, la réservation bascule en
  `annulée_tardive_facturée` selon la `RegleAnnulation` résolue.
- **CA-6 (RG-RESAENC-10)** — *Étant donné* une réservation « à payer » dont la Vente rattachée est
  **encore `en_cours`** (aucun règlement enregistré), *quand* elle est annulée, *alors* **aucun Avoir**
  n'est généré, mais la Vente pendante est **nettoyée** (marquée `annulée` ou vidée), pour qu'aucune
  recette fantôme ne subsiste en caisse.
- **CA-7 (RG-RESAENC-04, non-régression)** — *Étant donné* une réservation encaissée en caisse (CA-2),
  *quand* un agent demande a posteriori une **Facture justificative** sur la Vente rattachée
  (`POST /factures/depuis-vente`), *alors* la Facture est émise `acquittee` immédiatement, **sans générer
  de nouvelle écriture comptable** ni de nouvelle recette (`RG-FACT-03.1`) : la réservation reste
  comptabilisée **une seule fois**.
- **CA-8 (RG-RESAENC-05, articulation)** — *Étant donné* une réservation « à payer » sans agent au
  guichet, *quand* une `DemandePaiement` de type `vente` référençant `venteRattachee` est créée et
  réglée par le client via le lien de paiement, *alors* la Vente rattachée est validée par le mécanisme
  standard de `PaiementDistance`, et le statut de paiement dérivé de la réservation passe à `payée` —
  **au même titre** qu'un règlement guichet (aucune divergence de source de vérité).
- **CA-9 (§4.10, non-régression)** — *Étant donné* une réservation dont la Vente initiale a été
  **entièrement payée**, *quand* elle bascule malgré tout en no-show (bénéficiaire absent après
  paiement), *alors* **aucune nouvelle facturation** ni remboursement automatique n'est déclenché par ce
  module : le comportement `RG-M5-09` s'applique tel quel, sans interaction supplémentaire avec le
  paiement déjà encaissé.

## 7. Cas limites
- **Réservation en ligne d'un produit payant, sans session de caisse ouverte fournie** — Le code actuel
  (`ReserverProcessor`) **exige** une session de caisse dans la requête pour toute réservation payante
  sans quota ; sans elle, la réservation est **refusée** (422). Aucun chemin « réservation en ligne, sans
  agent, sans session physique » n'est câblé à ce jour pour un produit payant — la réservation en ligne
  d'un produit payant suppose soit (a) une **session technique** dédiée (même patron que
  `SessionSystemeResolver` utilisé pour `DebitPmvStrategie`), soit (b) une articulation avec le module
  **boutique** (M3/M8-boutique) qui n'est **pas spécifiée ici**. ⚠ point ouvert majeur pour toute
  réservation self-service payante hors guichet.
- **Paiement partiel / acompte** — `RG-M2-03` permet un paiement scindé mais **exige reste dû = 0** pour
  valider (sauf « paiement différé » avec justificatif non acquitté, mécanisme distinct). Il n'existe
  **aucune notion d'acompte formalisée** pour une réservation (« 30 % à la réservation, solde au
  guichet ») — ⚠ HYPOTHÈSE : non couvert, à spécifier séparément si le besoin se confirme (nécessiterait
  soit un montant dû partiel dédié, soit un paiement différé assumé avec un solde suivi).
- **Réservation multi-créneaux (récurrence, `RG-M5-07`)** — Chaque **occurrence** est une `Reservation`
  distincte (comportement déjà acté par M5) ; par cohérence, **chaque occurrence payante génère sa
  propre Vente** (pas de vente groupée multi-lignes pour une série) — ⚠ HYPOTHÈSE, non tranchée
  explicitement par les sources ; à confirmer si un usage (ex. abonnement à un cours collectif récurrent)
  demande une facturation groupée plutôt qu'à l'occurrence.
- **Tarif variable (quotient familial, promotion, carte multi-entrées, M1)** — Le montant dû actuel
  repose sur `Activite.tarifReferenceMontant`, un **montant de référence figé**, **pas** la résolution
  complète de la grille tarifaire M1 (`ResolveurPrix`) — divergence déjà documentée dans le code
  (`VenteReservationHandler`, commentaire de classe) et **héritée telle quelle** par cette spec ; un
  bénéficiaire éligible à un tarif réduit paiera aujourd'hui le tarif plein de référence lors d'une
  réservation. ⚠ à trancher avec M1 si le produit doit refléter le tarif exact du bénéficiaire.
- **Paiement partagé (`RG-M5-10`) combiné à l'encaissement caisse** — Le modèle actuel ne rattache
  **qu'une seule** `Vente` à la réservation (`venteRattachee` 1:1), alors que `ParticipantReservation`
  porte une part de paiement **par participant**. L'articulation entre plusieurs parts individuelles et
  une Vente unique en caisse **n'est pas connectée** dans le code (déjà noté comme point ouvert dans
  `spec-reservation.md` §4.10 : « référence M2 pour l'encaissement effectif de chaque part », non
  détaillé). ⚠ HYPOTHÈSE : hors périmètre de cette spec, à traiter dans une itération dédiée si le
  paiement partagé doit réellement passer par des encaissements caisse distincts par participant.
- **Annulation d'une réservation « à payer » dont la Vente porte déjà un règlement partiel** (paiement
  scindé commencé mais non finalisé, `Vente.statut` toujours `en_cours`) — ⚠ HYPOTHÈSE : traité comme
  RG-RESAENC-10 (nettoyage), mais un **remboursement du/des règlement(s) déjà enregistrés** doit alors
  être envisagé même si la Vente n'est jamais passée `validee` — cas non couvert par
  `ContrePassationHandler` en l'état (qui exige `Vente.statut ∈ {validee, avoir_emis}`) ; **à trancher au
  plan technique**, potentiel point de fragilité si un agent encaisse un acompte puis annule avant
  validation.
- **Émission du ticket/droit d'accès avant encaissement effectif** — La projection d'un droit d'accès
  optionnel (`RG-M5-12`, `ProjectionAccesReservationHandler`) est déclenchée **à la confirmation** de la
  réservation, **indépendamment** du statut de paiement dérivé (§4.3) — dans le code actuel c'est un
  **no-op documenté** (Risque n°2 du plan M5, projection non câblée vers L3), donc **aucune régression**
  observable aujourd'hui ; mais **le jour où** cette projection sera réellement câblée, il faudra
  trancher si l'accès doit être **conditionné** au statut `payée` (⚠ point à ne pas oublier lors du
  câblage L3, hors périmètre de cette spec).

## 8. Dépendances
- **Dépend de : M5 · Réservation** (`specs/reservation/spec-reservation.md`) — `Reservation`, `Creneau`,
  `Activite`, `RegleAnnulation`, décompte quota/vente à l'unité (`RG-M5-02`), délai franc d'annulation
  (`RG-M5-04`), no-show/annulation tardive facturée (`RG-M5-09`, **non modifié**). Ce module **complète**
  cette spec sur le seul lien caisse, il ne la redéfinit pas.
- **Dépend de : M2 · Vente & Caisse** (`specs/L2-vente/spec-vente.md`) — `Vente`, `SessionCaisse`,
  `Avoir`, paiement scindé (`RG-M2-03`), scellement/immuabilité NF525 (`RG-M2-07`), Clôture Z / état de
  régie (`RG-M2-06`), mode dégradé hors-ligne (`RG-M2-08`, hérité sans modification pour une réservation
  encaissée en mode dégradé). C'est le **module qui encaisse et trace**, ce lien ne fait que
  **déclencher** au bon moment (même principe déjà acté dans `spec-reservation.md` §8).
- **Dépend de : M1 · Offre & Tarification** (`specs/L1-offre/spec-offre.md`) — tarif de référence source
  du montant dû (§7 cas limite : résolution simplifiée aujourd'hui, pas la grille complète).
- **Dépend de (articulation, non détaillée) : Facturation** (`specs/facturation/spec-facturation.md`) —
  Facture justificative a posteriori d'une Vente issue d'une réservation (`RG-FACT-03.1`, §4.4) ;
  Facture directe comme alternative non câblée à l'encaissement guichet (`RG-FACT-03.2`, §4.7).
- **Dépend de (articulation, non détaillée) : PaiementDistance** (`app/src/PaiementDistance/**`) —
  réglage à distance de la Vente rattachée via `DemandePaiement{typeCible: vente}` (§4.5, RG-RESAENC-05).
- **Dépend de : M4 · CRM** (`specs/L5-crm/spec-crm.md`) — `Beneficiaire`/`Client` porteur de la
  réservation et destinataire de la Vente ; porte-monnaie virtuel (PMV) éventuellement recrédité par un
  avoir de remboursement (`ContrePassationHandler::recrediterPmv`, réutilisé tel quel).
- **Dépend de (non détaillé) : M6 · Compta & Régie** (`specs/L4-compta/spec-compta.md`) — toute Vente
  validée issue d'une réservation alimente le journal/la régie **exactement** comme une vente standard ;
  ce module ne redéfinit aucune écriture (§4.4).

---

## Points ouverts / hypothèses (récapitulatif)

### Écarts identifiés entre comportement attendu et code actuel (à corriger)
1. **Aucun statut de paiement observable** sur `Reservation` — RG-RESAENC-03, à exposer (calculé ou
   persisté, choix technique).
2. **Annulation d'une réservation payée ne déclenche aujourd'hui aucun avoir/remboursement**
   (`AnnulerReservationProcessor` ne touche jamais `venteRattachee`) — RG-RESAENC-09, **le gap principal
   signalé par le commanditaire**, priorité de correction.
3. **Annulation d'une réservation « à payer » non encore encaissée ne nettoie pas la Vente pendante** —
   RG-RESAENC-10, risque de panier fantôme visible en caisse.

### Points ouverts fonctionnels (à trancher avec le produit)
4. **Acompte / paiement partiel d'une réservation** — non couvert par `RG-M2-03` tel quel (§7).
5. **Réservation en ligne d'un produit payant sans session de caisse** — aucun chemin câblé (§7),
   nécessite soit une session technique, soit une articulation boutique (M3/M8) non spécifiée ici.
6. **Réservation multi-créneaux (récurrence) — une Vente par occurrence ou vente groupée ?** — retenu
   par défaut « une Vente par occurrence », à confirmer (§7).
7. **Paiement partagé (`RG-M5-10`) non connecté à l'encaissement caisse unique** — hérité comme point
   ouvert déjà identifié dans `spec-reservation.md`, non résolu ici (§7).
8. **Remboursement d'un règlement partiel sur une Vente jamais validée** — `ContrePassationHandler`
   n'accepte aujourd'hui que des Ventes `validee`/`avoir_emis` ; un acompte partiel sur une Vente restée
   `en_cours` n'a pas de mécanisme de remboursement dédié (§7).
9. **Facturation à terme (Facture directe) comme alternative à l'encaissement guichet** — chemin
   existant côté Facturation mais non relié structurellement à `Reservation` (§4.7, RG-RESAENC-07).
