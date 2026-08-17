<?php

declare(strict_types=1);

namespace App\Piscine\Service;

use App\Caution\Service\GestionCaution;
use App\Piscine\Entity\Casier;
use App\Piscine\Entity\ParametrePiscineEtablissement;
use App\Piscine\Entity\RelanceCasier;
use App\Piscine\Enum\EtatCasier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Relance d'un casier non restitué (US-L6-09, CA-9) : bascule l'état à « en retard ». Le délai de
 * forçage reste géré localement (`RelanceCasier`, spécifique à la verticale Piscine) mais est aussi
 * journalisé sur la caution générique liée (`GestionCaution::relancer()`), pour un ledger cohérent
 * cross-verticale (refactor caution générique).
 */
final class RelancerCasierHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GestionCaution $gestionCaution,
    ) {
    }

    public function relancer(Casier $casier): RelanceCasier
    {
        if ($casier->getEtat() === EtatCasier::Libre) {
            throw new UnprocessableEntityHttpException('Casier libre : aucune relance nécessaire.');
        }

        $etablissement = $casier->getEtablissement();
        $parametre = $etablissement !== null
            ? $this->em->getRepository(ParametrePiscineEtablissement::class)->findOneBy(['etablissement' => $etablissement])
            : null;
        $delaiForcageJours = $parametre?->getDelaiForcageCasierJours() ?? 3;

        $relance = new RelanceCasier();
        $relance->setCasier($casier)
            ->setDateRelance(new \DateTimeImmutable())
            ->setDelaiForcageJours($delaiForcageJours);
        $this->em->persist($relance);

        $cautionGenerique = $this->gestionCaution->cautionActivePour(AttribuerCasierHandler::TYPE_CIBLE, $casier->getId());
        if ($cautionGenerique !== null) {
            $this->gestionCaution->relancer($cautionGenerique, $delaiForcageJours);
        }

        $casier->setEtat(EtatCasier::NonRendu);

        $this->em->flush();

        return $relance;
    }
}
