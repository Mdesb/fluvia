<?php

declare(strict_types=1);

namespace App\Tests\Finance\Api;

use App\DataFixtures\SocleFixtures;
use App\Finance\DataFixtures\FinanceFixtures;
use App\Finance\SupplierInvoice\Entity\SupplierInvoice;
use App\Finance\Treasury\Entity\TreasuryCashAlert;
use App\Finance\Treasury\Entity\TreasurySettings;
use App\Finance\Treasury\Enum\CashAlertStatus;
use App\Organisation\Entity\Etablissement;
use App\Platform\Event\DomainEvent;
use App\Tests\Finance\TreasuryApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * T4/T5 du plan (RG-TRE-11/13, §0.5) — `finance:treasury:verifier-seuils` : création, anti-répétition,
 * aggravation, amélioration, résolution silencieuse, gel de `thresholdCentsAtDetection`.
 */
final class VerifierSeuilsCommandTest extends TreasuryApiTestCase
{
    private ?\Closure $listener = null;

    /** RG-TRE-11/13 — première détection : `TreasuryCashAlert` créée, `treasury.threshold_breached` émis. */
    public function testFranchissementDetecteCreeUneAlerte(): void
    {
        [$client, $entete] = $this->adminSurA();
        $this->creerCompteBancaire($client, $entete, ['openingBalance' => '5000.00']);
        $this->configurerSeuil($client, $entete, 0, 30);
        $echeance = new \DateTimeImmutable('today +12 days');
        $facture = $this->approuverFactureFournisseur($client, $entete, $echeance, '500.00', '10.000', 'FACT-CMD-001');

        $captures = $this->ecouter();
        try {
            $this->executerCommande();
        } finally {
            $this->neCoutePlus();
        }

        self::assertCount(1, $captures, 'Premier passage : un événement doit être émis.');
        /** @var DomainEvent $evenement */
        $evenement = $captures[0];
        self::assertSame('treasury.threshold_breached', $evenement->name->value);
        self::assertSame($this->idEtablissement(SocleFixtures::ETAB_A_NOM), $evenement->tenant->establishmentId->toRfc4122());
        self::assertSame('TreasuryCashAlert', $evenement->subject->type);
        self::assertSame($echeance->format('Y-m-d'), $evenement->payload['projected_breach_date']);
        self::assertSame('supplier_invoice', $evenement->payload['cause_source']);
        self::assertSame($facture['id'], $evenement->payload['cause_source_id']);

        $alerte = $this->uniqueAlerte();
        self::assertSame(CashAlertStatus::Open, $alerte->getStatus());
        self::assertSame(0, $alerte->getThresholdCentsAtDetection());
        self::assertSame(30, $alerte->getHorizonDaysAtDetection());
        self::assertSame($echeance->format('Y-m-d'), $alerte->getProjectedBreachDate()?->format('Y-m-d'));
        self::assertNotNull($alerte->getLastNotifiedAt());
    }

    /** RG-TRE-13 — deux passages successifs sans changement de situation : une seule alerte, un seul événement. */
    public function testDeuxPassagesMemeDateUneSeuleNotification(): void
    {
        [$client, $entete] = $this->adminSurA();
        $this->creerCompteBancaire($client, $entete, ['openingBalance' => '5000.00']);
        $this->configurerSeuil($client, $entete, 0, 30);
        $this->approuverFactureFournisseur($client, $entete, new \DateTimeImmutable('today +12 days'), '500.00', '10.000', 'FACT-CMD-002');

        $captures = $this->ecouter();
        try {
            $this->executerCommande();
            self::assertCount(1, $captures, 'Premier passage : un événement doit être émis.');

            $this->executerCommande();
            self::assertCount(1, $captures, 'Second passage sans changement : aucun événement supplémentaire.');
        } finally {
            $this->neCoutePlus();
        }

        self::assertCount(1, $this->em()->getRepository(TreasuryCashAlert::class)->findAll(), 'Une seule TreasuryCashAlert doit exister.');
    }

