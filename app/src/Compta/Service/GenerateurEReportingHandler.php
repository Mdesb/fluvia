<?php

declare(strict_types=1);

namespace App\Compta\Service;

use App\Compta\Entity\DeclarationEReporting;
use App\Compta\Entity\EcritureComptable;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Entity\VenteImpayeeRegie;
use App\Compta\Enum\NatureOperation;
use App\Compta\Enum\StatutEnvoi;
use App\Compta\Regime\RegimeComptableResolver;
use Doctrine\ORM\EntityManagerInterface;

/**
 * e-reporting agrégé (US-L4-08, RG-M6-07/08/09) : agrège les `LigneEcriture` du journal ventes
 * (hors ventes marquées « ImpayeRegie »), groupées par jour × taux TVA, un seul enregistrement par
 * SIREN (RG-M6-07).
 */
final class GenerateurEReportingHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RegimeComptableResolver $resolver,
    ) {
    }

    public function preparer(ProfilExploitant $profil, \DateTimeImmutable $debut, \DateTimeImmutable $fin): DeclarationEReporting
    {
        $regime = $this->resolver->pour($profil);
        $journalVentes = $regime->journalPour($profil, NatureOperation::Ventes);
        $compteTva = $regime->compteTvaCollectee($profil);

        $impayees = array_map(
            static fn (VenteImpayeeRegie $v): string => (string) $v->getVenteOrigine(),
            $this->em->getRepository(VenteImpayeeRegie::class)->findAll(),
        );

        /** @var list<EcritureComptable> $ecritures */
        $ecritures = $this->em->getRepository(EcritureComptable::class)->createQueryBuilder('e')
            ->andWhere('IDENTITY(e.profilExploitant) = :profil')
            ->andWhere('IDENTITY(e.journal) = :journal')
            ->andWhere('e.dateEcriture >= :debut')
            ->andWhere('e.dateEcriture <= :fin')
            ->andWhere('e.pieceExtourneDe IS NULL')
            ->setParameter('profil', $profil->getId(), 'uuid')
            ->setParameter('journal', $journalVentes->getId(), 'uuid')
            ->setParameter('debut', $debut)
            ->setParameter('fin', $fin)
            ->getQuery()
            ->getResult();

        /** @var array<string, array{jour: string, tauxTvaId: string, baseHTCentimes: int, tvaCentimes: int}> $buckets */
        $buckets = [];
        foreach ($ecritures as $ecriture) {
            if (\in_array((string) $ecriture->getVenteOrigine(), $impayees, true)) {
                continue; // RG-M6-09 : anti-double-comptabilisation.
            }

            $jour = $ecriture->getDateEcriture()->format('Y-m-d');
            foreach ($ecriture->getLignes() as $ligne) {
                if ($ligne->getCreditCentimes() <= 0) {
                    continue;
                }
                $tauxId = (string) $ligne->getTauxTva()?->getId();
                $cle = $jour . '|' . $tauxId;
                $buckets[$cle] ??= ['jour' => $jour, 'tauxTvaId' => $tauxId, 'baseHTCentimes' => 0, 'tvaCentimes' => 0];

                $estTva = $ligne->getCompte() !== null && $compteTva->getId()->equals($ligne->getCompte()->getId());
                if ($estTva) {
                    $buckets[$cle]['tvaCentimes'] += $ligne->getCreditCentimes();
                } else {
                    $buckets[$cle]['baseHTCentimes'] += $ligne->getCreditCentimes();
                }
            }
        }

        $declaration = new DeclarationEReporting();
        $declaration->setProfilExploitant($profil);
        $declaration->setPeriodeDebut($debut);
        $declaration->setPeriodeFin($fin);
        $declaration->setSiren($profil->getSiren());
        $declaration->setAgregatParJourTaux(array_values($buckets));
        $declaration->setStatutEnvoi(StatutEnvoi::Prepare);

        $this->em->persist($declaration);
        $this->em->flush();

        return $declaration;
    }
}
