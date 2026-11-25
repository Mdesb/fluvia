<?php

declare(strict_types=1);

namespace App\Tests\Crm\Api;

use App\Audit\Service\ClientAuditRedactor;
use App\Crm\Entity\Client;
use App\Crm\DataFixtures\CrmFixtures;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Tests\Crm\CrmApiTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * #363 — le rattrapage : les fiches DÉJÀ anonymisées dont l'audit garde l'identité.
 */
final class RedactAnonymisedAuditCommandTest extends CrmApiTestCase
{
    private const NAMES = ['Alpha', 'Bravo', 'Charlie'];

    public function testDryRunCountsAndWritesNothing(): void
    {
        $ids = $this->seedBacklog();
        $before = $this->allRows();

        $display = $this->runCommand(['--dry-run' => true]);

        self::assertStringContainsString('3 fiche(s), 6 entrée(s)', $display);
        self::assertStringContainsString('Simulation : rien n\'a été écrit', $display);
        self::assertSame($before, $this->allRows(), 'le mode essai ne doit modifier aucune ligne');
        foreach (self::NAMES as $name) {
            self::assertStringContainsString($name, $this->auditText($ids), 'témoin : l\'identité est toujours là');
        }
    }

    public function testLimitCountsRedactedCustomersNotExaminedOnes(): void
    {
        $ids = $this->seedBacklog();

        $first = $this->runCommand(['--limit' => '2']);
        self::assertStringContainsString('2 fiche(s), 4 entrée(s)', $first);
        self::assertSame(1, $this->unredactedCount($ids), 'une seule fiche reste à faire');

        // Un second passage au même plafond atteint la 3e : la limite ne compte pas les fiches propres déjà vues.
        $second = $this->runCommand(['--limit' => '2']);
        self::assertStringContainsString('1 fiche(s), 2 entrée(s)', $second);
        self::assertSame(0, $this->unredactedCount($ids));
    }

    public function testFullRunRedactsAllAnonymisedAndOnlyThose(): void
    {
        $ids = $this->seedBacklog();
        $active = $this->connection()->fetchAllAssociative('SELECT * FROM audit_entree WHERE cible_id = ? ORDER BY id', [$ids['active']]);
        $clean = $this->connection()->fetchAllAssociative('SELECT * FROM audit_entree WHERE cible_id = ? AND action <> ? ORDER BY id', [$ids['clean'], 'crm.rgpd.caviardage']);
        $foreignRows = $this->connection()->fetchAllAssociative('SELECT * FROM audit_entree WHERE cible_type <> ? AND action <> ? ORDER BY id', [Client::class, 'crm.rgpd.caviardage']);

        $display = $this->runCommand([]);

        self::assertStringContainsString('3 fiche(s), 6 entrée(s)', $display);
        $text = $this->auditText($ids);
        foreach (self::NAMES as $name) {
            self::assertStringNotContainsString($name, $text);
        }
        self::assertSame($active, $this->connection()->fetchAllAssociative('SELECT * FROM audit_entree WHERE cible_id = ? ORDER BY id', [$ids['active']]), 'la fiche active est intacte');
        self::assertSame($clean, $this->connection()->fetchAllAssociative('SELECT * FROM audit_entree WHERE cible_id = ? AND action <> ? ORDER BY id', [$ids['clean'], 'crm.rgpd.caviardage']));
        self::assertSame($foreignRows, $this->connection()->fetchAllAssociative('SELECT * FROM audit_entree WHERE cible_type <> ? AND action <> ? ORDER BY id', [Client::class, 'crm.rgpd.caviardage']), 'aucune autre classe n\'est touchée');

        // Une trace par fiche caviardée, signée de la tâche, rattachée à l'établissement de SA fiche.
        foreach (['a1', 'a2', 'c1'] as $key) {
            $trace = $this->connection()->fetchAllAssociative('SELECT * FROM audit_entree WHERE action = ? AND cible_id = ?', ['crm.rgpd.caviardage', $ids[$key]]);
            self::assertCount(1, $trace, "une trace pour $key");
            self::assertSame('tache:crm:rgpd:redact-audit', $trace[0]['auteur']);
            self::assertSame(['entrees' => 2], json_decode((string) $trace[0]['valeur_apres'], true));
            self::assertSame(
                $this->connection()->fetchOne('SELECT etablissement_creation_id FROM crm_client WHERE id = UNHEX(REPLACE(?, \'-\', \'\'))', [$ids[$key]]),
                $trace[0]['etablissement'],
                "la trace de $key porte l'établissement de création de SA fiche",
            );
        }
        $establishments = $this->connection()->fetchFirstColumn('SELECT DISTINCT etablissement FROM audit_entree WHERE action = ?', ['crm.rgpd.caviardage']);
        self::assertGreaterThanOrEqual(2, \count(array_filter($establishments, static fn ($e): bool => !\in_array($e, [null], true))), 'deux groupes, deux établissements');
    }

