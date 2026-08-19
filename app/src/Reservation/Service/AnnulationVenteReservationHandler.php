<?php

declare(strict_types=1);

namespace App\Reservation\Service;

use App\Reservation\Entity\Reservation;
use App\Securite\Entity\Utilisateur;
use App\Vente\Entity\Vente;
use App\Vente\Enum\StatutVente;
use App\Vente\Service\ContrePassationHandler;
use App\Vente\Service\PanierCalculateur;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Traite la Vente rattachée d'une Reservation au moment de son annulation (RG-RESAENC-09/10, gaps
 * G1/G2 du plan) : émet un avoir de remboursement si elle était déjà encaissée et dans le délai franc
 * (G1, réutilise ContrePassationHandler::rembourser — inchangé), nettoie une Vente pendante jamais
 * réglée pour éviter un panier fantôme en caisse (G2, ContrePassationHandler::annuler exige une Vente
 * déjà validee — non réutilisable en l'état pour ce cas, §4.9 de la spec). No-op si aucune Vente
 * rattachée (produit gratuit / quota de formule, G3 préservé) ou si elle est déjà annulee/avoir_emis
 * (idempotence défensive).
 */
final class AnnulationVenteReservationHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ContrePassationHandler $contrePassation,
        private readonly PanierCalculateur $calc,
    ) {
    }

    public function traiter(Reservation $reservation, Utilisateur $auteur, bool $remboursementAutorise): void
    {
        $vente = $reservation->getVenteRattachee();
        if ($vente === null) {
            return; // gratuit / quota_formule (G3) : rien à faire
        }

        match ($vente->getStatut()) {
            StatutVente::EnCours => $this->nettoyer($vente),
            StatutVente::Validee => $remboursementAutorise ? $this->rembourser($reservation, $vente, $auteur) : null,
            StatutVente::Annulee, StatutVente::AvoirEmis => null, // idempotent, déjà traité
        };
    }

    /** G1 (RG-RESAENC-09) : avoir de remboursement intégral, même mécanisme qu'un remboursement M2 standard. */
    private function rembourser(Reservation $reservation, Vente $vente, Utilisateur $auteur): void
    {
        $motif = sprintf('Annulation réservation %s dans le délai franc (RG-RESAENC-09)', (string) $reservation->getId());
        $avoir = $this->contrePassation->rembourser($vente, null, $motif, $auteur); // null = montant total
        $this->em->persist($avoir);
    }

    /** G2 (RG-RESAENC-10) : nettoyage d'une Vente jamais réglée, sans Avoir (rien n'a été encaissé). */
    private function nettoyer(Vente $vente): void
    {
        foreach ($vente->getLignes()->toArray() as $ligne) {
            $vente->removeLigne($ligne);
            $this->em->remove($ligne);
        }
        $this->calc->recalculerVente($vente);
        $vente->setStatut(StatutVente::Annulee);
    }
}
