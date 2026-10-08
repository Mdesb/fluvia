<?php

declare(strict_types=1);

namespace App\Tests\Facturation\Api;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Entity\TauxTva;
use App\Compta\Service\PeriodeComptableResolver;
use App\Facturation\Entity\SerieNumerotation;
use App\Facturation\Enum\PrefixeSerie;
use App\Organisation\Entity\Etablissement;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Compta\LegalVatRateFixtureTrait;
use App\Tests\Facturation\FacturationApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * DEUX EXPLOITANTS, DEUX SÉRIES : CHACUN ÉMET SA FACTURE N° 1 DE L'ANNÉE.
 *
 * Mesuré le 08/10/2026 : la série est tenue par exploitant (`SerieNumerotation`, unique sur
 * `(profil, exercice, préfixe)`), mais `uniq_facture_numero` portait sur le numéro SEUL. Le premier
 * exploitant prenait `FA-2026-00001` ; le second, qui ouvrait sa propre série à 1, heurtait l'index
 * et recevait une 500. Même chose pour la série `AVF`.
 *
 * Le second exploitant est ouvert comme un vrai client : `POST /organisation/structures` (un groupe,
 * un établissement, un profil, son plan de comptes et ses taux).
 */
final class InvoiceNumberPerOperatorTest extends FacturationApiTestCase
{
    use LegalVatRateFixtureTrait;

    public function testEachOperatorIssuesInvoiceNumberOneOfItsOwnSeries(): void
    {
        [$client, $headersA] = $this->adminSurA();
        [$headersB, $rateB] = $this->openSecondOperator($client, $headersA, '81240390500019');

        $invoiceA = $this->issueInvoice($client, $headersA, $this->idTauxTva('Taux normal 20 %'));
        $invoiceB = $this->issueInvoice($client, $headersB, $rateB);

        $year = date('Y');
        self::assertSame("FA-$year-00001", $invoiceA['numero']);
        self::assertSame("FA-$year-00001", $invoiceB['numero'], 'le second exploitant ouvre sa propre série');
    }

    public function testEachOperatorIssuesCreditNoteNumberOneOfItsOwnSeries(): void
    {
        [$client, $headersA] = $this->adminSurA();
        [$headersB, $rateB, $profileB] = $this->openSecondOperator($client, $headersA, '81240390500019');

        // B a déjà facturé une fois cette année : ses factures ne croisent pas celles de A, et le
        // test n'éprouve que la série des avoirs.
        $this->em()->persist((new SerieNumerotation())
            ->setProfilExploitant($profileB)->setExercice((int) date('Y'))->setPrefixe(PrefixeSerie::Facture)->setDernierNumero(1));
        $this->em()->flush();

        $invoiceA = $this->issueInvoice($client, $headersA, $this->idTauxTva('Taux normal 20 %'));
        $invoiceB = $this->issueInvoice($client, $headersB, $rateB);

        $creditA = $this->post($client, '/api/factures/' . $invoiceA['id'] . '/avoir', $headersA);
        $creditB = $this->post($client, '/api/factures/' . $invoiceB['id'] . '/avoir', $headersB);

        $year = date('Y');
        self::assertSame("AVF-$year-00001", $creditA['numero']);
        self::assertSame("AVF-$year-00001", $creditB['numero'], 'le second exploitant ouvre sa propre série d avoirs');
    }

    /**
     * DEUX PROFILS, UN SEUL SIREN : DEUX SÉRIES DISTINCTES (décision de Maxime du 08/10). Deux sites
     * d'une même société (deux SIRET) reçoivent chacun un profil ; la préprod en a deux au SIREN
     * 130025265. Le premier à numéroter garde la série sans code, et elle continue ; le second prend le
     * NIC de son SIRET, pour ses factures comme pour ses avoirs.
     */
    public function testSameSirenGetsADistinctSeries(): void
    {
        [$client, $headersA] = $this->adminSurA();
        [$headersB, $rateB] = $this->openSecondOperator($client, $headersA, '13002526500027');
        $rateA = $this->idTauxTva('Taux normal 20 %');

        $firstA = $this->issueInvoice($client, $headersA, $rateA);
        $firstB = $this->issueInvoice($client, $headersB, $rateB);
        $secondA = $this->issueInvoice($client, $headersA, $rateA);
        $creditB = $this->post($client, '/api/factures/' . $firstB['id'] . '/avoir', $headersB);

        $year = date('Y');
        self::assertSame("FA-$year-00001", $firstA['numero']);
        self::assertSame("FA-00027-$year-00001", $firstB['numero'], 'le second profil du SIREN prend le NIC de son SIRET');
        self::assertSame("FA-$year-00002", $secondA['numero'], 'la série sans code continue');
        self::assertSame("AVF-00027-$year-00001", $creditB['numero']);
    }

