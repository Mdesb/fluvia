<?php

declare(strict_types=1);

namespace App\Website\Exception;

/**
 * Le code ou l'adresse d'un métier est déjà pris.
 *
 * Les deux colonnes portent une contrainte unique, et c'est elle qui fait foi. Ce refus arrive
 * avant, pour que celui qui le reçoit lise POURQUOI plutôt qu'un 500 : deux métiers qui partagent un
 * code partageraient aussi leur texte de page, puisque la clé du bloc est `metier.<code>.body`.
 */
final class TradeAlreadyExistsException extends \RuntimeException
{
}
