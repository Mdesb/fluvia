<?php

declare(strict_types=1);

namespace App\Compta\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Service\GenerateurEcrituresHandler;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * POST /compta/ecritures/generer (RG-COMPTA-04, §2 du plan) : déclenche la génération d'écritures
 * pour le profil exploitant demandé. Idempotent (même handler que la commande CLI planifiée).
 * Corps : { "profilExploitant": iri|uuid }
 *
 * @implements ProcessorInterface<mixed, JsonResponse>
 */
final class GenererEcrituresProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly GenerateurEcrituresHandler $handler,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $corps = $this->lecteur->corps();
        $reference = $corps['profilExploitant'] ?? null;
        $id = \is_string($reference) ? (str_contains($reference, '/') ? basename($reference) : $reference) : null;
        if ($id === null || !Uuid::isValid($id)) {
            throw new UnprocessableEntityHttpException('Référence de profil exploitant obligatoire.');
        }

        $profil = $this->em->getRepository(ProfilExploitant::class)->find(Uuid::fromString($id));
        if ($profil === null) {
            throw new UnprocessableEntityHttpException('Profil exploitant introuvable.');
        }

        return new JsonResponse($this->handler->generer($profil));
    }
}
