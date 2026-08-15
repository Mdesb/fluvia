<?php

declare(strict_types=1);

namespace App\Securite\Security;

use App\Securite\Entity\Utilisateur;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTCreatedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Ajoute le claim `tokenVersion` au JWT à l'émission (§2.2 plan-backoffice.md) : permet
 * l'invalidation immédiate des jetons déjà émis à la suspension/réinitialisation de mot de passe
 * (`VerificateurJwtActifListener` compare ce claim à `Utilisateur::tokenVersion` à chaque requête).
 */
final class JwtClaimsAbonnee implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            Events::JWT_CREATED => 'onJwtCreated',
        ];
    }

    public function onJwtCreated(JWTCreatedEvent $event): void
    {
        $utilisateur = $event->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return;
        }

        $data = $event->getData();
        $data['tokenVersion'] = $utilisateur->getTokenVersion();
        $event->setData($data);
    }
}
