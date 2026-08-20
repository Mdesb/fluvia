<?php

declare(strict_types=1);

namespace App\Tests\Subscription\Unit;

use App\Subscription\Entity\Plan;
use App\Subscription\Entity\Subscription;
use App\Subscription\Enum\SubscriptionStatus;
use App\Subscription\Exception\InvalidSubscriptionTransitionException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ED-2 — cycle de vie de l'abonnement (CA-4, CA-5, RG-ED-05, RG-ED-06).
 *
 * Deux propriétés comptent plus que les autres et sont testées pour elles-mêmes : **suspendre
 * n'efface rien**, et **un abonnement résilié ne redevient jamais actif**. La première est une
 * promesse commerciale, la seconde un garde-fou de facturation.
 */
final class SubscriptionTest extends TestCase
{
    private const CAPACITE_INCLUSE = 'controle_acces';
    private const OPTION = 'reservation';
    private const OPTION_2 = 'no_show';

    public function testUnAbonnementNaitEnBrouillonSansAucunDroit(): void
    {
        $abonnement = $this->abonnement();

        self::assertSame(SubscriptionStatus::Draft, $abonnement->getStatus());
        self::assertSame([], $abonnement->activeCapabilities($this->date('2026-09-15')));
        self::assertNull($abonnement->getStartedAt());
    }

    public function testLActivationOuvreLesCapacitesDeLaFormule(): void
    {
        $abonnement = $this->abonnement();
        $abonnement->transitionTo(SubscriptionStatus::Active, $this->date('2026-09-01'));

        self::assertSame([self::CAPACITE_INCLUSE], $abonnement->activeCapabilities($this->date('2026-09-15')));
        self::assertEquals($this->date('2026-09-01'), $abonnement->getStartedAt());
    }

    /** La date de début fait foi pour la facturation : elle ne bouge plus, même après une suspension. */
    public function testLaDateDeDebutNestFixeeQuUneFois(): void
    {
        $abonnement = $this->abonnementActif('2026-09-01');
        $abonnement->transitionTo(SubscriptionStatus::Suspended, $this->date('2026-10-05'));
        $abonnement->transitionTo(SubscriptionStatus::Active, $this->date('2026-10-08'));

        self::assertEquals($this->date('2026-09-01'), $abonnement->getStartedAt());
    }

    public function testUneOptionAjouteeOuvreSaCapaciteAPartirDeSaDate(): void
    {
        $abonnement = $this->abonnementActif('2026-09-01');
        $abonnement->addOption(self::OPTION, 1500, $this->date('2026-09-16'));

        self::assertSame([self::CAPACITE_INCLUSE], $abonnement->activeCapabilities($this->date('2026-09-10')));
        self::assertSame(
            [self::CAPACITE_INCLUSE, self::OPTION],
            $abonnement->activeCapabilities($this->date('2026-09-20')),
        );
    }

    /**
     * RG-ED-05 — rejouer l'ajout ne crée pas de doublon facturable.
     *
     * Un webhook bancaire se répète. Un abonnement qui facturerait deux fois la même option pour une
     * notification dupliquée serait un défaut visible sur le relevé du client, pas dans nos journaux.
     */
    public function testReajouterUneOptionCouranteNeCreePasDeDoublon(): void
    {
        $abonnement = $this->abonnementActif('2026-09-01');
        $abonnement->addOption(self::OPTION, 1500, $this->date('2026-09-16'));
        $abonnement->addOption(self::OPTION, 1500, $this->date('2026-09-16'));
        $abonnement->addOption(self::OPTION, 1500, $this->date('2026-09-20'));

        self::assertCount(1, $abonnement->getItems());
    }

    /** Retirer une option la laisse courir jusqu'à la fin de la période déjà payée. */
    public function testUneOptionRetireeCourtJusquaLaFinDeLaPeriodePayee(): void
    {
        $abonnement = $this->abonnementActif('2026-09-01');
        $abonnement->addOption(self::OPTION, 1500, $this->date('2026-09-01'));
        $abonnement->removeOption(self::OPTION, $this->date('2026-10-01'));

        self::assertContains(self::OPTION, $abonnement->activeCapabilities($this->date('2026-09-28')));
        self::assertNotContains(self::OPTION, $abonnement->activeCapabilities($this->date('2026-10-02')));
    }

    /** Une option retirée puis re-souscrite repart : la première ligne garde son historique. */
    public function testUneOptionPeutEtreResouscriteApresRetrait(): void
    {
        $abonnement = $this->abonnementActif('2026-09-01');
        $abonnement->addOption(self::OPTION, 1500, $this->date('2026-09-01'));
        $abonnement->removeOption(self::OPTION, $this->date('2026-10-01'));
        $abonnement->addOption(self::OPTION, 1800, $this->date('2026-11-01'));

        self::assertCount(2, $abonnement->getItems());
        self::assertContains(self::OPTION, $abonnement->activeCapabilities($this->date('2026-11-10')));
    }

