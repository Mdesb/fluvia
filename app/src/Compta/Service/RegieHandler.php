<?php

declare(strict_types=1);

namespace App\Compta\Service;

use App\Compta\Entity\BordereauVersement;
use App\Compta\Entity\EcritureComptable;
use App\Compta\Entity\LigneEcriture;
use App\Compta\Entity\RegieRecettes;
use App\Compta\Enum\StatutEcriture;
use App\Compta\Nf525\ScellementEcritureHandler;
use App\Compta\Regime\RegimeComptableResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Régie de recettes (US-L4-02, RG-REGIE-02, RG-M6-10). `enregistrerEncaissement` incrémente le solde
 * d'encaisse (alerte bloquante au dépassement, CA-4) ; `enregistrerVersement` crée un bordereau daté,
 * décrémente le solde et génère l'écriture correspondante (CA-5).
 */
final class RegieHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RegimeComptableResolver $resolver,
        private readonly ScellementEcritureHandler $scellement,
    ) {
    }

    /** RG-M6-10 : incrémente le solde d'encaisse ; l'appelant doit vérifier `depassePlafond()` après appel. */
    public function enregistrerEncaissement(RegieRecettes $regie, int $montantCentimes): void
    {
        if ($montantCentimes <= 0) {
            throw new UnprocessableEntityHttpException('Le montant encaissé doit être strictement positif.');
        }
        $regie->setSoldeEncaisseCentimes($regie->getSoldeEncaisseCentimes() + $montantCentimes);
        $this->em->flush();
    }

    /**
     * @param list<string>|null $justificatifs
     */
    public function enregistrerVersement(RegieRecettes $regie, int $montantCentimes, ?array $justificatifs, ?\DateTimeImmutable $date = null): BordereauVersement
    {
        if ($montantCentimes <= 0) {
            throw new UnprocessableEntityHttpException('Le montant du versement doit être strictement positif.');
        }
        if ($montantCentimes > $regie->getSoldeEncaisseCentimes()) {
            throw new ConflictHttpException('Le montant du versement excède le solde d\'encaisse de la régie.');
        }

        $bordereau = new BordereauVersement();
        $bordereau->setRegie($regie);
        $bordereau->setDateVersement($date ?? new \DateTimeImmutable());
        $bordereau->setMontantCentimes($montantCentimes);
        $bordereau->setJustificatifs($justificatifs);

        $profil = $regie->getProfilExploitant();
        $regime = $this->resolver->pour($profil);
        $dto = $regime->genererEcritureRegie($bordereau);

        $ecriture = new EcritureComptable();
        $ecriture->setProfilExploitant($profil);
        $ecriture->setJournal($regime->journalPour($profil, \App\Compta\Enum\NatureOperation::Regie));
        $ecriture->setPeriode($this->periodePour($profil, $bordereau->getDateVersement()));
        $ecriture->setDateEcriture($bordereau->getDateVersement());
        $ecriture->setLibelle($dto->libelle);
        $ecriture->setStatut(StatutEcriture::Controlee);

        foreach ($dto->lignes as $ligneDto) {
            $ligne = new LigneEcriture();
            $ligne->setCompte($ligneDto->compte);
            $ligne->setDebitCentimes($ligneDto->debitCentimes);
            $ligne->setCreditCentimes($ligneDto->creditCentimes);
            $ligne->setTauxTva($ligneDto->tauxTva);
            $ligne->setLibelle($ligneDto->libelle);
            $ecriture->addLigne($ligne);
        }

        $this->scellement->sceller($ecriture);
        $this->em->persist($ecriture);
        $bordereau->setEcritureGeneree($ecriture);

        $regie->setSoldeEncaisseCentimes($regie->getSoldeEncaisseCentimes() - $montantCentimes);

        $this->em->persist($bordereau);
        $this->em->flush();

        return $bordereau;
    }

    private function periodePour(\App\Compta\Entity\ProfilExploitant $profil, \DateTimeImmutable $date): \App\Compta\Entity\PeriodeComptable
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
