<?php

declare(strict_types=1);

namespace App\Tests\Platform\Integration;

use App\Caisse\Entity\PointDeVente;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Platform\Entity\Notification;
use App\Platform\Nf525\Alert\Nf525AlertConnection;
use App\Platform\Nf525\Alert\Nf525AlertOpenedListener;
use App\Platform\Nf525\Alert\Nf525AlertStore;
use App\Platform\Nf525\Alert\OpenedAlert;
use App\Platform\Nf525\SealAlertOrigin;
use App\Platform\Nf525\SealChainRef;
use App\Platform\Nf525\SealRefusalCause;
use App\Platform\Notification\Nf525AlertNotifier;
use App\Platform\Notification\NotificationRecipientResolver;
use App\Platform\Notification\NotificationRule;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Utilisateur;
use App\Tests\SocleApiTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * LA CLOCHE SONNE-T-ELLE EXACTEMENT UNE FOIS, CHEZ CEUX DE L'ÉTABLISSEMENT VISÉ, ET PAS PLUS ?
 *
 * ── LES PIÈGES ──────────────────────────────────────────────────────────────────────────────────
 *
 * - « Le lecteur ne reçoit rien » est vrai aussi quand personne ne reçoit rien : chaque test négatif a son
 *   témoin positif (l'administrateur reçoit).
 * - Les lignes sont écrites en SQL : sans le test d'étanchéité, une colonne ajoutée à `Notification` ne
 *   ferait échouer que la production.
 */
final class Nf525AlertBellTest extends SocleApiTestCase
{
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

    private function store(?Nf525AlertOpenedListener $listener = null): Nf525AlertStore
    {
        $resolver = static::getContainer()->get(NotificationRecipientResolver::class);
        self::assertInstanceOf(NotificationRecipientResolver::class, $resolver);

        return new Nf525AlertStore(new Nf525AlertConnection($this->db()), $listener ?? new Nf525AlertNotifier($resolver));
    }

    private function chain(string $label = 'Piscine'): SealChainRef
    {
        return SealChainRef::forSales((new PointDeVente())->setLibelle($label));
    }

    /** @param list<Uuid> $establishments */
    private function open(Nf525AlertStore $store, SealChainRef $chain, array $establishments): OpenedAlert
    {
        return $store->open($chain, SealRefusalCause::InvalidSignature, SealAlertOrigin::Refusal, 'detail technique', $establishments);
    }

    private function establishmentId(string $name): Uuid
    {
        $establishment = $this->em()->getRepository(Etablissement::class)->findOneBy(['nom' => $name]);
        self::assertInstanceOf(Etablissement::class, $establishment);

        return $establishment->getId();
    }

    private function userId(string $email): Uuid
    {
        $user = $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(Utilisateur::class, $user);

        return $user->getId();
    }

    /** @return list<array<string, mixed>> */
    private function bells(?Uuid $establishment = null): array
    {
        $sql = 'SELECT * FROM platform_notification WHERE source = ?';
        $args = ['nf525.chain_blocked'];
        if ($establishment !== null) {
            $sql .= ' AND etablissement_id = ?';
            $args[] = $establishment->toBinary();
        }

        return $this->db()->fetchAllAssociative($sql, $args);
    }

    /** @param list<array<string, mixed>> $rows @return list<string> */
    private function recipients(array $rows): array
    {
        $ids = array_map(static fn (array $r): string => Uuid::fromBinary((string) $r['destinataire_id'])->toRfc4122(), $rows);
        sort($ids);

        return $ids;
    }

    public function testEachAdministratorOfTheTargetedEstablishmentIsNotifiedOnce(): void
    {
        $a = $this->establishmentId(SocleFixtures::ETAB_A_NOM);
        $admin = $this->userId(SocleFixtures::ADMIN_EMAIL);
        $reader = $this->userId(SocleFixtures::LECTEUR_EMAIL);

        $alert = $this->open($this->store(), $this->chain('Caisse X'), [$a]);

        $rows = $this->bells($a);
        // Témoin positif d'abord.
        self::assertContains($admin->toRfc4122(), $this->recipients($rows));
        // Témoin négatif : le lecteur est affecté à A sans securite.gerer.
        self::assertNotContains($reader->toRfc4122(), $this->recipients($rows));
        self::assertCount(\count(array_unique($this->recipients($rows))), $rows, 'une ligne par destinataire');

        foreach ($rows as $row) {
            self::assertSame('critique', $row['gravite']);
            self::assertSame('alertes-nf525', $row['ecran']);
            self::assertSame(['alerte' => $alert->alertId], json_decode((string) $row['params'], true));
            self::assertSame('Chaîne fiscale bloquée — Caisse Caisse X', $row['titre']);
            self::assertSame(0, (int) $row['lue']);
            self::assertDoesNotMatchRegularExpression('/NF525_|[0-9a-f]{8}-[0-9a-f]{4}-/i', (string) $row['texte'].(string) $row['titre']);
        }
    }

    public function testASecondRefusalOnTheSameChainAddsNoNotification(): void
    {
        $a = $this->establishmentId(SocleFixtures::ETAB_A_NOM);
        $store = $this->store();
        $chain = $this->chain();

        $first = $this->open($store, $chain, [$a]);
        $before = \count($this->bells());
        self::assertGreaterThan(0, $before, 'témoin : la première ouverture a sonné');

        $second = $this->open($store, $chain, [$a]);

        self::assertFalse($second->opened);
        self::assertSame($first->alertId, $second->alertId);
        self::assertCount($before, $this->bells());
    }

    public function testTheTargetedEstablishmentDecidesNotTheCallersActiveOne(): void
    {
        $a = $this->establishmentId(SocleFixtures::ETAB_A_NOM);
        $b = $this->establishmentId(SocleFixtures::ETAB_B_NOM);

        // L'appelant serait actif sur A ; la chaîne est celle de B.
        $this->open($this->store(), $this->chain(), [$b]);

        self::assertNotSame([], $this->bells($b), 'témoin : les administrateurs de B sont prévenus');
        self::assertContains($this->userId(SocleFixtures::ADMIN_EMAIL)->toRfc4122(), $this->recipients($this->bells($b)));
        self::assertSame([], $this->bells($a), 'rien sur A, qui n\'est pas visé');
    }

    public function testAnAdministratorOfTwoTargetedEstablishmentsReceivesTwoLines(): void
    {
        $a = $this->establishmentId(SocleFixtures::ETAB_A_NOM);
        $b = $this->establishmentId(SocleFixtures::ETAB_B_NOM);
        $admin = $this->userId(SocleFixtures::ADMIN_EMAIL)->toRfc4122();

        $this->open($this->store(), $this->chain(), [$a, $b]);

        self::assertContains($admin, $this->recipients($this->bells($a)));
        self::assertContains($admin, $this->recipients($this->bells($b)));
        self::assertSame(2, \count(array_filter($this->recipients($this->bells()), static fn (string $id): bool => $id === $admin)));
    }

    public function testAChainWithoutEstablishmentRingsNothing(): void
    {
        $alert = $this->open($this->store(), $this->chain(), []);

        self::assertTrue($alert->opened, 'témoin : l\'alerte existe');
        self::assertSame([], $this->bells());
    }

    public function testInsertedColumnsAreExactlyTheNonNullColumnsOfTheEntityAndTheOrmReadsTheRowBack(): void
    {
        $a = $this->establishmentId(SocleFixtures::ETAB_A_NOM);
        $alert = $this->open($this->store(), $this->chain('Caisse Y'), [$a]);

        $meta = $this->em()->getClassMetadata(Notification::class);
        $expected = [];
        foreach ($meta->fieldMappings as $mapping) {
            if (!$mapping->nullable) {
                $expected[] = $mapping->columnName;
            }
        }
        foreach ($meta->associationMappings as $mapping) {
            foreach ($mapping->joinColumns ?? [] as $join) {
                if (!$join->nullable) {
                    $expected[] = $join->name;
                }
            }
        }
        sort($expected);

        $row = $this->bells($a)[0];
        $nonNull = array_keys(array_filter($row, static fn (mixed $v): bool => $v !== null));
        sort($nonNull);
        self::assertSame($expected, $nonNull);

        $this->em()->clear();
        $notification = $this->em()->find(Notification::class, Uuid::fromBinary((string) $row['id']));
        self::assertInstanceOf(Notification::class, $notification);
        self::assertSame(['alerte' => $alert->alertId], $notification->getParams());
        self::assertSame('nf525.chain_blocked', $notification->getSource());
        self::assertSame((string) $a, (string) $notification->getEtablissement()->getId());
        self::assertFalse($notification->isLue());
    }

    public function testTheRuleIsNotSubscribedOnTheBus(): void
    {
        self::assertNotNull(NotificationRule::forEvent('nf525.chain_blocked'));
        self::assertNotContains('nf525.chain_blocked', NotificationRule::admittedEvents());
        self::assertContains('payment.failed', NotificationRule::admittedEvents(), 'témoin : les autres règles restent admises');
    }

    public function testAFailingListenerLeavesNeitherAlertNorBell(): void
    {
        $a = $this->establishmentId(SocleFixtures::ETAB_A_NOM);
        $chain = $this->chain();
        $resolver = static::getContainer()->get(NotificationRecipientResolver::class);
        self::assertInstanceOf(NotificationRecipientResolver::class, $resolver);
        // Écrit les vraies lignes de cloche, PUIS tombe en panne : elles doivent partir avec l'alerte.
        $failing = new class(new Nf525AlertNotifier($resolver)) implements Nf525AlertOpenedListener {
            public function __construct(private readonly Nf525AlertNotifier $inner)
            {
            }

            public function onOpened(Connection $alertConnection, OpenedAlert $alert, SealChainRef $chain, array $establishmentIds): void
            {
                $this->inner->onOpened($alertConnection, $alert, $chain, $establishmentIds);
                $written = (int) $alertConnection->fetchOne('SELECT COUNT(*) FROM platform_notification');
                if ($written === 0) {
                    throw new \LogicException('témoin : le notifieur devait avoir écrit');
                }
                throw new \RuntimeException('panne');
            }
        };

        try {
            $this->open($this->store($failing), $chain, [$a]);
            self::fail('La panne du notifieur doit remonter.');
        } catch (\Throwable $e) {
            self::assertSame('panne', $e->getMessage());
        }

        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM platform_nf525_alert WHERE chain_key = ?', [$chain->key()]));
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM platform_notification'));
    }

    public function testEachEstablishmentIsResolvedOnItsOwn(): void
    {
        $a = $this->establishmentId(SocleFixtures::ETAB_A_NOM);
        $b = $this->establishmentId(SocleFixtures::ETAB_B_NOM);
        $adminRole = $this->em()->getRepository(Affectation::class)->findOneBy([
            'utilisateur' => $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => SocleFixtures::ADMIN_EMAIL]),
            'etablissement' => $this->em()->getRepository(Etablissement::class)->find($a),
        ])?->getRole();
        self::assertNotNull($adminRole);

        $adminRoleId = $adminRole->getId();
        $userA = (new Utilisateur())->setEmail('only-a@example.test')->setNom('Seul A')->setActif(true);
        $userA->setMotDePasse('x');
        $userB = (new Utilisateur())->setEmail('only-b@example.test')->setNom('Seul B')->setActif(true);
        $userB->setMotDePasse('x');
        $this->em()->persist($userA);
        $this->em()->persist($userB);
        $role = $this->em()->getReference($adminRole::class, $adminRoleId);
        foreach ([[$userA, $a], [$userB, $b]] as [$user, $establishment]) {
            $this->em()->persist(
                (new Affectation())->setUtilisateur($user)->setRole($role)
                    ->setEtablissement($this->em()->getReference(Etablissement::class, $establishment)),
            );
        }
        $this->em()->flush();
        $onlyA = $userA->getId();
        $onlyB = $userB->getId();
        $this->em()->clear();

        $this->open($this->store(), $this->chain(), [$a, $b]);

        $rowsA = array_filter($this->bells(), static fn (array $r): bool => Uuid::fromBinary((string) $r['destinataire_id'])->equals($onlyA));
        $rowsB = array_filter($this->bells(), static fn (array $r): bool => Uuid::fromBinary((string) $r['destinataire_id'])->equals($onlyB));
        self::assertCount(1, $rowsA, 'témoin A : une ligne');
        self::assertCount(1, $rowsB, 'témoin B : une ligne');
        self::assertSame($a->toRfc4122(), Uuid::fromBinary((string) array_values($rowsA)[0]['etablissement_id'])->toRfc4122());
        self::assertSame($b->toRfc4122(), Uuid::fromBinary((string) array_values($rowsB)[0]['etablissement_id'])->toRfc4122());
    }

    public function testADuplicatedEstablishmentRingsOnlyOncePerRecipient(): void
    {
        $a = $this->establishmentId(SocleFixtures::ETAB_A_NOM);

        $this->open($this->store(), $this->chain(), [$a, $a]);

        $rows = $this->bells($a);
        self::assertNotSame([], $rows, 'témoin : la cloche a sonné');
        self::assertSame($this->recipients($rows), array_values(array_unique($this->recipients($rows))));
    }
}
