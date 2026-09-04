<?php

declare(strict_types=1);

namespace App\Website\Exception;

/**
 * On a tenté de renommer l'adresse d'un article déjà publié (ED-10).
 *
 * Exception dédiée parce que l'écran doit pouvoir le dire au rédacteur **et rester sur sa page**,
 * sans perdre ce qu'il vient d'écrire. Un refus générique l'aurait fait recommencer.
 *
 * Le jour où une table de redirections permanentes existe, ce refus disparaît : renommer deviendra
 * « renommer et laisser une trace ». Tant qu'elle n'existe pas, autoriser le renommage reviendrait à
 * casser des liens chez des gens qui ne nous liront jamais.
 */
final class PublishedSlugIsFrozenException extends \RuntimeException
{
}
