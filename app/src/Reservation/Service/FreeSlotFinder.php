<?php

declare(strict_types=1);

namespace App\Reservation\Service;

use App\Reservation\Entity\Activite;
use App\Reservation\Entity\Creneau;
use App\Reservation\Entity\DisponibiliteRessource;
use App\Reservation\Entity\IndisponibiliteRessource;
use App\Reservation\Entity\Ressource;
use App\Reservation\Enum\StatutCreneau;
use Doctrine\ORM\EntityManagerInterface;

/**
 * LES DÉBUTS POSSIBLES POUR UNE PRESTATION, UN JOUR DONNÉ.
 *
 * **Le second modèle de prise de rendez-vous, celui qui manquait.** Le module savait réserver *un
 * créneau qui existe déjà* — juste pour une séance de piscine ou un cours collectif, où la séance de
 * 14 h existe indépendamment de qui la réserve. Il ne savait pas **poser un rendez-vous là où il
 * tient** : c'est pourtant le seul modèle possible pour un coiffeur ou un masseur, où rien n'existe
 * avant que le client n'appelle.
 *
 * Maxime, le 27/08 : *« je pense qu'il faut les deux pour le calendrier »*. Les deux, donc — et sans
 * dupliquer quoi que ce soit : **ce service ne réserve rien.** Il rend des propositions ; la
 * réservation reste la création d'un `Creneau` puis d'une `Reservation`, protégée par
 * `ChevauchementCreneauGuard`. Tout ce qui suit — projection d'accès, règle d'annulation, facturation
 * des non-présentations — continue de fonctionner sans savoir que le créneau est né d'un placement
 * libre plutôt que d'une grille.
 *
 * > **Un second modèle de réservation qui recréerait sa propre chaîne de conséquences serait un second
 * > produit.**
 *
 * ---
 *
 * **LE BATTEMENT N'EST PAS ÉCRIT DANS LE CRÉNEAU, ET C'EST DÉLIBÉRÉ.**
 *
 * Nettoyer une cabine de massage prend quinze minutes ; remettre un fauteuil en état, deux. Ce temps
 * appartient à la prestation (`Activite::battementMinutes`), pas au rendez-vous.
 *
 * On aurait pu allonger le créneau d'autant. Ce serait faux sur toute la ligne : le client verrait un
 * rendez-vous d'1 h 15 pour un soin d'une heure, le ticket porterait la mauvaise durée, et le jour où
 * l'exploitant réduit son battement, **tous les rendez-vous passés mentiraient rétroactivement**.
 *
 * Le créneau dit donc quand le rendez-vous finit. Le battement est une **règle de placement** : au
 * calcul, chaque occupation existante est élargie du battement de sa propre prestation.
 *
 * ---
 *
 * **QUI PEUT FAIRE QUOI : deux champs qui existaient sans jamais servir.**
 *
 * `Activite::competenceExigee` et `Ressource::competenceRequise` sont déclarés depuis le début et
 * **utilisés nulle part** — vérifié le 27/08, aucune occurrence hors des entités. Leur sens n'était
 * donc écrit nulle part non plus.
 *
 * Ce service les lie explicitement, pour la première fois : **une ressource peut servir une activité
 * si l'activité n'exige aucune compétence, ou si la ressource porte la même.** C'est l'interprétation
 * la plus simple qui rende les deux champs utiles ensemble.
 *
 * ⚠ `competenceRequise` est **mal nommé** pour cet emploi — sur un praticien il désigne ce qu'il
 * *détient*, pas ce qu'il *exige*. Renommer une colonne utilisée par une API publique n'est pas une
 * correction gratuite ; c'est noté pour un retrofit, pas fait au passage.
 */
final class FreeSlotFinder
{
    /** Pas de proposition par défaut : un quart d'heure. */
    public const DEFAULT_STEP_MINUTES = 15;

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Les ressources capables de servir cette activité, sur son établissement.
     *
     * @return list<Ressource>
     */
    public function eligibleResources(Activite $activity): array
    {
        $qb = $this->em->getRepository(Ressource::class)->createQueryBuilder('r')
            ->andWhere('IDENTITY(r.etablissement) = :etablissement')
            // Type `uuid` explicite : sur un identifiant à type personnalisé, une comparaison sans
            // type ne compte rien **et ne lève pas** (D58). Ici elle rendrait « aucun praticien
            // disponible », ce qui ressemble à un agenda plein.
            ->setParameter('etablissement', $activity->getEtablissement()?->getId(), 'uuid');

        $competence = $activity->getCompetenceExigee();
        if ($competence !== null && trim($competence) !== '') {
            $qb->andWhere('r.competenceRequise = :competence')->setParameter('competence', trim($competence));
        }

        /** @var list<Ressource> $resources */
        $resources = $qb->getQuery()->getResult();

        return $resources;
    }

