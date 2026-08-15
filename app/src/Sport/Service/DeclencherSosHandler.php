<?php

declare(strict_types=1);

namespace App\Sport\Service;

use App\Acces\Entity\EspaceAcces;
use App\Acces\Entity\Support;
use App\Securite\Entity\Utilisateur;
use App\Sport\Entity\EvenementSOS;
use App\Sport\Enum\StatutEvenementSOS;
use Doctrine\ORM\EntityManagerInterface;

/** Bouton SOS (US-SPORT-09, §4.8 spec) : déclenchement horodaté, traçable, clôture tracée. */
final class DeclencherSosHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function declencher(EspaceAcces $espace, ?Support $support): EvenementSOS
    {
        $evenement = new EvenementSOS();
        $evenement->setEspaceAcces($espace)
            ->setHorodatage(new \DateTimeImmutable())
            ->setDeclenchePar($support)
            ->setStatut(StatutEvenementSOS::Ouverte);
        $this->em->persist($evenement);
        $this->em->flush();

        return $evenement;
    }

    public function traiter(EvenementSOS $evenement, Utilisateur $traitant): EvenementSOS
    {
        $evenement->setStatut(StatutEvenementSOS::Traitee)
            ->setTraitePar($traitant)
            ->setDateTraitement(new \DateTimeImmutable());
        $this->em->flush();

        return $evenement;
    }
}
