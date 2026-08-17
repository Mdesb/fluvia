<?php

declare(strict_types=1);

namespace App\Stock\Service;

use App\Stock\Entity\ArticleStock;
use App\Stock\Entity\ImputationLotStock as ImputationLotStockEntity;
use App\Stock\Entity\LotStock;
use App\Stock\Enum\MethodeValorisation;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Moteur de valorisation FIFO/LIFO (§2 du plan) : couches de coût (`LotStock`) triées `dateEntree`
 * (ASC=FIFO / DESC=LIFO), imputation couche par couche. Reproduit exactement les exemples chiffrés de
 * la spec §4.5 (CA-8/CA-9 : 490,00 € vs 505,00 € de coût sorti, 135,00 € vs 120,00 € de stock restant).
 * Aucun `flush()` ici (même convention que `App\Caution\Service\GestionCaution`) : les
 * handlers/listeners appelants portent la transaction.
 */
final class MoteurValorisationFifoLifo
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** Consommation générale (vente, perte/casse, ajustement négatif, sortie de transfert). */
    public function consommerSelonMethode(ArticleStock $article, string $quantite, MethodeValorisation $methode): ResultatConsommation
    {
        $lots = $this->lotsActifs($article, $methode);

        return $this->consommerLots($lots, $quantite);
    }

    /**
     * RG-STOCK-06 : retour fournisseur — retire prioritairement du lot d'origine, repli FIFO/LIFO sinon
     * (§2.3 du plan). Utilisé aussi par la régularisation d'inventaire à écart négatif (dernière couche
     * active en priorité, §2.3).
     */
    public function consommerLotPrioritaire(LotStock $lotPreferentiel, string $quantite, MethodeValorisation $methodeRepli): ResultatConsommation
    {
        $resteMilli = ArithmetiqueDecimale::versEntier($quantite, 3);
        $imputations = [];

        $dispoMilli = ArithmetiqueDecimale::versEntier($lotPreferentiel->getQuantiteRestante(), 3);
        if ($dispoMilli > 0 && $resteMilli > 0) {
            $prisMilli = min($dispoMilli, $resteMilli);
            $imputations[] = $this->imputer($lotPreferentiel, $prisMilli);
            $resteMilli -= $prisMilli;
        }

        if ($resteMilli > 0) {
            $lotsRepli = array_filter(
                $this->lotsActifs($lotPreferentiel->getArticleStock(), $methodeRepli),
                static fn (LotStock $l): bool => $l->getId()->toRfc4122() !== $lotPreferentiel->getId()->toRfc4122(),
            );
            $resultatRepli = $this->consommerLots(array_values($lotsRepli), ArithmetiqueDecimale::versDecimal($resteMilli, 3));
            $imputations = [...$imputations, ...$resultatRepli->imputations];
            $resteMilli = $resultatRepli->quantiteNonCouverte !== null ? ArithmetiqueDecimale::versEntier($resultatRepli->quantiteNonCouverte, 3) : 0;
        }

        return $this->finaliser($imputations, $resteMilli);
    }

    /** RG-STOCK-18 : valorisation courante = Σ(quantitéRestante × coûtUnitaire) des lots actifs. */
    public function valoriserCourant(ArticleStock $article): string
    {
        $lots = $this->em->getRepository(LotStock::class)->createQueryBuilder('l')
            ->andWhere('l.articleStock = :article')
            ->andWhere('l.quantiteRestante > 0')
            ->setParameter('article', $article->getId(), 'uuid')
            ->getQuery()->getResult();

        $totalCentimes = 0;
        foreach ($lots as $lot) {
            \assert($lot instanceof LotStock);
            $montant = ArithmetiqueDecimale::multiplierVersMontant($lot->getQuantiteRestante(), $lot->getCoutUnitaireHT());
            $totalCentimes += ArithmetiqueDecimale::versEntier($montant, 2);
        }

        return ArithmetiqueDecimale::versDecimal($totalCentimes, 2);
    }

    /** RG-STOCK-18 : valorisation historique à une date T (reconstruction, §2.4 du plan). */
    public function valoriserADate(ArticleStock $article, \DateTimeImmutable $date): string
    {
        $lots = $this->em->getRepository(LotStock::class)->createQueryBuilder('l')
            ->andWhere('l.articleStock = :article')
            ->andWhere('l.dateEntree <= :date')
            ->setParameter('article', $article->getId(), 'uuid')
            ->setParameter('date', $date)
            ->getQuery()->getResult();

        $totalCentimes = 0;
        foreach ($lots as $lot) {
            \assert($lot instanceof LotStock);

            // Somme des imputations dont le mouvement est daté ≤ T (SUM decimal(12,3) → string PHP,
            // reconverti en entier « millièmes » exact via ArithmetiqueDecimale, sans passage flottant).
            $consommeReel = (string) $this->em->getRepository(ImputationLotStockEntity::class)->createQueryBuilder('i')
                ->select('COALESCE(SUM(i.quantiteImputee), 0)')
                ->join('i.mouvementStock', 'm')
                ->andWhere('i.lotStock = :lot')
                ->andWhere('m.date <= :date')
                ->setParameter('lot', $lot->getId(), 'uuid')
                ->setParameter('date', $date)
                ->getQuery()->getSingleScalarResult();

            $consommeMilliExact = ArithmetiqueDecimale::versEntier($consommeReel, 3);
            $restanteMilli = ArithmetiqueDecimale::versEntier($lot->getQuantiteInitiale(), 3) - $consommeMilliExact;
            if ($restanteMilli <= 0) {
                continue;
            }
            $restanteDecimal = ArithmetiqueDecimale::versDecimal($restanteMilli, 3);
            $montant = ArithmetiqueDecimale::multiplierVersMontant($restanteDecimal, $lot->getCoutUnitaireHT());
            $totalCentimes += ArithmetiqueDecimale::versEntier($montant, 2);
        }

        return ArithmetiqueDecimale::versDecimal($totalCentimes, 2);
    }

    /** RG-STOCK-12 : coût de la dernière couche active (régularisation d'inventaire, §2.3). */
    public function coutDerniereCoucheActive(ArticleStock $article): ?string
    {
        $lot = $this->dernierLotActif($article);

        return $lot?->getCoutUnitaireHT();
    }

    public function dernierLotActif(ArticleStock $article): ?LotStock
    {
        return $this->em->getRepository(LotStock::class)->createQueryBuilder('l')
            ->andWhere('l.articleStock = :article')
            ->andWhere('l.quantiteRestante > 0')
            ->setParameter('article', $article->getId(), 'uuid')
            ->orderBy('l.dateEntree', 'DESC')
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }

    /** @return list<LotStock> */
    private function lotsActifs(?ArticleStock $article, MethodeValorisation $methode): array
    {
        if ($article === null) {
            return [];
        }
        $ordre = $methode === MethodeValorisation::Fifo ? 'ASC' : 'DESC';

        return $this->em->getRepository(LotStock::class)->createQueryBuilder('l')
            ->andWhere('l.articleStock = :article')
            ->andWhere('l.quantiteRestante > 0')
            ->setParameter('article', $article->getId(), 'uuid')
            ->orderBy('l.dateEntree', $ordre)
            ->addOrderBy('l.id', $ordre)
            ->getQuery()->getResult();
    }

    /** @param list<LotStock> $lots */
    private function consommerLots(array $lots, string $quantiteACommander): ResultatConsommation
    {
        $resteMilli = ArithmetiqueDecimale::versEntier($quantiteACommander, 3);
        $imputations = [];

        foreach ($lots as $lot) {
            if ($resteMilli <= 0) {
                break;
            }
            $dispoMilli = ArithmetiqueDecimale::versEntier($lot->getQuantiteRestante(), 3);
            if ($dispoMilli <= 0) {
                continue;
            }
            $prisMilli = min($dispoMilli, $resteMilli);
            $imputations[] = $this->imputer($lot, $prisMilli);
            $resteMilli -= $prisMilli;
        }

        return $this->finaliser($imputations, $resteMilli);
    }

    private function imputer(LotStock $lot, int $prisMilli): ImputationCalculee
    {
        $quantiteImputee = ArithmetiqueDecimale::versDecimal($prisMilli, 3);
        $dispoMilli = ArithmetiqueDecimale::versEntier($lot->getQuantiteRestante(), 3);
        $lot->setQuantiteRestante(ArithmetiqueDecimale::versDecimal($dispoMilli - $prisMilli, 3));

        return new ImputationCalculee($lot, $quantiteImputee, $lot->getCoutUnitaireHT());
    }

    /** @param list<ImputationCalculee> $imputations */
    private function finaliser(array $imputations, int $resteMilli): ResultatConsommation
    {
        $montantTotalCentimes = 0;
        foreach ($imputations as $imputation) {
            $montant = ArithmetiqueDecimale::multiplierVersMontant($imputation->quantite, $imputation->coutUnitaire);
            $montantTotalCentimes += ArithmetiqueDecimale::versEntier($montant, 2);
        }

        return new ResultatConsommation(
            ArithmetiqueDecimale::versDecimal($montantTotalCentimes, 2),
            $imputations,
            $resteMilli > 0 ? ArithmetiqueDecimale::versDecimal($resteMilli, 3) : null,
        );
    }
}
