<?php

declare(strict_types=1);

namespace App\Acces\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Acces\Entity\JetonTerminal;
use App\Acces\Entity\Terminal;
use App\Acces\Enum\StatutJetonTerminal;
use App\Acces\Enum\StatutTerminal;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Révocation d'un `Terminal` (POST /acces/terminaux/{id}/revoquer, US-TERM-09, CA-11). `Terminal.statut
 * = revoque` + révoque tout `JetonTerminal` actif ; **idempotent** (200 même si déjà révoqué, §2.1 du
 * plan). Effet immédiat côté API (futurs appels refusés, 401) — un snapshot déjà téléchargé reste local
 * jusqu'à expiration TTL (cas limite documenté §4.1 spec, hors périmètre serveur).
 *
 * @implements ProcessorInterface<Terminal, Terminal>
 */
final class RevoquerTerminalProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Terminal
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

        $data->setStatut(StatutTerminal::Revoque);

        $actifs = $this->em->getRepository(JetonTerminal::class)->findBy(['terminal' => $data, 'statut' => StatutJetonTerminal::Actif]);
        foreach ($actifs as $jeton) {
            $jeton->setStatut(StatutJetonTerminal::Revoque)->setRevoqueLe(new \DateTimeImmutable())->setRevoquePar($agent);
        }

        $this->em->flush();

        return $data;
    }
}
