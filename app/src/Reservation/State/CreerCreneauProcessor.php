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
 * (RG-M5-07). Bloque tout chevauchement de ressource à la création (RG-M5-03, CA-2).
 *
 * ── ⚠ UNE OCCURRENCE EN CONFLIT EST CRÉÉE EN ATTENTE D'ARBITRAGE, PLUS JAMAIS PERDUE ──────────
 *
 * Cette méthode écrivait `continue` sur une occurrence en conflit. Un cours hebdomadaire de douze
 * séances dont trois tombent sur un court déjà pris en créait **neuf**, l'API rendait 200, et rien
 * ne disait que trois manquaient. L'exploitant l'apprenait quand un client ne pouvait pas réserver
 * une date — ou ne l'apprenait pas.
 *
 * Tout ce qu'il fallait pour faire mieux existait déjà, et **rien n'y menait** :
 * `Creneau::$enAttenteArbitrage` n'avait aucun appelant en production, `RecurrenceReportHandler`
 * était appelé par quatre tests et zéro code de production, et
 * `ArbitrerConflitRecurrenceProcessor` résolvait un état que rien ne produisait.
 *
 * **Décision de Maxime du 31/08, entre quatre options : « ne jamais déplacer tout seul ».**
 * L'occurrence est donc créée, marquée `enAttenteArbitrage`, et **non réservable** — voir
 * `ReserverProcessor`, qui la refuse. Un humain tranche depuis l'écran : une autre ressource, ou
 * la confirmation telle quelle.
 *
 * ⚠ `RecurrenceReportHandler` reste volontairement débranché : il implémente le report
 * **automatique** (`RegleConflitRecurrence::ReportAuto`, valeur par défaut du champ), que la
 * décision écarte. Le laisser inerte en le disant vaut mieux que le brancher contre la décision, ou
 * que le supprimer — l'arbitrage manuel réutilisera sa recherche de ressource équivalente le jour
 * où l'écran proposera des candidats.
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
                $suivant = $this->construireCreneau($ressource, $activite, $occurrence['debut'], $occurrence['fin'], $capacite, $corps, $recurrence);

                // ⚠ CRÉÉE MALGRÉ LE CONFLIT, ET MARQUÉE. Elle existe donc, elle se voit, et elle ne
                // se réserve pas — `ReserverProcessor` la refuse. Une séance perdue en silence est
                // pire qu'une séance visiblement en attente : la première ne se rattrape jamais.
                //
                // On ne cherche PAS de ressource de remplacement : décision de Maxime du 31/08,
                // « ne jamais déplacer tout seul ». Un cours qui change de court sans que personne
                // ne l'ait validé est un problème invisible à la place d'un problème constaté.
                if ($this->guard->enConflit($ressource, $occurrence['debut'], $occurrence['fin'])) {
                    $suivant->setEnAttenteArbitrage(true);
                }

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
