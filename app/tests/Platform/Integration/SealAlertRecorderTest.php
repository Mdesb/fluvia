<?php

declare(strict_types=1);

namespace App\Tests\Platform\Integration;

use App\Caisse\Entity\PointDeVente;
use App\Compta\Entity\ProfilExploitant;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Platform\Nf525\Alert\Nf525AlertConnection;
use App\Platform\Nf525\Alert\Nf525AlertOpenedListener;
use App\Platform\Nf525\Alert\Nf525AlertStore;
use App\Platform\Nf525\Alert\OpenedAlert;
use App\Platform\Nf525\Alert\SealAlertRecorder;
use App\Platform\Nf525\Alert\SealChainEstablishments;
use App\Platform\Nf525\SealAlertOrigin;
use App\Platform\Nf525\SealChainRef;
use App\Platform\Nf525\SealRefusalCause;
use App\Platform\Nf525\SealingRefusedException;
use App\Platform\Notification\Nf525AlertNotifier;
use App\Tests\SocleApiTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\AbstractLogger;
use Symfony\Component\Uid\Uuid;

/**
 * LE RECORDER ÉCRIT-IL L'ALERTE SANS JAMAIS MASQUER LE REFUS, ET NE FERME-T-IL QUE CE QU'IL DOIT ?
 *
 * ── LES PIÈGES ──────────────────────────────────────────────────────────────────────────────────
 *
 * - « Aucune référence » après une panne ne prouve rien si le même scénario SANS panne n'en donne pas : chaque
 *   panne a son témoin sain.
 * - « Aucune écriture » se prouve par l'absence de seconde connexion ouverte (compteur de fabrique), avec
 *   son témoin : le cas où une alerte existe en ouvre une.
 * - La table absente est simulée par un RENAME TABLE (DDL, hors transaction) remis en `finally`.
 */
final class SealAlertRecorderTest extends SocleApiTestCase
{
    public const SECRET = 'NF525_SEAL_KEY_SECRETVALUE-s3cr3t-password';

