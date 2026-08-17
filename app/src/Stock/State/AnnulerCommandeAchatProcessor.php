<?php

declare(strict_types=1);

namespace App\Stock\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Stock\Entity\CommandeAchat;
use App\Stock\Enum\StatutCommandeAchat;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** `POST /stock/commandes-achat/{id}/annuler` (RG-STOCK-04) : possible avant réception uniquement.
 *
 * @implements ProcessorInterface<mixed, CommandeAchat>
 */
final class AnnulerCommandeAchatProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CommandeAchat
    {
        \assert($data instanceof CommandeAchat);
        if (\in_array($data->getStatut(), [StatutCommandeAchat::Recue, StatutCommandeAchat::PartiellementRecue, StatutCommandeAchat::Cloturee, StatutCommandeAchat::Annulee], true)) {
            throw new ConflictHttpException('Cette commande ne peut plus être annulée (déjà réceptionnée ou clôturée).');
        }

        $data->setStatut(StatutCommandeAchat::Annulee);
        $this->em->flush();

        return $data;
    }
}
