<?php

declare(strict_types=1);

namespace App\Dms\Service;

use App\Dms\Crypto\DocumentStreamCipher;
use App\Dms\Entity\Document;
use App\Dms\Entity\DocumentVersion;
use App\Dms\Entity\RetentionPolicy;
use App\Dms\Enum\DocumentCategory;
use App\Dms\Storage\StorageKeyGenerator;
use App\Dms\Storage\Storage;
use App\Organisation\Entity\Etablissement;
use App\Platform\Event\DomainEvent;
use App\Platform\Event\EventActor;
use App\Platform\Event\EventBus;
use App\Platform\Event\EventSubject;
use App\Platform\Event\EventTenant;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Cœur partagé de l'upload initial — consommé par la voie HTTP (`UploadDocumentProcessor`) **et** par
 * `App\Dms\Service\DefaultDocumentStore::store()` (plan-dms.md §5).
 *
 * §0.1 : `Document` + `DocumentVersion` v1 sont créés dans **une seule transaction** Doctrine
 * (`persist(Document)` -> `flush()` -> `persist(DocumentVersion)` -> `flush()` ->
 * `setCurrentVersion()` -> `flush()`) ; aucune lecture n'est jamais exposée pendant cette fenêtre, donc
 * `Document.currentVersion` n'est jamais observable `null` depuis l'extérieur (RG-DMS-19).
 *
 * Le chiffrement/écriture physique (`Storage::put`) se fait **avant** la transaction : une opération
 * d'E/S lente ne doit jamais tenir un verrou de ligne DB (cohérent avec §5.1, verrouillage pessimiste
 * réservé au remplacement de version). En cas d'échec de la transaction après écriture, le fichier
 * physique reste orphelin sur disque (non indexé, sans impact fonctionnel) — compromis assumé plutôt
 * qu'un `Storage::put` dans la transaction.
 */
final class UploadDocumentHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DocumentStreamCipher $cipher,
        private readonly Storage $storage,
        private readonly StorageKeyGenerator $storageKeyGenerator,
        private readonly EventBus $eventBus,
    ) {
    }

    /**
     * @param resource   $sourceStream ressource ouverte en lecture, position 0
     * @param list<string>|null $tags
     */
    public function upload(
        Etablissement $establishment,
        DocumentCategory $category,
        string $title,
        ?array $tags,
        ?string $sourceModule,
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

        $document = new Document();
        $document->setEstablishment($establishment)
            ->setCategory($category)
            ->setTitle($title)
            ->setTags($tags)
            ->setSourceModule($sourceModule)
            ->setCreatedBy($actor);

        $resultat = $this->em->wrapInTransaction(function () use (
            $document,
            $storageKey,
            $fileHash,
            $sizeBytes,
            $originalFilename,
            $mimeType,
            $actor,
            $category,
        ): Document {
            $this->em->persist($document);
            $this->em->flush();

            $version = new DocumentVersion($document, 1, null, $storageKey, $fileHash, $sizeBytes, $mimeType, $originalFilename, $actor);
            $this->em->persist($version);
            $this->em->flush();

            $document->setCurrentVersion($version);

            /** @var RetentionPolicy|null $politique */
            $politique = $this->em->getRepository(RetentionPolicy::class)->findOneBy(['defaultForCategory' => $category]);
            if ($politique instanceof RetentionPolicy) {
                $document->setRetentionPolicy($politique);
                $document->setRetainUntil(
                    (new \DateTimeImmutable('today'))->modify(sprintf('+%d months', $politique->getDurationMonths())),
                );
            }
            $this->em->flush();

            return $document;
        });

        $etablissement = $resultat->getEstablishment();
        \assert($etablissement instanceof Etablissement);
        $versionCourante = $resultat->getCurrentVersion();
        \assert($versionCourante instanceof DocumentVersion);

        $this->eventBus->publish(new DomainEvent(
            'document.stored',
            new EventTenant($etablissement->getId()),
            new EventSubject('Document', (string) $resultat->getId()),
            [
                'category' => $resultat->getCategory()->value,
                'versionId' => (string) $versionCourante->getId(),
                'mimeType' => $versionCourante->getMimeType(),
                'sizeBytes' => $versionCourante->getSizeBytes(),
                'sourceModule' => $resultat->getSourceModule(),
            ],
            $actor instanceof Utilisateur ? new EventActor($actor->getId()) : null,
        ));

        return $resultat;
    }
}
