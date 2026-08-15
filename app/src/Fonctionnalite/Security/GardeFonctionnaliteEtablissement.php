<?php

declare(strict_types=1);

namespace App\Fonctionnalite\Security;

use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\CalculateurDroits;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Contrôle impératif de droit **lié à l'établissement du chemin** (`{id}` de
 * `/etablissements/{id}/fonctionnalites*`), et non à l'établissement actif de l'en-tête
 * `X-Etablissement` (RG-SOCLE-05). Utilise directement `CalculateurDroits` (pas le voter `PERM`, qui ne
 * connaît que l'en-tête) : un utilisateur doit détenir `fonctionnalite.lire|gerer` (ou
 * `organisation.gerer`) via une affectation/délégation **sur cet établissement précis** pour agir dessus,
 * indépendamment de l'établissement actif transmis par ailleurs.
 */
final class GardeFonctionnaliteEtablissement
{
    public function __construct(
        private readonly Security $security,
        private readonly CalculateurDroits $calculateur,
    ) {
    }

    public function verifierLecture(Etablissement $etablissement): void
    {
        $this->verifier($etablissement, 'lire');
    }

    public function verifierGestion(Etablissement $etablissement): void
    {
        $this->verifier($etablissement, 'gerer');
    }

    private function verifier(Etablissement $etablissement, string $action): void
    {
        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            throw new AccessDeniedHttpException('Authentification requise.');
        }

        $codes = $this->calculateur->codesEffectifs($utilisateur, $etablissement->getId());
        $autorise = $this->calculateur->autorise($codes, 'fonctionnalite', $action)
            || ($action === 'lire' && $this->calculateur->autorise($codes, 'fonctionnalite', 'gerer'))
            || $this->calculateur->autorise($codes, 'organisation', 'gerer');

        if (!$autorise) {
            throw new AccessDeniedHttpException('Accès refusé aux fonctionnalités de cet établissement.');
        }
    }
}
