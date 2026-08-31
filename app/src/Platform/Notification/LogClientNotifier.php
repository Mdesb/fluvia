<?php

declare(strict_types=1);

namespace App\Platform\Notification;

use Psr\Log\LoggerInterface;

/**
 * L'implémentation par défaut : elle écrit, elle n'envoie pas.
 *
 * Elle rend `Journalisee` et **jamais** `Envoyee`. C'est la seule façon honnête de se comporter tant
 * qu'aucun prestataire n'est branché : un exploitant qui lit « envoyé » croit que son client a reçu
 * quelque chose. Le jour où un adaptateur réel existe, il rendra `Envoyee` et rien d'autre ne bougera.
 *
 * Le journal ne porte **ni le contenu ni les variables** : elles peuvent contenir un nom, un solde,
 * une adresse. On trace qu'on a notifié, pas ce qu'on a dit.
 */
final readonly class LogClientNotifier implements ClientNotifierInterface
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function notify(ClientNotification $notification): NotificationOutcome
    {
        $this->logger->info('notification client (journal uniquement, aucun envoi réel)', [
            'client' => $notification->clientId->toRfc4122(),
            'canal' => $notification->channel->value,
            'gabarit' => $notification->templateKey,
            'source' => $notification->source,
            'instant_metier' => $notification->occurredAt->format(\DateTimeInterface::ATOM),
        ]);

        return NotificationOutcome::Journalisee;
    }
}
