# Plan technique — ACC-3 : projection réelle « une réservation ouvre un accès » (`RG-M5-12`, `RG-ACC-01`)

- **Spec source :** `specs/acces/spec-acc3-projection-reservation.md`
- **Stack :** Symfony 7 · API Platform · Doctrine/MariaDB
- **Statut :** prêt à implémenter (aucune dépendance bloquante sur ACC-0/ACC-1, cf. spec §0)
- **Décisions reprises telles quelles (ne pas rouvrir) :** `TypeDroitAcces::Booking = 'booking'` (D5) ;
  ajout de `DroitAcces.reservationRef: ?Uuid` (migration nullable, colonne unique ajoutée).

## 0. Résumé de l'approche

Aucun nouveau endpoint, aucune nouvelle permission. Trois changements de code :

1. **`ProjectionAccesReservationHandler`** (`App\Reservation\Service`) : le no-op est remplacé par une
   construction/mise à jour directe d'un `DroitAcces` (patron `EmissionBadgeStaffHandler`), + une
   nouvelle méthode `revoquerSiProjete()` (patron `PropagationAccesHandler`/`PropagationAccesFitnessHandler`).
   **Aucun fichier `App\Acces\*` n'est modifié** pour la construction/révocation (troisième précédent du
   même genre après M2/Sport/Personnel), à l'exception d'un ajout de champ sur l'entité `DroitAcces`
   elle-même (`reservationRef`, décision actée) et de l'enum `TypeDroitAcces` (nouveau cas `Booking`).
2. **3 points de révocation câblés** (aucun nouveau point de création, les 3 existants restent
   inchangés — le comportement de `projeterSiApplicable()` change en interne, pas ses call sites).
3. **1 migration** : `ALTER TABLE acces_droit_acces ADD reservation_ref BINARY(16) DEFAULT NULL`.

