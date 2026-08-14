<?php

declare(strict_types=1);

namespace App\Tests\Vente\Api;

use App\Caisse\Entity\PointDeVente;
use App\Tests\Vente\VenteApiTestCase;
use App\Vente\Enum\TypeOperationScellee;
use App\Vente\Nf525\Entity\OperationScellee;
use App\Vente\Nf525\OperationInalterableException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Immuabilité ORM (CA-15 / US-L2-11) : une opération scellée est append-only ; toute tentative de
 * modification/suppression au niveau Doctrine lève OperationInalterableException.
 */
final class ImmuabiliteTest extends VenteApiTestCase
{
    public function testOperationScelleeInalterable(): void
    {
        static::createClient();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        $pdv = $em->getRepository(PointDeVente::class)->findOneBy([]);
        self::assertNotNull($pdv);

        $op = (new OperationScellee())
            ->setPointDeVente($pdv)
            ->setTypeOperation(TypeOperationScellee::Vente)
            ->setCibleType('Vente')
            ->setCibleId(Uuid::v4())
            ->setNumeroSequence(1)
            ->setEmpreinte('empreinte-initiale')
            ->setSignature('signature-initiale')
            ->setPayloadCanonique(['montant' => '10.00']);
        $em->persist($op);
        $em->flush();

        // Tentative de modification → interdite.
        $op->setEmpreinte('empreinte-falsifiee');
        $this->expectException(OperationInalterableException::class);
        $em->flush();
    }
}
