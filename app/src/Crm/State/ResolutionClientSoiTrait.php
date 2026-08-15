<?php

declare(strict_types=1);

namespace App\Crm\State;

use App\Crm\Entity\Client;
use App\Securite\Entity\Utilisateur;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Contrôle impératif « soi-même » (permissions `crm.*_soi`) pour les Providers renvoyant une
 * `JsonResponse` (agrégation) plutôt que l'entité `Client` elle-même — l'expression `security`
 * déclarative d'API Platform ne peut pas y référencer `object.estLieA(user)` (§6/§10.8 plan-crm.md).
 * Nécessite un service `Symfony\Bundle\SecurityBundle\Security $security` sur la classe hôte.
 */
trait ResolutionClientSoiTrait
{
    private function verifierAccesSoi(Client $client, string $permissionComplete, string $permissionSoi): void
    {
        /** @var \Symfony\Bundle\SecurityBundle\Security $security */
        $security = $this->security ?? null;
        if ($security === null) {
            return;
        }
        if ($security->isGranted('PERM', $permissionComplete)) {
            return;
        }
        if ($security->isGranted('PERM', $permissionSoi)) {
            $utilisateur = $security->getUser();
            if ($utilisateur instanceof Utilisateur && $client->estLieA($utilisateur)) {
                return;
            }
        }

        throw new AccessDeniedHttpException('Accès refusé à ce client.');
    }
}
