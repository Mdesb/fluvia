<?php

declare(strict_types=1);

namespace App\Lodging\Domain;

/**
 * Le calendrier d'occupation (ACT-2, D16) : qui est libre, quand, et combien.
 *
 * **La frontière avec `App\Reservation` est nette, et elle tient en une phrase : l'hébergement
 * propose, la réservation dispose.** `claude-G` a livré l'affectation différée et ses trois refus —
 * instance étrangère au type, instance d'un autre établissement, instance déjà affectée sur un créneau
 * qui chevauche. Rien de tout cela n'est à refaire. Ce qui manquait, c'est la question que pose
 * réellement un réceptionniste : *« j'ai une chambre double du 24 au 27 — laquelle puis-je donner ? »*
 * `App\Lodging` y répond ; c'est `App\Reservation` qui enregistre et qui refuse en dernier ressort.
 *
 * **Calcul pur, sans base.** Le calendrier reçoit son inventaire et ses occupations ; il ne les
 * cherche pas. C'est ce qui permet de figer par des tests unitaires la règle de la demi-journée, qui
 * vaut une nuit vendable par séjour et par chambre — et cette règle mérite mieux qu'une vérification
 * annuelle en haute saison.
 */
final class OccupancyCalendar
{
    /** @var array<string, list<LodgingPeriod>> occupations par unité */
    private array $occupancies = [];

    /**
     * @param array<string, string> $inventory unité → code de type (« chambre_double »)
     * @param list<UnitOccupancy>   $occupied  occupations connues
     */
    public function __construct(
        private readonly array $inventory,
        array $occupied,
    ) {
        foreach ($occupied as $occupation) {
            $this->occupancies[$occupation->unitId][] = $occupation->period;
        }
    }

    /**
     * Les unités de ce type qui peuvent accueillir cette période, dans l'ordre de l'inventaire.
     *
     * **Une unité rendue le matin est proposée pour le soir même** : c'est `LodgingPeriod::overlaps()`
     * qui le garantit, et c'est le cas le plus fréquent d'une chambre bien remplie.
     *
     * @return list<string>
     */
    public function freeUnitsFor(string $typeCode, LodgingPeriod $period): array
    {
        $libres = [];
        foreach ($this->inventory as $unitId => $type) {
            if ($type !== $typeCode) {
                continue;
            }
            if ($this->isFree((string) $unitId, $period)) {
                $libres[] = (string) $unitId;
            }
        }

        return $libres;
    }

    /**
     * L'unité à proposer, ou `null` si le type est complet.
     *
     * **Première libre de l'inventaire, et rien de plus malin.** Une heuristique — remplir d'abord les
     * chambres les moins demandées, grouper les familles au même étage — se défend, mais elle relève de
     * l'exploitation et se paramètre ; la coder ici la rendrait invisible et non désactivable. Tant que
     * personne ne l'a demandée, un ordre stable et explicable vaut mieux qu'un ordre astucieux.
     */
    public function firstAvailableUnitFor(string $typeCode, LodgingPeriod $period): ?string
    {
        return $this->freeUnitsFor($typeCode, $period)[0] ?? null;
    }

    public function canAccommodate(string $typeCode, LodgingPeriod $period): bool
    {
        return null !== $this->firstAvailableUnitFor($typeCode, $period);
    }

    /**
     * Le planning mural : pour chaque nuit de la fenêtre, combien d'unités de ce type sont prises.
     *
     * C'est la vue qui sert à décider d'ouvrir une remise ou de refuser un groupe — d'où le total en
     * plus du nombre d'occupées : un « 12 occupées » ne veut rien dire sans le parc en face.
     *
     * @return list<array{night: string, occupied: int, total: int}>
     */
    public function occupancyByNight(string $typeCode, LodgingPeriod $window): array
    {
        $unites = array_keys(array_filter($this->inventory, static fn (string $t): bool => $t === $typeCode));
        $total = \count($unites);

        $planning = [];
        foreach ($window->nights() as $nuit) {
            $occupees = 0;
            foreach ($unites as $unitId) {
                foreach ($this->occupancies[(string) $unitId] ?? [] as $periode) {
                    if ($periode->includesNight($nuit)) {
                        ++$occupees;
                        break;
                    }
                }
            }

            $planning[] = ['night' => $nuit->format('Y-m-d'), 'occupied' => $occupees, 'total' => $total];
        }

        return $planning;
    }

    private function isFree(string $unitId, LodgingPeriod $period): bool
    {
        foreach ($this->occupancies[$unitId] ?? [] as $occupee) {
            if ($occupee->overlaps($period)) {
                return false;
            }
        }

        return true;
    }
}
