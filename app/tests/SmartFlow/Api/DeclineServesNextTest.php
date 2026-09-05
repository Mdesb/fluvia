<?php

declare(strict_types=1);

namespace App\Tests\SmartFlow\Api;

use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Platform\Event\DomainEvent;
use App\Platform\Event\EventBus;
use App\Platform\Event\EventSubject;
use App\Platform\Event\EventTenant;
use App\SmartFlow\Entity\RescheduleProposal;
use App\SmartFlow\Entity\SlotWaitlistEntry;
use App\SmartFlow\Enum\RescheduleProposalStatus;
use App\SmartFlow\Enum\SlotWaitlistEntryStatus;
use App\Tests\SmartFlow\SmartFlowApiTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * REFUSER UNE PROPOSITION DOIT SERVIR LE SUIVANT (lot du 05/09, RG-SF-07).
 *
 * `DeclineRescheduleProposalProcessor` basculait la proposition en « expirée » et s'arrêtait là :
 * l'inscription restait « promue » et personne d'autre n'était servi. Un client qui répondait
 * « non merci » bloquait donc la place pour toute la file — et seule l'expiration planifiée, qui
 * n'est pas dans la liste blanche du lanceur, aurait fini par la débloquer.
 *
 * ⚠ CE TEST DEMANDE DEUX CHOSES QUI SE RESSEMBLENT ET NE SONT PAS LA MÊME. Que la proposition soit
 * close, n'importe quelle version du code le faisait. Ce qui manquait, c'est que la SUIVANTE soit
 * promue — donc l'assertion qui compte porte sur `$entree2`, pas sur la proposition refusée.
 *
 * ⚠ ET L'INSCRIPTION REFUSÉE PASSE À `Cancelled`, PAS À `Expired`. Refuser n'est pas ne pas avoir
 * répondu. `SlotWaitlistEntryStatus::Cancelled` existait dans l'enum sans que rien ne le pose ;
 * cette route en est désormais l'écrivain, et l'écran peut distinguer les deux.
 */
final class DeclineServesNextTest extends SmartFlowApiTestCase
{
    public function testRefuserUneProposionSertLaPersonneSuivante(): void
    {
        $idA = $this->establishmentId(SocleFixtures::ETAB_A_NOM);
        $etablissement = $this->em()->getRepository(Etablissement::class)->find(Uuid::fromString($idA));
        self::assertInstanceOf(Etablissement::class, $etablissement);

        $resourceId = Uuid::v4();
        $entree1 = $this->entree($etablissement, $resourceId, 1);
        $entree2 = $this->entree($etablissement, $resourceId, 2);

        // Une place se libère : la plus ancienne inscription est promue (RG-SF-06).
        $slotId = Uuid::v4();
        $this->libererCreneau($idA, $slotId, $resourceId);

        $em = $this->em();
        $em->refresh($entree1);
        $em->refresh($entree2);
        self::assertSame(SlotWaitlistEntryStatus::Promoted, $entree1->getStatus());
        self::assertSame(SlotWaitlistEntryStatus::Waiting, $entree2->getStatus());

        $proposition = $em->getRepository(RescheduleProposal::class)->find($entree1->getPromotedProposalRef());
        self::assertInstanceOf(RescheduleProposal::class, $proposition);

        // La personne refuse.
        [$client, $entete] = $this->managerOn(SocleFixtures::ETAB_A_NOM);
        $client->request('POST', '/api/smart-flow/reschedule-proposals/' . $proposition->getId() . '/decline', $entete);
        self::assertResponseIsSuccessful();

        $em->clear();
        $entree1 = $em->getRepository(SlotWaitlistEntry::class)->find($entree1->getId());
        $entree2 = $em->getRepository(SlotWaitlistEntry::class)->find($entree2->getId());
        $proposition = $em->getRepository(RescheduleProposal::class)->find($proposition->getId());

        self::assertSame(
            RescheduleProposalStatus::Expired,
            $proposition->getStatus(),
            'La proposition refusée est close.',
        );
        self::assertSame(
            SlotWaitlistEntryStatus::Cancelled,
            $entree1->getStatus(),
            'Refuser n’est pas ne pas avoir répondu : l’inscription est annulée, pas expirée.',
        );

        // ── L'ASSERTION QUI PORTE TOUT LE LOT ────────────────────────────────────────────────────
        self::assertSame(
            SlotWaitlistEntryStatus::Promoted,
            $entree2->getStatus(),
            'RG-SF-07 : refuser doit servir la personne suivante, sinon un « non merci » bloque la file.',
        );
        self::assertNotNull($entree2->getPromotedProposalRef());

        $suivante = $em->getRepository(RescheduleProposal::class)->find($entree2->getPromotedProposalRef());
        self::assertInstanceOf(RescheduleProposal::class, $suivante);
        self::assertSame(RescheduleProposalStatus::Proposed, $suivante->getStatus());
        self::assertSame(
            (string) $entree2->getBeneficiaryId(),
            (string) $suivante->getCustomerId(),
            'La nouvelle proposition va bien à la personne suivante.',
        );
    }

    /**
     * Le cas où il n'y a personne derrière : refuser doit clore proprement, sans erreur.
     *
     * Sans ce second cas, la promotion suivante pourrait planter sur une file vide et le premier
     * test ne le verrait jamais — il a toujours quelqu'un derrière.
     */
    public function testRefuserQuandPersonneNAttendDerriereNeLevePas(): void
    {
        $idA = $this->establishmentId(SocleFixtures::ETAB_A_NOM);
        $etablissement = $this->em()->getRepository(Etablissement::class)->find(Uuid::fromString($idA));
        self::assertInstanceOf(Etablissement::class, $etablissement);

        $resourceId = Uuid::v4();
        $seule = $this->entree($etablissement, $resourceId, 1);

        $this->libererCreneau($idA, Uuid::v4(), $resourceId);
        $em = $this->em();
        $em->refresh($seule);

        $proposition = $em->getRepository(RescheduleProposal::class)->find($seule->getPromotedProposalRef());
        self::assertInstanceOf(RescheduleProposal::class, $proposition);

        [$client, $entete] = $this->managerOn(SocleFixtures::ETAB_A_NOM);
        $client->request('POST', '/api/smart-flow/reschedule-proposals/' . $proposition->getId() . '/decline', $entete);
        self::assertResponseIsSuccessful();

        $em->clear();
        $seule = $em->getRepository(SlotWaitlistEntry::class)->find($seule->getId());
        self::assertSame(SlotWaitlistEntryStatus::Cancelled, $seule->getStatus());
    }

    private function entree(Etablissement $etablissement, Uuid $resourceId, int $rank): SlotWaitlistEntry
    {
        $entree = (new SlotWaitlistEntry())
            ->setEstablishment($etablissement)
            ->setResourceId($resourceId)
            ->setBeneficiaryId(Uuid::v4())
            ->setSearchWindowStart(new \DateTimeImmutable('+1 day'))
            ->setSearchWindowEnd(new \DateTimeImmutable('+7 days'))
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
