<?php

declare(strict_types=1);

namespace App\Stock\Service;

use App\Offre\Entity\Stock;
use App\Stock\Entity\ArticleStock;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Alimente `Stock.disponibilité` M1 (§3.1 du plan) — même patron exact que
 * `App\Vente\Service\DecrementStockHandler` : écriture directe DBAL (pas l'ORM) sur `off_stock`, dans
 * la même transaction que le `flush()` Doctrine qui suit (le handler appelant ne flush jamais avant).
 * Résolution de la cible : `ArticleStock.produit?.getStock()` — si absent, aucune écriture (article non
 * catalogué, traçabilité conservée côté `LotStock`/`MouvementStock` seuls, §3.1).
 *
 * ⚠ Divergence documentée : `off_stock.disponibilite` est une colonne `INT` côté M1 (code réel lu,
 * `App\Offre\Entity\Stock::$disponibilite`), alors que les quantités `App\Stock` sont `decimal(12,3)`
 * (kg/litre fractionnables, RG-STOCK-08). Ce handler arrondit à l'entier le plus proche à l'écriture
 * M1 — limite acceptée pour les articles à l'unité (« pièce », majorité des cas boutique) ; une
 * évolution du schéma M1 serait nécessaire pour un compteur fractionnable exact (hors périmètre de ce
 * lot, cf. Risque n°4 du plan sur les limites du schéma M1 actuel).
 */
final class DisponibiliteStockHandler
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function incrementer(ArticleStock $article, string $quantite): void
    {
        $stock = $this->resoudreStock($article);
        if ($stock === null) {
            return;
        }

        $this->connection->executeStatement(
            'UPDATE off_stock SET disponibilite = disponibilite + :q WHERE id = UNHEX(:hex)',
            ['q' => $this->versEntier($quantite), 'hex' => $this->hex($stock)],
        );
    }

    /** @throws UnprocessableEntityHttpException si le décrément ferait passer la disponibilité sous 0 (sauf $negatifAutorise). */
    public function decrementer(ArticleStock $article, string $quantite, bool $negatifAutorise = false): void
    {
        $stock = $this->resoudreStock($article);
        if ($stock === null) {
            return;
        }

        $affectees = (int) $this->connection->executeStatement(
            'UPDATE off_stock SET disponibilite = disponibilite - :q WHERE id = UNHEX(:hex) AND (disponibilite >= :q OR :negatif = 1)',
            ['q' => $this->versEntier($quantite), 'hex' => $this->hex($stock), 'negatif' => $negatifAutorise ? 1 : 0],
        );

        if ($affectees === 0) {
            throw new UnprocessableEntityHttpException('Stock insuffisant pour ce mouvement (RG-STOCK-16, disponibilité M1).');
        }
    }

    private function resoudreStock(ArticleStock $article): ?Stock
    {
        return $article->getProduit()?->getStock();
    }

    private function hex(Stock $stock): string
    {
        return bin2hex($stock->getId()->toBinary());
    }

    private function versEntier(string $quantite): int
    {
        return (int) round((float) $quantite);
    }
}
