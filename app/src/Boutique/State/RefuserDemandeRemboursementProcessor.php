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
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * POST /boutique/demandes-remboursement/{id}/refuser (RG-M3-15, CA-17) : motif communiqué au
 * client. Corps : { "motifRefus": string }.
 *
 * @implements ProcessorInterface<DemandeRemboursement, JsonResponse>
 */
final class RefuserDemandeRemboursementProcessor implements ProcessorInterface
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
        $motifRefus = \is_string($corps['motifRefus'] ?? null) ? $corps['motifRefus'] : '';
        if (trim($motifRefus) === '') {
            throw new UnprocessableEntityHttpException('« motifRefus » est requis.');
        }

        $this->handler->refuser($data, $motifRefus, $operateur);

        return new JsonResponse([
            'demande' => (string) $data->getId(),
            'statut' => $data->getStatut()->value,
            'motifRefus' => $data->getMotifRefus(),
        ]);
    }
}
