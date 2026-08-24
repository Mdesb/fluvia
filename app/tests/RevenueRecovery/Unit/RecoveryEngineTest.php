<?php

declare(strict_types=1);

namespace App\Tests\RevenueRecovery\Unit;

use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Platform\Event\DomainEvent;
use App\Platform\Event\EventSubject;
use App\Platform\Event\EventTenant;
use App\Reservation\Entity\Reservation;
use App\RevenueRecovery\Entity\RecoveryAttempt;
use App\RevenueRecovery\Entity\RecoveryCase;
use App\RevenueRecovery\Entity\RecoverySequence;
use App\RevenueRecovery\Enum\RecoveryAttemptStatus;
use App\RevenueRecovery\Enum\RecoveryCaseStatus;
use App\RevenueRecovery\Enum\RecoveryTriggerType;
use App\RevenueRecovery\Service\RecoveryEngine;
use App\Securite\Entity\Utilisateur;
use App\Tests\RevenueRecovery\RevenueRecoveryApiTestCase;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * `RecoveryEngine` (plan-revenue-recovery.md §0.7/T3) — machine à états au cœur du module. Test « Unit »
 * au sens du plan (§5), exécuté kernel démarré (base réelle, même patron que
 * `App\Tests\Recouvrement\Api\MoteurRecouvrementTest`) : `RecoveryEngine` interroge Doctrine directement,
 * un mock d'`EntityManagerInterface` ne couvrirait pas fidèlement ses requêtes.
 */
final class RecoveryEngineTest extends RevenueRecoveryApiTestCase
{
    /** RG-RR-02/US-RR-02 CA-2 : sans séquence active, aucun `RecoveryCase` n'est créé. */
    public function testEvenementSansSequenceConfigureeNouvreAucunCase(): void
    {
        $etablissement = $this->etablissementA();
        $evenement = $this->evenement($etablissement, 'Reservation', (string) Uuid::v4());

        $case = $this->engine()->handle($evenement, RecoveryTriggerType::BookingNoShow, 1000);

        self::assertNull($case);
        self::assertCount(0, $this->em()->getRepository(RecoveryCase::class)->findAll());
    }

    /** US-RR-02/CA-1 : séquence active -> RecoveryCase ouvert + tentatives programmées (min(steps, maxAttempts)). */
    public function testEvenementAvecSequenceActiveOuvreCaseEtProgrammePremiereAttempt(): void
    {
        $etablissement = $this->etablissementA();
        $this->creerSequenceActive($etablissement, RecoveryTriggerType::BookingNoShow, [
            ['delayDays' => 1, 'channel' => 'email', 'templateCode' => 'no_show_j1'],
            ['delayDays' => 3, 'channel' => 'email', 'templateCode' => 'no_show_j3'],
        ], 3);

        $subjectRef = (string) Uuid::v4();
        $evenement = $this->evenement($etablissement, 'Reservation', $subjectRef);

        $case = $this->engine()->handle($evenement, RecoveryTriggerType::BookingNoShow, 5000);

        self::assertInstanceOf(RecoveryCase::class, $case);
        self::assertSame(RecoveryCaseStatus::Active, $case->getStatus());
        self::assertSame($subjectRef, $case->getSubjectRef());
        self::assertSame(5000, $case->getAmountCents());

        $attempts = $this->em()->getRepository(RecoveryAttempt::class)->findBy(['recoveryCase' => $case->getId()], ['stepIndex' => 'ASC']);
        self::assertCount(2, $attempts, 'Les deux étapes de la séquence sont programmées dès l\'ouverture (§0.7 du plan).');
        self::assertEqualsWithDelta(
            $case->getOpenedAt()->modify('+1 day')->getTimestamp(),
            $attempts[0]->getScheduledAt()->getTimestamp(),
            2,
        );
        self::assertSame(RecoveryAttemptStatus::Pending, $attempts[0]->getStatus());
    }

