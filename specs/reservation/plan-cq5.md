# Plan technique — CQ-5 : no-show, l'issue sur le crédit (`RG-CQ5-01..10`)

- **Spec source :** `specs/reservation/spec-cq5-noshow-credit.md`
- **Stack :** Symfony 7 · API Platform · Doctrine/MariaDB
- **Modules touchés :** `App\Reservation` (nouveau service, extension d'entités existantes, aucune
  nouvelle route), `App\Acces` (lu/écrit directement — `DroitAcces`/`Appairage`/`Support` — **aucun
  fichier `App\Acces\*` modifié**, même précédent de couplage que `ProjectionAccesReservationHandler`),
  `COORDINATION/CONTRACT/catalogue-evenements.md` (catalogue).
- **Décisions déjà tranchées, non rouvertes ici** (rappel D24/D27/D22/D19/D3/D8/D2/D5/D7 + arbitrages de
  l'intégrateur en amont de ce plan) :
  1. Enum `IssueCreditNoShow` (`Decremented`/`Restored`/`RestoredWithReschedule`) sur `RegleAnnulation`,
     colonne `enumType` NOT NULL, défaut `restored_with_reschedule`.
  2. Résolution : `ResolveurRegleAnnulation` réutilisé **tel quel**, non modifié.
  3. Application dans `DeclencherFacturationNoShowHandler::declencher()`, après la `FacturationNoShow`,
     **orthogonale** à `modeFacturation`.
  4. Restitution atomique du crédit — patron SQL exact `CardRechargeHandler`/`ValidationPassageHandler`
     (`UPDATE ... credit_restant = credit_restant + 1` + `$em->refresh()`), seulement si un `DroitAcces`
     créditable est rattaché ; sinon no-op documenté.
  5. Événements : `booking.no_show`/`booking.cancelled` étendus (additif) ; nouvel événement
     `booking.reschedule_requested` (consommateur futur Smart Flow SF-2, zéro consommateur aujourd'hui).
  6. Dégradation D27 : l'API annonce la restitution du crédit, jamais un créneau/une date — deux
     booléens API distincts.
  7. Tests construits avec un `DroitAcces` créditable factice (fixtures directes), pas de dépendance à
     CQ-3/CQ-6.

  Ce plan **implémente** ces décisions et **tranche** les points laissés ouverts par la spec (§10) :
  nom de propriété PHP retenu (`issueCreditNoShow`, §10 pt.2 — la spec elle-même le retient déjà), nom
  du champ API de dégradation (`rescheduleRequested`, §10 pt.1), portée du service
  (`App\Reservation\Service`, §10 pt.5). Il ne rouvre **pas** l'hypothèse §3.3 (sens de « décompté » vs
  « restitué ») — il l'implémente en la signalant explicitement comme risque n°1 (§8).

## 0. Ce qui ne change PAS (rappel, pour cadrer la revue)

- `ModeFacturationNoShow`, `FacturationNoShow.venteRattachee`/`referenceEcheanceSepa`, les stratégies de
  facturation (`ResolveurStrategieFacturation`, `StrategieFacturationNoShow`) — **fichiers inchangés**,
  aucun diff dessus. Axe orthogonal (D24), preuve par CA-10.
- `ResolveurRegleAnnulation::resoudre()` — **fichier inchangé**. La précédence
  activité > ressource > type_ressource > établissement s'applique telle quelle au nouvel axe parce que
  la méthode retourne l'objet `RegleAnnulation` entier (les deux axes voyagent ensemble), pas un champ
  isolé.
- `ProjectionAccesReservationHandler` — **fichier inchangé**. `projeterSiApplicable()`/
  `revoquerSiProjete()` ne sont pas touchés ; ce lot **lit** `ProjectionAccesReservation.droitAccesRef`
  et le `DroitAcces` qu'il désigne, exactement comme `revoquerSiProjete()` le fait déjà
  (`ProjectionAccesReservationHandler.php:115-121`).
- Aucune nouvelle route API Platform, aucune nouvelle permission `module.action`.
- `App\Acces\Service\CardRechargeHandler`/`ValidationPassageHandler`/`VersionSnapshotSequencer` —
  **fichiers inchangés**, seul le **patron** SQL est repris (RG-CQ5-05, la spec l'exclut explicitement
  de la réutilisation directe : signature liée à `BilletSupport`/`Vente`).

## 1. Entités & schéma

| Entité (`App\<Module>\Entity\*`) | Champ | Type Doctrine | Null | Index/Contrainte | Relation |
|---|---|---|---|---|---|
| `App\Reservation\Entity\RegleAnnulation` | `issueCreditNoShow` | `IssueCreditNoShow` (enumType, `length: 24`) | non | — | même rang que `modeFacturation`, défaut applicatif `RestoredWithReschedule` |
| `App\Reservation\Enum\IssueCreditNoShow` (nouvel enum) | — | `string` : `decremented`\|`restored`\|`restored_with_reschedule` | — | — | `App\Reservation\Enum`, anglais (D5) |
| `App\Reservation\Entity\FacturationNoShow` | `issueCreditNoShow` | `?IssueCreditNoShow` (enumType, `length: 24`, **nullable**) | oui | — | copie figée de la valeur appliquée ; `null` pour les lignes historiques (avant ce lot) et pour les no-show sans règle active (RG-CQ5-03) |
| `App\Reservation\Entity\FacturationNoShow` | `creditActionne` | `bool` (`options: ['default' => false]`) | non | — | un `DroitAcces` créditable a-t-il été trouvé ? (RG-CQ5-04) |
| `App\Reservation\Entity\FacturationNoShow` | `creditRestitue` | `bool` (`options: ['default' => false]`) | non | — | `true` seulement si `Restored`/`RestoredWithReschedule` **et** l'`UPDATE` a effectivement affecté une ligne |
| `App\Reservation\Entity\FacturationNoShow` | `droitAccesRestitueRef` | `?Uuid`, **transient, non mappé** (aucun `#[ORM\Column]`) | — | — | portée process/requête uniquement — voir §3.3, sert à construire `booking.reschedule_requested` sans re-résoudre le droit côté appelant |
| `App\Acces\Entity\DroitAcces` | `creditRestant` | `?int` (existant, inchangé) | oui | — | **lu** (RG-CQ5-04) et **écrit** en `UPDATE` SQL brut (RG-CQ5-05) ; `null` = pas de crédit, no-op |
| `App\Acces\Entity\Appairage`/`Support` | — (existants, inchangés) | — | — | — | `Support.versionMaj` bousculé via `VersionSnapshotSequencer` si un `Appairage` actif existe pour le droit restitué (même garde que D23) |

`id` = UUID partout (existant). Rattachement multi-entités : `RegleAnnulation.etablissement` et
`FacturationNoShow` (via `Reservation.etablissement`) inchangés ; le `DroitAcces` manipulé porte déjà
`etablissement = Reservation.etablissement` (invariant posé à la création par
`ProjectionAccesReservationHandler::projeterSiApplicable()` — non revérifié ici en écriture, seulement
en lecture défensive, §3.2).

**Décision sur le nom de propriété (§10 pt.2 de la spec, tranchée ici)** : `issueCreditNoShow` — mixte
français/anglais, cohérent avec le reste de l'entité (`modeFacturation`, `margePostCreneauMinutes`) et
avec la propre recommandation de la spec. La tension avec D5 (anglais pour tout identifiant **neuf**)
est réelle mais déjà actée pour `RegleAnnulation` dans son ensemble (entité historiquement française) ;
seuls les **valeurs** de l'enum (`decremented`, `restored`, `restored_with_reschedule`) et le nom de la
classe enum (`IssueCreditNoShow`) sont strictement anglais, ce qui suffit à respecter D5 pour le code
réellement nouveau (le nom de la classe/les valeurs stockées, pas le nom du getter PHP local).

## 2. API (API Platform)

| Ressource | Opérations | security: | Groupes sérialisation | Filtres |
|---|---|---|---|---|
| `ReservationRegleAnnulation` (existante, `RegleAnnulation`) | `GetCollection`/`Get`/`Post`/`Patch` (inchangées) | inchangé (`reservation.lire` / `reservation.parametrer_annulation`) | `issueCreditNoShow` ajouté à `regle_annulation:read` **et** `regle_annulation:write` | inchangés |
| `ReservationFacturationNoShow` (existante, `FacturationNoShow`) | `GetCollection`/`Get`/`Post .../exonerer`/`Post .../emettre-vente` (inchangées) | inchangé | `issueCreditNoShow`, `creditActionne`, `creditRestitue`, `rescheduleRequested` (calculé, lecture seule) ajoutés à `facturation_no_show:read` | inchangés |

**Aucune nouvelle ressource, aucune nouvelle opération.** Les deux ressources existantes gagnent des
champs, exposés sur les mêmes opérations déjà sécurisées — c'est la totalité du changement de surface
API (RG-CQ5-01, RG-CQ5-10).

Champ calculé `rescheduleRequested` (RG-CQ5-09, nom tranché ici — §10 pt.1 de la spec) : **non
persisté**, un simple getter sur `FacturationNoShow` exposé au groupe `facturation_no_show:read` :

```php
#[Groups(['facturation_no_show:read'])]
public function isRescheduleRequested(): bool
{
    return $this->issueCreditNoShow === IssueCreditNoShow::RestoredWithReschedule
        && $this->creditActionne
        && $this->creditRestitue;
}
```

Choix du nom : anglais (D5, champ neuf), miroir exact du nom de l'événement `booking.reschedule_requested`
qu'il reflète — `true` si et seulement si l'événement a réellement été publié pour cette facturation.
**Jamais** de champ suggérant une date/un créneau (RG-CQ5-09) : ni `rescheduleDate`, ni `slotProposed`,
ni équivalent — seul `rescheduleRequested` (un signal a été émis) coexiste avec `creditRestitue` (le
solde a bougé), les deux visibles et distincts dès ce lot.

## 3. Conception détaillée

### 3.1 Enum — `App\Reservation\Enum\IssueCreditNoShow` (nouveau)

**Fichier neuf :** `app/src/Reservation/Enum/IssueCreditNoShow.php`.

```php
<?php

declare(strict_types=1);

namespace App\Reservation\Enum;

/**
 * Issue du crédit sur un no-show / une annulation tardive facturée (RG-CQ5-01, D24 — second axe
 * orthogonal à `ModeFacturationNoShow`, ne PAS fusionner). Paramétrable aux 4 portées de
 * `RegleAnnulation`, défaut `RestoredWithReschedule` (D27). ⚠ Le sens de `Decremented` vs `Restored`
 * repose sur l'hypothèse §3.3 de la spec (décompte au booking, pas au passage) — à confirmer par
 * CQ-3/CQ-6, cf. plan §8 risque n°1.
 */
enum IssueCreditNoShow: string
{
    /** Le crédit déjà pris (hypothèse §3.3) reste pris : aucune écriture supplémentaire. */
    case Decremented = 'decremented';

    /** Le crédit est rendu — `+1` atomique, sans promesse de report. */
    case Restored = 'restored';

    /** Même restitution que `Restored`, plus publication de `booking.reschedule_requested` (D27, SF-2 absent). */
    case RestoredWithReschedule = 'restored_with_reschedule';
}
```

### 3.2 `App\Reservation\Entity\RegleAnnulation` — nouveau champ

**Fichier modifié :** `app/src/Reservation/Entity/RegleAnnulation.php`. Ajout **au même rang** que
`modeFacturation` (ligne 93-95 actuelle) :

```php
#[ORM\Column(length: 24, enumType: IssueCreditNoShow::class)]
#[Groups(['regle_annulation:read', 'regle_annulation:write', 'facturation_no_show:read'])]
private IssueCreditNoShow $issueCreditNoShow = IssueCreditNoShow::RestoredWithReschedule;

public function getIssueCreditNoShow(): IssueCreditNoShow
{
    return $this->issueCreditNoShow;
}

public function setIssueCreditNoShow(IssueCreditNoShow $issueCreditNoShow): self
{
    $this->issueCreditNoShow = $issueCreditNoShow;

    return $this;
}
```

Import supplémentaire : `App\Reservation\Enum\IssueCreditNoShow`. Le groupe `facturation_no_show:read`
sur ce champ (comme `modeFacturation` déjà, ligne 94) permet à `FacturationNoShow.regleAppliquee` de
projeter la règle **au moment de sa lecture** — utile pour comparer « ce que dirait la règle aujourd'hui »
vs `FacturationNoShow.issueCreditNoShow` (« ce qui a réellement été décidé », RG-CQ5-06). CA-3 (défaut
sur création) est directement couvert par la valeur par défaut de la propriété PHP — aucune logique
supplémentaire nécessaire côté `Post`.

### 3.3 `App\Reservation\Entity\FacturationNoShow` — trois champs + un getter calculé

**Fichier modifié :** `app/src/Reservation/Entity/FacturationNoShow.php`.

```php
#[ORM\Column(length: 24, nullable: true, enumType: IssueCreditNoShow::class)]
#[Groups(['facturation_no_show:read'])]
private ?IssueCreditNoShow $issueCreditNoShow = null;

#[ORM\Column(options: ['default' => false])]
#[Groups(['facturation_no_show:read'])]
private bool $creditActionne = false;

#[ORM\Column(options: ['default' => false])]
#[Groups(['facturation_no_show:read'])]
private bool $creditRestitue = false;

/**
 * Référence du DroitAcces effectivement restitué — TRANSIENT, jamais persisté (aucun `#[ORM\Column]`).
 * Peuplé uniquement dans le même appel PHP que `AppliquerIssueCreditNoShowHandler::appliquer()`
 * (DeclencherFacturationNoShowHandler::declencher()), pour que l'appelant (BasculerNoShowCommand /
 * AnnulerReservationProcessor) puisse construire le payload de `booking.reschedule_requested` SANS
 * relire `ProjectionAccesReservation` une seconde fois. Redevient `null` après un `find()`/`refresh()`
 * ultérieur — usage strictement synchrone, jamais lu après un `em->clear()`.
 */
