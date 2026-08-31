<?php

declare(strict_types=1);

namespace App\Tests\SmartFlow\Api;

use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Reservation\DataFixtures\ReservationFixtures;
use App\Reservation\Entity\Ressource;
use App\SmartFlow\Entity\RescheduleProposal;
use App\SmartFlow\Entity\SlotWaitlistEntry;
use App\SmartFlow\Enum\RescheduleProposalStatus;
use App\SmartFlow\Enum\SlotWaitlistEntryStatus;
use App\Tests\SmartFlow\SmartFlowApiTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * D3/D8, RG-SF-15/16 — cloisonnement établissement (plan-smart-flow.md §0.10) : un exploitant de
 * l'établissement A ne voit jamais une `RescheduleProposal`/`SlotWaitlistEntry` de l'établissement B
 * (test explicitement demandé par la mission). RG-SF-15/§0.10 : la restriction « own »
 * (`smart_flow.reschedule_read_own`) filtre par `customerId`. §3 point 4 du plan : `resourceId` hors
 * périmètre actif -> 422 sur `POST /smart-flow/waitlist-entries` (IDOR, I2).
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

    /** RG-SF-16/§3 point 4 du plan : une ressource de l'établissement A n'est pas dans le périmètre de B. */
    public function testInscriptionListeAttenteRessourceHorsPerimetreRefusee422(): void
    {
        $idRessourceEtabA = (string) $this->entity(Ressource::class, ['libelle' => ReservationFixtures::RESSOURCE_SALLE_LIBELLE])->getId();

        [$client, $entete] = $this->managerOn(SocleFixtures::ETAB_B_NOM);

        $client->request('POST', '/api/smart-flow/waitlist-entries', $entete + [
            'json' => [
                'resourceId' => $idRessourceEtabA,
                'beneficiaryId' => (string) Uuid::v4(),
                'searchWindowStart' => (new \DateTimeImmutable('+1 day'))->format(\DATE_ATOM),
                'searchWindowEnd' => (new \DateTimeImmutable('+7 days'))->format(\DATE_ATOM),
            ],
        ]);
        self::assertResponseStatusCodeSame(422, (string) $client->getResponse()->getContent(false));
    }

    public function testSlotWaitlistEntryDunAutreEtablissementInvisible(): void
    {
        $entreeB = $this->creerEntreeListeAttente(SocleFixtures::ETAB_B_NOM);

        [$client, $entete] = $this->userWithSmartFlowPermissions(SocleFixtures::ETAB_A_NOM, ['read'], 'sf-read-' . uniqid('', true));

        $client->request('GET', '/api/smart-flow/waitlist-entries/' . $entreeB->getId(), $entete);
        self::assertResponseStatusCodeSame(404, (string) $client->getResponse()->getContent(false));

        $reponse = $client->request('GET', '/api/smart-flow/waitlist-entries', $entete);
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString((string) $entreeB->getId(), (string) $reponse->getContent(false));
    }

    /**
     * Défaut corrigé (revue de cohérence, ce lot) : `CreateSlotWaitlistEntryProcessor` calculait
     * `MAX(rank)` par `resourceId` seul (colonne UUID opaque, RG-SF-17), sans filtrer sur
     * l'établissement — une `SlotWaitlistEntry` d'un autre établissement portant, par accident ou abus,
     * le même UUID de ressource, aurait pu décaler le `rank` attribué en A. Cette entrée B ne doit
     * jamais influencer le `rank` calculé en A.
     */
    public function testRangListeAttenteScopeParEtablissementIgnoreLesEntreesDunAutreEtablissement(): void
    {
        $ressourceA = $this->entity(Ressource::class, ['libelle' => ReservationFixtures::RESSOURCE_SALLE_LIBELLE]);

        // Entrée dans l'établissement B, sur le **même** UUID de ressource que celui utilisé côté A
        // ci-dessous (colonne opaque, aucune contrainte d'unicité inter-établissements) — simule le
        // scénario de défense en profondeur visé par le correctif.
        $this->creerEntreeListeAttente(SocleFixtures::ETAB_B_NOM, $ressourceA->getId(), 5);

        [$client, $entete] = $this->managerOn(SocleFixtures::ETAB_A_NOM);
        $reponse = $client->request('POST', '/api/smart-flow/waitlist-entries', $entete + [
            'json' => [
                'resourceId' => (string) $ressourceA->getId(),
                'beneficiaryId' => (string) Uuid::v4(),
                'searchWindowStart' => (new \DateTimeImmutable('+1 day'))->format(\DATE_ATOM),
                'searchWindowEnd' => (new \DateTimeImmutable('+7 days'))->format(\DATE_ATOM),
            ],
        ])->toArray();

        self::assertSame(1, $reponse['rank'], 'Le rank 5 de l\'entrée B ne doit pas influencer le rank attribué en A.');
    }

    private function creerEntreeListeAttente(string $nomEtablissement, ?Uuid $resourceId = null, int $rank = 1): SlotWaitlistEntry
    {
        $em = $this->em();
        $etablissement = $em->getRepository(Etablissement::class)->findOneBy(['nom' => $nomEtablissement]);
        self::assertInstanceOf(Etablissement::class, $etablissement);

        $entry = (new SlotWaitlistEntry())
            ->setEstablishment($etablissement)
            ->setResourceId($resourceId ?? Uuid::v4())
            ->setBeneficiaryId(Uuid::v4())
            ->setSearchWindowStart(new \DateTimeImmutable('+1 day'))
            ->setSearchWindowEnd(new \DateTimeImmutable('+7 days'))
            ->setRank($rank)
            ->setStatus(SlotWaitlistEntryStatus::Waiting);

        $em->persist($entry);
        $em->flush();

        return $entry;
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
