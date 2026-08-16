<?php

declare(strict_types=1);

namespace App\Tests\Reservation\Unit;

use App\Offre\Entity\Formule;
use App\Offre\Entity\ServiceInclus;
use App\Offre\Enum\PeriodeQuota;
use App\Offre\Enum\PeriodiciteFormule;
use App\Offre\Service\SimulateurQuota;
use App\Reservation\Entity\Activite;
use App\Reservation\Port\FormuleBeneficiaireInterface;
use App\Reservation\Service\QuotaFormuleResolver;
use App\Organisation\Entity\Etablissement;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/** RG-M1-12 (semaine calendaire, sans report) via `App\Offre\Service\SimulateurQuota`, réutilisé tel quel. */
final class QuotaFormuleResolverTest extends TestCase
{
    public function testQuotaRestantSemaineCalendaireSansReport(): void
    {
        $activite = (new Activite())->setEtablissement(new Etablissement())->setLibelle('Padel')->setTypeActivite('sport')->setDureeMinutes(60);

        $formule = (new Formule())->setPeriodicite(PeriodiciteFormule::Mensuel);
        $service = (new ServiceInclus())->setFormule($formule)->setActiviteRef($activite->getId())->setQuota(2)->setPeriode(PeriodeQuota::SemaineCalendaire);

        $beneficiaireId = Uuid::v4();
        $lundiSemaine1 = new \DateTimeImmutable('2026-08-10T10:00:00'); // un lundi.

        $port = new class($beneficiaireId, $activite->getId(), $service) implements FormuleBeneficiaireInterface {
            /** @var list<\DateTimeImmutable> */
            public array $consommations = [];

            public function __construct(
                private readonly Uuid $beneficiaireId,
                private readonly Uuid $activiteId,
                private readonly ServiceInclus $service,
            ) {
            }

            public function serviceInclus(Uuid $beneficiaireId, Uuid $activiteId): ?ServiceInclus
            {
                return ((string) $beneficiaireId === (string) $this->beneficiaireId && (string) $activiteId === (string) $this->activiteId)
                    ? $this->service : null;
            }

            public function consommations(Uuid $beneficiaireId, Uuid $serviceInclusId): array
            {
                return $this->consommations;
            }
        };

        $resolveur = new QuotaFormuleResolver($port, new SimulateurQuota());

        // Aucune consommation : quota disponible.
        self::assertNotNull($resolveur->resoudre($beneficiaireId, $activite, $lundiSemaine1));

        // Quota épuisé (2 consommations dans la fenêtre) : plus de service disponible.
        $port->consommations = [$lundiSemaine1->modify('+1 day'), $lundiSemaine1->modify('+2 days')];
        self::assertNull($resolveur->resoudre($beneficiaireId, $activite, $lundiSemaine1));

        // Semaine suivante : réinitialisation sans report (RG-M1-12).
        $lundiSemaine2 = $lundiSemaine1->modify('+7 days');
        self::assertNotNull($resolveur->resoudre($beneficiaireId, $activite, $lundiSemaine2));

        // Bénéficiaire inconnu du port : aucun service.
        self::assertNull($resolveur->resoudre(Uuid::v4(), $activite, $lundiSemaine1));
    }
}