private ?Uuid $droitAccesRestitueRef = null;

public function getIssueCreditNoShow(): ?IssueCreditNoShow { /* getter standard */ }
public function setIssueCreditNoShow(?IssueCreditNoShow $issueCreditNoShow): self { /* … */ }
public function isCreditActionne(): bool { /* … */ }
public function setCreditActionne(bool $creditActionne): self { /* … */ }
public function isCreditRestitue(): bool { /* … */ }
public function setCreditRestitue(bool $creditRestitue): self { /* … */ }
public function getDroitAccesRestitueRef(): ?Uuid { /* … */ }
public function setDroitAccesRestitueRef(?Uuid $droitAccesRestitueRef): self { /* … */ }

#[Groups(['facturation_no_show:read'])]
public function isRescheduleRequested(): bool
{
    return $this->issueCreditNoShow === IssueCreditNoShow::RestoredWithReschedule
        && $this->creditActionne
        && $this->creditRestitue;
}
```

Import supplémentaire : `App\Reservation\Enum\IssueCreditNoShow`, `Symfony\Component\Uid\Uuid` (déjà
importé pour `$id`).

**Pourquoi un champ transient plutôt qu'une 4ᵉ colonne ?** La spec (§6, tableau des objets de données)
ne liste que trois champs persistés sur `FacturationNoShow`. `droitId` n'est nécessaire que le temps de
construire le payload de `booking.reschedule_requested`, **dans le même appel** que celui qui vient de
le produire (RG-CQ5-08 : « émis depuis le même appelant que `booking.no_show`/`booking.cancelled` »,
donc juste après le retour de `declencher()`, avant tout `em->clear()`). Le persister ajouterait une
colonne d'audit hors périmètre décrit par la spec pour un besoin strictement transitoire — signalé
explicitement ici pour que la revue de code le valide ou demande sa persistance si un futur besoin
d'audit (« quel droit exact a été crédité ? ») apparaît (§8 risque n°6).

### 3.4 Frontière module — écriture directe de `App\Acces\Entity\DroitAcces` depuis `App\Reservation`

Confirmé par lecture de `ProjectionAccesReservationHandler` (§0) : le patron « `App\Reservation` lit et
écrit directement `App\Acces\Entity\DroitAcces`/`Appairage`/`Support`, sans port ni interface, docblock
explicite justifiant l'absence de modification côté `App\Acces` » est déjà en place et accepté pour
ACC-3. Ce lot **réutilise exactement ce patron**, pas de port `ProjectionDroitInterface` ni d'interface
nouvelle : `AppliquerIssueCreditNoShowHandler` (nouveau, §3.5) importe directement
`App\Acces\Entity\DroitAcces`/`Appairage`/`Support` et `App\Acces\Service\VersionSnapshotSequencer`
(service déjà public/autowirable, utilisé tel quel — pas de duplication).

Ce choix diffère de `CardRechargeHandler` (qui, lui, est appelé **depuis** `App\Vente` via un port
`CardRechargeInterface` parce que `App\Acces` ne doit pas dépendre de `App\Vente`) : ici la dépendance va
dans le même sens que ACC-3 (`App\Reservation` → `App\Acces`), déjà établie et documentée comme
acceptable pour ce couplage précis (accès en lecture/écriture à `DroitAcces` depuis une réservation).
Aucun nouveau port n'est introduit.

### 3.5 Service — `App\Reservation\Service\AppliquerIssueCreditNoShowHandler` (nouveau)

**Fichier neuf :** `app/src/Reservation/Service/AppliquerIssueCreditNoShowHandler.php`. Portée
`App\Reservation\Service` (tranche §10 pt.5 de la spec, en faveur de Réservation) : précédent direct
`ProjectionAccesReservationHandler`, et centraliser côté `App\Acces` inverserait la direction de
dépendance établie par ACC-3 (`App\Reservation` connaît déjà `App\Acces\Entity\DroitAcces`, l'inverse
n'est pas vrai et ne doit pas le devenir).

**Fichier neuf associé (value object) :** `app/src/Reservation/Service/ResultatIssueCreditNoShow.php` —
non-Doctrine, readonly, pas de `#[ORM\Column]` :

