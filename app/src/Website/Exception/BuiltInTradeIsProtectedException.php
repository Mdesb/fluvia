<?php

declare(strict_types=1);

namespace App\Website\Exception;

/**
 * On a tenté de supprimer un métier que le produit porte lui-même.
 *
 * Ces cinq-là ont un cas dans `Metier` et un préréglage dans `PresetVerticale` : supprimer la ligne
 * ne retire pas le métier du produit — un établissement de ce métier continue de s'ouvrir avec ses
 * modules — elle retire seulement sa PAGE. Et l'adresse, indexée depuis des mois, se met à rendre
 * 404 : ça ne se voit pas d'ici, ça se voit chez les moteurs, des semaines plus tard.
 *
 * ⚠ **LE REFUS N'EST PAS UNE IMPASSE.** Le geste éditorial existe et reste ouvert : passer le métier
 * en brouillon. Il disparaît du site de la même façon, et il revient d'un clic.
 */
final class BuiltInTradeIsProtectedException extends \RuntimeException
{
}
