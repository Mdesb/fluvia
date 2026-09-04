<?php

declare(strict_types=1);

namespace App\Reservation\Facturation;

use App\Reservation\Entity\FacturationNoShow;
use App\Reservation\Enum\ModeFacturationNoShow;
use App\Reservation\Enum\StatutFacturationNoShow;
use App\Reservation\Service\SessionSystemeResolver;
use App\Reservation\Service\VenteReservationHandler;
use App\Securite\Entity\Utilisateur;
use App\Vente\Service\PaiementHandler;
use App\Vente\Service\ValiderVenteService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Stratégie réellement branchée (décision structurante n°4 du plan) : débit automatique du
 * porte-monnaie virtuel du bénéficiaire (`App\Vente\Port\PorteMonnaieVirtuelInterface`, via
 * `PaiementHandler` — même mécanisme atomique que le paiement guichet), sans agent présent. Utilise
 * une `SessionCaisse` technique permanente par établissement (`SessionSystemeResolver`, Risque n°1 du
 * plan). Si le débit réussit, la vente est validée/scellée (NF525) immédiatement.
 */
final class DebitPmvStrategie implements StrategieFacturationNoShow
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly VenteReservationHandler $venteHandler,
        private readonly SessionSystemeResolver $sessionSysteme,
        private readonly PaiementHandler $paiementHandler,
        private readonly ValiderVenteService $validation,
    ) {
    }

    public function code(): string
    {
        return ModeFacturationNoShow::DebitPmv->value;
    }

    public function appliquer(FacturationNoShow $facturation, ?Utilisateur $agent, array $contexte = []): ResultatFacturationNoShow
    {
        $reservation = $facturation->getReservation();
        $etablissement = $reservation?->getEtablissement();
        $organisateur = $reservation?->getOrganisateur();
        $clientRef = $organisateur?->getClient()?->getId();

        if ($etablissement === null || $clientRef === null) {
            return new ResultatFacturationNoShow(false, 'Bénéficiaire sans fiche client CRM rattachée : débit PMV impossible.');
        }

        // ⚠ SANS PRODUIT, ON NE FACTURE PAS. La chaîne de `?->` rendait `null` dès qu'une activité
        // n'a pas de produit tarifaire — les 3 de la préproduction, mesuré le 04/09 — et le handler
        // inventait alors un produit inexistant. Un no-show débité sur un produit fantôme est une
        // recette que la comptabilité ne verra jamais.
        $produitRef = $reservation->getCreneau()?->getActivite()?->getProduitTarifReference()?->getId();
        if ($produitRef === null) {
            return new ResultatFacturationNoShow(
                false,
                'L\'activité de ce créneau n\'a pas de produit tarifaire : le débit ne serait rattaché à '
                . 'aucune catégorie comptable. Renseignez-le avant de facturer ce no-show.',
            );
        }

        $session = $this->sessionSysteme->sessionSysteme($etablissement);
        $vente = $this->venteHandler->creerVente(
            $session,
            $facturation->getMontant(),
            $clientRef,
            'No-show (débit PMV automatique) — réservation ' . (string) $reservation->getId(),
            $produitRef,
        );

        try {
            $resultat = $this->paiementHandler->encaisser($vente, ['moyen' => 'pmv']);
        } catch (\Throwable $e) {
            return new ResultatFacturationNoShow(false, $e->getMessage());
        }

        if ($resultat['paiement'] === null) {
            return new ResultatFacturationNoShow(false, 'Débit PMV refusé (solde insuffisant ou PMV inexistant/expiré).');
        }

        $this->validation->valider($vente);
        $facturation->setVenteRattachee($vente);
        $facturation->setStatut(StatutFacturationNoShow::Facturee);
        $this->em->flush();

        return new ResultatFacturationNoShow(true);
    }
}
