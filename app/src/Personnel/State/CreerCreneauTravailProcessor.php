<?php

declare(strict_types=1);

namespace App\Personnel\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Organisation\Entity\Espace;
use App\Organisation\Entity\Etablissement;
use App\Personnel\Entity\CreneauTravail;
use App\Personnel\Enum\MotifRecurrenceTravail;
use App\Personnel\Enum\StatutCreneauTravail;
use App\Personnel\Enum\TypeQualification;
use App\Personnel\Security\EtablissementCibleVerificateur;
use App\Securite\Entity\Utilisateur;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Crée un CreneauTravail (POST /personnel/creneaux-travail, RG-PERSO-03) et, le cas échéant, expanse
 * une récurrence hebdomadaire (généralisation RG-M5-07, patron
 * `App\Reservation\State\CreerCreneauProcessor`). Les occurrences en conflit ne sont pas contrôlées
 * ici (le conflit se détecte à l'affectation d'un employé, RG-PERSO-04).
 *
 * L'`etablissement` du corps est un UUID/IRI brut fourni par le client : recoupé contre
 * l'établissement actif ou les droits directs de l'agent sur cette cible
 * (`EtablissementCibleVerificateur`), sinon 403 (RG-SOCLE-05).
 *
 * @implements ProcessorInterface<mixed, CreneauTravail>
 */
final class CreerCreneauTravailProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly ValidatorInterface $validator,
        private readonly Security $security,
        private readonly EtablissementCibleVerificateur $verificateurCible,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CreneauTravail
    {
        $agent = $this->security->getUser();
        if (!$agent instanceof Utilisateur) {
            throw new UnprocessableEntityHttpException('Agent authentifié requis.');
        }

        $corps = $this->lecteur->corps();

        $etablissement = $this->resoudre(Etablissement::class, $corps['etablissement'] ?? null, 'etablissement');
        \assert($etablissement instanceof Etablissement);

        if (!$this->verificateurCible->autorise($etablissement, $agent, 'personnel', 'gerer_planning')) {
            throw new AccessDeniedHttpException('L\'établissement ciblé ne correspond pas à l\'établissement actif et l\'agent ne détient pas personnel.gerer_planning sur cet établissement (RG-SOCLE-05).');
        }

        $espace = null;
        if (isset($corps['espace'])) {
            $espace = $this->resoudre(Espace::class, $corps['espace'], 'espace');
            \assert($espace instanceof Espace);
        }

        $debut = $this->dateTime($corps['debut'] ?? null, 'debut');
        $fin = $this->dateTime($corps['fin'] ?? null, 'fin');

        $qualificationRequise = null;
        if (isset($corps['qualificationRequise']) && $corps['qualificationRequise'] !== null) {
            $qualificationRequise = TypeQualification::tryFrom((string) $corps['qualificationRequise']);
        }

        $premier = $this->construire($etablissement, $espace, $debut, $fin, $corps, $qualificationRequise);
        $this->valider($premier);
        $this->em->persist($premier);

        if (isset($corps['recurrence']) && \is_array($corps['recurrence'])) {
            $motif = MotifRecurrenceTravail::tryFrom((string) ($corps['recurrence']['motif'] ?? '')) ?? MotifRecurrenceTravail::Hebdomadaire;
            $finRecurrence = $this->dateTime($corps['recurrence']['finRecurrence'] ?? null, 'recurrence.finRecurrence', dateSeule: true);
            $premier->setMotifRecurrence($motif)->setFinRecurrence($finRecurrence);

            foreach ($this->genererOccurrences($debut, $fin, $finRecurrence) as $occurrence) {
                $suivant = $this->construire($etablissement, $espace, $occurrence['debut'], $occurrence['fin'], $corps, $qualificationRequise);
                $suivant->setMotifRecurrence($motif)->setFinRecurrence($finRecurrence);
                $this->valider($suivant);
                $this->em->persist($suivant);
            }
        }

        $this->em->flush();

        return $premier;
    }

    private function construire(Etablissement $etablissement, ?Espace $espace, \DateTimeImmutable $debut, \DateTimeImmutable $fin, array $corps, ?TypeQualification $qualificationRequise): CreneauTravail
    {
        $creneau = new CreneauTravail();
        $creneau->setEtablissement($etablissement)
            ->setEspace($espace)
            ->setDebut($debut)
            ->setFin($fin)
            ->setLibellePoste((string) ($corps['libellePoste'] ?? ''))
            ->setQualificationRequise($qualificationRequise)
            ->setEffectifRequis(isset($corps['effectifRequis']) ? (int) $corps['effectifRequis'] : 1)
            ->setStatut(StatutCreneauTravail::Planifie);

        if (isset($corps['creneauReservationRef']) && \is_string($corps['creneauReservationRef']) && Uuid::isValid($corps['creneauReservationRef'])) {
            $creneau->setCreneauReservationRef(Uuid::fromString($corps['creneauReservationRef']));
        }

        return $creneau;
    }

    private function valider(CreneauTravail $creneau): void
    {
        $violations = $this->validator->validate($creneau);
        if (\count($violations) > 0) {
            throw new UnprocessableEntityHttpException((string) $violations);
        }
    }

    /**
     * @return list<array{debut: \DateTimeImmutable, fin: \DateTimeImmutable}>
     */
    private function genererOccurrences(\DateTimeImmutable $debut, \DateTimeImmutable $fin, \DateTimeImmutable $finRecurrence): array
    {
        $duree = $debut->diff($fin);
        $occurrences = [];
        $courant = $debut->modify('+1 week');
        while ($courant <= $finRecurrence->setTime(23, 59, 59)) {
            $occurrences[] = ['debut' => $courant, 'fin' => $courant->add($duree)];
            $courant = $courant->modify('+1 week');
        }

        return $occurrences;
    }

    private function dateTime(mixed $valeur, string $champ, bool $dateSeule = false): \DateTimeImmutable
    {
        if (!\is_string($valeur) || $valeur === '') {
            throw new UnprocessableEntityHttpException(sprintf('Champ « %s » obligatoire (date/datetime ISO-8601).', $champ));
        }
        try {
            return new \DateTimeImmutable($valeur);
        } catch (\Exception) {
            throw new UnprocessableEntityHttpException(sprintf('Champ « %s » invalide (date/datetime ISO-8601 attendu).', $champ));
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
