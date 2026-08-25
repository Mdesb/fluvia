<?php

declare(strict_types=1);

namespace App\Tests\RevenueRecovery\Api;

use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\RevenueRecovery\Entity\RecoveryAttempt;
use App\RevenueRecovery\Entity\RecoveryCase;
use App\RevenueRecovery\Entity\RecoverySequence;
use App\RevenueRecovery\Enum\RecoveryAttemptStatus;
use App\RevenueRecovery\Enum\RecoveryCaseStatus;
use App\RevenueRecovery\Enum\RecoveryChannel;
use App\RevenueRecovery\Enum\RecoveryTriggerType;
use App\Tests\RevenueRecovery\RevenueRecoveryApiTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * D3/D8, RG-RR-07 — cloisonnement établissement (plan-revenue-recovery.md §0.6) : un agent de
 * l'établissement A ne voit jamais un `RecoveryCase`/`RecoverySequence` de l'établissement B (test
 * explicitement demandé par la mission), échec fermé 404 jamais 403 (§0.6 pt.2 du plan).
 */
final class CloisonnementRevenueRecoveryTest extends RevenueRecoveryApiTestCase
{
    public function testEtablissementBNeVoitJamaisLesCasesDeA(): void
    {
        $sequenceA = $this->creerSequence(SocleFixtures::ETAB_A_NOM);
        $caseA = $this->creerCase($sequenceA);

        [$client, $entete] = $this->userWithPermissions(SocleFixtures::ETAB_B_NOM, ['read'], 'rr-cloison-b-' . uniqid('', true));

        $client->request('GET', '/api/revenue-recovery/cases/' . $caseA->getId(), $entete);
        self::assertResponseStatusCodeSame(404, (string) $client->getResponse()->getContent(false));

        $reponse = $client->request('GET', '/api/revenue-recovery/cases', $entete)->toArray();
        $membres = $reponse['member'] ?? $reponse['hydra:member'] ?? [];
        self::assertCount(0, $membres, 'B ne doit voir aucun RecoveryCase de A.');
    }

    public function testEtablissementBNeVoitJamaisLesSequencesDeA(): void
    {
        $sequenceA = $this->creerSequence(SocleFixtures::ETAB_A_NOM);

        [$client, $entete] = $this->userWithPermissions(SocleFixtures::ETAB_B_NOM, ['read'], 'rr-cloison-b-' . uniqid('', true));

        $client->request('GET', '/api/revenue-recovery/sequences/' . $sequenceA->getId(), $entete);
        self::assertResponseStatusCodeSame(404, (string) $client->getResponse()->getContent(false));
    }

    /** §0.6 pt.2 du plan : arrêt manuel d'un `RecoveryCase` hors périmètre -> 404, jamais 403 (RG-RR-07). */
    public function testArretManuelCaseHorsPerimetreRefuse404(): void
    {
        $sequenceA = $this->creerSequence(SocleFixtures::ETAB_A_NOM);
        $caseA = $this->creerCase($sequenceA);

        [$client, $entete] = $this->userWithPermissions(SocleFixtures::ETAB_B_NOM, ['read', 'manage'], 'rr-cloison-b-' . uniqid('', true));

        $client->request('POST', '/api/revenue-recovery/cases/' . $caseA->getId() . '/stop', $entete + [
            'json' => ['reason' => 'Tentative depuis un autre établissement'],
        ]);
        self::assertResponseStatusCodeSame(404, (string) $client->getResponse()->getContent(false));
    }

    private function creerSequence(string $nomEtablissement): RecoverySequence
    {
        $em = $this->em();
        $etablissement = $em->getRepository(Etablissement::class)->findOneBy(['nom' => $nomEtablissement]);
        self::assertInstanceOf(Etablissement::class, $etablissement);

        $sequence = (new RecoverySequence())
            ->setEstablishment($etablissement)
            ->setTriggerType(RecoveryTriggerType::BookingNoShow)
            ->setActive(true)
            ->setMaxAttempts(3)
            ->setSteps([['delayDays' => 1, 'channel' => 'email', 'templateCode' => 'no_show_j1']]);

        $em->persist($sequence);
        $em->flush();

        return $sequence;
    }

    private function creerCase(RecoverySequence $sequence): RecoveryCase
    {
        $em = $this->em();

        $case = (new RecoveryCase())
            ->setEstablishment($sequence->getEstablishment())
            ->setTriggerType(RecoveryTriggerType::BookingNoShow)
            ->setSubjectType('Reservation')
            ->setSubjectRef((string) Uuid::v4())
            ->setStatus(RecoveryCaseStatus::Active)
            ->setSequence($sequence)
            ->setOpenedAt(new \DateTimeImmutable());
        $em->persist($case);

        $attempt = (new RecoveryAttempt())
            ->setRecoveryCase($case)
            ->setStepIndex(0)
            ->setScheduledAt(new \DateTimeImmutable('+1 day'))
            ->setChannel(RecoveryChannel::Email)
            ->setStatus(RecoveryAttemptStatus::Pending);
        $em->persist($attempt);

        $em->flush();

        return $case;
    }
}