    private int $connectionsOpened = 0;

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }

    private function db(): Connection
    {
        return $this->em()->getConnection();
    }

    private function logger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
            public array $records = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };
    }

    /** @param (callable(Connection): Connection)|null $factory */
    private function recorder(AbstractLogger $logger, ?callable $factory = null, ?Nf525AlertOpenedListener $listener = null): SealAlertRecorder
    {
        $real = static fn (Connection $main): Connection => \Doctrine\DBAL\DriverManager::getConnection($main->getParams(), $main->getConfiguration());
        $counting = function (Connection $main) use ($factory, $real): Connection {
            ++$this->connectionsOpened;

            return $factory !== null ? $factory($main) : $real($main);
        };
        $store = new Nf525AlertStore(new Nf525AlertConnection($this->db(), 3, $counting), $listener);

        return new SealAlertRecorder($store, new SealChainEstablishments($this->em()), $this->em(), $logger);
    }

    private function pointOfSale(?string $establishmentName = SocleFixtures::ETAB_A_NOM, string $label = 'Piscine'): PointDeVente
    {
        $pos = (new PointDeVente())->setLibelle($label);
        if ($establishmentName !== null) {
            $establishment = $this->em()->getRepository(Etablissement::class)->findOneBy(['nom' => $establishmentName]);
            self::assertInstanceOf(Etablissement::class, $establishment);
            $pos->setEtablissement($establishment);
            $this->em()->persist($pos);
            $this->em()->flush();
        }

        return $pos;
    }

    private function establishment(string $name): Etablissement
    {
        $establishment = $this->em()->getRepository(Etablissement::class)->findOneBy(['nom' => $name]);
        self::assertInstanceOf(Etablissement::class, $establishment);

        return $establishment;
    }

    private function refusal(string $detail = 'detail technique'): SealingRefusedException
    {
        return new SealingRefusedException(SealRefusalCause::InvalidSignature, $detail);
    }

    /** @return list<array<string, mixed>> */
    private function alerts(SealChainRef $chain): array
    {
        return $this->db()->fetchAllAssociative('SELECT * FROM platform_nf525_alert WHERE chain_key = ?', [$chain->key()]);
    }

    private function seedAlert(SealChainRef $chain, SealAlertOrigin $origin): void
    {
        $this->db()->executeStatement(
            "INSERT INTO platform_nf525_alert (id, reference, chain_type, chain_key, chain_label, cause, origin, status, first_event_at, last_event_at, technical_detail)
             VALUES (?, ?, 'sales', ?, 'Caisse', 'unknown_key', ?, 'open', '2026-11-18 10:00:00', '2026-11-18 10:00:00', 'x')",
            [Uuid::v4()->toBinary(), 'NF525-' . strtoupper(bin2hex(random_bytes(4))), $chain->key(), $origin->value],
        );
    }

    public function testRefusalOnASalesChainCarriesTheReferenceAndTheAlertExists(): void
    {
        $chain = SealChainRef::forSales($this->pointOfSale());
        $refusal = $this->refusal('detail technique');

        $result = $this->recorder($this->logger())->recordRefusal($chain, $refusal);

        $rows = $this->alerts($chain);
        self::assertCount(1, $rows);
        self::assertNotSame($refusal, $result);
        self::assertMatchesRegularExpression('/Référence de l\'alerte : ' . preg_quote((string) $rows[0]['reference'], '/') . '\.$/', $result->getMessage());
        self::assertSame('refusal', $rows[0]['origin']);
        self::assertSame('invalid_signature', $rows[0]['cause']);
        self::assertSame('detail technique', $rows[0]['technical_detail']);
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM platform_nf525_alert_establishment WHERE alert_id = ?', [$rows[0]['id']]));
    }

    public function testASecondRefusalReusesTheReference(): void
    {
        $chain = SealChainRef::forSales($this->pointOfSale());
        $recorder = $this->recorder($this->logger());

        $first = $recorder->recordRefusal($chain, $this->refusal());
        $second = $recorder->recordRefusal($chain, $this->refusal());

        self::assertSame($first->reference(), $second->reference());
        self::assertCount(1, $this->alerts($chain));
    }

    public function testSecondConnectionOutageKeepsTheOriginalRefusalWithoutReferenceAndLogsCritical(): void
    {
        $chain = SealChainRef::forSales($this->pointOfSale());
        $logger = $this->logger();
        $refusal = $this->refusal();

        // Témoin sain : la même exception reçoit une référence quand la connexion marche.
        self::assertNotNull($this->recorder($this->logger())->recordRefusal(SealChainRef::forSales($this->pointOfSale(label: 'Autre')), $this->refusal())->reference());

        $result = $this->recorder($logger, static function (Connection $main): never {
            throw new \RuntimeException('SQLSTATE[HY000] [2002] ' . self::SECRET);
        })->recordRefusal($chain, $refusal);

        self::assertSame($refusal, $result);
        self::assertNull($result->reference());
        self::assertSame([], $this->alerts($chain));
        $levels = array_column($logger->records, 'level');
        self::assertContains('critical', $levels);
        $this->assertLogsDoNotLeak($logger);
    }

    public function testBellFailureCancelsTheAlertAndKeepsTheOriginalRefusal(): void
    {
        $chain = SealChainRef::forSales($this->pointOfSale());
        $logger = $this->logger();
        $refusal = $this->refusal();
        $failing = new class implements Nf525AlertOpenedListener {
            public function onOpened(Connection $alertConnection, OpenedAlert $alert, SealChainRef $chain, array $establishmentIds): void
            {
                throw new \RuntimeException('cloche en panne ' . SealAlertRecorderTest::SECRET);
            }
        };

        $result = $this->recorder($logger, null, $failing)->recordRefusal($chain, $refusal);

        self::assertSame($refusal, $result);
        self::assertSame([], $this->alerts($chain), 'la cloche annule l\'alerte entière (cloche ⇔ alerte)');
        self::assertContains('critical', array_column($logger->records, 'level'));
        $this->assertLogsDoNotLeak($logger);
    }

    public function testMissingAlertTableNeverMasksTheRefusalNorBreaksResolveOrVerification(): void
    {
        $chain = SealChainRef::forSales($this->pointOfSale());
        $logger = $this->logger();
        $refusal = $this->refusal();
        $recorder = $this->recorder($logger);

        $this->db()->executeStatement('RENAME TABLE platform_nf525_alert TO tmp_nf525_alert_absent');
        try {
            $result = $recorder->recordRefusal($chain, $refusal);
            $verification = $recorder->recordVerification($chain, false);
            $recorder->resolveOnSeal($chain);
            $closed = $recorder->recordVerification($chain, true);
        } finally {
            $this->db()->executeStatement('RENAME TABLE tmp_nf525_alert_absent TO platform_nf525_alert');
        }

        self::assertSame($refusal, $result);
        self::assertNull($verification);
        self::assertNull($closed);
        self::assertGreaterThanOrEqual(3, \count($logger->records));
        self::assertContains('critical', array_column($logger->records, 'level'));
        $this->assertLogsDoNotLeak($logger);
        // Témoin : la table remise, le même enregistrement aboutit.
        self::assertNotNull($recorder->recordRefusal($chain, $refusal)->reference());
    }

    public function testResolveOnSealWithoutAlertWritesNothing(): void
    {
        $chain = SealChainRef::forSales($this->pointOfSale());
        $recorder = $this->recorder($this->logger());

        $recorder->resolveOnSeal($chain);
        self::assertSame(0, $this->connectionsOpened, 'aucune seconde connexion, donc aucune écriture');
        self::assertSame([], $this->alerts($chain));

        // Témoin : avec une alerte de refus, la seconde connexion s'ouvre et l'alerte est fermée.
        $this->seedAlert($chain, SealAlertOrigin::Refusal);
        $recorder->resolveOnSeal($chain);
        self::assertSame(1, $this->connectionsOpened);
        $row = $this->alerts($chain)[0];
        self::assertSame('resolved', $row['status']);
        self::assertNotNull($row['resolved_at']);
    }

    public function testAnIntactVerificationWithoutOpenAlertOpensNoSecondConnection(): void
    {
        // Chaque remontée hors ligne vérifie la chaîne : sans alerte ouverte, aucune seconde connexion.
        $chain = SealChainRef::forSales($this->pointOfSale());
        $recorder = $this->recorder($this->logger());

        self::assertNull($recorder->recordVerification($chain, true));
        self::assertSame(0, $this->connectionsOpened);

        // Témoin : une alerte de refus ouverte ne déclenche pas non plus d'écriture ; une alerte de
        // vérification, si.
        $this->seedAlert($chain, SealAlertOrigin::Refusal);
        $recorder->recordVerification($chain, true);
        self::assertSame(0, $this->connectionsOpened);
        self::assertSame('open', $this->alerts($chain)[0]['status']);

        $other = SealChainRef::forSales($this->pointOfSale(label: 'Autre'));
        $this->seedAlert($other, SealAlertOrigin::Verification);
        $recorder->recordVerification($other, true);
        self::assertSame(1, $this->connectionsOpened);
        self::assertSame('resolved', $this->alerts($other)[0]['status']);
    }

    public function testResolveOnSealLeavesAVerificationAlertUntouched(): void
    {
        $chain = SealChainRef::forSales($this->pointOfSale());
        $this->seedAlert($chain, SealAlertOrigin::Verification);

        $this->recorder($this->logger())->resolveOnSeal($chain);

        self::assertSame(0, $this->connectionsOpened);
        $row = $this->alerts($chain)[0];
        self::assertSame('open', $row['status']);
        self::assertNull($row['resolved_at']);
    }

    public function testBrokenVerificationOpensAnAlertAndIntactClosesOnlyTheVerificationOne(): void
    {
        $verified = SealChainRef::forSales($this->pointOfSale());
        $refused = SealChainRef::forSales($this->pointOfSale(label: 'Autre'));
        $recorder = $this->recorder($this->logger());

        $reference = $recorder->recordVerification($verified, false, 'maillon 12');
        self::assertMatchesRegularExpression('/^NF525-[0-9A-F]{8}$/D', (string) $reference);
        $row = $this->alerts($verified)[0];
        self::assertSame('verification', $row['origin']);
        self::assertSame('chain_broken', $row['cause']);
        self::assertSame('maillon 12', $row['technical_detail']);
        self::assertSame(1, (int) $row['detection_count']);

        $this->seedAlert($refused, SealAlertOrigin::Refusal);

        // Intacte sur la chaîne d'origine refus : n'y touche pas ; sur l'autre : la ferme.
        self::assertNull($recorder->recordVerification($refused, true));
        self::assertSame('open', $this->alerts($refused)[0]['status']);
        self::assertNull($recorder->recordVerification($verified, true));
        $closed = $this->alerts($verified)[0];
        self::assertSame('resolved', $closed['status']);
        self::assertNotNull($closed['resolved_at']);
    }

    public function testEstablishmentsOfAPointOfSaleAreItsOwn(): void
    {
        $pos = $this->pointOfSale(SocleFixtures::ETAB_B_NOM);
        $ids = (new SealChainEstablishments($this->em()))->forChain(SealChainRef::forSales($pos));

        self::assertCount(1, $ids);
        self::assertTrue($ids[0]->equals($this->establishment(SocleFixtures::ETAB_B_NOM)->getId()));
    }

    public function testAPointOfSaleWithoutEstablishmentHasNone(): void
    {
        // Non persisté : aucune ligne, donc aucun établissement visé (et aucun plantage).
        $pos = $this->pointOfSale(null);

        self::assertSame([], (new SealChainEstablishments($this->em()))->forChain(SealChainRef::forSales($pos)));
    }

    public function testAProfileWithTwoEstablishmentsTargetsBothWithoutDuplicates(): void
    {
        $a = $this->establishment(SocleFixtures::ETAB_A_NOM);
        $b = $this->establishment(SocleFixtures::ETAB_B_NOM);
        $profile = new ProfilExploitant();
        $profile->setSiren('111222333')->setEtablissementPrincipal($a);
        $profile->setEtablissementsRattaches([$b, $a]);
        $this->em()->persist($profile);
        $this->em()->flush();

        $ids = (new SealChainEstablishments($this->em()))->forChain(SealChainRef::forInvoicing($profile));

        $found = array_map(static fn (Uuid $u): string => $u->toRfc4122(), $ids);
        sort($found);
        $expected = [$a->getId()->toRfc4122(), $b->getId()->toRfc4122()];
        sort($expected);
        self::assertSame($expected, $found);
    }

    public function testTypeErrorFromTheListenerOrTheFactoryKeepsTheOriginalRefusal(): void
    {
        $failing = new class implements Nf525AlertOpenedListener {
            public function onOpened(Connection $alertConnection, OpenedAlert $alert, SealChainRef $chain, array $establishmentIds): void
            {
                throw new \TypeError('type en panne');
            }
        };
        $refusal = $this->refusal();
        $chain = SealChainRef::forSales($this->pointOfSale());
        self::assertSame($refusal, $this->recorder($this->logger(), null, $failing)->recordRefusal($chain, $refusal));
        self::assertSame([], $this->alerts($chain));

        $refusal = $this->refusal();
        $result = $this->recorder($this->logger(), static function (Connection $main): never {
            throw new \TypeError('fabrique en panne');
        })->recordRefusal($chain, $refusal);
        self::assertSame($refusal, $result);
    }

    public function testAThrowingLoggerNeverReplacesTheRefusal(): void
    {
        $logger = new class extends AbstractLogger {
            public function log($level, \Stringable|string $message, array $context = []): void
            {
                throw new \RuntimeException('journal en panne');
            }
        };
        $refusal = $this->refusal();

        $result = $this->recorder($logger, static function (Connection $main): never {
            throw new \RuntimeException('panne');
        })->recordRefusal(SealChainRef::forSales($this->pointOfSale()), $refusal);

        self::assertSame($refusal, $result);
    }

    public function testTheContainerGivesTheStoreTheNotifierAsListener(): void
    {
        $recorder = static::getContainer()->get(SealAlertRecorder::class);
        self::assertInstanceOf(SealAlertRecorder::class, $recorder);

        $store = (new \ReflectionProperty(SealAlertRecorder::class, 'store'))->getValue($recorder);
        $listener = (new \ReflectionProperty(Nf525AlertStore::class, 'listener'))->getValue($store);

        self::assertInstanceOf(Nf525AlertNotifier::class, $listener);
    }

    private function assertLogsDoNotLeak(AbstractLogger $logger): void
    {
        $dump = json_encode($logger->records, \JSON_THROW_ON_ERROR | \JSON_PARTIAL_OUTPUT_ON_ERROR);
        self::assertStringNotContainsString('SECRETVALUE', $dump);
        self::assertStringNotContainsString('s3cr3t', $dump);
        $password = (string) ($this->db()->getParams()['password'] ?? '');
        if ($password !== '') {
            self::assertStringNotContainsString($password, $dump);
        }
    }
}