    /** RG-TRE-13 — la date de franchissement avance strictement (aggravation) : nouvel événement sur la MÊME alerte. */
    public function testDateQuiAvanceReemetUnEvenement(): void
    {
        [$client, $entete] = $this->adminSurA();
        $this->creerCompteBancaire($client, $entete, ['openingBalance' => '5000.00']);
        $this->configurerSeuil($client, $entete, 0, 30);
        $facture = $this->approuverFactureFournisseur($client, $entete, new \DateTimeImmutable('today +12 days'), '500.00', '10.000', 'FACT-CMD-003');

        $captures = $this->ecouter();
        try {
            $this->executerCommande();
            self::assertCount(1, $captures);
            $idAlerteApresCreation = $this->uniqueAlerte()->getId();

            // La même facture voit son échéance avancer : le franchissement projeté est désormais plus proche.
            $entite = $this->em()->getRepository(SupplierInvoice::class)->find($facture['id']);
            self::assertInstanceOf(SupplierInvoice::class, $entite);
            $entite->setDueDate(new \DateTimeImmutable('today +5 days'));
            $this->em()->flush();

            $this->executerCommande();
            self::assertCount(2, $captures, 'Aggravation : un second événement doit être émis.');
        } finally {
            $this->neCoutePlus();
        }

        $alerte = $this->uniqueAlerte();
        self::assertSame((string) $idAlerteApresCreation, (string) $alerte->getId(), 'La MÊME entité doit être mise à jour, pas une nouvelle ligne.');
        self::assertSame((new \DateTimeImmutable('today +5 days'))->format('Y-m-d'), $alerte->getProjectedBreachDate()?->format('Y-m-d'));
    }

    /** RG-TRE-13 — la date de franchissement recule (amélioration) : mise à jour silencieuse, aucun événement supplémentaire. */
    public function testDateQuiRecouleNeReemetRien(): void
    {
        [$client, $entete] = $this->adminSurA();
        $this->creerCompteBancaire($client, $entete, ['openingBalance' => '5000.00']);
        $this->configurerSeuil($client, $entete, 0, 30);
        $facture = $this->approuverFactureFournisseur($client, $entete, new \DateTimeImmutable('today +5 days'), '500.00', '10.000', 'FACT-CMD-004');

        $captures = $this->ecouter();
        try {
            $this->executerCommande();
            self::assertCount(1, $captures);

            // La même facture voit son échéance reculer : le franchissement projeté est désormais plus tardif.
            $entite = $this->em()->getRepository(SupplierInvoice::class)->find($facture['id']);
            self::assertInstanceOf(SupplierInvoice::class, $entite);
            $entite->setDueDate(new \DateTimeImmutable('today +20 days'));
            $this->em()->flush();

            $this->executerCommande();
            self::assertCount(1, $captures, 'Amélioration : aucun événement supplémentaire.');
        } finally {
            $this->neCoutePlus();
        }

        $alerte = $this->uniqueAlerte();
        self::assertSame(CashAlertStatus::Open, $alerte->getStatus());
        self::assertSame((new \DateTimeImmutable('today +20 days'))->format('Y-m-d'), $alerte->getProjectedBreachDate()?->format('Y-m-d'));
    }

    /** RG-TRE-13 — plus aucun franchissement projeté : résolution silencieuse, aucun événement. */
    public function testAbsenceDeFranchissementResoutLAlerteSansEvenement(): void
    {
        [$client, $entete] = $this->adminSurA();
        $this->creerCompteBancaire($client, $entete, ['openingBalance' => '5000.00']);
        $this->configurerSeuil($client, $entete, 0, 30);
        $facture = $this->approuverFactureFournisseur($client, $entete, new \DateTimeImmutable('today +5 days'), '500.00', '10.000', 'FACT-CMD-005');

        $captures = $this->ecouter();
        try {
            $this->executerCommande();
            self::assertCount(1, $captures);

            // L'échéance sort de l'horizon de 30 jours : plus aucune sortie dans l'échéancier, plus de franchissement.
            $entite = $this->em()->getRepository(SupplierInvoice::class)->find($facture['id']);
            self::assertInstanceOf(SupplierInvoice::class, $entite);
            $entite->setDueDate(new \DateTimeImmutable('today +100 days'));
            $this->em()->flush();

            $this->executerCommande();
            self::assertCount(1, $captures, 'Résolution silencieuse : aucun événement supplémentaire.');
        } finally {
            $this->neCoutePlus();
        }

        $alerte = $this->uniqueAlerteToutStatut();
        self::assertSame(CashAlertStatus::Resolved, $alerte->getStatus());
        self::assertNotNull($alerte->getResolvedAt());
    }

