<?php

declare(strict_types=1);

namespace App\Tests\Boutique\Support;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Email;

/**
 * Collecteur d'e-mails pour les tests L8 Boutique (registré uniquement en environnement `test`,
 * `config/services.yaml` bloc `when@test:`). Écoute `Symfony\Component\Mailer\Event\MessageEvent`,
 * dispatché par `Symfony\Component\Mailer\Transport\AbstractTransport::send()` **avant** l'envoi
 * effectif — fonctionne avec le transport `null://null` (dev/test), sans avoir besoin du profiler
 * Symfony (`MailerAssertionsTrait` usuel, non disponible ici : `web-profiler-bundle` n'est pas une
 * dépendance de ce dépôt).
 */
final class MailCollector implements EventSubscriberInterface
{
    /** @var list<Email> */
    private array $messages = [];

    public static function getSubscribedEvents(): array
    {
        return [MessageEvent::class => 'onMessage'];
    }

    public function onMessage(MessageEvent $event): void
    {
        $message = $event->getMessage();
        if ($message instanceof Email) {
            $this->messages[] = $message;
        }
    }

    /** @return list<Email> */
    public function messages(): array
    {
        return $this->messages;
    }

    public function dernier(): ?Email
    {
        return $this->messages === [] ? null : $this->messages[array_key_last($this->messages)];
    }

    public function reset(): void
    {
        $this->messages = [];
    }
}
