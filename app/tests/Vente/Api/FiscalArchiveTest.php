<?php

declare(strict_types=1);

namespace App\Tests\Vente\Api;

use App\DataFixtures\SocleFixtures;
use App\Offre\DataFixtures\OffreFixtures;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Vente\VenteApiTestCase;
use App\Vente\Nf525\FiscalArchiveSigner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * #342 / #343 — l'archive fiscale annuelle : ventes et fréquentation de l'année, figées, séparées des
 * données actives, consultables, et vérifiables.
 *
 * ⚠ LE VÉRIFICATEUR EST MESURÉ DANS LES DEUX SENS. Une archive intacte doit rendre « intacte », et
 * chacune des trois falsifications ci-dessous doit être nommée par LE contrôle qui la concerne — sans
 * quoi un vérificateur aveugle serait indiscernable d'un vérificateur qui marche.
 */
final class FiscalArchiveTest extends VenteApiTestCase
{
    public function testLArchiveFigeLAnneeEtSeVerifie(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $annee = $this->anneeCloturee($client, $entete);
        $this->passage(sprintf('%d-03-10 11:00', $annee));

        $archive = $client->request('POST', '/api/fiscal_archives', $entete + ['json' => ['year' => $annee]])->toArray(false);
        self::assertResponseStatusCodeSame(201);

        self::assertSame($annee, $archive['fiscalYear']);
        self::assertSame(1, $archive['salesCount']);
        self::assertSame(1, $archive['attendanceCount']);
        self::assertSame(64, \strlen($archive['sha256']));
        self::assertGreaterThan(0, $archive['fileSize']);
        self::assertArrayNotHasKey('contentBase64', $archive, 'La fiche ne charge pas le fichier.');
        $mars = $archive['summary']['months'][2];
        self::assertSame(3, $mars['month']);
        self::assertSame(1, $mars['salesCount']);
        self::assertSame(1, $mars['entries'], 'La fréquentation du mois est consultable sans ouvrir le fichier.');
        self::assertSame($archive['salesTotal'], $mars['salesTotal']);

        $rapport = $this->verifier($client, $entete, $archive['id']);
        self::assertResponseStatusCodeSame(200);
        self::assertTrue($rapport['intact']);
        self::assertSame(['empreinte', 'signature', 'fichiers', 'chaine', 'totaux'], array_column($rapport['checks'], 'check'));

        // Le fichier : un ZIP lisible, avec sa notice et les ventes de l'année.
        $zip = $this->telecharger($client, $entete, $archive['id']);
        self::assertSame($archive['sha256'], hash('sha256', $zip));
        $fichiers = $this->dezipper($zip);
        foreach (['LISEZMOI.txt', 'manifest.json', 'sales.csv', 'sale_lines.csv', 'payments.csv', 'credit_notes.csv',
            'sealed_operations.csv', 'daily_closures.csv', 'period_closures.csv', 'attendance.csv'] as $nom) {
            self::assertArrayHasKey($nom, $fichiers, $nom);
        }
        self::assertSame(2, substr_count(trim($fichiers['sales.csv']), "\n") + 1, 'En-tête + une vente.');
        self::assertStringContainsString('cloture_annuelle', $fichiers['sealed_operations.csv'], 'Le segment va jusqu\'à la clôture annuelle incluse.');

        // Une archive ne se refait pas.
        $client->request('POST', '/api/fiscal_archives', $entete + ['json' => ['year' => $annee]]);
        self::assertResponseStatusCodeSame(409);
    }

