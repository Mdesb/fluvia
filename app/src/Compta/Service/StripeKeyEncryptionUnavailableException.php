<?php

declare(strict_types=1);

namespace App\Compta\Service;

/**
 * La clé maîtresse `STRIPE_KEYS_ENCRYPTION_KEY` n'est pas posée ou est mal formée : les clés Stripe des exploitants ne
 * peuvent être ni enregistrées ni lues. Le message est destiné à l'opérateur ; il ne cite aucune valeur.
 */
final class StripeKeyEncryptionUnavailableException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'Le chiffrement des clés Stripe n\'est pas configuré sur ce serveur (variable STRIPE_KEYS_ENCRYPTION_KEY absente ou mal formée, 32 octets en base64 attendus) : '
            . 'la clé de l\'exploitant ne peut pas être enregistrée. Contactez l\'administrateur de la plateforme.'
        );
    }
}
