<?php

declare(strict_types=1);

namespace App\Tests\Sepa;

use App\Sepa\Command\RemettreCompteursCollecteCommand;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * UNE COMMANDE DE CORRECTION DE MASSE SE PROUVE PAR CE QU'ELLE N'ECRIT PAS.
 *
 * `sepa:compteurs:remettre-a-zero` remet a zero `nbCollectesReussies` sur tous les mandats — un
 * compteur dont la mesure a etabli qu'aucune valeur non nulle n'a jamais correspondu a une collecte
 * reelle : un seul endroit du depot l'incrementait, et c'etait le bouchon de transmission.
 *
 * ⚠ CE QUI EST DANGEREUX N'EST PAS CE QU'ELLE CORRIGE, C'EST CE QU'ELLE POURRAIT TOUCHER AU
 * PASSAGE. Une commande de masse qui deborde est pire que le defaut qu'elle repare, et elle deborde
 * EN SILENCE : personne ne relit une colonne qu'on n'a pas annoncee. Un IBAN chiffre altere ne se
 * voit pas — il se decouvre au prochain prelevement.
 *
 * D'ou le test central : une empreinte de TOUTES les autres colonnes du mandat, avant et apres.
 * S'il en manque une, la commande peut la modifier sans que rien ne le dise.
 */
final class RemettreCompteursCollecteCommandTest extends SepaApiTestCase
{
    /**
     * ⚠ L'EPARGNE EST LE VRAI SUJET DE CE TEST.
     *
     * L'empreinte porte sur toutes les colonnes SAUF celle qu'on corrige. On la construit depuis
     * `information_schema` plutot que de lister les noms a la main : une colonne ajoutee demain
     * entre automatiquement dans le controle, la ou une liste ecrite en dur laisserait passer
     * exactement les champs les plus recents — ceux que personne n'a encore l'habitude de verifier.
     */
    public function testElleNeTouchePasUneSeuleAutreColonne(): void
    {
        $connexion = static::getContainer()->get('doctrine')->getConnection();

        $colonnes = $connexion->fetchFirstColumn(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "sepa_mandat"'
            . ' AND COLUMN_NAME <> "nb_collectes_reussies" ORDER BY ORDINAL_POSITION'
        );

        // ⚠ TEMOIN : l'empreinte doit porter sur quelque chose. Une liste vide rendrait deux
        // empreintes identiques quoi qu'il arrive, et le test passerait en ne mesurant rien.
        self::assertGreaterThan(
            5,
            \count($colonnes),
            'temoin : l\'empreinte doit couvrir les autres colonnes du mandat, sinon elle ne prouve rien',
        );

        $empreinte = static fn (): string => (string) $connexion->fetchOne(sprintf(
            'SELECT COALESCE(GROUP_CONCAT(l ORDER BY l), \'\') FROM (SELECT CONCAT_WS(\'|\', %s) AS l FROM sepa_mandat) t',
            implode(', ', array_map(static fn (string $c): string => 'COALESCE(CAST(`' . $c . '` AS CHAR), \'~\')', $colonnes)),
        ));

        // ⚠ LA PRECONDITION EST INDISPENSABLE, ET SON ABSENCE RENDAIT CE TEST VIDE.
        //
        // Sans compteur non nul, la commande n'a rien a corriger : elle sort avant son UPDATE, et
        // l'empreinte ne peut pas changer. Le test etait alors vrai parce que la commande ne
        // touchait RIEN — une assertion d'absence d'effet mesuree sur une absence de cause.
        //
        // Decouvert en sabotant la commande pour qu'elle altere aussi le RUM : le test est reste
        // vert. C'est le sabotage qui a designe la precondition manquante, pas la relecture.
        $connexion->executeStatement('UPDATE sepa_mandat SET nb_collectes_reussies = 2');

        $avant = $empreinte();
        self::assertNotSame('', $avant, 'temoin : il doit y avoir des mandats en base');

        $this->lancer(['--ecrire' => true]);

        self::assertSame(
            $avant,
            $empreinte(),
            'la commande ne doit ecrire QUE `nb_collectes_reussies` : toute autre colonne modifiee '
            . 'ferait diverger cette empreinte, et une correction de masse qui deborde est pire que '
            . 'le defaut qu\'elle repare',
        );
    }

    /**
     * ⚠ SANS `--ecrire`, RIEN NE BOUGE — ET C'EST LE COMPORTEMENT PAR DEFAUT.
     *
     * Une ecriture de masse qu'on ne peut pas prevoir avant de la lancer est une ecriture qu'on
     * lance en esperant. Le constat doit donc etre le defaut, pas une option.
     */
    public function testSansEcrireElleNeCorrigeRienMaisAnnonceCeQuElleFerait(): void
    {
        $connexion = static::getContainer()->get('doctrine')->getConnection();

        $connexion->executeStatement('UPDATE sepa_mandat SET nb_collectes_reussies = 3');
        $avant = (int) $connexion->fetchOne('SELECT SUM(nb_collectes_reussies) FROM sepa_mandat');
        self::assertGreaterThan(0, $avant, 'temoin : des compteurs non nuls doivent exister pour que la mesure ait un sens');

        $sortie = $this->lancer([]);

        self::assertSame(
            $avant,
            (int) $connexion->fetchOne('SELECT SUM(nb_collectes_reussies) FROM sepa_mandat'),
            'sans --ecrire, aucun compteur ne doit changer',
        );
        self::assertStringContainsString('Constat seulement', $sortie);
    }

    public function testAvecEcrireTousLesCompteursTombentAZero(): void
    {
        $connexion = static::getContainer()->get('doctrine')->getConnection();

        $connexion->executeStatement('UPDATE sepa_mandat SET nb_collectes_reussies = 7');
        self::assertGreaterThan(
            0,
            (int) $connexion->fetchOne('SELECT SUM(nb_collectes_reussies) FROM sepa_mandat'),
            'temoin : la precondition doit etre posee, sinon le zero final ne prouve rien',
        );

        $this->lancer(['--ecrire' => true]);

        self::assertSame(
            0,
            (int) $connexion->fetchOne('SELECT SUM(nb_collectes_reussies) FROM sepa_mandat'),
        );
    }

    /** @param array<string, mixed> $options */
    private function lancer(array $options): string
    {
        $testeur = new CommandTester(
            static::getContainer()->get(RemettreCompteursCollecteCommand::class)
        );
        $testeur->execute($options);

        return $testeur->getDisplay();
    }
}
