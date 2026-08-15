<?php

declare(strict_types=1);

namespace App\Piscine\Service;

use App\Piscine\Entity\CreneauPublic;
use App\Piscine\Entity\LigneEau;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Garde « pas de chevauchement » (CA-6, US-L6-06, RG-PISC-03) : une ligne d'eau ne peut être affectée
 * qu'à un seul public à la fois. Pas de contrainte SQL simple possible (chevauchement temporel) ⇒
 * garde applicative, même pattern que `TopologieCoherente` en L3.
 */
final class AffectationLigneGuard
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function verifier(CreneauPublic $creneauPublic): void
    {
        $creneauBassin = $creneauPublic->getCreneauBassin();
        if ($creneauBassin === null) {
            return;
        }

        foreach ($creneauPublic->getLignes() as $ligne) {
            \assert($ligne instanceof LigneEau);

            $qb = $this->em->getRepository(CreneauPublic::class)->createQueryBuilder('cp')
                ->innerJoin('cp.lignes', 'l')
                ->innerJoin('cp.creneauBassin', 'cb')
                ->andWhere('l.id = :ligne')
                ->andWhere('cp.id != :moi')
                ->setParameter('ligne', $ligne->getId(), 'uuid')
                ->setParameter('moi', $creneauPublic->getId(), 'uuid');

            /** @var list<CreneauPublic> $existants */
            $existants = $qb->getQuery()->getResult();

            foreach ($existants as $existant) {
                $autreCreneau = $existant->getCreneauBassin();
                if ($autreCreneau !== null && $creneauBassin->chevauche($autreCreneau)) {
                    throw new UnprocessableEntityHttpException(sprintf(
                        'RG-PISC-03 : la ligne %d est déjà affectée à un autre public sur un créneau chevauchant.',
                        $ligne->getNumero(),
                    ));
                }
            }
        }
    }
}
