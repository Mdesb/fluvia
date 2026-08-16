<?php

declare(strict_types=1);

namespace App\Musee\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Musee\Entity\PartenaireOTA;
use App\Musee\Entity\ReservationOTA;
use App\Musee\Entity\Reversement;
use App\Musee\Enum\StatutReservationOTA;
use App\Musee\Port\ConnecteurOtaInterface;
use App\Securite\Service\ContexteEtablissement;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * POST /musee/reversements/generer (RG-MUS-04, CA-7) : calcule le montant (tarif net + commission)
 * sur les `ReservationOTA.statutOta=confirmee` de la période. Corps : { "partenaire": iri|uuid,
 * "periodeDebut": date, "periodeFin": date }.
 *
 * @implements ProcessorInterface<mixed, Reversement>
 */
final class CreerReversementProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ContexteEtablissement $contexte,
        private readonly LecteurCorps $lecteur,
        private readonly ConnecteurOtaInterface $connecteur,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Reversement
    {
        $corps = $this->lecteur->corps();

        $etablissement = $this->contexte->etablissementActif();
        if ($etablissement === null) {
            throw new UnprocessableEntityHttpException('Établissement actif requis (en-tête X-Etablissement).');
        }

        $partenaire = $this->resoudre(PartenaireOTA::class, $corps['partenaire'] ?? null, 'partenaire');
        \assert($partenaire instanceof PartenaireOTA);

        $periodeDebut = $this->date($corps['periodeDebut'] ?? null, 'periodeDebut');
        $periodeFin = $this->date($corps['periodeFin'] ?? null, 'periodeFin');

        /** @var list<ReservationOTA> $ventes */
        $ventes = $this->em->getRepository(ReservationOTA::class)->createQueryBuilder('r')
            ->innerJoin('r.allocation', 'a')
            ->andWhere('a.partenaire = :partenaire')
            ->andWhere('r.statutOta = :confirmee')
            ->andWhere('r.horodatageConfirmation >= :debut')
            ->andWhere('r.horodatageConfirmation < :fin')
            ->setParameter('partenaire', $partenaire->getId(), 'uuid')
            ->setParameter('confirmee', StatutReservationOTA::Confirmee->value)
            ->setParameter('debut', $periodeDebut, 'datetime_immutable')
            ->setParameter('fin', $periodeFin->modify('+1 day'), 'datetime_immutable')
            ->getQuery()->getResult();

        $montant = number_format(\count($ventes) * (float) $partenaire->montantParVente(), 2, '.', '');

        $reversement = new Reversement();
        $reversement->setPartenaire($partenaire)
            ->setPeriodeDebut($periodeDebut)
            ->setPeriodeFin($periodeFin)
            ->setMontant($montant)
            ->setEtablissement($etablissement);
        $this->em->persist($reversement);
        $this->em->flush();

        $this->connecteur->notifierReversement($reversement);

        return $reversement;
    }

    private function date(mixed $valeur, string $champ): \DateTimeImmutable
    {
        if (!\is_string($valeur) || $valeur === '') {
            throw new UnprocessableEntityHttpException(sprintf('Champ « %s » obligatoire (date).', $champ));
        }
        try {
            return new \DateTimeImmutable($valeur);
        } catch (\Exception) {
            throw new UnprocessableEntityHttpException(sprintf('Champ « %s » invalide.', $champ));
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
