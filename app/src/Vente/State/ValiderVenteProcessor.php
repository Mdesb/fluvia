<?php

declare(strict_types=1);

namespace App\Vente\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Vente\Entity\Vente;
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

        $this->service->valider($data, $overrides);
        $this->em->flush();

        return $data;
    }
}
