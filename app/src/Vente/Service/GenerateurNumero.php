<?php

declare(strict_types=1);

namespace App\Vente\Service;

use App\Caisse\Entity\PointDeVente;
use App\Caisse\Entity\SessionCaisse;
use App\Vente\Entity\Avoir;
use App\Vente\Entity\Vente;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Génère les numéros séquentiels lisibles (session par point de vente, vente par session, avoir).
 * Les numéros restent lisibles/traçables ; l'inaltérabilité stricte est portée par la chaîne NF525.
 */
final class GenerateurNumero
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function numeroSession(PointDeVente $pdv): string
    {
        $nb = (int) $this->em->getRepository(SessionCaisse::class)
            ->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->andWhere('s.pointDeVente = :pdv')
            ->setParameter('pdv', $pdv->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();

        return sprintf('S-%s-%05d', substr(strtoupper($pdv->getId()->toRfc4122()), 0, 8), $nb + 1);
    }

    public function numeroVente(SessionCaisse $session): string
    {
        $nb = (int) $this->em->getRepository(Vente::class)
            ->createQueryBuilder('v')
            ->select('COUNT(v.id)')
            ->andWhere('v.session = :session')
            ->setParameter('session', $session->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();

        return sprintf('%s-T%05d', $session->getNumero(), $nb + 1);
    }

    public function numeroAvoir(): string
    {
        $nb = (int) $this->em->getRepository(Avoir::class)
            ->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->getQuery()
            ->getSingleScalarResult();

        return sprintf('AV-%s-%05d', date('Ymd'), $nb + 1);
    }
}