**Écart signalé (hors demande initiale, découvert en revue) :** `App\Acces\State\AppairageProcessor`
résout le `DroitAcces` cible par `EntityManager::find()` brut (ligne 64), **sans repasser par
`PerimetreAccesExtension`** (qui ne s'applique qu'aux requêtes API Platform Get/GetCollection, pas à un
`find()` direct dans un Processor). Un agent scopé sur B pourrait donc aujourd'hui appairer un support à
un `DroitAcces` d'un établissement A — un gap pré-existant (partagé par tous les `TypeDroitAcces`, pas
introduit par ACC-3) mais qui contredit littéralement le second volet de CA-5 (« ni l'utiliser comme
cible d'un appairage »). Proposé en tâche T7 (petit durcissement, 4 lignes) pour que CA-5 soit
réellement vérifiable — signalé explicitement, à valider par l'intégrateur avant de l'inclure ou de le
reporter dans un lot dédié « durcissement appairage ».

## 1. Entités & schéma

| Entité (`App\<Module>\Entity\*`) | Champ | Type Doctrine | Null | Index/Contrainte | Relation |
|---|---|---|---|---|---|
| `App\Acces\Entity\DroitAcces` (existant) | `reservationRef` *(nouveau)* | `UuidType::NAME` | oui | aucun (miroir de `billetSupportRef`/`produitRef`, pas de FK Doctrine — cloisonnement inter-module) | référence logique `Reservation.id`, jamais résolue par relation Doctrine |
| `App\Acces\Enum\TypeDroitAcces` (existant) | `Booking` *(nouveau cas)* | `enum string` | — | `source_type VARCHAR(24)` déjà en place | — |
| `App\Reservation\Entity\ProjectionAccesReservation` (existant) | `droitAccesRef` | `UuidType::NAME` | oui (déjà) | — | **désormais renseigné**, aucun changement de schéma |

> id = UUID (`symfony/uid`). Rattachement multi-entités : `DroitAcces.etablissement` = strictement
> `Reservation.etablissement` (RG-ACC3-06), lui-même dérivé serveur (jamais d'en-tête client) — inchangé
> par ce lot.

Getter/setter à ajouter sur `DroitAcces` (`app/src/Acces/Entity/DroitAcces.php`), à côté de
`billetSupportRef`/`produitRef` (mêmes lignes 45-51, patron identique) :

```php
#[ORM\Column(type: UuidType::NAME, nullable: true)]
#[Groups(['droit:read'])]
private ?Uuid $reservationRef = null;

public function getReservationRef(): ?Uuid { return $this->reservationRef; }
public function setReservationRef(?Uuid $reservationRef): self { $this->reservationRef = $reservationRef; return $this; }
```

Enum `TypeDroitAcces` (`app/src/Acces/Enum/TypeDroitAcces.php`) : ajouter `case Booking = 'booking';`
sous `Personnel`, avec un ajout au docblock existant (même style que le paragraphe déjà présent pour
`Personnel`) documentant `Booking` comme deuxième extension additive coordonnée (`App\Reservation`).
Aucun `match`/`switch` exhaustif sur `TypeDroitAcces` n'existe dans le code (vérifié — seules des
comparaisons `===` isolées dans `SnapshotTerminalProvider`, `ValidationPassageHandler`,
`AffichagePorteurResolver`, tous ciblant `CarteQuota`/`Abonnement` uniquement) : **aucun autre fichier
`App\Acces\*` n'a besoin d'être touché** pour que ce nouveau cas soit géré sans erreur.

## 2. API (API Platform)

Aucune modification. Aucune nouvelle ressource, aucune nouvelle opération, aucun nouveau groupe de
sérialisation (le champ `reservationRef` réutilise `#[Groups(['droit:read'])]`, déjà exposé par
`GetCollection`/`Get` sur `DroitAcces` — visible dans les mêmes conditions que `billetSupportRef`/
`produitRef`, filtré par le même `security:` existant `is_granted('PERM', 'acces.lire')` et le même
cloisonnement `PerimetreAccesExtension`).

| Ressource | Opérations | security: | Groupes sérialisation | Filtres |
|---|---|---|---|---|
| `DroitAcces` (inchangé) | `GetCollection`, `Get` | `is_granted('PERM', 'acces.lire')` (inchangé) | `droit:read` (+`reservationRef`) | inchangés |
| `ReservationProjectionAcces` (inchangé) | `GetCollection`, `Get` | `is_granted('PERM', 'reservation.lire')` (inchangé) | `projection_acces:read` (inchangé) | `reservation` exact (inchangé) |

## 3. Sécurité & droits

- **Aucune nouvelle permission.** Le côté « écriture » de ce lot est un side-effect système déclenché
  par la confirmation/l'annulation d'une réservation (comme documenté §3 de la spec) — pas d'action
  utilisateur dédiée, donc pas de nouveau couple `module × action`.
- Le cloisonnement établissement de `DroitAcces` reste géré par `PerimetreAccesExtension`
  (`app/src/Acces/Doctrine/PerimetreAccesExtension.php`, ligne 44 : `DroitAcces::class =>
  '{root}.etablissement'`) — **aucune modification requise ici**, `etablissement` est déjà dans la
  liste des chemins cloisonnés, et ce lot ne fait que peupler ce champ depuis
  `Reservation.etablissement`, jamais depuis un contexte HTTP (RG-ACC3-06 respectée par construction :
  `ProjectionAccesReservationHandler` ne lit jamais `ContexteEtablissement`, seulement l'entité
  `Reservation` déjà résolue par le processor appelant).
- **Voter** : aucun nouveau voter. `ValidationPassageHandler`/`AppairageHandler` restent inchangés,
  déjà couverts (spec §2 exclusions — ACC-2 hors périmètre).
- **T7 (proposée, cf. §0)** : durcissement `AppairageProcessor` — ajouter, juste après résolution de
  `$droit` (ligne ~73-75 de `app/src/Acces/State/AppairageProcessor.php`), une vérification
  `$droit->getEtablissement()?->getId()?->equals($etablissement->getId())`, sinon lever une
  `NotFoundHttpException` (pas de fuite d'existence, cohérent avec le 404 déjà renvoyé par
  `PerimetreAccesExtension` sur un `Get` cross-établissement, cf. `CloisonnementTest::
  testGestionnaireScopeANePeutPasAgirSurUnTerminalDeB`). Cette garde est **générique** (s'applique à
  tout `TypeDroitAcces`, pas seulement `Booking`) — c'est un correctif de portée plus large que ACC-3
  strictement, mais nécessaire pour que CA-5 (second volet) soit réellement vrai. À confirmer par
  l'intégrateur : inclure ici ou reporter en lot dédié (§7 Risques).

## 4. Migrations

Une seule migration, écrite à la main (ne **pas** utiliser `doctrine:migrations:diff` brut — le dépôt
reproposera systématiquement la suppression de l'index FULLTEXT `App\Support` et des renommages
d'index Finance, cf. `app/migrations/Version20260821102107.php` et
`app/migrations/Version20260822090000.php` qui documentent explicitement ce piège C14). **Relire la
migration générée ligne à ligne et ne garder que l'instruction ci-dessous.**

Nouveau fichier `app/migrations/Version<horodatage>.php` (nommage/format identique aux migrations
existantes, `namespace DoctrineMigrations`) :

```php
public function up(Schema $schema): void
{
    $this->addSql('ALTER TABLE acces_droit_acces ADD reservation_ref BINARY(16) DEFAULT NULL');
}

public function down(Schema $schema): void
{
    $this->addSql('ALTER TABLE acces_droit_acces DROP reservation_ref');
}
```

- Table confirmée : `acces_droit_acces` (créée par `Version20260814210000.php`, ligne 30 — colonnes
  `billet_support_ref BINARY(16) DEFAULT NULL`, `produit_ref BINARY(16) DEFAULT NULL` déjà au même
  patron, sans index ni FK).
- Pas d'index requis (les colonnes miroir `billet_support_ref`/`produit_ref` n'en ont pas non plus —
  cohérence, aucun besoin de requête indexée sur `reservationRef` pour ce lot : la voie de résolution
  standard reste `ProjectionAccesReservation.droitAccesRef` → `DroitAcces.id`, pas l'inverse).
- Pas de migration pour `TypeDroitAcces::Booking` (colonne `source_type VARCHAR(24)` déjà assez large,
  même remarque que pour `Personnel`, cf. spec §5).
- Aucune modification d'aucune autre table (`reservation_projection_acces` inchangée en schéma —
  `droitAccesRef` existe déjà, seul son contenu change).

