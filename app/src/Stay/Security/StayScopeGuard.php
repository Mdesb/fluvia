<?php

declare(strict_types=1);

namespace App\Stay\Security;

use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\CalculateurDroits;
use App\Securite\Service\ContexteEtablissement;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Défense en profondeur pour `App\Stay` (D3/D8) — même patron que `App\Dms\Security\DmsScopeGuard`,
 * recopié plutôt qu'importé : `Stay` ne dépend pas de `Dms` (D2).
 *
 * **404 et jamais 403**, comme la GED : répondre 403 sur un séjour d'un autre établissement
 * confirmerait son existence. Or une référence de séjour est courte et devinable (`SEJ-0001`) — c'est
 * précisément le cas où « existe mais interdit » et « n'existe pas » doivent être indiscernables.
 *
 * Le périmètre se dérive de la **session serveur** (`ContexteEtablissement`) et des droits effectifs
 * de l'utilisateur, jamais d'un identifiant reçu dans le corps ou l'URL.
 */
final class StayScopeGuard
{
    public function __construct(
        private readonly Security $security,
        private readonly ContexteEtablissement $contexte,
        private readonly CalculateurDroits $calculateur,
    ) {
    }

    public function isInScope(?Etablissement $etablissement): bool
    {
        if (null === $etablissement) {
            return false;
        }

        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return false;
        }

        $idActif = $this->contexte->idActif();
        if (null !== $idActif && $idActif->equals($etablissement->getId())) {
            return true;
        }

        return [] !== $this->calculateur->codesEffectifs($utilisateur, $etablissement->getId());
    }

    /** @throws NotFoundHttpException si absent ou hors périmètre — 404 uniforme. */
    public function verify(
        ?Etablissement $etablissement,
        string $message = 'stay.error.stay_not_found',
    ): Etablissement {
        if (!$this->isInScope($etablissement)) {
            throw new NotFoundHttpException($message);
        }

        return $etablissement;
    }
}
