<?php

declare(strict_types=1);

namespace App\Sport\Service;

use App\Acces\Entity\EspaceAcces;
use App\Acces\Entity\JaugeFmi;
use App\Sport\Entity\AlertePresenceIsolee;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Détection de présence isolée (US-SPORT-09, §4.8 spec) : dérivée en lecture seule de `JaugeFmi` (L3),
 * même patron que `PossEtatLiveProvider` (L6). Signale toute occurrence d'un seul adhérent présent.
 */
final class DetecteurPresenceIsoleeHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function detecter(EspaceAcces $espace): ?AlertePresenceIsolee
    {
        $jauge = $this->em->getRepository(JaugeFmi::class)->findOneBy(['espace' => $espace]);
        if (!$jauge instanceof JaugeFmi || $jauge->getValeurCourante() !== 1) {
            return null;
        }

        $alerte = new AlertePresenceIsolee();
        $alerte->setEspaceAcces($espace)->setHorodatage(new \DateTimeImmutable())->setNbPersonnesDetectees(1);
        $this->em->persist($alerte);
        $this->em->flush();

        return $alerte;
    }
}
