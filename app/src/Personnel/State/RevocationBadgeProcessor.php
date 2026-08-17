<?php

declare(strict_types=1);

namespace App\Personnel\State;

use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Personnel\Entity\BadgeStaff;
use App\Personnel\Service\RevocationBadgeHandler;
use App\Securite\Entity\Utilisateur;
use App\Vente\Service\LecteurCorps;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Révocation/suspension/réactivation manuelle d'un badge staff (POST /personnel/badges/{id}/revoquer
 * | /suspendre | /reactiver, RG-PERSO-08, CA-10). Corps (revoquer/suspendre) : { "motif": "…" }.
 *
 * @implements ProcessorInterface<BadgeStaff, BadgeStaff>
 */
final class RevocationBadgeProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly LecteurCorps $lecteur,
        private readonly RevocationBadgeHandler $handler,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): BadgeStaff
    {
        \assert($data instanceof BadgeStaff);

        $agent = $this->security->getUser();
        if (!$agent instanceof Utilisateur) {
            throw new UnprocessableEntityHttpException('Agent authentifié requis.');
        }

        $uriTemplate = (string) ($operation instanceof HttpOperation ? $operation->getUriTemplate() : '');

        if (str_contains($uriTemplate, 'reactiver')) {
            return $this->handler->reactiver($data, $agent);
        }

        $motif = (string) ($this->lecteur->corps()['motif'] ?? 'Révocation manuelle (Administrateur RH).');

        if (str_contains($uriTemplate, 'suspendre')) {
            return $this->handler->suspendre($data, $motif, $agent);
        }

        return $this->handler->revoquer($data, $motif, $agent);
    }
}
