<?php

declare(strict_types=1);

namespace App\Personnel\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Personnel\Entity\Absence;
use App\Personnel\Entity\Employe;
use App\Personnel\Enum\StatutAbsence;
use App\Personnel\Enum\TypeAbsence;
use App\Personnel\Security\EmployeSoiVoter;
use App\Securite\Entity\Utilisateur;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Déclare une Absence (POST /personnel/absences, RG-PERSO-05) : statut forcé à `declaree` quel que
 * soit l'appelant. Un employé sans permission `personnel.gerer_planning` ne peut déclarer que sa
 * propre absence (`personnel.declarer_absence_soi` + `EmployeSoiVoter`).
 *
 * @implements ProcessorInterface<mixed, Absence>
 */
final class DeclarerAbsenceProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Absence
    {
        $corps = $this->lecteur->corps();

        $employe = $this->resoudre(Employe::class, $corps['employe'] ?? null, 'employe');
        \assert($employe instanceof Employe);

        if (!$this->security->isGranted('PERM', 'personnel.gerer_planning') && !$this->security->isGranted(EmployeSoiVoter::ATTRIBUTE, $employe)) {
            throw new UnprocessableEntityHttpException('Un employé ne peut déclarer qu\'une absence pour lui-même (personnel.declarer_absence_soi).');
        }

        $debut = $this->dateTime($corps['debut'] ?? null, 'debut');
        $fin = $this->dateTime($corps['fin'] ?? null, 'fin');
        if ($debut >= $fin) {
            throw new UnprocessableEntityHttpException('Le début de l\'absence doit être antérieur à la fin.');
        }

        $type = TypeAbsence::tryFrom((string) ($corps['type'] ?? ''));
        if ($type === null) {
            throw new UnprocessableEntityHttpException('Type d\'absence invalide ou manquant.');
        }

        $absence = new Absence();
        $absence->setEmploye($employe)
            ->setDebut($debut)
            ->setFin($fin)
            ->setType($type)
            ->setStatut(StatutAbsence::Declaree)
            ->setMotif(isset($corps['motif']) && \is_string($corps['motif']) ? $corps['motif'] : null);

        $this->em->persist($absence);
        $this->em->flush();

        return $absence;
    }

    private function dateTime(mixed $valeur, string $champ): \DateTimeImmutable
    {
        if (!\is_string($valeur) || $valeur === '') {
            throw new UnprocessableEntityHttpException(sprintf('Champ « %s » obligatoire (datetime ISO-8601).', $champ));
        }
        try {
            return new \DateTimeImmutable($valeur);
        } catch (\Exception) {
            throw new UnprocessableEntityHttpException(sprintf('Champ « %s » invalide (datetime ISO-8601 attendu).', $champ));
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
