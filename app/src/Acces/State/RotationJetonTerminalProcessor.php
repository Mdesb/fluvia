<?php

declare(strict_types=1);

namespace App\Acces\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Acces\Entity\JetonTerminal;
use App\Acces\Entity\Terminal;
use App\Acces\Enum\StatutJetonTerminal;
use App\Acces\Service\JetonTerminalFactory;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Rotation du jeton d'un `Terminal` (POST /acces/terminaux/{id}/jetons, US-TERM-09). Émet un nouveau
 * `JetonTerminal` actif, **révoque l'ancien actif** (pas de chevauchement MVP, Risque R-2 du plan),
 * renvoie le secret une fois.
 *
 * @implements ProcessorInterface<Terminal, JsonResponse>
 */
final class RotationJetonTerminalProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly JetonTerminalFactory $factory,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        \assert($data instanceof Terminal);

        $agent = $this->security->getUser();
        $agent = $agent instanceof Utilisateur ? $agent : null;

        $anciens = $this->em->getRepository(JetonTerminal::class)->findBy(['terminal' => $data, 'statut' => StatutJetonTerminal::Actif]);
        foreach ($anciens as $ancien) {
            $ancien->setStatut(StatutJetonTerminal::Revoque)->setRevoqueLe(new \DateTimeImmutable())->setRevoquePar($agent);
        }

        [$jeton, $secret] = $this->factory->emettre($data);
        $this->em->persist($jeton);
        $this->em->flush();

        return new JsonResponse([
            'terminalId' => (string) $data->getId(),
            'secret' => $secret,
        ], JsonResponse::HTTP_CREATED);
    }
}
