<?php

declare(strict_types=1);

namespace App\Tests\Finance\Api;

use App\Finance\Treasury\Entity\BankStatementLine;
use App\Platform\Event\DomainEvent;
use App\Tests\Finance\TreasuryApiTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * CA-6 (US-TRE-09, RG-TRE-09) — `finance:treasury:detecter-ecarts`, garde d'idempotence
 * `discrepancyNotifiedAt` (§0.9 du plan) : deux passages successifs de la commande sur la même ligne non
 * rapprochée n'émettent l'événement `treasury.discrepancy_detected` qu'**une seule fois**.
 */
final class DiscrepancyDetectionCommandTest extends TreasuryApiTestCase
{
    public function testEmissionUneSeuleFoisParLigneMemeApresDeuxPassagesDuCron(): void
    {
        [$client, $entete] = $this->adminSurA();

        // Délai à 0 jour : la ligne créée ci-dessous est déjà "au-delà du délai" au moment du passage
        // de la commande (§0.9 — le test n'a pas besoin de manipuler `createdAt` directement).
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
            self::assertCount(1, $captures, 'Premier passage : un événement doit être émis.');

            (new CommandTester($command))->execute([]);
            self::assertCount(1, $captures, 'Second passage : aucun événement supplémentaire (idempotence, CA-6).');
        } finally {
            $dispatcher->removeListener('treasury.discrepancy_detected', $listener);
        }

        /** @var DomainEvent $evenement */
        $evenement = $captures[0];
        self::assertSame('BankStatementLine', $evenement->subject->type);
        self::assertSame($ligne['id'], $evenement->subject->id);
        self::assertSame($this->idEtablissement('Piscine A'), $evenement->tenant->establishmentId->toRfc4122());

        $ligneApres = $this->em()->getRepository(BankStatementLine::class)->find($ligne['id']);
        self::assertInstanceOf(BankStatementLine::class, $ligneApres);
        self::assertNotNull($ligneApres->getDiscrepancyNotifiedAt());
    }

    public function testCompteInactifExcluDeLaDetection(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->request('POST', '/api/treasury_settings', $entete + [
            'json' => ['establishment' => '/api/etablissements/' . $this->idEtablissement('Piscine A'), 'unmatchedAlertDelayDays' => 0],
        ]);

        $compte = $this->creerCompteBancaire($client, $entete);
        $import = $this->creerImportManuel($client, $entete, $compte['id']);
        $ligne = $this->creerLigneManuelle($client, $entete, $import['id'], '2026-08-01', 'Virement non identifié', '75.00');

        $client->request('PATCH', '/api/bank_accounts/' . $compte['id'], $this->entetePatch($entete) + [
            'json' => ['active' => false],
        ]);

        $application = new Application(self::$kernel);
        $command = $application->find('finance:treasury:detecter-ecarts');
        (new CommandTester($command))->execute([]);

        $ligneApres = $this->em()->getRepository(BankStatementLine::class)->find($ligne['id']);
        self::assertInstanceOf(BankStatementLine::class, $ligneApres);
        self::assertNull($ligneApres->getDiscrepancyNotifiedAt(), 'Compte inactif : pas de détection (§7 cas limite spec).');
    }
}
