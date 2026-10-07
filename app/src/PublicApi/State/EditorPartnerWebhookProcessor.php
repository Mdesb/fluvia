<?php

declare(strict_types=1);

namespace App\PublicApi\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\PublicApi\ApiResource\EditorPartnerApplication;
use App\PublicApi\Entity\PartnerApplication;
use App\PublicApi\Webhook\PartnerWebhookManager;
use App\Subscription\Security\EditorOnly;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * L'abonnement aux webhooks d'une application : configurer (URL + événements), régénérer le secret,
 * couper. Le secret n'est rendu que dans la réponse qui le crée (`issuedWebhookSecret`).
 *
 * @cloisonnement-verifie : `PartnerApplication` n'appartient à aucun établissement client ;
 * `EditorOnly::assertEditor()`, appelé avant toute lecture, restreint l'appelant au tenant éditeur.
 */
final class EditorPartnerWebhookProcessor implements ProcessorInterface
{
    public const CONFIGURE = 'editor_partner_webhook_configure';
    public const ROTATE = 'editor_partner_webhook_rotate';
    public const DISABLE = 'editor_partner_webhook_disable';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EditorOnly $editorOnly,
        private readonly PartnerWebhookManager $webhooks,
        private readonly EditorPartnerApplicationProvider $provider,
        private readonly LecteurCorps $body,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): EditorPartnerApplication
    {
        $this->editorOnly->assertEditor(EditorPartnerApplicationProvider::PERMISSION);

        $reference = $uriVariables['id'] ?? null;
        $application = \is_string($reference) && Uuid::isValid($reference)
            ? $this->em->getRepository(PartnerApplication::class)->find(Uuid::fromString($reference))
            : null;
        if (!$application instanceof PartnerApplication) {
            throw new NotFoundHttpException();
        }

        $secret = null;
        if (self::CONFIGURE === $operation->getName()) {
            $body = $this->body->corps();
            $secret = $this->webhooks->configure(
                $application,
                \is_string($body['url'] ?? null) ? trim($body['url']) : '',
                \is_array($body['events'] ?? null) ? array_values($body['events']) : [],
            );
        } else {
            $subscription = $this->webhooks->find($application) ?? throw new NotFoundHttpException('Aucun webhook configuré pour cette application.');
            if (self::ROTATE === $operation->getName()) {
                $secret = $this->webhooks->rotateSecret($subscription);
            } else {
                $this->webhooks->disable($subscription);
            }
        }

        $view = $this->provider->view($application);
        $view->issuedWebhookSecret = $secret;

        return $view;
    }
}
