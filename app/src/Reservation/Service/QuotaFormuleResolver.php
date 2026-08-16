<?php

declare(strict_types=1);

namespace App\Reservation\Service;

use App\Offre\Entity\ServiceInclus;
use App\Offre\Service\SimulateurQuota;
use App\Reservation\Entity\Activite;
use App\Reservation\Port\FormuleBeneficiaireInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Enveloppe `App\Offre\Service\SimulateurQuota` (RG-M1-12, semaine calendaire sans report) pour
 * déterminer si un bénéficiaire dispose encore d'un quota inclus pour une Activité donnée (RG-M5-02,
 * CA-3). S'appuie sur le port `FormuleBeneficiaireInterface` (frontière M1/M4, Risque n°5 du plan).
 */
final class QuotaFormuleResolver
{
    public function __construct(
        private readonly FormuleBeneficiaireInterface $port,
        private readonly SimulateurQuota $simulateur,
    ) {
    }

    /** ServiceInclus disposant encore de quota pour cette activité/bénéficiaire à la date donnée, ou null. */
    public function resoudre(Uuid $beneficiaireId, Activite $activite, \DateTimeImmutable $date): ?ServiceInclus
    {
        $service = $this->port->serviceInclus($beneficiaireId, $activite->getId());
        if ($service === null) {
            return null;
        }

        $consommations = $this->port->consommations($beneficiaireId, $service->getId());
        $restant = $this->simulateur->quotaRestant($service, $date, $consommations);

        return $restant > 0 ? $service : null;
    }
}
