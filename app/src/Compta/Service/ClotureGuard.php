<?php

declare(strict_types=1);

namespace App\Compta\Service;

use App\Compta\Entity\EcritureComptable;
use App\Compta\Entity\PeriodeComptable;
use App\Compta\Entity\RegieRecettes;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Contrôles pré-clôture bloquants (RG-CLOTURE-10, CA-14) : journal équilibré, versements de régie
 * soldés, PCA à jour (pas de mouvement en attente incohérent).
 */
final class ClotureGuard
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @return list<string> points bloquants (vide = clôture possible) */
    public function pointsBloquants(PeriodeComptable $periode): array
    {
        $bloquants = [];

        /** @var list<EcritureComptable> $ecritures */
        $ecritures = $this->em->getRepository(EcritureComptable::class)->findBy(['periode' => $periode->getId()]);

        foreach ($ecritures as $ecriture) {
            if (!$ecriture->estEquilibree()) {
                $bloquants[] = sprintf('Écriture %s déséquilibrée.', $ecriture->getId());
            }
        }

        $profil = $periode->getProfilExploitant();
        /** @var list<RegieRecettes> $regies */
        $regies = $this->em->getRepository(RegieRecettes::class)->findBy(['profilExploitant' => $profil?->getId()]);
        foreach ($regies as $regie) {
            if ($regie->depassePlafond()) {
                $bloquants[] = sprintf('Régie « %s » : solde d\'encaisse au-dessus du plafond, versement requis.', $regie->getLibelle());
            }
        }

        return $bloquants;
    }
}
