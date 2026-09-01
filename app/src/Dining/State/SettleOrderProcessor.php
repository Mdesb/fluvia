<?php

declare(strict_types=1);

namespace App\Dining\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dining\Domain\DiningBill;
use App\Dining\Entity\DiningOrder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Reglement de l addition (ACT-4). Aucun corps.
 *
 * **Cet endpoint ne prend pas d argent, il constate.** L encaissement appartient a `App\Vente` et a
 * `App\Caisse` ; la restauration se contente d enregistrer que la table a regle. Melanger les deux
 * ferait de `App\Dining` un second moyen de paiement, hors de tout ce que la caisse sait cloturer —
 * et les ecritures NF525 ne passeraient plus par la chaine qui les scelle. Meme raisonnement que pour
 * le sejour, et pour la meme raison.
 *
 * Le montant est **recalcule ici** plutot que recu : un total transmis par l appelant serait un total
 * choisi par l appelant.
 *
 * @implements ProcessorInterface<mixed, DiningOrder>
 */
final class SettleOrderProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DiningOrderFromRequest $additions,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): DiningOrder
    {
        $addition = $this->additions->resolve($uriVariables);

        if (null === $addition->getClosedAt()) {
            throw new ConflictHttpException('dining.error.order_not_closed');
        }
        if (null !== $addition->getSettledAt()) {
            throw new ConflictHttpException('dining.error.order_already_settled');
        }

        $note = new DiningBill($addition->getLines()->toArray());
        if ('0.00' !== $note->total()) {
            // Tant que l encaissement n est pas branche, on refuse plutot que de declarer soldee une
            // addition qui ne l est pas : une creance qui disparait d un clic ne se retrouve pas.
            throw new ConflictHttpException(sprintf(
                'dining.error.balance_not_zero : reste %s a encaisser.',
                $note->total(),
            ));
        }

        $addition->settle(new \DateTimeImmutable('now'));
        $this->em->flush();

        return $addition;
    }
}
