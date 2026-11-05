<?php

declare(strict_types=1);

namespace App\Kiosk\Exception;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * La borne ne peut pas vendre : désactivée, sans terminal piloté, ou mal rattachée. 409 et non 503 :
 * c'est un état de la borne, que l'écran affiche (« hors service, adressez-vous à l'accueil »), pas une
 * panne du serveur — et un 5xx perd son message en production.
 */
final class KioskOutOfServiceException extends ConflictHttpException
{
    public const CODE = 'kiosk_out_of_service';
}