```php
<?php

declare(strict_types=1);

namespace App\Reservation\Service;

use Symfony\Component\Uid\Uuid;

/** Résultat de l'application d'une IssueCreditNoShow (RG-CQ5-04/05) — pas une entité, jamais persisté. */
final class ResultatIssueCreditNoShow
{
    private function __construct(
        public readonly bool $creditActionne,
        public readonly bool $creditRestitue,
        public readonly ?Uuid $droitId,
    ) {
    }

    /** RG-CQ5-04 : aucun DroitAcces créditable trouvé (pas de projection, ou creditRestant === null). */
    public static function sansCredit(): self
    {
        return new self(false, false, null);
    }

    /** RG-CQ5-05 issue `Decremented` : un droit créditable existe, aucune écriture supplémentaire. */
    public static function decompte(Uuid $droitId): self
    {
        return new self(true, false, $droitId);
    }

    /** RG-CQ5-05 issue `Restored`/`RestoredWithReschedule`, écriture réussie. */
    public static function restitue(Uuid $droitId): self
    {
        return new self(true, true, $droitId);
    }
}
```

**Le handler :**

```php
<?php

declare(strict_types=1);

namespace App\Reservation\Service;

use App\Acces\Entity\Appairage;
use App\Acces\Entity\DroitAcces;
use App\Acces\Entity\Support;
use App\Acces\Service\VersionSnapshotSequencer;
use App\Reservation\Entity\ProjectionAccesReservation;
use App\Reservation\Entity\Reservation;
use App\Reservation\Enum\IssueCreditNoShow;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Applique l'IssueCreditNoShow résolue par RegleAnnulation à la Reservation qui bascule en no-show /
 * annulation tardive facturée (RG-CQ5-04/05). Lit/écrit directement App\Acces\Entity\DroitAcces —
 * même précédent de couplage que ProjectionAccesReservationHandler (§3.4 du plan) : aucun fichier
 * App\Acces\* n'est modifié pour ce comportement.
 *
 * N'est appelé qu'APRÈS que DeclencherFacturationNoShowHandler ait persisté+flushé la FacturationNoShow
 * (RG-CQ5-07, point d'idempotence — voir §3.6 du plan) : un second appel pour la même réservation
 * n'atteint jamais ce service, la contrainte unique reservation_id ayant déjà fait échouer le flush
 * précédent.
 */
final class AppliquerIssueCreditNoShowHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $connection,
        private readonly VersionSnapshotSequencer $sequencer,
    ) {
    }

    public function appliquer(Reservation $reservation, IssueCreditNoShow $issue): ResultatIssueCreditNoShow
    {
        // RG-CQ5-04 — résolution du droit créditable, même repository lookup que
        // ProjectionAccesReservationHandler::revoquerSiProjete() (ligne 115-119).
        $projection = $this->em->getRepository(ProjectionAccesReservation::class)
            ->findOneBy(['reservation' => $reservation]);
        if (!$projection instanceof ProjectionAccesReservation || $projection->getDroitAccesRef() === null) {
            return ResultatIssueCreditNoShow::sansCredit(); // aucune projection — cas universel (§3.2 spec).
        }

        $droit = $this->em->getRepository(DroitAcces::class)->find($projection->getDroitAccesRef());
        if (!$droit instanceof DroitAcces || $droit->getCreditRestant() === null) {
            return ResultatIssueCreditNoShow::sansCredit(); // projeté mais sans crédit — cas universel Booking.
        }

        // RG-CQ5-10 — garde défensive de cloisonnement : ne devrait JAMAIS déclencher, puisque
        // ProjectionAccesReservationHandler pose systématiquement droit.etablissement =
        // reservation.etablissement à la création (invariant ACC-3). Échec fermé documenté (no-op,
        // pas d'exception) plutôt qu'un throw qui interromprait tout le batch BasculerNoShowCommand
        // pour une seule réservation suspecte.
        if ((string) $droit->getEtablissement()?->getId() !== (string) $reservation->getEtablissement()?->getId()) {
            return ResultatIssueCreditNoShow::sansCredit();
        }

        if ($issue === IssueCreditNoShow::Decremented) {
            // RG-CQ5-05 — sous l'hypothèse §3.3 (décompte au booking), le crédit déjà pris reste pris :
            // AUCUNE écriture. Volontairement symétrique et indépendant du point de décompte réel.
            return ResultatIssueCreditNoShow::decompte($droit->getId());
        }

        // Restored / RestoredWithReschedule — même patron atomique que CardRechargeHandler:139-159 /
        // ValidationPassageHandler:188-204 : UPDATE SQL conditionnel, jamais de read-modify-write.
        $droitId = $droit->getId();
        $restitue = false;

        $this->connection->transactional(function () use ($droit, $droitId, &$restitue): void {
            $affectees = (int) $this->connection->executeStatement(
                'UPDATE acces_droit_acces SET credit_restant = credit_restant + 1 WHERE id = UNHEX(:hex) AND credit_restant IS NOT NULL',
                ['hex' => bin2hex($droitId->toBinary())],
            );
            if ($affectees === 0) {
                // Garde de concurrence (§8 cas limite spec) : le crédit a disparu entre la lecture
                // RG-CQ5-04 ci-dessus et cet UPDATE (théorique, aucun chemin connu aujourd'hui). On ne
                // met PAS $restitue à true — creditActionne restera true (un droit a été trouvé),
                // creditRestitue false : traçable, pas silencieux (cf. §8 risque n°5 du plan).
                return;
            }

            // Mirage en mémoire par RECHARGEMENT (refresh), jamais par calcul relatif — même garde que
            // CardRechargeHandler:159 contre l'écrasement d'une écriture concurrente par un flush()
            // Doctrine basé sur une valeur périmée.
            $this->em->refresh($droit);
            $restitue = true;

            // RG-CQ5-05 dernier alinéa — si un Appairage actif existe pour ce droit, un terminal
            // hors-ligne doit voir le nouveau solde (même règle que D23).
            $appairage = $this->em->getRepository(Appairage::class)
                ->findOneBy(['droit' => $droit, 'actif' => true]);
            $support = $appairage?->getSupport();
            if ($support instanceof Support) {
                $version = $this->sequencer->suivant();
                $this->connection->executeStatement(
                    'UPDATE acces_support SET version_maj = :v WHERE id = UNHEX(:hex)',
                    ['v' => $version, 'hex' => bin2hex($support->getId()->toBinary())],
                );
                $support->setVersionMaj($version);
            }

            $this->em->flush();
        });

        return $restitue
            ? ResultatIssueCreditNoShow::restitue($droitId)
            : ResultatIssueCreditNoShow::sansCredit();
    }
}
```

