<?php

declare(strict_types=1);

namespace App\Reservation\Calendar;

use App\Calendar\Port\CalendarSourceInterface;
use App\Organisation\Entity\Etablissement;
use App\Reservation\Entity\Creneau;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;

/**
 * CE QUE RÉSERVATION PUBLIE DANS L'AGENDA : ses créneaux.
 *
 * ── POURQUOI CE FICHIER EST ICI ET NON DANS L'AGENDA ────────────────────────────────────────────
 *
 * L'agenda lisait `reservation_creneau` en SQL brut. Le module qui CONNAÎT la forme de ses créneaux
 * est celui-ci ; c'est donc lui qui les publie. Le jour où une colonne change, elle change à côté
 * de la requête qui la lit — et non trois modules plus loin, dans une chaîne de caractères
 * qu'aucun outil ne relit.
 *
 * ── PORTÉE `site` UNIQUEMENT, ET C'EST UNE DÉCISION ─────────────────────────────────────────────
 *
 * Un créneau de réservation appartient au SITE. Il n'a rien à faire dans « Moi » : un exploitant
 * qui ouvre son agenda personnel y cherche ce qu'il doit faire, pas les quarante cours de la
 * semaine. Rendre une liste vide pour `mine` est la bonne réponse, pas une lacune.
 *
 * ── DQL ET NON SQL ──────────────────────────────────────────────────────────────────────────────
 *
 * La requête passe par le mapping : un champ renommé fait échouer au démarrage avec un message qui
 * le nomme, au lieu de rendre zéro ligne à l'exécution.
 */
final readonly class BookingSlotsCalendarSource implements CalendarSourceInterface
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
        if ($scope !== 'site') {
            return [];
        }

        /** @var list<Creneau> $creneaux */
        $creneaux = $this->em->createQueryBuilder()
            ->select('c', 'a', 'r')
            ->from(Creneau::class, 'c')
            ->leftJoin('c.activite', 'a')
            ->leftJoin('c.ressource', 'r')
            ->andWhere('IDENTITY(c.etablissement) = :etab')
            ->andWhere('c.debut < :au AND c.fin > :du')
            // Type `'uuid'` explicite (D58) : sans lui la requête rend zéro ligne sans lever, et
            // l'agenda du site paraîtrait vide sur un site qui affiche quarante cours.
            ->setParameter('etab', $etablissement->getId(), 'uuid')
            ->setParameter('du', $du)
            ->setParameter('au', $au)
            ->orderBy('c.debut', 'ASC')
            ->getQuery()
            ->getResult();

        $lignes = [];
        foreach ($creneaux as $creneau) {
            $activite = $creneau->getActivite()?->getLibelle();
            $ressource = $creneau->getRessource()?->getLibelle();
            $titre = trim((string) $activite) ?: 'Créneau';

            $lignes[] = [
                'id' => 'booking-' . $creneau->getId(),
                'source' => 'reservation',
                'title' => $ressource === null || trim($ressource) === '' ? $titre : $titre . ' · ' . trim($ressource),
                'start' => $creneau->getDebut()->format(\DateTimeInterface::ATOM),
                'end' => $creneau->getFin()->format(\DateTimeInterface::ATOM),
                'allDay' => false,
                'type' => 'reservation',
                'scope' => 'site',
                'detail' => sprintf('Capacité %d · %s', $creneau->getCapacite(), $creneau->getStatut()->value),
            ];
        }

        return $lignes;
    }
}
