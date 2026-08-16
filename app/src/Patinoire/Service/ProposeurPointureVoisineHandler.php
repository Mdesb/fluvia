<?php

declare(strict_types=1);

namespace App\Patinoire\Service;

use App\Organisation\Entity\Etablissement;
use App\Patinoire\Entity\ParcPatins;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Propose une pointure voisine disponible en cas de rupture (décision actée « pointure en rupture »,
 * US-PATIN-05, §4.5, Risque n°9 du plan). Hypothèse de travail retenue (non chiffrée par les sources) :
 * ordre **±1 puis ±2**, inférieure d'abord puis supérieure, paramétrable par établissement dans une
 * itération future.
 */
final class ProposeurPointureVoisineHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** Retourne la pointure voisine disponible, ou null si aucune trouvée dans ±2. */
    public function proposer(ParcPatins $demandee): ?int
    {
        $etablissement = $demandee->getEtablissement();
        if (!$etablissement instanceof Etablissement) {
            return null;
        }

        foreach ([-1, 1, -2, 2] as $ecart) {
            $candidate = $this->em->getRepository(ParcPatins::class)->findOneBy([
                'etablissement' => $etablissement,
                'pointure' => $demandee->getPointure() + $ecart,
                'actif' => true,
            ]);
            if ($candidate instanceof ParcPatins && $candidate->getQuantiteDisponible() > 0) {
                return $candidate->getPointure();
            }
        }

        return null;
    }
}