**Points à ne pas rater à l'implémentation :**
- L'`UPDATE` est **inconditionnel côté plancher** (pas de `AND credit_restant > :plancher` comme
  `ValidationPassageHandler` en décompte) : incrémenter n'a pas de plancher naturel. Le garde-fou
  `AND credit_restant IS NOT NULL` protège uniquement contre la course théorique décrite ci-dessus.
- `$em->refresh($droit)` **avant** toute lecture de `$droit->getCreditRestant()` par un appelant — même
  remarque que `CardRechargeHandler:159`.
- Le service **ne publie aucun événement** — il retourne un DTO pur ; c'est
  `DeclencherFacturationNoShowHandler` (§3.6) puis, plus haut, `BasculerNoShowCommand`/
  `AnnulerReservationProcessor` (§3.7/3.8) qui construisent et publient (D7, D22 : jamais avant que le
  travail annoncé soit réellement acquis).

### 3.6 `App\Reservation\Service\DeclencherFacturationNoShowHandler::declencher()` — modification

**Fichier modifié :** `app/src/Reservation/Service/DeclencherFacturationNoShowHandler.php`.

Nouvelle dépendance injectée : `AppliquerIssueCreditNoShowHandler $appliquerCredit` (autowire standard).

```php
public function declencher(Reservation $reservation, StatutReservation $statutCible): ?FacturationNoShow
{
    $creneau = $reservation->getCreneau();
    $regle = $creneau !== null ? $this->resolveur->resoudre($creneau) : null;

    $reservation->setStatut($statutCible);
    $this->projectionAcces->revoquerSiProjete($reservation);

    if ($regle === null) {
        $this->em->flush();

        return null; // RG-CQ5-03 : aucune règle active -> aucune décision de crédit, dans un sens comme dans l'autre.
    }

    $facturation = new FacturationNoShow();
    $facturation->setReservation($reservation)
        ->setRegleAppliquee($regle)
        ->setMontant($regle->montantCalcule($creneau?->tarifReference() ?? '0.00'))
        ->setStatut(StatutFacturationNoShow::AFacturer);
    $this->em->persist($facturation);
    // RG-CQ5-07 — POINT D'IDEMPOTENCE : ce flush() exécute l'INSERT et fait respecter
    // uniq_facturation_no_show_reservation. Un second appel pour la même réservation échoue ICI (avant
    // toute écriture de crédit) et propage l'exception — AppliquerIssueCreditNoShowHandler::appliquer()
    // n'est alors jamais atteint une seconde fois (CA-8).
    $this->em->flush();

    // RG-CQ5-03/04/05 — appliqué seulement APRÈS la création réussie de la FacturationNoShow.
    $resultat = $this->appliquerCredit->appliquer($reservation, $regle->getIssueCreditNoShow());
    $facturation->setIssueCreditNoShow($regle->getIssueCreditNoShow())
        ->setCreditActionne($resultat->creditActionne)
        ->setCreditRestitue($resultat->creditRestitue)
        ->setDroitAccesRestitueRef($resultat->droitId); // transient, RG-CQ5-08 (payload booking.reschedule_requested)
    $this->em->flush();

    // Modes sans agent (débit automatique) : tentative immédiate. Inchangé — axe orthogonal (D24, CA-10).
    if ($regle->getModeFacturation() === ModeFacturationNoShow::DebitPmv) {
        $strategie = $this->strategies->pour(ModeFacturationNoShow::DebitPmv->value);
        $strategie?->appliquer($facturation, null);
    }

    return $facturation;
}
```

