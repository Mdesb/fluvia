<?php

declare(strict_types=1);

namespace App\Acces\Service;

use App\Acces\Entity\EspaceAcces;
use App\Acces\Entity\JaugeFmi;
use App\Acces\Enum\ModeRecalage;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Recalage FMI à l'ouverture de site (§4.5 du plan, arbitrage point ouvert n°5) : remise à zéro
 * retenue par défaut (`valeurCourante = 0`, `cumulJour = 0`, `dateReference = jour`). Le mode
 * `report_residuel` est un point d'extension non implémenté ici (⚠ impact sécurité ERP à confirmer).
 */
final class RecalageFmiHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function ouvrir(EspaceAcces $espace): JaugeFmi
    {
        $jauge = $this->em->getRepository(JaugeFmi::class)->findOneBy(['espace' => $espace]);
        if (!$jauge instanceof JaugeFmi) {
            $jauge = new JaugeFmi();
            $jauge->setEspace($espace)->setSeuil($espace->getSeuilFmi())->setMode($espace->getModeSeuil());
            $this->em->persist($jauge);
        }

        if ($espace->getRecalageOuverture() === ModeRecalage::RemiseAZero) {
            $jauge->setValeurCourante(0)->setCumulJour(0);
        }
        $jauge->setDateReference(new \DateTimeImmutable('today'));

        $this->em->flush();

        return $jauge;
    }

    /** Recalage post-rejeu (§4.6) : la jauge est déjà à jour (chaque replay incrémente/décrémente). */
    public function recalerApresSynchro(EspaceAcces $espace): JaugeFmi
    {
        $jauge = $this->em->getRepository(JaugeFmi::class)->findOneBy(['espace' => $espace]);
        if (!$jauge instanceof JaugeFmi) {
            $jauge = new JaugeFmi();
            $jauge->setEspace($espace)->setSeuil($espace->getSeuilFmi())->setMode($espace->getModeSeuil());
            $this->em->persist($jauge);
            $this->em->flush();
        }

        return $jauge;
    }
}
