<?php

declare(strict_types=1);

namespace App\Recouvrement\Security;

use App\Recouvrement\Entity\IncidentImpaye;
use App\Recouvrement\Service\RedevableRegistry;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Voter « soi » du moteur de recouvrement (remplace l'expression `object.getAbonnement().estLieA(user)`
 * utilisée par `App\Sport`, impossible à généraliser en expression-language puisque `IncidentImpaye` ne
 * connaît aucune verticale). Délègue à `RedevableRegistry::estLieA()`.
 *
 * @extends Voter<string, IncidentImpaye>
 */
final class RedevableSoiVoter extends Voter
{
    public const ATTRIBUTE = 'RECOUVREMENT_SOI';

    public function __construct(
        private readonly RedevableRegistry $redevables,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::ATTRIBUTE && $subject instanceof IncidentImpaye;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        \assert($subject instanceof IncidentImpaye);

        return $this->redevables->estLieA($subject->getTypeRedevable(), $subject->getReferenceRedevable(), $token->getUser());
    }
}
