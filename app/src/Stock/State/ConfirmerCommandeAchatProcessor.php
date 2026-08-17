<?php

declare(strict_types=1);

namespace App\Stock\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Stock\Entity\CommandeAchat;
use App\Stock\Enum\StatutCommandeAchat;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** `POST /stock/commandes-achat/{id}/confirmer` : envoyée → confirmée.
 *
 * @implements ProcessorInterface<mixed, CommandeAchat>
 */
final class ConfirmerCommandeAchatProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CommandeAchat
    {
        \assert($data instanceof CommandeAchat);
        if ($data->getStatut() !== StatutCommandeAchat::Envoyee) {
            throw new ConflictHttpException('Seule une commande envoyée peut être confirmée.');
        }

        $data->setStatut(StatutCommandeAchat::Confirmee);
        $this->em->flush();

        return $data;
    }
}
