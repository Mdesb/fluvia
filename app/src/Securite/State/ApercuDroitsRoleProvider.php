<?php

declare(strict_types=1);

namespace App\Securite\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Role;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * `GET /roles/{id}/apercu-droits?etablissement={uuid}` (US-L7-05, CA-13, §2.9 plan-backoffice.md)
 * : retourne, pour un `Role` et un `Etablissement` donnés, la liste des codes `module.action` que
 * ce rôle confère — équivalent de `/me` mais pour un rôle simulé. Lecture seule : aucune écriture,
 * aucun changement de `ContexteEtablissement` réel pour l'appelant.
 *
 * Le socle actuel ne restreint pas les permissions d'un rôle par établissement (RG-M8-02 non
 * implémenté) : le résultat est donc équivalent à `role.permissions` mappé en codes. Le paramètre
 * `etablissement` est conservé et validé pour ne pas casser le contrat d'API si une restriction
 * par établissement est introduite plus tard.
 *
 * @implements ProviderInterface<JsonResponse>
 */
final class ApercuDroitsRoleProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $roleUuid = $this->trouverUuid($uriVariables['id'] ?? null);
        $role = $roleUuid !== null ? $this->em->getRepository(Role::class)->find($roleUuid) : null;
        if (!$role instanceof Role) {
            throw new NotFoundHttpException('Rôle introuvable.');
        }

        $etablissementParam = $this->requestStack->getCurrentRequest()?->query->get('etablissement');
        $etablissement = \is_string($etablissementParam) && Uuid::isValid($etablissementParam)
            ? $this->em->getRepository(Etablissement::class)->find($etablissementParam)
            : null;
        if ($etablissementParam !== null && !$etablissement instanceof Etablissement) {
            throw new NotFoundHttpException('Établissement introuvable.');
        }

        $codes = [];
        foreach ($role->getPermissions() as $permission) {
            $codes[] = $permission->getCode();
        }

        return new JsonResponse([
            'roleId' => (string) $role->getId(),
            'etablissementId' => $etablissement !== null ? (string) $etablissement->getId() : null,
            'codes' => array_values(array_unique($codes)),
        ]);
    }

    private function trouverUuid(mixed $valeur): ?Uuid
    {
        return match (true) {
            $valeur instanceof Uuid => $valeur,
            \is_string($valeur) && Uuid::isValid($valeur) => Uuid::fromString($valeur),
            default => null,
        };
    }
}
