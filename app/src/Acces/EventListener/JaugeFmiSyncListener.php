<?php

declare(strict_types=1);

namespace App\Acces\EventListener;

use App\Acces\Entity\EspaceAcces;
use App\Acces\Entity\JaugeFmi;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * Maintient `JaugeFmi` en miroir de `EspaceAcces` (seuil, mode) : une jauge est créée à la création
 * de l'espace (1-1, §1.5 du plan) et son seuil/mode resynchronisés à la modification de l'espace.
 * `valeurCourante`/`cumulJour` restent uniquement pilotés par les UPDATE atomiques (§4.3/4.5).
 */
#[AsDoctrineListener(event: Events::onFlush)]
final class JaugeFmiSyncListener
{
    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();
        $metadata = $em->getClassMetadata(JaugeFmi::class);

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if (!$entity instanceof EspaceAcces) {
                continue;
            }
            $jauge = $em->getRepository(JaugeFmi::class)->findOneBy(['espace' => $entity]);
            if ($jauge instanceof JaugeFmi) {
                continue;
            }
            $jauge = new JaugeFmi();
            $jauge->setEspace($entity)->setSeuil($entity->getSeuilFmi())->setMode($entity->getModeSeuil());
            $em->persist($jauge);
            $uow->computeChangeSet($metadata, $jauge);
        }

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if (!$entity instanceof EspaceAcces) {
                continue;
            }
            $jauge = $em->getRepository(JaugeFmi::class)->findOneBy(['espace' => $entity]);
            if (!$jauge instanceof JaugeFmi) {
                continue;
            }
            $jauge->setSeuil($entity->getSeuilFmi())->setMode($entity->getModeSeuil());
            $uow->recomputeSingleEntityChangeSet($metadata, $jauge);
        }
    }
}
