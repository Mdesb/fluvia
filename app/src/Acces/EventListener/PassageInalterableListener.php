<?php

declare(strict_types=1);

namespace App\Acces\EventListener;

use App\Acces\Entity\Passage;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;

/**
 * Garde d'inaltérabilité du journal des passages (CA-14, RG-SOCLE-07) : `Passage` est append-only —
 * aucune modification ni suppression n'est permise au niveau ORM (même pattern que M2 §2,
 * `InalterabiliteListener`). Toute tentative lève `PassageInalterableException`.
 */
#[AsDoctrineListener(event: Events::preUpdate)]
#[AsDoctrineListener(event: Events::preRemove)]
final class PassageInalterableListener
{
    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $entity = $args->getObject();
        if ($entity instanceof Passage) {
            throw new PassageInalterableException('Modification interdite : le passage est append-only (CA-14, RG-SOCLE-07).');
        }
    }

    public function preRemove(PreRemoveEventArgs $args): void
    {
        $entity = $args->getObject();
        if ($entity instanceof Passage) {
            throw new PassageInalterableException('Suppression interdite : le passage est append-only (CA-14, RG-SOCLE-07).');
        }
    }
}
