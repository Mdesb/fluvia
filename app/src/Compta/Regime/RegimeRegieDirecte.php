<?php

declare(strict_types=1);

namespace App\Compta\Regime;

use App\Compta\Entity\CompteComptable;
use App\Compta\Entity\Journal;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Entity\QualificationEquipement;
use App\Compta\Enum\FormatExport;
use App\Compta\Enum\NatureOperation;
use App\Compta\Enum\TypeExploitant;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Régime « régie directe » (collectivité) : référentiel M57 ou M4 SPIC (délégué ligne à ligne à
 * `SelecteurReferentielPublic`, point EXPERT #1), exports publics (PES/Hélios + états de régie), PCA
 * désactivé par défaut (point EXPERT #3, `ParametresRegime::pcaActif`).
 */
#[AutoconfigureTag('compta.regime_comptable')]
final class RegimeRegieDirecte extends RegimeBase
{
    public function __construct(
        CompteLookupService $comptes,
        private readonly SelecteurReferentielPublic $selecteur,
    ) {
        parent::__construct($comptes);
    }

    public function cle(): TypeExploitant
    {
        return TypeExploitant::RegieDirecte;
    }

    public function formatsExportDisponibles(): array
    {
        return [FormatExport::PesV2Helios, FormatExport::EtatRegie];
    }

    public function journalPour(ProfilExploitant $profil, NatureOperation $nature): Journal
    {
        return $this->comptes->journal($profil, match ($nature) {
            NatureOperation::Ventes => 'VTE',
            NatureOperation::Encaissements => 'ENC',
            NatureOperation::Regie => 'REG',
            NatureOperation::PcaOd => 'PCA',
            NatureOperation::Extourne => 'EXT',
        });
    }

    public function compteAttente487(ProfilExploitant $profil, ?QualificationEquipement $qualif): CompteComptable
    {
        // Le référentiel exact (M4/M57) est déterminé par le sélecteur (point EXPERT #1) mais le
        // numéro de compte 487 est commun aux deux nomenclatures dans le plan de comptes seedé.
        $this->selecteur->choisir($profil, $qualif);

        return $this->comptes->compteParPrefixe($profil, '487');
    }

    public function compteEncaissement(ProfilExploitant $profil): CompteComptable
    {
        return $this->comptes->compteParPrefixe($profil, '511');
    }

    public function compteTvaCollectee(ProfilExploitant $profil): CompteComptable
    {
        return $this->comptes->compteParPrefixe($profil, '4457');
    }
}
