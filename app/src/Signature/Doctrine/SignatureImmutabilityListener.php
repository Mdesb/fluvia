<?php

declare(strict_types=1);

namespace App\Signature\Doctrine;

use App\Signature\Entity\ElectronicSignature;
use App\Signature\Service\ImmutableSignatureException;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;

/**
 * Garde d'inaltérabilité de la signature électronique : au niveau ORM, une `ElectronicSignature` ne
 * se modifie ni ne se supprime jamais. Le scellement pose ses champs AVANT la persistance (INSERT,
 * qui ne déclenche pas `preUpdate`) ; une fois en base, elle est figée.
 *
 * ⚠ MÊME RAISON QUE `Vente\Nf525\InalterabiliteListener` : le contrôle est posé au plus bas niveau
 * d'écriture, pas seulement à l'API. Un provider sur mesure ou une commande qui écrirait par l'ORM
 * contournerait une garde posée uniquement côté API — et une signature altérée en silence est
 * exactement ce que le sceau existe pour rendre impossible.
 */
#[AsDoctrineListener(event: Events::preUpdate)]
#[AsDoctrineListener(event: Events::preRemove)]
final class SignatureImmutabilityListener
{
    public function preUpdate(PreUpdateEventArgs $args): void
    {
        if ($args->getObject() instanceof ElectronicSignature) {
            throw new ImmutableSignatureException(
                'Modification interdite : une signature électronique est scellée (inaltérable). '
                . 'Recueillez une nouvelle signature plutôt que de retoucher celle-ci.',
            );
        }
    }

    public function preRemove(PreRemoveEventArgs $args): void
    {
        if ($args->getObject() instanceof ElectronicSignature) {
            throw new ImmutableSignatureException(
                'Suppression interdite : une signature électronique est scellée (append-only).',
            );
        }
    }
}