    /**
     * L'EXPORT EUROPEEN PAR NUMERO : deux clients portent chacun `FA-2026-00001`. Sans l'exploitant,
     * `findOneBy(['numero' => …])` rendait l'une des deux au hasard, donc le fichier d'un autre client.
     */
    public function testEuropeanExportAsksWhichOperatorWhenTwoShareTheNumber(): void
    {
        [$client, $headersA] = $this->adminSurA();
        [$headersB, $rateB, $profileB] = $this->openSecondOperator($client, $headersA, '81240390500019');
        $number = $this->issueInvoice($client, $headersA, $this->idTauxTva('Taux normal 20 %'))['numero'];
        $this->issueInvoice($client, $headersB, $rateB);

        $command = new CommandTester((new Application(static::$kernel))->find('facturation:einvoicing:emettre'));

        self::assertSame(Command::FAILURE, $command->execute(['numero' => $number]));
        self::assertStringContainsString('--exploitant', $command->getDisplay());

        // Aucune des deux factures d'essai n'est complète au sens EN 16931 : la commande dit ce qui
        // manque, et c'est ce qui désigne la facture lue. Le vendeur A a une adresse ; B, ouvert sans,
        // n'en a pas.
        $command->execute(['numero' => $number, '--exploitant' => $this->idProfilExploitant()]);
        self::assertStringNotContainsString('ProfilExploitant::adresse', $command->getDisplay(), 'la facture de A');
        $command->execute(['numero' => $number, '--exploitant' => (string) $profileB->getId()]);
        self::assertStringContainsString('ProfilExploitant::adresse', $command->getDisplay(), 'la facture de B');
    }

    /** @return array{0: array<string, mixed>, 1: string, 2: ProfilExploitant} */
    private function openSecondOperator(Client $client, array $headersA, string $siret): array
    {
        $this->seedFranceMetropolitanVatRates($this->em());
        $opened = $this->post($client, '/api/organisation/structures', $headersA, [
            'nomCommercial' => 'Second exploitant',
            'denomination' => 'SECOND EXPLOITANT SAS',
            'siret' => $siret,
            'formeJuridique' => '5710',
        ]);

        $em = $this->em();
        $site = $em->getRepository(Etablissement::class)->findOneBy(['nom' => 'Second exploitant']);
        $profile = $em->getRepository(ProfilExploitant::class)->findOneBy(['etablissementPrincipal' => $site]);
        self::assertInstanceOf(ProfilExploitant::class, $profile);
        $rate = $em->getRepository(TauxTva::class)->findOneBy(['profilExploitant' => $profile, 'taux' => '20.00']);
        self::assertInstanceOf(TauxTva::class, $rate);

        /** @var PeriodeComptableResolver $periods */
        $periods = static::getContainer()->get(PeriodeComptableResolver::class);
        $periods->resoudreOuCreer($profile, new \DateTimeImmutable());
        $em->flush();

        $headersB = $headersA;
        $headersB['headers'] = [ContexteEtablissement::HEADER => $opened['etablissement']];

        return [$headersB, (string) $rate->getId(), $profile];
    }

    /** @return array<string, mixed> */
    private function issueInvoice(Client $client, array $headers, string $rateId): array
    {
        $draft = $this->post($client, '/api/factures', $headers, $this->bodyWithRate($rateId));

        return $this->post($client, '/api/factures/' . $draft['id'] . '/emettre', $headers);
    }

    /** @return array<string, mixed> */
    private function bodyWithRate(string $rateId): array
    {
        $body = $this->corpsFactureDirecte();
        $body['lignes'][0]['tauxTva'] = '/api/taux_tvas/' . $rateId;

        return $body;
    }

    /** @return array<string, mixed> */
    private function post(Client $client, string $uri, array $headers, ?array $json = null): array
    {
        $response = $client->request('POST', $uri, $headers + ($json === null ? [] : ['json' => $json]));
        self::assertLessThan(300, $response->getStatusCode(), $uri . ' : ' . $response->getContent(false));

        return $response->toArray();
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
