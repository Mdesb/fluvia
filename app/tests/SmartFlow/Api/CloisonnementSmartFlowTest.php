<?php

declare(strict_types=1);

namespace App\Tests\SmartFlow\Api;

use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\SmartFlow\Entity\RescheduleProposal;
use App\SmartFlow\Enum\RescheduleProposalStatus;
use App\Tests\SmartFlow\SmartFlowApiTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * D3/D8, RG-SF-15/16 — cloisonnement établissement (plan-smart-flow.md §0.10) : un exploitant de
 * l'établissement A ne voit jamais une `RescheduleProposal` de l'établissement B (test explicitement
 * demandé par la mission). RG-SF-15/§0.10 : la restriction « own » (`smart_flow.reschedule_read_own`)
 * filtre par `customerId`.
 */
final class CloisonnementSmartFlowTest extends SmartFlowApiTestCase
{
    public function testPropositionDunAutreEtablissementInvisible404(): void
    {
        $propositionB = $this->creerProposition(SocleFixtures::ETAB_B_NOM, Uuid::v4());

        [$client, $entete] = $this->managerOn(SocleFixtures::ETAB_A_NOM);

        $client->request('GET', '/api/smart-flow/reschedule-proposals/' . $propositionB->getId(), $entete);
        self::assertResponseStatusCodeSame(404, (string) $client->getResponse()->getContent(false));

        $reponse = $client->request('GET', '/api/smart-flow/reschedule-proposals', $entete);
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString((string) $propositionB->getId(), (string) $reponse->getContent(false));
    }

    public function testClientNeVoitQueSesPropositions(): void
    {
        $clientA1Id = Uuid::v4();
        $clientA2Id = Uuid::v4();

        $propositionClient1 = $this->creerProposition(SocleFixtures::ETAB_A_NOM, $clientA1Id);
        $propositionClient2 = $this->creerProposition(SocleFixtures::ETAB_A_NOM, $clientA2Id);

        [$client, $entete] = $this->ownUserOn(SocleFixtures::ETAB_A_NOM, $clientA1Id, 'sf-own-client1');

        $reponse = $client->request('GET', '/api/smart-flow/reschedule-proposals', $entete)->toArray();
        $membres = $reponse['member'] ?? $reponse['hydra:member'] ?? [];
        self::assertCount(1, $membres, 'Le client 1 ne doit voir que sa propre proposition.');
        self::assertSame((string) $propositionClient1->getId(), $membres[0]['id']);

        $client->request('GET', '/api/smart-flow/reschedule-proposals/' . $propositionClient2->getId(), $entete);
        self::assertSame(404, $client->getResponse()->getStatusCode(), 'La proposition du client 2 est invisible au client 1.');
    }

    private function creerProposition(string $nomEtablissement, Uuid $customerId): RescheduleProposal
    {
        $em = $this->em();
        $etablissement = $em->getRepository(Etablissement::class)->findOneBy(['nom' => $nomEtablissement]);
        self::assertInstanceOf(Etablissement::class, $etablissement);

        $proposition = (new RescheduleProposal())
            ->setEstablishment($etablissement)
            ->setOriginReservationRef(Uuid::v4())
            ->setOriginSlotId(Uuid::v4())
            ->setCustomerId($customerId)
            ->setEntitlementRef(Uuid::v4())
            ->setStatus(RescheduleProposalStatus::Searching)
            ->setExpiresAt(new \DateTimeImmutable('+30 days'));

        $em->persist($proposition);
        $em->flush();

        return $proposition;
    }
}
