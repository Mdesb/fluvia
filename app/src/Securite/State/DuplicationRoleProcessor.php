<?php

declare(strict_types=1);

namespace App\Securite\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Securite\Entity\Role;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * `POST /roles/{id}/dupliquer {nom}` (US-L7-04, CA-12, §2.8 plan-backoffice.md) : crée un nouveau
 * `Role` (nouveau nom, `estModele = false`, `roleModeleOrigine = role source`), copie
 * INDÉPENDANTE de l'ensemble des `Permission`. Fonctionne aussi bien sur un rôle-modèle que sur
 * un rôle ordinaire.
 *
 * @implements ProcessorInterface<Role, Role>
 */
final class DuplicationRoleProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Role
    {
        \assert($data instanceof Role);

        $nom = trim((string) ($this->lecteur->corps()['nom'] ?? ''));
        if ($nom === '') {
            throw new UnprocessableEntityHttpException('Le nom du rôle dupliqué est requis.');
        }

        $copie = new Role();
        $copie->setNom($nom);
        $copie->setEstModele(false);
        $copie->setRoleModeleOrigine($data);
        foreach ($data->getPermissions() as $permission) {
            $copie->addPermission($permission);
        }

        $this->em->persist($copie);
        $this->em->flush();

        return $copie;
    }
}
