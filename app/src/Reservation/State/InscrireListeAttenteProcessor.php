<?php

declare(strict_types=1);

namespace App\Reservation\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Crm\Entity\Beneficiaire;
use App\Reservation\Entity\Creneau;
use App\Reservation\Entity\ListeAttente;
use App\Reservation\Enum\StatutListeAttente;
use App\Reservation\Service\RequestedQuantityReader;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Inscrit un bénéficiaire en liste d'attente sur un Créneau complet (RG-M5-06, CA-4), rang =
 * premier arrivé premier servi.
 *
 * @implements ProcessorInterface<mixed, ListeAttente>
 */
final class InscrireListeAttenteProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly RequestedQuantityReader $quantiteDemandee,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ListeAttente
    {
        \assert($data instanceof Creneau);
        $creneau = $data;

        $corps = $this->lecteur->corps();
        $beneficiaire = $this->resoudre(Beneficiaire::class, $corps['beneficiaire'] ?? null, 'beneficiaire');
        \assert($beneficiaire instanceof Beneficiaire);

        $rangMax = (int) $this->em->getRepository(ListeAttente::class)->createQueryBuilder('l')
            ->select('COALESCE(MAX(l.rang), 0)')
            ->andWhere('l.creneau = :creneau')
            ->setParameter('creneau', $creneau->getId(), 'uuid')
            ->getQuery()->getSingleScalarResult();

        $inscription = new ListeAttente();
        $inscription->setCreneau($creneau)
            ->setBeneficiaire($beneficiaire)
            ->setRang($rangMax + 1)
            // ACT-1 — on attend pour N unités : une table de huit ne se contente pas d'une place.
            ->setQuantity($this->quantiteDemandee->read($corps))
            ->setStatut(StatutListeAttente::EnAttente);

        $this->em->persist($inscription);
        $this->em->flush();

        return $inscription;
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
