<?php

declare(strict_types=1);

namespace App\Dms\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dms\Entity\Document;
use App\Dms\Security\DmsScopeGuard;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * `PATCH /documents/{id}` (title/category/tags — jamais establishment/sourceModule/currentVersion,
 * plan §2). Changer `category` ne recalcule **jamais** `retainUntil`/`retentionPolicy` (RG-DMS-11) :
 * ce processor ne touche pas ces champs, seule `SetRetentionProcessor` le fait.
 *
 * `DmsScopeGuard` appelé explicitement (RG-DMS-02) même si l'opération Patch a déjà
 * `read: true` par défaut (défense en profondeur, pas de confiance implicite dans le provider).
 *
 * @implements ProcessorInterface<Document, Document>
 */
final class RenameDocumentProcessor implements ProcessorInterface
{
    /** @param ProcessorInterface<Document, Document> $persistProcessor */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private readonly ProcessorInterface $persistProcessor,
        private readonly DmsScopeGuard $scopeGuard,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        \assert($data instanceof Document);
        $this->scopeGuard->verify($data->getEstablishment());
        $data->touch();

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}
