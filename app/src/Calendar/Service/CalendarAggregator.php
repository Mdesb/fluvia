<?php

declare(strict_types=1);

namespace App\Calendar\Service;

use App\Calendar\Entity\CalendarEvent;
use App\Organisation\Entity\Etablissement;
use App\Opening\Service\OpeningCalendar;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;

/**
 * CE QUI SE PASSE ICI CETTE SEMAINE — et ce que MOI j'ai à faire.
 *
 * ── POURQUOI UN AGRÉGATEUR ET PAS UNE TABLE ─────────────────────────────────────────────────────
 *
 * Les créneaux de réservation, les créneaux de travail et les plages d'ouverture existent déjà,
 * chacun chez lui, chacun avec ses règles. Les recopier dans une table d'agenda créerait une
 * seconde vérité qui dériverait dès la première annulation : l'agenda montrerait un cours annulé
 * la veille, et l'exploitant croirait le logiciel plutôt que son planning.
 *
 * > **Un agenda ne possède pas ce qu'il montre.** Il le lit, à la demande, chez ceux qui le
 * > possèdent.
 *
 * Une seule chose lui appartient en propre : ce qu'on note à la main, `CalendarEvent`.
 *
 * ── LES DEUX PORTÉES ────────────────────────────────────────────────────────────────────────────
 *
 * `site` : ce qui se passe sur le site — créneaux de réservation, événements du site, ouverture et
 * fermetures. `moi` : mes créneaux de travail et mes événements personnels. Deux questions
 * distinctes, deux réponses distinctes, un seul calcul.
 *
 * ⚠ **Les événements personnels des AUTRES n'apparaissent nulle part**, pas même dans la vue site,
 * pas même pour un administrateur. Un blocage personnel dans un agenda professionnel dit parfois
 * autre chose qu'un horaire.
 */
