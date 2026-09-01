<?php

declare(strict_types=1);

namespace App\Dining\Security;

use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\CalculateurDroits;
use App\Securite\Service\ContexteEtablissement;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Defense en profondeur pour `App\Dining` (D3/D8) — meme patron que `App\Stay\Security\StayScopeGuard`,
 * recopie plutot qu importe : D2 interdit l appel direct de module a module.
 *
 * **404 et jamais 403.** Une addition porte ce qu une table a mange et a quelle heure ; sa reference
 * est courte et lisible en salle. Repondre 403 confirmerait son existence et suffirait a enumerer le
 * service d un concurrent du meme groupe. « Existe mais interdit » et « n existe pas » doivent etre
 * indiscernables.
 */
final class DiningScopeGuard
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

    /** @throws NotFoundHttpException si absent ou hors perimetre — 404 uniforme. */
    public function verify(
        ?Etablissement $etablissement,
        string $message = 'dining.error.order_not_found',
    ): Etablissement {
        if (!$this->isInScope($etablissement)) {
            throw new NotFoundHttpException($message);
        }

        return $etablissement;
    }
}
