<?php

declare(strict_types=1);

namespace App\Tests\Finance\Api;

use App\DataFixtures\SocleFixtures;
use App\Finance\DataFixtures\FinanceFixtures;
use App\Finance\Treasury\Entity\TreasuryCashAlert;
use App\Tests\Finance\TreasuryApiTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * T1 du plan (RG-TRE-10, §4.1 de la spec) — `cashAlertThresholdCents`/`cashAlertHorizonDays` sur
 * `TreasurySettings`.
 */
final class TreasurySettingsApiTest extends TreasuryApiTestCase
{
    /** RG-TRE-10 — `cashAlertThresholdCents = null` (défaut) : la commande ignore l'établissement, aucune alerte créée. */
    public function testSeuilNulDesactiveLaFonctionnalite(): void
    {
        [$client, $entete] = $this->adminSurA();
        $this->creerCompteBancaire($client, $entete, ['openingBalance' => '10.00']);

        // Réglage créé sans `cashAlertThresholdCents` : reste `null` (défaut, RG-TRE-10).
        $reponse = $client->request('POST', '/api/treasury_settings', $entete + [
            'json' => ['establishment' => '/api/etablissements/' . $this->idEtablissement(SocleFixtures::ETAB_A_NOM)],
        ])->toArray();
        self::assertNull($reponse['cashAlertThresholdCents']);
        self::assertSame(30, $reponse['cashAlertHorizonDays'], 'Défaut RG-TRE-10.');

        $idFournisseur = $this->idFournisseur(FinanceFixtures::FOURNISSEUR_ACTIF);
        $facture = $this->creerFactureAvecLigne($client, $entete, $idFournisseur, [], 'FACT-SEUIL-NUL-001');
        $client->request('POST', '/api/finance/supplier-invoices/' . $facture['id'] . '/approve', $entete);

        $application = new Application(self::$kernel);
        $command = $application->find('finance:treasury:verifier-seuils');
        (new CommandTester($command))->execute([]);

        self::assertSame(
            [],
            $this->em()->getRepository(TreasuryCashAlert::class)->findAll(),
            'Seuil désactivé (null) : la commande ne doit jamais créer d\'alerte pour cet établissement.',
        );
    }

    /** §4.1 de la spec — découvert autorisé : aucune contrainte de signe sur `cashAlertThresholdCents`. */
    public function testSeuilNegatifAccepte(): void
    {
        [$client, $entete] = $this->adminSurA();

        $reponse = $client->request('POST', '/api/treasury_settings', $entete + [
            'json' => [
                'establishment' => '/api/etablissements/' . $this->idEtablissement(SocleFixtures::ETAB_A_NOM),
                'cashAlertThresholdCents' => -300000,
                'cashAlertHorizonDays' => 15,
            ],
        ]);

        self::assertSame(201, $reponse->getStatusCode(), 'Une valeur négative doit être acceptée sans erreur de validation.');
        $corps = $reponse->toArray();
        self::assertSame(-300000, $corps['cashAlertThresholdCents']);
        self::assertSame(15, $corps['cashAlertHorizonDays']);
    }
}
