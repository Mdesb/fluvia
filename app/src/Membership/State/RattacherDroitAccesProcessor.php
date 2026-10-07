<?php

declare(strict_types=1);

namespace App\Membership\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Acces\Entity\Appairage;
use App\Acces\Entity\DroitAcces;
use App\Acces\Enum\StatutProjectionDroit;
use App\Acces\Service\AppairageHandler;
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
        private readonly AppairageHandler $appairageHandler,
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

        // ── ON RÉVOQUE L'ANCIEN DROIT AVANT DE LE REMPLACER ──────────────────────────────────────
        // Depuis l'émission du billet QR à la souscription, `droitAcces` n'est plus null au départ :
        // il porte le support QR, avec un appairage ACTIF. L'écraser sans le révoquer laissait ce QR
        // ouvrir la porte indéfiniment — même après résiliation, car la propagation ne dévalide que le
        // droit rattaché AU STATUT, désormais le nouveau. On coupe donc l'ancien : dévalidation + tous
        // ses appairages actifs révoqués. (Un adhérent n'a qu'un support à la fois ; en vouloir deux —
        // QR ET badge — serait une fonctionnalité à part, pas un effet de bord de l'appairage d'un badge.)
        $ancien = $statutAcces->getDroitAcces();
        if ($ancien instanceof DroitAcces && (string) $ancien->getId() !== (string) $droit->getId()) {
            $ancien->setStatutProjection(StatutProjectionDroit::Devalide);
            foreach ($this->em->getRepository(Appairage::class)->findBy(['droit' => $ancien, 'actif' => true]) as $app) {
                $this->appairageHandler->revoquer($app);
            }
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
