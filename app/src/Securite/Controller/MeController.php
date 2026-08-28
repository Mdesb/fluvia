<?php

declare(strict_types=1);

namespace App\Securite\Controller;

use App\Fonctionnalite\Service\Fonctionnalites;
use App\Securite\Entity\Utilisateur;
use App\Organisation\Service\EditorTenantResolver;
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
        private readonly EditorTenantResolver $editeur,
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
            // ⚠ « SUIS-JE CHEZ MOI OU CHEZ UN CLIENT ? » — la question que se pose un employé de
            // l'éditeur entré sur le site d'un client par un accès d'assistance. Sans réponse à
            // l'écran, il écrira une note au mauvais endroit, ou lira des chiffres en croyant que
            // ce sont ceux de l'éditeur.
            //
            // `isEditor()` ne lève jamais et rend `false` quand la désignation manque : fermé par
            // défaut. Un déploiement sans `EDITOR_TENANT_ID` n'a donc pas d'éditeur — ce qui est
            // exact, et non « tout le monde l'est ».
            'estEditeur' => $this->editeur->isEditor($etablissementActifEntite),
            'capacitesActives' => $etablissementActifEntite !== null ? $this->fonctionnalites->actives($etablissementActifEntite) : [],
        ]);
    }
}