    public function testSecondRunFindsNothingAndWritesNothing(): void
    {
        $this->seedBacklog();
        $this->runCommand([]);
        $after = $this->allRows();

        $display = $this->runCommand([]);

        self::assertStringContainsString('0 fiche(s), 0 entrée(s)', $display);
        self::assertSame($after, $this->allRows(), 'un second passage n\'écrit aucune ligne');
    }

    public function testLimitReachedIsAnnouncedInDryRunAndRealRun(): void
    {
        $this->seedBacklog();

        $dryRun = $this->runCommand(['--dry-run' => true, '--limit' => '2']);
        self::assertStringContainsString('2 fiche(s), 4 entrée(s)', $dryRun);
        self::assertStringContainsString('limite atteinte : il peut en rester', $dryRun);

        // Témoin : sans limite atteinte, le message ne s'affiche pas.
        self::assertStringNotContainsString('limite atteinte', $this->runCommand(['--dry-run' => true]));

        self::assertStringContainsString('limite atteinte : il peut en rester', $this->runCommand(['--limit' => '2']));
    }

    public function testInvalidLimitIsRefused(): void
    {
        $this->seedBacklog();
        $tester = $this->commandTester();

        self::assertSame(Command::INVALID, $tester->execute(['--limit' => '0']));
        self::assertSame(Command::INVALID, $tester->execute(['--limit' => 'abc']));
    }

    /** @return array<string, string> */
    private function seedBacklog(): array
    {
        $this->adminSurA();
        $em = $this->em();
        $a = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        $c = $em->getRepository(Etablissement::class)->findOneBy(['nom' => CrmFixtures::ETAB_C_NOM]);

        $ids = [
            'a1' => $this->createCustomer($a, self::NAMES[0]),
            'a2' => $this->createCustomer($a, self::NAMES[1]),
            'c1' => $this->createCustomer($c, self::NAMES[2]),
            'active' => $this->createCustomer($a, 'Actif'),
            'clean' => $this->createCustomer($a, 'Propre'),
        ];
        // Les trois premières : « déjà anonymisées » avant le correctif — le statut change, l'audit garde l'identité.
        $this->connection()->executeStatement('UPDATE crm_client SET statut = ? WHERE id IN (UNHEX(REPLACE(?, \'-\', \'\')), UNHEX(REPLACE(?, \'-\', \'\')), UNHEX(REPLACE(?, \'-\', \'\')))', ['anonymise', $ids['a1'], $ids['a2'], $ids['c1']]);
        // Une fiche anonymisée ET déjà propre : passage par le service.
        $em->clear();
        $clean = $em->getRepository(Client::class)->find($ids['clean']);
        (new ClientAuditRedactor($em, static::getContainer()->get(\App\Audit\Service\JournalAudit::class)))->redact($clean);
        $this->connection()->executeStatement('UPDATE crm_client SET statut = ? WHERE id = UNHEX(REPLACE(?, \'-\', \'\'))', ['anonymise', $ids['clean']]);
        $em->flush();
        $em->clear();

        return $ids;
    }

    private function createCustomer(Etablissement $establishment, string $name): string
    {
        $em = $this->em();
        $c = (new Client())
            ->setGroupe($establishment->getRegion()?->getGroupe())
            ->setEtablissementCreation($establishment)
            ->setNom($name)
            ->setPrenom('Prenom' . $name)
            ->setEmail(strtolower($name) . '@example.test');
        $em->persist($c);
        $em->flush();
        $c->setTelephone('0600000000');
        $em->flush();

        return (string) $c->getId();
    }

    /** @param array<string, mixed> $options */
    private function runCommand(array $options): string
    {
        $tester = $this->commandTester();
        $tester->execute($options);
        $tester->assertCommandIsSuccessful();

        return $tester->getDisplay();
    }

    private function commandTester(): CommandTester
    {
        return new CommandTester((new Application(static::$kernel))->find('crm:rgpd:redact-audit'));
    }

    /** @param array<string, string> $ids */
    private function auditText(array $ids): string
    {
        $out = [];
        foreach ([$ids['a1'], $ids['a2'], $ids['c1']] as $id) {
            foreach ($this->connection()->fetchAllAssociative('SELECT valeur_avant, valeur_apres FROM audit_entree WHERE cible_type = ? AND cible_id = ?', [Client::class, $id]) as $l) {
                $out[] = json_encode([json_decode((string) $l['valeur_avant'], true), json_decode((string) $l['valeur_apres'], true)], JSON_UNESCAPED_UNICODE);
            }
        }

        return implode('|', $out);
    }

    /** @param array<string, string> $ids */
    private function unredactedCount(array $ids): int
    {
        $n = 0;
        foreach (['a1', 'a2', 'c1'] as $key) {
            if (!str_contains($this->auditText([...$ids, 'a1' => $ids[$key], 'a2' => $ids[$key], 'c1' => $ids[$key]]), ClientAuditRedactor::MARKER)) {
                ++$n;
            }
        }

        return $n;
    }

    /** @return list<array<string, mixed>> */
    private function allRows(): array
    {
        return $this->connection()->fetchAllAssociative('SELECT * FROM audit_entree ORDER BY id');
    }

    private function connection(): Connection
    {
        return $this->em()->getConnection();
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
