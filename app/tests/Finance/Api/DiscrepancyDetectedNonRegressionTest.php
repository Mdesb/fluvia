<?php

declare(strict_types=1);

namespace App\Tests\Finance\Api;

use App\Finance\Treasury\Entity\BankStatementLine;
use App\Platform\Enum\NotificationSeverity;
use App\Platform\Event\DomainEvent;
use App\Platform\Notification\NotificationRule;
use App\Tests\Finance\TreasuryApiTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * T9 du plan (revue de cohérence) — l'addendum FIN-4 « alertes de trésorerie proactives » touche des
 * fichiers **partagés** (`NotificationRule`, `ScheduleCatalog`, `FinanceModule`,
 * `PerimetreFinanceExtension`) : ce test prouve que `treasury.discrepancy_detected` (règle **et**
 * commande `finance:treasury:detecter-ecarts`) n'est affecté ni dans son comportement, ni dans son
 * couple `module`/`action`, ni dans sa gravité.
 */
final class DiscrepancyDetectedNonRegressionTest extends TreasuryApiTestCase
{
    public function testTreasuryDiscrepancyDetectedInchange(): void
    {
        $regle = NotificationRule::forEvent('treasury.discrepancy_detected');
        self::assertNotNull($regle);
        self::assertSame(NotificationSeverity::Critical, $regle->severity, 'Toujours le seul niveau critique du tableau (§0.8 du plan).');
        self::assertSame('compta', $regle->module);
        self::assertSame('lire', $regle->action);
        self::assertSame('comptabilite', $regle->screen);
        self::assertSame('rapprochement', $regle->paramName);

        [$client, $entete] = $this->adminSurA();
        $client->request('POST', '/api/treasury_settings', $entete + [
            'json' => ['establishment' => '/api/etablissements/' . $this->idEtablissement('Piscine A'), 'unmatchedAlertDelayDays' => 0],
        ]);

        $compte = $this->creerCompteBancaire($client, $entete);
        $import = $this->creerImportManuel($client, $entete, $compte['id']);
        $ligne = $this->creerLigneManuelle($client, $entete, $import['id'], '2026-08-01', 'Virement non identifié', '75.00');

        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = static::getContainer()->get(EventDispatcherInterface::class);
        $captures = new \ArrayObject();
        $listener = static function (DomainEvent $event) use ($captures): void {
            $captures[] = $event;
        };
        $dispatcher->addListener('treasury.discrepancy_detected', $listener);

        try {
            $application = new Application(self::$kernel);
            $command = $application->find('finance:treasury:detecter-ecarts');
            (new CommandTester($command))->execute([]);
        } finally {
            $dispatcher->removeListener('treasury.discrepancy_detected', $listener);
        }

        self::assertCount(1, $captures, 'finance:treasury:detecter-ecarts doit continuer à émettre normalement.');
        /** @var DomainEvent $evenement */
        $evenement = $captures[0];
        self::assertSame('BankStatementLine', $evenement->subject->type);
        self::assertSame($ligne['id'], $evenement->subject->id);
        self::assertArrayHasKey('bankAccountId', $evenement->payload);
        self::assertArrayHasKey('amountCents', $evenement->payload);
        self::assertArrayHasKey('unmatchedSinceDays', $evenement->payload);

        $ligneApres = $this->em()->getRepository(BankStatementLine::class)->find($ligne['id']);
        self::assertInstanceOf(BankStatementLine::class, $ligneApres);
        self::assertNotNull($ligneApres->getDiscrepancyNotifiedAt());
    }
}
