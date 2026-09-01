<?php

declare(strict_types=1);

namespace App\Tests\Finance\Api;

use App\DataFixtures\SocleFixtures;
use App\Finance\DataFixtures\FinanceFixtures;
use App\Finance\SupplierInvoice\Entity\SupplierInvoice;
use App\Platform\Event\DomainEvent;
use App\Tests\Finance\TreasuryApiTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * D6 — même patron que FIN-2/FIN-3/FIN-4 (`EventTenantTreasuryTest`, `SupplierInvoiceEventTest`) : le
 * tenant de `treasury.threshold_breached` est dérivé de `TreasuryCashAlert.establishment` (donc de
 * `TreasurySettings.establishment`, l'entrée parcourue par la commande), **jamais** de
 * `ContexteEtablissement` — la commande n'a d'ailleurs aucun contexte HTTP (§0.7 du plan).
 */
final class EventTenantTreasuryThresholdTest extends TreasuryApiTestCase
{
    public function testTenantDeriveDeLetablissementDeLalerte(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $this->creerCompteBancaire($client, $entete, ['openingBalance' => '5000.00']);
        $client->request('POST', '/api/treasury_settings', $entete + [
            'json' => [
                'establishment' => '/api/etablissements/' . $this->idEtablissement(SocleFixtures::ETAB_A_NOM),
                'cashAlertThresholdCents' => 0,
                'cashAlertHorizonDays' => 30,
            ],
        ]);

        $idFournisseur = $this->idFournisseur(FinanceFixtures::FOURNISSEUR_ACTIF);
        $facture = $this->creerFactureAvecLigne($client, $entete, $idFournisseur, ['unitPriceExclTax' => '500.00', 'quantity' => '10.000'], 'FACT-TENANT-001');
        $client->request('POST', '/api/finance/supplier-invoices/' . $facture['id'] . '/approve', $entete);
        $entite = $this->em()->getRepository(SupplierInvoice::class)->find($facture['id']);
        self::assertInstanceOf(SupplierInvoice::class, $entite);
        $entite->setDueDate(new \DateTimeImmutable('today +5 days'));
        $this->em()->flush();

        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = static::getContainer()->get(EventDispatcherInterface::class);
        $captures = new \ArrayObject();
        $listener = static function (DomainEvent $event) use ($captures): void {
            $captures[] = $event;
        };
        $dispatcher->addListener('treasury.threshold_breached', $listener);

        try {
            $application = new Application(self::$kernel);
            $command = $application->find('finance:treasury:verifier-seuils');
            (new CommandTester($command))->execute([]);
        } finally {
            $dispatcher->removeListener('treasury.threshold_breached', $listener);
        }

        self::assertCount(1, $captures, 'Témoin absent : sans événement capturé, le tenant ne se mesure pas.');
        /** @var DomainEvent $evenement */
        $evenement = $captures[0];
        self::assertSame(
            $this->idEtablissement(SocleFixtures::ETAB_A_NOM),
            $evenement->tenant->establishmentId->toRfc4122(),
            'Le tenant doit être l\'établissement de TreasurySettings/TreasuryCashAlert, jamais un contexte HTTP (la commande n\'en a d\'ailleurs aucun).',
        );
    }
}
