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
 * Régime « DSP » (délégataire) : référentiel PCG, exports privés (FEC + éditeurs). PCA actif par
 * défaut (`ParametresRegime::defautPour`). Point d'extension `calculerRedevance()` (RAD, §7.6 du
 * plan, hors périmètre L4) : volontairement **non implémenté**.
 */
#[AutoconfigureTag('compta.regime_comptable')]
class RegimeDspPcg extends RegimeBase
{
    public function cle(): TypeExploitant
    {
        return TypeExploitant::Dsp;
    }

    public function formatsExportDisponibles(): array
    {
        return [FormatExport::Fec, FormatExport::Ciel, FormatExport::Ebp, FormatExport::Sage, FormatExport::Cegid];
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
        return $this->comptes->compteParPrefixe($profil, '487');
    }

    public function compteEncaissement(ProfilExploitant $profil): CompteComptable
    {
        return $this->comptes->compteParPrefixe($profil, '411');
    }

    public function compteTvaCollectee(ProfilExploitant $profil): CompteComptable
    {
        return $this->comptes->compteParPrefixe($profil, '4457');
    }

    /**
     * Point d'extension RAD/redevances DSP (écran M6-08, §7.6 du plan) — **non implémenté**, hors
     * périmètre des 10 US-L4 actuelles (aucune story backlog ne couvre le RAD, cf. spec §4.8).
     */
    public function calculerRedevance(): never
    {
        throw new \LogicException('calculerRedevance() : non disponible dans ce lot (RAD hors périmètre L4).');
    }
}
