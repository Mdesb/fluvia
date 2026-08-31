<?php

declare(strict_types=1);

namespace App\Tests\Opening;

use App\Opening\Service\FrenchPublicHolidays;
use PHPUnit\Framework\TestCase;

/**
 * LES JOURS FÉRIÉS SE CALCULENT — et un calcul se vérifie sur des dates connues, pas sur lui-même.
 *
 * ⚠ **Le piège de ce test est de le réécrire avec la formule qu'il vérifie.** Un test qui
 * recalculerait Pâques pour comparer au résultat serait vert quelle que soit l'erreur : il
 * comparerait la fonction à elle-même. Les dates ci-dessous sont donc écrites EN DUR, relevées sur
 * le calendrier, et chacune est datée d'un raisonnement vérifiable à la main :
 *
 *   · 2026 : Pâques le 5 avril → lundi le 6, Ascension (+39) le 14 mai, Pentecôte (+50) le 25 mai ;
 *   · 2024 : Pâques le 31 mars, la date la plus précoce de la décennie ;
 *   · 2038 : Pâques le 25 avril, la plus tardive possible — les deux bornes de l'algorithme.
 *
 * Sans les bornes, une erreur de modulo passerait inaperçue vingt années sur vingt-deux.
 */
final class FrenchPublicHolidaysTest extends TestCase
{
    public function testLesTroisFeriesMobilesDeriventDePaques(): void
    {
        $dates = $this->datesDe(2026);

        self::assertContains('2026-04-06', $dates, 'Lundi de Pâques 2026.');
        self::assertContains('2026-05-14', $dates, 'Ascension 2026 (Pâques + 39).');
        self::assertContains('2026-05-25', $dates, 'Lundi de Pentecôte 2026 (Pâques + 50).');
    }

    public function testLesBornesDeLAlgorithmeDePaques(): void
    {
        // Pâques 2024 : 31 mars. Le lundi tombe donc le 1er avril — un changement de mois qu'un
        // calcul naïf sur le jour du mois raterait.
        self::assertContains('2024-04-01', $this->datesDe(2024), 'Lundi de Pâques 2024, à cheval sur deux mois.');

        // Pâques 2038 : 25 avril, la date la plus tardive que l'algorithme puisse rendre.
        self::assertContains('2038-04-26', $this->datesDe(2038), 'Lundi de Pâques 2038, borne haute.');
    }

    public function testLesHuitDatesFixesSontTouteLa(): void
    {
        $dates = $this->datesDe(2026);

        foreach (['2026-01-01', '2026-05-01', '2026-05-08', '2026-07-14',
                  '2026-08-15', '2026-11-01', '2026-11-11', '2026-12-25'] as $attendue) {
            self::assertContains($attendue, $dates);
        }
    }

    /**
     * ALSACE-MOSELLE : deux fériés de plus, et ils ne s'ajoutent QUE là.
     *
     * Le test vérifie les deux sens. Vérifier seulement leur présence laisserait passer une
     * implémentation qui les ajoute partout — et un club parisien fermé le Vendredi saint est une
     * journée de chiffre d'affaires perdue que personne ne rattrape.
     */
    public function testAlsaceMoselleAjouteDeuxFeriesEtSeulementLa(): void
    {
        $ailleurs = $this->datesDe(2026);
        self::assertNotContains('2026-04-03', $ailleurs, 'Le Vendredi saint n’est pas férié hors Alsace-Moselle.');
        self::assertNotContains('2026-12-26', $ailleurs, 'Le 26 décembre n’est pas férié hors Alsace-Moselle.');

        $local = $this->datesDe(2026, true);
        self::assertContains('2026-04-03', $local, 'Vendredi saint 2026 (Pâques − 2).');
        self::assertContains('2026-12-26', $local, 'Saint-Étienne.');
        self::assertCount(13, $local, 'Onze fériés nationaux, plus deux locaux.');
    }

    public function testEntreDeuxDatesTraverseLesAnneesCiviles(): void
    {
        $feries = (new FrenchPublicHolidays())->entre(
            new \DateTimeImmutable('2026-12-20'),
            new \DateTimeImmutable('2027-01-05'),
        );
        $dates = array_column($feries, 'date');

        self::assertSame(['2026-12-25', '2027-01-01'], $dates, 'La bascule d’année ne doit rien perdre.');
    }

    /** @return list<string> */
    private function datesDe(int $annee, bool $alsaceMoselle = false): array
    {
        return array_column((new FrenchPublicHolidays())->pourAnnee($annee, $alsaceMoselle), 'date');
    }
}
