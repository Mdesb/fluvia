<?php

declare(strict_types=1);

namespace App\Acces\Service;

use App\Acces\Enum\CodeMessageAffichage;
use App\Acces\Enum\CodeMotifRefus;
use App\Acces\Enum\ResultatPassage;
use App\Acces\Port\LibellesParEtablissementInterface;
use App\Organisation\Entity\Etablissement;

/**
 * Mappe `CodeMotifRefus|null → CodeMessageAffichage → libellé` (US-TERM-02, spec-acces-terminal.md
 * §4.5, tableau des 11 entrées). Un ajout de motif futur = ajout d'un `case` enum + une ligne `match`,
 * aucune migration.
 */
final class CatalogueMessageAffichage
{
    public function __construct(
        private readonly ?LibellesParEtablissementInterface $personnalisation = null,
    ) {
    }

    public function pour(?CodeMotifRefus $motif, ResultatPassage $resultat): CodeMessageAffichage
    {
        if ($resultat === ResultatPassage::Compte) {
            return CodeMessageAffichage::PassageCompte;
        }
        if ($motif === null) {
            return CodeMessageAffichage::BonneSeance;
        }

        return match ($motif) {
            CodeMotifRefus::HorsMarge => CodeMessageAffichage::HorsMarge,
            CodeMotifRefus::AntiPassback => CodeMessageAffichage::DejaPasse,
            CodeMotifRefus::CreditEpuise => CodeMessageAffichage::CarteEpuisee,
            CodeMotifRefus::DejaConsomme => CodeMessageAffichage::DejaPasse,
            CodeMotifRefus::SupportBloque => CodeMessageAffichage::SupportBloque,
            CodeMotifRefus::SeuilFmi => CodeMessageAffichage::JaugeAtteinte,
            CodeMotifRefus::DroitInvalide => CodeMessageAffichage::DroitInvalide,
            CodeMotifRefus::SensInterdit => CodeMessageAffichage::SensInterdit,
            CodeMotifRefus::FederationInactive => CodeMessageAffichage::FederationInactive,
            CodeMotifRefus::SignatureInvalide => CodeMessageAffichage::CodeInvalide,
            // Motifs non couverts par le tableau §4.5 (non_nominatif, ouverture_manuelle, hors_portee,
            // credit_epuise_hors_ligne_litige) : repli neutre — le passage n'est de toute façon jamais
            // « refusé » sur ces motifs côté flux terminal en ligne.
            default => CodeMessageAffichage::DroitInvalide,
        };
    }

    public function libelle(CodeMessageAffichage $code, ?Etablissement $etablissement = null): string
    {
        if ($etablissement !== null && $this->personnalisation !== null) {
            $libelle = $this->personnalisation->libellePour($code, $etablissement);
            if ($libelle !== null) {
                return $libelle;
            }
        }

        return $code->libelle();
    }
}