Nouveaux imports : `App\Reservation\Service\AppliquerIssueCreditNoShowHandler` (constructeur).

**Pourquoi deux `flush()` séparés et pas un seul ?** C'est précisément ce qui rend RG-CQ5-07 correct
sans dépendre d'une transaction explicite englobante (qui n'existe pas ici, comme documenté pour
`CardRechargeHandler` §8 risque n°2 de `plan-cq1.md`) : le premier flush est le **seul** point où la
contrainte unique peut être violée ; tout ce qui suit ne s'exécute que si ce flush a réussi, donc au
plus une fois par réservation. Fusionner les deux flushes ferait courir le risque qu'un intégrateur
retire par erreur ce garde-fou d'ordre lors d'un futur refactor sans s'en rendre compte.

### 3.7 `App\Reservation\Command\BasculerNoShowCommand` — extension des événements

**Fichier modifié :** `app/src/Reservation/Command/BasculerNoShowCommand.php`, bloc `booking.no_show`
(lignes 90-116 actuelles). `$facturation` est déjà capturé (ligne 82) — aucun changement de signature
nécessaire, seulement le payload et un nouvel envoi conditionnel juste après :

```php
$etablissementNoShow = $reservation->getEtablissement();
if ($etablissementNoShow !== null) {
    $this->eventBus->publish(new DomainEvent(
        'booking.no_show',
        new EventTenant($etablissementNoShow->getId()),
        new EventSubject('Reservation', (string) $reservation->getId()),
        [
            'customerId' => (string) $reservation->getOrganisateur()?->getId(),
            'amountAtRisk' => $facturation?->getMontant() ?? '0.00',
            'hasBillingRule' => $facturation !== null,
            'slotId' => (string) $creneau->getId(),
            // RG-CQ5-08 — extension additive. `creditIssue` absent (pas juste `null` dans le JSON
            // final : la clé n'est écrite que si une règle a été résolue) si $facturation === null.
            ...($facturation !== null ? [
                'creditIssue' => $facturation->getIssueCreditNoShow()?->value,
                'creditRestoredAmount' => ($facturation->isCreditActionne() && $facturation->isCreditRestitue()) ? 1 : 0,
            ] : []),
        ],
    ));

    // RG-CQ5-08 — booking.reschedule_requested : publié UNIQUEMENT si issue = RestoredWithReschedule
    // ET creditActionne ET creditRestitue (renforcement volontaire par rapport à la lettre de
    // RG-CQ5-08, qui ne mentionne littéralement que creditActionne=true — cf. §8 risque n°2 du plan :
    // jamais annoncer un report pour un crédit qui, en pratique, n'a pas bougé, même dans le cas
    // théorique de course concurrente §3.5). Émis depuis CE call site, jamais depuis
    // DeclencherFacturationNoShowHandler (RG-CQ5-08, même raison que booking.cancelled/booking.no_show
    // déjà actée dans AnnulerReservationProcessor:104-106).
    if ($facturation?->getIssueCreditNoShow() === IssueCreditNoShow::RestoredWithReschedule
        && $facturation->isCreditActionne() && $facturation->isCreditRestitue()) {
        $this->eventBus->publish(new DomainEvent(
            'booking.reschedule_requested',
            new EventTenant($etablissementNoShow->getId()),
            new EventSubject('Reservation', (string) $reservation->getId()),
            [
                'customerId' => (string) $reservation->getOrganisateur()?->getId(),
                'reservationRef' => (string) $reservation->getId(),
                'slotId' => (string) $creneau->getId(),
                'droitId' => (string) $facturation->getDroitAccesRestitueRef(),
            ],
        ));
    }
}
```

Nouvel import : `App\Reservation\Enum\IssueCreditNoShow`.

### 3.8 `App\Reservation\State\AnnulerReservationProcessor` — extension (branche tardive)

**Fichier modifié :** `app/src/Reservation/State/AnnulerReservationProcessor.php`.

1. Capturer le retour de `declencher()` (actuellement ignoré, ligne 78) :

```php
$facturationNoShow = null;
if ($dansDelai) {
    $data->setStatut(StatutReservation::AnnuleeLibre);
    $this->em->flush();
    $this->projectionAcces->revoquerSiProjete($data);
} else {
    $facturationNoShow = $this->facturationHandler->declencher($data, StatutReservation::AnnuleeTardiveFacturee);
}
```

