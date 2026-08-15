<?php

declare(strict_types=1);

namespace App\Compta\Regime;

use App\Compta\Entity\ProfilExploitant;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Construit la map `[TypeExploitant => RegimeComptableInterface]` à partir de l'itérateur taggé
 * `compta.regime_comptable` (aucun `switch`). **Seul point de lecture** du discriminant
 * `ProfilExploitant::type` dans tout le module (§0.1 du plan) : toute la logique métier en aval reçoit
 * déjà l'implémentation résolue.
 */
final class RegimeComptableResolver
{
    /** @var array<string, RegimeComptableInterface>|null */
    private ?array $parCle = null;

    /**
     * @param iterable<RegimeComptableInterface> $regimes
     */
    public function __construct(
        #[AutowireIterator('compta.regime_comptable')]
        private readonly iterable $regimes,
    ) {
    }

    public function pour(ProfilExploitant $profil): RegimeComptableInterface
    {
        $map = $this->map();
        $cle = $profil->getType()->value;

        return $map[$cle] ?? throw new UnprocessableEntityHttpException(sprintf('Aucun régime comptable enregistré pour le type « %s ».', $cle));
    }

    /** @return array<string, RegimeComptableInterface> */
    private function map(): array
    {
        if ($this->parCle !== null) {
            return $this->parCle;
        }

        $map = [];
        foreach ($this->regimes as $regime) {
            $map[$regime->cle()->value] = $regime;
        }

        return $this->parCle = $map;
    }
}