    /** Un octet changé dans le fichier : l'empreinte ne correspond plus. */
    public function testUnFichierAltereEstDetecte(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $archive = $this->archiver($client, $entete, $this->anneeCloturee($client, $entete));

        $this->em()->getConnection()->executeStatement(
            'UPDATE nf525_fiscal_archive_file SET content = CONCAT(content, ?) WHERE archive_id = ?',
            ['x', Uuid::fromString($archive['id'])->toBinary()],
        );

        $rapport = $this->verifier($client, $entete, $archive['id']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(['empreinte' => false, 'signature' => true], $this->etats($rapport, ['empreinte', 'signature']));
    }

    /**
     * Un fichier refait de toutes pièces, empreinte recalculée, SANS la clé : la signature le trahit.
     */
    public function testUneArchiveRefaiteSansLaCleEstDetectee(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $archive = $this->archiver($client, $entete, $this->anneeCloturee($client, $entete));
        $fichiers = $this->dezipper($this->telecharger($client, $entete, $archive['id']));

        $zip = $this->zipper($fichiers);
        $sha = hash('sha256', $zip);
        $this->remplacer($archive['id'], $zip, $sha, hash_hmac('sha256', $sha, 'une-autre-cle'));

        $rapport = $this->verifier($client, $entete, $archive['id']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(['empreinte' => true, 'signature' => false], $this->etats($rapport, ['empreinte', 'signature']));
    }

    /**
     * Le cas le plus sournois : un maillon scellé modifié, le manifeste et la signature refaits avec la
     * clé. Seul le recalcul de la chaîne peut le voir — et c'est lui qui doit le dire.
     */
    public function testUnMaillonFalsifieEstDetecteParLeRecalculDeLaChaine(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $archive = $this->archiver($client, $entete, $this->anneeCloturee($client, $entete));
        $fichiers = $this->dezipper($this->telecharger($client, $entete, $archive['id']));

        $avant = $fichiers['sealed_operations.csv'];
        // Le payload est du JSON dans une cellule CSV : ses guillemets y sont doublés.
        $fichiers['sealed_operations.csv'] = str_replace('""nombreVentes"":1', '""nombreVentes"":7', $avant);
        self::assertNotSame($avant, $fichiers['sealed_operations.csv'], 'La falsification a bien eu lieu.');
        $manifeste = json_decode($fichiers['manifest.json'], true);
        foreach ($manifeste['fichiers'] as $i => $f) {
            if ($f['nom'] === 'sealed_operations.csv') {
                $manifeste['fichiers'][$i]['sha256'] = hash('sha256', $fichiers['sealed_operations.csv']);
            }
        }
        $fichiers['manifest.json'] = (string) json_encode($manifeste);

        $zip = $this->zipper($fichiers);
        $sha = hash('sha256', $zip);
        /** @var FiscalArchiveSigner $signer */
        $signer = static::getContainer()->get(FiscalArchiveSigner::class);
        $this->remplacer($archive['id'], $zip, $sha, $signer->sign($sha));

        $rapport = $this->verifier($client, $entete, $archive['id']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(
            ['empreinte' => true, 'signature' => true, 'fichiers' => true, 'chaine' => false, 'totaux' => true],
            $this->etats($rapport, ['empreinte', 'signature', 'fichiers', 'chaine', 'totaux']),
        );
    }

    /** Une année non close au niveau annuel, ou l'année en cours, ne s'archive pas. */
    public function testOnNArchivePasUneAnneeNonClotureeNiLAnneeEnCours(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $session = $this->ouvrirSession($client, $entete);
        $annee = (int) (new \DateTimeImmutable('last year'))->format('Y');
        $this->vendreLe($client, $entete, $session['id'], sprintf('%d-03-10 10:00', $annee));

        $client->request('POST', '/api/fiscal_archives', $entete + ['json' => ['year' => $annee]]);
        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('clôture annuelle', $client->getResponse()->toArray(false)['detail'] ?? '');

        $client->request('POST', '/api/fiscal_archives', $entete + ['json' => ['year' => (int) date('Y')]]);
        self::assertResponseStatusCodeSame(422);
    }

    /**
     * Cloisonnement : deux établissements et un témoin. L'archive de A se lit sous A, ni listée ni
     * lisible ni vérifiable sous B. Et le droit d'archiver n'appartient pas au lecteur.
     */
    public function testUneArchiveNeSeLitPasDUnEtablissementALAutre(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $archive = $this->archiver($client, $entete, $this->anneeCloturee($client, $entete));

        self::assertContains($archive['id'], $this->ids($client, $entete), 'Témoin : visible sous A.');

        $idB = $this->idEtablissement(SocleFixtures::ETAB_B_NOM);
        $enteteB = ['auth_bearer' => $entete['auth_bearer'], 'headers' => [ContexteEtablissement::HEADER => $idB]];
        self::assertNotContains($archive['id'], $this->ids($client, $enteteB));
        $client->request('GET', '/api/fiscal_archives/' . $archive['id'] . '/download', $enteteB);
        self::assertResponseStatusCodeSame(404);
        $client->request('POST', '/api/fiscal_archives/' . $archive['id'] . '/verify', $enteteB + ['json' => []]);
        self::assertResponseStatusCodeSame(404);

        $token = $this->jeton($client, SocleFixtures::LECTEUR_EMAIL, SocleFixtures::LECTEUR_MDP);
        $client->request('POST', '/api/fiscal_archives', ['auth_bearer' => $token, 'headers' => $entete['headers'], 'json' => ['year' => 2020]]);
        self::assertResponseStatusCodeSame(403);
    }

    // ── outils ─────────────────────────────────────────────────────────────────────────────────

    /**
     * Une vente en mars de l'an dernier, arrêtée au jour, au mois et à l'année.
     *
     * @param array<string, mixed> $entete
     */
    private function anneeCloturee(object $client, array $entete): int
    {
        $annee = (int) (new \DateTimeImmutable('last year'))->format('Y');
        $session = $this->ouvrirSession($client, $entete);
        $jour = $this->vendreLe($client, $entete, $session['id'], sprintf('%d-03-10 10:00', $annee));
        $pdv = '/api/point_de_ventes/' . $this->idPointDeVente();

        $client->request('POST', $pdv . '/cloture-journaliere', $entete + ['json' => ['journee' => $jour]]);
        self::assertResponseIsSuccessful();
        $client->request('POST', $pdv . '/monthly-closure', $entete + ['json' => ['month' => $annee . '-03']]);
        self::assertResponseIsSuccessful();
        $client->request('POST', $pdv . '/annual-closure', $entete + ['json' => ['year' => $annee]]);
        self::assertResponseIsSuccessful();

        return $annee;
    }

    /**
     * @param array<string, mixed> $entete
     *
     * @return array<string, mixed>
     */
    private function archiver(object $client, array $entete, int $annee): array
    {
        $a = $client->request('POST', '/api/fiscal_archives', $entete + ['json' => ['year' => $annee]])->toArray(false);
        self::assertResponseStatusCodeSame(201);

        return $a;
    }

    /**
     * @param array<string, mixed> $entete
     *
     * @return array<string, mixed>
     */
    private function verifier(object $client, array $entete, string $id): array
    {
        return $client->request('POST', '/api/fiscal_archives/' . $id . '/verify', $entete + ['json' => []])->toArray(false);
    }

    /** @param array<string, mixed> $entete */
    private function telecharger(object $client, array $entete, string $id): string
    {
        $r = $client->request('GET', '/api/fiscal_archives/' . $id . '/download', $entete)->toArray();
        self::assertResponseIsSuccessful();

        return (string) base64_decode($r['contentBase64'], true);
    }

    /**
     * @param array<string, mixed> $rapport
     * @param list<string>          $noms
     *
     * @return array<string, bool>
     */
    private function etats(array $rapport, array $noms): array
    {
        $etats = array_column($rapport['checks'] ?? [], 'ok', 'check');

        return array_intersect_key($etats, array_flip($noms)) + array_fill_keys(array_diff($noms, array_keys($etats)), false);
    }

    private function remplacer(string $id, string $zip, string $sha, string $signature): void
    {
        $c = $this->em()->getConnection();
        $bin = Uuid::fromString($id)->toBinary();
        // Hors ORM : l'archive est immuable pour l'application, une falsification passe dessous.
        $c->executeStatement('DELETE FROM nf525_fiscal_archive_file WHERE archive_id = ?', [$bin]);
        $c->executeStatement(
            'INSERT INTO nf525_fiscal_archive_file (id, archive_id, part, content) VALUES (?, ?, 0, ?)',
            [Uuid::v4()->toBinary(), $bin, $zip],
        );
        $c->executeStatement('UPDATE nf525_fiscal_archive SET sha256 = ?, signature = ? WHERE id = ?', [$sha, $signature, $bin]);
        $this->em()->clear();
    }

    /** @return array<string, string> */
    private function dezipper(string $zip): array
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'fat');
        file_put_contents($tmp, $zip);
        $z = new \ZipArchive();
        self::assertTrue($z->open($tmp) === true, 'Le fichier est un ZIP lisible.');
        $fichiers = [];
        for ($i = 0; $i < $z->numFiles; ++$i) {
            $fichiers[(string) $z->getNameIndex($i)] = (string) $z->getFromIndex($i);
        }
        $z->close();
        unlink($tmp);

        return $fichiers;
    }

    /** @param array<string, string> $fichiers */
    private function zipper(array $fichiers): string
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'fat');
        $z = new \ZipArchive();
        $z->open($tmp, \ZipArchive::OVERWRITE);
        foreach ($fichiers as $nom => $contenu) {
            $z->addFromString($nom, $contenu);
        }
        $z->close();
        $zip = (string) file_get_contents($tmp);
        unlink($tmp);

