<?php

declare(strict_types=1);

namespace App\PublicApi\Enum;

/**
 * L'etat d'un consentement.
 *
 * ⚠ **REVOQUE EST ABSORBANT.** Un exploitant qui retire l'acces a une application ne doit pas le
 * voir revenir parce que le partenaire a refait une demande. Reaccorder est un geste explicite, qui
 * cree un consentement neuf et laisse le retrait dans l'historique.
 */
enum GrantStatus: string
{
    case Active = 'active';
    case Revoked = 'revoked';
}
