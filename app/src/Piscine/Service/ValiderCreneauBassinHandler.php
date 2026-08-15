<?php

declare(strict_types=1);

namespace App\Piscine\Service;

use App\Piscine\Entity\AffectationEncadrant;
use App\Piscine\Entity\CreneauBassin;
use App\Piscine\Enum\StatutCreneauBassin;
use App\Piscine\Enum\TypeEncadrement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Garde « encadrant qualifié requis » (RG-PISC-02, US-L6-04, CA-4). Un créneau exigeant un
 * encadrement ne peut être validé sans au moins une `AffectationEncadrant` dont la qualification
 * couvre le type requis et reste valide à la date du créneau (qualification expirée = non prise en
 * compte, calcul seulement — pas de suppression, cahier M5-04).
 */
final class ValiderCreneauBassinHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function valider(CreneauBassin $creneau): CreneauBassin
    {
        if ($creneau->getEncadrantRequis() !== TypeEncadrement::Aucune) {
            if (!$this->aEncadrantQualifieCouvrant($creneau)) {
                throw new UnprocessableEntityHttpException(
                    'RG-PISC-02 : aucun encadrant qualifié à diplôme valide affecté à ce créneau.',
                );
            }
        }

        $creneau->setStatut(StatutCreneauBassin::Valide);
        $this->em->flush();

        return $creneau;
    }

    private function aEncadrantQualifieCouvrant(CreneauBassin $creneau): bool
    {
        $debut = $creneau->getDebut();
        if ($debut === null) {
            return false;
        }

        /** @var list<AffectationEncadrant> $affectations */
        $affectations = $this->em->getRepository(AffectationEncadrant::class)->findBy(['creneauBassin' => $creneau]);

        foreach ($affectations as $affectation) {
            $qualification = $affectation->getQualification();
            if ($qualification === null) {
                continue;
            }
            if ($qualification->getType() === $creneau->getEncadrantRequis() && $qualification->estValideA($debut)) {
                return true;
            }
        }

        return false;
    }
}
