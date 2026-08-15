<?php

declare(strict_types=1);

namespace App\Securite\State;

use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Securite\Entity\Utilisateur;
use App\Securite\Enum\StatutUtilisateur;
use App\Securite\Service\GardeDernierAdministrateur;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Suspension à effet immédiat (RG-M8-01, CA-3) — `POST /utilisateurs/{id}/suspendre` :
 * `statut = suspendu` et `tokenVersion++` (invalidation JWT) après vérification du garde-fou
 * « dernier administrateur » (RG-M8-07, CA-11). Réactivation — `POST /utilisateurs/{id}/reactiver` :
 * `statut = actif`, pas d'incrément de `tokenVersion` (aucune session à invalider en réautorisant).
 *
 * @implements ProcessorInterface<Utilisateur, Utilisateur>
 */
final class SuspensionUtilisateurProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GardeDernierAdministrateur $garde,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Utilisateur
    {
        \assert($data instanceof Utilisateur);

        $uriTemplate = $operation instanceof HttpOperation ? $operation->getUriTemplate() : null;
        $reactivation = str_contains((string) $uriTemplate, 'reactiver');

        if ($reactivation) {
            $data->setStatut(StatutUtilisateur::Actif);
            $this->em->flush();

            return $data;
        }

        $this->garde->verifierSuspension($data);

        $data->setStatut(StatutUtilisateur::Suspendu);
        $data->setTokenVersion($data->getTokenVersion() + 1);
        $this->em->flush();

        return $data;
    }
}
