<?php

declare(strict_types=1);

namespace App\Marketing\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Marketing\Entity\Referral;
use App\Marketing\Service\MarketingContext;
use App\Marketing\Service\ReferralService;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `POST /marketing/parrainages` — un code, un filleul, et trois refus.
 *
 * Le parrain n'est **jamais reçu du client** : il est déduit du code. L'accepter permettrait
 * d'attribuer un parrainage à n'importe qui — donc de créditer n'importe qui.
 *
 * @implements ProcessorInterface<Referral, Referral>
 */
final readonly class ReferralDeclarationProcessor implements ProcessorInterface
{
    public function __construct(
        private MarketingContext $contexte,
        private ReferralService $parrainage,
    ) {
    }

    /**
     * @param Referral             $data
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Referral
    {
        [$utilisateur, $etablissement] = $this->contexte->exigerAutorite('fidelite', 'gerer');

        $filleul = $data->getRefereeRef();
        if ($filleul === null) {
            throw new NotFoundHttpException('Client introuvable.');
        }

        // Le filleul doit être dans le périmètre du lecteur ; le parrain le sera forcément, puisque
        // son code appartient à cet établissement.
        $this->contexte->exigerClientDansLePerimetre($filleul->toRfc4122(), $utilisateur);

        return $this->parrainage->declarer($data->getCode(), $filleul, $etablissement);
    }
}
