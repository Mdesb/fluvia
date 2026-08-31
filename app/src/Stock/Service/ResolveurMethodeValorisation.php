<?php

declare(strict_types=1);

namespace App\Stock\Service;

use App\Stock\Entity\ArticleStock;
use App\Stock\Entity\ParametrageStock;
use App\Stock\Enum\MethodeValorisation;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Méthode de valorisation effective d'un article (RG-STOCK-08, §2.2 du plan) : surcharge par article,
 * sinon héritée du paramétrage de l'établissement, sinon FIFO par défaut si aucun paramétrage n'existe.
 */
final class ResolveurMethodeValorisation
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StockSettingsProvider $reglages,
    ) {
    }

    public function pour(ArticleStock $article): MethodeValorisation
    {
        if ($article->getMethodeValorisation() !== null) {
            return $article->getMethodeValorisation();
        }

        $etablissement = $article->getEtablissement();
        if ($etablissement === null) {
            return MethodeValorisation::Fifo;
        }

        // Le repli FIFO est desormais declare une seule fois, dans `StockSettings` (D52) : ce service
        // ne decide plus ce que signifie l'absence de parametrage, il la lit.
        return $this->reglages->forEstablishment($etablissement)->valuationMethod();
    }
}
