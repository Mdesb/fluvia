<?php

declare(strict_types=1);

namespace App\Tests\Organisation\Unit;

use App\Organisation\Service\CompanyDirectory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * CE QUE L'ANNUAIRE REND À LA CRÉATION D'UNE STRUCTURE.
 *
 * L'API `recherche-entreprises.api.gouv.fr` publie l'adresse du siège en MORCEAUX (numero_voie /
 * type_voie / libelle_voie) et la ville à part (libelle_commune). La facture EN 16931 la veut
 * découpée (BT-35 rue / BT-37 ville). Ce test mesure la SORTIE de `chercher()`, pas la présence des
 * champs : c'est la recomposition de la rue et la reprise de la ville qui rendent, en aval, l'adresse
 * vendeur exploitable à l'émission — et sans elles, une structure neuve ne sait pas émettre.
 */
final class CompanyDirectoryTest extends TestCase
{
    public function testLAdresseDuSiegeEstDecoupeePourLaFactureEuropeenne(): void
    {
        // Charge utile réelle de l'annuaire (mêmes clés, mêmes casses que la production).
        $charge = [
            'results' => [[
                'nom_complet' => 'GIONE FITNESS',
                'nom_raison_sociale' => 'GIONE FITNESS',
                'siren' => '812403905',
                'siege' => [
                    'siret' => '81240390500019',
                    'numero_voie' => '9',
                    'type_voie' => 'RUE',
                    'libelle_voie' => 'DU COLONEL PIERRE AVIA',
                    'complement_adresse' => 'BATIMENT A',
                    'code_postal' => '75015',
                    'libelle_commune' => 'PARIS',
                    'adresse' => '9 RUE DU COLONEL PIERRE AVIA 75015 PARIS',
                ],
            ]],
        ];

        $annuaire = new CompanyDirectory(new MockHttpClient(new MockResponse(
            json_encode($charge, JSON_THROW_ON_ERROR),
        )));

        $resultat = $annuaire->chercher('gione fitness');
        self::assertTrue($resultat['disponible']);
        $siege = $resultat['resultats'][0];

        // La rue est recomposée dans l'ordre officiel (numéro, type, libellé), la ville reprise
        // telle quelle. Ces deux champs, absents auparavant, sont ce que l'ouverture pose sur le
        // profil émetteur.
        self::assertSame('9 RUE DU COLONEL PIERRE AVIA', $siege['rue']);
        self::assertSame('PARIS', $siege['ville']);
        self::assertSame('BATIMENT A', $siege['complement']);
        self::assertSame('75015', $siege['codePostal']);
        // Le texte libre demeure, pour l'affichage : on AJOUTE le découpage, on ne le remplace pas.
        self::assertSame('9 RUE DU COLONEL PIERRE AVIA 75015 PARIS', $siege['adresse']);
    }

    /**
     * ⚠ LE CAS QUI DÉMASQUE UNE CONCATÉNATION NAÏVE. Un siège qui ne publie pas le numéro (une
     * place, un lieu-dit) ne doit pas produire « ` PLACE DU MARCHE` » avec des blancs en tête : un
     * validateur européen refuse une rue réduite à des espaces. Les segments vides sont écartés
     * AVANT la jointure, pas joints puis rognés.
     */
    public function testUneVoieSansNumeroNeLaissePasDEspacesParasites(): void
    {
        $charge = [
            'results' => [[
                'nom_complet' => 'ASSO DU MARCHE',
                'siege' => [
                    'numero_voie' => '',
                    'type_voie' => '',
                    'libelle_voie' => 'PLACE DU MARCHE',
                    'code_postal' => '34200',
                    'libelle_commune' => 'SETE',
                ],
            ]],
        ];

        $annuaire = new CompanyDirectory(new MockHttpClient(new MockResponse(
            json_encode($charge, JSON_THROW_ON_ERROR),
        )));

        $siege = $annuaire->chercher('asso du marche')['resultats'][0];
        self::assertSame('PLACE DU MARCHE', $siege['rue'], 'les segments vides sont écartés, pas concaténés en blancs');
        self::assertSame('SETE', $siege['ville']);
    }
}
