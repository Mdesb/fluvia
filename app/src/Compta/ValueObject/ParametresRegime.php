<?php

declare(strict_types=1);

namespace App\Compta\ValueObject;

use App\Compta\Enum\Qualification;

/**
 * Les 6 points ⚠ EXPERT (§8 du plan) : structure paramétrable avec défaut prudent. Changer un
 * arbitrage = changer une valeur de configuration, jamais le code des `Regime*`. Embarqué en JSON
 * sur `ProfilExploitant::parametres` (jamais lu ailleurs que dans le moteur de régime et les gardes).
 */
final class ParametresRegime
{
    public function __construct(
        /** Point EXPERT #3 — admissibilité du PCA (487) en comptabilité publique. */
        public readonly bool $pcaActif = false,
        /** Point EXPERT #1 — qualification par défaut si l'équipement n'a pas de QualificationEquipement. */
        public readonly Qualification $qualificationParDefaut = Qualification::Spa,
        /** Point EXPERT #2 — taux réduit TVA 2025 (cours/accès sportifs). */
        public readonly bool $tauxReduitTvaActif = false,
        /** Point EXPERT #4/5 — périmètre NF525 pour les régies de recettes. */
        public readonly bool $nf525PerimetreRegie = true,
        /** Point EXPERT #6 — génère un titre de régularisation sur l'export PES. */
        public readonly bool $genereTitreRegularisationPes = false,
    ) {
    }

    /** @return array{pcaActif: bool, qualificationParDefaut: string, tauxReduitTvaActif: bool, nf525PerimetreRegie: bool, genereTitreRegularisationPes: bool} */
    public function toArray(): array
    {
        return [
            'pcaActif' => $this->pcaActif,
            'qualificationParDefaut' => $this->qualificationParDefaut->value,
            'tauxReduitTvaActif' => $this->tauxReduitTvaActif,
            'nf525PerimetreRegie' => $this->nf525PerimetreRegie,
            'genereTitreRegularisationPes' => $this->genereTitreRegularisationPes,
        ];
    }

    /** @param array<string, mixed> $donnees */
    public static function depuisArray(array $donnees): self
    {
        return new self(
            pcaActif: (bool) ($donnees['pcaActif'] ?? false),
            qualificationParDefaut: Qualification::tryFrom((string) ($donnees['qualificationParDefaut'] ?? 'SPA')) ?? Qualification::Spa,
            tauxReduitTvaActif: (bool) ($donnees['tauxReduitTvaActif'] ?? false),
            nf525PerimetreRegie: (bool) ($donnees['nf525PerimetreRegie'] ?? true),
            genereTitreRegularisationPes: (bool) ($donnees['genereTitreRegularisationPes'] ?? false),
        );
    }

    /** Défaut par type d'exploitant (§8 du plan : PCA actif par défaut hors régie directe). */
    public static function defautPour(\App\Compta\Enum\TypeExploitant $type): self
    {
        return new self(pcaActif: $type !== \App\Compta\Enum\TypeExploitant::RegieDirecte);
    }
}
