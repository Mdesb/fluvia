<?php

declare(strict_types=1);

namespace App\Dms\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dms\Entity\DocumentPublicLink;
use App\Dms\Security\DmsScopeGuard;
use App\Organisation\Entity\Etablissement;
use App\Platform\Event\DomainEvent;
use App\Platform\Event\EventActor;
use App\Platform\Event\EventBus;
use App\Platform\Event\EventSubject;
use App\Platform\Event\EventTenant;
use App\Securite\Entity\Utilisateur;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * `POST /public-links/{id}/revoke` (RG-DMS-07, CA-4) — révocation immédiate et définitive. Revérifie
 * explicitement le périmètre du document porteur (RG-DMS-02) même si `read: true`.
 *
 * @implements ProcessorInterface<DocumentPublicLink, DocumentPublicLink>
 */
final class RevokePublicLinkProcessor implements ProcessorInterface
{
    /** @param ProcessorInterface<DocumentPublicLink, DocumentPublicLink> $persistProcessor */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private readonly ProcessorInterface $persistProcessor,
        private readonly DmsScopeGuard $scopeGuard,
        private readonly EventBus $eventBus,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        \assert($data instanceof DocumentPublicLink);
        $document = $data->getDocument();
        $etablissement = $this->scopeGuard->verify($document?->getEstablishment());
        \assert($etablissement instanceof Etablissement);

        $data->revoke(new \DateTimeImmutable());
        $resultat = $this->persistProcessor->process($data, $operation, $uriVariables, $context);

        $acteur = $this->security->getUser();
        $this->eventBus->publish(new DomainEvent(
            'document.public_link_revoked',
            new EventTenant($etablissement->getId()),
            new EventSubject('Document', (string) $document?->getId()),
            ['publicLinkId' => (string) $data->getId()],
            $acteur instanceof Utilisateur ? new EventActor($acteur->getId()) : null,
        ));

        return $resultat;
    }
}
