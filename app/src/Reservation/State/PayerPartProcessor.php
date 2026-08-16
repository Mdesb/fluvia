<?php

declare(strict_types=1);

namespace App\Reservation\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Reservation\Entity\ParticipantReservation;
use App\Reservation\Enum\StatutPaiementParticipant;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Règlement individuel d'une part de paiement partagé (RG-M5-10, CA-13) : chaque part est encaissée
 * et tracée séparément. La référence M2 de l'encaissement effectif de la part est hors périmètre de
 * ce processor (vente/paiement standard M2, déclenché séparément par l'agent/le client) — ce
 * processor matérialise la part comme réglée.
 *
 * @implements ProcessorInterface<ParticipantReservation, ParticipantReservation>
 */
final class PayerPartProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ParticipantReservation
    {
        \assert($data instanceof ParticipantReservation);

        $data->setStatutPaiement(StatutPaiementParticipant::Paye);
        $this->em->flush();

        return $data;
    }
}
