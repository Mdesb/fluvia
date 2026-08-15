<?php

declare(strict_types=1);

namespace App\Piscine\Service;

use App\Piscine\Entity\BraceletEtanche;
use App\Piscine\Entity\Casier;
use App\Piscine\Entity\CautionCasier;
use App\Piscine\Entity\ParametrePiscineEtablissement;
use App\Piscine\Enum\EtatCasier;
use App\Piscine\Enum\StatutCaution;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Attribution d'un casier au bracelet (US-L6-09, CA-9) : casier → occupé, caution encaissée
 * (montant par défaut de `ParametrePiscineEtablissement`, ou fourni explicitement).
 */
final class AttribuerCasierHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function attribuer(Casier $casier, BraceletEtanche $bracelet, ?string $moyenEncaissement, ?string $montant): CautionCasier
    {
        if ($casier->getEtat() !== EtatCasier::Libre) {
            throw new UnprocessableEntityHttpException('Casier non disponible (déjà occupé ou en retard).');
        }

        $etablissement = $casier->getEtablissement();
        $parametre = $etablissement !== null
            ? $this->em->getRepository(ParametrePiscineEtablissement::class)->findOneBy(['etablissement' => $etablissement])
            : null;
        $montantDefaut = $parametre?->getMontantCautionCasierDefaut() ?? '10.00';

        $casier->setEtat(EtatCasier::Occupe)->setBracelet($bracelet);

        $caution = new CautionCasier();
        $caution->setCasier($casier)
            ->setMontant($montant ?? $montantDefaut)
            ->setStatut(StatutCaution::Encaissee)
            ->setMoyenEncaissement($moyenEncaissement)
            ->setDateEncaissement(new \DateTimeImmutable());
        $this->em->persist($caution);

        $this->em->flush();

        return $caution;
    }
}
