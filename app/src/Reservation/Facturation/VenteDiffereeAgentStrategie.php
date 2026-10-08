<?php

declare(strict_types=1);

namespace App\Reservation\Facturation;

use App\Caisse\Entity\SessionCaisse;
use App\Reservation\Entity\FacturationNoShow;
use App\Reservation\Enum\ModeFacturationNoShow;
use App\Reservation\Enum\StatutFacturationNoShow;
use App\Reservation\Service\NoShowBillingLock;
use App\Reservation\Service\VenteReservationHandler;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Stratégie réellement branchée (décision structurante n°4 du plan) : crée une `Vente` M2 réelle
 * quand un agent traite le dossier depuis une session caisse ouverte (`EmettreVenteNoShowProcessor`,
 * CA-11). Aucun encaissement automatique : l'agent finalise le règlement au guichet via l'API Vente
 * standard (`POST /ventes/{id}/paiements`, `/valider`).
 */
final class VenteDiffereeAgentStrategie implements StrategieFacturationNoShow
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly VenteReservationHandler $venteHandler,
        private readonly NoShowBillingLock $verrou,
    ) {
    }

    public function code(): string
    {
        return ModeFacturationNoShow::VenteDiffereeAgent->value;
    }

    public function appliquer(FacturationNoShow $facturation, ?Utilisateur $agent, array $contexte = []): ResultatFacturationNoShow
    {
        if ($agent === null) {
            return new ResultatFacturationNoShow(false, 'Un agent authentifié est requis (mode vente_differee_agent).');
        }

        $session = $contexte['session'] ?? null;
        if (!$session instanceof SessionCaisse) {
            return new ResultatFacturationNoShow(false, 'Session de caisse ouverte requise pour émettre la vente.');
        }
        if (!$session->estOuverte()) {
            return new ResultatFacturationNoShow(false, 'La session de caisse fournie n\'est pas ouverte (RG-M2-01).');
        }

        $reservation = $facturation->getReservation();
        $organisateur = $reservation?->getOrganisateur();
        $clientRef = $organisateur?->getClient()?->getId();

        // ⚠ MÊME GARDE QUE `DebitPmvStrategie` : sans produit, la vente pointerait vers un produit
        // inexistant et sortirait de la ventilation comptable. On refuse, et on dit quoi paramétrer.
        $produitRef = $reservation?->getCreneau()?->getActivite()?->getProduitTarifReference()?->getId();
        if ($produitRef === null) {
            return new ResultatFacturationNoShow(
                false,
                'L\'activité de ce créneau n\'a pas de produit tarifaire : la vente ne serait rattachée à '
                . 'aucune catégorie comptable. Renseignez-le avant d\'émettre cette facturation.',
            );
        }

        // Sous le verrou de la facturation (lot 4) : deux agents, ou un agent et une exonération, ne
        // passent plus tous deux — une seule vente, et jamais pour une absence exonérée entre-temps.
        return $this->em->getConnection()->transactional(function () use ($facturation, $session, $clientRef, $reservation, $produitRef): ResultatFacturationNoShow {
            $statut = $this->verrou->lock($facturation);
            if ($statut !== StatutFacturationNoShow::AFacturer) {
                return ResultatFacturationNoShow::alreadySettled($statut);
            }

            $vente = $this->venteHandler->creerVente(
                $session,
                $facturation->getMontant(),
                $clientRef,
                'No-show / annulation tardive — réservation ' . (string) $reservation?->getId(),
                $produitRef,
                $reservation?->getCreneau()?->getActivite()?->getId(),
                $reservation?->getCreneau()?->getRessource()?->getId(),
            );

            $facturation->setVenteRattachee($vente);
            $facturation->setStatut(StatutFacturationNoShow::Facturee);
            $this->em->flush();

            return new ResultatFacturationNoShow(true);
        });
    }
}
