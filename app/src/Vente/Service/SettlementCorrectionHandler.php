<?php

declare(strict_types=1);

namespace App\Vente\Service;

use App\Autorisation\Service\ComparateurMontant;
use App\Securite\Entity\Utilisateur;
use App\Vente\Entity\Paiement;
use App\Vente\Entity\SettlementCorrection;
use App\Vente\Entity\Vente;
use App\Vente\Enum\StatutVente;
use App\Vente\Enum\TypeOperationScellee;
use App\Vente\Nf525\OperationAScellerDto;
use App\Vente\Nf525\ScellementHandler;
use App\Vente\Port\ReferentielReglementInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Corrige la ventilation d'un règlement (D45) : −X sur un moyen, +X sur un autre, sans jamais toucher
 * la vente.
 *
 * La correction est **ajoutée et scellée** dans la chaîne NF525, au même titre qu'un avoir. La vente
 * d'origine reste intacte — c'est la condition pour que la chaîne garde sa valeur.
 */
final class SettlementCorrectionHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ScellementHandler $scellement,
        private readonly ReferentielReglementInterface $referentiel,
    ) {
    }

    /** @param array<string, mixed> $donnees */
    public function corriger(Vente $vente, array $donnees, ?Utilisateur $auteur): SettlementCorrection
    {
        if ($vente->getStatut() !== StatutVente::Validee) {
            // Sur un panier non validé, rien n'est scellé : le règlement se reprend directement, et
            // ajouter une écriture de correction fabriquerait une trace pour un fait qui n'a pas eu
            // lieu.
            throw new ConflictHttpException('Seule une vente validée se corrige : reprenez le règlement du panier.');
        }

        $debite = $this->moyen($donnees['moyenDebite'] ?? null, 'moyenDebite');
        $credite = $this->moyen($donnees['moyenCredite'] ?? null, 'moyenCredite');
        if ($debite === $credite) {
            throw new UnprocessableEntityHttpException('Les deux moyens sont identiques : il n\'y a rien à corriger.');
        }

        $motif = \is_string($donnees['motif'] ?? null) ? trim((string) $donnees['motif']) : '';
        if ($motif === '') {
            // Ce geste déplace de l'argent entre moyens ; sans motif il est indéfendable en contrôle.
            throw new UnprocessableEntityHttpException('Un motif est obligatoire pour corriger un règlement.');
        }

        $montant = $this->montant($donnees['montant'] ?? null);
        $disponible = $this->resteSurLeMoyen($vente, $debite);
        if (ComparateurMontant::comparer($montant, $disponible) === 1) {
            // Sans ce contrôle, on créditerait la carte depuis des espèces qui n'ont jamais été
            // encaissées — et deux corrections successives pourraient déplacer plus que le règlement
            // d'origine. C'est le **net des corrections déjà passées** qui fait foi, pas le paiement.
            throw new UnprocessableEntityHttpException(sprintf(
                'Montant supérieur à ce qui reste réglé en « %s » sur cette vente : %s disponible.',
                $debite,
                $disponible,
            ));
        }

        $correction = new SettlementCorrection();
        $correction->setVente($vente)
            ->setMoyenDebite($debite)
            ->setMoyenCredite($credite)
            ->setMontant($montant)
            ->setMotif($motif)
            ->setAuteur($auteur)
            ->setEtablissement($vente->getEtablissement());
        $this->em->persist($correction);

        $pdv = $vente->getSession()?->getPointDeVente();
        if ($pdv !== null) {
            $this->scellement->sceller(new OperationAScellerDto(
                $pdv,
                TypeOperationScellee::CorrectionReglement,
                'SettlementCorrection',
                $correction->getId(),
                [
                    'correction' => (string) $correction->getId(),
                    'vente' => (string) $vente->getId(),
                    'moyenDebite' => $debite,
                    'moyenCredite' => $credite,
                    'montant' => $montant,
                    'motif' => $motif,
                    // La date du geste, jamais celle de la vente : la chaîne est chronologique.
                    'dateHeure' => $correction->getDateHeure()->format(\DATE_ATOM),
                ],
            ));
        }

        return $correction;
    }

    /**
     * Ce qui reste réellement réglé sur ce moyen pour cette vente : les paiements du moyen, moins ce
     * que des corrections antérieures en ont déjà retiré, plus ce qu'elles y ont reporté.
     *
     * C'est ce net-là qui borne une correction. Se fier au seul `Paiement` laisserait deux corrections
     * successives déplacer deux fois la même somme.
     */
    private function resteSurLeMoyen(Vente $vente, string $moyen): string
    {
        // Centimes entiers : `bcmath` n'est pas installe sur l'image PHP du projet — verifie, et
        // deja constate par `Autorisation\Service\ComparateurMontant` et `Stay\Service\StayBalance`.
        // On reprend leur convention plutot que d'en introduire une troisieme.
        $centimes = 0;
        foreach ($this->em->getRepository(Paiement::class)->findBy(['vente' => $vente]) as $paiement) {
            if ($paiement->getMoyenCode() === $moyen) {
                $centimes += ComparateurMontant::centimes($paiement->getMontant());
            }
        }

        foreach ($this->em->getRepository(SettlementCorrection::class)->findBy(['vente' => $vente]) as $anterieure) {
            if ($anterieure->getMoyenDebite() === $moyen) {
                $centimes -= ComparateurMontant::centimes($anterieure->getMontant());
            }
            if ($anterieure->getMoyenCredite() === $moyen) {
                $centimes += ComparateurMontant::centimes($anterieure->getMontant());
            }
        }

        return number_format($centimes / 100, 2, '.', '');
    }

    private function moyen(mixed $code, string $champ): string
    {
        $code = \is_string($code) ? $code : '';
        if ($code === '' || $this->referentiel->moyen($code) === null) {
            throw new UnprocessableEntityHttpException(sprintf('Moyen de règlement inconnu pour « %s ».', $champ));
        }

        return $code;
    }

    private function montant(mixed $brut): string
    {
        $montant = \is_string($brut) || \is_numeric($brut) ? number_format((float) $brut, 2, '.', '') : '0.00';
        if (ComparateurMontant::comparer($montant, '0.00') !== 1) {
            throw new UnprocessableEntityHttpException('Le montant corrigé doit être strictement positif.');
        }

        return $montant;
    }
}
