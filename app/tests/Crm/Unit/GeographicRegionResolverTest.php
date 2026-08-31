<?php

declare(strict_types=1);

namespace App\Tests\Crm\Unit;

use App\Crm\Service\GeographicRegionResolver;
use PHPUnit\Framework\TestCase;

/**
 * La région géographique déduite du code postal.
 *
 * ⚠ CE QUI EST TESTÉ N'EST PAS « la table est bien recopiée » — ce serait comparer le code à
 * lui-même. Ce sont les FRONTIÈRES : ce que la fonction refuse de deviner, et les cas où deviner
 * produirait une statistique fausse plutôt qu'incomplète.
 */
final class GeographicRegionResolverTest extends TestCase
{
    private GeographicRegionResolver $resolveur;

    protected function setUp(): void
    {
        $this->resolveur = new GeographicRegionResolver();
    }

    /** Le cas nominal, sur les quatre coins — sans lui, tout le reste pourrait être vert à vide. */
    public function testQuelquesCodesConnus(): void
    {
        self::assertSame('Île-de-France', $this->resolveur->pourCodePostal('75011'));
        self::assertSame('Hauts-de-France', $this->resolveur->pourCodePostal('59000'));
        self::assertSame("Provence-Alpes-Côte d'Azur", $this->resolveur->pourCodePostal('13001'));
        self::assertSame('Bretagne', $this->resolveur->pourCodePostal('29200'));
        self::assertSame('Grand Est', $this->resolveur->pourCodePostal('67000'));
    }

    /**
     * ⚠ L'OUTRE-MER SE LIT SUR TROIS CHIFFRES, PAS DEUX.
     *
     * « 97 » seul ne distingue pas la Guadeloupe de Mayotte. Lire deux chiffres rangerait les cinq
     * départements d'outre-mer sous une même étiquette inexistante — une statistique qui aurait
     * l'air de marcher.
     */
    public function testLoutreMerSeLitSurTroisChiffres(): void
    {
        self::assertSame('Guadeloupe', $this->resolveur->pourCodePostal('97110'));
        self::assertSame('Martinique', $this->resolveur->pourCodePostal('97200'));
        self::assertSame('Guyane', $this->resolveur->pourCodePostal('97300'));
        self::assertSame('La Réunion', $this->resolveur->pourCodePostal('97400'));
        self::assertSame('Mayotte', $this->resolveur->pourCodePostal('97600'));
    }

    /**
     * ⚠ LES COLLECTIVITÉS NE SONT PAS DES RÉGIONS, ET ON NE LES RANGE PAS DE FORCE.
     *
     * Saint-Pierre-et-Miquelon, Saint-Martin, la Nouvelle-Calédonie n'appartiennent à aucune région
     * administrative. Les rattacher « au plus proche » inventerait une donnée ; `null` dit qu'on ne
     * sait pas rattacher, ce qui est exact.
     */
    public function testLesCollectivitesNeSontRattacheesANulleRegion(): void
    {
        self::assertNull($this->resolveur->pourCodePostal('97500'), 'Saint-Pierre-et-Miquelon');
        self::assertNull($this->resolveur->pourCodePostal('97133'), 'Saint-Barthélemy');
        self::assertNull($this->resolveur->pourCodePostal('98800'), 'Nouvelle-Calédonie');
        self::assertNull($this->resolveur->pourCodePostal('98000'), 'Monaco');
    }

    /** La Corse se lit sur « 20 » : le découpage postal ne suit pas la limite 2A/2B, et les deux sont Corse. */
    public function testLaCorse(): void
    {
        self::assertSame('Corse', $this->resolveur->pourCodePostal('20000'));
        self::assertSame('Corse', $this->resolveur->pourCodePostal('20200'));
    }

    /**
     * ⚠ UN CODE À QUATRE CHIFFRES N'EST PAS UN CODE FRANÇAIS AMPUTÉ DE SON ZÉRO.
     *
     * « 1000 » est Bruxelles. Le compléter en « 01000 » rattacherait un Belge à l'Ain — l'invention
     * exacte qu'on refuse, et elle ne se verrait jamais : la statistique aurait l'air complète.
     */
    public function testUnCodeMalFormeNestPasDevine(): void
    {
        self::assertNull($this->resolveur->pourCodePostal('1000'));
        self::assertNull($this->resolveur->pourCodePostal('7501'));
        self::assertNull($this->resolveur->pourCodePostal('ABCDE'));
        self::assertNull($this->resolveur->pourCodePostal('750110'));
        self::assertNull($this->resolveur->pourCodePostal(''));
        self::assertNull($this->resolveur->pourCodePostal(null));
    }

    /** Les espaces sont fréquents à la saisie et ne doivent pas coûter la région. */
    public function testLesEspacesDeSaisieSontTolerees(): void
    {
        self::assertSame('Île-de-France', $this->resolveur->pourCodePostal(' 75 011 '));
    }

    /**
     * ⚠ UN PAYS ÉTRANGER L'EMPORTE SUR UN CODE QUI RESSEMBLE À UN CODE FRANÇAIS.
     *
     * Sans ce test, une adresse suisse « 1204 Genève » ou un code à cinq chiffres étranger serait
     * rattaché à un département français, et le client compterait dans une région où il n'habite pas.
     */
    public function testUnPaysEtrangerEmpecheLeRattachement(): void
    {
        self::assertNull($this->resolveur->pourAdresse([
            'cp' => '10115', 'ville' => 'Berlin', 'pays' => 'Allemagne',
        ]));

        // Témoin : le MÊME code, sans pays étranger, se rattache — sinon le null ci-dessus pourrait
        // venir du code lui-même et ce test ne mesurerait pas ce qu'il annonce.
        self::assertSame('Grand Est', $this->resolveur->pourAdresse([
            'cp' => '10115', 'ville' => 'Troyes',
        ]));
    }

    /** « France », « FR », « fra » : le champ est libre, et le refuser ferait perdre de vrais clients. */
    public function testLesEcrituresDeLaFranceSontAcceptees(): void
    {
        foreach (['France', 'FRANCE', 'fr', ' France '] as $pays) {
            self::assertSame(
                'Occitanie',
                $this->resolveur->pourAdresse(['cp' => '31000', 'pays' => $pays]),
                sprintf('« %s » doit être reconnu comme la France', $pays),
            );
        }
    }

    /** Une adresse sans code postal, ou absente, ne se devine pas. */
    public function testUneAdresseSansCodePostal(): void
    {
        self::assertNull($this->resolveur->pourAdresse(null));
        self::assertNull($this->resolveur->pourAdresse(['ville' => 'Lyon']));
        self::assertNull($this->resolveur->pourAdresse([]));
    }
}