        return $zip;
    }

    /** Un passage d'entrée validé, posé en SQL (le décor ; l'accès a ses propres tests). */
    private function passage(string $quand): void
    {
        $this->em()->getConnection()->executeStatement(
            'INSERT INTO acces_passage (id, horodatage, sens, resultat, origine_hors_ligne, en_conflit, cle_idempotence, etablissement_id)
             VALUES (?, ?, ?, ?, 0, 0, ?, UNHEX(REPLACE(?, "-", "")))',
            [Uuid::v4()->toBinary(), $this->momentUtc($quand), 'entree', 'valide', Uuid::v4()->toBinary(), $this->idEtablissement(SocleFixtures::ETAB_A_NOM)],
        );
    }

    /**
     * @param array<string, mixed> $entete
     *
     * @return list<string>
     */
    private function ids(object $client, array $entete): array
    {
        $rendu = $client->request('GET', '/api/fiscal_archives', $entete)->toArray();
        self::assertResponseIsSuccessful();

        return array_map(static fn (array $m): string => (string) $m['id'], $rendu['member'] ?? $rendu['hydra:member'] ?? []);
    }

    /** @param array<string, mixed> $entete */
    private function vendreLe(object $client, array $entete, string $sessionId, string $quand): string
    {
        $vente = $this->creerVente($client, $entete, $sessionId);
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/lignes', $entete + [
            'json' => [
                'produit' => '/api/produits/' . $this->idProduit(OffreFixtures::PRODUIT_ENTREE),
                'typeTarif' => '/api/type_tarifs/' . $this->idTarif(OffreFixtures::TARIF_PLEIN),
                'quantite' => 1,
            ],
        ]);
        self::assertResponseIsSuccessful();
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/paiements', $entete + ['json' => ['moyen' => 'cb']]);
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/valider', $entete + ['json' => []]);
        self::assertResponseIsSuccessful();

        $em = $this->em();
        $em->getConnection()->executeStatement(
            'UPDATE vente_vente SET date = ? WHERE id = UNHEX(REPLACE(?, "-", ""))',
            [$this->momentUtc($quand), $vente['id']],
        );
        $em->clear();

        return $this->jourComptable($quand);
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
