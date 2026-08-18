<?php

declare(strict_types=1);

namespace App\Tests\Facturation;

use App\Facturation\Entity\Facture;
use App\Facturation\Enum\StatutFacture;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Inaltérabilité post-scellement (NF525, RG-FACT-01/04/05) : le contenu financier d'une facture émise
 * est bloqué en modification ; le statut (suivi) reste modifiable. Même patron que
 * `App\Tests\Compta\Api\ImmuabiliteTest`.
 */
final class FactureInalterableListenerTest extends FacturationApiTestCase
{
    public function testFactureScelleeRejetteModificationDuContenu(): void
    {
        [$client, $entete] = $this->adminSurA();

        $brouillon = $client->request('POST', '/api/factures', $entete + ['json' => $this->corpsFactureDirecte(100.0)])->toArray();
        $emise = $client->request('POST', '/api/factures/' . $brouillon['id'] . '/emettre', $entete)->toArray();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $facture = $em->getRepository(Facture::class)->find(Uuid::fromString($emise['id']));
        self::assertNotNull($facture);
        self::assertTrue($facture->estScellee());

        $facture->setTotalHT('999.99');

        $this->expectException(ConflictHttpException::class);
        $em->flush();
    }

    public function testStatutResteModifiableApresScellement(): void
    {
        [$client, $entete] = $this->adminSurA();

        $brouillon = $client->request('POST', '/api/factures', $entete + ['json' => $this->corpsFactureDirecte(100.0)])->toArray();
        $emise = $client->request('POST', '/api/factures/' . $brouillon['id'] . '/emettre', $entete)->toArray();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $facture = $em->getRepository(Facture::class)->find(Uuid::fromString($emise['id']));
        self::assertNotNull($facture);

        $facture->setStatut(StatutFacture::Echue);
        $em->flush();

        $em->clear();
        $verif = $em->getRepository(Facture::class)->find(Uuid::fromString($emise['id']));
        self::assertSame(StatutFacture::Echue, $verif->getStatut());
        self::assertSame('120.00', $verif->getTotalTTC(), 'Le contenu financier est resté intact.');
    }
}
