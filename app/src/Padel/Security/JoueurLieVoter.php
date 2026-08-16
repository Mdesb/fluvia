<?php

declare(strict_types=1);

namespace App\Padel\Security;

use App\Padel\Entity\NiveauJoueur;
use App\Padel\Entity\ReservationPadel;
use App\Securite\Entity\Utilisateur;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Voter « soi » (attribut `PADEL_LIE`, patron `App\Reservation\Security\ReservationSoiVoter`) :
 * autorise si l'utilisateur connecté correspond à l'organisateur/un participant d'une
 * `ReservationPadel`, ou au joueur d'un `NiveauJoueur` consulté.
 *
 * @extends Voter<string, ReservationPadel|NiveauJoueur>
 */
final class JoueurLieVoter extends Voter
{
    public const ATTRIBUTE = 'PADEL_LIE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::ATTRIBUTE && ($subject instanceof ReservationPadel || $subject instanceof NiveauJoueur);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $utilisateur = $token->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return false;
        }
        $clientLie = $utilisateur->getClientLie();
        if ($clientLie === null) {
            return false;
        }

        if ($subject instanceof NiveauJoueur) {
            $client = $subject->getJoueur()?->getClient();

            return $client !== null && (string) $client->getId() === (string) $clientLie;
        }

        $reservation = $subject->getReservation();
        if ($reservation === null) {
            return false;
        }

        $organisateurClient = $reservation->getOrganisateur()?->getClient();
        if ($organisateurClient !== null && (string) $organisateurClient->getId() === (string) $clientLie) {
            return true;
        }

        foreach ($reservation->getParticipants() as $participant) {
            $client = $participant->getPersonne()?->getClient();
            if ($client !== null && (string) $client->getId() === (string) $clientLie) {
                return true;
            }
        }

        return false;
    }
}
