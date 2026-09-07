<?php

declare(strict_types=1);

namespace App\Vente\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Vente\Entity\Vente;
use App\Vente\Port\MandateChoice;
use App\Vente\Port\SaleSubscriptionInterface;
use App\Vente\Service\LecteurCorps;
use App\Vente\Service\ValiderVenteService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Valide et scelle une vente (POST /ventes/{id}/valider, CA-8/11/12/15). Refuse si reste dû > 0 (sauf
 * différé), décrémente le stock atomiquement, scelle l'opération NF525, émet/appaire les supports et
 * applique le seuil d'impression — le tout dans une seule transaction. Corps optionnel :
 *   { "supports": [{"ligne": uuid, "type": "qr", "identifiant": "…"}] }
 *
 * @implements ProcessorInterface<Vente, Vente>
 */
final class ValiderVenteProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly ValiderVenteService $service,
        private readonly SaleSubscriptionInterface $saleSubscription,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Vente
    {
        \assert($data instanceof Vente);

        $overrides = [];
        foreach ($this->lecteur->corps()['supports'] ?? [] as $support) {
            if (\is_array($support) && isset($support['ligne'])) {
                $overrides[(string) $support['ligne']] = [
                    'type' => isset($support['type']) ? (string) $support['type'] : null,
                    'identifiant' => isset($support['identifiant']) ? (string) $support['identifiant'] : null,
                ];
            }
        }

        // LA CAISSE CRÉE L'ABONNEMENT (arbitrage du 07/09). Le choix de mandat vient du corps
        // optionnel `abonnement` (absent -> « counter »). Le rappel s'execute dans la transaction
        // de scellement : un refus de souscription fait rollback le scel, la vente reste EnCours.
        $mandate = MandateChoice::fromBody($this->lecteur->corps()['abonnement'] ?? null);
        $this->service->valider($data, $overrides, function (Vente $vente) use ($mandate): void {
            $this->saleSubscription->createSubscriptionsFromSale($vente, $mandate);
        });
        $this->em->flush();

        return $data;
    }
}
