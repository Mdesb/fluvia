<?php

declare(strict_types=1);

namespace App\Tests\Subscription\Unit;

use App\Organisation\Entity\Etablissement;
use App\Subscription\Port\ConfigurationSnapshotProvider;
use App\Subscription\Service\DemoConfiguration;
use PHPUnit\Framework\TestCase;

/**
 * ED-3, CA-7 — la reprise du paramétrage de démo, et surtout ce qu'elle refuse de rejouer.
 *
 * Test unitaire contre des fournisseurs doubles, délibérément : ce qui est vérifié ici n'est pas ce
 * qu'un module sait exporter — cela lui appartient — mais les règles de l'orchestration. Elles
 * tiennent en une phrase : on rejoue ce qui a été acheté, rien d'autre, et on ne casse jamais une
 * souscription payée à cause d'un document imparfait.
 */
final class DemoConfigurationTest extends TestCase
{
    private const OFFRE = 'offre';
    private const RESERVATION = 'reservation';

    public function testLaCaptureRangeChaqueModuleSousSaCapacite(): void
    {
        $offre = $this->provider(self::OFFRE, ['tarifs' => [['code' => 'plein', 'cents' => 850]]]);
        $reservation = $this->provider(self::RESERVATION, ['creneaux' => ['09:00', '10:00']]);

        $document = (new DemoConfiguration([$offre, $reservation]))->capture(new Etablissement());

        self::assertSame(DemoConfiguration::FORMAT_VERSION, $document['version']);
        self::assertSame(
            [self::OFFRE, self::RESERVATION],
            array_keys($document['modules']),
        );
        self::assertSame(['creneaux' => ['09:00', '10:00']], $document['modules'][self::RESERVATION]);
    }

    /**
     * Un module sans configuration n'apparaît pas au document.
     *
     * Une clé vide se lirait « configuré à vide », ce qui n'est pas « pas configuré » — et un rejeu
     * pourrait alors effacer un défaut légitime chez le client.
     */
    public function testUnModuleSansConfigurationNestPasRange(): void
    {
        $document = (new DemoConfiguration([$this->provider(self::OFFRE, [])]))->capture(new Etablissement());

        self::assertSame([], $document['modules']);
    }

    /**
     * **La règle qui compte** : le rejeu ne touche pas un module non souscrit.
     *
     * Le prospect a pu essayer la réservation en démo sans l'acheter. Rejouer sa configuration
     * ouvrirait un service non facturé, que le client découvrirait le jour où on le lui retire.
     */
    public function testLeRejeuIgnoreUnModuleNonSouscrit(): void
    {
        $offre = $this->provider(self::OFFRE, ['tarifs' => ['plein']]);
        $reservation = $this->provider(self::RESERVATION, ['creneaux' => ['09:00']]);
        $service = new DemoConfiguration([$offre, $reservation]);

        $document = $service->capture(new Etablissement());
        $rejoues = $service->replay(new Etablissement(), $document, [self::OFFRE]);

        self::assertSame([self::OFFRE], $rejoues);
        self::assertTrue($offre->rejoue, 'le module acheté doit être rejoué');
        self::assertFalse($reservation->rejoue, 'le module non acheté ne doit pas l\'être');
    }

    /** Un document d'une version inconnue est ignoré, pas réinterprété au jugé. */
    public function testUnDocumentDeVersionInconnueEstIgnore(): void
    {
        $offre = $this->provider(self::OFFRE, ['tarifs' => ['plein']]);

        $rejoues = (new DemoConfiguration([$offre]))->replay(
            new Etablissement(),
            ['version' => 99, 'modules' => [self::OFFRE => ['tarifs' => ['plein']]]],
            [self::OFFRE],
        );

        self::assertSame([], $rejoues);
        self::assertFalse($offre->rejoue);
    }

    /**
     * Aucune démo derrière la souscription : le provisionnement continue sans broncher.
     *
     * C'est le cas majoritaire, pas un cas limite.
     */
    public function testSansDocumentLeProvisionnementNestPasInterrompu(): void
    {
        $offre = $this->provider(self::OFFRE, ['tarifs' => ['plein']]);

        self::assertSame([], (new DemoConfiguration([$offre]))->replay(new Etablissement(), null, [self::OFFRE]));
        self::assertFalse($offre->rejoue);
    }

    /**
     * Un module souscrit mais absent du document ne fait rien — et surtout n'échoue pas.
     *
     * Le client a acheté un module qu'il n'avait pas configuré en démo : il démarre avec la
     * configuration par défaut, ce qui est le comportement attendu, pas une erreur.
     */
    public function testUnModuleSouscritMaisAbsentDuDocumentNeCassePas(): void
    {
        $offre = $this->provider(self::OFFRE, ['tarifs' => ['plein']]);
        $reservation = $this->provider(self::RESERVATION, ['creneaux' => ['09:00']]);
        $service = new DemoConfiguration([$offre, $reservation]);

        $document = ['version' => DemoConfiguration::FORMAT_VERSION, 'modules' => [self::OFFRE => ['tarifs' => ['plein']]]];
        $rejoues = $service->replay(new Etablissement(), $document, [self::OFFRE, self::RESERVATION]);

        self::assertSame([self::OFFRE], $rejoues);
        self::assertFalse($reservation->rejoue);
    }

    /** @param array<string, mixed> $snapshot */
    private function provider(string $capability, array $snapshot): FakeSnapshotProvider
    {
        return new FakeSnapshotProvider($capability, $snapshot);
    }
}

/**
 * Fournisseur double : il rend l'instantané qu'on lui a donné, et note s'il a été rejoué.
 *
 * Classe nommée plutôt qu'anonyme pour que `$provider->rejoue` reste typé — un double qu'on interroge
 * mérite le même soin qu'un service.
 */
final class FakeSnapshotProvider implements ConfigurationSnapshotProvider
{
    public bool $rejoue = false;

    /** @param array<string, mixed> $snapshot */
    public function __construct(
        private readonly string $capability,
        private readonly array $snapshot,
    ) {
    }

    public function capability(): string
    {
        return $this->capability;
    }

    public function capture(Etablissement $source): array
    {
        return $this->snapshot;
    }

    public function replay(Etablissement $target, array $snapshot): void
    {
        $this->rejoue = true;
    }
}
