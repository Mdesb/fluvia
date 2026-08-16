<?php

declare(strict_types=1);

namespace App\Reservation\Security;

use App\Reservation\Entity\Reservation;
use App\Securite\Entity\Utilisateur;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Voter « soi » (attribut `RESERVATION_SOI`, patron `App\Recouvrement\Security\RedevableSoiVoter`) :
 * autorise si l'utilisateur connecté correspond au `Beneficiaire.client` de `Reservation.organisateur`
 * ou d'un `ParticipantReservation` de la réservation.
 *
 * @extends Voter<string, Reservation>
 */
final class ReservationSoiVoter extends Voter
{
    public const ATTRIBUTE = 'RESERVATION_SOI';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::ATTRIBUTE && $subject instanceof Reservation;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        \assert($subject instanceof Reservation);

        $utilisateur = $token->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return false;
        }
        $clientLie = $utilisateur->getClientLie();
        if ($clientLie === null) {
            return false;
        }

        $organisateur = $subject->getOrganisateur();
        if ($organisateur !== null && $organisateur->getClient() !== null && (string) $organisateur->getClient()->getId() === (string) $clientLie) {
            return true;
        }

        foreach ($subject->getParticipants() as $participant) {
            $personne = $participant->getPersonne();
            if ($personne !== null && $personne->getClient() !== null && (string) $personne->getClient()->getId() === (string) $clientLie) {
                return true;
            }
        }

        return false;
    }
}
