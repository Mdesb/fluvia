<?php

declare(strict_types=1);

namespace App\Acces\Service;

use App\Acces\Entity\Controleur;
use App\Acces\Enum\EtatControleur;
use App\Acces\Port\PiloteAcces;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Bascule online/offline automatique (§4.6 du plan, RG-ACC-05, CA-8) : absence de heartbeat au-delà
 * du seuil fait basculer le contrôleur `hors_ligne`, sans dégrader l'affichage des autres (CA-7).
 */
final class EtatReseauHandler
{
    private const SEUIL_HORS_LIGNE_SECONDES = 60;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PiloteAcces $pilote,
    ) {
    }

    public function pulser(Controleur $controleur): Controleur
    {
        $etat = $this->pilote->heartbeat($controleur);
        $controleur->setEtat($etat->etat)->setDernierHeartbeat($etat->dernierHeartbeat ?? new \DateTimeImmutable());
        $this->em->flush();

        return $controleur;
    }

    /** Recalcule l'état réseau à partir de l'ancienneté du dernier heartbeat (sans appel matériel). */
    public function verifierExpiration(Controleur $controleur, ?\DateTimeImmutable $maintenant = null): Controleur
    {
        $maintenant ??= new \DateTimeImmutable();
        $dernier = $controleur->getDernierHeartbeat();

        if ($controleur->getEtat() !== EtatControleur::HorsService) {
            if ($dernier === null || ($maintenant->getTimestamp() - $dernier->getTimestamp()) > self::SEUIL_HORS_LIGNE_SECONDES) {
                $controleur->setEtat(EtatControleur::HorsLigne);
            } else {
                $controleur->setEtat(EtatControleur::EnLigne);
            }
        }

        $this->em->flush();

        return $controleur;
    }
}
