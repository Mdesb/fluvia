<?php

declare(strict_types=1);

namespace App\Securite\Security;

use App\Securite\Entity\Utilisateur;
use App\Securite\Service\CalculateurDroits;
use App\Securite\Service\ContexteEtablissement;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Voter des droits fins (RG-SOCLE-04). Attribut « PERM », sujet « module.action ».
 * Autorise si l'union des permissions des affectations de l'utilisateur sur l'établissement
 * actif (en-tête X-Etablissement) couvre le couple demandé.
 *
 * @extends Voter<string, string>
 */
final class PermissionVoter extends Voter
{
    public const ATTRIBUTE = 'PERM';

    public function __construct(
        private readonly ContexteEtablissement $contexte,
        private readonly CalculateurDroits $calculateur,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::ATTRIBUTE && \is_string($subject) && str_contains($subject, '.');
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $utilisateur = $token->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return false;
        }

        [$module, $action] = array_pad(explode('.', (string) $subject, 2), 2, '');

        $codes = $this->calculateur->codesEffectifs($utilisateur, $this->contexte->idActif());

        return $this->calculateur->autorise($codes, $module, $action);
    }
}
