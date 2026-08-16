<?php

declare(strict_types=1);

namespace App\Boutique\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Boutique\Entity\AllocationQuotaOTA;
use App\Boutique\Ota\ConnecteurOtaInterface;
use App\Boutique\Security\PanierProprietaireGuard;
use App\Crm\Entity\Beneficiaire;
use App\Reservation\Entity\Reservation;
use App\Reservation\Enum\ModeDecompteReservation;
use App\Reservation\Service\JaugeCreneauGuard;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * POST /boutique/ota/ventes (US-L8-14, RG-M3-09, CA-19) : ingestion d'une vente confirmée par un
 * connecteur OTA — décrémente le **même inventaire réel** que la vente directe (timed-entry :
 * `JaugeCreneauGuard`/`Reservation`, patron `App\Musee\State\CreerReservationOtaProcessor` reconduit
 * ici sur les objets génériques L8) et plafonne via `AllocationQuotaOTA.quotaConsomme` (anti
 * sur-vente). Corps : { "allocation": iri|uuid, "beneficiaire": iri|uuid }.
 *
 * @implements ProcessorInterface<mixed, JsonResponse>
 */
final class IngestionVenteOtaProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly JaugeCreneauGuard $jauge,
        private readonly ConnecteurOtaInterface $connecteur,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $corps = $this->lecteur->corps();

        $allocationId = PanierProprietaireGuard::estUuid($corps['allocation'] ?? null);
        $allocation = $allocationId !== null ? $this->em->getRepository(AllocationQuotaOTA::class)->find($allocationId) : null;
        if (!$allocation instanceof AllocationQuotaOTA) {
            throw new UnprocessableEntityHttpException('« allocation » est requise et doit référencer une allocation existante.');
        }
        if ($allocation->estEpuisee()) {
            throw new ConflictHttpException('RG-M3-09 : quota alloué à ce partenaire épuisé.');
        }

        $beneficiaireId = PanierProprietaireGuard::estUuid($corps['beneficiaire'] ?? null);
        $beneficiaire = $beneficiaireId !== null ? $this->em->getRepository(Beneficiaire::class)->find($beneficiaireId) : null;
        if (!$beneficiaire instanceof Beneficiaire) {
            throw new UnprocessableEntityHttpException('« beneficiaire » est requis et doit référencer un bénéficiaire existant.');
        }

        $creneau = $allocation->getCreneau();
        if ($creneau !== null) {
            // Timed-entry : même compteur réel que la vente directe (RG-M3-09), aucun stock séparé.
            if ($this->jauge->estComplet($creneau)) {
                throw new ConflictHttpException('RG-M3-09 : créneau complet (inventaire partagé avec la vente directe).');
            }
            $reservation = new Reservation();
            $reservation->setCreneau($creneau)
                ->setOrganisateur($beneficiaire)
                ->setEtablissement($allocation->getEtablissement())
                ->setModeDecompte(ModeDecompteReservation::VenteUnite)
                ->setMontantDu($allocation->getPartenaire()?->getTarifNet() ?? '0.00');
            $this->em->persist($reservation);
        } else {
            $produit = $allocation->getProduit();
            $stock = $produit?->getStock();
            if ($stock === null || $stock->disponibiliteEffective() <= 0) {
                throw new ConflictHttpException('RG-M3-09 : stock épuisé (inventaire partagé avec la vente directe).');
            }
            $stock->setDisponibilite(max(0, $stock->getDisponibilite() - 1));
        }

        $allocation->setQuotaConsomme($allocation->getQuotaConsomme() + 1);
        $this->em->flush();

        $this->connecteur->notifierAllocation($allocation);

        return new JsonResponse([
            'allocation' => (string) $allocation->getId(),
            'quotaConsomme' => $allocation->getQuotaConsomme(),
            'quotaAlloue' => $allocation->getQuotaAlloue(),
        ], JsonResponse::HTTP_CREATED);
    }
}
