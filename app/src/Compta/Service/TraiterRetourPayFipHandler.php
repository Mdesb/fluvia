<?php

declare(strict_types=1);

namespace App\Compta\Service;

use App\Compta\Entity\BordereauPayFiP;
use App\Compta\Enum\StatutPayFiP;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Traite le retour PayFiP (US-L4-03, CA-6) : met à jour le statut de la transaction et marque le
 * rapprochement. ⚠ Simplification assumée (§9 du plan) — ne modifie **jamais** `App\Vente\Entity\
 * Vente` (M2 non modifié) : le statut « payé » côté vente reste porté par M2 (hors périmètre de ce
 * lot, cf. consigne de non-régression), M6 trace ici le rapprochement de son propre référentiel.
 */
final class TraiterRetourPayFipHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function initier(Uuid $venteId, string $referenceTransaction): BordereauPayFiP
    {
        $bordereau = new BordereauPayFiP();
        $bordereau->setVenteOrigine($venteId);
        $bordereau->setReferenceTransaction($referenceTransaction);
        $bordereau->setStatutRetour(StatutPayFiP::EnAttente);

        $this->em->persist($bordereau);
        $this->em->flush();

        return $bordereau;
    }

    public function traiterRetour(BordereauPayFiP $bordereau, StatutPayFiP $statut): BordereauPayFiP
    {
        $bordereau->setStatutRetour($statut);
        if ($statut === StatutPayFiP::Ok) {
            $bordereau->setVenteRapprochee(true);
        }

        $this->em->flush();

        return $bordereau;
    }

    public function rejouer(BordereauPayFiP $bordereau): BordereauPayFiP
    {
        $bordereau->setNbTentativesRejeu($bordereau->getNbTentativesRejeu() + 1);
        $this->em->flush();

        return $bordereau;
    }
}
