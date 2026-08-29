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
        /**
         * Ventile le débit d'encaissement par moyen de paiement, selon `PaymentMethodAccount`.
         *
         * ⚠ FAUX PAR DÉFAUT, ET CE N'EST PAS DE LA TIÉDEUR. Activer change la FORME des écritures :
         * une vente réglée en trois fois produit trois lignes de débit au lieu d'une. C'est la
         * comptabilité correcte — les espèces ne vivent pas sur le compte de la banque — mais c'est
         * l'arbitrage de l'exploitant et de son expert-comptable, pas un défaut à corriger d'office
         * sur des livres déjà tenus.
         *
         * Sans ventilation déclarée pour un moyen, ce moyen retombe sur le compte d'encaissement
         * unique : activer le drapeau sans rien déclarer ne change donc rien non plus.
         */
        public readonly bool $ventilationEncaissementParMoyen = false,
    ) {
    }

    /** @return array{pcaActif: bool, qualificationParDefaut: string, tauxReduitTvaActif: bool, nf525PerimetreRegie: bool, genereTitreRegularisationPes: bool, ventilationEncaissementParMoyen: bool} */
    public function toArray(): array
    {
        return [
            'pcaActif' => $this->pcaActif,
            'qualificationParDefaut' => $this->qualificationParDefaut->value,
            'tauxReduitTvaActif' => $this->tauxReduitTvaActif,
            'nf525PerimetreRegie' => $this->nf525PerimetreRegie,
            'genereTitreRegularisationPes' => $this->genereTitreRegularisationPes,
            'ventilationEncaissementParMoyen' => $this->ventilationEncaissementParMoyen,
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
            ventilationEncaissementParMoyen: (bool) ($donnees['ventilationEncaissementParMoyen'] ?? false),
        );
    }

    /** Défaut par type d'exploitant (§8 du plan : PCA actif par défaut hors régie directe). */
    public static function defautPour(\App\Compta\Enum\TypeExploitant $type): self
    {
        return new self(pcaActif: $type !== \App\Compta\Enum\TypeExploitant::RegieDirecte);
    }
}
