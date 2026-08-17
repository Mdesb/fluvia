<?php

declare(strict_types=1);

namespace App\Personnel\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Personnel\Entity\AffectationTravail;
use App\Personnel\Enum\StatutAffectationTravail;
use App\Personnel\Service\RecalculFenetreBadgeHandler;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Annule une AffectationTravail (POST /personnel/affectations/{id}/annuler).
 *
 * @implements ProcessorInterface<AffectationTravail, AffectationTravail>
 */
final class AnnulerAffectationTravailProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RecalculFenetreBadgeHandler $recalculFenetre,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): AffectationTravail
    {
        \assert($data instanceof AffectationTravail);

        $data->setStatut(StatutAffectationTravail::Annulee);
        $this->em->flush();

        $employe = $data->getEmploye();
        if ($employe !== null) {
            $badges = $this->em->getRepository(\App\Personnel\Entity\BadgeStaff::class)->findBy(['employe' => $employe]);
            foreach ($badges as $badge) {
                $this->recalculFenetre->recalculer($badge);
            }
        }

        return $data;
    }
}
