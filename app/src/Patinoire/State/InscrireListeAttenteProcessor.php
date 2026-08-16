<?php

declare(strict_types=1);

namespace App\Patinoire\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Crm\Entity\Beneficiaire;
use App\Patinoire\Entity\ListeAttentePointure;
use App\Patinoire\Entity\ParcPatins;
use App\Patinoire\Service\ProposeurPointureVoisineHandler;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Inscription en liste d'attente pointure (POST /patinoire/liste-attente, US-PATIN-05, CA-5).
 * Corps : { "parcPatins": iri|uuid, "beneficiaire": iri|uuid }. Calcule et mémorise la pointure
 * voisine éventuellement disponible à l'inscription (`ProposeurPointureVoisineHandler`).
 *
 * @implements ProcessorInterface<mixed, ListeAttentePointure>
 */
final class InscrireListeAttenteProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly ProposeurPointureVoisineHandler $proposeur,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ListeAttentePointure
    {
        $corps = $this->lecteur->corps();

        $parcPatins = $this->resoudre(ParcPatins::class, $corps['parcPatins'] ?? null, 'parcPatins');
        \assert($parcPatins instanceof ParcPatins);
        $beneficiaire = $this->resoudre(Beneficiaire::class, $corps['beneficiaire'] ?? null, 'beneficiaire');
        \assert($beneficiaire instanceof Beneficiaire);

        $dernierRang = (int) ($this->em->getRepository(ListeAttentePointure::class)->createQueryBuilder('l')
            ->select('MAX(l.rang)')
            ->andWhere('l.parcPatins = :parc')
            ->setParameter('parc', $parcPatins->getId(), 'uuid')
            ->getQuery()->getSingleScalarResult() ?? 0);

        $inscription = new ListeAttentePointure();
        $inscription->setParcPatins($parcPatins)
            ->setBeneficiaire($beneficiaire)
            ->setRang($dernierRang + 1)
            ->setPointureVoisineProposee($this->proposeur->proposer($parcPatins));
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
