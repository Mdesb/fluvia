<?php

declare(strict_types=1);

namespace App\Piscine\Service;

use App\Piscine\Entity\Bassin;
use App\Piscine\Entity\CreneauBassin;
use App\Piscine\Entity\JaugeGrandPublicCalculee;
use App\Piscine\Entity\LigneEau;
use App\Piscine\Entity\ParametrePiscineEtablissement;
use App\Piscine\Enum\EtatLigneEau;
use App\Piscine\Enum\ModeProrata;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Recalcule la jauge grand public au prorata des lignes réservées (US-L6-07). Formule par défaut
 * (mode `lignes`, plan §1.4) :
 *
 *   lignesReservees = count(LigneEau où etat = reservee, pour ce bassin)
 *   lignesTotales   = Bassin.nbLignes
 *   capaciteRestante = round( Bassin.capacite × (1 − lignesReservees / lignesTotales) )
 *
 * Les modes `surface`/`forfait` (⚠ non figés, plan risque n°4) retombent sur `lignes` (structure de
 * données prête — `LigneEau.surfaceM2`, `ParametrePiscineEtablissement` — calcul non implémenté).
 */
final class PossProrataCalculator
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function recalculer(CreneauBassin $creneauBassin): JaugeGrandPublicCalculee
    {
        $bassin = $creneauBassin->getBassin();
        \assert($bassin instanceof Bassin);

        $etablissement = $bassin->getEtablissement();
        $parametre = $etablissement !== null
            ? $this->em->getRepository(ParametrePiscineEtablissement::class)->findOneBy(['etablissement' => $etablissement])
            : null;
        $mode = $parametre?->getModeProrataDefaut() ?? ModeProrata::Lignes;

        $lignesTotales = max(1, $bassin->getNbLignes());
        $lignesReservees = $this->em->getRepository(LigneEau::class)->count([
            'bassin' => $bassin,
            'etat' => EtatLigneEau::Reservee,
        ]);

        // Modes surface/forfait : point d'extension non implémenté (⚠ risque n°4) — retombe sur lignes.
        $capaciteRestante = (int) round($bassin->getCapacite() * (1 - $lignesReservees / $lignesTotales));

        $jauge = $this->em->getRepository(JaugeGrandPublicCalculee::class)->findOneBy(['creneauBassin' => $creneauBassin]);
        if (!$jauge instanceof JaugeGrandPublicCalculee) {
            $jauge = new JaugeGrandPublicCalculee();
            $jauge->setCreneauBassin($creneauBassin);
            $this->em->persist($jauge);
        }

        $jauge->setCapaciteRestante($capaciteRestante)
            ->setModeProrata($mode)
            ->setRecalculeLe(new \DateTimeImmutable());

        return $jauge;
    }
}
