<?php

declare(strict_types=1);

namespace App\Reservation\Service;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * ACT-1 / D16 point 1 — lecture de la quantité demandée dans un corps de requête, défaut 1.
 *
 * Partagé entre la réservation et l'inscription en liste d'attente : les deux posent la même
 * question (« combien d'unités ? »), et deux validations séparées auraient dérivé l'une de l'autre.
 *
 * **Le contrôle n'est pas décoratif.** Ces deux points d'entrée lisent le corps brut
 * (`LecteurCorps`) et non un objet dénormalisé : sans lui, `0`, `-3` ou `"beaucoup"` arriveraient
 * jusqu'à la jauge, qui les additionnerait sans jamais rien signaler — un `0` rendrait une
 * réservation gratuite en capacité, un négatif **libérerait** des places.
 */
final class RequestedQuantityReader
{
    public const CHAMP = 'quantity';

    /** @param array<string, mixed> $corps */
    public function read(array $corps): int
    {
        $brut = $corps[self::CHAMP] ?? 1;

        if (\is_string($brut) && ctype_digit($brut)) {
            $brut = (int) $brut;   // un corps JSON peut porter "4" plutôt que 4.
        }
        if (!\is_int($brut) || $brut < 1) {
            throw new UnprocessableEntityHttpException(
                sprintf('« %s » doit être un entier strictement positif.', self::CHAMP)
            );
        }

        return $brut;
    }
}
