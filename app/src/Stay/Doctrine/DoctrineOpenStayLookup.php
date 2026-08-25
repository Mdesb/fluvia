<?php

declare(strict_types=1);

namespace App\Stay\Doctrine;

use App\Crm\Entity\Client;
use App\Organisation\Entity\Etablissement;
use App\Stay\Entity\Stay;
use App\Stay\Enum\StayStatus;
use App\Stay\Service\OpenStayLookup;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Implémentation Doctrine de {@see OpenStayLookup}.
 *
 * **La date de départ prévue n'entre PAS dans le filtre**, et c'est un choix. Elle est déclarative :
 * un client qui prolonge d'une nuit ne repasse pas au comptoir avant de commander au bar. Filtrer
 * dessus ferait « tomber » les consommations d'un séjour parfaitement ouvert le jour où il dépasse sa
 * date annoncée — un bogue invisible qui n'apparaîtrait qu'en haute saison. C'est le **statut** qui
 * fait foi : un séjour est ouvert tant que personne ne l'a clos.
 *
 * La date d'arrivée, elle, est filtrée : rattacher une consommation à un séjour qui n'a pas encore
 * commencé n'a pas de sens, et c'est le cas d'une réservation enregistrée à l'avance.
 *
 * Paramètres liés par `IDENTITY(...)` + type `uuid` explicite : voir
 * {@see DoctrineStayChargeLookup} pour la raison, elle a coûté une lecture muette.
 */
final class DoctrineOpenStayLookup implements OpenStayLookup
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** @return list<Stay> */
    public function openStaysFor(Etablissement $establishment, Client $customer, \DateTimeImmutable $at): array
    {
        /** @var list<Stay> $sejours */
        $sejours = $this->em->createQuery(
            'SELECT s FROM ' . Stay::class . ' s
             WHERE IDENTITY(s.establishment) = :establishment
               AND IDENTITY(s.customer) = :customer
               AND s.status = :status
               AND s.arrivalDate <= :at
             ORDER BY s.openedAt ASC',
        )
            ->setParameter('establishment', $establishment->getId(), 'uuid')
            ->setParameter('customer', $customer->getId(), 'uuid')
            ->setParameter('status', StayStatus::Open->value)
            ->setParameter('at', $at->setTime(0, 0))
            ->getResult();

        return $sejours;
    }
}
