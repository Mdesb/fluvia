<?php

declare(strict_types=1);

namespace App\Padel\Service;

use App\Padel\Entity\EvenementEclairage;
use App\Padel\Entity\RelaisEclairageTerrain;
use App\Padel\Enum\ActionEclairage;
use App\Padel\Enum\StatutEvenementEclairage;
use App\Padel\Enum\StatutRelaisEclairage;
use App\Padel\Port\PiloteEclairage;
use App\Reservation\Entity\Reservation;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Pilote l'allumage/extinction automatique du relais d'éclairage sur la fenêtre réservée (RG-PADEL-05,
 * CA-11). En cas de défaut détecté (heartbeat ou commande en échec), le relais bascule `en_defaut` —
 * aucun `EvenementEclairage` n'est tracé à ce stade (l'incident tracé `echec_repli_manuel` correspond
 * au **repli manuel effectif** déclenché par le Gestionnaire de club, `ForcerEclairageManuelProcessor`).
 */
final class PilotageEclairageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PiloteEclairage $pilote,
    ) {
    }

    public function declencher(RelaisEclairageTerrain $relais, ActionEclairage $action, ?Reservation $reservation = null): ?EvenementEclairage
    {
        if ($this->pilote->heartbeat($relais) === StatutRelaisEclairage::EnDefaut) {
            $relais->setStatut(StatutRelaisEclairage::EnDefaut);
            $this->em->flush();

            return null;
        }

        $resultat = $this->pilote->commander($relais, $action);
        if (!$resultat->ok) {
            $relais->setStatut(StatutRelaisEclairage::EnDefaut);
            $this->em->flush();

            return null;
        }

        $relais->setStatut(StatutRelaisEclairage::Operationnel);

        $evenement = new EvenementEclairage();
        $evenement->setTerrain($relais->getTerrain())
            ->setReservation($reservation)
            ->setAction($action)
            ->setStatut(StatutEvenementEclairage::Ok);
        $this->em->persist($evenement);
        $this->em->flush();

        return $evenement;
    }
}
