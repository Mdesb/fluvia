<?php

declare(strict_types=1);

namespace App\Dms\Service;

use App\Dms\Crypto\DocumentStreamCipher;
use App\Dms\Entity\Document;
use App\Dms\Entity\DocumentVersion;
use App\Dms\Storage\StorageKeyGenerator;
use App\Dms\Storage\Storage;
use App\Organisation\Entity\Etablissement;
use App\Platform\Event\DomainEvent;
use App\Platform\Event\EventActor;
use App\Platform\Event\EventBus;
use App\Platform\Event\EventSubject;
use App\Platform\Event\EventTenant;
use App\Securite\Entity\Utilisateur;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Cœur partagé du remplacement de version — voie HTTP (`ReplaceDocumentVersionProcessor`) **et**
 * `DefaultDocumentStore` (plan-dms.md §5). RG-DMS-18 : n'altère jamais la version précédente, ajoute
 * une nouvelle `DocumentVersion` (`versionNumber` = précédent + 1, `previousVersion` = version
 * précédente), puis fait pointer `Document.currentVersion` vers elle.
 *
 * §5.1 — verrouillage pessimiste sur la ligne `Document` : la deuxième requête concurrente **attend**
 * (bloquée par MariaDB InnoDB), puis relit `currentVersion` déjà mis à jour par la première — elle
 * produit donc `versionNumber = N+2`, jamais de collision ni de perte d'écriture. La contrainte
 * `UNIQUE(document, versionNumber)` reste un filet de sécurité si le verrou était contourné.
 */
final class ReplaceVersionHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DocumentStreamCipher $cipher,
        private readonly Storage $storage,
        private readonly StorageKeyGenerator $storageKeyGenerator,
        private readonly EventBus $eventBus,
    ) {
    }

    /** @param resource $sourceStream ressource ouverte en lecture, position 0 */
    public function replace(
        Uuid $documentId,
        $sourceStream,
        string $originalFilename,
        string $mimeType,
        ?Utilisateur $actor,
    ): Document {
        $storageKey = $this->storageKeyGenerator->generate();
        $destination = fopen('php://temp/maxmemory:1048576', 'w+b');
        \assert($destination !== false);

        try {
            $fileHash = $this->cipher->encrypt($sourceStream, $destination);
            $sizeBytes = ftell($sourceStream);
            if ($sizeBytes === false || $sizeBytes <= 0) {
                throw new UnprocessableEntityHttpException('dms.error.empty_file : le fichier ne peut pas être vide.');
            }

            rewind($destination);
            $this->storage->put($storageKey, $destination);
        } finally {
            fclose($destination);
        }

        $resultat = $this->em->wrapInTransaction(function () use (
            $documentId,
            $storageKey,
            $fileHash,
            $sizeBytes,
            $originalFilename,
            $mimeType,
            $actor,
        ): Document {
            /** @var Document|null $document */
            $document = $this->em->find(Document::class, $documentId, LockMode::PESSIMISTIC_WRITE);
            \assert($document instanceof Document);

            $courante = $document->getCurrentVersion();
            \assert($courante instanceof DocumentVersion);

            $nouvelle = new DocumentVersion(
                $document,
                $courante->getVersionNumber() + 1,
                $courante,
                $storageKey,
                $fileHash,
                $sizeBytes,
                $mimeType,
                $originalFilename,
                $actor,
            );
            $this->em->persist($nouvelle);
            $document->setCurrentVersion($nouvelle);
            $document->touch();
            $this->em->flush();

            return $document;
        });

        $etablissement = $resultat->getEstablishment();
        \assert($etablissement instanceof Etablissement);
        $nouvelleVersion = $resultat->getCurrentVersion();
        \assert($nouvelleVersion instanceof DocumentVersion);

        $this->eventBus->publish(new DomainEvent(
            'document.version_added',
            new EventTenant($etablissement->getId()),
            new EventSubject('Document', (string) $resultat->getId()),
            [
                'versionId' => (string) $nouvelleVersion->getId(),
                'versionNumber' => $nouvelleVersion->getVersionNumber(),
                'previousVersionId' => $nouvelleVersion->getPreviousVersion() !== null
                    ? (string) $nouvelleVersion->getPreviousVersion()->getId()
                    : null,
                'mimeType' => $nouvelleVersion->getMimeType(),
                'sizeBytes' => $nouvelleVersion->getSizeBytes(),
            ],
            $actor instanceof Utilisateur ? new EventActor($actor->getId()) : null,
        ));

        return $resultat;
    }
}
