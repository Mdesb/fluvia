<?php

declare(strict_types=1);

namespace App\Stay\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Stay\Entity\Stay;
use App\Stay\Service\StayFolio;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Règlement du solde (ACT-3, D16 « réglé une fois au départ »). Aucun corps.
 *
 * **Cet endpoint ne prend pas d'argent, il constate.** L'encaissement appartient à `App\Vente` et à
 * `App\Caisse` ; le séjour se contente d'enregistrer que son compte est soldé. Mélanger les deux
 * ferait de `App\Stay` un second moyen de paiement, hors de tout ce que la caisse sait clôturer — et
 * les écritures NF525 ne passeraient pas par la chaîne qui les scelle.
 *
 * Le solde est **recalculé ici** plutôt que reçu : un montant transmis par l'appelant serait un montant
 * choisi par l'appelant.
 *
 * @implements ProcessorInterface<mixed, Stay>
 */
final class SettleStayProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StayFromRequest $sejours,
        private readonly StayFolio $folio,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Stay
    {
        $sejour = $this->sejours->resolve($uriVariables);

        if (null === $sejour->getClosedAt()) {
            throw new ConflictHttpException('stay.error.stay_not_closed');
        }
        if (null !== $sejour->getSettledAt()) {
            throw new ConflictHttpException('stay.error.stay_already_settled');
        }

        if (!$this->folio->balanceOf($sejour)->isZero()) {
            // Solder un compte encore debiteur ferait disparaitre une creance d'un clic. Tant que
            // l'encaissement n'est pas branche, on refuse plutot que de mentir sur l'etat du compte.
            throw new ConflictHttpException('stay.error.balance_not_zero');
        }

        $sejour->settle(new \DateTimeImmutable('now'));
        $this->em->flush();

        return $sejour;
    }
}
