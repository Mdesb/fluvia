<?php

declare(strict_types=1);

namespace App\Vente\Service;

use App\Offre\Entity\Produit;
use App\Offre\Entity\Stock;
use App\Vente\Entity\Vente;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Décrément de stock atomique à la validation (RG-M1-10, §6 du plan). Chaque ligne d'un produit géré
 * en stock est décrémentée par un UPDATE conditionnel `disponibilite >= q` sur la table de stock
 * dédiée (off_stock) ou de pool partagé (off_pool) : 0 ligne affectée ⇒ rupture ⇒ validation refusée
 * (422 « stock épuisé »), aucun ticket scellé. Ceci sérialise le décrément mutualisé au niveau SQL et
 * évite la survente concurrente inter-caisses / avec la vente en ligne (M3) sans verrou long.
 */
final class DecrementStockHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $connection,
    ) {
    }

    public function decrementer(Vente $vente): void
    {
        foreach ($vente->getLignes() as $ligne) {
            $produit = $this->em->getRepository(Produit::class)->find($ligne->getProduit());
            if (!$produit instanceof Produit) {
                continue;
            }
            $stock = $produit->getStock();
            if ($stock === null) {
                continue; // produit non géré en stock : jamais bloqué (CA-6).
            }

            $partage = $stock->getType() === Stock::TYPE_PARTAGE && $stock->getPool() !== null;
            if ($partage) {
                $table = 'off_pool';
                $hex = bin2hex($stock->getPool()->getId()->toBinary());
            } else {
                $table = 'off_stock';
                $hex = bin2hex($stock->getId()->toBinary());
            }

            // UPDATE conditionnel atomique : UNHEX pour comparer sur la clé binaire sans ambiguïté.
            $affectees = (int) $this->connection->executeStatement(
                sprintf('UPDATE %s SET disponibilite = disponibilite - :q WHERE id = UNHEX(:hex) AND disponibilite >= :q', $table),
                ['q' => $ligne->getQuantite(), 'hex' => $hex],
            );

            if ($affectees === 0) {
                throw new UnprocessableEntityHttpException('stock épuisé : décrément impossible (RG-M1-10, survente évitée).');
            }
        }
    }
}