## 5. Comportements détaillés

### 5.1 `ProjectionAccesReservationHandler::projeterSiApplicable()` (réécriture complète)

Fichier : `app/src/Reservation/Service/ProjectionAccesReservationHandler.php`.

**Concurrence (RG-ACC3-04, risque §9 de la spec).** Verrou pessimiste sur l'entité `Reservation`
elle-même (patron `EmettreFactureDirecteHandler::emettre()`, `app/src/Facturation/Service/
EmettreFactureDirecteHandler.php` lignes 74-80 : `wrapInTransaction` + `$this->em->lock($entite,
LockMode::PESSIMISTIC_WRITE)` posé en tout premier dans la transaction, puis le find-or-create est
**revérifié après verrou**). Choix retenu plutôt que catch `UniqueConstraintViolationException` : le
verrou est posé sur la ligne `Reservation` (déjà persistée à ce stade dans les 3 call sites — chacun
fait `flush()` avant d'appeler `projeterSiApplicable()`), donc deux appels concurrents pour la **même**
réservation se sérialisent strictement ; le second, une fois le verrou obtenu, relit
`ProjectionAccesReservation`/`DroitAcces` déjà créés par le premier et bascule en mise à jour — aucune
seconde ligne insérée, contrainte unique `uniq_projection_acces_reservation` jamais sollicitée en
conflit. Deux réservations différentes ne se bloquent pas mutuellement (verrous sur des lignes
distinctes). Pas de `UniqueConstraintViolationException` à catcher avec cette approche (contrairement à
`AvoirFactureHandler`/`OcrProviderConfigProcessor` qui n'ont pas d'entité porteuse à verrouiller) — plus
simple et plus proche du patron `EmettreFactureDirecteHandler` retenu comme référence la plus proche
structurellement (numérotation idempotente sous contrainte unique).

```php
<?php

declare(strict_types=1);

namespace App\Reservation\Service;

use App\Acces\Entity\DroitAcces;
use App\Acces\Enum\StatutProjectionDroit;
use App\Acces\Enum\TypeDroitAcces;
use App\Reservation\Entity\ProjectionAccesReservation;
use App\Reservation\Entity\Reservation;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Projette un droit d'accès réel (RG-M5-12, CA-15 spec-reservation ; RG-ACC3-01..07) sur la fenêtre du
 * créneau, déclenché à la confirmation d'une réservation dont la Ressource porte `ouvreAcces=true`.
 * Construction directe d'un `App\Acces\Entity\DroitAcces` (`sourceType = TypeDroitAcces::Booking`), hors
 * `ProjectionDroitInterface` — même patron que `App\Personnel\Service\EmissionBadgeStaffHandler`
 * (`TypeDroitAcces::Personnel`). Révocation symétrique via `revoquerSiProjete()`, même patron que
 * `App\Recouvrement\Service\PropagationAccesHandler` / `App\Sport\Service\PropagationAccesFitnessHandler`
 * — aucun fichier `App\Acces\*` n'est modifié pour ce comportement.
 */
final class ProjectionAccesReservationHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** RG-ACC3-01/02/03/04 : find-or-create idempotent, protégé par verrou pessimiste sur la Reservation. */
    public function projeterSiApplicable(Reservation $reservation): ?ProjectionAccesReservation
    {
        $creneau = $reservation->getCreneau();
        $ressource = $creneau?->getRessource();
        if ($creneau === null || $ressource === null || !$ressource->isOuvreAcces()) {
            return null;
        }

        /** @var ProjectionAccesReservation $resultat */
        $resultat = $this->em->wrapInTransaction(function () use ($reservation, $creneau): ProjectionAccesReservation {
            // Verrou pessimiste (RG-ACC3-04, risque §9 spec) : sérialise les rejeux concurrents pour
            // la même réservation ; revérification du find-or-create après obtention du verrou.
            $this->em->lock($reservation, LockMode::PESSIMISTIC_WRITE);

            $projection = $this->em->getRepository(ProjectionAccesReservation::class)
                ->findOneBy(['reservation' => $reservation]);

            $droit = null;
            if ($projection instanceof ProjectionAccesReservation && $projection->getDroitAccesRef() !== null) {
                $droit = $this->em->getRepository(DroitAcces::class)->find($projection->getDroitAccesRef());
            }

            if (!$droit instanceof DroitAcces) {
                $droit = new DroitAcces();
                $droit->setSourceType(TypeDroitAcces::Booking)
                    ->setReservationRef($reservation->getId());
                $this->em->persist($droit);
            }

            // RG-ACC3-02 : fenêtre = créneau (marges non alimentées à ce jour, cf. §2 exclusions spec —
            // ResolveurMarges applique un défaut 0, fenêtre stricte). creditRestant/produitRef = null
            // (pas de décompte, pas de Produit M1). statutProjection revalidé à Valide même en mise à
            // jour (rejeu défensif d'une réservation toujours confirmée).
            $droit->setFenetreDebut($creneau->getDebut())
                ->setFenetreFin($creneau->getFin())
                ->setCreditRestant(null)
                ->setProduitRef(null)
                ->setEtablissement($reservation->getEtablissement())
                ->setStatutProjection(StatutProjectionDroit::Valide)
                ->setSynchroniseLe(new \DateTimeImmutable());

            if (!$projection instanceof ProjectionAccesReservation) {
                $projection = new ProjectionAccesReservation();
                $projection->setReservation($reservation);
                $this->em->persist($projection);
            }
            $projection->setFenetreDebut($creneau->getDebut())
                ->setFenetreFin($creneau->getFin())
                ->setEtablissement($reservation->getEtablissement())
                ->setDroitAccesRef($droit->getId());

            $this->em->flush();

            return $projection;
        });

        return $resultat;
    }

    /**
     * RG-ACC3-05 : dévalide le DroitAcces projeté (s'il existe) quand la Réservation quitte
     * `occupePlace()`. No-op silencieux si aucune projection (Ressource `ouvreAcces=false` ou jamais
     * projetée) — pas une erreur (§8 cas limite spec).
     */
    public function revoquerSiProjete(Reservation $reservation): void
    {
        $projection = $this->em->getRepository(ProjectionAccesReservation::class)
            ->findOneBy(['reservation' => $reservation]);
        if (!$projection instanceof ProjectionAccesReservation || $projection->getDroitAccesRef() === null) {
            return;
        }

        $droit = $this->em->getRepository(DroitAcces::class)->find($projection->getDroitAccesRef());
        if ($droit instanceof DroitAcces) {
            $droit->setStatutProjection(StatutProjectionDroit::Devalide);
            $this->em->flush();
        }
    }
}
```

Points à noter pour l'implémenteur :
- `$droit->getId()` est disponible immédiatement après `new DroitAcces()` (constructeur : `$this->id =
  Uuid::v4()`, cf. `app/src/Acces/Entity/DroitAcces.php` ligne 93) — pas besoin d'un flush intermédiaire
  avant de renseigner `$projection->setDroitAccesRef($droit->getId())`, exactement comme
  `EmissionBadgeStaffHandler` utilise `$droit` juste après `persist()` sans flush préalable (ligne 86-90).
- Le paramètre `LoggerInterface $logger` du constructeur actuel disparaît (plus de `logger->info(...
  no_op)` à produire) — vérifier qu'aucun autre appelant n'attend cette dépendance (recherche : seuls
  les 3 processors/handlers déjà listés injectent `ProjectionAccesReservationHandler`, aucun test
  n'instancie le service directement hors conteneur DI).
- `Ressource::isOuvreAcces()` et la garde `$creneau === null || $ressource === null` sont **inchangés**
  (repris tels quels du no-op actuel).

### 5.2 Points de création (3, inchangés — aucune édition requise)

Le comportement de `projeterSiApplicable()` change en interne ; les 3 call sites restent identiques
(ils appellent déjà la méthode après confirmation de la `Reservation`) :

| Fichier | Ligne | Contexte |
|---|---|---|
| `app/src/Reservation/State/ReserverProcessor.php` | 125 | après `$this->em->flush();` (ligne 123), POST `/reservation/reservations` |
| `app/src/Padel/State/ReserverTerrainProcessor.php` | 164 | après `$this->em->flush();` (ligne 162), POST `/padel/terrains/{id}/reservations` |
| `app/src/Boutique/Service/ConfirmerCommandeHandler.php` | 229 | après `$this->em->flush();` (ligne 228), dans la boucle `foreach ($vente->getLignes() as $ligneVente)` de `confirmerApresPaiementReussi()` |

Dans les 3 cas, `$reservation` est déjà managée et flushée avant l'appel — condition requise par le
verrou pessimiste (§5.1).

### 5.3 Points de révocation (3, à câbler)

**Choix d'architecture** : plutôt que de dupliquer l'appel à `revoquerSiProjete()` dans
`AnnulerReservationProcessor` (branche tardive) **et** `BasculerNoShowCommand` (branche no-show), les
deux passent déjà par le même point de passage unique — `DeclencherFacturationNoShowHandler::
declencher()` — c'est **là** qu'il faut câbler la révocation pour ces deux branches en un seul
endroit (exactement ce que la spec RG-ACC3-05 décrit : *« branche tardive (via
DeclencherFacturationNoShowHandler::declencher())… BasculerNoShowCommand… via
DeclencherFacturationNoShowHandler::declencher() »* — la spec identifie déjà ce handler partagé comme LE
point d'intégration, pas les deux call sites séparément). Au total : **3 fichiers édités**, couvrant les
3 branches de sortie de `occupePlace()`.

| # | Fichier | Ligne (avant édition) | Édition |
|---|---|---|---|
| 1 | `app/src/Reservation/State/AnnulerReservationProcessor.php` | 63-64 (branche libre, `dansDelai`) | injecter `ProjectionAccesReservationHandler $projectionAcces` au constructeur ; après `$data->setStatut(StatutReservation::AnnuleeLibre); $this->em->flush();` ajouter `$this->projectionAcces->revoquerSiProjete($data);` |
| 2 | `app/src/Reservation/State/AnnulerCreneauProcessor.php` | 39 (boucle, `$reservation->getStatut()->occupePlace()`) | injecter `ProjectionAccesReservationHandler $projectionAcces` au constructeur ; dans la boucle, après `$reservation->setStatut(StatutReservation::AnnuleeLibre);` ajouter `$this->projectionAcces->revoquerSiProjete($reservation);` (avant ou après le `flush()` final de la méthode, peu importe — `revoquerSiProjete()` fait son propre `flush()`) |
| 3 | `app/src/Reservation/Service/DeclencherFacturationNoShowHandler.php` | 36 (`$reservation->setStatut($statutCible);`) | injecter `ProjectionAccesReservationHandler $projectionAcces` au constructeur ; juste après `$reservation->setStatut($statutCible);` ajouter `$this->projectionAcces->revoquerSiProjete($reservation);` — couvre **à la fois** `AnnulerReservationProcessor` branche tardive (appel ligne 66) et `BasculerNoShowCommand` branche no-show (appel ligne 77) |

Transition vers `Honoree` (`BasculerNoShowCommand`, branche `isPresenceConfirmee()`, ligne 75) :
**aucune révocation** — conforme à la spec (RG-ACC3-05, la fenêtre est de toute façon échue,
`ResolveurMarges` refuse déjà tout passage postérieur). Ne pas ajouter d'appel ici.

`revoquerSiProjete()` est un no-op silencieux si `ouvreAcces=false` (aucune `ProjectionAccesReservation`
à retrouver) — couvre nativement le cas limite §8 spec (« annulation d'une réservation jamais
projetée »).

## 6. Cas limite — report de créneau (§8 spec)

**Décision : hors périmètre explicite de ce lot, signalé (pas de nouveau call site ajouté).**

Justification : aucun des deux mécanismes de report existants (`RecurrenceReportHandler::
tenterReport()`, `app/src/Reservation/Service/RecurrenceReportHandler.php` ; `ModifierOccurrenceProcessor`,
`app/src/Reservation/State/ModifierOccurrenceProcessor.php`) n'opère sur une `Reservation` déjà
confirmée avec droit projeté — ils modifient `Creneau.debut/fin/ressource` au niveau de la série/
occurrence (RG-M5-07/11, contexte gestionnaire de planning), un cran au-dessus des réservations
individuelles. Aucun des 8 CA de la spec ne teste ce scénario. Le coupler à ce lot introduirait un
risque de régression sur un mécanisme de récurrence déjà livré et testé, pour un gain non demandé.

**Risque résiduel signalé** (à traiter dans un lot ultérieur si confirmé nécessaire) : si un créneau
avec réservations confirmées + droits projetés est reporté via ces deux mécanismes, `DroitAcces.
fenetreDebut/Fin` reste figée sur l'ancienne fenêtre (copie prise à la projection, pas de lecture live
du `Creneau`) — un droit pourrait devenir inutilisable (fenêtre passée) ou, plus rarement, rester
valide sur un horaire qui n'est plus le bon. Correctif possible et peu coûteux si retenu plus tard :
appeler `projeterSiApplicable()` pour chaque `Reservation` `occupePlace()` du créneau, à la fin de
`RecurrenceReportHandler::tenterReport()` et `ModifierOccurrenceProcessor::process()` — la méthode est
déjà idempotente (§5.1), donc sûre à rejouer. **Non inclus dans les tâches de ce lot** — à arbitrer par
l'intégrateur.

## 7. Tests

| Test | Type | Couvre |
|---|---|---|
| `ProjectionAccesTest::testCa15ProjectionCreeeSurConfirmation` (existant, à étendre) | Fonctionnel API | Ajouter : assert `$projection->getDroitAccesRef()` non nul ; charger le `DroitAcces` référencé, assert `sourceType === Booking`, `statutProjection === Valide`, `fenetreDebut/Fin` = créneau, `etablissement` = celui de la réservation (**CA-1**) |
| `ProjectionAccesTest::testAucuneProjectionSiRessourceNouvrePasAcces` (existant, inchangé) | Fonctionnel API | Non-régression `ouvreAcces=false` (**CA-7**) |
| `AccesBadgeTest::testCa12ProjectionAccesSurFenetreReservee` (existant, inchangé — Padel) | Fonctionnel API | Non-régression verticale consommatrice (RG-PADEL-05), aucune redéfinition |
| *(nouveau)* `ProjectionAccesTest::testIdempotenceRejeuNeCreePasDeSecondeProjection` | Fonctionnel/intégration | Appelle `ProjectionAccesReservationHandler::projeterSiApplicable()` deux fois pour la même `Reservation` (récupéré via le conteneur, patron `TypeDroitAccesPersonnelTest`) ; assert une seule `ProjectionAccesReservation`, un seul `DroitAcces` (**CA-6**) |
| *(nouveau)* `app/tests/Acces/Api/DroitAccesReservationTest.php::testAppairageEtPassageAccepteDansLaFenetre` | Fonctionnel API | Patron `TypeDroitAccesPersonnelTest` : crée une réservation confirmée sur une ressource `ouvreAcces=true`, récupère le `DroitAcces` projeté, `POST /api/acces/appairages` (`droit=<iri>`), `POST /api/acces/passages` dans la fenêtre → `resultat=valide` (**CA-2, volet accepté**) |
| *(nouveau)* même fichier `::testPassageRefuseHorsFenetre` | Fonctionnel API | Même montage, passage hors `fenetreDebut/Fin` → `resultat=refuse`, `codeMotif=HORS_MARGE` (`CodeMotifRefus::HorsMarge`) (**CA-2, volet refusé**) |
| *(nouveau)* `app/tests/Reservation/Api/RevocationAccesReservationTest.php::testAnnulationLibreDevalideLeDroit` | Fonctionnel API | Réservation + droit projeté → `POST /reservation/reservations/{id}/annuler` dans le délai franc → `DroitAcces.statutProjection === Devalide` ; passage tenté ensuite → refusé `DroitInvalide` (**CA-3**) |
| *(nouveau)* même fichier `::testAnnulationTardiveDevalideLeDroit` | Fonctionnel API | Idem hors délai franc (agent, `reservation.annuler`) → `AnnuleeTardiveFacturee` → `Devalide` (**CA-4, volet tardif**) |
| *(nouveau)* même fichier `::testNoShowDevalideLeDroit` | Fonctionnel/commande | Réservation + droit projeté, créneau échu sans présence confirmée, exécute `BasculerNoShowCommand::basculer()` → `NoShowFacture` → `Devalide` (**CA-4, volet no-show**) |
| *(nouveau)* même fichier `::testAnnulationCreneauDevalideLesDroitsDeToutesLesReservations` | Fonctionnel API | `POST /reservation/creneaux/{id}/annuler` avec plusieurs réservations confirmées + droits projetés → toutes `Devalide` |
| *(nouveau)* `app/tests/Acces/Api/CloisonnementReservationDroitTest.php::testAgentScopeBNePeutPasLireLeDroitProjeteSurA` | Fonctionnel API | Réservation confirmée sur A avec droit projeté → `GET /api/acces/droit_acces/{id}` par un agent scopé B uniquement → 404 (**CA-5, volet lecture**, patron `CloisonnementTest`) |
| *(nouveau)* même fichier `::testAgentScopeBNePeutPasAppairerLeDroitDeA` | Fonctionnel API | Même montage, `POST /api/acces/appairages` (`droit=<iri du droit de A>`) avec en-tête établissement B → refus (**CA-5, volet appairage** — dépend de T7 §3 ; si T7 non retenue, ce test **doit échouer et documenter le gap**, ne pas le supprimer silencieusement) |
| *(nouveau)* `app/tests/Reservation/Api/RevocationAccesReservationTest.php::testReservationGratuiteFonctionneIdentiquement` | Fonctionnel API | Réservation `ModeDecompteReservation::Gratuit` sur ressource `ouvreAcces=true` → projection identique (§8 cas limite, non-régression du principe « ouvreAcces seul déclenche ») |
| *(existant)* `HorsLigneTest` (aucune modification) | Fonctionnel API | **CA-8** : couvert transitivement — un `DroitAcces` de type `Booking` appairé suit le même mécanisme générique hors-ligne que tout autre droit (`/api/acces/synchro`), aucun code hors-ligne spécifique à `Booking`. Pas de nouveau test requis ; mentionné ici pour traçabilité de couverture du CA. |
| *(nouveau, unitaire)* `app/tests/Reservation/Unit/ProjectionAccesReservationHandlerTest.php` | Unitaire | Vérifie directement (sans HTTP) que `projeterSiApplicable()` sur une Ressource `ouvreAcces=false` retourne `null` sans écriture ; que `revoquerSiProjete()` sur une réservation sans projection est un no-op silencieux (aucune exception) |

## 8. Tâches (voir `tasks-acc3.md`)

1. **T1** — `DroitAcces` : ajout champ `reservationRef` (+ getter/setter, groupe `droit:read`).
2. **T2** — `TypeDroitAcces` : ajout `case Booking = 'booking'` + complément docblock.
3. **T3** — Migration `acces_droit_acces.reservation_ref` (nullable, écrite à la main, relecture ligne
   à ligne obligatoire — cf. §4).
4. **T4** — Réécriture `ProjectionAccesReservationHandler` (création idempotente verrouillée +
   `revoquerSiProjete()`), suppression de la dépendance `LoggerInterface` devenue inutile.
5. **T5** — Câblage des 3 points de révocation (`AnnulerReservationProcessor`,
   `AnnulerCreneauProcessor`, `DeclencherFacturationNoShowHandler`) — injection du handler + appel.
6. **T6** — Tests (§7) : extension `ProjectionAccesTest`, nouveaux fichiers `DroitAccesReservationTest`,
   `RevocationAccesReservationTest`, `CloisonnementReservationDroitTest`,
   `ProjectionAccesReservationHandlerTest`.
7. **T7 (à confirmer, §3)** — Durcissement `AppairageProcessor` (vérification établissement du droit
   résolu) + test `CloisonnementReservationDroitTest::testAgentScopeBNePeutPasAppairerLeDroitDeA`.
8. **T8** — `composer cs-fix`/`phpstan` + `bin/phpunit` complet (non-régression Padel/Boutique/Reservation/
   Acces/Personnel/Recouvrement/Sport, tous consommateurs directs ou indirects des fichiers touchés).

Ordre : T1 → T2 → T3 (schéma) → T4 (logique) → T5 (câblage) → T6 (tests) → T7 (si retenue) → T8.

## 9. Risques / à valider

- **T7 (gap `AppairageProcessor`) à trancher par l'intégrateur** avant implémentation : soit incluse
  dans ce lot (petit correctif, généralise au-delà de `Booking`), soit reportée en lot dédié
  « durcissement appairage cross-établissement » — dans ce cas, retirer/marquer `skip` le test
  `testAgentScopeBNePeutPasAppairerLeDroitDeA` avec une référence explicite au ticket de suivi (ne pas
  le supprimer silencieusement, le gap doit rester tracé).
- **Cas limite report de créneau (§6)** : risque résiduel documenté et volontairement laissé hors
  périmètre — fenêtre `DroitAcces` non re-synchronisée si un créneau confirmé avec droit projeté est
  reporté via `RecurrenceReportHandler`/`ModifierOccurrenceProcessor`. Aucun CA ne le couvre
  aujourd'hui ; à confirmer si une story dédiée doit fermer ce risque.
- **Marges (`margeAvanceMinutes`/`margeRetardMinutes` sur `ProjectionAccesReservation`)** : toujours non
  alimentées par aucun code amont (dépendance non bloquante déjà actée spec §9) — la fenêtre d'entrée
  reste stricte (0 minute de tolérance) tant qu'un paramétrage Ressource/Établissement n'existe pas.
  Sans impact sur ce lot, signalé pour mémoire.
- **`AffichagePorteurResolver`** (`app/src/Acces/Service/AffichagePorteurResolver.php`) ne traite
  explicitement que `Abonnement`/`CarteQuota` pour l'affichage porteur au contrôle — un droit `Booking`
  suivra le chemin par défaut (comme `Billet`/`Personnel` aujourd'hui). Non testé par les CA de ce lot ;
  à vérifier visuellement si un écran agent affiche le porteur d'un droit de réservation (hors
  périmètre strict, mentionné par prudence).
- **NF525/comptabilité publique** : sans objet pour ce lot (aucune écriture financière, `DroitAcces` et
  `ProjectionAccesReservation` ne sont pas des objets comptables) — aucun point à faire valider par un
  expert compta ici.
