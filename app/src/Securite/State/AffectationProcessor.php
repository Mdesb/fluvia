<?php

declare(strict_types=1);

namespace App\Securite\State;

use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\GardeDernierAdministrateur;
use App\Securite\Service\RoleAPrivileges;
use App\Securite\Service\VerificateurPlafondDroits;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Garde de l'`Affectation` (§2.6/§2.7 plan-backoffice.md) :
 * - **Post** : plafond d'attribution (RG-M8-09, CA-10) — l'auteur ne peut affecter que des droits
 *   ≤ aux siens sur l'établissement cible ; garde MFA (RG-M8-06, CA-4) — refuse (422) l'affectation
 *   à un rôle à privilèges si `beneficiaire.mfaActif === false`.
 * - **Delete** : garde dernier administrateur (RG-M8-07, CA-11).
 *
 * @implements ProcessorInterface<Affectation, Affectation|null>
 */
final class AffectationProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<Affectation, Affectation> $persistProcessor
     * @param ProcessorInterface<Affectation, null>         $removeProcessor
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private readonly ProcessorInterface $persistProcessor,
        #[Autowire(service: 'api_platform.doctrine.orm.state.remove_processor')]
        private readonly ProcessorInterface $removeProcessor,
        private readonly GardeDernierAdministrateur $gardeDernierAdmin,
        private readonly VerificateurPlafondDroits $plafond,
        private readonly RoleAPrivileges $roleAPrivileges,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        \assert($data instanceof Affectation);

        if ($operation instanceof DeleteOperationInterface) {
            $this->gardeDernierAdmin->verifierSuppressionAffectation($data);

            return $this->removeProcessor->process($data, $operation, $uriVariables, $context);
        }

        $role = $data->getRole();
        $etablissement = $data->getEtablissement();
        $beneficiaire = $data->getUtilisateur();

        if ($role !== null && $etablissement !== null) {
            $auteur = $this->security->getUser();
            if ($auteur instanceof Utilisateur) {
                $this->plafond->verifier($auteur, $etablissement, $role->getPermissions());
            }
        }

        if ($role !== null && $beneficiaire !== null
            && $this->roleAPrivileges->estAPrivileges($role) && !$beneficiaire->isMfaActif()
        ) {
            throw new UnprocessableEntityHttpException(
                "Ce rôle est à privilèges et exige le MFA : activez le MFA du bénéficiaire avant de l'affecter."
            );
        }

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}
