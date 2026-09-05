<?php

declare(strict_types=1);

namespace App\Tests\SmartFlow\Api;

use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Platform\Event\DomainEvent;
use App\Platform\Event\EventBus;
use App\Platform\Event\EventSubject;
use App\Platform\Event\EventTenant;
use App\Reservation\Entity\Creneau;
use App\Reservation\Entity\Ressource;
use App\Reservation\Enum\StatutCreneau;
use App\SmartFlow\Entity\SlotWaitlistEntry;
use App\SmartFlow\Enum\SlotWaitlistEntryStatus;
use App\Tests\SmartFlow\SmartFlowApiTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * LA FENÊTRE DE RECHERCHE EST HONORÉE (lot du 05/09).
 *
 * `searchWindowStart`/`searchWindowEnd` étaient obligatoires à l'inscription — 422 sans, et fin >
 * début exigé — stockées, puis JAMAIS LUES : mesuré sur `src/` et `tests/`, leurs accesseurs
 * n'avaient aucun appelant. La promotion ordonnait par rang sans jamais les consulter, donc elle
 * pouvait proposer un créneau de décembre à quelqu'un qui avait demandé la semaine prochaine.
 *
 * ⚠ LE MONTAGE EST LE TEST. Le premier de la file a une fenêtre qui NE CONTIENT PAS le créneau
 * libéré ; le second l'a. Sans le filtre, c'est le rang 1 qui serait promu — le FIFO seul le
 * garantit. L'assertion qui compte est donc que le rang 1 reste en attente.
 *
 * ⚠ ET IL FAUT UN VRAI CRÉNEAU. Le filtre a besoin de la date de début, que la promotion résout par
 * `ReservationSlotReader`. Les autres tests du module publient des identifiants fabriqués : ils
 * exercent le repli, pas le filtre. C'est pourquoi celui-ci crée une ressource et un créneau réels.
 */
final class SearchWindowRespectedTest extends SmartFlowApiTestCase
{
    public function testLaPlaceVaAQuiLAVraimentDemandee(): void
    {
        $idA = $this->establishmentId(SocleFixtures::ETAB_A_NOM);
        $etablissement = $this->em()->getRepository(Etablissement::class)->find(Uuid::fromString($idA));
        self::assertInstanceOf(Etablissement::class, $etablissement);

        $creneau = $this->creneauReel($etablissement, new \DateTimeImmutable('+3 days'));
        $resourceId = $creneau->getRessource()?->getId();
        self::assertInstanceOf(Uuid::class, $resourceId);

        // Rang 1 : cherche BEAUCOUP plus tard — ce créneau ne l'intéresse pas.
        $horsFenetre = $this->entree($etablissement, $resourceId, 1, '+30 days', '+40 days');
        // Rang 2 : cherche justement dans ces jours-là.
        $dansFenetre = $this->entree($etablissement, $resourceId, 2, '+1 day', '+7 days');

        $this->libererCreneau($idA, $creneau->getId(), $resourceId);

        $em = $this->em();
        $em->refresh($horsFenetre);
        $em->refresh($dansFenetre);

        self::assertSame(
            SlotWaitlistEntryStatus::Waiting,
            $horsFenetre->getStatus(),
            'Le premier de la file a demandé une autre période : la place ne doit pas lui être proposée.',
        );
        self::assertSame(
            SlotWaitlistEntryStatus::Promoted,
            $dansFenetre->getStatus(),
            'Elle revient à la personne suivante dont la fenêtre couvre ce créneau.',
        );
    }

    /**
     * LE REPLI, DOCUMENTÉ PLUTÔT QUE SUBI.
     *
     * Quand le créneau n'est pas lisible, la fenêtre est inapplicable — faute de savoir à quelle
     * date comparer. Refuser toute promotion priverait quelqu'un d'une vraie place sur un simple
     * échec de lecture : on retombe donc sur le FIFO d'avant, et le service le journalise.
     *
     * Ce test fige ce comportement pour qu'il reste un choix, et non une découverte.
     */
    public function testUnCreneauIllisibleRetombeSurLeFifoDAvant(): void
    {
        $idA = $this->establishmentId(SocleFixtures::ETAB_A_NOM);
        $etablissement = $this->em()->getRepository(Etablissement::class)->find(Uuid::fromString($idA));
        self::assertInstanceOf(Etablissement::class, $etablissement);

        $resourceId = Uuid::v4();
        // Une fenêtre qui ne couvre rien de proche : sans date de créneau, elle ne peut pas filtrer.
        $entree = $this->entree($etablissement, $resourceId, 1, '+300 days', '+400 days');

        $this->libererCreneau($idA, Uuid::v4(), $resourceId);

        $this->em()->refresh($entree);
        self::assertSame(
            SlotWaitlistEntryStatus::Promoted,
            $entree->getStatus(),
            'Créneau illisible : on sert quand même, plutôt que de priver quelqu’un sur un échec de lecture.',
        );
    }

    private function creneauReel(Etablissement $etablissement, \DateTimeImmutable $debut): Creneau
    {
        $ressource = (new Ressource())
            ->setEtablissement($etablissement)
            ->setLibelle('Ressource fenêtre ' . uniqid('', true))
            ->setCodeType('test')
            ->setCapacitePropre(1);
        $this->em()->persist($ressource);

        $creneau = (new Creneau())
            ->setRessource($ressource)
            ->setEtablissement($etablissement)
            ->setDebut($debut)
            ->setFin($debut->modify('+1 hour'))
            ->setCapacite(1)
            ->setStatut(StatutCreneau::Planifie);
        $this->em()->persist($creneau);
        $this->em()->flush();

        return $creneau;
    }

    private function entree(
        Etablissement $etablissement,
        Uuid $resourceId,
        int $rank,
        string $debutFenetre,
        string $finFenetre,
    ): SlotWaitlistEntry {
        $entree = (new SlotWaitlistEntry())
            ->setEstablishment($etablissement)
            ->setResourceId($resourceId)
            ->setBeneficiaryId(Uuid::v4())
            ->setSearchWindowStart(new \DateTimeImmutable($debutFenetre))
            ->setSearchWindowEnd(new \DateTimeImmutable($finFenetre))
            ->setRank($rank)
            ->setStatus(SlotWaitlistEntryStatus::Waiting);

        $this->em()->persist($entree);
        $this->em()->flush();

        return $entree;
    }

    private function libererCreneau(string $idEtablissement, Uuid $slotId, Uuid $resourceId): void
    {
        /** @var EventBus $bus */
        $bus = static::getContainer()->get(EventBus::class);
        $bus->publish(new DomainEvent(
            'slot.released',
            new EventTenant(Uuid::fromString($idEtablissement)),
            new EventSubject('Slot', (string) $slotId),
            ['slot' => (string) $slotId, 'resource' => (string) $resourceId],
        ));
    }
}
