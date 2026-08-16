<?php

declare(strict_types=1);

namespace App\Reservation\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Reservation\Entity\Emargement;
use App\Reservation\Entity\Reservation;
use App\Reservation\Enum\SourcePresence;
use App\Reservation\Enum\StatutEmargement;
use App\Securite\Entity\Utilisateur;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Émargement manuel présent/absent (cahier M5-05), upsert : un seul émargement courant par
 * réservation, modifiable jusqu'à la clôture du créneau. `présent` confirme la présence (§4.8).
 *
 * @implements ProcessorInterface<mixed, Emargement>
 */
final class EmargerProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Emargement
    {
        \assert($data instanceof Reservation);
        $reservation = $data;

        $corps = $this->lecteur->corps();
        $statut = StatutEmargement::tryFrom((string) ($corps['statut'] ?? ''));
        if ($statut === null) {
            throw new UnprocessableEntityHttpException('Statut d\'émargement invalide (présent|absent attendu).');
        }

        $emargement = $this->em->getRepository(Emargement::class)->findOneBy(['reservation' => $reservation]);
        if ($emargement === null) {
            $emargement = new Emargement();
            $emargement->setReservation($reservation);
            $this->em->persist($emargement);
        }

        $emargement->setStatut($statut)->setHorodatage(new \DateTimeImmutable());
        if (isset($corps['compteRendu']) && \is_string($corps['compteRendu'])) {
            $emargement->setCompteRendu($corps['compteRendu']);
        }

        $utilisateur = $this->security->getUser();
        if ($utilisateur instanceof Utilisateur) {
            $emargement->setOperateur($utilisateur);
        }

        if ($statut === StatutEmargement::Present) {
            $reservation->confirmerPresence(SourcePresence::EmargementManuel, new \DateTimeImmutable());
        } else {
            $reservation->setPresenceConfirmee(false);
            $reservation->setDateConfirmationPresence(null);
            $reservation->setSourcePresence(null);
        }

        $this->em->flush();

        return $emargement;
    }
}
