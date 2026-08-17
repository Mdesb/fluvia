<?php

declare(strict_types=1);

namespace App\Support\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Securite\Entity\Utilisateur;
use App\Support\Entity\TicketSupport;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * POST /support/tickets/{id}/reaffecter (RG-SUP-13) : réaffecte le ticket à un autre agent
 * (`support.traiter_ticket_n2` uniquement). Corps : { "affecteA": IRI }.
 *
 * @implements ProcessorInterface<TicketSupport, TicketSupport>
 */
final class ReaffecterTicketProcessor implements ProcessorInterface
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
        if (!\is_string($affecteAIri) || $affecteAIri === '') {
            throw new UnprocessableEntityHttpException('Champ "affecteA" (IRI utilisateur) requis.');
        }

        $agent = $this->em->getRepository(Utilisateur::class)->find(basename($affecteAIri));
        if (!$agent instanceof Utilisateur) {
            throw new UnprocessableEntityHttpException('Agent introuvable.');
        }

        $data->setAffecteA($agent);
        $data->toucherDateMaj();

        $this->em->flush();

        return $data;
    }
}
