<?php

declare(strict_types=1);

namespace App\Tests\Compta;

use App\Compta\Entity\LegalVatRate;
use App\Compta\Enum\VatRateCategory;
use Doctrine\ORM\EntityManagerInterface;

/**
 * LE REFERENTIEL LEGAL DE TVA, POSE DANS UNE BASE DE TEST.
 *
 * ⚠ POURQUOI IL FAUT LE POSER A LA MAIN. En production, `accounting_legal_vat_rate` porte 62 taux
 * sur 29 pays, semes par `vat:seed-legal-rates` puis `vat:import-tedb`. En test, la table est
 * VIDE : aucune fixture ne l'alimente, et `SchemaDuHarnais` tronque toutes les tables entre deux
 * tests. Un test qui suppose le referentiel present sans le poser mesure donc l'inverse de la
 * production — et il le fait en silence, puisqu'une liste vide ne leve rien.
 *
 * ⚠ LES VALEURS SONT CELLES DU REFERENTIEL REEL, RELEVEES SUR LA PREPRODUCTION LE 14/09/2026. Les
 * recopier ici est volontaire : ce trait sert a des tests dont l'objet EST le bareme rendu. Les
 * lire depuis `SeedLegalVatRatesCommand` ferait qu'un bareme faux et un test faux bougeraient
 * ensemble, et le test ne pourrait plus rien attraper.
 */
trait LegalVatRateFixtureTrait
{
    /**
     * Le bareme metropolitain francais : 20 / 10 / 5,5 / 2,1.
     *
     * Ce sont exactement les quatre valeurs que portait l'ancienne constante `TAUX_TVA_FRANCE` de
     * `StructureOnboarding` — c'est ce qui permet au test d'ouverture d'affirmer la MEME sortie
     * qu'avant, en ayant change de source.
     */
    private function seedFranceMetropolitanVatRates(EntityManagerInterface $em): void
    {
        $this->seedLegalVatRates($em, 'FR', '', [
            [VatRateCategory::Standard, '20.00', 'Taux normal — la plupart des biens et services'],
            [VatRateCategory::Reduced, '10.00', 'Taux reduit — restauration, transport de voyageurs, travaux de renovation'],
            [VatRateCategory::SecondReduced, '5.50', 'Taux reduit — produits alimentaires, livres, abonnements gaz et electricite'],
            [VatRateCategory::SuperReduced, '2.10', 'Taux particulier — medicaments remboursables, presse, certains spectacles'],
        ]);
    }

    /**
     * Le bareme des DOM : 8,5 / 2,1 / 1,05 (CGI art. 296).
     *
     * ⚠ IL N'EST PAS UN SOUS-ENSEMBLE DU METROPOLITAIN, IL EST AUTRE. Le 20 % et le 5,5 % n'y
     * existent pas, et le 2,1 y est le taux REDUIT quand il est le taux PARTICULIER en metropole.
     * C'est ce qui en fait le temoin : un semeur reste sur le bareme francais rendrait 20 %, un
     * semeur qui lit le territoire rend 8,5 %.
     */
    private function seedFranceOverseasVatRates(EntityManagerInterface $em): void
    {
        $this->seedLegalVatRates($em, 'FR', 'DOM', [
            [VatRateCategory::Standard, '8.50', 'Taux normal — Guadeloupe, Martinique, Reunion'],
            [VatRateCategory::Reduced, '2.10', 'Taux reduit — Guadeloupe, Martinique, Reunion'],
            [VatRateCategory::SuperReduced, '1.05', 'Taux particulier presse — Guadeloupe, Martinique, Reunion'],
        ]);
    }

    /** @param list<array{0: VatRateCategory, 1: string, 2: string}> $rates */
    private function seedLegalVatRates(EntityManagerInterface $em, string $country, string $territory, array $rates): void
    {
        foreach ($rates as [$category, $rate, $label]) {
            $em->persist(
                (new LegalVatRate())
                    ->setCountry($country)
                    ->setTerritory($territory)
                    ->setCategory($category)
                    ->setRate($rate)
                    ->setLabel($label)
                    ->setValidFrom(new \DateTimeImmutable('2014-01-01'))
                    ->setValidUntil(null)
                    ->setSource('Jeu de test — releve sur la preproduction le 14/09/2026')
            );
        }

        $em->flush();
    }
}
