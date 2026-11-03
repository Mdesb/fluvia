<?php

declare(strict_types=1);

namespace App\Offre\Port;

use App\Offre\Entity\Produit;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Ce qu'un autre module exige d'un produit avant qu'il soit publié (lot des garde-fous, 08/10).
 *
 * `Offre` ne sait ni ce qu'est une zone d'accès ni ce qu'est un créneau : chaque module dit lui-même
 * ce qui, chez lui, rendrait le produit invendable, et `PublicationGuard` ne fait que collecter. Un
 * port comme `DependanceVenteInterface`, mais à plusieurs implémentations : l'étiquette les câble
 * toutes, sans alias dans `services.yaml`.
 */
#[AutoconfigureTag(self::TAG)]
interface PublicationPrerequisite
{
    public const TAG = 'offre.publication_prerequisite';

    /**
     * @return array<string, string> code => phrase qui dit à l'exploitant quoi faire ; vide si rien ne manque
     */
    public function missing(Produit $product): array;
}
