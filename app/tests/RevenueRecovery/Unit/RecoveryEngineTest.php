<?php

declare(strict_types=1);

namespace App\Tests\RevenueRecovery\Unit;

use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Platform\Event\DomainEvent;
use App\Platform\Event\EventSubject;
use App\Platform\Event\EventTenant;
use App\Platform\Notification\ClientNotification;
use App\Platform\Notification\ClientNotifierInterface;
use App\Platform\Notification\NotificationBasis;
use App\Platform\Notification\NotificationOutcome;
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
 *
 * **Migration `App\Platform\Notification\ClientNotifierInterface`** (port transverse, remplace
 * `RecoveryAttemptMailer` + la vérification maison de consentement) : `testConsentementAbsentTentative-
 * SauteeSkippedNoConsent` passe par la **vraie chaîne du container** (`ConsentGatedNotifier` ->
 * `LogClientNotifier`) — aucun consentement enregistré par les fixtures CRM pour ce client, donc
 * `Refusee`, mappé ici en `Skipped`. Les tests ci-dessous qui vérifient le mapping complet des quatre
 * `NotificationOutcome` (`Envoyee`/`Echouee`) et la base légale transmise remplacent
 * `ClientNotifierInterface` par un espion dans le container (même patron que
 * `App\Tests\Subscription\Integration\CourrielDeBienvenueTest`).
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

    /**
     * RG-RR-03 (mapping outcome -> statut) : `NotificationOutcome::Envoyee` marque la tentative `Sent`.
     *
     * ⚠ MAPPING BASIS À CONFIRMER PAR claude-A : ce test vérifie aussi que le déclencheur
     * `payment_failed` porte `NotificationBasis::Contractuelle` (relance d'impayé = fondement
     * contractuel, mapping provisoire de `RecoveryEngine::basisFor()`).
     */
    public function testSortieEnvoyeeMarqueTentativeSentEtBasisPaymentFailedEstContractuelle(): void
    {
        $etablissement = $this->etablissementA();
        $this->creerSequenceActive($etablissement, RecoveryTriggerType::PaymentFailed, [
            ['delayDays' => 0, 'channel' => 'email', 'templateCode' => 'impaye_j0'],
        ], 3);

        $beneficiaire = $this->beneficiairePayeur();
        $reservation = $this->em()->getRepository(Reservation::class)->findOneBy(['organisateur' => $beneficiaire]);
        self::assertInstanceOf(Reservation::class, $reservation, 'Réservation de démonstration introuvable (fixtures Reservation).');

        $espion = $this->espionnerNotifier(NotificationOutcome::Envoyee);

        $evenement = new DomainEvent(
            'payment.failed',
            new EventTenant($etablissement->getId()),
            new EventSubject('Reservation', (string) $reservation->getId()),
            [],
        );
        $case = $this->engine()->handle($evenement, RecoveryTriggerType::PaymentFailed, 4200);
        self::assertInstanceOf(RecoveryCase::class, $case);

        $resultat = $this->engine()->sendDueAttempts(new \DateTimeImmutable('+1 minute'));
        self::assertSame(1, $resultat['sent']);
        self::assertSame(0, $resultat['failed']);

        self::assertCount(1, $espion->recues, 'ClientNotifierInterface::notify() doit être appelé une fois.');
        self::assertSame(NotificationBasis::Contractuelle, $espion->recues[0]->basis);

        $attempt = $this->em()->getRepository(RecoveryAttempt::class)->findOneBy(['recoveryCase' => $case->getId()]);
        self::assertSame(RecoveryAttemptStatus::Sent, $attempt->getStatus());
        self::assertNotNull($attempt->getSentAt());
    }

    /** RG-RR-03 (mapping outcome -> statut) : `NotificationOutcome::Echouee` marque la tentative `Failed`, jamais bloquante. */
    public function testSortieEchoueeMarqueTentativeFailed(): void
    {
        $etablissement = $this->etablissementA();
        $this->creerSequenceActive($etablissement, RecoveryTriggerType::BookingNoShow, [
            ['delayDays' => 0, 'channel' => 'email', 'templateCode' => 'no_show_j0'],
        ], 3);

        $beneficiaire = $this->beneficiairePayeur();
        $reservation = $this->em()->getRepository(Reservation::class)->findOneBy(['organisateur' => $beneficiaire]);
        self::assertInstanceOf(Reservation::class, $reservation, 'Réservation de démonstration introuvable (fixtures Reservation).');

        $this->espionnerNotifier(NotificationOutcome::Echouee);

        $evenement = $this->evenement($etablissement, 'Reservation', (string) $reservation->getId());
        $case = $this->engine()->handle($evenement, RecoveryTriggerType::BookingNoShow);
        self::assertInstanceOf(RecoveryCase::class, $case);

        $resultat = $this->engine()->sendDueAttempts(new \DateTimeImmutable('+1 minute'));
        self::assertSame(1, $resultat['failed']);
        self::assertSame(0, $resultat['sent']);
        self::assertSame(0, $resultat['skipped']);

        $attempt = $this->em()->getRepository(RecoveryAttempt::class)->findOneBy(['recoveryCase' => $case->getId()]);
        self::assertSame(RecoveryAttemptStatus::Failed, $attempt->getStatus());
    }

    /**
     * Client non résolu par `RecoverySubjectCustomerResolver` (RG-RR-03/RG-RR-07) : la tentative est
     * sautée proprement, **aucun appel** n'est fait à `ClientNotifierInterface` (jamais d'envoi à
     * l'aveugle sans destinataire).
     */
    public function testClientNonResoluNappelleJamaisLeNotifierEtSauteLaTentative(): void
    {
        $etablissement = $this->etablissementA();
        $this->creerSequenceActive($etablissement, RecoveryTriggerType::BookingNoShow, [
            ['delayDays' => 0, 'channel' => 'email', 'templateCode' => 'no_show_j0'],
        ], 3);

        $espion = $this->espionnerNotifier(NotificationOutcome::Envoyee);

        // `subjectType = 'PaymentIncident'` n'est pas résolu par `RecoverySubjectCustomerResolver` en I1
        // (§0.8 du plan) : `resolveCustomerId()` renvoie toujours `null`.
        $evenement = $this->evenement($etablissement, 'PaymentIncident', (string) Uuid::v4());
        $case = $this->engine()->handle($evenement, RecoveryTriggerType::BookingNoShow);
        self::assertInstanceOf(RecoveryCase::class, $case);

        $resultat = $this->engine()->sendDueAttempts(new \DateTimeImmutable('+1 minute'));
        self::assertSame(1, $resultat['skipped']);
        self::assertSame(0, $resultat['sent']);
        self::assertCount(0, $espion->recues, 'Aucun appel au notifier sans client résolu.');

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

    /**
     * Remplace `ClientNotifierInterface` par un espion qui rend systématiquement `$sortie` (même patron
     * que `App\Tests\Subscription\Integration\CourrielDeBienvenueTest::espionner()`) — permet de vérifier
     * le mapping outcome -> statut de `RecoveryEngine::sendDueAttempts()` sans dépendre de la chaîne
     * réelle `ConsentGatedNotifier`/`LogClientNotifier`, qui ne rend jamais `Envoyee`/`Echouee`.
     *
     * @return object{recues: list<ClientNotification>}
     */
    private function espionnerNotifier(NotificationOutcome $sortie): object
    {
        $espion = new class($sortie) implements ClientNotifierInterface {
            /** @var list<ClientNotification> */
            public array $recues = [];

            public function __construct(private readonly NotificationOutcome $sortie)
            {
            }

            public function notify(ClientNotification $notification): NotificationOutcome
            {
                $this->recues[] = $notification;

                return $this->sortie;
            }
        };

        static::getContainer()->set(ClientNotifierInterface::class, $espion);

        return $espion;
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
