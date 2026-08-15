<?php

declare(strict_types=1);

namespace App\Compta\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Compta\Entity\DeclarationEReporting;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Service\GenerateurEReportingHandler;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * POST /compta/e-reporting (US-L4-08, CA-12). Corps :
 *   { "profilExploitant": iri|uuid, "periodeDebut": "YYYY-MM-DD", "periodeFin": "YYYY-MM-DD" }
 *
 * @implements ProcessorInterface<mixed, DeclarationEReporting>
 */
final class PreparerEReportingProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly GenerateurEReportingHandler $handler,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): DeclarationEReporting
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

        $debut = new \DateTimeImmutable((string) ($corps['periodeDebut'] ?? 'first day of this month'));
        $fin = new \DateTimeImmutable((string) ($corps['periodeFin'] ?? 'last day of this month'));

        return $this->handler->preparer($profil, $debut, $fin);
    }
}
