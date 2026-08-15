<?php

declare(strict_types=1);

namespace App\Securite\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Securite\Entity\DelegationDroit;
use App\Securite\Entity\Utilisateur;
use App\Securite\Enum\StatutDelegation;
use App\Securite\Service\VerificateurPlafondDroits;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * `POST /delegations` (US-L7-07, CA-7) : `dateFin` obligatoire (déjà porté par `Assert\NotNull`
 * sur l'entité, 422 automatique) ; plafond d'attribution (RG-M8-09, CA-10) — le délégant ne peut
 * déléguer que des droits qu'il possède lui-même sur l'établissement concerné. Le délégant par
 * défaut est l'auteur de la requête si non fourni.
 *
 * @implements ProcessorInterface<DelegationDroit, DelegationDroit>
 */
final class DelegationDroitProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<DelegationDroit, DelegationDroit> $persistProcessor
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private readonly ProcessorInterface $persistProcessor,
        private readonly VerificateurPlafondDroits $plafond,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): DelegationDroit
    {
        \assert($data instanceof DelegationDroit);

        if ($data->getDateFin() === null) {
            throw new UnprocessableEntityHttpException('La date de fin est obligatoire pour une délégation de droits.');
        }
        if ($data->getDateDebut() !== null && $data->getDateFin() <= $data->getDateDebut()) {
            throw new UnprocessableEntityHttpException('La date de fin doit être postérieure à la date de début.');
        }

        if ($data->getDelegant() === null) {
            $auteur = $this->security->getUser();
            if ($auteur instanceof Utilisateur) {
                $data->setDelegant($auteur);
            }
        }

        $data->setStatut(StatutDelegation::Active);

        $role = $data->getRole();
        $etablissement = $data->getEtablissement();
        $delegant = $data->getDelegant();
        if ($role !== null && $etablissement !== null && $delegant instanceof Utilisateur) {
            $this->plafond->verifier($delegant, $etablissement, $role->getPermissions());
        }

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}