2. Étendre le payload de `booking.cancelled` (lignes 110-125 actuelles) — **seulement branche tardive**
   (RG-CQ5-08 : « la branche libre n'a jamais résolu de `RegleAnnulation` ») :

```php
$etablissement = $data->getEtablissement();
if ($creneau !== null && $etablissement !== null) {
    $this->eventBus->publish(new DomainEvent(
        'booking.cancelled',
        new EventTenant($etablissement->getId()),
        new EventSubject('Reservation', (string) $data->getId()),
        [
            'slotId' => (string) $creneau->getId(),
            'leadTimeMinutes' => max(0, (int) round(
                ($creneau->getDebut()->getTimestamp() - $maintenant->getTimestamp()) / 60
            )),
            'withinFreeWindow' => $dansDelai,
            ...(!$dansDelai && $facturationNoShow !== null ? [
                'creditIssue' => $facturationNoShow->getIssueCreditNoShow()?->value,
                'creditRestoredAmount' => ($facturationNoShow->isCreditActionne() && $facturationNoShow->isCreditRestitue()) ? 1 : 0,
            ] : []),
        ],
        new EventActor($utilisateur->getId()),
    ));

    // RG-CQ5-08 — même condition et même acteur que booking.cancelled ci-dessus, cohérence avec §3.7.
    if (!$dansDelai
        && $facturationNoShow?->getIssueCreditNoShow() === IssueCreditNoShow::RestoredWithReschedule
        && $facturationNoShow->isCreditActionne() && $facturationNoShow->isCreditRestitue()) {
        $this->eventBus->publish(new DomainEvent(
            'booking.reschedule_requested',
            new EventTenant($etablissement->getId()),
            new EventSubject('Reservation', (string) $data->getId()),
            [
                'customerId' => (string) $data->getOrganisateur()?->getId(),
                'reservationRef' => (string) $data->getId(),
                'slotId' => (string) $creneau->getId(),
                'droitId' => (string) $facturationNoShow->getDroitAccesRestitueRef(),
            ],
            new EventActor($utilisateur->getId()),
        ));
    }
}
```

Nouvel import : `App\Reservation\Enum\IssueCreditNoShow`. C'est ce qui couvre **CA-9** : même handler
partagé (`DeclencherFacturationNoShowHandler::declencher()`), donc même mouvement de crédit qu'un
no-show automatique, orchestré par un point d'appel différent.

### 3.9 Catalogue — `COORDINATION/CONTRACT/catalogue-evenements.md`

Deux lignes existantes mises à jour (colonne « Key payload », additif) + une ligne neuve ajoutée, **avant
implémentation** (D2) :

```diff
- | `booking.cancelled` | Reservation | slotId, leadTimeMinutes, withinFreeWindow | **Smart Flow**, Revenue Recovery |
- | `booking.no_show` | Reservation | customerId, amountAtRisk, hasBillingRule, slotId | **Revenue Recovery**, Smart Flow |
+ | `booking.cancelled` | Reservation | slotId, leadTimeMinutes, withinFreeWindow, creditIssue?, creditRestoredAmount? | **Smart Flow**, Revenue Recovery |
+ | `booking.no_show` | Reservation | customerId, amountAtRisk, hasBillingRule, slotId, creditIssue?, creditRestoredAmount? | **Revenue Recovery**, Smart Flow |
+ | `booking.reschedule_requested` | Reservation (CQ-5) | customerId, reservationRef, slotId, droitId | **Smart Flow** (SF-2, zéro consommateur aujourd'hui) |
```

(`?` = présent seulement quand une `RegleAnnulation` a été résolue / restitution effective — cohérent
avec la légende déjà utilisée ailleurs au catalogue, ex. `newExpiryAt?` ligne `access.card_recharged`.)

### 3.10 Dégradation D27 (RG-CQ5-09) — récapitulatif de ce que l'API dit et ne dit jamais

| Champ API (`FacturationNoShow`) | Signifie | Ne signifie PAS |
|---|---|---|
| `creditRestitue: true` | Le solde a été incrémenté de 1, fait accompli et vérifiable | — |
| `rescheduleRequested: true` | Un signal (`booking.reschedule_requested`) a été émis vers un mécanisme qui n'existe pas encore (SF-2) | Qu'un créneau va être proposé, qu'une date existe, qu'un rappel est garanti |
| `issueCreditNoShow: 'restored_with_reschedule'` | La règle configurée demande une restitution + un report | Que le report a eu lieu — c'est `rescheduleRequested`/`creditActionne`/`creditRestitue` qui portent le fait réel |

Aucune autre surface (back-office, notification client) n'est construite par ce lot (§2 exclusions de
la spec) — la dégradation ne concerne donc que ces trois champs API, déjà couverts par CA-4.

## 4. Migrations

**Fichier neuf :** `app/migrations/Version20260824090000.php`.

```php
public function getDescription(): string
{
    return 'CQ-5 : IssueCreditNoShow sur RegleAnnulation (défaut restored_with_reschedule) + traçabilité '
        . 'crédit sur FacturationNoShow (issueCreditNoShow/creditActionne/creditRestitue).';
}

public function up(Schema $schema): void
{
    // RegleAnnulation — colonne NOT NULL avec DEFAULT constant (D27) : contrairement au backfill
    // conditionnel de Version20260815092253 (statut dérivé d'une AUTRE colonne), ici la valeur de
    // repli est la même pour toutes les lignes existantes -> un simple ADD COLUMN ... NOT NULL DEFAULT
    // suffit et reste rejouable (MariaDB remplit les lignes existantes avec le DEFAULT à l'ALTER).
    $this->addSql("ALTER TABLE reservation_regle_annulation ADD issue_credit_no_show VARCHAR(24) DEFAULT 'restored_with_reschedule' NOT NULL");

    // FacturationNoShow — nullable pour issue_credit_no_show (aucune règle active possible, RG-CQ5-03 ;
    // et rows historiques antérieures à ce lot, sans valeur connue). credit_actionne/credit_restitue
    // NOT NULL DEFAULT 0 : correct pour l'historique -- le mécanisme n'existait pas avant ce lot, donc
    // aucun crédit n'a JAMAIS été actionné/restitué par le passé (§3.2 spec), pas seulement un défaut
    // technique arbitraire.
    $this->addSql('ALTER TABLE reservation_facturation_no_show ADD issue_credit_no_show VARCHAR(24) DEFAULT NULL, ADD credit_actionne TINYINT DEFAULT 0 NOT NULL, ADD credit_restitue TINYINT DEFAULT 0 NOT NULL');
}

public function down(Schema $schema): void
{
    $this->addSql('ALTER TABLE reservation_facturation_no_show DROP issue_credit_no_show, DROP credit_actionne, DROP credit_restitue');
    $this->addSql('ALTER TABLE reservation_regle_annulation DROP issue_credit_no_show');
}
```

**À relire ligne à ligne avant merge (consigne explicite du mandat)** : les deux `ALTER TABLE` ne
touchent que `reservation_regle_annulation`/`reservation_facturation_no_show` — aucun index, aucune
contrainte, aucune colonne d'un autre module n'est déplacée ni droppée. Pas de `CREATE TABLE`, pas de
nouvelle FK. `uniq_facturation_no_show_reservation` (contrainte pivot de RG-CQ5-07) n'est pas touchée.

## 5. Sécurité & droits

- **Aucune nouvelle permission.** `reservation.parametrer_annulation` (existante, `Post`/`Patch` de
  `RegleAnnulation`) couvre l'écriture de `issueCreditNoShow` ; `reservation.lire`/`compta.lire`
  (existantes) couvrent sa lecture et celle des 4 champs ajoutés sur `FacturationNoShow`.
- **Aucun voter nouveau.** Le cloisonnement établissement du `DroitAcces` manipulé (RG-CQ5-10) est un
  contrôle métier défensif dans `AppliquerIssueCreditNoShowHandler` (§3.5), pas une règle de permission
  — même patron que le reste du module (`ProjectionAccesReservationHandler` n'a pas de voter dédié non
  plus).
- `AppliquerIssueCreditNoShowHandler`/`ResultatIssueCreditNoShow` : classes de service sans attribut
  `security:` ni `is_granted()` — aucune surface API nouvelle, aucune permission française référencée
  depuis un fichier neuf autre que celles déjà en place sur `RegleAnnulation`/`FacturationNoShow`.
