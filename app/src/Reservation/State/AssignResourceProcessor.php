<?php

declare(strict_types=1);

namespace App\Reservation\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Reservation\Entity\Reservation;
use App\Reservation\Entity\Ressource;
use App\Reservation\Enum\StatutReservation;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * ACT-1 point 2 / D16 (nom anglais, D5 — fichier neuf) — affecte l'**instance** à une réservation faite sur un **type**
 * (POST /reservation/reservations/{id}/affecter).
 *
 * « Personne ne réserve la chambre 214 : on réserve une chambre double. » Le type est une
 * `Ressource` qui porte des sous-ressources (`ressourceMere`), et les instances sont ses enfants —
 * la structure existe déjà, c'est l'usage qui change : réserver **l'enfant**, c'est choisir une
 * instance précise (la ligne d'eau 1, le court 3) ; réserver **le parent**, c'est réserver un type,
 * et l'instance s'affecte ici. Aucune entité neuve, aucune colonne sur `Creneau` : la « couche
 * mince » que D16 demande.
 *
 * **Ce que ce processor refuse, et pourquoi chaque refus existe :**
 * - une instance qui n'est pas un enfant du type réservé — sinon « chambre double » pourrait être
 *   honorée par un emplacement de camping ;
 * - une instance d'un autre établissement — échec fermé en 404 (D3/D8), l'instance étant désignée
 *   par le client ;
 * - une instance **déjà affectée** à une autre réservation qui chevauche le créneau. C'est le seul
 *   refus qui protège un client réel : sans lui, deux personnes reçoivent la chambre 214 pour la
 *   même nuit, et personne ne s'en aperçoit avant l'arrivée.
 *
 * @implements ProcessorInterface<mixed, Reservation>
 */
final class AssignResourceProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Reservation
    {
        \assert($data instanceof Reservation);

        if (!$data->getStatut()->occupePlace()) {
            throw new ConflictHttpException('Réservation annulée : aucune instance à lui affecter.');
        }

        $creneau = $data->getCreneau();
        $type = $creneau?->getRessource();
        if ($creneau === null || $type === null) {
            throw new UnprocessableEntityHttpException('Réservation sans créneau ou sans ressource : affectation impossible.');
        }

        $instance = $this->resoudreInstance($this->lecteur->corps()['ressource'] ?? null);

        // Cloisonnement (D3/D8) : le périmètre vient de la réservation, donc de la session serveur ;
        // l'instance vient du corps de la requête. 404 et non 403 — confirmer l'existence d'une
        // ressource d'un autre établissement serait déjà une fuite.
        if ((string) $instance->getEtablissement()?->getId() !== (string) $data->getEtablissement()?->getId()) {
            throw new NotFoundHttpException('Ressource introuvable.');
        }

        if ((string) $instance->getRessourceMere()?->getId() !== (string) $type->getId()) {
            throw new UnprocessableEntityHttpException(sprintf(
                'La ressource « %s » n\'est pas une instance de « %s » : elle ne peut pas honorer cette réservation (ACT-1, D16).',
                $instance->getLibelle(),
                $type->getLibelle(),
            ));
        }

        if (!$instance->isActif()) {
            throw new ConflictHttpException(sprintf('La ressource « %s » est désactivée.', $instance->getLibelle()));
        }

        $conflit = $this->reservationConcurrente($instance, $data);
        if ($conflit !== null) {
            throw new ConflictHttpException(sprintf(
                'La ressource « %s » est déjà affectée sur un créneau qui chevauche celui-ci (ACT-1, D16).',
                $instance->getLibelle(),
            ));
        }

        $data->setRessourceAffectee($instance);
        $this->em->flush();

        return $data;
    }

    /**
     * Une instance ne peut honorer qu'une réservation à la fois sur un intervalle donné. Le
     * chevauchement se lit sur les créneaux : deux réservations se disputent l'instance dès que
     * leurs créneaux se recouvrent, même partiellement — une chambre n'est pas libre « à moitié ».
     */
    private function reservationConcurrente(Ressource $instance, Reservation $reservation): ?Reservation
    {
        $creneau = $reservation->getCreneau();
        \assert($creneau !== null);

        /** @var Reservation|null $conflit */
        $conflit = $this->em->getRepository(Reservation::class)->createQueryBuilder('r')
            ->join('r.creneau', 'c')
            ->andWhere('r.ressourceAffectee = :instance')
            ->andWhere('r.id != :moi')
            ->andWhere('r.statut IN (:statuts)')
            ->andWhere('c.debut < :fin')
            ->andWhere('c.fin > :debut')
            ->setParameter('instance', $instance->getId(), 'uuid')
            ->setParameter('moi', $reservation->getId(), 'uuid')
            ->setParameter('statuts', [StatutReservation::Confirmee->value, StatutReservation::Honoree->value])
            ->setParameter('debut', $creneau->getDebut(), 'datetime_immutable')
            ->setParameter('fin', $creneau->getFin(), 'datetime_immutable')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $conflit;
    }

    private function resoudreInstance(mixed $reference): Ressource
    {
        if (!\is_string($reference) || $reference === '') {
            throw new UnprocessableEntityHttpException('Référence « ressource » obligatoire (UUID ou IRI).');
        }
        $segment = str_contains($reference, '/') ? basename($reference) : $reference;
        if (!Uuid::isValid($segment)) {
            throw new UnprocessableEntityHttpException('Référence « ressource » invalide.');
        }

        $instance = $this->em->getRepository(Ressource::class)->find(Uuid::fromString($segment));
        if (!$instance instanceof Ressource) {
            throw new NotFoundHttpException('Ressource introuvable.');
        }

        return $instance;
    }
}
