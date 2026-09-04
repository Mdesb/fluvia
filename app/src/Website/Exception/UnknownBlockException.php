<?php

declare(strict_types=1);

namespace App\Website\Exception;

/**
 * On a tenté d'écrire dans un bloc que le gabarit ne connaît pas (ED-10).
 *
 * Le refus vaut mieux que l'acceptation : un bloc inconnu s'enregistre sans erreur et ne se rend
 * nulle part. Le rédacteur croit avoir publié, la page est inchangée, et il n'y a rien à chercher —
 * pas de message, pas de journal, juste un texte qui n'apparaît pas.
 */
final class UnknownBlockException extends \RuntimeException
{
}