    /** §11 spec, idempotence (⚠ HYPOTHÈSE) : une seconde occurrence pendant qu'un dossier est Active ne rouvre pas de second RecoveryCase. */
    public function testSecondeOccurrenceMemeSujetPendantCaseActifNouvrePasSecondCase(): void
    {
        $etablissement = $this->etablissementA();
        $this->creerSequenceActive($etablissement, RecoveryTriggerType::BookingNoShow, [
            ['delayDays' => 1, 'channel' => 'email', 'templateCode' => 'no_show_j1'],
        ], 3);

        $subjectRef = (string) Uuid::v4();
        $evenement = $this->evenement($etablissement, 'Reservation', $subjectRef);

        $premier = $this->engine()->handle($evenement, RecoveryTriggerType::BookingNoShow);
        $second = $this->engine()->handle($evenement, RecoveryTriggerType::BookingNoShow);

        self::assertNotNull($premier);
        self::assertSame($premier->getId()->toRfc4122(), $second?->getId()->toRfc4122());
        self::assertCount(1, $this->em()->getRepository(RecoveryCase::class)->findBy([
            'establishment' => $etablissement,
            'subjectRef' => $subjectRef,
        ]));
    }

    /** US-RR-03/CA-1, RG-RR-03 : client sans consentement Email accordé -> tentative sautée, jamais bloquante. */
    public function testConsentementAbsentTentativeSauteeSkippedNoConsent(): void
    {
        $etablissement = $this->etablissementA();
        $this->creerSequenceActive($etablissement, RecoveryTriggerType::BookingNoShow, [
            ['delayDays' => 0, 'channel' => 'email', 'templateCode' => 'no_show_j0'],
        ], 3);

        $beneficiaire = $this->beneficiairePayeur();
        $reservation = $this->em()->getRepository(Reservation::class)->findOneBy(['organisateur' => $beneficiaire]);
        self::assertInstanceOf(Reservation::class, $reservation, 'Réservation de démonstration introuvable (fixtures Reservation).');

        $evenement = $this->evenement($etablissement, 'Reservation', (string) $reservation->getId());
        $case = $this->engine()->handle($evenement, RecoveryTriggerType::BookingNoShow);
        self::assertInstanceOf(RecoveryCase::class, $case);

        // Aucun `Consentement` n'a été enregistré par les fixtures CRM pour ce client (RG-M4-07 : état
        // par défaut = non exploitable) — la tentative programmée à J+0 est immédiatement due.
        $resultat = $this->engine()->sendDueAttempts(new \DateTimeImmutable('+1 minute'));
        self::assertSame(1, $resultat['skipped']);
        self::assertSame(0, $resultat['sent']);

        $attempt = $this->em()->getRepository(RecoveryAttempt::class)->findOneBy(['recoveryCase' => $case->getId()]);
        self::assertSame(RecoveryAttemptStatus::Skipped, $attempt->getStatus());
        self::assertSame(RecoveryEngine::SKIP_REASON_NO_CONSENT, $attempt->getSkipReason());
    }

    /** US-RR-04/CA-1, RG-RR-04 : un événement de résolution clôt le dossier actif et annule les tentatives Pending restantes. */
    public function testArretAutomatiqueSurEvenementDeResolutionAnnuleTentativesRestantes(): void
    {
        $etablissement = $this->etablissementA();
        $this->creerSequenceActive($etablissement, RecoveryTriggerType::BookingNoShow, [
            ['delayDays' => 1, 'channel' => 'email', 'templateCode' => 'no_show_j1'],
            ['delayDays' => 3, 'channel' => 'email', 'templateCode' => 'no_show_j3'],
        ], 3);

        $subjectRef = (string) Uuid::v4();
        $evenement = $this->evenement($etablissement, 'Reservation', $subjectRef);
        $case = $this->engine()->handle($evenement, RecoveryTriggerType::BookingNoShow);
        self::assertInstanceOf(RecoveryCase::class, $case);

        $nbResolus = $this->engine()->resolve($etablissement->getId(), 'Reservation', $subjectRef);
        self::assertSame(1, $nbResolus);

        $this->em()->clear();
        $caseRelu = $this->em()->getRepository(RecoveryCase::class)->find($case->getId());
        self::assertSame(RecoveryCaseStatus::Resolved, $caseRelu->getStatus());
        self::assertNotNull($caseRelu->getResolvedAt());

        $attempts = $this->em()->getRepository(RecoveryAttempt::class)->findBy(['recoveryCase' => $case->getId()]);
        foreach ($attempts as $attempt) {
            self::assertSame(RecoveryAttemptStatus::Cancelled, $attempt->getStatus());
        }
    }

