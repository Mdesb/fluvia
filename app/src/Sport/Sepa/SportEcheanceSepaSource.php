<?php

declare(strict_types=1);

namespace App\Sport\Sepa;

use App\Organisation\Entity\Etablissement;
use App\Sepa\Dto\EcheanceSepaDue;
use App\Sepa\Entity\RemiseSepa as SepaRemiseSepa;
use App\Sepa\Port\EcheanceSepaSource;
use App\Sport\Entity\AbonnementFitness;
use App\Sport\Entity\EcheanceSepa;
use App\Sport\Enum\StatutEcheanceSepa;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Implémentation Sport du port `EcheanceSepaSource` (plan §3/§5) : fournit au module SEPA partagé les
 * échéances dues de l'échéancier fitness (`App\Sport\Entity\EcheanceSepa`, resté propre à Sport), en
 * indiquant la dernière échéance de chaque engagement à durée déterminée (pour `FNAL`). Taguée
 * `sepa.echeance_source` (services.yaml) pour être agrégée par `CompositeEcheanceSepaSource`.
 */
final class SportEcheanceSepaSource implements EcheanceSepaSource
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function echeancesDues(Etablissement $etablissement, \DateTimeImmutable $dateExecution): array
    {
        /** @var list<EcheanceSepa> $echeances */
        $echeances = $this->em->getRepository(EcheanceSepa::class)->createQueryBuilder('e')
            ->join('e.abonnement', 'a')
            ->addSelect('a')
            ->join('a.mandatSepa', 'm')
            ->andWhere('IDENTITY(a.etablissement) = :etab')
            ->andWhere('e.statut = :av')
            ->andWhere('e.dateProgrammee <= :date')
            ->setParameter('etab', $etablissement->getId(), 'uuid')
            ->setParameter('av', StatutEcheanceSepa::AVenir->value)
            ->setParameter('date', $dateExecution, 'date_immutable')
            ->getQuery()->getResult();

        if ($echeances === []) {
            return [];
        }

        // Dernière échéance « à venir » de chaque abonnement (engagement à durée déterminée, pour FNAL).
        $derniereDateParAbonnement = [];
        foreach ($echeances as $echeance) {
            $idAbonnement = (string) $echeance->getAbonnement()->getId();
            $date = $echeance->getDateProgrammee();
            if (!isset($derniereDateParAbonnement[$idAbonnement]) || $date > $derniereDateParAbonnement[$idAbonnement]) {
                $derniereDateParAbonnement[$idAbonnement] = $date;
            }
        }

        $dues = [];
        foreach ($echeances as $echeance) {
            $abonnement = $echeance->getAbonnement();
            \assert($abonnement instanceof AbonnementFitness);
            $mandat = $abonnement->getMandatSepa();
            if ($mandat === null) {
                continue;
            }
            $idAbonnement = (string) $abonnement->getId();
            $derniere = $derniereDateParAbonnement[$idAbonnement] == $echeance->getDateProgrammee();

            $dues[] = new EcheanceSepaDue(
                referenceOrigine: (string) $echeance->getId(),
                mandatId: $mandat->getId(),
                montantCentimes: $echeance->getMontantCentimes(),
                libelle: 'Abonnement fitness ' . $mandat->getRum(),
                dateEcheance: $echeance->getDateProgrammee(),
                derniereEcheanceEngagement: $derniere,
                paiementUnique: false,
            );
        }

        return $dues;
    }

    public function marquerCollectees(SepaRemiseSepa $remise, array $referencesOrigine): void
    {
        $repository = $this->em->getRepository(EcheanceSepa::class);
        foreach ($referencesOrigine as $reference) {
            $echeance = $repository->find($reference);
            if (!$echeance instanceof EcheanceSepa) {
                // Référence appartenant à une autre verticale (agrégation `CompositeEcheanceSepaSource`).
                continue;
            }
            $echeance->setRemise($remise);
            $echeance->setStatut(StatutEcheanceSepa::Prelevee);
            $echeance->setDateExecutionReelle(new \DateTimeImmutable());
        }
    }
}
