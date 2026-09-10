<?php

declare(strict_types=1);

namespace App\Group\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Group\Entity\GroupBooking;
use App\Group\Enum\GroupBookingStatus;
use App\Organisation\Entity\Etablissement;
use App\Reservation\Entity\Activite;
use App\Reservation\Entity\Creneau;
use App\Securite\Service\ContexteEtablissement;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Affecte une réservation de groupe à une activité / un créneau (POST /group/bookings/{id}/assign).
 * Corps : { "creneau"?, "activite"? } (UUID ou IRI ; au moins l'un des deux).
 *
 * La réservation est déjà cloisonnée (chargée via l'extension de périmètre) ; le créneau et l'activité
 * visés sont vérifiés appartenir au même établissement actif. On refuse d'affecter une réservation
 * annulée.
 *
 * @implements ProcessorInterface<GroupBooking, GroupBooking>
 */
final class AssignGroupBookingProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ContexteEtablissement $contexte,
        private readonly LecteurCorps $lecteur,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): GroupBooking
    {
        \assert($data instanceof GroupBooking);

        if ($data->getStatus() === GroupBookingStatus::Cancelled) {
            throw new UnprocessableEntityHttpException('Une réservation annulée ne peut plus être affectée.');
        }

        $actif = $this->contexte->etablissementActif();
        if ($actif === null) {
            throw new UnprocessableEntityHttpException('Établissement actif requis (en-tête X-Etablissement).');
        }

        $corps = $this->lecteur->corps();

        $creneau = null;
        if (($corps['creneau'] ?? null) !== null && $corps['creneau'] !== '') {
            $creneau = $this->resoudre(Creneau::class, $corps['creneau'], 'creneau');
            \assert($creneau instanceof Creneau);
            $this->memeEtablissement($creneau->getEtablissement(), $actif, 'creneau');
        }

        $activite = null;
        if (($corps['activite'] ?? null) !== null && $corps['activite'] !== '') {
            $activite = $this->resoudre(Activite::class, $corps['activite'], 'activite');
            \assert($activite instanceof Activite);
            $this->memeEtablissement($activite->getEtablissement(), $actif, 'activite');
        }

        if ($creneau === null && $activite === null) {
            throw new UnprocessableEntityHttpException('Fournir au moins « creneau » ou « activite ».');
        }

        if ($creneau !== null) {
            $data->setCreneau($creneau);
            if ($activite === null) {
                $activite = $creneau->getActivite();
            }
        }
        if ($activite !== null) {
            $data->setActivite($activite);
        }

        $this->em->flush();

        return $data;
    }

    private function memeEtablissement(?Etablissement $porte, Etablissement $actif, string $champ): void
    {
        if ($porte === null || !$porte->getId()->equals($actif->getId())) {
            throw new NotFoundHttpException(sprintf('%s introuvable dans l\'établissement actif.', $champ));
        }
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $classe
     *
     * @return T
     */
    private function resoudre(string $classe, mixed $reference, string $champ): object
    {
        $segment = \is_string($reference) && str_contains($reference, '/') ? basename($reference) : $reference;
        if (!\is_string($segment) || $segment === '' || !Uuid::isValid($segment)) {
            throw new UnprocessableEntityHttpException(sprintf('Référence « %s » invalide (UUID ou IRI).', $champ));
        }
        $entite = $this->em->getRepository($classe)->find(Uuid::fromString($segment));
        if ($entite === null) {
            throw new NotFoundHttpException(sprintf('%s introuvable.', $champ));
        }

        return $entite;
    }
}
