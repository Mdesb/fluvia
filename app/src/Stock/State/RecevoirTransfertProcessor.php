<?php

declare(strict_types=1);

namespace App\Stock\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Stock\Entity\TransfertStock;
use App\Stock\Security\PerimetreEtablissementVerificateur;
use App\Stock\Service\TransfertStockHandler;
use Doctrine\ORM\EntityManagerInterface;

/**
 * `POST /stock/transferts/{id}/recevoir` (RG-STOCK-14, CA-12, côté établissement destination). La
 * lecture de l'item passe par `PerimetreStockExtension` (OR source/destination) : ce processor
 * resserre explicitement le contrôle au seul établissement **destination**, faute de quoi un
 * utilisateur affecté uniquement sur la source pourrait déclencher une réception côté destination.
 *
 * @implements ProcessorInterface<mixed, TransfertStock>
 */
final class RecevoirTransfertProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TransfertStockHandler $handler,
        private readonly PerimetreEtablissementVerificateur $perimetre,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): TransfertStock
    {
        \assert($data instanceof TransfertStock);

        $this->perimetre->verifier(
            $data->getArticleStockDestination()?->getEtablissement(),
            'Réception refusée : établissement destination hors du périmètre de l\'appelant (RG-STOCK-14).',
        );

        $this->handler->recevoir($data);
        $this->em->flush();

        return $data;
    }
}
