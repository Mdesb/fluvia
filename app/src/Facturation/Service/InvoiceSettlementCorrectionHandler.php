<?php

declare(strict_types=1);

namespace App\Facturation\Service;

use App\Facturation\Entity\Facture;
use App\Facturation\Entity\InvoiceSettlementCorrection;
use App\Facturation\Entity\ReglementFacture;
use App\Facturation\Enum\StatutFacture;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * « CORRIGER LE PAIEMENT » — moins sur le moyen d'origine, plus sur le moyen réel.
 *
 * Le caissier a encaissé en espèces et saisi « carte » ; on ne réécrit pas la saisie d'origine, on
 * pose la compensation. C'est la doctrine D45, déjà appliquée aux ventes par
 * `App\Vente\Service\SettlementCorrectionHandler`, et cette classe en est le pendant pour les
 * factures — mêmes contrôles, même refus d'effacer.
 *
 * ── LE SOLDE SE CORRIGE TOUT SEUL, ET C'EST VOULU ───────────────────────────────────────────────
 *
 * `Facture::getMontantRegle()` somme les règlements. Une ligne à `−X` et une à `+X` laissent donc le
 * total identique, et `getSoldeDu()` reste exact **sans qu'aucun calcul ne soit recopié ici**. C'est
 * ce qui permet à Maxime d'avoir « automatiquement les écritures qui vont avec » sans qu'on ajoute
 * un second mécanisme de lettrage à côté du premier.
 *
 * ── LES CINQ REFUS, ET POURQUOI CHACUN EXISTE ───────────────────────────────────────────────────
 *
 * Ils sont repris du geste équivalent sur la vente, parce que les mêmes erreurs se font des deux
 * côtés — et le dernier est celui qu'on oublie toujours.
 */
final class InvoiceSettlementCorrectionHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param array<string, mixed> $donnees { moyenDebite, moyenCredite, montant, motif }
     */
    public function corriger(Facture $facture, array $donnees, ?Utilisateur $auteur): InvoiceSettlementCorrection
    {
        // 1. Rien n'est scellé sur un brouillon : le règlement s'y reprend directement, et poser une
        //    correction fabriquerait la trace d'un fait qui n'a pas eu lieu.
        if ($facture->getStatut() === StatutFacture::Brouillon) {
            throw new ConflictHttpException('Seule une facture émise se corrige : reprenez le règlement du brouillon.');
        }

        $debite = $this->moyen($donnees['moyenDebite'] ?? null, 'moyenDebite');
        $credite = $this->moyen($donnees['moyenCredite'] ?? null, 'moyenCredite');

        // 2. Deux moyens identiques ne corrigent rien, et laisseraient deux lignes qui s'annulent
        //    sans rien apprendre à personne.
        if ($debite === $credite) {
            throw new UnprocessableEntityHttpException('Les deux moyens sont identiques : il n’y a rien à corriger.');
        }

        // 3. Ce geste déplace de l'argent entre deux moyens de paiement. Sans motif, il est
        //    indéfendable en contrôle — et six mois plus tard, personne ne sait pourquoi.
        $motif = \is_string($donnees['motif'] ?? null) ? trim($donnees['motif']) : '';
        if ($motif === '') {
            throw new UnprocessableEntityHttpException('Un motif est obligatoire pour corriger un règlement.');
        }

        $montantCentimes = $this->centimes($donnees['montant'] ?? null);

        // 4. Un montant nul ou négatif n'est pas une correction : c'est une ligne de bruit, ou une
        //    tentative de fabriquer un encaissement.
        if ($montantCentimes <= 0) {
            throw new UnprocessableEntityHttpException('Le montant à corriger doit être strictement positif.');
        }

        // 5. ⚠ LE CONTRÔLE QU'ON OUBLIE. Sans lui, on créditerait la carte depuis des espèces jamais
        //    encaissées, et deux corrections successives déplaceraient plus que le règlement
        //    d'origine. C'est le NET des corrections déjà passées qui fait foi, pas le règlement
        //    initial : la somme des lignes du moyen débité, négatives comprises.
        $disponible = $this->resteSurLeMoyen($facture, $debite);
        if ($montantCentimes > $disponible) {
            throw new UnprocessableEntityHttpException(sprintf(
                'Montant supérieur à ce qui reste réglé en « %s » sur cette facture : %s disponible.',
                $debite,
                $this->decimal($disponible),
            ));
        }

        // ⚠ LE JOUR DU GESTE, PAS CELUI DU RÈGLEMENT D'ORIGINE (D45). Antidater reviendrait à
        // écrire dans une journée comptable peut-être close, et à nier que l'écart a existé.
        $maintenant = new \DateTimeImmutable();
        $montant = $this->decimal($montantCentimes);

        $debit = $this->ligne($facture, $debite, $this->decimal(-$montantCentimes), $motif, $auteur, $maintenant);
        $credit = $this->ligne($facture, $credite, $montant, $motif, $auteur, $maintenant);

        $correction = new InvoiceSettlementCorrection(
            $facture,
            $debite,
            $credite,
            $montant,
            $motif,
            $debit,
            $credit,
            $auteur,
            $maintenant,
        );

        $this->em->persist($correction);
        $this->em->flush();

        return $correction;
    }

    /**
     * Ce qui reste réglé sur un moyen, corrections comprises.
     *
     * Les lignes négatives entrent dans la somme : c'est exactement ce qui empêche une seconde
     * correction de déplacer une somme déjà déplacée.
     */
    private function resteSurLeMoyen(Facture $facture, string $moyen): int
    {
        $total = 0;
        foreach ($facture->getReglements() as $reglement) {
            if ($reglement->getMoyen() === $moyen) {
                $total += $this->centimes($reglement->getMontant());
            }
        }

        return $total;
    }

    private function ligne(
        Facture $facture,
        string $moyen,
        string $montant,
        string $motif,
        ?Utilisateur $auteur,
        \DateTimeImmutable $quand,
    ): ReglementFacture {
        $ligne = (new ReglementFacture())
            ->setFacture($facture)
            ->setMoyen($moyen)
            ->setMontant($montant)
            // La référence porte le motif : c'est le seul champ libre de la ligne, et sans lui un
            // montant négatif apparaîtrait dans un relevé sans la moindre explication.
            ->setReference(mb_substr('Correction : ' . $motif, 0, 64))
            ->setDateReglement($quand)
            ->setAuteur($auteur);

        $facture->addReglement($ligne);
        $this->em->persist($ligne);

        return $ligne;
    }

    private function moyen(mixed $valeur, string $champ): string
    {
        $moyen = \is_string($valeur) ? trim($valeur) : '';
        if ($moyen === '') {
            throw new UnprocessableEntityHttpException(sprintf('« %s » est requis.', $champ));
        }

        return $moyen;
    }

    private function centimes(mixed $montant): int
    {
        if (!\is_string($montant) && !\is_int($montant) && !\is_float($montant)) {
            throw new UnprocessableEntityHttpException('« montant » est requis, en euros.');
        }

        return (int) round(((float) $montant) * 100);
    }

    private function decimal(int $centimes): string
    {
        return number_format($centimes / 100, 2, '.', '');
    }
}
