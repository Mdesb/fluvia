<?php

declare(strict_types=1);

namespace App\Subscription\Exception;

/**
 * Confirmation demandée sans mandat SEPA actif (ED-3, D10).
 *
 * Exception dédiée parce que le tunnel doit pouvoir renvoyer le prospect à l'étape de signature
 * plutôt que tomber en 500. Et refus plutôt que tolérance : activer sans mandat ouvrirait un service
 * que rien ne paie, puis ferait relancer pour impayé un client qui n'a jamais donné d'autorisation de
 * prélèvement — la relance serait chez nous la faute, pas chez lui.
 */
final class MissingMandateException extends \RuntimeException
{
}
