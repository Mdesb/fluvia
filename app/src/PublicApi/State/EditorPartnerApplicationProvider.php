<?php

declare(strict_types=1);

namespace App\PublicApi\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\PublicApi\ApiResource\EditorPartnerApplication;
use App\PublicApi\Entity\ApiCredential;
use App\PublicApi\Entity\PartnerApplication;
use App\PublicApi\Enum\CredentialStatus;
use App\PublicApi\Webhook\PartnerEventCatalog;
use App\PublicApi\Webhook\PartnerWebhookOverview;
use App\Subscription\Security\EditorOnly;
use Doctrine\ORM\EntityManagerInterface;

/**
 * `GET /editor/partner-applications` — toutes les applications, actives ou non, et toutes leurs clés.
 *
 * Les clés révoquées restent listées : c'est l'endroit où l'on vérifie qu'une clé qui a fuité est bien
 * fermée, et depuis quand elle ne sert plus (`lastUsedAt`).
 */
final class EditorPartnerApplicationProvider implements ProviderInterface
{
    /** Le droit qui garde tout l'écran : émettre une clé ouvre une porte sur le réseau. */
    public const PERMISSION = 'editor.manage_partner_api';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EditorOnly $editorOnly,
        private readonly PartnerWebhookOverview $webhooks,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     *
     * @return list<EditorPartnerApplication>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $this->editorOnly->assertEditor(self::PERMISSION);

        /** @var list<PartnerApplication> $applications */
        $applications = $this->em->getRepository(PartnerApplication::class)->findBy([], ['createdAt' => 'DESC']);

        return array_map(fn (PartnerApplication $a): EditorPartnerApplication => $this->view($a), $applications);
    }

    /** Compose la fiche. Partagée avec le processeur pour qu'il rende la même forme que la liste. */
    public function view(PartnerApplication $application): EditorPartnerApplication
    {
        $now = new \DateTimeImmutable();
        $view = new EditorPartnerApplication();
        $view->id = (string) $application->getId();
        $view->name = $application->getName();
        $view->contactEmail = $application->getContactEmail();
        $view->active = $application->isActive();
        $view->createdAt = $application->getCreatedAt()->format(\DATE_ATOM);
        $view->webhook = $this->webhooks->of($application);
        $view->webhookEvents = PartnerEventCatalog::EVENTS;

        /** @var list<ApiCredential> $credentials */
        $credentials = $this->em->getRepository(ApiCredential::class)->findBy(['application' => $application], ['issuedAt' => 'DESC']);

        foreach ($credentials as $credential) {
            $expiresAt = $credential->getExpiresAt();
            $status = match (true) {
                CredentialStatus::Revoked === $credential->getStatus() => 'revoked',
                null !== $expiresAt && $expiresAt < $now => 'expired',
                default => 'active',
            };

            $view->credentials[] = [
                'id' => (string) $credential->getId(),
                'prefix' => $credential->getPrefix(),
                'status' => $status,
                'issuedAt' => $credential->getIssuedAt()->format(\DATE_ATOM),
                'expiresAt' => $expiresAt?->format(\DATE_ATOM),
                'lastUsedAt' => $credential->getLastUsedAt()?->format(\DATE_ATOM),
                'revokedAt' => $credential->getRevokedAt()?->format(\DATE_ATOM),
                'revokedBy' => $credential->getRevokedBy()?->getEmail(),
            ];
        }

        return $view;
    }
}
