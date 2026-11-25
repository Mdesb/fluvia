<?php

declare(strict_types=1);

namespace App\Tests\Compta\Integration;

use App\Compta\Entity\BordereauPayFiP;
use App\Compta\Service\TraiterRetourPayFipHandler;
use App\Tests\Compta\ComptaApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaValidator;
use Symfony\Component\Uid\Uuid;

/**
 * G-2 : le numéro client PayFiP envoyé à l'initiation est FIGÉ sur le bordereau de l'opération
 * (`compta_bordereau_payfip.client_number`), à côté du mode de saisie. Un bordereau créé hors initiation n'en a pas.
 */
final class BordereauPayFipClientNumberTest extends ComptaApiTestCase
{
    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine')->getManager();
    }

    private function storedNumber(BordereauPayFiP $bordereau): mixed
    {
        return $this->em()->getConnection()->fetchOne(
            'SELECT client_number FROM compta_bordereau_payfip WHERE id = ?',
            [$bordereau->getId()->toBinary()],
        );
    }

    public function testMappingIsValidForTheEntity(): void
    {
        $errors = (new SchemaValidator($this->em()))->validateClass($this->em()->getClassMetadata(BordereauPayFiP::class));

        self::assertSame([], $errors);
    }

    public function testRecordInitiationMemorisesTheNumberInTheColumn(): void
    {
        $handler = static::getContainer()->get(TraiterRetourPayFipHandler::class);
        $bordereau = $handler->initier(Uuid::v4(), 'idop-' . bin2hex(random_bytes(6)));
        $bordereau->recordInitiation('CMD000001', 1200, 'T', '123456');
        $this->em()->flush();

        // Témoin positif : la valeur est en base, relue en SQL brut.
        self::assertSame('123456', $this->storedNumber($bordereau));
        $this->em()->clear();
        self::assertSame('123456', $this->em()->getRepository(BordereauPayFiP::class)->find($bordereau->getId())->getClientNumber());
    }

    public function testABordereauCreatedWithoutInitiationHasNoNumber(): void
    {
        $handler = static::getContainer()->get(TraiterRetourPayFipHandler::class);
        $bordereau = $handler->initier(Uuid::v4(), 'idop-' . bin2hex(random_bytes(6)));

        self::assertNull($this->storedNumber($bordereau));
        self::assertNull($bordereau->getClientNumber());
        // Témoin que la requête lit bien la ligne : l'identifiant de l'opération y est.
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM compta_bordereau_payfip WHERE id = ?', [$bordereau->getId()->toBinary()]));
    }
}