    /**
     * RG-ED-06 — la suspension coupe l'exposition, **pas les données**.
     *
     * C'est la propriété qui rend la régularisation possible, et qui fait qu'un impayé ne détruit pas
     * la relation commerciale.
     */
    public function testLaSuspensionCoupeLexpositionSansPerdreLesOptions(): void
    {
        $abonnement = $this->abonnementActif('2026-09-01');
        $abonnement->addOption(self::OPTION, 1500, $this->date('2026-09-01'));
        $abonnement->addOption(self::OPTION_2, 900, $this->date('2026-09-01'));

        $abonnement->transitionTo(SubscriptionStatus::Suspended, $this->date('2026-10-05'));

        self::assertSame([], $abonnement->activeCapabilities($this->date('2026-10-06')));
        self::assertCount(2, $abonnement->getItems(), 'les options doivent survivre à la suspension');

        $abonnement->transitionTo(SubscriptionStatus::Active, $this->date('2026-10-08'));

        self::assertSame(
            [self::CAPACITE_INCLUSE, self::OPTION_2, self::OPTION],
            $abonnement->activeCapabilities($this->date('2026-10-09')),
        );
    }

    public function testUnAbonnementResilieNeDonnePlusAucunDroit(): void
    {
        $abonnement = $this->abonnementActif('2026-09-01');
        $abonnement->transitionTo(SubscriptionStatus::Cancelled, $this->date('2026-10-01'));

        self::assertSame([], $abonnement->activeCapabilities($this->date('2026-10-02')));
        self::assertEquals($this->date('2026-10-01'), $abonnement->getEndedAt());
    }

    /** État final : aucune méthode, aucun appelant distrait ne peut ressusciter un abonnement résilié. */
    public function testUnAbonnementResilieNeRedevientJamaisActif(): void
    {
        $abonnement = $this->abonnementActif('2026-09-01');
        $abonnement->transitionTo(SubscriptionStatus::Cancelled, $this->date('2026-10-01'));

        $this->expectException(InvalidSubscriptionTransitionException::class);
        $this->expectExceptionMessageMatches('/état final/');

        $abonnement->transitionTo(SubscriptionStatus::Active, $this->date('2026-10-02'));
    }

    public function testUnBrouillonNePeutPasEtreSuspendu(): void
    {
        $this->expectException(InvalidSubscriptionTransitionException::class);

        $this->abonnement()->transitionTo(SubscriptionStatus::Suspended, $this->date('2026-09-01'));
    }

    /** Rejouer une transition déjà atteinte est sans effet, et sans erreur : les webhooks se répètent. */
    public function testUneTransitionVersLetatCourantEstSansEffet(): void
    {
        $abonnement = $this->abonnementActif('2026-09-01');
        $abonnement->transitionTo(SubscriptionStatus::Active, $this->date('2026-09-20'));

        self::assertSame(SubscriptionStatus::Active, $abonnement->getStatus());
        self::assertEquals($this->date('2026-09-01'), $abonnement->getStartedAt());
    }

    #[DataProvider('transitionsAutorisees')]
    public function testLesTransitionsAutoriseesSontCellesDuContrat(
        SubscriptionStatus $depuis,
        SubscriptionStatus $vers,
        bool $autorisee,
    ): void {
        self::assertSame($autorisee, $depuis->canTransitionTo($vers));
    }

    /** @return iterable<string, array{SubscriptionStatus, SubscriptionStatus, bool}> */
    public static function transitionsAutorisees(): iterable
    {
        yield 'brouillon → actif' => [SubscriptionStatus::Draft, SubscriptionStatus::Active, true];
        yield 'brouillon → résilié' => [SubscriptionStatus::Draft, SubscriptionStatus::Cancelled, true];
        yield 'brouillon → suspendu' => [SubscriptionStatus::Draft, SubscriptionStatus::Suspended, false];
        yield 'actif → suspendu' => [SubscriptionStatus::Active, SubscriptionStatus::Suspended, true];
        yield 'actif → résilié' => [SubscriptionStatus::Active, SubscriptionStatus::Cancelled, true];
        yield 'suspendu → actif' => [SubscriptionStatus::Suspended, SubscriptionStatus::Active, true];
        yield 'suspendu → résilié' => [SubscriptionStatus::Suspended, SubscriptionStatus::Cancelled, true];
        yield 'résilié → actif' => [SubscriptionStatus::Cancelled, SubscriptionStatus::Active, false];
        yield 'résilié → suspendu' => [SubscriptionStatus::Cancelled, SubscriptionStatus::Suspended, false];
    }

    private function abonnement(): Subscription
    {
        $plan = (new Plan())
            ->setCode('essentiel')
            ->setLabel('Essentiel')
            ->setMonthlyPriceCents(4900)
            ->setIncludedCapabilities([self::CAPACITE_INCLUSE]);

        return (new Subscription())
            ->setCustomerReference('CLI-0001')
            ->setPlan($plan);
    }

    private function abonnementActif(string $depuis): Subscription
    {
        return $this->abonnement()->transitionTo(SubscriptionStatus::Active, $this->date($depuis));
    }

    private function date(string $jour): \DateTimeImmutable
    {
        return new \DateTimeImmutable($jour.' 00:00:00');
    }
}
