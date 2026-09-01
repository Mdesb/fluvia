<?php

declare(strict_types=1);

namespace App\Dining\Domain;

/**
 * Un service dans le repas — entrée, plat, dessert — désigné par un **code libre** et un rang (ACT-4).
 *
 * **Pas d'énumération figée**, et c'est la même raison que pour les types de ressource de
 * `App\Reservation` : un bistrot a trois services, un gastronomique en a huit avec un trou normand,
 * une brasserie n'en a qu'un. Figer la liste obligerait à modifier le code pour chaque exploitant
 * exotique — exactement ce que D15 reproche à l'énumération `Metier` qu'elle a supprimée.
 *
 * **Le rang est ce qui compte, pas le nom.** Il ordonne l'envoi : on ne lance pas les desserts avant
 * les entrées. Deux services peuvent partager un rang — fromage et dessert servis ensemble — et c'est
 * une information, pas une collision.
 */
final class CourseRef
{
    private function __construct(
        public readonly string $code,
        public readonly int $rank,
    ) {
    }

    /**
     * @throws \InvalidArgumentException si le code est vide ou hors du format snake_case attendu
     */
    public static function of(string $code, int $rank): self
    {
        if (1 !== preg_match('/^[a-z][a-z0-9_]{1,39}$/', $code)) {
            throw new \InvalidArgumentException(sprintf(
                'Code de service invalide : « %s ». Attendu du snake_case court, comme « entree » ou « trou_normand ».',
                $code,
            ));
        }

        if ($rank < 0) {
            throw new \InvalidArgumentException('Le rang d\'un service ne peut pas être négatif.');
        }

        return new self($code, $rank);
    }

    public function equals(self $other): bool
    {
        return $this->code === $other->code;
    }

    /** Précède-t-il l'autre dans l'ordre du repas ? Un rang égal n'est pas une précédence. */
    public function precedes(self $other): bool
    {
        return $this->rank < $other->rank;
    }
}
