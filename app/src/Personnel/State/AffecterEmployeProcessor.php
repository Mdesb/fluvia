<?php

declare(strict_types=1);

namespace App\Personnel\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Personnel\Entity\AffectationTravail;
use App\Personnel\Entity\CreneauTravail;
use App\Personnel\Entity\Employe;
use App\Personnel\Entity\Qualification;
use App\Personnel\Enum\StatutAffectationTravail;
use App\Personnel\Enum\StatutCreneauTravail;
use App\Personnel\Service\AbsenceConflitGuard;
use App\Personnel\Service\ChevauchementTravailGuard;
use App\Personnel\Service\RecalculFenetreBadgeHandler;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Affecte un Employé à un CreneauTravail (POST /personnel/affectations, RG-PERSO-04, CA-4/5/7) :
 * bloque le conflit de chevauchement (même établissement ou établissement différent), refuse en
 * l'absence d'une Qualification valide si le créneau l'exige, refuse si l'employé est en Absence
 * validée sur la période (CA-7).
 *
 * @implements ProcessorInterface<mixed, AffectationTravail>
 */
final class AffecterEmployeProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly ChevauchementTravailGuard $chevauchementGuard,
        private readonly AbsenceConflitGuard $absenceGuard,
        private readonly RecalculFenetreBadgeHandler $recalculFenetre,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): AffectationTravail
    {
        $corps = $this->lecteur->corps();

        $creneau = $this->resoudre(CreneauTravail::class, $corps['creneauTravail'] ?? null, 'creneauTravail');
        \assert($creneau instanceof CreneauTravail);
        $employe = $this->resoudre(Employe::class, $corps['employe'] ?? null, 'employe');
        \assert($employe instanceof Employe);

        if ($creneau->getStatut() === StatutCreneauTravail::Annule) {
            throw new ConflictHttpException('Ce créneau est annulé.');
        }

        $debut = $creneau->getDebut();
        $fin = $creneau->getFin();
        if ($debut === null || $fin === null) {
            throw new UnprocessableEntityHttpException('Créneau de travail sans fenêtre horaire.');
        }

        // CA-4 — conflit de chevauchement (RG-PERSO-04), y compris entre établissements différents.
        if ($this->chevauchementGuard->enConflit($employe, $debut, $fin)) {
            throw new ConflictHttpException('Conflit de planning : l\'employé est déjà affecté à un créneau chevauchant, y compris sur un autre établissement (RG-PERSO-04).');
        }

        // CA-7 — absence validée bloquant l'affectation (RG-PERSO-05).
        if ($this->absenceGuard->estAbsentValide($employe, $debut, $fin)) {
            throw new ConflictHttpException('L\'employé est en absence validée sur cette période (RG-PERSO-05, CA-7).');
        }

        // CA-5 — qualification exigée par le créneau : refus si aucune Qualification valide.
        $qualificationUtilisee = null;
        $qualificationRequise = $creneau->getQualificationRequise();
        if ($qualificationRequise !== null) {
            $qualificationUtilisee = $this->qualificationValide($employe, $qualificationRequise, $debut);
            if ($qualificationUtilisee === null) {
                throw new UnprocessableEntityHttpException('L\'employé ne détient aucune qualification valide requise par ce créneau (CA-5).');
            }
        }

        $affectation = new AffectationTravail();
        $affectation->setCreneauTravail($creneau)
            ->setEmploye($employe)
            ->setQualificationUtilisee($qualificationUtilisee)
            ->setStatut(StatutAffectationTravail::Planifiee);

        $this->em->persist($affectation);
        $this->em->flush();

        $this->recalculerBadgesEmploye($employe);

        return $affectation;
    }

    private function qualificationValide(Employe $employe, \App\Personnel\Enum\TypeQualification $type, \DateTimeImmutable $date): ?Qualification
    {
        /** @var list<Qualification> $qualifications */
        $qualifications = $this->em->getRepository(Qualification::class)->findBy(['employe' => $employe, 'type' => $type]);
        foreach ($qualifications as $qualification) {
            if ($qualification->estValideA($date)) {
                return $qualification;
            }
        }

        return null;
    }

    private function recalculerBadgesEmploye(Employe $employe): void
    {
        $badges = $this->em->getRepository(\App\Personnel\Entity\BadgeStaff::class)->findBy(['employe' => $employe]);
        foreach ($badges as $badge) {
            $this->recalculFenetre->recalculer($badge);
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
