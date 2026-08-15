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
use App\Compta\Enum\NaturePca;
use App\Compta\Enum\NatureOperation;
use App\Compta\Enum\StatutEcriture;
use App\Compta\Enum\StatutPeriode;
use App\Compta\Enum\TypeMouvementPca;
use App\Compta\Nf525\ScellementEcritureHandler;
use App\Compta\Port\ProjectionVenteInterface;
use App\Compta\Regime\Dto\EcritureADto;
use App\Compta\Regime\Dto\LigneEcritureADto;
use App\Compta\Regime\Dto\VenteProjectionDto;
use App\Compta\Regime\RegimeComptableResolver;
use App\Offre\Enum\ReglePca;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Génération des écritures depuis M2 (RG-COMPTA-04, §2 du plan). **Idempotent et rejouable** : ne
 * recrée jamais une écriture pour une vente déjà comptabilisée (filtrage porté par
 * `ProjectionVenteInterface`). Utilisé par la commande CLI planifiée et par l'action API
 * `POST /compta/ecritures/generer` (même handler).
 */
final class GenerateurEcrituresHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProjectionVenteInterface $projectionVente,
        private readonly RegimeComptableResolver $resolver,
        private readonly MappingComptableGuard $mappingGuard,
        private readonly ScellementEcritureHandler $scellement,
    ) {
    }

    /**
     * @return array{ecrituresGenerees: int, extournesGenerees: int, anomalies: list<array{vente: string, anomalies: list<string>}>}
     */
    public function generer(ProfilExploitant $profil): array
    {
        $regime = $this->resolver->pour($profil);
        $mappingResolveur = $this->mappingGuard->resolveur($profil);

        $genereesVentes = 0;
        $anomaliesGlobales = [];

        foreach ($this->projectionVente->ventesValideesNonComptabilisees($profil) as $venteDto) {
            $anomalies = $this->mappingGuard->anomalies($profil, $venteDto);
            if ($anomalies !== []) {
                $anomaliesGlobales[] = ['vente' => $venteDto->numero, 'anomalies' => $anomalies];
                continue;
            }

            $dto = $regime->genererEcritureVente($venteDto, $profil, $mappingResolveur);
            if ($dto->lignes === []) {
                continue;
            }
            if (!$dto->estEquilibree()) {
                $anomaliesGlobales[] = ['vente' => $venteDto->numero, 'anomalies' => ['Écriture déséquilibrée générée par le régime (anomalie interne).']];
                continue;
            }

            $ecriture = $this->persisterEcriture($profil, $regime->journalPour($profil, NatureOperation::Ventes), $dto);
            $this->traiterPca($profil, $regime, $venteDto, $ecriture, $mappingResolveur);
            ++$genereesVentes;
        }

        $genereesExtournes = 0;
        foreach ($this->projectionVente->avoirsNonComptabilises($profil) as $avoirDto) {
            $origine = $this->em->getRepository(EcritureComptable::class)->findOneBy([
                'profilExploitant' => $profil->getId(),
                'venteOrigine' => $avoirDto->venteOrigine,
                'pieceExtourneDe' => null,
            ]);
            if ($origine === null) {
                continue;
            }

            $dto = $regime->genererEcritureExtourne($origine, $avoirDto);
            if ($dto->lignes === [] || !$dto->estEquilibree()) {
                continue;
            }

            $extourne = $this->persisterEcriture($profil, $regime->journalPour($profil, NatureOperation::Extourne), $dto);
            $extourne->setPieceExtourneDe($origine);
            ++$genereesExtournes;
        }

        $this->em->flush();

        return [
            'ecrituresGenerees' => $genereesVentes,
            'extournesGenerees' => $genereesExtournes,
            'anomalies' => $anomaliesGlobales,
        ];
    }

    private function persisterEcriture(ProfilExploitant $profil, \App\Compta\Entity\Journal $journal, EcritureADto $dto): EcritureComptable
    {
        $ecriture = new EcritureComptable();
        $ecriture->setProfilExploitant($profil);
        $ecriture->setJournal($journal);
        $ecriture->setPeriode($this->periodePour($profil, $dto->dateEcriture));
        $ecriture->setDateEcriture($dto->dateEcriture);
        $ecriture->setLibelle($dto->libelle);
        $ecriture->setVenteOrigine($dto->venteOrigine);
        $ecriture->setStatut(StatutEcriture::Controlee);

        foreach ($dto->lignes as $ligneDto) {
            $ligne = new LigneEcriture();
            $ligne->setCompte($ligneDto->compte);
            $ligne->setDebitCentimes($ligneDto->debitCentimes);
            $ligne->setCreditCentimes($ligneDto->creditCentimes);
            $ligne->setTauxTva($ligneDto->tauxTva);
            $ligne->setAxeSite($ligneDto->axeSite);
            $ligne->setAxeActivite($ligneDto->axeActivite);
            $ligne->setAxeFinanceur($ligneDto->axeFinanceur);
            $ligne->setLibelle($ligneDto->libelle);
            $ecriture->addLigne($ligne);
        }

        $this->scellement->sceller($ecriture);
        $this->em->persist($ecriture);
        // Flush immédiat : le prochain scellement (même journal, éventuellement dans la même boucle)
        // doit voir cette écriture en base pour calculer un numeroSequence strictement croissant
        // (dernierMaillon() interroge la base, pas l'UnitOfWork en attente, §6 du plan).
        $this->em->flush();

        return $ecriture;
    }

    /** Trouve (ou crée) la période comptable ouverte couvrant la date (garde légère, pas de trou). */
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
        $nouvelle->setStatut(StatutPeriode::Ouverte);
        $this->em->persist($nouvelle);

        return $nouvelle;
    }

    /**
     * RG-M6-02 : dotation 487 pour chaque ligne à étaler/consommer (pcaActif=true). Crée l'étalement
     * s'il n'existe pas déjà pour ce (produit, vente).
     */
    private function traiterPca(ProfilExploitant $profil, \App\Compta\Regime\RegimeComptableInterface $regime, VenteProjectionDto $venteDto, EcritureComptable $ecriture, \App\Compta\Regime\MappingResolver $mappingResolveur): void
    {
        if (!$profil->getParametresRegime()->pcaActif) {
            return;
        }

        foreach ($venteDto->lignes as $ligneVente) {
            if ($ligneVente->reglePca === ReglePca::Aucune) {
                continue;
            }
            if ($ligneVente->categorieComptable === null) {
                continue;
            }

            $existant = $this->em->getRepository(EtalementPca::class)->findOneBy([
                'profilExploitant' => $profil->getId(),
                'venteOrigine' => $venteDto->id,
                'produit' => $ligneVente->produit,
            ]);
            if ($existant !== null) {
                continue;
            }

            $mapping = $mappingResolveur->pour($ligneVente->categorieComptable);
            if ($mapping === null || !$mapping->estValide()) {
                continue;
            }

            // Montant HT reporté au 487 (la TVA a déjà été collectée à l'encaissement, cf. RegimeBase).
            $tauxValeur = (float) $mapping->getTauxTva()->getTaux();
            $ttc = $ligneVente->montantTtcCentimes;
            $tva = (int) round($ttc * $tauxValeur / (100 + $tauxValeur));
            $montantHt = $ttc - $tva;
            if ($montantHt <= 0) {
                continue;
            }

            $etalement = new EtalementPca();
            $etalement->setProfilExploitant($profil);
            $etalement->setProduit($ligneVente->produit);
            $etalement->setVenteOrigine($venteDto->id);
            $etalement->setCompteReport($regime->compteAttente487($profil, null));
            $etalement->setMontantReporteCentimes($montantHt);
            $etalement->setResteAServirCentimes($montantHt);

            if ($ligneVente->reglePca === ReglePca::Etalement) {
                $etalement->setNature(NaturePca::AEtaler);
                $etalement->setMethode(MethodePca::ProrataTemporis);
                $etalement->setPeriodeServiceDebut($venteDto->date);
                $duree = $ligneVente->dureeValidite ?? new \DateInterval('P1Y');
                $etalement->setPeriodeServiceFin((clone $venteDto->date)->add($duree));
            } else {
                $etalement->setNature(NaturePca::ALaConsommation);
                $etalement->setMethode(MethodePca::AuPassage);
                $etalement->setNbUnitesCarte($ligneVente->nbCrediteCarte ?? 1);
                $etalement->setIdentifiantSupport($ligneVente->identifiantSupport);
            }

            $this->em->persist($etalement);

            $mouvement = new MouvementPca();
            $mouvement->setEtalement($etalement);
            $mouvement->setType(TypeMouvementPca::Dotation);
            $mouvement->setDateMouvement($venteDto->date);
            $mouvement->setMontantCentimes($montantHt);
            $mouvement->setFaitGenerateur(FaitGenerateurPca::Encaissement);
            $mouvement->setEcritureLiee($ecriture);
            $this->em->persist($mouvement);
        }
    }
}
