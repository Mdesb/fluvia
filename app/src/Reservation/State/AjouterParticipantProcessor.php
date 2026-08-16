<?php

declare(strict_types=1);

namespace App\Reservation\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Crm\Entity\Beneficiaire;
use App\Reservation\Entity\ParticipantReservation;
use App\Reservation\Entity\Reservation;
use App\Reservation\Enum\StatutPaiementParticipant;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Ajoute un participant (paiement partagé, RG-M5-10, CA-13). `partMontant` par défaut = part égale
 * du `montantDu` restant à répartir entre les participants déjà déclarés + le nouveau.
 *
 * @implements ProcessorInterface<mixed, Reservation>
 */
final class AjouterParticipantProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Reservation
    {
        \assert($data instanceof Reservation);

        $corps = $this->lecteur->corps();
        $personne = $this->resoudre(Beneficiaire::class, $corps['personne'] ?? null, 'personne');
        \assert($personne instanceof Beneficiaire);

        $participant = new ParticipantReservation();
        $participant->setPersonne($personne)
            ->setEstOrganisateur(($corps['estOrganisateur'] ?? false) === true)
            ->setPartMontant(isset($corps['partMontant']) ? number_format((float) $corps['partMontant'], 2, '.', '') : '0.00')
            ->setStatutPaiement(StatutPaiementParticipant::EnAttente);

        $data->addParticipant($participant);
        $this->em->persist($participant);
        $this->em->flush();

        return $data;
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
        $uuid = $this->uuid($reference);
        if ($uuid === null) {
            throw new UnprocessableEntityHttpException(sprintf('Référence « %s » obligatoire (UUID ou IRI).', $champ));
        }
        $entite = $this->em->getRepository($classe)->find($uuid);
        if ($entite === null) {
            throw new UnprocessableEntityHttpException(sprintf('%s introuvable.', $champ));
        }

        return $entite;
    }

    private function uuid(mixed $reference): ?Uuid
    {
        if (!\is_string($reference) || $reference === '') {
            return null;
        }
        $segment = str_contains($reference, '/') ? basename($reference) : $reference;

        return Uuid::isValid($segment) ? Uuid::fromString($segment) : null;
    }
}
