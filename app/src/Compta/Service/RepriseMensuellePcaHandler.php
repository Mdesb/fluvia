<?php

declare(strict_types=1);

namespace App\Compta\Service;

use App\Compta\Entity\EcritureComptable;
use App\Compta\Entity\EtalementPca;
use App\Compta\Entity\LigneEcriture;
use App\Compta\Entity\MouvementPca;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Enum\FaitGenerateurPca;
use App\Compta\Enum\MethodePca;
use App\Compta\Enum\NatureOperation;
use App\Compta\Enum\StatutEcriture;
use App\Compta\Enum\TypeMouvementPca;
use App\Compta\Nf525\ScellementEcritureHandler;
use App\Compta\Regime\CompteLookupService;
use App\Compta\Regime\RegimeComptableResolver;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reprise PCA au prorata temporis (US-L4-05, RG-M6-03) : pour chaque `EtalementPca` actif en méthode
 * prorata, débite le 487 / crédite le compte produit d'une quote-part mensuelle plafonnée au
 * reste-à-servir. Idempotent par appel (une tranche par exécution et par étalement).
 */
final class RepriseMensuellePcaHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RegimeComptableResolver $resolver,
        private readonly CompteLookupService $comptes,
        private readonly ScellementEcritureHandler $scellement,
    ) {
    }

    /** @return int nombre de reprises générées */
    public function reprendre(ProfilExploitant $profil, ?\DateTimeImmutable $date = null): int
    {
        $date ??= new \DateTimeImmutable();

        /** @var list<EtalementPca> $etalements */
        $etalements = $this->em->getRepository(EtalementPca::class)->createQueryBuilder('e')
            ->andWhere('IDENTITY(e.profilExploitant) = :profil')
            ->andWhere('e.methode = :methode')
            ->andWhere('e.resteAServirCentimes > 0')
            ->setParameter('profil', $profil->getId(), 'uuid')
            ->setParameter('methode', MethodePca::ProrataTemporis)
            ->getQuery()
            ->getResult();

        $regime = $this->resolver->pour($profil);
        $journal = $regime->journalPour($profil, NatureOperation::PcaOd);
        $tauxHorsChamp = $this->comptes->tauxHorsChamp($profil);

        $nb = 0;
        foreach ($etalements as $etalement) {
            $debut = $etalement->getPeriodeServiceDebut();
            $fin = $etalement->getPeriodeServiceFin();
            if ($debut === null || $fin === null || $date < $debut) {
                continue;
            }

            $nbMois = max(1, (int) ceil($debut->diff($fin)->days / 30));
            $montantMensuel = intdiv($etalement->getMontantReporteCentimes(), $nbMois);
            $montant = min($montantMensuel > 0 ? $montantMensuel : $etalement->getResteAServirCentimes(), $etalement->getResteAServirCentimes());
            if ($montant <= 0) {
                continue;
            }

            // Cherche le compte produit initialement crédité par cet étalement (retrouvé via le mapping
            // n'étant pas conservé ici, on retombe sur le compte de report comme contrepartie de reprise
            // vers un compte produit générique 706 du profil).
            $compteProduit = $this->comptes->compteParPrefixe($profil, '706');

            $ecriture = new EcritureComptable();
            $ecriture->setProfilExploitant($profil);
            $ecriture->setJournal($journal);
            $ecriture->setPeriode($this->periodePour($profil, $date));
            $ecriture->setDateEcriture($date);
            $ecriture->setLibelle('Reprise PCA prorata temporis');
            $ecriture->setStatut(StatutEcriture::Controlee);

            $ligneDebit = new LigneEcriture();
            $ligneDebit->setCompte($etalement->getCompteReport());
            $ligneDebit->setDebitCentimes($montant);
            $ligneDebit->setCreditCentimes(0);
            $ligneDebit->setTauxTva($tauxHorsChamp);
            $ecriture->addLigne($ligneDebit);

            $ligneCredit = new LigneEcriture();
            $ligneCredit->setCompte($compteProduit);
            $ligneCredit->setDebitCentimes(0);
            $ligneCredit->setCreditCentimes($montant);
            $ligneCredit->setTauxTva($tauxHorsChamp);
            $ecriture->addLigne($ligneCredit);

            $this->scellement->sceller($ecriture);
            $this->em->persist($ecriture);

            $mouvement = new MouvementPca();
            $mouvement->setEtalement($etalement);
            $mouvement->setType(TypeMouvementPca::Reprise);
            $mouvement->setDateMouvement($date);
            $mouvement->setMontantCentimes($montant);
            $mouvement->setFaitGenerateur(FaitGenerateurPca::Periode);
            $mouvement->setEcritureLiee($ecriture);
            $this->em->persist($mouvement);

            $etalement->setResteAServirCentimes($etalement->getResteAServirCentimes() - $montant);
            ++$nb;

            // Flush immédiat : le prochain scellement (chaînage NF525) doit voir cette écriture en
            // base pour calculer un numeroSequence strictement croissant (dernierMaillon() interroge
            // la base, pas l'UnitOfWork en attente).
            $this->em->flush();
        }

        return $nb;
    }

    private function periodePour(ProfilExploitant $profil, \DateTimeImmutable $date): \App\Compta\Entity\PeriodeComptable
    {
        $periode = $this->em->getRepository(\App\Compta\Entity\PeriodeComptable::class)->createQueryBuilder('p')
            ->andWhere('IDENTITY(p.profilExploitant) = :profil')
            ->andWhere('p.dateDebut <= :date')
            ->andWhere('p.dateFin >= :date')
            ->setParameter('profil', $profil->getId(), 'uuid')
            ->setParameter('date', $date)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($periode instanceof \App\Compta\Entity\PeriodeComptable) {
            return $periode;
        }

        $nouvelle = new \App\Compta\Entity\PeriodeComptable();
        $nouvelle->setProfilExploitant($profil);
        $nouvelle->setDateDebut(new \DateTimeImmutable($date->format('Y-m-01')));
        $nouvelle->setDateFin(new \DateTimeImmutable($date->format('Y-m-t')));
        $this->em->persist($nouvelle);

        return $nouvelle;
    }
}
