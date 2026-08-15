<?php

declare(strict_types=1);

namespace App\Securite\Service;

use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Permission;
use App\Securite\Entity\Utilisateur;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Plafond d'attribution des droits (RG-M8-09, CA-10) : un administrateur d'établissement ne peut
 * créer/modifier une `Affectation`/`DelegationDroit` que si les permissions attribuées sont
 * incluses dans ses propres droits effectifs sur l'établissement cible.
 *
 * ⚠ Point ouvert assumé (§2.7 plan) : l'exemption « administrateur groupe » suppose une notion
 * d'affectation au niveau Groupe absente du socle actuel (même écart que RG-M8-02) ; la règle
 * s'applique donc uniformément à tout auteur.
 */
final class VerificateurPlafondDroits
{
    public function __construct(
        private readonly CalculateurDroits $calculateur,
    ) {
    }

    /**
     * @param iterable<Permission> $permissionsAAttribuer
     */
    public function verifier(Utilisateur $auteur, Etablissement $cible, iterable $permissionsAAttribuer): void
    {
        $codesAuteur = $this->calculateur->codesEffectifs($auteur, $cible->getId());

        foreach ($permissionsAAttribuer as $permission) {
            if (!$this->calculateur->autorise($codesAuteur, $permission->getModule(), $permission->getAction())) {
                throw new AccessDeniedException(sprintf(
                    "Vous ne pouvez pas attribuer le droit « %s » : il excède vos propres droits sur cet établissement.",
                    $permission->getCode()
                ));
            }
        }
    }
}
