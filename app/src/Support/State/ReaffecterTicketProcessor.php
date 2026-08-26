<?php

declare(strict_types=1);

namespace App\Support\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Utilisateur;
use App\Support\Entity\TicketSupport;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
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

        // Cloisonnement (D3/D8) — le ticket ($data) est confronté au périmètre par `read: true` +
        // PerimetreSupportExtension, mais l'agent est résolu depuis le corps par un `find()` direct,
        // hors des extensions. On exige qu'il soit affecté à l'établissement du ticket : sans quoi on
        // réaffecte le ticket à un agent d'un autre établissement. Échec fermé en 404 (anti-oracle).
        $this->assertAgentDansLEtablissementDuTicket($agent, $data);

        $data->setAffecteA($agent);
        $data->toucherDateMaj();

        $this->em->flush();

        return $data;
    }

    private function assertAgentDansLEtablissementDuTicket(Utilisateur $agent, TicketSupport $ticket): void
    {
        $etablissement = $ticket->getEtablissement();
        $affecte = $etablissement !== null && $this->em->getRepository(Affectation::class)
            ->findOneBy(['utilisateur' => $agent, 'etablissement' => $etablissement]) instanceof Affectation;

        if (!$affecte) {
            throw new NotFoundHttpException('Agent introuvable.');
        }
    }
}
