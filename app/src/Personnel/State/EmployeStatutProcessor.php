<?php

declare(strict_types=1);

namespace App\Personnel\State;

use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Personnel\Entity\BadgeStaff;
use App\Personnel\Entity\Employe;
use App\Personnel\Enum\StatutBadgeStaff;
use App\Personnel\Enum\StatutEmploye;
use App\Personnel\Service\RevocationBadgeHandler;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Suspension/réactivation d'un Employé (`POST /personnel/employes/{id}/suspendre` | `/reactiver`,
 * §4.8 spec, risque n°11 du plan) : suspend/réactive **automatiquement** le(s) `BadgeStaff` actif(s)
 * de l'employé (comportement retenu par ce plan, à confirmer produit).
 *
 * @implements ProcessorInterface<Employe, Employe>
 */
final class EmployeStatutProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RevocationBadgeHandler $revocationHandler,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Employe
    {
        \assert($data instanceof Employe);

        $agent = $this->security->getUser();
        if (!$agent instanceof Utilisateur) {
            throw new UnprocessableEntityHttpException('Agent authentifié requis.');
        }

        $uriTemplate = $operation instanceof HttpOperation ? $operation->getUriTemplate() : null;
        $reactivation = str_contains((string) $uriTemplate, 'reactiver');

        if ($reactivation) {
            $data->setStatut(StatutEmploye::Actif);
            $this->em->flush();

            foreach ($this->badgesSuspendus($data) as $badge) {
                $this->revocationHandler->reactiver($badge, $agent);
            }

            return $data;
        }

        $data->setStatut(StatutEmploye::Suspendu);
        $this->em->flush();

        foreach ($this->badgesActifs($data) as $badge) {
            $this->revocationHandler->suspendre($badge, 'Suspension de l\'employé', $agent);
        }

        return $data;
    }

    /** @return list<BadgeStaff> */
    private function badgesActifs(Employe $employe): array
    {
        return $this->em->getRepository(BadgeStaff::class)->findBy(['employe' => $employe, 'statut' => StatutBadgeStaff::Actif]);
    }

    /** @return list<BadgeStaff> */
    private function badgesSuspendus(Employe $employe): array
    {
        return $this->em->getRepository(BadgeStaff::class)->findBy(['employe' => $employe, 'statut' => StatutBadgeStaff::Suspendu]);
    }
}
