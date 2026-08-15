<?php

declare(strict_types=1);

namespace App\Compta\Service;

use App\Compta\Entity\EcritureComptable;
use App\Compta\Entity\EtalementPca;
use App\Compta\Entity\LigneEcriture;
use App\Compta\Entity\MouvementPca;
use App\Compta\Entity\PeriodeComptable;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Enum\FaitGenerateurPca;
use App\Compta\Enum\MethodePca;
use App\Compta\Enum\NatureOperation;
use App\Compta\Enum\StatutEcriture;
use App\Compta\Enum\TypeMouvementPca;
use App\Compta\Nf525\ScellementEcritureHandler;
use App\Compta\Port\ProjectionPassageInterface;
use App\Compta\Regime\CompteLookupService;
use App\Compta\Regime\RegimeComptableResolver;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reprise PCA « au passage » (carte multi-entrées, RG-M6-03, §7.3 du plan) : une reprise par entrée
 * consommée remontée par le module Accès (cumul de passages uniquement, jamais la jauge FMI).
 */
final class RepriseAuPassageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProjectionPassageInterface $passages,
        private readonly RegimeComptableResolver $resolver,
        private readonly CompteLookupService $comptes,
        private readonly ScellementEcritureHandler $scellement,
    ) {
    }

    public function reprendre(EtalementPca $etalement): int
    {
        if ($etalement->getMethode() !== MethodePca::AuPassage || $etalement->getIdentifiantSupport() === null) {
            return 0;
        }
        if ($etalement->getResteAServirCentimes() <= 0) {
            return 0;
        }

        $nbUnites = $etalement->getNbUnitesCarte() ?? 1;
        $montantParPassage = intdiv($etalement->getMontantReporteCentimes(), max(1, $nbUnites));

        $nbDejaRepris = (int) $this->em->getRepository(MouvementPca::class)->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->andWhere('IDENTITY(m.etalement) = :etalement')
            ->andWhere('m.type = :type')
            ->andWhere('m.faitGenerateur = :fait')
            ->setParameter('etalement', $etalement->getId(), 'uuid')
            ->setParameter('type', TypeMouvementPca::Reprise)
            ->setParameter('fait', FaitGenerateurPca::Passage)
            ->getQuery()
            ->getSingleScalarResult();

        $nbPassages = $this->passages->compterPassagesAutorises(
            $etalement->getIdentifiantSupport(),
            $etalement->getPeriodeServiceDebut() ?? new \DateTimeImmutable('-10 years'),
        );

        $aReprendre = max(0, min($nbPassages - $nbDejaRepris, $nbUnites - $nbDejaRepris));
        if ($aReprendre <= 0) {
            return 0;
        }

        $profil = $etalement->getProfilExploitant();
        $regime = $this->resolver->pour($profil);
        $journal = $regime->journalPour($profil, NatureOperation::PcaOd);
        $tauxHorsChamp = $this->comptes->tauxHorsChamp($profil);
        $compteProduit = $this->comptes->compteParPrefixe($profil, '706');

        $nb = 0;
        for ($i = 0; $i < $aReprendre; ++$i) {
            $montant = min($montantParPassage, $etalement->getResteAServirCentimes());
            if ($montant <= 0) {
                break;
            }

            $ecriture = new EcritureComptable();
            $ecriture->setProfilExploitant($profil);
            $ecriture->setJournal($journal);
            $ecriture->setPeriode($this->periodePour($profil, new \DateTimeImmutable()));
            $ecriture->setDateEcriture(new \DateTimeImmutable());
            $ecriture->setLibelle('Reprise PCA au passage');
            $ecriture->setStatut(StatutEcriture::Controlee);

            $ligneDebit = new LigneEcriture();
            $ligneDebit->setCompte($etalement->getCompteReport());
            $ligneDebit->setDebitCentimes($montant);
            $ligneDebit->setTauxTva($tauxHorsChamp);
            $ecriture->addLigne($ligneDebit);

            $ligneCredit = new LigneEcriture();
            $ligneCredit->setCompte($compteProduit);
            $ligneCredit->setCreditCentimes($montant);
            $ligneCredit->setTauxTva($tauxHorsChamp);
            $ecriture->addLigne($ligneCredit);

            $this->scellement->sceller($ecriture);
            $this->em->persist($ecriture);

            $mouvement = new MouvementPca();
            $mouvement->setEtalement($etalement);
            $mouvement->setType(TypeMouvementPca::Reprise);
            $mouvement->setDateMouvement(new \DateTimeImmutable());
            $mouvement->setMontantCentimes($montant);
            $mouvement->setFaitGenerateur(FaitGenerateurPca::Passage);
            $mouvement->setEcritureLiee($ecriture);
            $this->em->persist($mouvement);

            $etalement->setResteAServirCentimes($etalement->getResteAServirCentimes() - $montant);
            ++$nb;

            // Flush immédiat : le prochain scellement (chaînage NF525, §6 du plan) doit voir cette
            // écriture en base pour calculer un numeroSequence strictement croissant (dernierMaillon()
            // interroge la base, pas l'UnitOfWork en attente).
            $this->em->flush();
        }

        return $nb;
    }

    private function periodePour(ProfilExploitant $profil, \DateTimeImmutable $date): PeriodeComptable
    {
        $periode = $this->em->getRepository(PeriodeComptable::class)->createQueryBuilder('p')
            ->andWhere('IDENTITY(p.profilExploitant) = :profil')
            ->andWhere('p.dateDebut <= :date')
            ->andWhere('p.dateFin >= :date')
            ->setParameter('profil', $profil->getId(), 'uuid')
            ->setParameter('date', $date)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($periode instanceof PeriodeComptable) {
            return $periode;
        }

        $nouvelle = new PeriodeComptable();
        $nouvelle->setProfilExploitant($profil);
        $nouvelle->setDateDebut(new \DateTimeImmutable($date->format('Y-m-01')));
        $nouvelle->setDateFin(new \DateTimeImmutable($date->format('Y-m-t')));
        $this->em->persist($nouvelle);

        return $nouvelle;
    }
}