    /** US-RR-05/CA-2, RG-RR-05 : arrêt manuel sans motif refusé. */
    public function testArretManuelSansMotifRefuse(): void
    {
        $etablissement = $this->etablissementA();
        $sequence = $this->creerSequenceActive($etablissement, RecoveryTriggerType::BookingNoShow, [
            ['delayDays' => 1, 'channel' => 'email', 'templateCode' => 'no_show_j1'],
        ], 3);
        $case = $this->creerCaseActif($sequence);
        $agent = $this->creerAgent();

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->engine()->stopManually($case, $agent, '   ');
    }

    public function testArretManuelDunCaseDejaClosRefuse409(): void
    {
        $etablissement = $this->etablissementA();
        $sequence = $this->creerSequenceActive($etablissement, RecoveryTriggerType::BookingNoShow, [
            ['delayDays' => 1, 'channel' => 'email', 'templateCode' => 'no_show_j1'],
        ], 3);
        $case = $this->creerCaseActif($sequence);
        $agent = $this->creerAgent();

        $this->engine()->stopManually($case, $agent, 'Premier arrêt');

        $this->expectException(ConflictHttpException::class);
        $this->engine()->stopManually($case, $agent, 'Second arrêt');
    }

    /** §11 spec : séquence désactivée entre programmation et échéance -> la tentative n'est pas envoyée. */
    public function testSequenceDesactiveeEntreProgrammationEtEcheanceNenvoiePas(): void
    {
        $etablissement = $this->etablissementA();
        $sequence = $this->creerSequenceActive($etablissement, RecoveryTriggerType::BookingNoShow, [
            ['delayDays' => 0, 'channel' => 'email', 'templateCode' => 'no_show_j0'],
        ], 3);

        $evenement = $this->evenement($etablissement, 'Reservation', (string) Uuid::v4());
        $case = $this->engine()->handle($evenement, RecoveryTriggerType::BookingNoShow);
        self::assertInstanceOf(RecoveryCase::class, $case);

        $sequence->setActive(false);
        $this->em()->flush();

        $resultat = $this->engine()->sendDueAttempts(new \DateTimeImmutable('+1 minute'));
        self::assertSame(1, $resultat['cancelled']);
        self::assertSame(0, $resultat['sent']);

        $attempt = $this->em()->getRepository(RecoveryAttempt::class)->findOneBy(['recoveryCase' => $case->getId()]);
        self::assertSame(RecoveryAttemptStatus::Cancelled, $attempt->getStatus());
    }

    private function engine(): RecoveryEngine
    {
        return static::getContainer()->get(RecoveryEngine::class);
    }

    private function etablissementA(): Etablissement
    {
        return $this->entity(Etablissement::class, ['nom' => SocleFixtures::ETAB_A_NOM]);
    }

    private function evenement(Etablissement $etablissement, string $subjectType, string $subjectRef): DomainEvent
    {
        return new DomainEvent(
            'booking.no_show',
            new EventTenant($etablissement->getId()),
            new EventSubject($subjectType, $subjectRef),
            [],
        );
    }

    /** @param list<array{delayDays: int, channel: string, templateCode: string}> $steps */
    private function creerSequenceActive(Etablissement $etablissement, RecoveryTriggerType $triggerType, array $steps, int $maxAttempts): RecoverySequence
    {
        $sequence = (new RecoverySequence())
            ->setEstablishment($etablissement)
            ->setTriggerType($triggerType)
            ->setActive(true)
            ->setMaxAttempts($maxAttempts)
            ->setSteps($steps);

        $this->em()->persist($sequence);
        $this->em()->flush();

        return $sequence;
    }

    private function creerCaseActif(RecoverySequence $sequence): RecoveryCase
    {
        $case = (new RecoveryCase())
            ->setEstablishment($sequence->getEstablishment())
            ->setTriggerType($sequence->getTriggerType())
            ->setSubjectType('Reservation')
            ->setSubjectRef((string) Uuid::v4())
            ->setStatus(RecoveryCaseStatus::Active)
            ->setSequence($sequence)
            ->setOpenedAt(new \DateTimeImmutable());

        $this->em()->persist($case);
        $this->em()->flush();

        return $case;
    }

    private function creerAgent(): Utilisateur
    {
        $agent = (new Utilisateur())->setEmail('rr-agent-' . uniqid('', true) . '@itcotation.com')->setNom('Agent test')->setActif(true);
        $agent->setMotDePasse('x');
        $this->em()->persist($agent);
        $this->em()->flush();

        return $agent;
    }
}
