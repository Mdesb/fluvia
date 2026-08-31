<?php

declare(strict_types=1);

namespace App\Tests\Sport\Unit;

use App\Acces\Entity\EspaceAcces;
use App\Sport\Entity\EvenementSOS;
use App\Tests\Sport\SportApiTestCase;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

/**
 * SONDE — un paramètre de date sans type explicite compte-t-il ?
 *
 * L'hypothèse vient de la famille d'à côté : `setParameter('ref', $uuid)` sans `'uuid'` compare une
 * chaîne RFC 4122 à un `BINARY(16)` et ne matche RIEN, en silence. Si l'inférence de type se
 * trompait aussi sur `DateTimeImmutable`, la comparaison rendrait zéro sans lever — exactement ce
 * qu'a fait ma dérogation.
 *
 * On compte de quatre façons : sans condition de date, avec le paramètre nu, avec le type explicite,
 * et sans paramètre du tout. Une seule divergence suffit à nommer la cause.
 */
final class SondeDateTest extends SportApiTestCase
{
    public function testCommentCompteUnParametreDeDate(): void
    {
        static::createClient();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $espace = $em->getRepository(EspaceAcces::class)->findBy([], null, 1)[0];

        $e = new EvenementSOS();
        $e->setEspaceAcces($espace)->setHorodatage(new \DateTimeImmutable('-1 minute'));
        $em->persist($e);
        $em->flush();

        $depuis = new \DateTimeImmutable('-15 minutes');
        $base = fn () => $em->getRepository(EvenementSOS::class)->createQueryBuilder('e')->select('COUNT(e.id)');

        echo "\nsans condition            = " . (int) $base()->getQuery()->getSingleScalarResult() . "\n";
        echo 'espace seul               = ' . (int) $base()
            ->andWhere('e.espaceAcces = :espace')->setParameter('espace', $espace)
            ->getQuery()->getSingleScalarResult() . "\n";
        echo 'date, parametre nu        = ' . (int) $base()
            ->andWhere('e.horodatage >= :depuis')->setParameter('depuis', $depuis)
            ->getQuery()->getSingleScalarResult() . "\n";
        echo 'date, type explicite      = ' . (int) $base()
            ->andWhere('e.horodatage >= :depuis')
            ->setParameter('depuis', $depuis, Types::DATETIME_IMMUTABLE)
            ->getQuery()->getSingleScalarResult() . "\n";
        echo 'horodatage reel           = ' . $e->getHorodatage()->format('c') . "\n";
        echo 'seuil                     = ' . $depuis->format('c') . "\n";

        self::assertTrue(true);
    }
}
