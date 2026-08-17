<?php

declare(strict_types=1);

namespace App\Support\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Support\Entity\TicketSupport;
use App\Support\Enum\StatutTicket;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * POST /support/tickets/{id}/statut (CA-9, RG-SUP-11) : `en_attente_client`/`resolu`/`ferme`. Corps :
 * { "statut": "en_attente_client"|"resolu"|"ferme", "motifFermeture"?: string }.
 *
 * @implements ProcessorInterface<TicketSupport, TicketSupport>
 */
final class StatutTicketProcessor implements ProcessorInterface
{
    /** @var list<string> */
    private const STATUTS_AUTORISES = ['en_attente_client', 'resolu', 'ferme'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): TicketSupport
    {
        \assert($data instanceof TicketSupport);

        $corps = $this->lecteur->corps();
        $statutDemande = $corps['statut'] ?? null;
        if (!\is_string($statutDemande) || !\in_array($statutDemande, self::STATUTS_AUTORISES, true)) {
            throw new UnprocessableEntityHttpException('Statut demandé invalide : en_attente_client|resolu|ferme attendu.');
        }

        $nouveauStatut = StatutTicket::from($statutDemande);
        $data->setStatut($nouveauStatut);

        if ($nouveauStatut === StatutTicket::Resolu) {
            $data->setDateResolution(new \DateTimeImmutable());
        }
        if ($nouveauStatut === StatutTicket::Ferme) {
            $data->setDateFermeture(new \DateTimeImmutable());
            $motif = $corps['motifFermeture'] ?? null;
            if (\is_string($motif) && $motif !== '') {
                $data->setMotifFermeture($motif);
            }
        }

        $this->em->flush();

        return $data;
    }
}
