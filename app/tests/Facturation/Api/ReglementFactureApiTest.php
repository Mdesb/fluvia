<?php

declare(strict_types=1);

namespace App\Tests\Facturation\Api;

use App\Compta\Entity\LettrageEcriture;
use App\Tests\Facturation\FacturationApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * US-FACT-04, RG-FACT-06 (CA-5) : un règlement total lettre la ligne « client » et solde la facture ;
 * un règlement partiel met à jour le solde sans lettrage.
 */
final class ReglementFactureApiTest extends FacturationApiTestCase
{
    public function testCa5ReglementTotalLettreEtPasse(): void
    {
        [$client, $entete] = $this->adminSurA();

        $brouillon = $client->request('POST', '/api/factures', $entete + [
            'json' => $this->corpsFactureDirecte(100.0),
        ])->toArray();
        $emise = $client->request('POST', '/api/factures/' . $brouillon['id'] . '/emettre', $entete)->toArray();
        self::assertSame('120.00', $emise['totalTTC']);

        $reponse = $client->request('POST', '/api/factures/' . $brouillon['id'] . '/reglements', $entete + [
            'json' => ['montant' => '120.00', 'moyen' => 'virement', 'reference' => 'VIR-001'],
        ]);
        self::assertSame(201, $reponse->getStatusCode());

        $facture = $client->request('GET', '/api/factures/' . $brouillon['id'], $entete)->toArray();
        self::assertSame('payee', $facture['statut']);
        self::assertSame('0.00', $facture['soldeDu']);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $nbLettrages = (int) $em->getRepository(LettrageEcriture::class)->createQueryBuilder('l')
            ->select('COUNT(l.id)')->getQuery()->getSingleScalarResult();
        self::assertSame(1, $nbLettrages, 'La ligne client a été lettrée (CA-5).');
    }

    public function testCa5ReglementPartielMetAJourSolde(): void
    {
        [$client, $entete] = $this->adminSurA();

        $brouillon = $client->request('POST', '/api/factures', $entete + [
            'json' => $this->corpsFactureDirecte(100.0),
        ])->toArray();
        $client->request('POST', '/api/factures/' . $brouillon['id'] . '/emettre', $entete);

        $client->request('POST', '/api/factures/' . $brouillon['id'] . '/reglements', $entete + [
            'json' => ['montant' => '50.00', 'moyen' => 'virement'],
        ]);

        $facture = $client->request('GET', '/api/factures/' . $brouillon['id'], $entete)->toArray();
        self::assertSame('partiellement_reglee', $facture['statut']);
        self::assertSame('70.00', $facture['soldeDu']);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $nbLettrages = (int) $em->getRepository(LettrageEcriture::class)->createQueryBuilder('l')
            ->select('COUNT(l.id)')->getQuery()->getSingleScalarResult();
        self::assertSame(0, $nbLettrages, 'Aucun lettrage tant que le solde n\'est pas à zéro (CA-5).');
    }
}
