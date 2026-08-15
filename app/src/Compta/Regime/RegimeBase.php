<?php

declare(strict_types=1);

namespace App\Compta\Regime;

use App\Compta\Entity\BordereauVersement;
use App\Compta\Entity\CompteComptable;
use App\Compta\Entity\EcritureComptable;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Enum\NatureOperation;
use App\Compta\Regime\Dto\AvoirProjectionDto;
use App\Compta\Regime\Dto\EcritureADto;
use App\Compta\Regime\Dto\LigneEcritureADto;
use App\Compta\Regime\Dto\VenteProjectionDto;
use App\Offre\Enum\ReglePca;

/**
 * Socle commun aux implémentations de `RegimeComptableInterface` (construction des lignes
 * d'écriture de vente/extourne/régie). Les spécificités par régime (comptes 487/encaissement/TVA,
 * journaux, formats d'export, qualification SPIC/SPA) restent dans les sous-classes — composition,
 * pas de branche sur le discriminant ici.
 */
abstract class RegimeBase implements RegimeComptableInterface
{
    public function __construct(
        protected readonly CompteLookupService $comptes,
    ) {
    }

    public function genererEcritureVente(VenteProjectionDto $vente, ProfilExploitant $profil, MappingResolver $mapping): EcritureADto
    {
        $pcaActif = $profil->getParametresRegime()->pcaActif;
        $compteTva = $this->compteTvaCollectee($profil);
        $compteEncaissement = $this->compteEncaissement($profil);

        $lignes = [];
        foreach ($vente->lignes as $ligneVente) {
            if ($ligneVente->categorieComptable === null) {
                continue; // Écarté en amont par MappingComptableGuard (CA-2) : ne devrait pas arriver ici.
            }
            $mappingCategorie = $mapping->pour($ligneVente->categorieComptable);
            if ($mappingCategorie === null || !$mappingCategorie->estValide()) {
                continue;
            }

            $taux = $mappingCategorie->getTauxTva();
            $tauxValeur = (float) $taux->getTaux();
            $ttc = $ligneVente->montantTtcCentimes;
            $tva = (int) round($ttc * $tauxValeur / (100 + $tauxValeur));
            $ht = $ttc - $tva;

            $compteCredit = ($ligneVente->reglePca !== ReglePca::Aucune && $pcaActif)
                ? $this->compteAttente487($profil, null)
                : $mappingCategorie->getCompteProduit();

            $lignes[] = new LigneEcritureADto(
                compte: $compteCredit,
                debitCentimes: 0,
                creditCentimes: $ht,
                tauxTva: $taux,
                libelle: 'Produit ' . $vente->numero,
            );
            if ($tva > 0) {
                $lignes[] = new LigneEcritureADto(
                    compte: $compteTva,
                    debitCentimes: 0,
                    creditCentimes: $tva,
                    tauxTva: $taux,
                    libelle: 'TVA collectée ' . $vente->numero,
                );
            }
        }

        if ($lignes === []) {
            return new EcritureADto(NatureOperation::Ventes, $vente->date, [], $vente->id, $vente->numero);
        }

        $totalCredit = array_sum(array_map(static fn (LigneEcritureADto $l): int => $l->creditCentimes, $lignes));
        $tauxPremiereLigne = $lignes[0]->tauxTva;
        array_unshift($lignes, new LigneEcritureADto(
            compte: $compteEncaissement,
            debitCentimes: $totalCredit,
            creditCentimes: 0,
            tauxTva: $tauxPremiereLigne,
            libelle: 'Encaissement ' . $vente->numero,
        ));

        return new EcritureADto(NatureOperation::Ventes, $vente->date, $lignes, $vente->id, $vente->numero);
    }

    public function genererEcritureExtourne(EcritureComptable $origine, AvoirProjectionDto $avoir): EcritureADto
    {
        $lignes = [];
        foreach ($origine->getLignes() as $ligne) {
            // Contre-passation totale : inversion stricte débit/crédit (RG-M2-07/§4.2).
            $lignes[] = new LigneEcritureADto(
                compte: $ligne->getCompte(),
                debitCentimes: $ligne->getCreditCentimes(),
                creditCentimes: $ligne->getDebitCentimes(),
                tauxTva: $ligne->getTauxTva(),
                axeSite: $ligne->getAxeSite(),
                axeActivite: $ligne->getAxeActivite(),
                axeFinanceur: $ligne->getAxeFinanceur(),
                libelle: 'Extourne — ' . $avoir->motif,
            );
        }

        return new EcritureADto(NatureOperation::Extourne, $avoir->dateHeure, $lignes, $avoir->venteOrigine, 'Extourne ' . $origine->getNumeroSequence());
    }

    public function genererEcritureRegie(BordereauVersement $bordereau): EcritureADto
    {
        $regie = $bordereau->getRegie();
        $profil = $regie->getProfilExploitant();
        $taux = $this->comptes->tauxHorsChamp($profil);
        $compteEncaissement = $this->compteEncaissement($profil);
        $compteBanque = $this->comptes->compteParPrefixe($profil, '512');

        $montant = $bordereau->getMontantCentimes();

        $lignes = [
            new LigneEcritureADto($compteBanque, $montant, 0, $taux, libelle: 'Versement régie'),
            new LigneEcritureADto($compteEncaissement, 0, $montant, $taux, libelle: 'Versement régie'),
        ];

        return new EcritureADto(NatureOperation::Regie, $bordereau->getDateVersement(), $lignes, null, 'Versement ' . $bordereau->getId());
    }

    abstract public function compteAttente487(ProfilExploitant $profil, ?\App\Compta\Entity\QualificationEquipement $qualif): CompteComptable;

    abstract public function compteEncaissement(ProfilExploitant $profil): CompteComptable;

    abstract public function compteTvaCollectee(ProfilExploitant $profil): CompteComptable;
}
