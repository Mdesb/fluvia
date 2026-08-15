<?php

declare(strict_types=1);

namespace App\Securite\Controller;

use App\Fonctionnalite\Service\Fonctionnalites;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\CalculateurDroits;
use App\Securite\Service\ContexteEtablissement;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Profil de l'utilisateur courant + droits effectifs sur l'établissement actif (US-L0-05).
 * `capacitesActives` (module `App\Fonctionnalite`) permet à l'UI de n'afficher que les fonctionnalités
 * pertinentes pour l'établissement actif (règle d'or §2 constitution.md) — champ additif, ne modifie
 * aucun champ existant du contrat `/me`.
 */
#[AsController]
final class MeController
{
    public function __construct(
        private readonly Security $security,
        private readonly ContexteEtablissement $contexte,
        private readonly CalculateurDroits $calculateur,
        private readonly Fonctionnalites $fonctionnalites,
    ) {
    }

    #[Route('/me', name: 'securite_me', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return new JsonResponse(['message' => 'Non authentifié.'], 401);
        }

        $etablissementActif = $this->contexte->idActif();
        $etablissementActifEntite = $this->contexte->etablissementActif();

        return new JsonResponse([
            'id' => (string) $utilisateur->getId(),
            'email' => $utilisateur->getEmail(),
            'nom' => $utilisateur->getNom(),
            'actif' => $utilisateur->isActif(),
            'etablissementActif' => $etablissementActif !== null ? (string) $etablissementActif : null,
            'droits' => $this->calculateur->codesEffectifs($utilisateur, $etablissementActif),
            'capacitesActives' => $etablissementActifEntite !== null ? $this->fonctionnalites->actives($etablissementActifEntite) : [],
        ]);
    }
}
