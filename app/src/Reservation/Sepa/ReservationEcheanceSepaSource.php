<?php

declare(strict_types=1);

namespace App\Reservation\Sepa;

use App\Organisation\Entity\Etablissement;
use App\Reservation\Entity\FacturationNoShow;
use App\Reservation\Enum\ModeFacturationNoShow;
use App\Reservation\Enum\StatutFacturationNoShow;
use App\Sepa\Dto\EcheanceSepaDue;
use App\Sepa\Entity\MandatSepa;
use App\Sepa\Entity\RemiseSepa;
use App\Sepa\Enum\StatutMandatSepa;
use App\Sepa\Port\EcheanceSepaSource;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Implémentation du port `EcheanceSepaSource` (plan §3/§5, comme `App\Sport\Sepa\SportEcheanceSepaSource`)
 * pour le mode `prelevement_differe` (squelette documenté, Risque n°1 du plan) : fournit au module SEPA
 * partagé les `FacturationNoShow` en attente (`referenceEcheanceSepa` renseignée, statut `à_facturer`)
 * dont le bénéficiaire dispose d'un mandat SEPA actif. Taguée `sepa.echeance_source` (services.yaml).
 */
final class ReservationEcheanceSepaSource implements EcheanceSepaSource
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function echeancesDues(Etablissement $etablissement, \DateTimeImmutable $dateExecution): array
    {
        /** @var list<FacturationNoShow> $facturations */
        $facturations = $this->em->getRepository(FacturationNoShow::class)->createQueryBuilder('f')
            ->join('f.reservation', 'r')
            ->addSelect('r')
            ->join('f.regleAppliquee', 'regle')
            ->andWhere('IDENTITY(r.etablissement) = :etab')
            ->andWhere('regle.modeFacturation = :mode')
            ->andWhere('f.statut = :statut')
            ->andWhere('f.referenceEcheanceSepa IS NOT NULL')
            ->setParameter('etab', $etablissement->getId(), 'uuid')
            ->setParameter('mode', ModeFacturationNoShow::PrelevementDiffere->value)
            ->setParameter('statut', StatutFacturationNoShow::AFacturer->value)
            ->getQuery()->getResult();

        $dues = [];
        foreach ($facturations as $facturation) {
            $client = $facturation->getReservation()?->getOrganisateur()?->getClient();
            if ($client === null) {
                continue;
            }
            $mandat = $this->em->getRepository(MandatSepa::class)->findOneBy(['client' => $client, 'statut' => StatutMandatSepa::Actif]);
            if ($mandat === null) {
                continue;
            }

            $dues[] = new EcheanceSepaDue(
                referenceOrigine: (string) $facturation->getId(),
                mandatId: $mandat->getId(),
                montantCentimes: (int) round(((float) $facturation->getMontant()) * 100),
                libelle: 'No-show réservation ' . (string) $facturation->getReservation()?->getId(),
                dateEcheance: $dateExecution,
                derniereEcheanceEngagement: true,
                paiementUnique: true,
            );
        }

        return $dues;
    }

    public function marquerCollectees(RemiseSepa $remise, array $referencesOrigine): void
    {
        $repository = $this->em->getRepository(FacturationNoShow::class);
        foreach ($referencesOrigine as $reference) {
            $facturation = $repository->find($reference);
            if (!$facturation instanceof FacturationNoShow) {
                continue;
            }
            $facturation->setStatut(StatutFacturationNoShow::Facturee);
        }
    }
}