final readonly class CalendarAggregator
{
    public function __construct(
        private EntityManagerInterface $em,
        private OpeningCalendar $calendrier,
    ) {
    }

    /**
     * @return list<array{id: string, source: string, title: string, start: string, end: string, allDay: bool, type: string, scope: string, detail: string|null}>
     */
    public function evenements(
        Etablissement $etablissement,
        Utilisateur $utilisateur,
        \DateTimeImmutable $du,
        \DateTimeImmutable $au,
        string $portee,
    ): array {
        $lignes = [];

        if ($portee === 'site') {
            $lignes = array_merge(
                $lignes,
                $this->evenementsSaisis($etablissement, $du, $au, null),
                $this->creneauxDeReservation($etablissement, $du, $au),
                $this->ouvertureEtFermetures($etablissement, $du, $au),
            );
        } else {
            $lignes = array_merge(
                $lignes,
                $this->evenementsSaisis($etablissement, $du, $au, $utilisateur),
                $this->creneauxDeTravail($etablissement, $utilisateur, $du, $au),
            );
        }

        usort($lignes, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        return array_values($lignes);
    }

    /**
     * Les événements saisis à la main. `$proprietaire === null` rend ceux DU SITE ; sinon, ceux de
     * cette personne — jamais les deux, jamais ceux d'un tiers.
     *
     * @return list<array<string, mixed>>
     */
    private function evenementsSaisis(
        Etablissement $etablissement,
        \DateTimeImmutable $du,
        \DateTimeImmutable $au,
        ?Utilisateur $proprietaire,
    ): array {
        $qb = $this->em->createQueryBuilder()
            ->select('e')
            ->from(CalendarEvent::class, 'e')
            ->andWhere('IDENTITY(e.establishment) = :etab')
            ->andWhere('e.start < :au AND e.end > :du')
            ->setParameter('etab', $etablissement->getId(), 'uuid')
            ->setParameter('du', $du)
            ->setParameter('au', $au);

        if ($proprietaire === null) {
            $qb->andWhere('e.owner IS NULL');
        } else {
            $qb->andWhere('IDENTITY(e.owner) = :moi')
                ->setParameter('moi', $proprietaire->getId(), 'uuid');
        }

        /** @var list<CalendarEvent> $evenements */
        $evenements = $qb->getQuery()->getResult();

        return array_map(static fn (CalendarEvent $e): array => [
            'id' => (string) $e->getId(),
            'source' => 'event',
            'title' => $e->getTitle(),
            'start' => ($e->getStart() ?? new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'end' => ($e->getEnd() ?? new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'allDay' => $e->isAllDay(),
            'type' => $e->getType()->value,
            'scope' => $e->isSiteWide() ? 'site' : 'mine',
            'detail' => $e->getNotes(),
        ], $evenements);
    }

    /**
     * Les créneaux de réservation du site. Lecture DIRECTE de la table plutôt que passage par
     * l'API du module : on ne veut ni sa pagination, ni sa sérialisation, ni ses filtres — on veut
     * quatre colonnes sur un intervalle.
     *
     * @return list<array<string, mixed>>
     */
    private function creneauxDeReservation(Etablissement $etablissement, \DateTimeImmutable $du, \DateTimeImmutable $au): array
    {
        $lignes = $this->em->getConnection()->fetchAllAssociative(
            'SELECT c.id, c.debut, c.fin, c.capacite, a.libelle AS activite, r.libelle AS ressource, c.statut
             FROM reservation_creneau c
             LEFT JOIN reservation_activite a ON a.id = c.activite_id
             LEFT JOIN reservation_ressource r ON r.id = c.ressource_id
             WHERE c.etablissement_id = UNHEX(:etab) AND c.debut < :au AND c.fin > :du
             ORDER BY c.debut',
            [
                'etab' => bin2hex($etablissement->getId()->toBinary()),
                'du' => $du->format('Y-m-d H:i:s'),
                'au' => $au->format('Y-m-d H:i:s'),
            ],
        );

        return array_map(static function (array $l): array {
            $titre = trim((string) ($l['activite'] ?? '')) ?: 'Créneau';
            $ressource = trim((string) ($l['ressource'] ?? ''));

            return [
                'id' => 'creneau-' . bin2hex((string) $l['id']),
                'source' => 'reservation',
                'title' => $ressource === '' ? $titre : $titre . ' · ' . $ressource,
                'start' => (new \DateTimeImmutable((string) $l['debut']))->format(\DateTimeInterface::ATOM),
                'end' => (new \DateTimeImmutable((string) $l['fin']))->format(\DateTimeInterface::ATOM),
                'allDay' => false,
                'type' => 'reservation',
                'scope' => 'site',
                'detail' => sprintf('Capacité %d · %s', (int) $l['capacite'], (string) $l['statut']),
            ];
        }, $lignes);
    }

    /**
     * MES créneaux de travail : ceux où une affectation me nomme. Passe par `personnel_employe`,
     * qui porte le lien vers le compte — un employé n'a pas toujours de compte, et un compte n'est
     * pas toujours un employé.
     *
     * @return list<array<string, mixed>>
     */
    private function creneauxDeTravail(
        Etablissement $etablissement,
        Utilisateur $utilisateur,
        \DateTimeImmutable $du,
        \DateTimeImmutable $au,
    ): array {
        $lignes = $this->em->getConnection()->fetchAllAssociative(
            'SELECT ct.id, ct.debut, ct.fin, ct.libelle_poste, ct.statut
             FROM personnel_affectation_travail at
             JOIN personnel_creneau_travail ct ON ct.id = at.creneau_travail_id
             JOIN personnel_employe e ON e.id = at.employe_id
             WHERE e.utilisateur_id = UNHEX(:moi)
               AND ct.etablissement_id = UNHEX(:etab)
               AND ct.debut < :au AND ct.fin > :du
             ORDER BY ct.debut',
            [
                'moi' => bin2hex($utilisateur->getId()->toBinary()),
                'etab' => bin2hex($etablissement->getId()->toBinary()),
                'du' => $du->format('Y-m-d H:i:s'),
                'au' => $au->format('Y-m-d H:i:s'),
            ],
        );

        return array_map(static fn (array $l): array => [
            'id' => 'shift-' . bin2hex((string) $l['id']),
            'source' => 'shift',
            'title' => trim((string) $l['libelle_poste']) ?: 'Créneau de travail',
            'start' => (new \DateTimeImmutable((string) $l['debut']))->format(\DateTimeInterface::ATOM),
            'end' => (new \DateTimeImmutable((string) $l['fin']))->format(\DateTimeInterface::ATOM),
            'allDay' => false,
            'type' => 'shift',
            'scope' => 'mine',
            'detail' => (string) $l['statut'],
        ], $lignes);
    }

    /**
     * L'ouverture du site, calculée par `OpeningCalendar` et par personne d'autre.
     *
     * Elle apparaît dans l'agenda comme un ARRIÈRE-PLAN, pas comme un rendez-vous : c'est ce qui
     * permet de voir qu'un créneau a été posé en dehors des heures d'ouverture — l'erreur que rien
     * ne relevait avant ce module.
     *
     * @return list<array<string, mixed>>
     */
    private function ouvertureEtFermetures(Etablissement $etablissement, \DateTimeImmutable $du, \DateTimeImmutable $au): array
    {
        $lignes = [];
        foreach ($this->calendrier->fenetres($etablissement, $du, $au) as $i => $fenetre) {
            $lignes[] = [
                'id' => 'opening-' . $fenetre['day'] . '-' . $i,
                'source' => 'opening',
                'title' => $fenetre['label'] ?? 'Ouvert',
                'start' => $fenetre['start']->format(\DateTimeInterface::ATOM),
                'end' => $fenetre['end']->format(\DateTimeInterface::ATOM),
                'allDay' => false,
                'type' => 'opening',
                'scope' => 'site',
                'detail' => null,
            ];
        }

        return $lignes;
    }
}
