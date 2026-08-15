<?php

declare(strict_types=1);

namespace App\Tests\Compta\Api;

use App\Compta\Entity\BordereauPayFiP;
use App\Tests\Compta\ComptaApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * US-L4-03, RG-PAYFIP-03 (CA-6) : retour PayFiP « OK » → référence stockée, rapprochement automatique
 * ; retour manquant → transaction rejouable depuis le journal PayFiP.
 */
final class PayFipTest extends ComptaApiTestCase
{
    public function testRetourOkRapprocheLaTransaction(): void
    {
        [$client, $entete] = $this->adminSurA();
        $venteId = (string) Uuid::v4();

        $bordereau = $client->request('POST', '/api/compta/payfip/retour', $entete + [
            'json' => ['venteOrigine' => $venteId, 'referenceTransaction' => 'PAYFIP-TEST-001', 'statut' => 'ok'],
        ])->toArray();

        self::assertSame('ok', $bordereau['statutRetour']);
        self::assertTrue($bordereau['venteRapprochee']);
        self::assertSame('PAYFIP-TEST-001', $bordereau['referenceTransaction']);
    }

    public function testRetourManquantResteEnAttenteEtEstRejouable(): void
    {
        [$client, $entete] = $this->adminSurA();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $bordereau = new BordereauPayFiP();
        $bordereau->setVenteOrigine(Uuid::v4());
        $bordereau->setReferenceTransaction('PAYFIP-TEST-002');
        $em->persist($bordereau);
        $em->flush();

        self::assertSame('en_attente', $bordereau->getStatutRetour()->value);

        $reponse = $client->request('POST', '/api/compta/payfip/' . $bordereau->getId() . '/rejouer', $entete)->toArray();
        self::assertSame(1, $reponse['nbTentativesRejeu']);
    }
}