- Aucun identifiant fourni par le client n'entre dans la résolution du `DroitAcces` créditable
  (RG-CQ5-10) — entièrement interne, déclenché par `BasculerNoShowCommand` (tâche planifiée) ou
  `AnnulerReservationProcessor` (agent déjà authentifié pour l'annulation elle-même).

## 6. Tâches (ordonnées)

| # | Tâche | Fichiers | Dépend de |
|---|---|---|---|
| T1 | Ajouter `booking.reschedule_requested` au catalogue + étendre les 2 lignes existantes (§3.9) | `COORDINATION/CONTRACT/catalogue-evenements.md` | — |
| T2 | Créer l'enum `IssueCreditNoShow` (§3.1) | `app/src/Reservation/Enum/IssueCreditNoShow.php` | — |
| T3 | Étendre `RegleAnnulation` (§3.2) | `app/src/Reservation/Entity/RegleAnnulation.php` | T2 |
| T4 | Étendre `FacturationNoShow` (§3.3) | `app/src/Reservation/Entity/FacturationNoShow.php` | T2 |
| T5 | Migration (§4) | `app/migrations/Version20260824090000.php` | T3, T4 |
| T6 | Créer `ResultatIssueCreditNoShow` + `AppliquerIssueCreditNoShowHandler` (§3.5) | `app/src/Reservation/Service/ResultatIssueCreditNoShow.php`, `app/src/Reservation/Service/AppliquerIssueCreditNoShowHandler.php` | T2, T3 |
| T7 | Modifier `DeclencherFacturationNoShowHandler::declencher()` (§3.6) | `app/src/Reservation/Service/DeclencherFacturationNoShowHandler.php` | T4, T6 |
| T8 | Étendre `BasculerNoShowCommand` (§3.7) | `app/src/Reservation/Command/BasculerNoShowCommand.php` | T7 |
| T9 | Étendre `AnnulerReservationProcessor` (§3.8) | `app/src/Reservation/State/AnnulerReservationProcessor.php` | T7 |
| T10 | Tests unitaires `AppliquerIssueCreditNoShowHandler` (§7) | `app/tests/Reservation/Unit/AppliquerIssueCreditNoShowHandlerTest.php` | T6 |
| T11 | Collecteur d'événements de test (`NoShowCreditEventCollector`, même patron que `CardRechargedEventCollector`) + câblage `services.yaml` `when@test:` | `app/tests/Reservation/Support/NoShowCreditEventCollector.php`, `app/config/services.yaml` | T1 |
| T12 | Tests fonctionnels API CA-1..10 (§7) | `app/tests/Reservation/Api/IssueCreditNoShowTest.php` | T5, T8, T9, T11 |
| T13 | Non-régression — relancer `App\Tests\Reservation\**` complet (en particulier `AnnulationNoShowTest`, `ProjectionAccesTest`, `FacturationNoShowStrategiesTest`, `CloisonnementTest`) | — | T5-T12 |

## 7. Tests

Fixtures : réutiliser `App\Tests\Reservation\ReservationApiTestCase` (charge déjà les fixtures socle +
Réservation, dont la `RegleAnnulation` établissement de démonstration et le `terrain`
`RESSOURCE_TERRAIN_LIBELLE`). **Aucun crédit réel n'existe aujourd'hui (§3.2/§9 spec)** : chaque test
qui a besoin d'un `DroitAcces` créditable le construit **directement** — même posture que
`CardRechargeHandler` testé avant que CQ-6 n'existe côté vente (plan-cq1.md §7). Scénario de base
commun à la plupart des cas : 1) `terrain.setOuvreAcces(true)` + `flush()`, 2) créer un créneau, 3)
réserver dessus (déclenche `ProjectionAccesReservationHandler`, crée un `DroitAcces` `Booking` avec
`creditRestant = null`), 4) **relire ce `DroitAcces` en base et forcer `creditRestant`** (ex. `3`) — la
fixture factice mandatée par la décision 6 du mandat, 5) attacher une `RegleAnnulation` portant
l'`issueCreditNoShow` voulue sur la portée pertinente (Activité/Ressource/Établissement), 6) basculer
via `BasculerNoShowCommand::basculer()` (auto) ou `POST .../annuler` hors délai franc (agent, CA-9).

