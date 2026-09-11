<?php

declare(strict_types=1);

namespace App\Membership\Recouvrement;

use App\Acces\Entity\DroitAcces;
use App\Organisation\Entity\Etablissement;
use App\Recouvrement\Port\RedevablePort;
use App\Membership\Entity\Membership;
use App\Membership\Entity\StatutAccesFitness;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Port `RedevablePort` fourni PAR Sport AU moteur de recouvrement partagé (refactor extraction —
 * `App\Recouvrement`, plan de refactor §0). Tagué `recouvrement.redevable_port` (services.yaml),
 * agrégé par `App\Recouvrement\Service\RedevableRegistry`. Le type de contrat exposé est
 * `sport.abonnement_fitness` : `referenceRedevable` est l'UUID d'un `Membership`.
 */
final class AbonnementFitnessRedevablePort implements RedevablePort
{
    public const TYPE = 'sport.abonnement_fitness';

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function typeRedevable(): string
    {
        return self::TYPE;
    }

    public function droitAcces(string $referenceRedevable): ?DroitAcces
    {
        if (!Uuid::isValid($referenceRedevable)) {
            return null;
        }
        $statutAcces = $this->em->getRepository(StatutAccesFitness::class)->findOneBy(['abonnement' => Uuid::fromString($referenceRedevable)]);

        return $statutAcces?->getDroitAcces();
    }

    public function etablissement(string $referenceRedevable): ?Etablissement
    {
        $abonnement = $this->resoudreAbonnement($referenceRedevable);

        return $abonnement?->getEtablissement();
    }

    public function estLieA(string $referenceRedevable, mixed $utilisateur): bool
    {
        $abonnement = $this->resoudreAbonnement($referenceRedevable);

        return $abonnement instanceof Membership && $abonnement->estLieA($utilisateur);
    }

    private function resoudreAbonnement(string $referenceRedevable): ?Membership
    {
        if (!Uuid::isValid($referenceRedevable)) {
            return null;
        }

        return $this->em->getRepository(Membership::class)->find($referenceRedevable);
    }
}
