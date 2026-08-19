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
 * `POST /stock/transferts/{id}/expedier` (RG-STOCK-14, CA-12, côté établissement source). La lecture
 * de l'item passe par `PerimetreStockExtension` (OR source/destination) : ce processor resserre
 * explicitement le contrôle au seul établissement **source**, faute de quoi un utilisateur affecté
 * uniquement sur la destination pourrait déclencher une expédition (décrément) côté source.
 *
 * @implements ProcessorInterface<mixed, TransfertStock>
 */
final class ExpedierTransfertProcessor implements ProcessorInterface
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
            $data->getArticleStockSource()?->getEtablissement(),
            'Expédition refusée : établissement source hors du périmètre de l\'appelant (RG-STOCK-14).',
        );

        $this->handler->expedier($data);
        $this->em->flush();

        return $data;
    }
}
