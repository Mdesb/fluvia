<?php

declare(strict_types=1);

namespace App\Membership\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Acces\Entity\DroitAcces;
use App\Membership\Entity\Membership;
use App\Membership\Entity\StatutAccesFitness;
use App\Membership\Service\PropagationAccesFitnessHandler;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * POST /sport/abonnements/{id}/rattacher-droit-acces (§0 point 5 du plan, CA-1). Rattache le
 * `DroitAcces` L3 (créé par l'appairage du support physique, flux standard M1/M2/L3) à
 * `StatutAccesFitness`, puis rejoue immédiatement la propagation de l'état courant de l'abonnement.
 * Corps : { "droitAcces": iri|uuid }.
 *
 * @implements ProcessorInterface<Membership, Membership>
 */
final class RattacherDroitAccesProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly PropagationAccesFitnessHandler $propagation,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Membership
    {
        \assert($data instanceof Membership);

        $corps = $this->lecteur->corps();
        $droitId = $this->uuid($corps['droitAcces'] ?? null);
        $droit = $droitId !== null ? $this->em->getRepository(DroitAcces::class)->find($droitId) : null;
        if (!$droit instanceof DroitAcces) {
            throw new UnprocessableEntityHttpException('Droit d\'accès introuvable.');
        }

        $statutAcces = $this->em->getRepository(StatutAccesFitness::class)->findOneBy(['abonnement' => $data]);
        if (!$statutAcces instanceof StatutAccesFitness) {
            throw new UnprocessableEntityHttpException('Statut d\'accès Sport introuvable pour cet abonnement.');
        }

        $statutAcces->setDroitAcces($droit);
        $this->em->flush();

        if ($statutAcces->isActif()) {
            $this->propagation->activer($data);
        } else {
            $motif = $statutAcces->getMotifInactivite();
            if ($motif !== null) {
                $this->propagation->desactiver($data, $motif);
            }
        }

        return $data;
    }

    private function uuid(mixed $reference): ?Uuid
    {
        if (!\is_string($reference) || $reference === '') {
            return null;
        }
        $segment = str_contains($reference, '/') ? basename($reference) : $reference;

        return Uuid::isValid($segment) ? Uuid::fromString($segment) : null;
    }
}
