<?php

declare(strict_types=1);

namespace App\Support\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Securite\Entity\Utilisateur;
use App\Support\Entity\TicketSupport;
use App\Support\Enum\NiveauAffectation;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;

/**
 * POST /support/tickets/{id}/escalader (CA-10, RG-SUP-13) : `niveauAffectation` → `N2`, agent
 * affecté modifié si un `affecteA` (IRI) est fourni dans le corps. Traçable via `AuditWriteSubscriber`
 * (`TicketSupport` surveillé, RG-SUP-15).
 *
 * @implements ProcessorInterface<TicketSupport, TicketSupport>
 */
final class EscaladerTicketProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): TicketSupport
    {
        \assert($data instanceof TicketSupport);

        $corps = $this->lecteur->corps();
        $affecteAIri = $corps['affecteA'] ?? null;
        if (\is_string($affecteAIri) && $affecteAIri !== '') {
            $agent = $this->em->getRepository(Utilisateur::class)->find(basename($affecteAIri));
            if ($agent instanceof Utilisateur) {
                $data->setAffecteA($agent);
            }
        }

        $data->setNiveauAffectation(NiveauAffectation::N2);
        $data->toucherDateMaj();

        $this->em->flush();

        return $data;
    }
}
