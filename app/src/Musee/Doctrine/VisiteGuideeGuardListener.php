<?php

declare(strict_types=1);

namespace App\Musee\Doctrine;

use App\Musee\Entity\VisiteGuidee;
use App\Musee\Service\GuideDisponibiliteGuard;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Applique `GuideDisponibiliteGuard` à la création et à la modification d'une `VisiteGuidee`
 * (POST/PATCH, RG-MUS-02) — même patron additif que `App\Piscine\EventListener\PossModeSeuilGuard`.
 */
#[AsDoctrineListener(event: Events::onFlush)]
final class VisiteGuideeGuardListener
{
    public function __construct(
        private readonly GuideDisponibiliteGuard $guard,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $uow = $args->getObjectManager()->getUnitOfWork();

        foreach ([...$uow->getScheduledEntityInsertions(), ...$uow->getScheduledEntityUpdates()] as $entity) {
            if (!$entity instanceof VisiteGuidee) {
                continue;
            }
            $guide = $entity->getGuide();
            $creneau = $entity->getCreneauVisite();
            if ($guide === null || $creneau === null) {
                continue;
            }
            if ($this->guard->enConflit($guide, $creneau->getDebut(), $creneau->getFin(), $entity->getId())) {
                throw new ConflictHttpException('RG-MUS-02 : ce guide est déjà affecté sur une visite dont le créneau chevauche celui-ci.');
            }
        }
    }
}
