<?php

declare(strict_types=1);

namespace App\Musee\Doctrine;

use App\Musee\Entity\Exposition;
use App\Reservation\Entity\Creneau;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Garde additive « fermeture automatique hors période » (CA-9, RG-MUS-06, plan §1.3). Écoute
 * `prePersist` de `App\Reservation\Entity\Creneau` (module socle Réservation) : refuse la création
 * d'un `Creneau` dont `debut` est hors `[Exposition.dateDebut, dateFin]` lorsque la ressource porte
 * l'exposition (`Exposition.ressourceEntree`). Ne modifie **aucun** fichier `App\Reservation\*` —
 * lecture seule, même patron additif que `App\Piscine\EventListener\PossModeSeuilGuard` et
 * `App\Patinoire\Doctrine\VerificateurFenetreSaisonEphemereListener`.
 */
#[AsDoctrineListener(event: Events::prePersist)]
final class FenetreExpositionGuard
{
    public function prePersist(PrePersistEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof Creneau) {
            return;
        }

        $ressource = $entity->getRessource();
        if ($ressource === null) {
            return;
        }

        $em = $args->getObjectManager();
        $exposition = $em->getRepository(Exposition::class)->findOneBy(['ressourceEntree' => $ressource]);
        if (!$exposition instanceof Exposition) {
            return;
        }

        $debut = $entity->getDebut();
        if (!$exposition->venteOuverteA($debut)) {
            throw new UnprocessableEntityHttpException(sprintf(
                'RG-MUS-06 : aucun créneau ne peut être proposé hors de la période de l\'exposition « %s » (%s → %s).',
                (string) $exposition->getId(),
                $exposition->getDateDebut()?->format('Y-m-d'),
                $exposition->getDateFin()?->format('Y-m-d'),
            ));
        }
    }
}
