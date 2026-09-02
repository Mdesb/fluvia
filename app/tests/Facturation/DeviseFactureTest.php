<?php

declare(strict_types=1);

namespace App\Tests\Facturation;

use App\Facturation\Entity\Facture;
use App\Organisation\Entity\Etablissement;
use PHPUnit\Framework\TestCase;

/**
 * LA DEVISE DE LA FACTURE SUIT SON ÉTABLISSEMENT — SANS QUE SIX ENDROITS AIENT À Y PENSER.
 *
 * Mesure du 02/09 : `new Facture()` apparaît à **six** endroits — abonnements, facture directe,
 * chaîne documentaire, avoir, acompte, facture justificative. Demander à chacun de poser la devise
 * serait une consigne, et le septième l'oublierait.
 *
 * ⚠ CES TESTS GARDENT DEUX COMPORTEMENTS QUI SE CONTREDISENT EN APPARENCE : la devise est héritée,
 * et un choix explicite n'est jamais repris. Le rattachement à un établissement répond à « qui
 * facture », pas à « dans quelle unité » — sans la seconde règle, rattacher une facture écraserait
 * une décision que quelqu'un a prise.
 */
final class DeviseFactureTest extends TestCase
{
    public function testUneFactureNeuveEstEnEuros(): void
    {
        self::assertSame('EUR', (new Facture())->getCurrency());
    }

    public function testElleHeriteDeLaDeviseDeSonEtablissement(): void
    {
        $etablissement = (new Etablissement())->setDevise('CHF');

        $facture = (new Facture())->setEtablissement($etablissement);

        self::assertSame('CHF', $facture->getCurrency());
    }

    /**
     * ⚠ LE TÉMOIN QUI EMPÊCHE L'HÉRITAGE D'ÊTRE UN ÉCRASEMENT.
     *
     * Sans lui, `testElleHerite…` passerait aussi si le rattachement écrasait TOUJOURS la devise —
     * et une facture libellée volontairement dans une autre unité perdrait ce choix au premier
     * rattachement, sans que rien ne le signale.
     */
    public function testUnChoixExpliciteNEstPasEcraseParLeRattachement(): void
    {
        $facture = (new Facture())->setCurrency('USD');

        $facture->setEtablissement((new Etablissement())->setDevise('CHF'));

        self::assertSame('USD', $facture->getCurrency(), 'le rattachement ne doit pas reprendre une devise choisie');
    }

    /**
     * Un établissement sans devise particulière ne change rien.
     *
     * ⚠ Ce cas est le plus fréquent — tous les établissements existants sont en euros — et c'est
     * celui où une erreur passerait le plus inaperçue.
     */
    public function testUnEtablissementEnEurosLaisseLaFactureEnEuros(): void
    {
        $facture = (new Facture())->setEtablissement(new Etablissement());

        self::assertSame('EUR', $facture->getCurrency());
    }

    /**
     * La devise est normalisée en majuscules — ISO 4217 ne connaît que celles-là.
     *
     * `chf` passerait la contrainte de longueur et serait refusé par un validateur européen.
     */
    public function testLaDeviseEstNormaliseeEnMajuscules(): void
    {
        self::assertSame('CHF', (new Etablissement())->setDevise('chf')->getDevise());
        self::assertSame('USD', (new Facture())->setCurrency('usd')->getCurrency());
    }

    /**
     * Le pays et la devise sont deux questions distinctes.
     *
     * ⚠ Une table pays → devise serait à maintenir, fausse là où plusieurs devises ont cours, et
     * muette sur l'exploitant français qui facture en francs suisses une clientèle frontalière. Ce
     * test garde la séparation : changer l'un ne touche pas l'autre.
     */
    public function testLePaysEtLaDeviseSontIndependants(): void
    {
        $etablissement = (new Etablissement())->setPays('CH');

        self::assertSame('CH', $etablissement->getPays());
        self::assertSame('EUR', $etablissement->getDevise(), 'le pays ne choisit pas la devise');

        $etablissement->setDevise('CHF');

        self::assertSame('CH', $etablissement->getPays());
        self::assertSame('CHF', $etablissement->getDevise());
    }
}
