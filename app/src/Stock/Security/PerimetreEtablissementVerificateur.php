<?php

declare(strict_types=1);

namespace App\Stock\Security;

use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\CalculateurDroits;
use App\Securite\Service\ContexteEtablissement;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Défense en profondeur contre l'IDOR cross-tenant (RG-SOCLE-05) : plusieurs processors/providers de
 * `App\Stock` résolvent une entité à partir d'un identifiant fourni dans le corps de requête via un
 * `find()` Doctrine brut (hors `PerimetreStockExtension`, qui ne s'applique qu'aux lectures
 * standard API Platform). Ce service revérifie explicitement, après une telle résolution manuelle,
 * que l'établissement de l'entité obtenue fait bien partie du périmètre de l'appelant : établissement
 * actif (`ContexteEtablissement::idActif()`) ou toute affectation portant au moins un droit sur cet
 * établissement précis (`CalculateurDroits::codesEffectifs`). Réutilise ces deux services socle sans
 * les réécrire (même patron que `PerimetreStockExtension`).
 */
final class PerimetreEtablissementVerificateur
{
    public function __construct(
        private readonly Security $security,
        private readonly ContexteEtablissement $contexte,
        private readonly CalculateurDroits $calculateur,
    ) {
    }

    public function estDansLePerimetre(?Etablissement $etablissement): bool
    {
        if ($etablissement === null) {
            return false;
        }

        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return false;
        }

        $idActif = $this->contexte->idActif();
        if ($idActif !== null && $idActif->equals($etablissement->getId())) {
            return true;
        }

        return $this->calculateur->codesEffectifs($utilisateur, $etablissement->getId()) !== [];
    }

    /**
     * @throws AccessDeniedHttpException si l'établissement est absent ou hors du périmètre de l'appelant.
     */
    public function verifier(
        ?Etablissement $etablissement,
        string $message = 'Ressource hors du périmètre de l\'établissement de l\'appelant (RG-SOCLE-05).',
    ): Etablissement {
        if (!$this->estDansLePerimetre($etablissement)) {
            throw new AccessDeniedHttpException($message);
        }

        return $etablissement;
    }
}
