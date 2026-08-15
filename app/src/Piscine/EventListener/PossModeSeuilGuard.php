<?php

declare(strict_types=1);

namespace App\Piscine\EventListener;

use App\Acces\Entity\EspaceAcces;
use App\Acces\Enum\ModeSeuil;
use App\Piscine\Entity\Poss;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Garde additive « mode blocage strict imposé » (RG-PISC-01, plan §2.2) : une `Poss` piscine doit
 * toujours référencer un `EspaceAcces` L3 en `modeSeuil = blocage` (jamais `alerte`). N'ajoute ni ne
 * modifie aucun fichier `App\Acces\*` — lecture seule de `EspaceAcces` — c'est le seul point
 * d'extension nécessaire pour imposer le blocage strict côté piscine (le générique L3 reste
 * paramétrable blocage/alerte pour les autres verticales).
 */
#[AsDoctrineListener(event: Events::onFlush)]
final class PossModeSeuilGuard
{
    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof Poss) {
                $this->verifierPoss($entity);
            }
        }

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if ($entity instanceof Poss) {
                $this->verifierPoss($entity);
            }
            if ($entity instanceof EspaceAcces && $entity->getModeSeuil() !== ModeSeuil::Blocage) {
                $poss = $em->getRepository(Poss::class)->findOneBy(['espaceAcces' => $entity]);
                if ($poss instanceof Poss) {
                    throw new UnprocessableEntityHttpException(
                        'RG-PISC-01 : une POSS piscine doit être en blocage strict, jamais en alerte simple.',
                    );
                }
            }
        }
    }

    private function verifierPoss(Poss $poss): void
    {
        if ($poss->getEspaceAcces()?->getModeSeuil() !== ModeSeuil::Blocage) {
            throw new UnprocessableEntityHttpException(
                'RG-PISC-01 : une POSS piscine doit être en blocage strict, jamais en alerte simple.',
            );
        }
    }
}
