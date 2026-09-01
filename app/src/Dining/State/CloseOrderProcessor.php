<?php

declare(strict_types=1);

namespace App\Dining\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dining\Entity\DiningOrder;
use App\Dining\Entity\DiningOrderLine;
use App\Dining\Enum\LineStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * L addition est demandee et figee (ACT-4). Aucun corps.
 *
 * **Refuse tant qu une ligne attend au brouillon.** C est la faute de fin de service : on saisit un
 * cafe, on clique « addition », et le cafe n est jamais parti en cuisine ni facture. Le refus dit
 * exactement ce qui manque plutot que de figer une addition incomplete.
 *
 * **Cloturer ne regle rien** : le compte est fige, il peut rester debiteur — note de frais
 * d entreprise, litige, client qui revient payer. C est `SettleOrderProcessor` qui solde.
 *
 * @implements ProcessorInterface<mixed, DiningOrder>
 */
final class CloseOrderProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DiningOrderFromRequest $additions,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): DiningOrder
    {
        $addition = $this->additions->resolve($uriVariables);

        if (null !== $addition->getSettledAt()) {
            throw new ConflictHttpException('dining.error.order_already_settled');
        }

        foreach ($addition->getLines() as $ligne) {
            \assert($ligne instanceof DiningOrderLine);
            if (LineStatus::Draft === $ligne->getStatus()) {
                throw new ConflictHttpException(sprintf(
                    'dining.error.draft_line_pending : « %s » n a pas ete envoyee en cuisine.',
                    $ligne->getLabel(),
                ));
            }
        }

        // `close()` est idempotent par conception : en plein coup de feu, on clique deux fois.
        $addition->close(new \DateTimeImmutable('now'));
        $this->em->flush();

        return $addition;
    }
}
