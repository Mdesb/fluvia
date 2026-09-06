<?php

declare(strict_types=1);

namespace App\PublicApi\Controller;

use App\PublicApi\Security\PartnerUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `GET /v1/me` — ce que la cle presentee identifie, et rien d'autre.
 *
 * C'est le premier point d'entree de toute API a cles, et il n'est pas decoratif : un integrateur
 * doit pouvoir verifier sa cle SANS toucher a des donnees metier, et notre suite doit pouvoir
 * prouver la chaine d'authentification sans dependre d'une ressource qui n'existe pas encore.
 *
 * ⚠ **IL N'EXPOSE AUCUNE DONNEE D'ETABLISSEMENT**, seulement les identifiants de ceux qui ont
 * consenti et les portees accordees. Un partenaire y lit ce qu'il peut demander ; il n'y lit rien
 * de ce que ca contient.
 */
final class WhoAmIController extends AbstractController
{
    #[Route('/v1/me', name: 'public_api_whoami', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $partenaire = $this->getUser();

        // Le pare-feu `public_api` ne resout que des `PartnerUser` ; ce garde-fou existe pour le cas
        // ou l'ordre des pare-feux changerait sans que personne le remarque.
        if (!$partenaire instanceof PartnerUser) {
            return new JsonResponse(['message' => 'Clé d\'API requise.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $consentements = [];

        foreach ($partenaire->grants as $grant) {
            $etablissement = $grant->getEtablissement();

            if (null === $etablissement) {
                continue;
            }

            $consentements[] = [
                'establishment' => (string) $etablissement->getId(),
                'scopes' => $grant->getScopes(),
            ];
        }

        return new JsonResponse([
            'application' => [
                'id' => (string) $partenaire->application->getId(),
                'name' => $partenaire->application->getName(),
            ],
            'grants' => $consentements,
        ]);
    }
}
