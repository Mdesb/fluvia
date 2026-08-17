<?php

declare(strict_types=1);

namespace App\Support\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Support\Entity\TicketSupport;
use App\Support\Enum\StatutTicket;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * POST /support/tickets/{id}/rouvrir (RG-SUP-11, ⚠ délai §7 plan) : un ticket `ferme` peut être
 * rouvert par le demandeur ou un agent support, dans un délai `support.delai_reouverture_jours`
 * (défaut 15, paramètre applicatif — constitution §4 point 4). Au-delà, refuse (409) : seule
 * l'ouverture d'un nouveau ticket est proposée côté UI (non modélisé en base v1).
 *
 * @implements ProcessorInterface<TicketSupport, TicketSupport>
 */
final class RouvrirTicketProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly int $delaiReouvertureJours,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): TicketSupport
    {
        \assert($data instanceof TicketSupport);

        if ($data->getStatut() !== StatutTicket::Ferme) {
            throw new UnprocessableEntityHttpException('Seul un ticket fermé peut être rouvert.');
        }

        $dateFermeture = $data->getDateFermeture();
        if ($dateFermeture !== null) {
            $limite = $dateFermeture->modify(sprintf('+%d days', $this->delaiReouvertureJours));
            if (new \DateTimeImmutable() > $limite) {
                throw new ConflictHttpException(sprintf('Délai de réouverture (%d jours) dépassé : ouvrez un nouveau ticket.', $this->delaiReouvertureJours));
            }
        }

        $data->setStatut($data->getAffecteA() !== null ? StatutTicket::EnCours : StatutTicket::Nouveau);
        $data->setDateFermeture(null);

        $this->em->flush();

        return $data;
    }
}
