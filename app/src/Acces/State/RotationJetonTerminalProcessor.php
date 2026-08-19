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
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
        // Durcissement revue sécurité (cloisonnement) : un `Terminal` d'un autre établissement est
        // filtré par `PerimetreAccesExtension` en amont (RG-SOCLE-05) — `$data` vaut alors `null`
        // plutôt qu'une instance `Terminal`. Un `assert()` brut produisait un 500 (AssertionError) au
        // lieu d'un 404 propre, révélant potentiellement l'existence de l'objet hors périmètre.
        if (!$data instanceof Terminal) {
            throw new NotFoundHttpException('Terminal introuvable.');
        }

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
