<?php

declare(strict_types=1);

namespace App\Padel\Service;

use App\Organisation\Entity\Etablissement;
use App\Padel\Entity\PlageHoraire;
use Doctrine\ORM\EntityManagerInterface;

/** Résout la `PlageHoraire` couvrant un instant donné (RG-PADEL-02, §4.2). */
final class PlageHoraireResolver
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function resoudre(Etablissement $etablissement, \DateTimeImmutable $instant): ?PlageHoraire
    {
        /** @var list<PlageHoraire> $plages */
        $plages = $this->em->getRepository(PlageHoraire::class)->findBy(['etablissement' => $etablissement]);
        foreach ($plages as $plage) {
            if ($plage->couvre($instant)) {
                return $plage;
            }
        }

        return null;
    }
}
