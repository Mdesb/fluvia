<?php

declare(strict_types=1);

namespace App\Stock\Service;

use App\Stock\Entity\ImputationLotStock;
use App\Stock\Entity\LotStock;
use App\Stock\Entity\MouvementStock;
use App\Stock\Entity\TransfertStock;
use App\Stock\Enum\OrigineLotStock;
use App\Stock\Enum\StatutTransfertStock;
use App\Stock\Enum\TypeMouvementStock;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Transfert inter-établissements (US-STOCK-10, RG-STOCK-14, CA-12) : l'expédition consomme une couche
 * côté source selon sa méthode active ; la réception crée à destination une **nouvelle** couche dont le
 * coût unitaire est celui de la couche consommée à la source (transfert de la valeur réelle, pas un
 * nouvel achat au prix courant).
 */
final class TransfertStockHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MoteurValorisationFifoLifo $moteur,
        private readonly ResolveurMethodeValorisation $resolveur,
        private readonly DisponibiliteStockHandler $disponibilite,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function expedier(TransfertStock $transfert): void
    {
        if ($transfert->getStatut() !== StatutTransfertStock::Demande) {
            throw new ConflictHttpException('Ce transfert a déjà été expédié.');
        }
        $source = $transfert->getArticleStockSource();
        \assert($source !== null);

        $methode = $this->resolveur->pour($source);
        $resultat = $this->moteur->consommerSelonMethode($source, $transfert->getQuantite(), $methode);

        $mouvement = new MouvementStock();
        $mouvement->setArticleStock($source)
            ->setEtablissement($source->getEtablissement())
            ->setType(TypeMouvementStock::SortieTransfert)
            ->setQuantite($transfert->getQuantite())
            ->setCoutUnitaireCalcule($resultat->coutUnitaireMoyen())
            ->setCoutTotalCalcule($resultat->coutTotal)
            ->setMotif('Transfert vers ' . ($transfert->getArticleStockDestination()?->getEtablissement()?->getNom() ?? 'établissement destination'))
            ->setReferenceType('TransfertStock')
            ->setReferenceId($transfert->getId());
        $this->em->persist($mouvement);

        foreach ($resultat->imputations as $imputation) {
            $ligneImputation = new ImputationLotStock();
            $ligneImputation->setMouvementStock($mouvement)
                ->setLotStock($imputation->lot)
                ->setQuantiteImputee($imputation->quantite)
                ->setCoutUnitaire($imputation->coutUnitaire);
            $this->em->persist($ligneImputation);
        }

        // §2.1 du plan : rupture de couches FIFO/LIFO — imputation partielle, non bloquante, mais
        // journalisée (ne doit pas passer silencieusement inaperçue).
        if ($resultat->quantiteNonCouverte !== null) {
            $this->logger->warning('stock.consommation.rupture_couches', [
                'articleStock' => (string) $source->getId(),
                'mouvementStock' => (string) $mouvement->getId(),
                'transfertStock' => (string) $transfert->getId(),
                'quantiteNonCouverte' => $resultat->quantiteNonCouverte,
            ]);
        }

        $this->disponibilite->decrementer($source, $transfert->getQuantite());

        $transfert->setMouvementSortie($mouvement)
            ->setStatut(StatutTransfertStock::Expedie)
            ->setDateExpedition(new \DateTimeImmutable());
    }

    public function recevoir(TransfertStock $transfert): void
    {
        if ($transfert->getStatut() !== StatutTransfertStock::Expedie) {
            throw new ConflictHttpException('Ce transfert doit être expédié avant réception.');
        }
        $destination = $transfert->getArticleStockDestination();
        $mouvementSortie = $transfert->getMouvementSortie();
        \assert($destination !== null && $mouvementSortie !== null);

        $coutUnitaire = $mouvementSortie->getCoutUnitaireCalcule() ?? '0.0000';

        $lot = new LotStock();
        $lot->setArticleStock($destination)
            ->setEtablissement($destination->getEtablissement())
            ->setDateEntree(new \DateTimeImmutable())
            ->setQuantiteInitiale($transfert->getQuantite())
            ->setQuantiteRestante($transfert->getQuantite())
            ->setCoutUnitaireHT($coutUnitaire)
            ->setOrigine(OrigineLotStock::EntreeTransfert)
            ->setReferenceOrigineType('TransfertStock')
            ->setReferenceOrigineId($transfert->getId());
        $this->em->persist($lot);

        $mouvement = new MouvementStock();
        $mouvement->setArticleStock($destination)
            ->setEtablissement($destination->getEtablissement())
            ->setType(TypeMouvementStock::EntreeTransfert)
            ->setQuantite($transfert->getQuantite())
            ->setCoutUnitaireCalcule($coutUnitaire)
            ->setCoutTotalCalcule(ArithmetiqueDecimale::multiplierVersMontant($transfert->getQuantite(), $coutUnitaire))
            ->setMotif('Transfert depuis ' . ($transfert->getArticleStockSource()?->getEtablissement()?->getNom() ?? 'établissement source'))
            ->setReferenceType('TransfertStock')
            ->setReferenceId($transfert->getId());
        $this->em->persist($mouvement);

        $this->disponibilite->incrementer($destination, $transfert->getQuantite());

        $transfert->setMouvementEntree($mouvement)
            ->setStatut(StatutTransfertStock::Recu)
            ->setDateReception(new \DateTimeImmutable());
    }
}
