<?php

declare(strict_types=1);

namespace App\Tests\Compta\Integration;

use App\Tests\Compta\ComptaApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaValidator;

/**
 * G-1 : le numéro client PayFiP est stocké en clair dans `compta_profil_exploitant.payfip_client_number`,
 * `NULL` par défaut.
 */
final class PayFipClientNumberPersistenceTest extends ComptaApiTestCase
{
    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine')->getManager();
    }

    private function stored(string $profileId): mixed
    {
        return $this->em()->getConnection()->fetchOne(
            'SELECT payfip_client_number FROM compta_profil_exploitant WHERE id = ?',
            [(new \Symfony\Component\Uid\Uuid($profileId))->toBinary()],
        );
    }

    public function testMappingIsValidForTheEntity(): void
    {
        $errors = (new SchemaValidator($this->em()))->validateClass($this->em()->getClassMetadata(\App\Compta\Entity\ProfilExploitant::class));

        self::assertSame([], $errors);
    }

    public function testAnExistingProfileHasNoNumberByDefault(): void
    {
        $profile = $this->profilExploitant();

        self::assertNull($this->stored((string) $profile->getId()));
    }

    public function testTheNumberRoundTripsInTheColumn(): void
    {
        $em = $this->em();
        $profile = $this->profilExploitant();
        $profile->setPayfipClientNumber('123456');
        $em->flush();

        // Témoin positif : la valeur est bien dans la colonne, en clair.
        self::assertSame('123456', $this->stored((string) $profile->getId()));

        $em->clear();
        self::assertSame('123456', $this->profilExploitant()->getPayfipClientNumber());

        $this->profilExploitant()->setPayfipClientNumber(null);
        $em->flush();
        self::assertNull($this->stored((string) $profile->getId()));
    }
}
