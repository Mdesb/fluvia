<?php

declare(strict_types=1);

namespace App\Stock\Doctrine;

use App\Stock\Entity\ArticleStock;
use App\Stock\Entity\ImputationLotStock;
use App\Stock\Entity\MouvementStock;
use App\Stock\Enum\TypeMouvementStock;
use App\Stock\Service\MoteurValorisationFifoLifo;
use App\Stock\Service\ResolveurMethodeValorisation;
use App\Vente\Entity\LigneVente;
use App\Vente\Entity\Vente;
use App\Vente\Enum\StatutVente;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;

/**
 * Journalise le mouvement de sortie de stock détaillé (coût FIFO/LIFO, lots imputés) à la validation
 * d'une `Vente` M2, **sans jamais réécrire** `off_stock.disponibilite` (déjà décrémentée par
 * `App\Vente\Service\DecrementStockHandler`, code réel inchangé) — §3.2 du plan, RG-STOCK-01/17. Même
 * patron exact que `App\Audit\Doctrine\AuditWriteSubscriber` (`Events::onFlush`) : observe strictement
 * le changement d'état persisté `Vente.statut → Validee`, aucune modification de `App\Vente\*`.
 */
#[AsDoctrineListener(event: Events::onFlush)]
final class SortieVenteStockSubscriber
{
    public function __construct(
        private readonly MoteurValorisationFifoLifo $moteur,
        private readonly ResolveurMethodeValorisation $resolveur,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        /** @var EntityManagerInterface $em */
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if (!$entity instanceof Vente) {
                continue;
            }
            $changeSet = $uow->getEntityChangeSet($entity);
            if (!isset($changeSet['statut']) || !$this->estDevenueValidee($changeSet['statut'][1])) {
                continue; // ne traite que la transition EnCours → Validee (unique par construction, RG-M2-03).
            }

            foreach ($entity->getLignes() as $ligne) {
                $this->journaliserLigne($em, $ligne);
            }
        }
    }

    /**
     * Le changeset d'un champ `enumType` peut contenir soit l'instance de l'enum, soit sa valeur
     * scalaire brute selon le point du cycle de vie Doctrine où il est lu (constaté empiriquement) :
     * les deux représentations sont acceptées ici.
     */
    private function estDevenueValidee(mixed $nouveauStatut): bool
    {
        if ($nouveauStatut instanceof StatutVente) {
            return $nouveauStatut === StatutVente::Validee;
        }

        return $nouveauStatut === StatutVente::Validee->value;
    }

    private function journaliserLigne(EntityManagerInterface $em, LigneVente $ligne): void
    {
        $uow = $em->getUnitOfWork();

        /** @var ArticleStock|null $article */
        $article = $em->getRepository(ArticleStock::class)->findOneBy(['produit' => $ligne->getProduit()]);
        if ($article === null) {
            return; // produit non géré en stock (RG-M2-04) : rien à journaliser.
        }

        $methode = $this->resolveur->pour($article);
        $resultat = $this->moteur->consommerSelonMethode($article, (string) $ligne->getQuantite(), $methode);

        $mouvement = new MouvementStock();
        $mouvement->setArticleStock($article)
            ->setEtablissement($article->getEtablissement())
            ->setType(TypeMouvementStock::SortieVente)
            ->setQuantite((string) $ligne->getQuantite())
            ->setCoutUnitaireCalcule($resultat->coutUnitaireMoyen())
            ->setCoutTotalCalcule($resultat->coutTotal)
            ->setReferenceType('LigneVente')
            ->setReferenceId($ligne->getId());
        $em->persist($mouvement);
        $uow->computeChangeSet($em->getClassMetadata(MouvementStock::class), $mouvement);

        // §2.1 du plan : rupture de couches FIFO/LIFO — imputation partielle, non bloquante, mais
        // journalisée (ne doit pas passer silencieusement inaperçue).
        if ($resultat->quantiteNonCouverte !== null) {
            $this->logger->warning('stock.consommation.rupture_couches', [
                'articleStock' => (string) $article->getId(),
                'mouvementStock' => (string) $mouvement->getId(),
                'ligneVente' => (string) $ligne->getId(),
                'quantiteNonCouverte' => $resultat->quantiteNonCouverte,
            ]);
        }

        foreach ($resultat->imputations as $imputation) {
            $ligneImputation = new ImputationLotStock();
            $ligneImputation->setMouvementStock($mouvement)
                ->setLotStock($imputation->lot)
                ->setQuantiteImputee($imputation->quantite)
                ->setCoutUnitaire($imputation->coutUnitaire);
            $em->persist($ligneImputation);
            $uow->computeChangeSet($em->getClassMetadata(ImputationLotStock::class), $ligneImputation);
        }

        foreach ($resultat->lotsModifies() as $lot) {
            // Déjà managé (chargé par le moteur), quantiteRestante modifiée après le calcul initial des
            // change sets : recomputeSingleEntityChangeSet le prend en compte dans ce même flush.
            $uow->recomputeSingleEntityChangeSet($em->getClassMetadata($lot::class), $lot);
        }
    }
}
