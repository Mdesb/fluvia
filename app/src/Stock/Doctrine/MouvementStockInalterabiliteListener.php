<?php

declare(strict_types=1);

namespace App\Stock\Doctrine;

use App\Stock\Entity\ImputationLotStock;
use App\Stock\Entity\MouvementStock;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Garde d'inaltérabilité du journal `App\Stock` (RG-STOCK-15, RG-SOCLE-07, §0 décision n°6) — même
 * patron que `App\Vente\Nf525\InalterabiliteListener`, fichier neuf dédié au scope `App\Stock` (ne
 * modifie pas le listener NF525 de M2). `MouvementStock`/`ImputationLotStock` sont append-only : toute
 * correction passe par un nouveau mouvement compensatoire, jamais par édition/suppression.
 */
#[AsDoctrineListener(event: Events::preUpdate)]
#[AsDoctrineListener(event: Events::preRemove)]
final class MouvementStockInalterabiliteListener
{
    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $entity = $args->getObject();
        if ($this->estAppendOnly($entity)) {
            throw new ConflictHttpException('Modification interdite : mouvement de stock append-only (RG-STOCK-15).');
        }
    }

    public function preRemove(PreRemoveEventArgs $args): void
    {
        $entity = $args->getObject();
        if ($this->estAppendOnly($entity)) {
            throw new ConflictHttpException('Suppression interdite : mouvement de stock append-only (RG-STOCK-15).');
        }
    }

    private function estAppendOnly(object $entity): bool
    {
        return $entity instanceof MouvementStock || $entity instanceof ImputationLotStock;
    }
}
