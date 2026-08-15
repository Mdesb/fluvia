<?php

declare(strict_types=1);

namespace App\Securite\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Securite\Entity\DelegationDroit;
use App\Securite\Entity\Utilisateur;
use App\Securite\Enum\StatutDelegation;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * `POST /delegations/{id}/revoquer {motif?}` (US-L7-07, CA-9) : révocation anticipée par le
 * déléguant ou un administrateur — effet immédiat sur `codesEffectifs()` (filtré sur
 * `statut = active`).
 *
 * @implements ProcessorInterface<DelegationDroit, DelegationDroit>
 */
final class RevocationDelegationProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): DelegationDroit
    {
        \assert($data instanceof DelegationDroit);

        $auteur = $this->security->getUser();
        $motif = (string) ($this->lecteur->corps()['motif'] ?? '');

        $data->setStatut(StatutDelegation::Revoquee);
        $data->setDateRevocation(new \DateTimeImmutable());
        $data->setMotifRevocation($motif !== '' ? $motif : null);
        if ($auteur instanceof Utilisateur) {
            $data->setRevoquePar($auteur);
        }

        $this->em->flush();

        return $data;
    }
}
