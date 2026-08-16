<?php

declare(strict_types=1);

namespace App\Padel\Service;

use App\Padel\Entity\InscriptionTournoi;
use App\Padel\Entity\MatchTournoi;
use App\Padel\Enum\StatutMatchTournoi;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Saisit le score d'un match, détermine le vainqueur (US-PADEL-06, CA-7). Le classement (poules) ou
 * l'avancement (tableau) est **recalculé à la lecture** par `ClassementTournoiProvider` — aucune
 * dénormalisation persistée ici, évite toute désynchronisation.
 */
final class SaisirScoreHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function saisir(MatchTournoi $match, string $score, InscriptionTournoi $vainqueur): MatchTournoi
    {
        $paireA = $match->getPaireA();
        $paireB = $match->getPaireB();
        if ((string) $vainqueur->getId() !== (string) $paireA?->getId() && (string) $vainqueur->getId() !== (string) $paireB?->getId()) {
            throw new UnprocessableEntityHttpException('Le vainqueur doit être l\'une des deux paires du match.');
        }

        $match->setScore($score)
            ->setVainqueur($vainqueur)
            ->setStatut(StatutMatchTournoi::Joue);
        $this->em->flush();

        return $match;
    }
}
