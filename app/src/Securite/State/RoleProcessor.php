<?php

declare(strict_types=1);

namespace App\Securite\State;

use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Securite\Entity\Permission;
use App\Securite\Entity\Role;
use App\Securite\Service\GardeDernierAdministrateur;
use Doctrine\ORM\PersistentCollection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Garde dernier administrateur (RG-M8-07, CA-11, §2.6 plan-backoffice.md) sur `Role` : refuse
 * (422) le retrait de `securite.gerer` (Patch) ou la suppression (Delete) d'un rôle qui
 * laisserait un établissement sans administrateur.
 *
 * Sur Patch, le corps a déjà été dénormalisé sur l'entité managée AVANT l'appel à ce processor :
 * la collection `permissions` (PersistentCollection) porte donc l'état PROPOSÉ, tandis que son
 * « snapshot » (chargé depuis la base à la première initialisation, avant mutation) porte l'état
 * ACTUEL — comparés pour détecter un retrait de `securite.gerer`.
 *
 * @implements ProcessorInterface<Role, Role|null>
 */
final class RoleProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<Role, Role> $persistProcessor
     * @param ProcessorInterface<Role, null> $removeProcessor
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private readonly ProcessorInterface $persistProcessor,
        #[Autowire(service: 'api_platform.doctrine.orm.state.remove_processor')]
        private readonly ProcessorInterface $removeProcessor,
        private readonly GardeDernierAdministrateur $garde,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        \assert($data instanceof Role);

        if ($operation instanceof DeleteOperationInterface) {
            $this->garde->verifierModificationRole($data, avaitSecuriteGererAvant: $this->garde->roleEstAdministrateur($data), conserveraSecuriteGererApres: false);

            return $this->removeProcessor->process($data, $operation, $uriVariables, $context);
        }

        $permissions = $data->getPermissions();
        if ($permissions instanceof PersistentCollection) {
            // Force l'initialisation AVANT lecture du snapshot : si la requête n'a pas touché
            // `permissions`, l'initialisation charge l'état courant (identique avant/après, pas
            // de faux positif) ; si elle l'a modifié, l'initialisation a déjà eu lieu pendant la
            // dénormalisation (avant l'appel à ce processor) et le snapshot reflète l'état pristine.
            $permissions->initialize();
        }
        $avant = $permissions instanceof PersistentCollection
            ? $this->contientSecuriteGerer($permissions->getSnapshot())
            : $this->garde->roleEstAdministrateur($data);
        $apres = $this->garde->roleEstAdministrateur($data);

        $this->garde->verifierModificationRole($data, avaitSecuriteGererAvant: $avant, conserveraSecuriteGererApres: $apres);

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }

    /** @param iterable<Permission> $permissions */
    private function contientSecuriteGerer(iterable $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($permission->getCode() === 'securite.gerer') {
                return true;
            }
        }

        return false;
    }
}
