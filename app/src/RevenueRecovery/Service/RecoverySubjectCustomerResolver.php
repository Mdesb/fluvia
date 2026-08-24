<?php

declare(strict_types=1);

namespace App\RevenueRecovery\Service;

use App\Reservation\Entity\Reservation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Résolution best-effort d'un client CRM depuis le couple opaque `(subjectType, subjectRef)` porté par
 * un `RecoveryCase` (§0.8 du plan) — nécessaire **uniquement** pour la vérification du consentement RGPD
 * à l'envoi (RG-RR-03, `RecoveryEngine::sendDueAttempts()`), jamais pour l'ouverture du dossier
 * lui-même (`RecoveryEngine::handle()` ne résout jamais l'entité d'origine, §0.8 — aucune association
 * Doctrine cross-module sur `RecoveryCase`).
 *
 * ⚠ HYPOTHÈSE — mécanisme non détaillé par le plan (§3/§10 renvoient au « couple (client, canal) connu
 * par ailleurs » sans préciser comment RR le retrouve depuis `subjectRef`), à confirmer par claude-A.
 * Implémentation retenue pour I1 : lecture cross-module en lecture seule d'
 * `App\Reservation\Entity\Reservation` pour `subjectType = 'Reservation'` (`booking.cancelled`/
 * `booking.no_show`, seuls déclencheurs I1 dont le sujet est une réservation) — même dérogation
 * documentée que `App\SmartFlow\Service\ReservationSlotReader` (précédent direct, RG-SF-17). Les autres
 * `subjectType` (`PaymentIncident`, `Cart`, `Quote`, `Customer`…) ne sont **pas résolus** en I1 : `null`
 * est renvoyé, ce qui fait échouer fermé la vérification de consentement côté `RecoveryEngine`
 * (RG-RR-03 : pas de client identifiable = tentative sautée, jamais une exception ni un envoi à
 * l'aveugle).
 */
final class RecoverySubjectCustomerResolver
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function resolveCustomerId(string $subjectType, string $subjectRef): ?Uuid
    {
        if ('Reservation' !== $subjectType) {
            return null;
        }
        if (!Uuid::isValid($subjectRef)) {
            return null;
        }

        $reservation = $this->em->getRepository(Reservation::class)->find(Uuid::fromString($subjectRef));
        if (!$reservation instanceof Reservation) {
            return null;
        }

        return $reservation->getOrganisateur()?->getClient()?->getId();
    }
}
