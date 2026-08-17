<?php

declare(strict_types=1);

namespace App\Piscine\Service;

use App\Caution\Service\GestionCaution;
use App\Piscine\Entity\Casier;
use App\Piscine\Entity\CautionCasier;
use App\Piscine\Enum\EtatCasier;
use App\Piscine\Enum\StatutCaution;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Restitution d'un casier (US-L6-09, CA-9) : caution libérée, casier repasse libre. Restitution
 * déléguée à `GestionCaution::restituer()` sur la caution générique liée (refactor caution générique).
 */
final class LibererCasierHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GestionCaution $gestionCaution,
    ) {
    }

    public function liberer(Casier $casier): CautionCasier
    {
        $caution = $this->em->getRepository(CautionCasier::class)->createQueryBuilder('c')
            ->andWhere('c.casier = :casier')
            ->andWhere('c.statut != :liberee')
            ->setParameter('casier', $casier->getId(), 'uuid')
            ->setParameter('liberee', StatutCaution::Liberee)
            ->orderBy('c.dateEncaissement', 'DESC')
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
        if (!$caution instanceof CautionCasier) {
            throw new UnprocessableEntityHttpException('Aucune caution active pour ce casier.');
        }

        $cautionGenerique = $this->gestionCaution->cautionActivePour(AttribuerCasierHandler::TYPE_CIBLE, $casier->getId());
        if ($cautionGenerique !== null) {
            $this->gestionCaution->restituer($cautionGenerique);
        }

        $caution->setStatut(StatutCaution::Liberee)->setDateLiberation(new \DateTimeImmutable());

        $casier->setEtat(EtatCasier::Libre)->setBracelet(null);

        $this->em->flush();

        return $caution;
    }
}
