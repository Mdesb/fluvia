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

        $encaissements = $this->lignesEncaissement($vente, $profil, $totalCredit, $compteEncaissement, $tauxPremiereLigne);
        foreach (array_reverse($encaissements) as $ligne) {
            array_unshift($lignes, $ligne);
        }

        return new EcritureADto(NatureOperation::Ventes, $vente->date, $lignes, $vente->id, $vente->numero);
    }

    /**
     * Compte declare pour ce moyen de paiement, ou `null` si rien n'est declare.
     *
     * Pose au niveau du regime, au meme rang que `compteEncaissement()` : un regime pourra un jour
     * en decider autrement sans toucher a la repartition. C'est aussi ce qui rend l'arithmetique
     * de `lignesEncaissement()` eprouvable sans base de donnees.
     */
    protected function compteEncaissementPourMoyen(ProfilExploitant $profil, string $moyenCode): ?CompteComptable
    {
        return $this->comptes->comptePourMoyen($profil, $moyenCode);
    }

    /**
     * LES LIGNES DE DEBIT D'ENCAISSEMENT — UNE, OU UNE PAR MOYEN DE PAIEMENT.
     *
     * ── LE PIEGE QUI COMMANDE TOUTE CETTE METHODE ───────────────────────────────────────────────
     *
     * Le debit ne vaut PAS le total de la vente : il vaut la somme des CREDITS RETENUS. Les lignes
     * dont la categorie n'a pas de mapping valide sont ecartees en amont (CA-2), et le debit suit —
     * c'est ce qui garde l'ecriture equilibree quoi qu'il arrive.
     *
     * Ventiler d'apres les montants REELS des reglements, qui somment au total de la VENTE,
     * casserait cet equilibre des qu'une ligne est ecartee. On repartit donc `$totalCredit` AU
     * PRORATA des reglements, et le reste de l'arrondi va a la plus grosse part.
     *
     *     somme des debits == $totalCredit, par construction et non par chance.
     *
     * ── QUATRE RAISONS DE RETOMBER SUR UNE LIGNE UNIQUE ─────────────────────────────────────────
     *
     * Le drapeau est eteint · la vente ne porte aucun reglement (vente differee, projection
     * ancienne) · leur somme est nulle · un seul moyen a servi. Dans les quatre cas, l'ecriture est
     * exactement celle d'avant ce lot — c'est ce qui rend le deploiement sans effet sur les livres
     * deja tenus.
     *
     * ⚠ Le taux de TVA porte sur la ligne de debit est celui de la premiere ligne de credit, comme
     * avant. Ce n'est pas satisfaisant — un encaissement n'a pas de taux — mais le changer ici
     * modifierait toutes les ecritures existantes pour une raison sans rapport avec ce lot.
     *
     * @return list<LigneEcritureADto>
     */
    private function lignesEncaissement(
        VenteProjectionDto $vente,
        ProfilExploitant $profil,
        int $totalCredit,
        CompteComptable $compteEncaissement,
        \App\Compta\Entity\TauxTva $taux,
    ): array {
        $unique = [new LigneEcritureADto(
            compte: $compteEncaissement,
            debitCentimes: $totalCredit,
            creditCentimes: 0,
            tauxTva: $taux,
            libelle: 'Encaissement ' . $vente->numero,
        )];

        if (!$profil->getParametresRegime()->ventilationEncaissementParMoyen) {
            return $unique;
        }

        $parts = [];
        foreach ($vente->reglements as $reglement) {
            $code = $reglement->paymentMethodCode;
            $parts[$code] = ($parts[$code] ?? 0) + $reglement->netAmountCents;
        }

        $sommeReglements = array_sum($parts);
        if (\count($parts) < 2 || $sommeReglements <= 0) {
            return $unique;
        }

        // La plus grosse part d'abord : elle recevra le reste de l'arrondi, ou l'ecart sera le plus
        // dilue. Tri stable sur le code a montant egal, pour que deux executions donnent la meme
        // ecriture — une ecriture scellee ne doit pas dependre d'un ordre de hachage.
        uksort($parts, static function (string $a, string $b) use ($parts): int {
            return [$parts[$b], $a] <=> [$parts[$a], $b];
        });

        $lignes = [];
        $reste = $totalCredit;
        $rang = 0;
        $dernier = \count($parts) - 1;

        foreach ($parts as $code => $montant) {
            // Le dernier prend tout ce qui reste : c'est ce qui garantit l'egalite exacte, quel que
            // soit le comportement de l'arrondi sur les parts precedentes.
            $debit = $rang === $dernier ? $reste : (int) round($totalCredit * $montant / $sommeReglements);
            $reste -= $debit;
            ++$rang;

            if ($debit === 0) {
                continue;
            }

            $lignes[] = new LigneEcritureADto(
                compte: $this->compteEncaissementPourMoyen($profil, $code) ?? $compteEncaissement,
                debitCentimes: $debit,
                creditCentimes: 0,
                tauxTva: $taux,
                libelle: 'Encaissement ' . $code . ' ' . $vente->numero,
            );
        }

        // Ceinture : si la repartition n'a rien produit — tous les debits a zero apres arrondi —
        // on rend la ligne unique plutot qu'une ecriture sans debit. Un desequilibre serait refuse
        // plus loin, mais il vaut mieux ne pas le fabriquer.
        return $lignes === [] ? $unique : $lignes;
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
