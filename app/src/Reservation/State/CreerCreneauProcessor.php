<?php

declare(strict_types=1);

namespace App\Reservation\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Reservation\Entity\Activite;
use App\Reservation\Entity\Creneau;
use App\Reservation\Entity\Recurrence;
use App\Reservation\Entity\Ressource;
use App\Reservation\Enum\MotifRecurrence;
use App\Reservation\Enum\RegleConflitRecurrence;
use App\Reservation\Enum\StatutCreneau;
use App\Reservation\Service\ChevauchementCreneauGuard;
use App\Reservation\Service\RecurrenceExpansionHandler;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Crée un Créneau (POST /reservation/creneaux) et, le cas échéant, expanse une récurrence
 * (RG-M5-07). Bloque tout chevauchement de ressource à la création (RG-M5-03, CA-2). Les occurrences
 * de récurrence en conflit sont simplement ignorées à la création initiale (le report/l'arbitrage
 * RG-M5-11 s'applique aux conflits détectés *après coup*, via `RecurrenceReportHandler`).
 *
 * @implements ProcessorInterface<mixed, Creneau>
 */
final class CreerCreneauProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly ChevauchementCreneauGuard $guard,
        private readonly RecurrenceExpansionHandler $expansion,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Creneau
    {
        $corps = $this->lecteur->corps();

        $ressource = $this->resoudre(Ressource::class, $corps['ressource'] ?? null, 'ressource');
        \assert($ressource instanceof Ressource);

        $activite = null;
        if (isset($corps['activite'])) {
            $activite = $this->resoudre(Activite::class, $corps['activite'], 'activite');
            \assert($activite instanceof Activite);
        }

        $debut = $this->dateTime($corps['debut'] ?? null, 'debut');
        $fin = $this->dateTime($corps['fin'] ?? null, 'fin');
        if ($debut >= $fin) {
            throw new UnprocessableEntityHttpException('Le début du créneau doit être antérieur à la fin.');
        }

        $capacite = isset($corps['capacite']) ? (int) $corps['capacite'] : $ressource->getCapacitePropre();
        if ($capacite < 1) {
            throw new UnprocessableEntityHttpException('La capacité du créneau doit être ≥ 1 (cahier M5-02).');
        }

        if ($this->guard->enConflit($ressource, $debut, $fin)) {
            throw new ConflictHttpException('Conflit de ressource : un autre créneau chevauche cette fenêtre sur la même ressource (RG-M5-03).');
        }

        $recurrence = null;
        if (isset($corps['recurrence']) && \is_array($corps['recurrence'])) {
            $recurrence = $this->construireRecurrence($corps['recurrence'], $ressource);
        }

        $premier = $this->construireCreneau($ressource, $activite, $debut, $fin, $capacite, $corps, $recurrence);
        $this->em->persist($premier);

        if ($recurrence !== null) {
            $occurrences = $this->expansion->genererOccurrences($debut, $fin, $recurrence);
            foreach ($occurrences as $index => $occurrence) {
                if ($index === 0) {
                    continue; // déjà créé ci-dessus.
                }
                if ($this->guard->enConflit($ressource, $occurrence['debut'], $occurrence['fin'])) {
                    continue; // occurrence en conflit : ignorée à la création initiale.
                }
                $suivant = $this->construireCreneau($ressource, $activite, $occurrence['debut'], $occurrence['fin'], $capacite, $corps, $recurrence);
                $this->em->persist($suivant);
            }
        }

        $this->em->flush();

        return $premier;
    }

    private function construireCreneau(Ressource $ressource, ?Activite $activite, \DateTimeImmutable $debut, \DateTimeImmutable $fin, int $capacite, array $corps, ?Recurrence $recurrence): Creneau
    {
        $creneau = new Creneau();
        $creneau->setRessource($ressource)
            ->setActivite($activite)
            ->setDebut($debut)
            ->setFin($fin)
            ->setCapacite($capacite)
            ->setEtablissement($ressource->getEtablissement())
            ->setStatut(StatutCreneau::Planifie);
        if (isset($corps['publicReserve']) && \is_string($corps['publicReserve'])) {
            $creneau->setPublicReserve($corps['publicReserve']);
        }
        if ($recurrence !== null) {
            $creneau->setRecurrence($recurrence);
        }

        return $creneau;
    }

    /**
     * @param array<string, mixed> $donnees
     */
    private function construireRecurrence(array $donnees, Ressource $ressource): Recurrence
    {
        $motif = MotifRecurrence::tryFrom((string) ($donnees['motif'] ?? '')) ?? MotifRecurrence::Hebdomadaire;
        $recurrence = new Recurrence();
        $recurrence->setEtablissement($ressource->getEtablissement())
            ->setMotif($motif)
            ->setFinRecurrence($this->dateTime($donnees['finRecurrence'] ?? null, 'recurrence.finRecurrence'));
        if (isset($donnees['joursSemaine']) && \is_array($donnees['joursSemaine'])) {
            $recurrence->setJoursSemaine(array_map('intval', $donnees['joursSemaine']));
        }
        if (isset($donnees['regleConflit'])) {
            $regle = RegleConflitRecurrence::tryFrom((string) $donnees['regleConflit']);
            if ($regle !== null) {
                $recurrence->setRegleConflit($regle);
            }
        }
        $this->em->persist($recurrence);

        return $recurrence;
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
