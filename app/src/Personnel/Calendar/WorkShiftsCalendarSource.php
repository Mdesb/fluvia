<?php

declare(strict_types=1);

namespace App\Personnel\Calendar;

use App\Calendar\Port\CalendarSourceInterface;
use App\Organisation\Entity\Etablissement;
use App\Personnel\Entity\AffectationTravail;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;

/**
 * CE QUE PERSONNEL PUBLIE DANS L'AGENDA : les créneaux de travail DE CELUI QUI REGARDE.
 *
 * ── PORTÉE `mine` UNIQUEMENT, ET C'EST LE CŒUR DE LA DÉCISION ───────────────────────────────────
 *
 * Le planning de l'équipe existe déjà, dans l'écran Personnel, avec ses règles et ses droits.
 * Le publier aussi dans « Le site » ferait de l'agenda une seconde porte vers la même donnée —
 * deux endroits à corriger, et un exploitant qui ne sait plus lequel fait foi. Pire : il exposerait
 * les horaires de toute l'équipe à qui a simplement le droit d'ouvrir un agenda.
 *
 * L'onglet « Moi » répond à « que dois-je faire, moi ? ». Un créneau où une affectation me nomme y
 * a sa place ; celui du collègue, non.
 *
 * ── LE CHEMIN PASSE PAR L'EMPLOYÉ, ET IL LE FAUT ────────────────────────────────────────────────
 *
 * Un employé n'a pas toujours de compte, et un compte n'est pas toujours un employé. On part donc
 * de l'affectation, on remonte à l'employé, et on ne retient que ceux dont le compte est celui qui
 * regarde. Partir du compte aurait supposé une correspondance qui n'existe pas toujours.
 */
final readonly class WorkShiftsCalendarSource implements CalendarSourceInterface
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * @return list<array{id: string, source: string, title: string, start: string, end: string, allDay: bool, type: string, scope: string, detail: string|null}>
     */
    public function occurrences(
        Etablissement $etablissement,
        Utilisateur $utilisateur,
        \DateTimeImmutable $du,
        \DateTimeImmutable $au,
        string $scope,
    ): array {
        if ($scope !== 'mine') {
            return [];
        }

        /** @var list<AffectationTravail> $affectations */
        $affectations = $this->em->createQueryBuilder()
            ->select('a', 'c')
            ->from(AffectationTravail::class, 'a')
            ->join('a.creneauTravail', 'c')
            ->join('a.employe', 'e')
            ->andWhere('IDENTITY(e.utilisateur) = :moi')
            ->andWhere('IDENTITY(c.etablissement) = :etab')
            ->andWhere('c.debut < :au AND c.fin > :du')
            // Types explicites (D58) : sans eux, la requête rend zéro ligne sans lever — et un
            // agent verrait un planning personnel vide un jour où il travaille.
            ->setParameter('moi', $utilisateur->getId(), 'uuid')
            ->setParameter('etab', $etablissement->getId(), 'uuid')
            ->setParameter('du', $du)
            ->setParameter('au', $au)
            ->orderBy('c.debut', 'ASC')
            ->getQuery()
            ->getResult();

        $lignes = [];
        foreach ($affectations as $affectation) {
            $creneau = $affectation->getCreneauTravail();
            $debut = $creneau?->getDebut();
            $fin = $creneau?->getFin();
            if ($creneau === null || $debut === null || $fin === null) {
                continue;
            }

            $lignes[] = [
                'id' => 'shift-' . $creneau->getId(),
                'source' => 'shift',
                'title' => trim($creneau->getLibellePoste()) ?: 'Créneau de travail',
                'start' => $debut->format(\DateTimeInterface::ATOM),
                'end' => $fin->format(\DateTimeInterface::ATOM),
                'allDay' => false,
                'type' => 'shift',
                'scope' => 'mine',
                'detail' => $affectation->getStatut()->value,
            ];
        }

        return $lignes;
    }
}
