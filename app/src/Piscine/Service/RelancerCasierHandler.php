<?php

declare(strict_types=1);

namespace App\Piscine\Service;

use App\Piscine\Entity\Casier;
use App\Piscine\Entity\ParametrePiscineEtablissement;
use App\Piscine\Entity\RelanceCasier;
use App\Piscine\Enum\EtatCasier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/** Relance d'un casier non restitué (US-L6-09, CA-9) : bascule l'état à « en retard ». */
final class RelancerCasierHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function relancer(Casier $casier): RelanceCasier
    {
        if ($casier->getEtat() === EtatCasier::Libre) {
            throw new UnprocessableEntityHttpException('Casier libre : aucune relance nécessaire.');
        }

        $etablissement = $casier->getEtablissement();
        $parametre = $etablissement !== null
            ? $this->em->getRepository(ParametrePiscineEtablissement::class)->findOneBy(['etablissement' => $etablissement])
            : null;

        $relance = new RelanceCasier();
        $relance->setCasier($casier)
            ->setDateRelance(new \DateTimeImmutable())
            ->setDelaiForcageJours($parametre?->getDelaiForcageCasierJours() ?? 3);
        $this->em->persist($relance);

        $casier->setEtat(EtatCasier::NonRendu);

        $this->em->flush();

        return $relance;
    }
}