    /** §0.5/§5 du plan — un seuil modifié après coup ne réécrit pas l'historique de l'alerte déjà ouverte. */
    public function testThresholdCentsAtDetectionNestJamaisReecrit(): void
    {
        [$client, $entete] = $this->adminSurA();
        $this->creerCompteBancaire($client, $entete, ['openingBalance' => '5000.00']);
        $this->configurerSeuil($client, $entete, 0, 30);
        $this->approuverFactureFournisseur($client, $entete, new \DateTimeImmutable('today +5 days'), '500.00', '10.000', 'FACT-CMD-006');

        $this->executerCommande();
        $alerte = $this->uniqueAlerte();
        self::assertSame(0, $alerte->getThresholdCentsAtDetection());

        // Le seuil change en base directement (hors API, pour ne pas déclencher la résolution silencieuse
        // du processor, réservée au cas `null`) : toujours en dessous du solde projeté, l'alerte reste ouverte.
        $settings = $this->em()->getRepository(TreasurySettings::class)->findOneBy(['establishment' => $this->etablissementA()->getId()]);
        self::assertInstanceOf(TreasurySettings::class, $settings);
        $settings->setCashAlertThresholdCents(-50000);
        $this->em()->flush();

        $this->executerCommande();

        $alerteApres = $this->uniqueAlerte();
        self::assertSame(0, $alerteApres->getThresholdCentsAtDetection(), 'thresholdCentsAtDetection doit rester figé à la valeur de la création.');
    }

    // ── Aides ───────────────────────────────────────────────────────────────────────────────────

    private function configurerSeuil(Client $client, array $entete, int $thresholdCents, int $horizonDays): string
    {
        $reponse = $client->request('POST', '/api/treasury_settings', $entete + [
            'json' => [
                'establishment' => '/api/etablissements/' . $this->idEtablissement(SocleFixtures::ETAB_A_NOM),
                'cashAlertThresholdCents' => $thresholdCents,
                'cashAlertHorizonDays' => $horizonDays,
            ],
        ])->toArray(false);

        return (string) ($reponse['id'] ?? '');
    }

    /** @return array<string, mixed> */
    private function approuverFactureFournisseur(
        Client $client,
        array $entete,
        \DateTimeImmutable $dueDate,
        string $unitPriceExclTax,
        string $quantity,
        string $numero,
    ): array {
        $idFournisseur = $this->idFournisseur(FinanceFixtures::FOURNISSEUR_ACTIF);
        $facture = $this->creerFactureAvecLigne($client, $entete, $idFournisseur, [
            'unitPriceExclTax' => $unitPriceExclTax,
            'quantity' => $quantity,
        ], $numero);
        $client->request('POST', '/api/finance/supplier-invoices/' . $facture['id'] . '/approve', $entete);

        $entite = $this->em()->getRepository(SupplierInvoice::class)->find($facture['id']);
        self::assertInstanceOf(SupplierInvoice::class, $entite);
        $entite->setDueDate($dueDate);
        $this->em()->flush();

        return $facture;
    }

    private function executerCommande(): CommandTester
    {
        $application = new Application(self::$kernel);
        $command = $application->find('finance:treasury:verifier-seuils');
        $tester = new CommandTester($command);
        $tester->execute([]);

        return $tester;
    }

    private function ecouter(): \ArrayObject
    {
        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = static::getContainer()->get(EventDispatcherInterface::class);
        $captures = new \ArrayObject();
        $this->listener = static function (DomainEvent $event) use ($captures): void {
            $captures[] = $event;
        };
        $dispatcher->addListener('treasury.threshold_breached', $this->listener);

        return $captures;
    }

    private function neCoutePlus(): void
    {
        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = static::getContainer()->get(EventDispatcherInterface::class);
        if ($this->listener !== null) {
            $dispatcher->removeListener('treasury.threshold_breached', $this->listener);
            $this->listener = null;
        }
    }

    private function uniqueAlerte(): TreasuryCashAlert
    {
        $alertes = $this->em()->getRepository(TreasuryCashAlert::class)->findBy(['status' => CashAlertStatus::Open]);
        self::assertCount(1, $alertes, 'Une seule alerte ouverte attendue.');

        return $alertes[0];
    }

    private function uniqueAlerteToutStatut(): TreasuryCashAlert
    {
        $alertes = $this->em()->getRepository(TreasuryCashAlert::class)->findAll();
        self::assertCount(1, $alertes, 'Une seule alerte attendue.');

        return $alertes[0];
    }

    private function etablissementA(): Etablissement
    {
        $etablissement = $this->em()->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        self::assertInstanceOf(Etablissement::class, $etablissement);

        return $etablissement;
    }
}
