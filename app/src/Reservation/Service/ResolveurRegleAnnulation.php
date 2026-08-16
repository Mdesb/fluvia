<?php

declare(strict_types=1);

namespace App\Reservation\Service;

use App\Reservation\Entity\Creneau;
use App\Reservation\Entity\RegleAnnulation;
use App\Reservation\Enum\PorteeRegleAnnulation;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Résout la `RegleAnnulation` applicable à un Créneau (RG-M5-09) : priorité
 * activité > ressource > type_ressource > établissement (décision structurante n°2 du plan,
 * ⚠ à confirmer produit). Renvoie `null` si aucune règle active n'est trouvée (§7 cas limite :
 * aucune facturation par défaut).
 */
final class ResolveurRegleAnnulation
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function resoudre(Creneau $creneau): ?RegleAnnulation
    {
        $etablissement = $creneau->getEtablissement();
        if ($etablissement === null) {
            return null;
        }
        $activite = $creneau->getActivite();
        $ressource = $creneau->getRessource();

        $repo = $this->em->getRepository(RegleAnnulation::class);

        if ($activite !== null) {
            $regle = $repo->findOneBy(['etablissement' => $etablissement, 'portee' => PorteeRegleAnnulation::Activite, 'cibleActivite' => $activite, 'actif' => true]);
            if ($regle !== null) {
                return $regle;
            }
        }

        if ($ressource !== null) {
            $regle = $repo->findOneBy(['etablissement' => $etablissement, 'portee' => PorteeRegleAnnulation::Ressource, 'cibleRessource' => $ressource, 'actif' => true]);
            if ($regle !== null) {
                return $regle;
            }
        }

        if ($ressource !== null) {
            $regle = $repo->findOneBy(['etablissement' => $etablissement, 'portee' => PorteeRegleAnnulation::TypeRessource, 'cibleTypeRessource' => $ressource->getCodeType(), 'actif' => true]);
            if ($regle !== null) {
                return $regle;
            }
        }

        return $repo->findOneBy(['etablissement' => $etablissement, 'portee' => PorteeRegleAnnulation::Etablissement, 'actif' => true]);
    }
}
