<?php

declare(strict_types=1);

namespace App\Social\Dto;

/**
 * Un relevé de statistiques : la vue normalisée **et** ce qu'on a reçu (SOC-3, D14 contrainte 2).
 *
 * **Les compteurs sont nullables, et ce n'est pas de la coquetterie.** « Zéro partage » et « ce réseau
 * ne rend pas cette métrique » sont deux faits différents, et les confondre fabrique des moyennes
 * fausses : une portée moyenne calculée en comptant pour zéro les réseaux qui ne la publient pas
 * n'aura aucun sens, et personne ne saura d'où vient l'écart. Aucun des deux réseaux ouverts ne rend
 * la portée — `impressions` sera donc nul, et c'est une information.
 *
 * **`raw` est conservé en plus, jamais à la place.** Les plateformes redéfinissent leurs métriques
 * entre versions d'API : sans la charge brute, une redéfinition de « portée » rendrait tout le passé
 * incomparable, sans qu'on puisse même le constater. La vue normalisée est une *interprétation* ; on
 * garde donc la source de l'interprétation.
 */
final readonly class CollectedMetrics
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public ?int $likes,
        public ?int $shares,
        public ?int $replies,
        public ?int $impressions,
        public array $raw,
    ) {
    }
}
