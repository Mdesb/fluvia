<?php

declare(strict_types=1);

namespace App\Acces\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Acces\Entity\DeclarationPerteVol;
use App\Acces\Service\BlocageSupportHandler;
use App\Securite\Entity\Utilisateur;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Annule une déclaration de perte/vol (POST /acces/declarations/{id}/annuler, US-L3-09, CA-10) :
 * réversible par un rôle habilité, réactive le support.
 *
 * @implements ProcessorInterface<DeclarationPerteVol, DeclarationPerteVol>
 */
final class AnnulerDeclarationProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly BlocageSupportHandler $handler,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): DeclarationPerteVol
    {
        \assert($data instanceof DeclarationPerteVol);

        $agent = $this->security->getUser();
        if (!$agent instanceof Utilisateur) {
            throw new UnprocessableEntityHttpException('Agent authentifié requis.');
        }

        return $this->handler->annuler($data, $agent);
    }
}