| Test | Type | Couvre |
|---|---|---|
| `testDecrementedAucuneEcritureNiRestitution` | Unitaire (`AppliquerIssueCreditNoShowHandlerTest`) | RG-CQ5-05 branche `Decremented` : `creditRestant` inchangé en base, `ResultatIssueCreditNoShow::creditActionne=true`, `creditRestitue=false` |
| `testRestoredIncrementeAtomiquementEtRafraichit` | Unitaire | RG-CQ5-05 branche `Restored` : `+1` en base, `$droit` en mémoire reflète la nouvelle valeur après l'appel (preuve du `refresh()`) |
| `testAppairageActifBasculeVersionMajSupport` | Unitaire | RG-CQ5-05 dernier alinéa : un `Appairage actif=true` sur le droit restitué fait avancer `Support.versionMaj` |
| `testAucuneProjectionRetourneSansCredit` | Unitaire | RG-CQ5-04 cas 1 : aucune `ProjectionAccesReservation` -> `sansCredit()`, aucun `UPDATE` exécuté (assertion sur le SQL logué ou sur la valeur en base inchangée) |
| `testDroitProjeteSansCreditRetourneSansCredit` | Unitaire | RG-CQ5-04 cas 2 : `creditRestant = null` -> `sansCredit()` |
| `testCloisonnementEtablissementDefensifNoOp` | Unitaire | RG-CQ5-10 garde défensive : `DroitAcces` d'un autre établissement que la réservation (construit artificiellement) -> `sansCredit()`, pas d'exception |
| `testCa1RestoredRestitueCreditEtEvenement` | Fonctionnel API | CA-1 : `creditRestant` 3→4, `FacturationNoShow.creditRestitue=true`, `booking.no_show.payload.creditIssue='restored'`/`creditRestoredAmount=1` (via collecteur T11) |
| `testCa2DecrementedLaisseCreditInchange` | Fonctionnel API | CA-2 : `creditRestant` reste 3, `creditActionne=true`/`creditRestitue=false`, `creditIssue='decremented'`/`creditRestoredAmount=0` |
| `testCa3DefautRestoredWithRescheduleSansPrecision` | Fonctionnel API | CA-3 : `POST /api/reservation_regle_annulations` sans `issueCreditNoShow` -> `restored_with_reschedule` en retour |
| `testCa4RestoredWithRescheduleEvenementPublieAucunCreneauPropose` | Fonctionnel API | CA-4 : `creditRestant +1`, `booking.reschedule_requested` publié (customerId/slotId/droitId corrects), `rescheduleRequested=true` côté API, **assertion négative** : aucune entité/route « proposition de créneau » n'existe dans le code (`grep -ri "propositioncreneau\|smartflow" app/src` vide, documenté en commentaire de test) |
| `testCa5AucunDroitProjeteAucunUpdateAucunChampNeMent` | Fonctionnel API | CA-5 : Ressource `ouvreAcces=false` (défaut), règle `Restored` -> `creditActionne=false`/`creditRestitue=false`/`rescheduleRequested=false`, `creditRestant` d'aucun `DroitAcces` modifié |
| `testCa6DroitProjeteSansCreditMemeResultatQueCa5` | Fonctionnel API | CA-6 : `DroitAcces` projeté avec `creditRestant=null` (cas universel réel aujourd'hui, **sans** forcer de valeur) -> même résultat que CA-5, pas d'exception |
| `testCa7PrecedenceActiviteSurEtablissement` | Fonctionnel API | CA-7 : règle Activité `Decremented` + règle Établissement (repli) `RestoredWithReschedule` -> c'est la règle Activité qui s'applique (précédence `ResolveurRegleAnnulation` non modifiée, vérifiée sur ce nouvel axe) |
| `testCa8IdempotenceRebasculementEchoueSoldeIncrementeUneFois` | Fonctionnel API | CA-8 : second appel de `declencher()` (invocation directe du service en test, réservation déjà en no-show) lève une exception de contrainte, `creditRestant` incrémenté une seule fois en base |
| `testCa9AnnulationTardiveMemeMouvementQueNoShowAutomatique` | Fonctionnel API | CA-9 : `POST .../annuler` hors délai franc (agent) avec règle `Restored` -> même restitution que `BasculerNoShowCommand`, `booking.cancelled.payload.creditIssue='restored'` |
| `testCa10OrthogonaliteFacturationEtRestitution` | Fonctionnel API | CA-10 : règle `modeFacturation=DebitPmv` + `issueCreditNoShow=Restored` -> `FacturationNoShow` avec tentative de débit PMV **et** `creditRestant +1`, les deux indépendamment |
| `testAucuneRegleActiveAucuneDecisionDeCredit` | Fonctionnel API | §7 cas limite : aucune `RegleAnnulation` active -> `declencher()` retourne `null`, aucun `UPDATE` sur `acces_droit_acces`, pas de restitution par défaut malgré `RestoredWithReschedule` = défaut d'une règle créée |
| Non-régression `App\Tests\Reservation\**` | Suite complète | Relancée telle quelle après T5-T9 — en particulier `AnnulationNoShowTest` (CA-8/9/10 existants), `ProjectionAccesTest`, `FacturationNoShowStrategiesTest`, `CloisonnementTest`, `ResolveurRegleAnnulationTest` |

**Collecteur de test (T11)** : `App\Tests\Reservation\Support\NoShowCreditEventCollector`, même patron
exact que `App\Tests\Acces\Support\CardRechargedEventCollector` (§0) — `getSubscribedEvents()` retourne
`['booking.no_show' => 'onNoShow', 'booking.cancelled' => 'onCancelled', 'booking.reschedule_requested' => 'onRescheduleRequested']`,
enregistré `public: true`, `tags: ['kernel.event_subscriber']` dans le bloc `when@test:` de
`app/config/services.yaml` (à côté de `CardRechargedEventCollector`, ligne ~289).

## 8. Risques / points à valider

1. **⚠ Hypothèse structurante §3.3 de la spec, à confirmer par l'intégrateur avant que CQ-3/CQ-6
   n'ouvrent réellement `creditRestant` sur un droit `Booking`.** Ce lot implémente `Decremented` =
   « le crédit déjà pris (au booking) reste pris, aucune écriture » et `Restored` = « rendre ce qui a
   été prélevé, `+1` ». Cette lecture suppose que le futur décompte se produit **à la confirmation de
   la réservation** (`ReserverProcessor`, par analogie avec `QuotaFormuleResolver`), **pas** au passage
   physique. Si la spec CQ-3/CQ-6 tranche l'inverse (décompte au passage, comme `CarteQuota`
   aujourd'hui), le sens des deux issues s'inverse et `AppliquerIssueCreditNoShowHandler` (§3.5) doit
   être relu avant toute mise en production réelle du couple — **ce lot reste un no-op sûr tant que
   `creditRestant` demeure `null` pour tout droit `Booking`, donc livrable dès maintenant sans risque de
   régression**, mais le sens des libellés `decremented`/`restored` exposés en API devra être
   revalidé avec CQ-3/CQ-6 avant qu'ils ne pilotent un vrai mouvement de crédit.
2. **Renforcement de RG-CQ5-08 au-delà de sa lettre stricte** (§3.7/3.8) : la spec dit littéralement
   « publié si `issueCreditNoShow = RestoredWithReschedule` **et** `creditActionne = true` » ; ce plan
   ajoute une troisième condition (`creditRestitue = true`) pour ne jamais publier
   `booking.reschedule_requested` dans le cas théorique de course concurrente (§3.5, `affectees === 0`)
   où un droit a été trouvé (`creditActionne=true`) mais la restitution a en réalité échoué
   (`creditRestitue=false`). Écart mineur, dans le sens de la prudence (jamais de faux signal), mais à
   valider explicitement puisqu'il diffère de la formulation exacte de la spec.
3. **Champ transient `FacturationNoShow::$droitAccesRestitueRef`, non persisté** (§3.3) — choix motivé
   par le tableau §6 de la spec qui ne liste que 3 colonnes neuves. Si un futur besoin d'audit
   (« quel `DroitAcces` exact a été crédité, consultable après coup ? ») apparaît, une 4ᵉ colonne
   persistée devra être ajoutée — non fait ici pour rester au plus près du périmètre décrit.
4. **`App\Reservation` écrit directement dans `App\Acces\Entity\DroitAcces`/`Appairage`/`Support`**
   (§3.4) — dépendance déjà acceptée pour ACC-3, mais ce lot l'**étend** (ACC-3 ne faisait que
   dévalider un statut ; ce lot modifie une valeur numérique métier, `credit_restant`, avec des
   implications terminal hors-ligne via `Support.versionMaj`). Signalé pour que la revue de cohérence
   confirme que ce périmètre de couplage reste acceptable au fur et à mesure qu'il s'élargit.
5. **Garde de concurrence `AND credit_restant IS NOT NULL` (§3.5)** — protège contre une course
   théorique aujourd'hui impossible à provoquer (rien ne remet `creditRestant` à `null` après
   affectation) mais qui deviendra possible en théorie si CQ-3/CQ-6 introduisent un chemin d'écriture
   supplémentaire sur ce champ. Comportement en cas de perte de la course : `creditActionne=true`,
   `creditRestitue=false`, pas d'exception, pas de log dédié prévu par ce plan — à décider en
   implémentation si un log d'anomalie explicite (`error_log`/moniteur) est souhaité au-delà de la
   trace déjà portée par `FacturationNoShow`.
6. **Deux `flush()` distincts dans `declencher()` (§3.6)** sont le mécanisme d'idempotence — un futur
   refactor qui les fusionnerait romprait silencieusement CA-8 (le second appel ne violerait plus la
   contrainte unique avant d'avoir déjà exécuté l'`UPDATE` de crédit). Signalé explicitement dans le
   commentaire de code proposé (§3.6) et dans `testCa8...` (§7) pour que ce risque reste testé, pas
   seulement documenté.
7. **`reschedule_requested` reste sans consommateur** (Smart Flow/SF-2 non écrit, comme
   `access.card_recharged` l'était avant Revenue Recovery et `booking.no_show` avant lui — D22). Rien à
   faire de plus dans ce lot ; le test CA-4 vérifie l'**absence** de tout effet observable au-delà de
   l'événement lui-même, condition explicite de la spec (§0, RG-CQ5-09).
8. **Le crédit d'une annulation libre** (délai franc) reste hors périmètre (§0/§10 pt.3 de la spec,
   non repris ici) — si CQ-6 décrémente réellement à la réservation, une annulation libre devra
   restituer inconditionnellement, en dehors du mécanisme `IssueCreditNoShow` (qui ne couvre que la
   branche tardive/no-show). Trou signalé, pas construit, cohérent avec le périmètre du mandat.
