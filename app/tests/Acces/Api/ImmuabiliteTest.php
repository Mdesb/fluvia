<?php

declare(strict_types=1);

namespace App\Tests\Acces\Api;

use App\Acces\Entity\EspaceAcces;
use App\Acces\Entity\Passage;
use App\Acces\EventListener\PassageInalterableException;
use App\Tests\Acces\AccesApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Immuabilité ORM du journal des passages (CA-14, RG-SOCLE-07) : append-only — toute tentative de
 * modification/suppression au niveau Doctrine lève `PassageInalterableException`.
 */
final class ImmuabiliteTest extends AccesApiTestCase
{
    public function testPassageInalterableEnModification(): void
    {
        static::createClient();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        $espace = $em->getRepository(EspaceAcces::class)->findOneBy([]);
        self::assertNotNull($espace);

        $passage = new Passage();
        $passage->setEspace($espace);
        $em->persist($passage);
        $em->flush();

        $passage->setMotif('falsification a posteriori');
        $this->expectException(PassageInalterableException::class);
        $em->flush();
    }

    public function testPassageInalterableEnSuppression(): void
    {
        static::createClient();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        $espace = $em->getRepository(EspaceAcces::class)->findOneBy([]);
        self::assertNotNull($espace);

        $passage = new Passage();
        $passage->setEspace($espace);
        $em->persist($passage);
        $em->flush();

        // Doctrine invoque preRemove de manière synchrone dès `remove()` (pas seulement à `flush()`).
        $this->expectException(PassageInalterableException::class);
        $em->remove($passage);
    }
}