    /**
     * Les débuts possibles pour une prestation, sur une ressource, un jour donné.
     *
     * @return list<array{debut: \DateTimeImmutable, fin: \DateTimeImmutable}>
     */
    public function findStarts(
        Ressource $resource,
        Activite $activity,
        \DateTimeImmutable $day,
        ?int $stepMinutes = null,
    ): array {
        $step = max(5, $stepMinutes ?? self::DEFAULT_STEP_MINUTES);
        $duration = max(5, $activity->getDureeMinutes());

        $openRanges = $this->openRanges($resource, $day);
        if ($openRanges === []) {
            return [];
        }

        $busy = $this->busyRanges($resource, $day);

        $starts = [];
        foreach ($openRanges as [$openStart, $openEnd]) {
            $candidate = $openStart;
            while (true) {
                $end = $candidate->modify(sprintf('+%d minutes', $duration));
                if ($end > $openEnd) {
                    break;
                }

                if (!$this->intersectsAny($candidate, $end, $busy)) {
                    $starts[] = ['debut' => $candidate, 'fin' => $end];
                }

                $candidate = $candidate->modify(sprintf('+%d minutes', $step));
            }
        }

        return $starts;
    }

    /**
     * Les plages d'ouverture de la ressource ce jour-là, amputées de ses absences.
     *
     * @return list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>
     */
    private function openRanges(Ressource $resource, \DateTimeImmutable $day): array
    {
        $weekday = (int) $day->format('N');

        /** @var list<DisponibiliteRessource> $availabilities */
        $availabilities = $this->em->getRepository(DisponibiliteRessource::class)
            ->findBy(['ressource' => $resource, 'jourSemaine' => $weekday]);

        $ranges = [];
        foreach ($availabilities as $availability) {
            $start = $this->at($day, $availability->getHeureDebut());
            $end = $this->at($day, $availability->getHeureFin());
            if ($end > $start) {
                $ranges[] = [$start, $end];
            }
        }

        // UNE ABSENCE COUPE UNE PLAGE EN DEUX, elle ne la supprime pas.
        //
        // Un praticien absent de 12 h à 14 h sur une journée 9 h – 18 h reste disponible le matin et
        // l'après-midi. Traiter l'absence comme un simple filtre effacerait la journée entière, et
        // l'agenda afficherait « complet » sur un praticien qui a six heures de libre.
        foreach ($this->absences($resource, $day) as [$absenceStart, $absenceEnd]) {
            $decoupees = [];
            foreach ($ranges as [$start, $end]) {
                if ($absenceEnd <= $start || $absenceStart >= $end) {
                    $decoupees[] = [$start, $end];
                    continue;
                }
                if ($absenceStart > $start) {
                    $decoupees[] = [$start, $absenceStart];
                }
                if ($absenceEnd < $end) {
                    $decoupees[] = [$absenceEnd, $end];
                }
            }
            $ranges = $decoupees;
        }

        return $ranges;
    }

    /**
     * Ce qui occupe déjà la ressource ce jour-là, **élargi du battement de chaque prestation**.
     *
     * @return list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>
     */
    private function busyRanges(Ressource $resource, \DateTimeImmutable $day): array
    {
        $dayStart = $day->setTime(0, 0);
        $dayEnd = $dayStart->modify('+1 day');

        /** @var list<Creneau> $slots */
        $slots = $this->em->getRepository(Creneau::class)->createQueryBuilder('c')
            ->andWhere('c.ressource = :ressource')
            ->andWhere('c.statut != :annule')
            ->andWhere('c.debut < :fin')
            ->andWhere('c.fin > :debut')
            ->setParameter('ressource', $resource->getId(), 'uuid')
            ->setParameter('annule', StatutCreneau::Annule->value)
            ->setParameter('debut', $dayStart, 'datetime_immutable')
            ->setParameter('fin', $dayEnd, 'datetime_immutable')
            ->getQuery()
            ->getResult();

        $busy = [];
        foreach ($slots as $slot) {
            $buffer = $slot->getActivite()?->getBattementMinutes() ?? 0;
            $busy[] = [
                $slot->getDebut(),
                $buffer > 0 ? $slot->getFin()->modify(sprintf('+%d minutes', $buffer)) : $slot->getFin(),
            ];
        }

        return $busy;
    }

    /**
     * @return list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>
     */
    private function absences(Ressource $resource, \DateTimeImmutable $day): array
    {
        $dayStart = $day->setTime(0, 0);
        $dayEnd = $dayStart->modify('+1 day');

        /** @var list<IndisponibiliteRessource> $absences */
        $absences = $this->em->getRepository(IndisponibiliteRessource::class)->createQueryBuilder('i')
            ->andWhere('i.ressource = :ressource')
            ->andWhere('i.debut < :fin')
            ->andWhere('i.fin > :debut')
            ->setParameter('ressource', $resource->getId(), 'uuid')
            ->setParameter('debut', $dayStart, 'datetime_immutable')
            ->setParameter('fin', $dayEnd, 'datetime_immutable')
            ->getQuery()
            ->getResult();

        return array_map(
            static fn (IndisponibiliteRessource $a): array => [$a->getDebut(), $a->getFin()],
            $absences,
        );
    }

    /**
     * @param list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}> $ranges
     */
    private function intersectsAny(\DateTimeImmutable $start, \DateTimeImmutable $end, array $ranges): bool
    {
        foreach ($ranges as [$otherStart, $otherEnd]) {
            if ($start < $otherEnd && $end > $otherStart) {
                return true;
            }
        }

        return false;
    }

    /** Pose une heure de la journée sur une date, en gardant le fuseau de la date. */
    private function at(\DateTimeImmutable $day, \DateTimeImmutable $time): \DateTimeImmutable
    {
        return $day->setTime((int) $time->format('H'), (int) $time->format('i'), 0);
    }
}
