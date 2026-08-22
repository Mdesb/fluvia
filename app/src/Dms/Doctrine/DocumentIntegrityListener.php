<?php

declare(strict_types=1);

namespace App\Dms\Doctrine;

use App\Dms\Entity\Document;
use App\Dms\Entity\DocumentVersion;
use App\Dms\Exception\DocumentEstablishmentImmutableException;
use App\Dms\Exception\DocumentVersionImmutableException;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;

/**
 * Garde d'inaltérabilité GED (copie du patron `App\Vente\Nf525\InalterabiliteListener`), deux
 * responsabilités dans le même fichier :
 *
 * 1. **`DocumentVersion` append-only (RG-DMS-17, CA-7)** — toute `preUpdate`/`preRemove` sur une
 *    version déjà persistée lève `DocumentVersionImmutableException`. Couvre l'API **et** l'accès ORM
 *    direct (fixtures, commande). **Seule exception tolérée** : la transition `purgedAt` null ->
 *    non-null effectuée par `PurgeDocumentsCommand` (RG-DMS-15, plan §14 pt.10) — un champ
 *    administratif « contenu physique retiré », pas une donnée de preuve (`fileHash`/`sizeBytes`/...).
 * 2. **`Document.establishment` immuable (RG-DMS-04)** — si le changeset `preUpdate` contient la clé
 *    `establishment`, lève `DocumentEstablishmentImmutableException` — défense en profondeur en
 *    complément du groupe `document:write`, qui n'expose déjà pas ce champ en écriture API.
 */
#[AsDoctrineListener(event: Events::preUpdate)]
#[AsDoctrineListener(event: Events::preRemove)]
final class DocumentIntegrityListener
{
    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $entity = $args->getObject();

        if ($entity instanceof DocumentVersion) {
            $changeSet = $args->getEntityChangeSet();
            if ($this->isPurgeMarkingOnly($changeSet)) {
                return;
            }

            throw new DocumentVersionImmutableException(
                'dms.error.document_version_sealed : modification interdite, la version est scellée (GED, append-only).',
            );
        }

        if ($entity instanceof Document) {
            $changeSet = $args->getEntityChangeSet();
            if (array_key_exists('establishment', $changeSet)) {
                throw new DocumentEstablishmentImmutableException(
                    'dms.error.document_establishment_immutable : l\'établissement d\'un document ne peut jamais être modifié (RG-DMS-04).',
                );
            }
        }
    }

    public function preRemove(PreRemoveEventArgs $args): void
    {
        $entity = $args->getObject();

        if ($entity instanceof DocumentVersion) {
            throw new DocumentVersionImmutableException(
                'dms.error.document_version_sealed : suppression interdite, la version est scellée (GED, append-only).',
            );
        }
    }

    /** @param array<string, array{0: mixed, 1: mixed}> $changeSet */
    private function isPurgeMarkingOnly(array $changeSet): bool
    {
        return array_keys($changeSet) === ['purgedAt']
            && $changeSet['purgedAt'][0] === null
            && $changeSet['purgedAt'][1] !== null;
    }
}
