<?php

declare(strict_types=1);

namespace App\Boutique\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Boutique\Entity\DemandeRemboursement;
use App\Boutique\Service\TraiterDemandeRemboursementHandler;
use App\Securite\Entity\Utilisateur;
use App\Vente\Service\LecteurCorps;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * POST /boutique/demandes-remboursement/{id}/accepter (RG-M3-15, CA-17) : déclenche un avoir M2 via
 * `ContrePassationHandler` (réutilisé). Corps : { "montant"?: string } (défaut = total).
 *
 * @implements ProcessorInterface<DemandeRemboursement, JsonResponse>
 */
final class AccepterDemandeRemboursementProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly LecteurCorps $lecteur,
        private readonly TraiterDemandeRemboursementHandler $handler,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        \assert($data instanceof DemandeRemboursement);
        $operateur = $this->security->getUser();
        \assert($operateur instanceof Utilisateur);

        $corps = $this->lecteur->corps();
        $montant = isset($corps['montant']) ? (string) $corps['montant'] : null;

        $avoir = $this->handler->accepter($data, $montant, $operateur);

        return new JsonResponse([
            'demande' => (string) $data->getId(),
            'statut' => $data->getStatut()->value,
            'avoir' => (string) $avoir->getId(),
            'montant' => $avoir->getMontant(),
        ]);
    }
}
