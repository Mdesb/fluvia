<?php

declare(strict_types=1);

namespace App\Compta\Regime;

use App\Compta\Entity\BordereauVersement;
use App\Compta\Entity\CompteComptable;
use App\Compta\Entity\EcritureComptable;
use App\Compta\Entity\Journal;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Entity\QualificationEquipement;
use App\Compta\Enum\FormatExport;
use App\Compta\Enum\NatureOperation;
use App\Compta\Enum\TypeExploitant;
use App\Compta\Regime\Dto\AvoirProjectionDto;
use App\Compta\Regime\Dto\EcritureADto;
use App\Compta\Regime\Dto\LigneEcritureADto;
use App\Compta\Regime\Dto\VenteProjectionDto;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Régime « groupe privé » : même base que `RegimeDspPcg` (référentiel PCG) — **décore** par
 * composition (pas d'héritage dupliqué, §0.2 du plan) et **rend obligatoires** les axes analytiques
 * site/activité/financeur sur chaque ligne (RG-M6-04/§4.11). Point d'extension
 * `consoliderAgregats()` **non implémenté** (hors périmètre L4).
 */
#[AutoconfigureTag('compta.regime_comptable')]
final class RegimeGroupePrive implements RegimeComptableInterface
{
    public function __construct(
        private readonly RegimeDspPcg $interne,
    ) {
    }

    public function cle(): TypeExploitant
    {
        return TypeExploitant::GroupePrive;
    }

    public function genererEcritureVente(VenteProjectionDto $vente, ProfilExploitant $profil, MappingResolver $mapping): EcritureADto
    {
        $ecriture = $this->interne->genererEcritureVente($vente, $profil, $mapping);

        return $this->forcerAxes($ecriture, $profil);
    }

    public function genererEcritureExtourne(EcritureComptable $origine, AvoirProjectionDto $avoir): EcritureADto
    {
        return $this->interne->genererEcritureExtourne($origine, $avoir);
    }

    public function genererEcritureRegie(BordereauVersement $bordereau): EcritureADto
    {
        return $this->interne->genererEcritureRegie($bordereau);
    }

    public function formatsExportDisponibles(): array
    {
        return $this->interne->formatsExportDisponibles();
    }

    public function journalPour(ProfilExploitant $profil, NatureOperation $nature): Journal
    {
        return $this->interne->journalPour($profil, $nature);
    }

    public function compteAttente487(ProfilExploitant $profil, ?QualificationEquipement $qualif): CompteComptable
    {
        return $this->interne->compteAttente487($profil, $qualif);
    }

    public function compteEncaissement(ProfilExploitant $profil): CompteComptable
    {
        return $this->interne->compteEncaissement($profil);
    }

    public function compteTvaCollectee(ProfilExploitant $profil): CompteComptable
    {
        return $this->interne->compteTvaCollectee($profil);
    }

    /** Point d'extension consolidation groupe (§4.11 spec) — **non implémenté**, hors périmètre L4. */
    public function consoliderAgregats(): never
    {
        throw new \LogicException('consoliderAgregats() : non disponible dans ce lot (consolidation groupe hors périmètre L4).');
    }

    private function forcerAxes(EcritureADto $ecriture, ProfilExploitant $profil): EcritureADto
    {
        $site = (string) $profil->getEtablissementPrincipal()?->getId();
        $lignes = array_map(
            static fn (LigneEcritureADto $l): LigneEcritureADto => new LigneEcritureADto(
                compte: $l->compte,
                debitCentimes: $l->debitCentimes,
                creditCentimes: $l->creditCentimes,
                tauxTva: $l->tauxTva,
                axeSite: $l->axeSite ?? $site,
                axeActivite: $l->axeActivite ?? 'non_ventile',
                axeFinanceur: $l->axeFinanceur ?? 'non_ventile',
                libelle: $l->libelle,
            ),
            $ecriture->lignes,
        );

        return new EcritureADto($ecriture->nature, $ecriture->dateEcriture, $lignes, $ecriture->venteOrigine, $ecriture->libelle);
    }
}
