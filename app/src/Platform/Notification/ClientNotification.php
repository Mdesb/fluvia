<?php

declare(strict_types=1);

namespace App\Platform\Notification;

use Symfony\Component\Uid\Uuid;

/**
 * Ce qu'on veut dire à un client, sans savoir par quel module ni par quel prestataire.
 *
 * **Pourquoi un objet et pas trois arguments.** Trois modules vont produire des notifications — Smart
 * Flow, Revenue Recovery, Campagnes (D42) — et chacun aurait inventé sa propre signature. Un objet
 * unique force la même information partout, et surtout il rend le point de passage **unique** : c'est
 * ce qui permet d'y accrocher le contrôle de consentement (D42, RG-CMP-06) sans que personne ne
 * puisse le contourner.
 *
 * **`templateKey` et pas un corps rédigé** : le texte appartient au module qui l'envoie ou au gabarit
 * de campagne, pas au transport. Un port qui recevrait du texte déjà composé empêcherait de traduire,
 * de personnaliser et de tracer ce qui a été promis.
 *
 * **`occurredAt` est l'instant MÉTIER** — celui où le fait s'est produit pour le client, jamais l'heure
 * d'exécution (D37). Sur vingt et un émetteurs d'événements de ce dépôt, un seul le passait
 * explicitement ; on ne recommence pas ici.
 */
final readonly class ClientNotification
{
    /** @param array<string, scalar|null> $variables */
    public function __construct(
        public Uuid $clientId,
        public NotificationChannel $channel,
        public string $templateKey,
        public array $variables,
        public \DateTimeImmutable $occurredAt,
        /** D'où vient la notification — pour la traçabilité et le plafond de sollicitation (D42). */
        public string $source,
        /**
         * Sur quel fondement on écrit. **Le défaut est le plus strict** : qui ne se pose pas la
         * question voit son message refusé faute de consentement, ce qui se voit et se corrige.
         * L'inverse enverrait de la prospection à des gens qui l'ont refusée, ce qui ne se voit pas.
         */
        public NotificationBasis $basis = NotificationBasis::Consentement,
    ) {
    }
}
