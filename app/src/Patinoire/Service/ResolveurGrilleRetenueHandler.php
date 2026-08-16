<?php

declare(strict_types=1);

namespace App\Patinoire\Service;

use App\Organisation\Entity\Etablissement;
use App\Patinoire\Entity\GrilleRetenue;
use App\Patinoire\Entity\ParcPatins;
use App\Patinoire\Enum\MotifRetenue;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Résout la `GrilleRetenue` applicable à un motif donné (US-PATIN-04, §4.4) : priorité à une règle
 * spécifique à la pointure (`parcPatins` renseigné) sur la règle générale de l'établissement
 * (`parcPatins` null).
 */
final class ResolveurGrilleRetenueHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function resoudre(Etablissement $etablissement, ParcPatins $parcPatins, MotifRetenue $motif): ?GrilleRetenue
    {
        $specifique = $this->em->getRepository(GrilleRetenue::class)->findOneBy([
            'etablissement' => $etablissement,
            'parcPatins' => $parcPatins,
            'motif' => $motif,
            'actif' => true,
        ]);
        if ($specifique instanceof GrilleRetenue) {
            return $specifique;
        }

        return $this->em->getRepository(GrilleRetenue::class)->findOneBy([
            'etablissement' => $etablissement,
            'parcPatins' => null,
            'motif' => $motif,
            'actif' => true,
        ]);
    }

    /**
     * Montant proposé par défaut (⚠ HYPOTHÈSE — `montantOuTaux` interprété directement comme un
     * montant quel que soit le `mode` : le cahier §7 ne tranche pas « forfait vs valeur de
     * remplacement », les deux modes sont donc portés à titre informatif par la grille, l'établissement
     * ayant déjà résolu le montant final au paramétrage).
     */
    public function montantPropose(?GrilleRetenue $grille, string $montantCautionParDefaut): string
    {
        return $grille?->getMontantOuTaux() ?? $montantCautionParDefaut;
    }
}
