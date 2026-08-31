<?php

declare(strict_types=1);

namespace App\Dms\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dms\Entity\Document;
use App\Dms\Enum\DocumentStatus;
use App\Dms\Enum\RetentionStatus;
use App\Dms\Security\DmsScopeGuard;
use App\Dms\Service\RetentionStatusCalculator;
use App\Organisation\Entity\Etablissement;
use App\Platform\Event\DomainEvent;
use App\Platform\Event\EventActor;
use App\Platform\Event\EventBus;
use App\Platform\Event\EventSubject;
use App\Platform\Event\EventTenant;
use App\Securite\Entity\Utilisateur;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * `DELETE /documents/{id}` — suppression **logique** (RG-DMS-13/14, CA-5/CA-6) : `status = deleted`,
 * jamais une suppression physique de ligne (pas de `remove_processor`, on persiste une mise à jour de
 * statut via le processor Doctrine standard). Refusée (409) quel que soit le rôle de l'appelant si la
 * rétention est `active` — `document.deletion_refused` publié **avant** le 409, rien n'est modifié.
 *
 * @implements ProcessorInterface<Document, Document|null>
 */
final class DeleteDocumentProcessor implements ProcessorInterface
{
    /** @param ProcessorInterface<Document, Document> $persistProcessor */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private readonly ProcessorInterface $persistProcessor,
        private readonly DmsScopeGuard $scopeGuard,
        private readonly RetentionStatusCalculator $retentionStatusCalculator,
        private readonly EventBus $eventBus,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        \assert($data instanceof Document);
        $etablissement = $this->scopeGuard->verify($data->getEstablishment());
        \assert($etablissement instanceof Etablissement);
        $acteur = $this->security->getUser();
        $acteurEvent = $acteur instanceof Utilisateur ? new EventActor($acteur->getId()) : null;

        $statut = $this->retentionStatusCalculator->statusFor($data->getRetainUntil());
        if ($statut === RetentionStatus::Active) {
            $this->eventBus->publish(new DomainEvent(
                'document.deletion_refused',
                new EventTenant($etablissement->getId()),
                new EventSubject('Document', (string) $data->getId()),
                [
                    'retainUntil' => $data->getRetainUntil()?->format('Y-m-d'),
                    'reasonCode' => 'retention_active',
                ],
                $acteurEvent,
            ));

            throw new ConflictHttpException(
                'dms.error.retention_active : suppression refusée, rétention active jusqu\'au ' . $data->getRetainUntil()?->format('Y-m-d') . '.',
            );
        }

        $data->setStatus(DocumentStatus::Deleted);
        $data->setDeletedAt(new \DateTimeImmutable());
        $data->touch();

        $this->persistProcessor->process($data, $operation, $uriVariables, $context);

        $this->eventBus->publish(new DomainEvent(
            'document.deleted',
            new EventTenant($etablissement->getId()),
            new EventSubject('Document', (string) $data->getId()),
            ['category' => $data->getCategory()->value],
            $acteurEvent,
        ));

        return null;
    }
}
